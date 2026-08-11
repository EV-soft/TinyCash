<?php # /year_end_close.php v:1.0.0 d:2026-07-08 i:claude
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
// Hent KUN egenkapitalkonti (acc_type = 'liability') til dropdown - forhindrer
// at resultatet ved en fejl posteres på en almindelig indtægts-/udgiftskonto.
// -------------------------------------------------------------------------
$acc_options = ['' => '-- ' . lang('@Select Account') . ' --'];
$acc_res = DB::query($conn, "SELECT acc_id, acc_name, acc_type FROM accounts WHERE acc_type = 'liability' ORDER BY acc_id ASC");
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
    $sql = "SELECT l.acc_id, a.acc_name, a.acc_type, SUM(l.amount) as balance
            FROM ledger l
            JOIN journal j ON l.jou_id = j.jou_id
            JOIN accounts a ON l.acc_id = a.acc_id
            WHERE j.jou_date BETWEEN '$period_start' AND '$period_end'
              AND a.acc_type IN ('revenue', 'expense')
              AND j.is_cancelled = 0
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
    } else {
        $calc = calc_closing_lines($conn, $period_start, $period_end);
        $closing_lines = array_map(fn($l) => ['acc_id' => $l['acc_id'], 'amount' => -$l['balance']], $calc['lines']);
        $net_result = $calc['net_result'];

        if (empty($closing_lines)) {
            $err = lang('@No income or expense postings found in the selected period - nothing to close.');
        } else {
            // Opret journalpostering
            DB::insert($conn, 'journal', [
                'jou_date'   => $period_end,
                'jou_text'   => lang('@Year-end closing') . ' ' . $period_start . ' - ' . $period_end,
                'trans_type' => 'year_end_close',
                'currency'   => 'DKK'
            ]);
            $jou_id = DB::insert_id($conn);

            // Bogfør nulstillings-linjerne for hver income/expense-konto
            foreach ($closing_lines as $line) {
                DB::insert($conn, 'ledger', [
                    'jou_id' => $jou_id, 'acc_id' => $line['acc_id'], 'amount' => $line['amount']
                ]);
            }

            // Bogfør nettoresultatet over på egenkapitalkontoen (modsat fortegn,
            // så hele posteringen går i nul - dobbelt bogføring)
            DB::insert($conn, 'ledger', [
                'jou_id' => $jou_id, 'acc_id' => $equity_acc, 'amount' => $net_result
            ]);

            // Opdater lock-dato, så det afsluttede år ikke kan rettes bagudrettet
            DB::query($conn, "DELETE FROM settings WHERE setting_key = 'accounting_lock_date'");
            DB::insert($conn, 'settings', ['setting_key' => 'accounting_lock_date', 'setting_value' => $period_end]);

            // Marker regnskabsåret som lukket
            if ($year_id > 0) {
                $sql_close = DB::is_sqlite()
                    ? "UPDATE fiscal_years SET is_closed = 1, closed_at = CURRENT_TIMESTAMP, closed_by = ?, closing_jou_id = ?, equity_acc_id = ?, net_result = ? WHERE year_id = ?"
                    : "UPDATE fiscal_years SET is_closed = 1, closed_at = NOW(), closed_by = ?, closing_jou_id = ?, equity_acc_id = ?, net_result = ? WHERE year_id = ?";
                DB::prepare_and_execute($conn, $sql_close, [$_SESSION['user_id'], $jou_id, $equity_acc, $net_result, $year_id]);
            }

            // Log i audit_log, hvis den findes (bevidst "best effort" - fejler
            // ikke hele processen hvis audit_log skulle mangle)
            DB::query($conn, "INSERT INTO audit_log (user_id, action_type, table_name, row_id, new_values) VALUES ("
                . (int)$_SESSION['user_id'] . ", 'year_end_close', 'journal', $jou_id, "
                . "'" . DB::escape($conn, json_encode(['period' => "$period_start - $period_end", 'net_result' => $net_result, 'equity_acc' => $equity_acc])) . "')");

            $msg = lang('@Fiscal year closed successfully.') . ' '
                 . lang('@Net result:') . ' ' . number_format($net_result, 2, ',', '.') . ' DKK. '
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
    htm_Alert(lang('@No equity accounts found (acc_type = \'liability\'). Run migrate_equity_account.php once, or add one manually in the Chart of Accounts, before closing a fiscal year.'), 'error');
} elseif ($preview !== null) {
    // -------------------------------------------------------------------
    // VIS FORHÅNDSVISNING + BEKRÆFT-FORMULAR (intet er bogført endnu)
    // -------------------------------------------------------------------
    echo '<p style="font-size:0.9em; color:var(--text-muted);">' . lang('@Review the calculation below. Nothing has been posted yet.') . '</p>';

    $headers = ['@Account', '@Type', '@Balance'];
    $rows = [];
    foreach ($preview['lines'] as $l) {
        $rows[] = [
            $l['acc_id'] . ' - ' . htmlspecialchars($l['acc_name']),
            lang('@' . $l['acc_type']),
            '<div style="text-align:right; font-weight:bold; color:' . ($l['balance'] >= 0 ? 'green' : 'red') . ';">' . number_format($l['balance'], 2, ',', '.') . '</div>'
        ];
    }
    htm_Table($headers, $rows, 'preview_tbl', 100);

    echo '<div style="display:flex; justify-content:space-between; align-items:center; padding:15px 0; border-top:2px solid var(--color-primary); margin-top:10px; font-weight:bold; font-size:1.1em;">';
    echo '<span>' . lang('@Net Result') . '</span>';
    echo '<span style="color:' . ($preview['net_result'] >= 0 ? 'green' : 'red') . ';">' . number_format($preview['net_result'], 2, ',', '.') . ' DKK</span>';
    echo '</div>';

    echo '<form method="post" onsubmit="return confirm(\'' . htmlspecialchars(lang('@Are you sure you want to close this fiscal year? This cannot be undone.'), ENT_QUOTES) . '\');">';
    echo '<input type="hidden" name="year_id" value="' . $posted_year_id . '">';
    echo '<input type="hidden" name="period_start" value="' . htmlspecialchars($posted_period_start) . '">';
    echo '<input type="hidden" name="period_end" value="' . htmlspecialchars($posted_period_end) . '">';
    echo '<input type="hidden" name="equity_acc_id" value="' . $posted_equity_acc . '">';
    echo '<div style="display:flex; gap:10px; margin-top:15px;">';
    htm_Button(icon: 'fa-lock', labl: '@Close Fiscal Year', type: 'danger', styl: 'flex:2; padding:12px;', attr: 'name="confirm_close" type="submit"');
    htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'year_end_close.php', styl: 'flex:1;');
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
            styl: 'width:100%; padding:12px;', attr: 'name="preview" type="submit"'
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
