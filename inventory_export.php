<?php # /inventory_export.php v:1.3.0 d:2026-08-30 i:evs
# v1.0.0: CSV-injection-beskyttelse tilføjet (csv_safe()) - se export.php for
# forklaring. Fundet ved en rapport-/eksportgennemgang.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';      // redirect()

// Foranstiller et anførselstegn på celler der starter med =/+/-/@, så
// Excel/LibreOffice aldrig fortolker fritekst-indhold som en formel.
function csv_safe($v) {
    if (!is_string($v) || $v === '') return $v;
    return (strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
}

// 1. Vi rydder bufferen for at undgå uønsket output i filen
if (ob_get_level()) ob_end_clean();

// 2. Data-hentning (gør det før vi sender headers, hvis nu SQL fejler)
$sql = "SELECT p.*, a.acc_name 
        FROM products p 
        LEFT JOIN accounts a ON p.acc_id = a.acc_id 
        ORDER BY p.prod_sku ASC";
$res = DB::query($conn, $sql);

if (!$res) redirect('inventory_status.php', '@Error retrieving data', 'error');      // (var en hvid side med en uoversat nøgle)

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
$headers = [lang('@SKU'), lang('@ItemName'), lang('@Account'), lang('@Price'), lang('@Cost Price'), lang('@In Stock'), lang('@Stock value (cost price)')];
fputcsv($output, $headers, ';');

// 5. Skriv rækkerne
while ($row = DB::fetch_assoc($res)) {
    $price = (float)($row['prod_price'] ?? 0);
    $stock = (float)($row['prod_stock'] ?? 0);      // RETTET: (int) skar decimaler af (fx 7,5 kg -> 7) - også i lagerværdien
    // Lageret værdiansættes til kostpris, ikke salgspris (salgsprisen overvurderede lageret med hele avancen).
    // Ukendt kostpris = tom værdi, ikke et gæt.
    $cost = isset($row['prod_cost_price']) && $row['prod_cost_price'] !== null && $row['prod_cost_price'] !== '' ? (float)$row['prod_cost_price'] : null;

    $line = [
        csv_safe($row['prod_sku']),
        csv_safe($row['prod_name']),
        csv_safe($row['acc_name']),
        fmt_dk_input($price),
        $cost === null ? '' : fmt_dk_input($cost),
        rtrim(rtrim(fmt_dk_input($stock, 3), '0'), ','),
        $cost === null ? '' : fmt_dk_input($cost * $stock)
    ];
    fputcsv($output, $line, ';');
}

fclose($output);
exit;