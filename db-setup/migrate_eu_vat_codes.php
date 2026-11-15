<?php # /db-setup/migrate_eu_vat_codes.php v:1.0.0 d:2026-11-09 i:evs
// Momskoder til EU-handel og eksport fra landepakken (Danmark: EUV, EUY, EXP, IEUV, IEUY, IUV, IUY) - grundlaget for
// momsangivelsens rubrik A/B/C og "moms af køb i udlandet" (omvendt betalingspligt). Kobler standardkontoplanens
// udlandskonti (1050-1200, 1630-1740) til koderne, når nummer og navn er standardkontoplanens. Eksisterende momskoder
// og konti med en EU-/eksportkode røres ikke. Se tc_apply_pack_vat_setup() i inc/country.inc.php.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Motor: $db_type\n\n";

if (!db_has_column($conn, 'vat_codes', 'vat_type')) {
    echo "[FEJL] vat_codes.vat_type mangler - kør db-setup/migrate_vat_code_types.php først.\n";
    exit;
}
$log = tc_apply_pack_vat_setup($conn);
foreach ($log as $l) echo "[OK] $l\n";
if (!$log) echo "[SPRUNGET OVER] Alle momskoder findes allerede.\n";
echo "\nFærdig. Se momskoderne på vat_codes.php - en konto med omvendt betalingspligt skal pege på kontoen for\n"
   . "\"moms af varekøb/ydelseskøb i udlandet\" (standardkontoplanen: 7700/7720).\n";
