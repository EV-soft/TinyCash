<?php # /cvr_duplicate_check.php v:1.0.0 d:2026-09-15 i:evs
# NY (bruger-anmodet, "kan bogholderen ønske sig mere"): lille JSON-endepunkt
# der advarer - blokerer IKKE - hvis et CVR-nummer allerede er registreret på
# en ANDEN kunde eller leverandør. To poster for samme firma splitter
# historikken (fakturaer/betalinger på den ene, ikke den anden) og kan sende
# kontoudtog/rykkere til den forkerte kontakt. Fælles endepunkt for både
# customer_edit.php og supplier_edit.php (samme kontroltjek, forskellig
# tabel/kolonne) - ingen grund til to næsten-identiske filer.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

header('Content-Type: application/json; charset=utf-8');

$type       = $_GET['type'] ?? '';
$cvr        = preg_replace('/\D/', '', $_GET['cvr'] ?? '');
$exclude_id = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

if (strlen($cvr) !== 8 || !in_array($type, ['customer', 'supplier'], true)) {
    echo json_encode(['duplicate' => false]);
    ob_end_flush();
    exit;
}

$results = [];

// Tjek kunder
$sql_c = "SELECT cust_id, cust_name FROM customers WHERE cust_cvr = '" . DB::escape($conn, $cvr) . "'";
if ($type === 'customer' && $exclude_id > 0) $sql_c .= " AND cust_id != $exclude_id";
$res_c = DB::query($conn, $sql_c);
if ($res_c) {
    while ($r = DB::fetch_assoc($res_c)) {
        $results[] = ['type' => 'customer', 'name' => $r['cust_name'], 'edit_link' => 'customer_edit.php?id=' . (int)$r['cust_id']];
    }
}

// Tjek leverandører (findes kun efter db-setup/migrate_suppliers.php)
$res_s = @DB::query($conn, "SELECT supplier_id, supplier_name FROM suppliers WHERE cvr = '" . DB::escape($conn, $cvr) . "'"
    . ($type === 'supplier' && $exclude_id > 0 ? " AND supplier_id != $exclude_id" : ""));
if ($res_s) {
    while ($r = DB::fetch_assoc($res_s)) {
        $results[] = ['type' => 'supplier', 'name' => $r['supplier_name'], 'edit_link' => 'supplier_edit.php?id=' . (int)$r['supplier_id']];
    }
}

if (!empty($results)) {
    echo json_encode(['duplicate' => true, 'matches' => $results]);
} else {
    echo json_encode(['duplicate' => false]);
}
ob_end_flush();
exit;
?>
