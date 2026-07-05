<?php # /inventory_export.php v:0.9.1 d:2026-05-08 i:evs
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

// 1. Vi rydder bufferen for at undgå uønsket output i filen
if (ob_get_level()) ob_end_clean();

// 2. Data-hentning (gør det før vi sender headers, hvis nu SQL fejler)
$sql = "SELECT p.*, a.acc_name 
        FROM products p 
        LEFT JOIN accounts a ON p.acc_id = a.acc_id 
        ORDER BY p.prod_sku ASC";
$res = DB::query($conn, $sql);

if (!$res) {
    die("@Error retrieving data");
}

// 3. CSV Headers - Fortæller browseren at dette er en download
$filename = "inventory_export_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// 4. Åbn output stream
$output = fopen('php://output', 'w');

// Fix til Excel (BOM gør at Excel forstår UTF-8 og danske tegn)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Kolonneoverskrifter (Vi bruger ltrim($key, '@') hvis lang() ikke er indlæst, 
// eller indlæs biblioteket uden at bruge htm_Header)
// $headers = ['SKU', 'Varenavn', 'Konto', 'Pris', 'På lager', 'Samlet værdi'];
$headers = [lang('@SKU'), lang('@ItemName'), lang('@Account'), lang('@Price'), lang('@In Stock'), lang('@Total Value')];
fputcsv($output, $headers, ';');

// 5. Skriv rækkerne
while ($row = DB::fetch_assoc($res)) {
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

fclose($output);
exit;