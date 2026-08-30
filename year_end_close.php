<?php # /year_end_close.php v:1.3.0 d:2026-08-30 i:evs
# Afslutter et regnskabsår i to trin: (1) forhåndsvisning - beregner
# periodens indtægts-/udgiftskonto-saldi og nettoresultat uden at bogføre
# noget, (2) bekræftelse - poster ÉN journalpostering der nulstiller de
# konti og lægger nettoresultatet over på den valgte egenkapitalkonto,
# opdaterer accounting_lock_date og markerer regnskabsåret som lukket i
# fiscal_years. year_end_validate_period() håndhæver at hverken periodens
# start- eller slutdato må overlappe en allerede låst periode (forhindrer
# en efterfølgende afslutning i at række baglæns ind i et allerede afsluttet
# år); equity_acc_id valideres server-side til reelt at være acc_type='equity'.
# calc_closing_lines() udelukker selve tidligere lukkeposteringer
# (trans_type='year_end_close') fra periodens egen beregning.
# v1.1.0: rettet to type-forvekslinger der gjorde årsafslutning ikke-
# funktionel: søgte efter acc_type='liability' i stedet for 'equity' til
# egenkapitalkonto-dropdown, og 'revenue' i stedet for 'income' ved
# beregning af posteringer til nulstilling (se regnskabsloven-analyse)
# v1.2.0: årsafslutningens journalpostering fik intet voucher_no og gik uden
# om ledger_post() - brød dermed C1/C2-garantierne (hulfrit bilagsnummer på
# tværs af ALLE posteringstyper, og uforanderlighed for bogførte poster).
# Fundet ved en opfølgende bogføringslov-kontrol, rettet med det samme.
# v1.3.0: tilføjet year_end_validate_period() - manglede is_date_locked()-
# tjek (i modsætning til alle andre posteringsflows), ingen validering af
# period_end >= period_start, og accounting_lock_date kunne flyttes baglæns
# uden varsel. Fundet ved en periodehåndtering-gennemgang.
# v1.4.0: ALVORLIGT FUND - "AND j.is_cancelled = 0" fjernet fra
# calc_closing_lines(). Se inc/annual_report.lib.php for den fulde
# forklaring (samme fund, samme dag).
/* ==========================================================================
   AFSLUT REGNSKABSÅR

   1. Viser en forhåndsvisning af årets resultat pr. income/expense-konto
      for den valgte periode, uden at bogføre noget.
   2. Ved bekræftelse: opretter ÉN afsluttende journalpostering (samme
      journal+ledger-mønster som resten af systemet), der nulstiller
      income/expense-kontiene og lægger nettoresultatet over på den
      valgte egenkapitalkonto.
   3. Opdaterer settings.accounting_lock_date og markerer regnskabsåret
      som lukket i fiscal_years (kræver migrate_fiscal_years.php kørt først).

   VIGTIGT: rører IKKE invoice_no/voucher_no-sekvenserne - de skal ifølge
   bogføringsloven forblive fortløbende på tværs af årsskiftet.
   ========================================================================== */

require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

// Kun admin må afslutte et regnskabsår - det er en irreversibel handling.
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}

$msg = ''; $err = '';

// -------------------------------------------------------------------------
// Hent åbne regnskabsår (kræver fiscal_years-tabellen - se migrate_fiscal_years.php)
// -------------------------------------------------------------------------
$open_years = [];
$fy_check = DB::query($conn, "SELECT year_id, start_date, end_date FROM fiscal_years WHERE is_closed = 0 ORDER BY start_date ASC");
if ($fy_check) {
    while ($fy = DB::fetch_assoc($fy_check)) {
        $open_years[$fy['year_id']] = $fy['start_date'] . ' - ' . $fy['end_date'];
    }
}

// -------------------------------------------------------------------------
// Hent KUN egenkapitalkonti (acc_type = 'equity') til dropdown - forhindrer
// at resultatet ved en fejl posteres på en almindelig indtægts-/udgiftskonto.
// RETTET 2026-08-15: forvekslede før 'liability' med 'equity' (kopierings-
// fejl fra migrate_liability_account.php) - fandt derfor ALDRIG den rigtige
// egenkapitalkonto, selv når migrate_equity_account.php var kørt korrekt.
// -------------------------------------------------------------------------
$acc_options = ['' => '-- ' . lang('@Select Account') . ' --'];
$acc_res = DB::query($conn, "SELECT acc_id, acc_name, acc_type FROM accounts WHERE acc_type = 'equity' ORDER BY acc_id ASC");
if ($acc_res) {
    while ($a = DB::fetch_assoc($acc_res)) {
        $acc_options[$a['acc_id']] = $a['acc_id'] . ' - ' . $a['acc_name'];
    }
}
$has_equity_accounts = (count($acc_options) > 1);

// -------------------------------------------------------------------------
// Hjælpefunktion: beregn saldo pr. income/expense-konto for en periode
// -------------------------------------------------------------------------
function calc_closing_lines($conn, $period_start, $period_end) {
    // RETTET 2026-08-15: filtrerede før på 'revenue', men den faktiske konto-
    // plan (create_all_tables.php, demo- og live-data) bruger 'income' - så
    // ALDRIG nogen indtægtskonti blev fundet, kun udgiftskonti. Årsafslut-
    // ningen har derfor aldrig lukket omsætningen korrekt.
    // BEVIDST intet "j.is_cancelled = 0"-filter - se inc/annual_report.lib.php
    // for hvorfor (rettet 2026-08-19, samme fund): kun ORIGINALEN af en
    // annulleret+modposteret postering får is_cancelled=1, ikke selve mod-
    // posteringen - et filter her ville derfor ekskludere originalen men
    // medtage modposteringen, og lade et rent annulleret spøgelsesbeløb
    // slippe igennem i årsafslutningens nulstilling. Samme mønster som
    // report_income.php allerede brugte korrekt.
    // RETTET (se [[year-end-close-bugs-review]]): manglede samme udelukkelse
    // af trans_type='year_end_close' som blev fundet og rettet i
    // inc/annual_report.lib.php's arl_income_statement() (samme dag, samme
    // rodårsag). Rammer denne funktion specifikt hvis en periode fejlagtigt
    // tillades at overlappe et allerede afsluttet regnskabsår (se
    // year_end_validate_period()'s nye period_start-tjek ovenfor) - uden
    // denne udelukkelse ville en sådan (nu blokeret) gentaget lukning have
    // genberegnet OG genbogført den forrige lukkeposterings egne linjer.
    // RETTET (§bugs-batch-22-review): "j.trans_type != 'year_end_close'" så
    // rigtig ud, men trans_type ER nullable (ingen NOT NULL/standardværdi i
    // skemaet) og reelt NULL på en del af de faktiske journalposter (bekræftet
    // direkte: 3 ud af 6 rækker i en testdatabase). SQL's tre-værdis-logik
    // gør at "NULL != 'year_end_close'" evaluerer til NULL, ikke SAND - en
    // sådan række bliver derfor stiltiende UDELUKKET af hele WHERE-klausulen,
    // ikke inkluderet som ellers tiltænkt. Enhver indtægts-/udgiftspostering
    // hvis journalpost har trans_type=NULL ville dermed aldrig blive lukket/
    // nulstillet af en årsafslutning. Kræver nu eksplicit at NULL også
    // tælles med (kun selve year_end_close-posteringerne skal udelukkes).
    $sql = "SELECT l.acc_id, a.acc_name, a.acc_type, SUM(l.amount) as balance
            FROM ledger l
            JOIN journal j ON l.jou_id = j.jou_id
            JOIN accounts a ON l.acc_id = a.acc_id
            WHERE j.jou_date BETWEEN '$period_start' AND '$period_end'
              AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')
              AND a.acc_type IN ('income', 'expense')
            GROUP BY l.acc_id
            HAVING SUM(l.amount) != 0";
    $res = DB::query($conn, $sql);

    $lines = []; $net_result = 0.0;
    if ($res) {
        while ($row = DB::fetch_assoc($res)) {
            $bal = (float)$row['balance'];
            $lines[] = ['acc_id' => (int)$row['acc_id'], 'acc_name' => $row['acc_name'], 'acc_type' => $row['acc_type'], 'balance' => $bal];
            $net_result += $bal;
        }
    }
    return ['lines' => $lines, 'net_result' => $net_result];
}

// -------------------------------------------------------------------------
// Hjælpefunktion: validér den valgte periode FØR beregning/bogføring.
// Manglede før - i modsætning til alle andre posteringsflows (faktura,
// kreditnota, udgift, bankafstemning), som allerede tjekker is_date_locked()
// - årsafslutningen, den mest irreversible handling i hele systemet, kunne
// bogføres ind i en allerede låst periode. Genbruges af både forhånds-
// visningen (trin 1) og selve bogføringen (trin 2), så en bruger der
// manipulerer de skjulte felter mellem de to trin fanges på begge steder.
// -------------------------------------------------------------------------
function year_end_validate_period($conn, $period_start, $period_end) {
    if ($period_start === '' || $period_end === '') {
        return lang('@Please select a period start and end date.');
    }
    if (strtotime($period_end) < strtotime($period_start)) {
        return lang('@Period end date cannot be before period start date.');
    }
    // is_date_locked() returnerer true hvis $period_end er PÅ eller FØR den
    // nuværende accounting_lock_date - dvs. samme tjek forhindrer BÅDE at
    // bogføre ind i en allerede låst periode OG at flytte lock-datoen
    // baglæns (denne funktion sætter altid lock-datoen til $period_end
    // bagefter, så "ikke låst endnu" garanterer at den nye lock-dato er
    // senere end den gamle).
    if (is_date_locked($conn, $period_end)) {
        return lang('@This period is on or before the current accounting lock date - it may already be closed, or would move the lock date backwards.');
    }
    // RETTET (se [[year-end-close-bugs-review]]): kun period_end blev tjekket
    // mod lock-datoen - period_start blev aldrig tjekket. En periode der
    // starter FØR (eller på) den nuværende lock-dato, men slutter et godt
    // stykke efter, bestod derfor valideringen uændret, selvom den rækker
    // baglæns ind i et allerede afsluttet regnskabsår. Bekræftet direkte:
    // efter en afslutning af 2026-01-01–2026-12-31, blev en efterfølgende
    // "lukning" af 2026-06-01–2027-06-30 fejlagtigt accepteret og genberegnede
    // det allerede afsluttede halvår, inkl. selve den forrige lukkepostering.
    if (is_date_locked($conn, $period_start)) {
        return lang('@This period starts on or before the current accounting lock date - it would overlap with an already closed period.');
    }
    return null;
}

// RETTET (se [[year-end-close-bugs-review]]): $acc_options-dropdown'en viser
// kun egenkapitalkonti (acc_type = 'equity'), men INTET tjekkede server-side
// at det POSTEDE equity_acc_id rent faktisk var en af dem - præcis den fejl
// filens egen kommentar ved $acc_options siger den forhindrer. Bekræftet
// direkte: postede equity_acc_id=1000 (en almindelig indtægtskonto) blev
// accepteret uden indsigelse, og hele årets nettoresultat blev bogført
// derpå i stedet for at blive afvist.
function year_end_is_equity_account($conn, $acc_id) {
    if ($acc_id <= 0) return false;
    $row = DB::fetch_assoc(DB::query($conn, "SELECT acc_type FROM accounts WHERE acc_id = " . (int)$acc_id));
    return $row && $row['acc_type'] === 'equity';
}

$preview = null;
$posted_period_start = $_POST['period_start'] ?? '';
$posted_period_end   = $_POST['period_end'] ?? '';
$posted_year_id      = (int)($_POST['year_id'] ?? 0);
$posted_equity_acc   = (int)($_POST['equity_acc_id'] ?? 0);

// -------------------------------------------------------------------------
// STEP 1: FORHÅNDSVISNING - beregner og VISER, men bogfører intet endnu
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview'])) {
    $ps = DB::escape($conn, $posted_period_start);
    $pe = DB::escape($conn, $posted_period_end);

    if ($posted_equity_acc <= 0) {
        $err = lang('@Please select an equity account to post the result to.');
    } elseif (!year_end_is_equity_account($conn, $posted_equity_acc)) {
        $err = lang('@The selected account is not an equity account.');
    } elseif (($period_err = year_end_validate_period($conn, $posted_period_start, $posted_period_end)) !== null) {
        $err = $period_err;
    } else {
        $preview = calc_closing_lines($conn, $ps, $pe);
        if (empty($preview['lines'])) {
            $err = lang('@No income or expense postings found in the selected period - nothing to close.');
            $preview = null;
        }
    }
}

// -------------------------------------------------------------------------
// STEP 2: BOGFØR AFSLUTNINGEN (efter bekræftelse fra preview)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_close'])) {
    $year_id      = (int)$_POST['year_id'];
    $period_start = DB::escape($conn, $_POST['period_start']);
    $period_end   = DB::escape($conn, $_POST['period_end']);
    $equity_acc   = (int)$_POST['equity_acc_id'];

    if ($equity_acc <= 0) {
        $err = lang('@Please select an equity account to post the result to.');
    } elseif (!year_end_is_equity_account($conn, $equity_acc)) {
        $err = lang('@The selected account is not an equity account.');
    } elseif (($period_err = year_end_validate_period($conn, $_POST['period_start'], $_POST['period_end'])) !== null) {
        $err = $period_err;
    } else {
        $calc = calc_closing_lines($conn, $period_start, $period_end);
        $closing_lines = array_map(fn($l) => ['acc_id' => $l['acc_id'], 'amount' => -$l['balance']], $calc['lines']);
        $net_result = $calc['net_result'];

        if (empty($closing_lines)) {
            $err = lang('@No income or expense postings found in the selected period - nothing to close.');
        } else {
          // RETTET (§bugs-batch-22-review): KRITISK - hele denne flerdelte
          // bogføring (ny journalpost + N ledger-linjer + ny lock-dato +
          // markering af regnskabsåret som lukket) kørte HELT uden om
          // DB::begin_transaction()/commit()/rollback (i modsætning til alle
          // andre flerdelte posteringsflows i appen), og intet atomisk tjek
          // forhindrede is_closed=1 i at blive sat blindt uanset dens
          // nuværende værdi. Et dobbeltklik på "Bekræft lukning" (eller en
          // gensendt formular) kunne derfor nå at poste TO FULDE
          // lukkeposteringer for samme regnskabsår - hver med sin egen fulde
          // nulstilling af indtægts-/udgiftskonti OG sin egen nettoresultat-
          // postering til egenkapitalkontoen, en potentielt alvorlig, dobbelt
          // regnskabsmæssig forvrængning. Regnskabsåret "kræves" nu atomisk
          // FØR noget som helst bogføres (WHERE year_id=? AND is_closed=0) -
          // et 0-rækker-resultat (en anden, hurtigere forespørgsel nåede
          // først) afbryder hele lukningen med det samme, og hele
          // transaktionen (inkl. et evt. allerede oprettet journal-/ledger-
          // arbejde) rulles tilbage i stedet for at lade en dublet ske.
          DB::begin_transaction($conn);
          try {
            if ($year_id > 0) {
                $claim_res = DB::prepare_and_execute($conn,
                    "UPDATE fiscal_years SET is_closed = 1 WHERE year_id = ? AND is_closed = 0", [$year_id]);
                if (!$claim_res || DB::affected_rows($conn, $claim_res) < 1) {
                    throw new Exception(lang('@This fiscal year was already closed by another, simultaneous request (race) - nothing was duplicated.'));
                }
            }

            // Opret journalpostering. Får et bilagsnummer fra den fælles tæller
            // (manglede FØR helt - årsafslutningen var dermed usporbar i
            // bilagsrækken, OG ville forblive hård-sletbar under den nyere
            // regel i ledger_view.php der kun beskytter poster MED voucher_no).
            $voucher_no = next_voucher_no($conn);
            // RETTET (§currency-setting-is-cosmetic-label): journal.currency
            // blev altid sat til hardkodet 'DKK', uanset firmaets faktisk
            // konfigurerede bogføringsvaluta.
            DB::insert($conn, 'journal', [
                'jou_date'   => $period_end,
                'jou_text'   => lang('@Year-end closing') . ' ' . $period_start . ' - ' . $period_end,
                'trans_type' => 'year_end_close',
                'currency'   => $global_settings['currency'] ?? 'DKK',
                'voucher_no' => $voucher_no
            ]);
            $jou_id = DB::insert_id($conn);

            // Bogfør nulstillings-linjerne for hver income/expense-konto. Via
            // ledger_post() (ikke rå DB::insert) så created_at/user_id også
            // udfyldes, ligesom alle andre posteringsflows.
            foreach ($closing_lines as $line) {
                ledger_post($conn, $jou_id, (int)$line['acc_id'], (float)$line['amount']);
            }

            // Bogfør nettoresultatet over på egenkapitalkontoen (modsat fortegn,
            // så hele posteringen går i nul - dobbelt bogføring)
            ledger_post($conn, $jou_id, $equity_acc, $net_result);

            // Opdater lock-dato, så det afsluttede år ikke kan rettes bagudrettet
            DB::query($conn, "DELETE FROM settings WHERE setting_key = 'accounting_lock_date'");
            DB::insert($conn, 'settings', ['setting_key' => 'accounting_lock_date', 'setting_value' => $period_end]);

            // Udfyld de resterende detaljer på regnskabsåret - selve
            // is_closed=1-flaget blev allerede atomisk sat/reserveret
            // ovenfor, FØR noget blev bogført.
            if ($year_id > 0) {
                $sql_close = DB::is_sqlite()
                    ? "UPDATE fiscal_years SET closed_at = CURRENT_TIMESTAMP, closed_by = ?, closing_jou_id = ?, equity_acc_id = ?, net_result = ? WHERE year_id = ?"
                    : "UPDATE fiscal_years SET closed_at = NOW(), closed_by = ?, closing_jou_id = ?, equity_acc_id = ?, net_result = ? WHERE year_id = ?";
                DB::prepare_and_execute($conn, $sql_close, [$_SESSION['user_id'], $jou_id, $equity_acc, $net_result, $year_id]);
            }

            // RETTET 2026-08-20: brugte før et hånd-rullet, direkte INSERT INTO
            // audit_log i stedet for den fælles log_action()-hjælpefunktion -
            // fungerede, men brugte små bogstaver ("year_end_close") i stedet
            // for den ellers konsekvente STORE_BOGSTAVER-navngivning
            // (CANCEL_EXPENSE, POST_INVOICE osv.), og var reelt den eneste
            // log_action-uafhængige logskrivning i hele kodebasen. Fundet under
            // en revisionsspor-gennemgang.
            log_action($conn, 'YEAR_END_CLOSE', 'journal', $jou_id, null,
                ['period' => "$period_start - $period_end", 'net_result' => $net_result, 'equity_acc' => $equity_acc]);

            DB::commit($conn);
          } catch (Exception $e) {
            DB::rollback($conn);
            $err = $e->getMessage();
          }
        }

        if (!isset($err)) {
            $msg = lang('@Fiscal year closed successfully.') . ' '
                 . lang('@Net result:') . ' ' . number_format($net_result, 2, ',', '.') . ' ' . htmlspecialchars($global_settings['currency'] ?? 'DKK') . '. '
                 . lang('@Journal entry:') . ' #' . $jou_id;
        }
    }
}

htm_Header('@Close Fiscal Year');
showMenu();

echo "<div style='max-width:900px; margin:0 auto; padding:10px;'>";

if ($msg) htm_Alert($msg, 'success');
if ($err) htm_Alert($err, 'error');

htm_Card_('@Close Fiscal Year', 900);

if (empty($open_years)) {
    htm_Alert(lang('@No open fiscal years found. Run migrate_fiscal_years.php once to set one up.'), 'error');
} elseif (!$has_equity_accounts) {
    htm_Alert(lang('@No equity accounts found (acc_type = \'equity\'). Run migrate_equity_account.php once, or add one manually in the Chart of Accounts, before closing a fiscal year.'), 'error');
} elseif ($preview !== null) {
    // -------------------------------------------------------------------
    // VIS FORHÅNDSVISNING + BEKRÆFT-FORMULAR (intet er bogført endnu)
    // -------------------------------------------------------------------
    echo '<p style="font-size:0.9em; color:var(--text-muted);">' . lang('@Review the calculation below. Nothing has been posted yet.') . '</p>';

    $headers = ['@Account', '@Type', '@Balance'];
    $rows = [];
    foreach ($preview['lines'] as $l) {
        // RETTET: $l['acc_type'] kommer altid småt fra databasen ('income',
        // 'expense' osv.), men den faktiske oversættelsesnøgle er stort
        // forbogstav (@Income, @Expense) - lang('@income') matchede derfor
        // aldrig noget i languages.json, og kontotypen kunne aldrig oversættes
        // på denne forhåndsvisning før en regnskabsafslutning. Samme fejlklasse
        // som quote_view.php's status-stempel, fundet i samme runde.
        // NB: lang('@'.ucfirst($l['acc_type'])) kan frase-skanneren ikke selv
        // opdage - forespørgslen ovenfor begrænser acc_type til kun 'income'/
        // 'expense' her, så de faktiske nøgler nævnes bevidst som streng-
        // literaler: '@Income', '@Expense'
        $rows[] = [
            $l['acc_id'] . ' - ' . htmlspecialchars($l['acc_name']),
            lang('@' . ucfirst($l['acc_type'])),
            '<div style="text-align:right; font-weight:bold; color:' . ($l['balance'] >= 0 ? 'green' : 'red') . ';">' . number_format($l['balance'], 2, ',', '.') . '</div>'
        ];
    }
    htm_Table($headers, $rows, 'preview_tbl', 100);

    echo '<div style="display:flex; justify-content:space-between; align-items:center; padding:15px 0; border-top:2px solid var(--color-primary); margin-top:10px; font-weight:bold; font-size:1.1em;">';
    echo '<span>' . lang('@Net Result') . '</span>';
    echo '<span style="color:' . ($preview['net_result'] >= 0 ? 'green' : 'red') . ';">' . number_format($preview['net_result'], 2, ',', '.') . ' ' . htmlspecialchars($global_settings['currency'] ?? 'DKK') . '</span>';
    echo '</div>';

    echo '<form method="post" onsubmit="return confirm(\'' . htmlspecialchars(lang('@Are you sure you want to close this fiscal year? This cannot be undone.'), ENT_QUOTES) . '\');">';
    csrf_field();
    echo '<input type="hidden" name="year_id" value="' . $posted_year_id . '">';
    echo '<input type="hidden" name="period_start" value="' . htmlspecialchars($posted_period_start) . '">';
    echo '<input type="hidden" name="period_end" value="' . htmlspecialchars($posted_period_end) . '">';
    echo '<input type="hidden" name="equity_acc_id" value="' . $posted_equity_acc . '">';
    echo '<div style="display:flex; gap:10px; margin-top:15px;">';
    htm_Button(icon: 'fa-lock', labl: '@Close Fiscal Year', type: 'danger', styl: 'flex:2; padding:12px;', attr: 'name="confirm_close" type="submit" data-hint="'.lang('@Post the closing entry and lock this fiscal year').'"');
    htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'year_end_close.php', styl: 'flex:1;', attr: 'data-hint="'.lang('@Cancel and choose a different period').'"');
    echo '</div>';
    echo '</form>';
} else {
    // -------------------------------------------------------------------
    // TRIN 1: VALG AF PERIODE + EGENKAPITALKONTO -> SUBMITTER TIL PREVIEW
    // -------------------------------------------------------------------
    ?>
    <p style="font-size:0.9em; color:var(--text-muted);">
        <?php echo lang('@This will zero out all income and expense accounts for the selected period and post the net result to the equity account you choose below. Nothing is posted until you confirm the preview on the next step.'); ?>
    </p>

    <form method="post">
        <?php csrf_field(); ?>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
            <div>
                <label style="font-size:0.85em; font-weight:bold; display:block; margin-bottom:5px;"><?php echo lang('@Fiscal Year'); ?></label>
                <?php
                    // Når et regnskabsår vælges, udfyldes periode-felterne automatisk via JS
                    echo '<select name="year_id" id="year_id" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px;" onchange="fillPeriod(this)">';
                    foreach ($open_years as $yid => $label) {
                        echo '<option value="'.$yid.'" data-start="'.substr($label,0,10).'" data-end="'.substr($label,-10).'">'.$label.'</option>';
                    }
                    echo '</select>';
                ?>
            </div>
            <div>
                <label style="font-size:0.85em; font-weight:bold; display:block; margin-bottom:5px;"><?php echo lang('@Post Result To'); ?></label>
                <?php htm_Select('equity_acc_id', $acc_options, '', 'width:100%; padding:8px;'); ?>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:20px;">
            <div>
                <label style="font-size:0.85em; font-weight:bold; display:block; margin-bottom:5px;"><?php echo lang('@Period Start'); ?></label>
                <input type="date" name="period_start" id="period_start" required style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; box-sizing:border-box;">
            </div>
            <div>
                <label style="font-size:0.85em; font-weight:bold; display:block; margin-bottom:5px;"><?php echo lang('@Period End'); ?></label>
                <input type="date" name="period_end" id="period_end" required style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; box-sizing:border-box;">
            </div>
        </div>

        <?php
        htm_Button(
            icon: 'fa-calculator', labl: '@Calculate Preview', type: 'primary',
            styl: 'width:100%; padding:12px;', attr: 'name="preview" type="submit" data-hint="'.lang('@Calculate the closing result for this period without posting anything').'"'
        );
        ?>
    </form>

    <script>
    function fillPeriod(sel) {
        var opt = sel.options[sel.selectedIndex];
        document.getElementById('period_start').value = opt.getAttribute('data-start');
        document.getElementById('period_end').value = opt.getAttribute('data-end');
    }
    document.addEventListener('DOMContentLoaded', function() {
        fillPeriod(document.getElementById('year_id'));
    });
    </script>
    <?php
}

htm_Card_end();
echo "</div>";
htm_Footer();
?>
