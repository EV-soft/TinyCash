<?php # /bank_balance_check.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 26):
# reconcile_list.php matcher enkelte banklinjer, men ingen side sammenlignede
# hovedbogens bankkonto-saldo mod den FAKTISKE saldo på selve bankkontoudtoget
# pr. en given dato - samme kontrolprincip som subledger_reconciliation.php
# allerede gør for debitorer/kreditorer, blot for banken/kassen. Bevidst INGEN
# gemt historik (samme som subledger_reconciliation.php) - ren on-demand
# sammenligning, ingen ny tabel nødvendig.
#
# RETTET (BEK 811/2026-gennemgang, Bilag 3 "bankafstemning", se
# [[digital-standard-bookkeeping-registration-goal]]): on-demand-sammenligning
# alene efterlod intet bevis for, HVORNÅR og AF HVEM en konto sidst reelt blev
# afstemt - kun at værktøjet FINDES, ikke at det bruges løbende. "Gem
# afstemning" gør nu hver udført sammenligning til en permanent, dateret post
# i den nye bank_reconciliations-tabel (db-setup/migrate_bank_reconciliations.
# php) - bevidst APPEND-ONLY (intet rediger/slet-endpoint er bygget), samme
# bevis-må-ikke-kunne-ændres-filosofi som append-only-triggerne på journal/
# ledger/expenses.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$has_recon_table = @DB::query($conn, "SELECT 1 FROM bank_reconciliations LIMIT 1") !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recon'])) {
    if (!csrf_verify()) {
        header('Location: bank_balance_check.php?msg=csrf'); exit;
    }
    if (!$has_recon_table) {
        header('Location: bank_balance_check.php?msg=no_table'); exit;
    }
    $p_acc_id    = (int)($_POST['acc_id'] ?? 0);
    $p_stmt_date = $_POST['stmt_date'] ?? '';
    // RETTET (find-fem-fejl-gennemgang): gl_balance/difference blev FØR taget
    // direkte fra $_POST og skrevet uændret ind i den append-only-ment
    // afstemningspost - en bruger kunne (fx via devtools) ændre det skjulte
    // gl_balance-felt og gemme en falsk "afstemt, 0 kr. difference"-post,
    // selvom hovedbogens reelle saldo var noget andet. Underminerede netop
    // det, funktionen skal bevise. gl_balance genberegnes nu her, SERVER-SIDE,
    // fra journal/ledger - nøjagtig samme forespørgsel som selve sammenlignings-
    // visningen ovenfor bruger. stmt_balance er bevidst STADIG fra $_POST - det
    // er brugerens egen aflæsning af det fysiske/digitale kontoudtog, intet
    // TinyCash selv kan genberegne.
    $p_stmt_bal  = (float)($_POST['stmt_balance'] ?? 0);
    $p_note      = trim($_POST['note'] ?? '');

    if ($p_acc_id > 0 && $p_stmt_date !== '') {
        $ds_save = DB::escape($conn, $p_stmt_date);
        $gl_row_save = DB::fetch_assoc(DB::query($conn, "SELECT SUM(l.amount) AS bal FROM ledger l JOIN journal j ON l.jou_id = j.jou_id WHERE l.acc_id = $p_acc_id AND j.jou_date <= '$ds_save'"));
        $p_gl_bal = (float)($gl_row_save['bal'] ?? 0);
        $p_diff   = round($p_gl_bal - $p_stmt_bal, 2);
        DB::insert($conn, 'bank_reconciliations', [
            'acc_id'       => $p_acc_id,
            'stmt_date'    => $p_stmt_date,
            'gl_balance'   => $p_gl_bal,
            'stmt_balance' => $p_stmt_bal,
            'difference'   => $p_diff,
            'note'         => $p_note !== '' ? $p_note : null,
            'created_by'   => (int)($_SESSION['user_id'] ?? 0),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        log_action($conn, 'SAVE_BANK_RECONCILIATION', 'bank_reconciliations', 0, null,
            ['acc_id' => $p_acc_id, 'stmt_date' => $p_stmt_date, 'difference' => $p_diff]);
    }
    header('Location: bank_balance_check.php?acc_id=' . $p_acc_id . '&stmt_date=' . urlencode($p_stmt_date) . '&stmt_bal=' . urlencode(number_format($p_stmt_bal, 2, ',', '')) . '&msg=saved');
    exit;
}

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

require_once __DIR__ . '/inc/accounts.inc.php';     // valglisten: slukkede konti udeladt, dæmpede nederst
$acc_res = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE acc_type = 'bank' AND " . acc_visible_sql($conn, '', [(int)($_GET['acc_id'] ?? 0), (int)($s['conf_acc_bank'] ?? 0)]) . " ORDER BY " . acc_order_sql($conn) . "acc_id");
$acc_options = [];
while ($ar = DB::fetch_assoc($acc_res)) { $acc_options[$ar['acc_id']] = $ar['acc_id'] . ' - ' . acc_label($ar['acc_name'], $ar['acc_status'] ?? null); }

$acc_id    = isset($_GET['acc_id']) ? (int)$_GET['acc_id'] : (int)($s['conf_acc_bank'] ?? 0);
$stmt_date = isset($_GET['stmt_date']) && $_GET['stmt_date'] !== '' ? $_GET['stmt_date'] : date('Y-m-d');
$stmt_bal  = isset($_GET['stmt_bal']) && $_GET['stmt_bal'] !== '' ? parse_dk_number($_GET['stmt_bal']) : null;

htm_Header('@Bank Balance Reconciliation');
showMenu();

echo "<div style='max-width:800px; margin:0 auto; padding:10px;'>";

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'saved') htm_Alert(lang('@Reconciliation saved - this is now a permanent, dated record.'), 'success', 800, true);
    elseif ($_GET['msg'] === 'csrf') htm_Alert(lang('@Security check failed (CSRF). Please try again.'), 'error', 800, true);
    elseif ($_GET['msg'] === 'no_table') htm_Alert(lang('@This installation has not yet run the "Saved bank reconciliations" migration (see run_migrate.php).'), 'error', 800, true);
}

echo '<p style="color:var(--text-muted); margin-bottom:20px;">'
    . lang('@Compares the general ledger balance of a bank/cash account against the actual closing balance shown on the real bank statement as of a chosen date - catches missing or duplicate imports that reconcile_list.php\'s line-by-line matching alone would not surface.')
    . '</p>';

htm_Card_('@Bank Balance Reconciliation', 800);

if (empty($acc_options)) {
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@No accounts of type "bank" found in the Chart of Accounts.'), 'warning');
} else {
    echo '<form method="get" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:20px;">';
    echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Account') . '</label>';
    htm_Select('acc_id', $acc_options, (string)$acc_id, 'width:260px; padding:6px;');
    echo '</div>';
    echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Statement Date') . '</label>';
    echo '<input type="date" name="stmt_date" value="' . htmlspecialchars($stmt_date) . '" style="padding:6px;"></div>';
    echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Statement Closing Balance') . '</label>';
    echo '<input type="text" name="stmt_bal" value="' . htmlspecialchars($_GET['stmt_bal'] ?? '') . '" placeholder="' . htmlspecialchars($cur) . '" style="width:150px; padding:6px;"></div>';
    htm_Button(labl: '@Compare', type: 'primary', attr: 'data-hint="'.lang('@Compare the ledger balance against this statement balance').'"');
    echo '</form>';

    if ($acc_id > 0 && $stmt_bal !== null) {
        $ds = DB::escape($conn, $stmt_date);
        $gl_row = DB::fetch_assoc(DB::query($conn, "SELECT SUM(l.amount) AS bal FROM ledger l JOIN journal j ON l.jou_id = j.jou_id WHERE l.acc_id = $acc_id AND j.jou_date <= '$ds'"));
        $gl_bal = (float)($gl_row['bal'] ?? 0);
        $diff = round($gl_bal - $stmt_bal, 2);

        echo '<div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:15px;">';
        echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@General Ledger Balance') . '</div><div style="font-weight:bold; font-size:1.2em;"><a href="account_card.php?acc_id=' . $acc_id . '&to=' . htmlspecialchars($stmt_date) . '">' . number_format($gl_bal, 2, ',', '.') . ' ' . $cur . '</a></div></div>';
        echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Statement Closing Balance') . '</div><div style="font-weight:bold; font-size:1.2em;">' . number_format($stmt_bal, 2, ',', '.') . ' ' . $cur . '</div></div>';
        echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Difference') . '</div><div style="font-weight:bold; font-size:1.2em; color:' . (abs($diff) > 0.01 ? 'var(--color-danger)' : 'inherit') . ';">' . number_format($diff, 2, ',', '.') . ' ' . $cur . '</div></div>';
        echo '</div>';

        if (abs($diff) <= 0.01) {
            htm_Alert(lang('@Reconciled - the ledger balance matches the bank statement.'), 'success', 800, true);
        } else {
            htm_Alert(sprintf(lang('@NOT reconciled - the ledger and the bank statement differ by %s as of %s. Check reconcile_list.php for unmatched or duplicate lines.'), number_format(abs($diff), 2, ',', '.') . ' ' . $cur, date(CONF_DATE_FORMAT, strtotime($stmt_date))), 'error', 800, true);
        }
        echo '<p><a href="reconcile_list.php">' . lang('@Go to Bank Reconciliation') . '</a> · <a href="account_card.php?acc_id=' . $acc_id . '">' . lang('@View Account Card') . '</a></p>';

        if ($has_recon_table) {
            echo '<form method="post" style="border-top:1px solid var(--border-color); padding-top:15px; margin-top:10px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">';
            csrf_field();
            echo '<input type="hidden" name="acc_id" value="' . $acc_id . '">';
            echo '<input type="hidden" name="stmt_date" value="' . htmlspecialchars($stmt_date) . '">';
            // RETTET (find-fem-fejl-gennemgang): gl_balance/difference sendes ikke
            // længere med - gemme-handleren genberegner selv gl_balance server-
            // side, se dens egen begrundelse. stmt_balance er brugerens eget
            // aflæste tal og er den ENESTE af de tre, der reelt skal sendes.
            echo '<input type="hidden" name="stmt_balance" value="' . htmlspecialchars((string)$stmt_bal) . '">';
            echo '<div style="flex:1; min-width:200px;"><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Note (optional)') . '</label>';
            echo '<input type="text" name="note" style="width:100%; padding:6px;" placeholder="' . htmlspecialchars(lang('@e.g. explanation of a difference')) . '"></div>';
            htm_Button(icon: 'fa-check', labl: '@Save Reconciliation', type: 'success', attr: 'type="submit" name="save_recon" value="1" data-hint="'.lang('@Permanently record that this account was reconciled as of this date - cannot be edited or deleted afterwards.').'"');
            echo '</form>';
        }
    }

    // --- AFSTEMNINGSHISTORIK (BEK 811/2026, se filens egen begrundelse) ---
    if ($has_recon_table && $acc_id > 0) {
        echo '<h3 style="margin-top:25px; border-top:1px solid var(--border-color); padding-top:15px;">' . lang('@Reconciliation History') . '</h3>';
        $hist_res = DB::query($conn, "SELECT r.*, u.username FROM bank_reconciliations r LEFT JOIN users u ON u.user_id = r.created_by WHERE r.acc_id = $acc_id ORDER BY r.stmt_date DESC, r.recon_id DESC LIMIT 25");
        $rows = [];
        while ($hist_res && ($h = DB::fetch_assoc($hist_res))) {
            $h_diff = (float)$h['difference'];
            $rows[] = [
                date(CONF_DATE_FORMAT, strtotime($h['stmt_date'])),
                number_format((float)$h['gl_balance'], 2, ',', '.') . ' ' . $cur,
                number_format((float)$h['stmt_balance'], 2, ',', '.') . ' ' . $cur,
                '<span style="' . (abs($h_diff) > 0.01 ? 'color:var(--color-danger); font-weight:bold;' : '') . '">' . number_format($h_diff, 2, ',', '.') . ' ' . $cur . '</span>',
                htmlspecialchars($h['username'] ?? '-'),
                htmlspecialchars($h['note'] ?? ''),
            ];
        }
        if ($rows) {
            htm_Table(['@Statement Date', '@General Ledger Balance', '@Statement Closing Balance', '@Difference', '@User', '@Note'], $rows, 'bankReconHistTbl', 25);
        } else {
            echo '<p style="color:var(--text-muted);">' . lang('@No saved reconciliations for this account yet.') . '</p>';
        }
    }
}

htm_Card_end();
echo "</div>";
htm_Footer();
