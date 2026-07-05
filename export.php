<?php # export.php v:0.9.0 d:2026-05-08 i:evs
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';

// Deaktiver fejlvisning for at sikre en ren CSV-fil
ini_set('display_errors', 0);

$type = $_GET['type'] ?? '';
$filename = "TinyCash_Export_" . $type . "_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM til Excel

$sep = ";"; // Semikolon fungerer bedst i dansk Excel

if ($type == 'customers') {
    // Overskrifter baseret på din Blueprint
    fputcsv($output, ['ID', 'Navn', 'Kontaktperson', 'Adresse', 'Email', 'Telefon', 'CVR', 'Noter', 'Betalingsdage'], $sep);
    
    $sql = "SELECT cust_id, cust_name, cust_contact_person, cust_address, cust_email, cust_phone, cust_cvr, cust_notes, cust_payment_days 
            FROM customers 
            ORDER BY cust_name";
    
    $res = DB::query($conn, $sql);
    while ($row = DB::fetch_assoc($res)) {
        // Rens tekstfelter for linjeskift så CSV-strukturen ikke knækker
        $row['cust_address'] = str_replace(["\r", "\n"], " ", $row['cust_address'] ?? '');
        $row['cust_notes'] = str_replace(["\r", "\n"], " ", $row['cust_notes'] ?? '');
        fputcsv($output, $row, $sep);
    }
} 
elseif ($type == 'invoices') {
    // Kombineret oversigt over fakturaer og deres linjer
    fputcsv($output, [
        'Faktura ID', 'Nr', 'Dato', 'Forfald', 'Kunde', 'Status', 
        'Linje tekst', 'Antal', 'Pris pr. stk', 'Moms %', 'Linje Total', 'Valuta'
    ], $sep);
    
    $sql = "SELECT i.inv_id, i.invoice_no, i.inv_date, i.inv_due_date, c.cust_name, i.inv_status, 
                   l.line_text, l.quantity, l.price_each, l.line_vat_rate,
                   (l.quantity * l.price_each) as line_total, i.currency
            FROM invoices i 
            LEFT JOIN customers c ON i.cust_id = c.cust_id 
            LEFT JOIN invoice_lines l ON i.inv_id = l.inv_id 
            ORDER BY i.inv_id DESC, l.line_id ASC";
            
    $res = DB::query($conn, $sql);
    while ($row = DB::fetch_assoc($res)) {
        // Formater tal til dansk format (valgfrit, men godt for Excel)
        $row['line_total'] = number_format($row['line_total'], 2, ',', '');
        $row['price_each'] = number_format($row['price_each'], 2, ',', '');
        
        fputcsv($output, $row, $sep);
    }
}

fclose($output);
exit;