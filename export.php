<?php # /export.php v:1.3.0 d:2026-08-30 i:evs
# v1.0.0: CSV-injection-beskyttelse tilføjet (csv_safe()) - fritekstfelter
# (kundenavn/-noter/-adresse, fakturalinje-tekst) blev før skrevet råt ind i
# CSV'en; en celle der starter med =/+/-/@ kan af Excel/LibreOffice blive
# fortolket som en formel, hvis nogen har fået et sådant indhold ind i et
# fritekstfelt. Fundet ved en rapport-/eksportgennemgang.
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/invoice_layouts.inc.php';     // il_column_exists()
require_once 'inc/php2htm.lib.php';           // redirect()

// Deaktiver fejlvisning for at sikre en ren CSV-fil
ini_set('display_errors', 0);

// Foranstiller et anførselstegn på celler der starter med =/+/-/@, så
// Excel/LibreOffice aldrig fortolker fritekst-indhold som en formel
// (CSV/formel-injektion). Rører kun strenge - tal/int-felter er upåvirkede.
function csv_safe($v) {
    if (!is_string($v) || $v === '') return $v;
    return (strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
}

$type = $_GET['type'] ?? '';
if (!in_array($type, ['customers', 'invoices'], true)) redirect('index.php', '@Unknown export type.', 'error');      // (gav en tom CSV-fil)
$filename = "TinyCash_Export_" . $type . "_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM til Excel

$sep = ";"; // Semikolon fungerer bedst i dansk Excel

if ($type == 'customers') {
    // Overskrifter baseret på din Blueprint
    // Kolonnenavnene var faste danske tekster - nu på brugerens sprog som resten af appens eksporter
    fputcsv($output, array_map('lang', ['@ID', '@Name', '@Contact Person', '@Address', '@Email', '@Phone', '@CVR', '@Notes', '@Payment Days']), $sep);
    
    $sql = "SELECT cust_id, cust_name, cust_contact_person, cust_address, cust_email, cust_phone, cust_cvr, cust_notes, cust_payment_days 
            FROM customers WHERE " . customers_active_filter($conn) . "
            ORDER BY cust_name";      // RETTET: kunder i papirkurven (slettede) kom med i eksporten
    
    $res = DB::query($conn, $sql);
    while ($row = DB::fetch_assoc($res)) {
        // Rens tekstfelter for linjeskift så CSV-strukturen ikke knækker
        $row['cust_address'] = str_replace(["\r", "\n"], " ", $row['cust_address'] ?? '');
        $row['cust_notes'] = str_replace(["\r", "\n"], " ", $row['cust_notes'] ?? '');
        // CSV-injection-beskyttelse på alle fritekstfelter
        foreach (['cust_name', 'cust_contact_person', 'cust_address', 'cust_email', 'cust_phone', 'cust_cvr', 'cust_notes'] as $f) {
            if (isset($row[$f])) $row[$f] = csv_safe($row[$f]);
        }
        fputcsv($output, $row, $sep);
    }
}
elseif ($type == 'invoices') {
    // Kombineret oversigt over fakturaer og deres linjer
    fputcsv($output, array_map('lang', ['@Invoice ID', '@No.', '@Date', '@Due Date', '@Customer', '@Status',
        '@Line text', '@Qty', '@Price', '@VAT %', '@Line total', '@Currency']), $sep);
    
    // RETTET: linjetotalen så bort fra linjens rabat (discount_pct) - eksporten viste for høje beløb
    $exp_disc = invoice_discount_sql($conn, 'l.');
    // RETTET: kreditnotaer (intet invoice_no) stod uden nummer - nu KN-nummeret
    $exp_cn = il_column_exists($conn, 'invoices', 'credit_note_no')
        ? "CASE WHEN i.credit_ref IS NOT NULL AND i.credit_note_no IS NOT NULL THEN 'KN-' || i.credit_note_no ELSE i.invoice_no END"
        : "i.invoice_no";
    if (!DB::is_sqlite()) $exp_cn = str_replace("'KN-' || i.credit_note_no", "CONCAT('KN-', i.credit_note_no)", $exp_cn);
    $sql = "SELECT i.inv_id, $exp_cn AS invoice_no, i.inv_date, i.inv_due_date, c.cust_name, i.inv_status, 
                   l.line_text, l.quantity, l.price_each, l.line_vat_rate,
                   (l.quantity * l.price_each $exp_disc) as line_total, i.currency
            FROM invoices i 
            LEFT JOIN customers c ON i.cust_id = c.cust_id 
            LEFT JOIN invoice_lines l ON i.inv_id = l.inv_id 
            ORDER BY i.inv_id DESC, l.line_id ASC";
            
    $res = DB::query($conn, $sql);
    while ($row = DB::fetch_assoc($res)) {
        // Formater tal til dansk format (valgfrit, men godt for Excel)
        $row['line_total'] = fmt_dk_input($row['line_total']);
        $row['price_each'] = fmt_dk_input($row['price_each']);
        if (strncmp((string)$row['invoice_no'], 'KN-', 3) === 0) $row['invoice_no'] = 'KN-' . str_pad(substr($row['invoice_no'], 3), 6, '0', STR_PAD_LEFT);      // (samme visning som i appen)
        // RETTET: antal og momssats blev skrevet med punktum ("2.5") - dansk Excel læser det som dato/tekst
        $row['quantity']      = $row['quantity'] === null ? '' : rtrim(rtrim(fmt_dk_input((float)$row['quantity'], 3), '0'), ',');
        $row['line_vat_rate'] = $row['line_vat_rate'] === null ? '' : rtrim(rtrim(fmt_dk_input((float)$row['line_vat_rate']), '0'), ',');
        // CSV-injection-beskyttelse på fritekstfelter
        foreach (['cust_name', 'line_text'] as $f) {
            if (isset($row[$f])) $row[$f] = csv_safe($row[$f]);
        }
        fputcsv($output, $row, $sep);
    }
}

fclose($output);
exit;