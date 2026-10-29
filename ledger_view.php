<?php # /ledger_view.php v:1.3.1 d:2026-09-15 i:evs
# bilagsdato brugte hårdkodet d.m.Y i stedet for CONF_DATE_FORMAT
# v1.5.0: bogførte posteringer (har voucher_no) er nu urørlige UANSET
# periodelås - kun Annullér vises. Slet er kun tilbage til ikke-bogførte
# manuelle/legacy-poster (§C1, bogforingslov-compliance).
# v1.6.0: Annullér-modposteringens egen journalpost manglede et voucher_no -
# fundet ved en systematisk gennemgang af alle "INSERT INTO journal"-steder
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';   // log_action() - revisionsspor for slet/annullér

// --- DELETE JOURNAL ENTRY LOGIC ---
if (isset($_GET['action']) && $_GET['action'] == 'delete_jou' && isset($_GET['jou_id'])) {
    $jou_id = (int)$_GET['jou_id'];

    // Server-side håndhævelse - IKKE kun UI-knapskjul, da action-URL'en kan
    // kaldes direkte. Bogført (har voucher_no) er urørlig uanset periodelås;
    // ellers gælder den gamle periodelås-regel.
    $jrow = DB::fetch_assoc(DB::query($conn, "SELECT jou_date, voucher_no FROM journal WHERE jou_id = $jou_id"));
    if ($jrow && !empty($jrow['voucher_no'])) {
        header("Location: ledger_view.php?msg=posted");
        exit;
    }
    if ($jrow && is_date_locked($conn, $jrow['jou_date'])) {
        header("Location: ledger_view.php?msg=locked");
        exit;
    }

    // Log de FULDE gamle værdier (journal + alle ledger-linjer) FØR sletning,
    // så der altid findes et spor i audit_log - selv efter rækkerne er væk.
    $jou_full = DB::fetch_assoc(DB::query($conn, "SELECT * FROM journal WHERE jou_id = $jou_id"));
    $led_rows = [];
    $lres = DB::query($conn, "SELECT * FROM ledger WHERE jou_id = $jou_id");
    while ($lr = DB::fetch_assoc($lres)) { $led_rows[] = $lr; }
    if ($jou_full) {
        log_action($conn, 'DELETE_JOURNAL_ENTRY', 'journal', $jou_id, ['journal' => $jou_full, 'ledger_lines' => $led_rows], null);
    }

    DB::begin_transaction($conn);
    try {
        DB::query($conn, "DELETE FROM ledger WHERE jou_id = $jou_id");
        DB::query($conn, "DELETE FROM journal WHERE jou_id = $jou_id");

        DB::commit($conn);
        header("Location: ledger_view.php?msg=deleted");
        exit;
    } catch (Exception $e) {
        DB::rollback($conn);
        die("Fejl ved sletning: " . $e->getMessage());
    }
}

// --- ANNULLÉR (MODPOSTERING) LOGIC ---
// Den korrekte rettelsesmetode jf. bogføringsloven: originalen røres IKKE
// (kun et is_cancelled-flag, samme mønster som faktura/udgifts-annullering
// allerede bruger), og en ny modsat postering tilføjes med dags dato. Virker
// UANSET periodelås - det er netop mekanismen til at rette låste perioder.
// RETTET: "Annullér" kunne vende ENHVER postering - også en fakturas bogføring, en betaling, en kreditnota,
// en udgift, en afskrivning eller årsafslutningen. Kun hovedbogen blev vendt; dokumentet blev stående
// (fakturaen "sendt"/"betalt", betalingen registreret, aktivet afskrevet) - debitorer, kreditorer og
// anlægskartoteket stemte så ikke længere med hovedbogen. Sådanne posteringer rettes via dokumentet
// (kreditnota, annullér udgiften ...). Manuelle posteringer og bankposteringer på en konto kan vendes som før.
const LEDGER_DOC_TRANS_TYPES = ['credit_note', 'expense', 'expense_payment', 'shop_day', 'year_end_close', 'bad_debt_writeoff', 'cancellation',
                                'depreciation', 'fixed_asset_cancellation', 'fixed_asset_disposal', 'fixed_asset_acquisition', 'chart_switch'];
// Posteringer uden trans_type der rører debitor- eller kreditorkontoen: fakturaens bogføring, betalinger af
// fakturaer og udgifter (bankafstemningen) (jou_id => true)
function ledger_debtor_jous($conn, ?int $only = null): array {
    $st  = get_settings($conn);
    $deb = (int)($st['conf_acc_debitor'] ?? 8100);  $cre = (int)($st['conf_acc_creditor'] ?? 4000);
    $out = [];
    $r = DB::query($conn, "SELECT DISTINCT l.jou_id FROM ledger l JOIN journal j ON j.jou_id = l.jou_id
                           WHERE l.acc_id IN ($deb, $cre) AND (j.trans_type IS NULL OR j.trans_type = '')" . ($only ? " AND l.jou_id = $only" : ''));
    if ($r) while ($x = DB::fetch_assoc($r)) $out[(int)$x['jou_id']] = true;
    // ... og bankposteringer der har oprettet en (bogført) udgift med samme bilagsnummer - rettes via udgiften
    $r = DB::query($conn, "SELECT DISTINCT j.jou_id FROM journal j JOIN expenses e ON e.voucher_no = j.voucher_no
                           WHERE (j.trans_type IS NULL OR j.trans_type = '')" . ($only ? " AND j.jou_id = $only" : ''));
    if ($r) while ($x = DB::fetch_assoc($r)) $out[(int)$x['jou_id']] = true;
    return $out;
}
function ledger_is_doc_entry(array $jou, array $debtor_jous): bool {
    return in_array((string)($jou['trans_type'] ?? ''), LEDGER_DOC_TRANS_TYPES, true) || isset($debtor_jous[(int)$jou['jou_id']]);
}

if (isset($_GET['action']) && $_GET['action'] == 'cancel_jou' && isset($_GET['jou_id'])) {
    $jou_id = (int)$_GET['jou_id'];

    $orig = DB::fetch_assoc(DB::query($conn, "SELECT * FROM journal WHERE jou_id = $jou_id"));
    if (!$orig) {
        header("Location: ledger_view.php?msg=notfound");
        exit;
    }
    if (ledger_is_doc_entry($orig, ledger_debtor_jous($conn, $jou_id))) {
        header("Location: ledger_view.php?msg=doc_entry");
        exit;
    }
    if ((int)($orig['is_cancelled'] ?? 0) === 1) {
        header("Location: ledger_view.php?msg=already_cancelled");
        exit;
    }

    $lines = [];
    $lres = DB::query($conn, "SELECT acc_id, amount FROM ledger WHERE jou_id = $jou_id");
    while ($lr = DB::fetch_assoc($lres)) { $lines[] = $lr; }

    if (empty($lines)) {
        header("Location: ledger_view.php?msg=nolines");
        exit;
    }

    DB::begin_transaction($conn);
    try {
        $today       = date('Y-m-d');
        $cancel_text = DB::escape($conn, "Annullering af bilag #$jou_id: " . $orig['jou_text']);
        // Bilagsnummer fra den fælles tæller - manglede FØR, samme klasse fejl
        // som blev fundet i year_end_close.php (§bogforingslov-compliance):
        // uden det ville selve modposteringen ikke tælle som "bogført" og
        // dermed forblive hård-sletbar, selvom den er selve rettelsesbeviset.
        $cancel_voucher_no = next_voucher_no($conn);
        // RETTET (§currency-setting-is-cosmetic-label): modposteringen arver
        // nu den oprindelige posterings valuta i stedet for altid at falde
        // tilbage til skemaets DEFAULT 'DKK'.
        $cancel_currency = DB::escape($conn, $orig['currency'] ?? ($global_settings['currency'] ?? 'DKK'));
        // NYT (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]).
        $cb = journal_created_by_sql($conn);
        DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']}) VALUES ('$today', '$cancel_text', 'cancellation', $cancel_voucher_no, '$cancel_currency'{$cb['val']})");
        $new_jou_id = DB::insert_id($conn);

        foreach ($lines as $l) {
            ledger_post($conn, $new_jou_id, (int)$l['acc_id'], -1 * (float)$l['amount'], true);      // (modpostering - se ledger_post())
        }

        // RETTET (§bugs-batch-22-review): "allerede annulleret"-tjekket
        // ovenfor (linje ~69) kører kun ÉN gang, tidligt, FØR transaktionen
        // overhovedet starter - to næsten-samtidige annulleringsforsøg for
        // samme bilag (fx et dobbeltklik) kunne begge bestå det tjek FØR
        // nogen af dem nåede at skrive, og dermed begge poste en fuld
        // modpostering - originalens beløb ville blive reverseret TO gange i
        // stedet for én, og hovedbogen ville ende med den MODSATTE nettoeffekt
        // af den oprindelige postering i stedet for nul. Samme sårbarheds-
        // klasse som allerede fundet og rettet i invoice_post_action.php/
        // invoice_credit.php/expense_actions.php/reconcile_action.php
        // (§bugs-batch-19-review) og year_end_close.php (§bugs-batch-22-
        // review, samme runde) - WHERE-klausulen tjekker nu atomisk
        // is_cancelled=0 ved selve skrivningen, og måler reelt berørte
        // rækker (DB::affected_rows()) i stedet for en efterfølgende SELECT,
        // som ikke pålideligt kan skelne "jeg vandt kapløbet" fra "jeg ser
        // bare en andens allerede-committede resultat".
        $cancel_upd = DB::query($conn, "UPDATE journal SET is_cancelled = 1 WHERE jou_id = $jou_id AND is_cancelled = 0");
        if (!$cancel_upd || DB::affected_rows($conn, $cancel_upd) < 1) {
            throw new Exception(lang('@This entry was already cancelled by another, simultaneous request (race) - nothing was duplicated.'));
        }

        log_action($conn, 'CANCEL_JOURNAL_ENTRY', 'journal', $jou_id,
            ['is_cancelled' => 0],
            ['is_cancelled' => 1, 'reversed_by_jou_id' => $new_jou_id]);

        DB::commit($conn);
        header("Location: ledger_view.php?msg=cancelled");
        exit;
    } catch (Exception $e) {
        DB::rollback($conn);
        die("Fejl ved annullering: " . $e->getMessage());
    }
}

htm_Header(lang('@General Ledger'));
showMenu();

echo "<div style='max-width:1200px; margin:0 auto; padding:10px;'>";

if (isset($_GET['msg']) && $_GET['msg'] == 'posted') {
    htm_Alert(lang('@This entry is posted and can never be deleted, only reversed with an opposite counter-posting.'), 'error');
}
if (isset($_GET['msg']) && $_GET['msg'] == 'locked') {
    htm_Alert(lang('@This entry is in a locked accounting period and cannot be deleted.'), 'error');
}
if (isset($_GET['msg']) && $_GET['msg'] == 'deleted') {
    htm_Alert(lang('@Journal entry and all related ledger lines have been deleted.'), 'success');
}
if (isset($_GET['msg']) && $_GET['msg'] == 'cancelled') {
    htm_Alert(lang('@Entry reversed with an opposite counter-posting. The original is kept for the audit trail.'), 'success');
}
if (isset($_GET['msg']) && $_GET['msg'] == 'already_cancelled') {
    htm_Alert(lang('@This entry has already been reversed.'), 'error');
}
if (isset($_GET['msg']) && $_GET['msg'] == 'doc_entry') {
    htm_Alert(lang('@This entry belongs to an invoice, a payment, a credit note, an expense, a depreciation or the year-end closing - correct it there (credit note, cancel the expense ...). A reversal here would leave the document unchanged.'), 'error');
}
if (isset($_GET['msg']) && ($_GET['msg'] == 'notfound' || $_GET['msg'] == 'nolines')) {
    htm_Alert(lang('@Entry not found or has no ledger lines to reverse.'), 'error');
}

// 1. Hent transaktioner
$debtor_jous = ledger_debtor_jous($conn);
// RETTET: posteringer på et kontonummer der ikke findes i kontoplanen var helt usynlige i hovedbogen (JOIN)
$sql = "SELECT j.jou_id, j.jou_date, j.jou_text, j.is_cancelled, j.voucher_no, j.trans_type, l.acc_id,
               COALESCE(a.acc_name, '" . DB::escape($conn, '⚠ ' . lang('@not in the chart of accounts')) . "') AS acc_name, l.amount
        FROM ledger l
        JOIN journal j ON l.jou_id = j.jou_id
        LEFT JOIN accounts a ON l.acc_id = a.acc_id
        ORDER BY j.jou_date DESC, j.jou_id DESC, l.led_id ASC";

$res = DB::query($conn, $sql);

// 2. Forbered data til htm_Table
$tableData = [];
$last_jou = null;

if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $is_new_jou   = ($last_jou !== $row['jou_id']);
        $is_cancelled = ((int)($row['is_cancelled'] ?? 0) === 1);

        // Handlinger (kun på første linje af et bilag). Samme mønster som
        // expense_list.php: ÉT skiftende ikon i stedet for to knapper, så der
        // aldrig vises en knap der alligevel ville blive afvist. Rækkefølgen
        // af reglerne betyder noget:
        //  1) Bogført (har voucher_no) -> KUN Annullér, UANSET periodelås.
        //     En bogført postering er urørlig og må kun rettes ved en
        //     modpostering - det er selve pointen med bilagsnumre (§C1).
        //  2) Ikke bogført (mangler voucher_no - en sjælden manuel/legacy-
        //     postering fra før det fælles bilagsnummer-system) -> falder
        //     tilbage til den gamle periodelås-regel: låst periode -> kun
        //     Annullér, åben periode -> Slet er stadig muligt (logges).
        // Allerede annulleret bilag får ingen knap - neutraliseret af sin
        // modpostering.
        $is_posted  = !empty($row['voucher_no']);
        $actionCell = '';
        if ($is_new_jou) {
            if ($is_cancelled) {
                $actionCell = '<span style="color:#94a3b8; font-size:11px; font-style:italic;">' . lang('@Cancelled') . '</span>';
            } elseif ($is_posted && ledger_is_doc_entry($row, $debtor_jous)) {
                $actionCell = '<span style="color:#94a3b8; font-size:11px; font-style:italic; cursor:help;" data-hint="' . htmlspecialchars(lang('@This entry belongs to an invoice, a payment, a credit note, an expense, a depreciation or the year-end closing - correct it there (credit note, cancel the expense ...). A reversal here would leave the document unchanged.'), ENT_QUOTES) . '">' . lang('@Corrected via its document') . '</span>';
            } elseif ($is_posted) {
                $actionCell = htm_ConfirmLink(
                    icon: 'fa-rotate-left',
                    link: "ledger_view.php?action=cancel_jou&jou_id={$row['jou_id']}",
                    mess: '@Reverse this entry with an opposite counter-posting? The original is kept for the audit trail.',
                    type: 'warning',
                    styl: 'padding:2px 6px; font-size:11px;',
                    attr: 'data-hint="' . htmlspecialchars(lang('@This entry is posted (voucher no. ') . $row['voucher_no'] . lang('@). Posted entries can never be deleted, only reversed with an opposite counter-posting — regardless of period lock.'), ENT_QUOTES) . '"',
                    echo: false
                );
            } elseif (is_date_locked($conn, $row['jou_date'])) {
                $actionCell = htm_ConfirmLink(
                    icon: 'fa-rotate-left',
                    link: "ledger_view.php?action=cancel_jou&jou_id={$row['jou_id']}",
                    mess: '@Reverse this entry with an opposite counter-posting? The original is kept for the audit trail.',
                    type: 'warning',
                    styl: 'padding:2px 6px; font-size:11px;',
                    attr: 'data-hint="' . htmlspecialchars(lang('@This period is locked, so deletion is blocked. Reversal (an opposite counter-posting) is the correct way to correct it — the original stays visible for the audit trail.'), ENT_QUOTES) . '"',
                    echo: false
                );
            } else {
                // Erstattet htm_Button+manuel confirm()-onclick med htm_ConfirmLink,
                // som escaper bekræftelsesteksten centralt.
                $actionCell = htm_ConfirmLink(
                    icon: 'fa-trash-can',
                    link: "ledger_view.php?action=delete_jou&jou_id={$row['jou_id']}",
                    mess: '@Are you sure?',
                    type: 'danger',
                    styl: 'padding:2px 6px; font-size:11px;',
                    attr: 'data-hint="' . htmlspecialchars(lang('@This entry has no voucher number, so it is not part of the official posted bookkeeping. Open period — it can still be deleted (logged for the audit trail).'), ENT_QUOTES) . '"',
                    echo: false
                );
            }
        }

        // Formater beløb
        $debit = ($row['amount'] > 0) ? '<span style="color:green; font-weight:500;">' . number_format($row['amount'], 2, ',', '.') . '</span>' : '';
        $credit = ($row['amount'] < 0) ? '<span style="color:red; font-weight:500;">' . number_format(abs($row['amount']), 2, ',', '.') . '</span>' : '';

        // Annullerede bilag vises overstreget/nedtonet, men BLIVER i oversigten
        // (i modsætning til før, hvor sletning fjernede dem sporløst).
        $rowStyle = $is_cancelled ? 'text-decoration:line-through; opacity:0.55;' : '';

        // Tilføj række til array
        $tableData[] = [
            $is_new_jou ? '<span style="'.$rowStyle.'">'.date(CONF_DATE_FORMAT, strtotime($row['jou_date'])).'</span>' : '<span style="color:var(--text-muted);">»</span>',
            $is_new_jou ? "<strong style='$rowStyle'>#{$row['jou_id']}</strong>" : '',
            $is_new_jou ? "<span style='$rowStyle'>".htmlspecialchars($row['jou_text'])."</span>" : '',
            "<small style='color:var(--text-muted);'>{$row['acc_id']}</small> " . htmlspecialchars($row['acc_name']),
            "<div style='text-align:right;'>$debit</div>",
            "<div style='text-align:right;'>$credit</div>",
            "<div style='text-align:center; white-space:nowrap;'>$actionCell</div>"
        ];
        
        $last_jou = $row['jou_id'];
    }
}

// 3. Overskrifter
$headers = ['@Date', '@Journal #', '@Description', '@Account', '@Debit (+)', '@Credit (-)', ''];

// 4. Visning i Card
htm_Card_(lang('@Transaction History'), 1200); // fold: kun relevant på sider med flere cards

if (empty($tableData)) {
    htm_Alert(lang('@No transactions found'), 'info');
} else {
    // Nu med automatiske zebrastriber og søgefunktion fra biblioteket
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): CSV-eksport
    // tilføjet - export.php understøtter kun kunder/fakturaer, ikke selve
    // hovedbogen, og det er typisk netop disse data en ekstern revisor
    // beder om. Samme delte exportCsv()-mekanisme som resten af appen.
    htm_Table($headers, $tableData, 'ledger_table', 100, expo: 'hovedbog.csv');
}

htm_Card_end();
echo "</div>";

htm_Footer();
ob_end_flush();
?>
