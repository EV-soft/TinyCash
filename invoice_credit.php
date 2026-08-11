<?php # /invoice_credit.php v:1.2.0 d:2026-08-11 i:evs 
# Kreditnota-flow — opretter en modpostering til en bogført faktura
# Kaldet fra sales_hub.php med ?credit_ref=INV_ID
# Opfylder bogføringsloven § 16: bogførte fakturaer må ikke ændres,
# fejl korrigeres med en kreditnota der nulstiller den originale faktura
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
            DB::query($conn, "INSERT INTO invoices
                (cust_id, inv_date, inv_due_date, inv_status, inv_note,
                 currency, credit_ref, proj_id)
                VALUES ({$orig['cust_id']}, '$credit_date', '$credit_date', 'credit',
                '{$credit_note}', '{$orig['currency']}', $credit_ref,
                " . ($orig['proj_id'] ? $orig['proj_id'] : 'NULL') . ")");
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
            }

            // 3. Marker original faktura som krediteret
            DB::query($conn, "UPDATE invoices SET inv_status = 'credited' WHERE inv_id = $credit_ref");

            // 4. Journalpostering — modpostering
            $jou_text = DB::escape($conn, lang('@Credit note for invoice') . ' #' . $credit_ref . ': ' . $orig['cust_name']);
            DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type)
                VALUES ('$credit_date', '$jou_text', 'credit_note')");
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
            $total_incl_vat = $total_excl_vat + $total_vat;

            // Debitor-konto (1000): negativ = tilgodehavende reduceres
            DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount)
                VALUES ($jou_id, 1000, " . ($total_incl_vat * -1) . ")");
            // Omsætningskonto (1020 standard): positiv modpostering
            DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount)
                VALUES ($jou_id, 1020, $total_excl_vat)");
            // Moms-konto (2500 standard): positiv modpostering
            if ($total_vat != 0) {
                DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount)
                    VALUES ($jou_id, 2500, $total_vat)");
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

$tools = htm_Button('fa-times', '@Cancel', 'secondary', 'sales_hub.php', '', '', '<div></div>', false);
htm_Card_(lang('@Create Credit Note') . ' — ' . lang('@Invoice') . ' #' . $credit_ref, 900, '', '', true, $tools);

if (isset($err)) htm_Alert($err, 'error');

// Info-boks om hvad der sker
echo '<div style="margin-bottom:20px; padding:14px; background:rgba(241,196,15,0.1);
    border-left:4px solid var(--color-warning); border-radius:4px; font-size:13px;">';
echo '<strong><i class="fa fa-info-circle"></i> ' . lang('@What happens when you create a credit note:') . '</strong><ul style="margin:8px 0 0 0; padding-left:20px; line-height:1.8;">';
echo '<li>' . lang('@A new credit invoice is created with negative quantities') . '</li>';
echo '<li>' . lang('@The original invoice is marked as credited and can no longer be edited') . '</li>';
echo '<li>' . lang('@A reversal journal entry is posted to the ledger') . '</li>';
echo '<li>' . lang('@The credit note can be sent to the customer from the invoice view') . '</li>';
echo '</ul></div>';

// Original faktura — oversigt
echo '<div style="margin-bottom:20px; padding:14px; background:var(--bg-panel); border-radius:6px; border:1px solid var(--border-color);">';
echo '<strong>' . lang('@Original invoice') . ':</strong><br>';
echo '<table style="width:100%; margin-top:10px; border-collapse:collapse; font-size:13px;">';
echo '<thead><tr style="background:var(--bg-panel);">';
foreach ([lang('@Description'), lang('@Qty'), lang('@Price'), lang('@VAT%'), lang('@Line total')] as $h) {
    echo '<th style="padding:6px 8px; text-align:left; border-bottom:2px solid var(--border-color);">' . $h . '</th>';
}
echo '</tr></thead><tbody>';
foreach ($orig_lines as $l) {
    $lt = (float)$l['quantity'] * (float)$l['price_each'];
    echo '<tr>';
    echo '<td style="padding:6px 8px;">' . htmlspecialchars($l['line_text']) . '</td>';
    echo '<td style="padding:6px 8px;">' . number_format($l['quantity'],   2, ',', '.') . '</td>';
    echo '<td style="padding:6px 8px;">' . number_format($l['price_each'], 2, ',', '.') . '</td>';
    echo '<td style="padding:6px 8px;">' . $l['line_vat_rate'] . '%</td>';
    echo '<td style="padding:6px 8px; text-align:right; font-weight:500;">' . number_format($lt, 2, ',', '.') . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '<div style="text-align:right; margin-top:10px; font-size:13px;">';
echo lang('@Subtotal') . ': <strong>' . number_format($sub, 2, ',', '.') . '</strong> | ';
echo lang('@VAT') . ': <strong>' . number_format($vat, 2, ',', '.') . '</strong> | ';
echo lang('@Total') . ': <strong style="font-size:1.1em; color:var(--color-danger);">'
    . number_format($sub + $vat, 2, ',', '.') . ' ' . $orig['currency'] . '</strong>';
echo '</div></div>';

// Formular
echo '<form method="POST">';
echo '<div style="display:flex; gap:15px; margin-bottom:15px;">';
htm_InputGroup(icon:'fa-calendar', labl:'@Credit date', name:'credit_date',
    valu:date('Y-m-d'), type:'date', echo:true);
echo '</div>';
htm_InputGroup(icon:'fa-comment', labl:'@Credit note reason', name:'credit_note',
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
