<?php # /supplier_payment_export.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 31):
# ingen måde at samle flere ubetalte leverandørudgifter til én betalingsomgang
# - bogholderen skulle betale hver for sig. Lader brugeren vælge blandt
# ubetalte, bogførte udgifter (samme $is_payable-kriterie som expense_list.
# php's "Betal nu"-knap) og downloade en CSV med reg.nr/kontonr/beløb/tekst
# til brug ved en samlet overførsel i netbank.
#
# BEVIDST IKKE et "certificeret" bankfil-format (fx et rigtigt Betalings-
# service-/SEPA-XML-format, som kræver en formel bankaftale og præcis
# overholdelse af en bank-specifik specifikation, der ikke kan garanteres
# korrekt herfra) - blot en almindelig CSV til manuel/semi-automatisk import,
# samme "aldrig lad som om noget er mere officielt end det er"-princip som
# resten af sessionen. Markerer ALDRIG selv udgifterne som betalt - det gør
# bogholderen stadig selv (expense_list.php's "Betal nu"), først når
# overførslen faktisk er gennemført.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

function spe_csv_safe($v) {
    if (!is_string($v) || $v === '') return $v;
    return (strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
}

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$supplier_module_ready = false;
if (DB::is_sqlite()) {
    $cc = DB::query($conn, "PRAGMA table_info(expenses)");
    if ($cc) { while ($ccr = DB::fetch_assoc($cc)) { if ($ccr['name'] === 'paid_date') { $supplier_module_ready = true; break; } } }
} else {
    $cc = DB::query($conn, "SHOW COLUMNS FROM expenses LIKE 'paid_date'");
    $supplier_module_ready = ($cc && DB::num_rows($cc) > 0);
}
$has_bank_cols = false;
if (DB::is_sqlite()) {
    $bc = DB::query($conn, "PRAGMA table_info(suppliers)");
    if ($bc) { while ($bcr = DB::fetch_assoc($bc)) { if ($bcr['name'] === 'supplier_reg_no') { $has_bank_cols = true; break; } } }
} else {
    $bc = DB::query($conn, "SHOW COLUMNS FROM suppliers LIKE 'supplier_reg_no'");
    $has_bank_cols = ($bc && DB::num_rows($bc) > 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exp_ids']) && is_array($_POST['exp_ids'])) {
    $ids = array_map('intval', $_POST['exp_ids']);
    $ids = array_filter($ids, fn($i) => $i > 0);
    if (!empty($ids)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leverandoer_betalinger_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        // RETTET: beløbet var altid udgiftens KRONEbeløb uden valuta - en regning på 1.000 EUR stod som "7460,00" og
        // ville blive betalt med 7.460 i leverandørens valuta. Nu fakturaens eget beløb og valuta (kolonnen Valuta).
        $base_cur = strtoupper((string)($global_settings['currency'] ?? 'DKK'));
        fputcsv($output, [lang('@Supplier'), lang('@Reg. No.'), lang('@Account No.'), lang('@Amount'), lang('@Currency'), lang('@Reference')], ';');
        $id_list = implode(',', $ids);
        $res = DB::query($conn, "SELECT e.*, s.supplier_name, s.supplier_reg_no, s.supplier_account_no
            FROM expenses e JOIN suppliers s ON e.supplier_id = s.supplier_id
            WHERE e.exp_id IN ($id_list) AND e.is_cancelled = 0 AND e.paid_date IS NULL AND e.voucher_no IS NOT NULL");
        if ($res) {
            while ($r = DB::fetch_assoc($res)) {
                fputcsv($output, [
                    spe_csv_safe($r['supplier_name']),
                    spe_csv_safe($r['supplier_reg_no'] ?? ''),
                    spe_csv_safe($r['supplier_account_no'] ?? ''),
                    number_format(!empty($r['orig_currency']) && (float)($r['orig_amount'] ?? 0) != 0 ? (float)$r['orig_amount'] : (float)$r['amount'], 2, ',', ''),
                    !empty($r['orig_currency']) && (float)($r['orig_amount'] ?? 0) != 0 ? strtoupper($r['orig_currency']) : $base_cur,
                    lang('@Voucher') . ' ' . $r['voucher_no'],
                ], ';');
            }
        }
        fclose($output);
        exit;
    }
}

htm_Header('@Supplier Payment Export');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";
htm_Card_('@Supplier Payment Export', 1000);
echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">'
    . lang('@Select unpaid, posted expenses below and download a CSV (supplier, reg. no., account no., amount, reference) for a batch payment in your netbank. This is NOT a certified bank file format - just a helpful export for manual/semi-automatic import. Does not mark anything as paid - do that from the Expense List once the transfer is actually made.')
    . '</p>';

if (!$supplier_module_ready) {
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@The supplier module database structure has not been set up yet. Run the migration under System -> Maintenance -> Database migration to enable the creditor side of this report.'), 'warning');
} elseif (!$has_bank_cols) {
    htm_Banner('<i class="fa fa-triangle-exclamation"></i> ' . lang('@Supplier bank details are not set up yet. Run the migration under System -> Maintenance -> Database migration, then add reg./account numbers on each supplier.'), 'warning');
} else {
    $res = DB::query($conn, "SELECT e.exp_id, e.amount, e.voucher_no, e.due_date, s.supplier_name, s.supplier_reg_no, s.supplier_account_no
        FROM expenses e JOIN suppliers s ON e.supplier_id = s.supplier_id
        WHERE e.is_cancelled = 0 AND e.voucher_no IS NOT NULL AND e.due_date IS NOT NULL AND e.paid_date IS NULL
        ORDER BY e.due_date ASC");
    $rows = [];
    while ($r = DB::fetch_assoc($res)) {
        $has_bank_details = !empty($r['supplier_reg_no']) && !empty($r['supplier_account_no']);
        $rows[] = [
            '<input type="checkbox" name="exp_ids[]" value="' . (int)$r['exp_id'] . '" ' . ($has_bank_details ? '' : 'disabled') . ' onchange="updateSpeCount()">',
            htmlspecialchars($r['supplier_name']) . ($has_bank_details ? '' : ' <span style="color:var(--color-danger); font-size:0.8em;" data-hint="' . htmlspecialchars(lang('@No bank details registered for this supplier')) . '">(' . lang('@No bank details') . ')</span>'),
            date(CONF_DATE_FORMAT, strtotime($r['due_date'])),
            number_format((float)$r['amount'], 2, ',', '.') . ' ' . $cur,
        ];
    }
    if (empty($rows)) {
        echo '<p style="color:var(--text-muted); font-style:italic;">' . lang('@No unpaid, posted expenses found.') . '</p>';
    } else {
        echo '<form method="post">';
        csrf_field();
        htm_Table(['&nbsp;', '@Supplier', '@Due Date', '@Amount'], $rows, 'speTbl', 100);
        echo '<button type="submit" id="speBtn" disabled style="margin-top:10px; padding:8px 16px; background:var(--color-success); color:#fff; border:none; border-radius:4px; cursor:pointer; opacity:0.5;"><i class="fa fa-file-csv"></i> ' . lang('@Download Payment CSV') . ' (<span id="speCount">0</span>)</button>';
        echo '</form>';
        ?>
        <script>
        function updateSpeCount() {
            var n = document.querySelectorAll('#speTbl input[name="exp_ids[]"]:checked').length;
            document.getElementById('speCount').innerText = n;
            var btn = document.getElementById('speBtn');
            btn.disabled = (n === 0);
            btn.style.opacity = (n === 0) ? '0.5' : '1';
        }
        </script>
        <?php
    }
}
htm_Card_end();
echo "</div>";
htm_Footer();
