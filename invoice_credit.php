<?php # /invoice_credit.php v:1.3.0 d:2026-08-30 i:evs
# Kreditnota-flow - opretter en ny kreditnota-faktura + modpostering til en
# allerede bogført faktura. Kaldet fra sales_hub.php med ?credit_ref=INV_ID.
# Opfylder bogføringsloven § 16: bogførte fakturaer må ikke ændres, fejl
# korrigeres i stedet med en kreditnota der nulstiller den originale faktura.
# Journalposten får sit eget voucher_no fra next_voucher_no(); beløb
# omregnes til DKK med den ORIGINALE fakturas gemte exch_rate, og
# orig_currency/exch_rate kopieres til den nye kreditnota-faktura; varen
# lægges tilbage på lager (modpost til lagertrækket i invoice_post_action.php).
# En kreditnota kan ikke selv krediteres igen (server-side spærret).
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';

$credit_ref = (int)($_GET['credit_ref'] ?? 0);

if ($credit_ref <= 0) {
    header("Location: sales_hub.php?err=invalid_credit_ref"); exit;
}

// Hent original faktura
$orig = DB::fetch_assoc(DB::query($conn,
    "SELECT i.*, c.cust_name FROM invoices i
     JOIN customers c ON i.cust_id = c.cust_id
     WHERE i.inv_id = $credit_ref"));

if (!$orig) {
    header("Location: sales_hub.php?err=not_found"); exit;
}

// Kun bogførte fakturaer kan krediteres
if (strtolower($orig['inv_status']) === 'draft') {
    header("Location: invoice_edit.php?id=$credit_ref&err=draft_no_credit"); exit;
}

// RETTET (se [[bugs-batch-10-review]]): manglede et tjek for at "originalen"
// der skal krediteres ikke selv ER en kreditnota - sales_hub.php viste
// "Kreditér denne faktura"-knappen på alle ikke-kladde-fakturaer inkl.
// kreditnota-rækkerne selv, og intet her stoppede det. En kreditnota af en
// kreditnota giver ingen mening bogføringsmæssigt.
if (!empty($orig['credit_ref'])) {
    header("Location: sales_hub.php?err=cannot_credit_a_credit_note"); exit;
}

// Allerede krediteret?
$existing_credit = DB::fetch_assoc(DB::query($conn,
    "SELECT inv_id FROM invoices WHERE credit_ref = $credit_ref LIMIT 1"));
if ($existing_credit) {
    header("Location: sales_hub.php?err=already_credited&existing=" . $existing_credit['inv_id']); exit;
}

// Periodespærring
if (is_date_locked($conn, $orig['inv_date'])) {
    header("Location: sales_hub.php?err=date_locked"); exit;
}

// Hent originale linjer
$orig_lines = [];
$lines_res  = DB::query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $credit_ref ORDER BY line_id");
while ($l = DB::fetch_assoc($lines_res)) { $orig_lines[] = $l; }

// ── HÅNDTER BEKRÆFTELSE ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_credit'])) {

    $credit_date     = DB::escape($conn, $_POST['credit_date'] ?? date('Y-m-d'));
    $credit_note     = DB::escape($conn, $_POST['credit_note'] ?? '');
    $current_user_id = (int)($_SESSION['user_id'] ?? 1);

    if (is_date_locked($conn, $credit_date)) {
        $err = lang('@Credit date is in a locked accounting period.');
    } else {
        DB::begin_transaction($conn);
        try {
            // 1. Opret kreditnota-faktura med negative beløb
            // RETTET (se [[bugs-batch-10-review]]): orig_currency/exch_rate
            // blev ikke kopieret fra originalen - currency blev sat korrekt,
            // men en visning der (som invoice_view.php) læser orig_currency/
            // exch_rate specifikt for at vise fremmed-valuta-kurs-info ville
            // se ufuldstændige data på selve kreditnota-fakturaen.
            $orig_currency_sql = !empty($orig['orig_currency']) ? "'" . DB::escape($conn, $orig['orig_currency']) . "'" : 'NULL';
            $exch_rate_sql     = !empty($orig['exch_rate']) ? (float)$orig['exch_rate'] : 'NULL';
            DB::query($conn, "INSERT INTO invoices
                (cust_id, inv_date, inv_due_date, inv_status, inv_note,
                 currency, credit_ref, proj_id, orig_currency, exch_rate)
                VALUES ({$orig['cust_id']}, '$credit_date', '$credit_date', 'credit',
                '{$credit_note}', '{$orig['currency']}', $credit_ref,
                " . ($orig['proj_id'] ? $orig['proj_id'] : 'NULL') . ", $orig_currency_sql, $exch_rate_sql)");
            $new_inv_id = DB::insert_id($conn);

            // 2. Kopiér linjer med negativt antal (nulstiller originalen)
            foreach ($orig_lines as $l) {
                $neg_qty  = (float)$l['quantity'] * -1;
                $txt      = DB::escape($conn, $l['line_text']);
                $prc      = (float)$l['price_each'];
                $vat      = (float)$l['line_vat_rate'];
                $pid      = (int)$l['prod_id'];
                $proj_sql = $l['proj_id'] ? (int)$l['proj_id'] : 'NULL';
                DB::query($conn, "INSERT INTO invoice_lines
                    (inv_id, line_text, quantity, price_each, line_vat_rate, prod_id, proj_id)
                    VALUES ($new_inv_id, '$txt', $neg_qty, $prc, $vat, $pid, $proj_sql)");

                // LAGERREGULERING: læg varen tilbage på lager - modposten til
                // trækket der sker i invoice_post_action.php ved selve
                // bogføringen. Manglede FØR helt, samme fund som der.
                if ($pid > 0) {
                    DB::query($conn, "UPDATE products SET prod_stock = prod_stock + " . (float)$l['quantity'] . " WHERE prod_id = $pid");
                }
            }

            // 3. Marker original faktura som krediteret
            // RETTET (§bugs-batch-19-review): "allerede krediteret"-tjekket
            // ovenfor (linje 48) køres kun ÉN gang tidligt i denne
            // sidevisning - to næsten-samtidige kreditnota-forsøg for samme
            // faktura (fx to faner, eller et dobbeltklik der sender formularen
            // to gange) kunne begge bestå det tjek FØR nogen af dem nåede at
            // skrive, og dermed begge oprette en fuld kreditnota (dobbelt
            // lagerregulering, dobbelt modpostering). WHERE-klausulen her
            // tjekker nu atomisk, at originalen IKKE allerede blev krediteret
            // af en anden, hurtigere transaktion, og et 0-rækker-resultat
            // ruller hele denne kreditnota (allerede indsatte linjer/lager-
            // regulering) tilbage i stedet for at lade den stå.
            // OPFØLGNING (samme runde): den første udgave af denne rettelse
            // tjekkede bagefter kun OM status var 'credited' - men det viser
            // blot om SLUTTILSTANDEN er korrekt, ikke om DENNE forespørgsel
            // selv satte den. Taberen af kapløbet ville se vinderens
            // allerede-committede 'credited'-status og fejlagtigt tro sit
            // eget forsøg lykkedes - og fortsætte med at committe sin egen
            // duplikerede kreditnota alligevel. Rettet til at måle antal
            // reelt berørte rækker fra selve UPDATE'en (DB::affected_rows()),
            // som kun er >0 for den forespørgsel der faktisk vandt kapløbet.
            $upd_result = DB::query($conn, "UPDATE invoices SET inv_status = 'credited' WHERE inv_id = $credit_ref AND inv_status != 'credited'");
            if (!$upd_result || DB::affected_rows($conn, $upd_result) < 1) {
                throw new Exception('Fakturaen blev allerede krediteret af en anden, samtidig forespørgsel (kapløb) - intet blev dublet.');
            }

            // 4. Journalpostering — modpostering. Får nu et bilagsnummer fra den
            // fælles tæller (voucher_no manglede helt her før - kreditnotaer var
            // usporbare i den samlede bilagsrække).
            $cn_voucher_no = next_voucher_no($conn);
            $jou_text = DB::escape($conn, lang('@Credit note for invoice') . ' #' . $credit_ref . ': ' . $orig['cust_name']);
            // RETTET (§currency-setting-is-cosmetic-label): journal.currency
            // blev aldrig sat her (faldt altid tilbage til skemaets DEFAULT
            // 'DKK'), uanset firmaets faktisk konfigurerede bogføringsvaluta.
            $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
            DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency)
                VALUES ('$credit_date', '$jou_text', 'credit_note', $cn_voucher_no, '$jou_currency')");
            $jou_id = DB::insert_id($conn);

            // 5. Ledger-modposteringer (nulstiller debitor og omsætning)
            // Debit: omsætningskonto (negativ = kreditering af omsætning)
            // Credit: debitor 1000 (negativ = reducer tilgodehavende)
            $total_excl_vat = 0;
            $total_vat      = 0;
            foreach ($orig_lines as $l) {
                $lt = (float)$l['quantity'] * (float)$l['price_each'];
                $total_excl_vat += $lt;
                $total_vat      += $lt * ((float)$l['line_vat_rate'] / 100);
            }
            // Afrundes til øre FØR bogføring - se invoice_post_action.php for
            // hvorfor (SQLite gemmer ellers flydende-komma-støj i ledger).
            $total_excl_vat = round($total_excl_vat, 2);
            $total_vat      = round($total_vat, 2);
            $total_incl_vat = round($total_excl_vat + $total_vat, 2);

            // KRITISK: ledger er ALTID firmaets bogføringsvaluta, uanset
            // fakturaens egen valuta - denne omregning manglede FØR helt (samme fund som i
            // invoice_post_action.php). Bruger den ORIGINALE fakturas egen
            // gemte kurs ($orig['exch_rate']), så modposteringen bruger
            // PRÆCIS samme kurs som den oprindelige postering blev lavet
            // med - ellers ville de to posteringer ikke nødvendigvis nulstille
            // hinanden korrekt, hvis kursen har ændret sig i mellemtiden.
            $orig_exch_rate = (float)($orig['exch_rate'] ?? 0);
            if ($orig_exch_rate > 0) {
                $total_excl_vat = round($total_excl_vat * $orig_exch_rate, 2);
                $total_vat      = round($total_vat * $orig_exch_rate, 2);
                $total_incl_vat = round($total_excl_vat + $total_vat, 2);
            }

            // Konti — samme konfigurerbare konti som posteringsflowet (defaults til
            // standard-kontoplanen). Moms bogføres korrekt på 6900 (Moms, salg),
            // IKKE 2500 (Markedsføring), og debitor på 8100 - ikke 1000/1020.
            $acc_debitor = (int)($global_settings['conf_acc_debitor'] ?? 8100);
            $acc_sales   = (int)($global_settings['conf_acc_sales']   ?? 1000);
            $acc_vat     = (int)($global_settings['conf_acc_vat']     ?? 6900);

            // Kreditnota vender salget (modsatte fortegn af en normal postering):
            //   KREDIT debitor       = − total inkl. moms (reducerer tilgodehavende)
            //   DEBET  omsætning     = + netto            (reducerer omsætning)
            //   DEBET  udgående moms = + momsbeløb        (reducerer moms)
            ledger_post($conn, $jou_id, $acc_debitor, $total_incl_vat * -1);
            ledger_post($conn, $jou_id, $acc_sales, $total_excl_vat);
            if ($total_vat != 0) {
                ledger_post($conn, $jou_id, $acc_vat, $total_vat);
            }

            // 6. Audit-log
            log_action($conn, 'CREATE_CREDIT_NOTE', 'invoices', $new_inv_id,
                ['credit_ref' => $credit_ref, 'orig_status' => $orig['inv_status']],
                ['inv_status' => 'credit', 'credit_date' => $credit_date]);
            log_action($conn, 'MARK_CREDITED', 'invoices', $credit_ref,
                ['inv_status' => $orig['inv_status']],
                ['inv_status' => 'credited', 'credit_inv_id' => $new_inv_id]);

            DB::commit($conn);
            header("Location: invoice_view.php?id=$new_inv_id&msg=credit_created"); exit;

        } catch (Exception $e) {
            DB::rollback($conn);
            $err = lang('@Error creating credit note:') . ' ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ── RENDER BEKRÆFTELSESSIDE ───────────────────────────────────────────────────
htm_Header(lang('@Create Credit Note'));
showMenu();

// Beregn totaler til visning
$sub = 0; $vat = 0;
foreach ($orig_lines as $l) {
    $lt   = (float)$l['quantity'] * (float)$l['price_each'];
    $sub += $lt;
    $vat += $lt * ((float)$l['line_vat_rate'] / 100);
}

$tools = htm_Button('fa-times', '@Cancel', 'secondary', 'sales_hub.php', '', 'data-hint="'.lang('@Discard the credit note and return to the sales hub').'"', '<div></div>', false);
htm_Card_(lang('@Create Credit Note') . ' — ' . lang('@Invoice') . ' #' . $credit_ref, 900, '', '', true, $tools);

if (isset($err)) htm_Alert($err, 'error');

// Info-boks om hvad der sker
$credit_notice = '<strong><i class="fa fa-info-circle"></i> ' . lang('@What happens when you create a credit note:') . '</strong><ul style="margin:8px 0 0 0; padding-left:20px; line-height:1.8;">'
    . '<li>' . lang('@A new credit invoice is created with negative quantities') . '</li>'
    . '<li>' . lang('@The original invoice is marked as credited and can no longer be edited') . '</li>'
    . '<li>' . lang('@A reversal journal entry is posted to the ledger') . '</li>'
    . '<li>' . lang('@The credit note can be sent to the customer from the invoice view') . '</li>'
    . '</ul>';
htm_Banner($credit_notice, 'warning');

// Original faktura — oversigt
echo '<div style="margin-bottom:20px; padding:14px; background:var(--bg-panel); border-radius:6px; border:1px solid var(--border-color);">';
echo '<strong>' . lang('@Original invoice') . ':</strong><br>';
// RETTET (§bugs-batch-22-review, del b): erstattet den håndrullede <table>
// med htm_Table() (se csrf-protection-added.md/htm-alert-banner-refactor.md
// for baggrunden).
$credit_line_rows = [];
foreach ($orig_lines as $l) {
    $lt = (float)$l['quantity'] * (float)$l['price_each'];
    $credit_line_rows[] = [
        htmlspecialchars($l['line_text']),
        number_format($l['quantity'],   2, ',', '.'),
        number_format($l['price_each'], 2, ',', '.'),
        $l['line_vat_rate'] . '%',
        '<div style="text-align:right; font-weight:500;">' . number_format($lt, 2, ',', '.') . '</div>',
    ];
}
htm_Table(['@Description', '@Qty', '@Price', '@VAT%', '@Line total'], $credit_line_rows, 'credit_line_tbl', 100);
echo '<div style="text-align:right; margin-top:10px; font-size:13px;">';
echo lang('@Subtotal') . ': <strong>' . number_format($sub, 2, ',', '.') . '</strong> | ';
echo lang('@VAT') . ': <strong>' . number_format($vat, 2, ',', '.') . '</strong> | ';
echo lang('@Total') . ': <strong style="font-size:1.1em; color:var(--color-danger);">'
    . number_format($sub + $vat, 2, ',', '.') . ' ' . $orig['currency'] . '</strong>';
echo '</div></div>';

// Formular
echo '<form method="POST">';
csrf_field();
echo '<div style="display:flex; gap:15px; margin-bottom:15px;">';
htm_Field(icon:'fa-calendar', labl:'@Credit date', name:'credit_date',
    valu:date('Y-m-d'), type:'date', echo:true);
echo '</div>';
htm_Field(icon:'fa-comment', labl:'@Credit note reason', name:'credit_note',
    valu:lang('@Credit note for invoice') . ' #' . $credit_ref,
    type:'text', hint:'@This text appears on the credit note sent to the customer', echo:true);

echo '<div style="margin-top:20px; display:flex; gap:10px; border-top:1px solid var(--border-color); padding-top:20px;">';
echo '<button type="submit" name="confirm_credit" value="1"
    style="flex:2; background:var(--color-danger); color:white; border:none; padding:12px;
    border-radius:4px; font-weight:bold; cursor:pointer; font-size:14px;">
    <i class="fa fa-undo"></i> ' . lang('@Confirm — Create Credit Note') . '</button>';
echo '<a href="sales_hub.php" style="flex:1; background:var(--bg-panel); color:var(--text-main);
    border:1px solid var(--border-color); padding:12px; border-radius:4px; font-weight:bold;
    text-align:center; text-decoration:none; display:flex; align-items:center; justify-content:center;">
    ' . lang('@Cancel') . '</a>';
echo '</div>';
echo '</form>';

htm_Card_end();
htm_Footer();
?>
