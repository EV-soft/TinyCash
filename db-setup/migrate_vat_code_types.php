<?php # /db-setup/migrate_vat_code_types.php v:1.0.0 d:2026-11-09 i:evs
// vat_codes.vat_type - hvilken slags moms koden er (salg, køb, EU-varer/-ydelser, eksport, fritaget, omvendt
// betalingspligt; se TC_VAT_TYPES i inc/country.inc.php). Grundlaget for momsangivelsens felter pr. land.
// Eksisterende koder udfyldes: landepakkens egne koder får pakkens type, ellers købsmoms hvis koden peger på
// købsmomskontoen eller hedder K..., fritaget ved 0 %, og ellers salgsmoms. Kan rettes bagefter på vat_codes.php.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Motor: $db_type\n\n";

if (db_has_column($conn, 'vat_codes', 'vat_type')) {
    echo "[SPRUNGET OVER] Kolonnen 'vat_type' findes allerede på vat_codes.\n";
} elseif (DB::query($conn, "ALTER TABLE vat_codes ADD COLUMN vat_type " . (DB::is_sqlite() ? 'TEXT' : 'VARCHAR(30)'))) {
    echo "[OK] Kolonnen 'vat_type' tilføjet til vat_codes.\n";
} else {
    echo "[FEJL] " . DB::error($conn) . "\n";
    exit;
}

// Udfyld kun koder uden type (genkørsel ændrer intet, og en type rettet i hånden bevares)
$pack_types = [];
foreach ((array)tc_country_pack('vat.codes', []) as $vc) {
    if (is_array($vc) && isset($vc['id'], $vc['type'])) $pack_types[strtoupper((string)$vc['id'])] = (string)$vc['type'];
}
$acc_in = (int)setting('conf_acc_purchase_vat', 6910);
$n = 0;
$res = DB::query($conn, "SELECT vat_id, vat_rate, vat_account FROM vat_codes WHERE vat_type IS NULL OR vat_type = ''");
$rows = [];
while ($res && ($r = DB::fetch_assoc($res))) $rows[] = $r;
foreach ($rows as $r) {
    $id = (string)$r['vat_id'];
    $type = $pack_types[strtoupper($id)]
        ?? (((int)$r['vat_account'] === $acc_in || stripos($id, 'K') === 0) ? 'purchase'
        : ((float)$r['vat_rate'] == 0 ? 'exempt' : 'sales'));
    DB::prepare_and_execute($conn, "UPDATE vat_codes SET vat_type = ? WHERE vat_id = ?", [$type, $id]);
    echo "  $id -> $type\n";
    $n++;
}
echo "\n[OK] $n momskode(r) fik en type.\nFærdig. Ret evt. typen på vat_codes.php.\n";
