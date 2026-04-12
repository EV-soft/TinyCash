<?php # /inventory_export.php v:0.8.1 d:2026-04-10 i:Gemini m:1
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

// 1. Visuel side-struktur (Header)
htm_Header(lang('@Inventory Export'));
showMenu();

// 2. CSV Generering & Headers
$filename = "inventory_export_" . date('Y-m-d') . ".csv";

// Vi sender headers med det samme
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Åbn output stream
$output = fopen('php://output', 'w');

// Fix til Excel (BOM)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Kolonneoverskrifter
$headers = [
    lang('@SKU'), 
    lang('@Product Name'), 
    lang('@Account'), 
    lang('@Price'), 
    lang('@In Stock'), 
    lang('@Total Value')
];
fputcsv($output, $headers, ';');

// 3. Data-hentning
$sql = "SELECT p.*, a.acc_name 
        FROM products p 
        LEFT JOIN accounts a ON p.acc_id = a.acc_id 
        ORDER BY p.prod_sku ASC";

$res = mysqli_query($conn, $sql);

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $price = (float)($row['prod_price'] ?? 0);
        $stock = (int)($row['prod_stock'] ?? 0);
        $total_val = $price * $stock;

        $line = [
            $row['prod_sku'],
            $row['prod_name'],
            $row['acc_name'],
            number_format($price, 2, ',', ''), 
            $stock,
            number_format($total_val, 2, ',', '')
        ];
        fputcsv($output, $line, ';');
    }
}

fclose($output);

// 4. Slutmelding (Dette vil ikke altid ses pga. download-header, men her er den korrekte tekst)
// Bemærk: Når man bruger 'attachment' headers, bliver brugeren ofte på den forrige side.
// Hvis du vil have en dedikeret succes-side, skal eksporten køre i en iframe eller et nyt vindue.

$msg = lang('@Export completed!') . ' ' . lang('@You will find the file in your download folder.');
htm_Alert($msg, 'success');

htm_Footer();
ob_end_flush();
exit;