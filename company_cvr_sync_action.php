<?php # /company_cvr_sync_action.php v:1.3.1 d:2026-09-15 i:evs
# NYT (BEK 811/2026-gennemgang, Bilag 3 "låste stamoplysninger" - navn/
# adresse/CVR-nr må kun opdateres via officielt CVR-opslag, se
# [[digital-standard-bookkeeping-registration-goal]]): eneste vej til at
# ændre company_cvr/company_name/company_address/company_zip/company_city.
# company_settings.php's almindelige gem-handler IGNORERER nu bevidst disse
# fem nøgler i $_POST['set'] (se dens egen skip-liste) - selv et forfalsket
# POST-kald mod den store formular kan derfor IKKE længere sætte dem til
# vilkårlige værdier. Denne handler slår i stedet CVR-nummeret op SERVER-SIDE
# via den delte cvr_lookup_by_number() (inc/cvr_lookup.inc.php, samme
# cache/kvote som cvr_proxy.php) og skriver KUN det, det officielle svar
# faktisk indeholder - aldrig klient-indsendte navn/adresse-værdier.
# Telefon/email er bevidst IKKE med her (de er ikke en del af bekendtgørelsens
# liste over låste stamoplysninger) og forbliver frit redigerbare i den
# almindelige formular.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/cvr_lookup.inc.php';
require_once 'inc/audit.inc.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: company_settings.php?msg=cvr_sync_error&detail=' . urlencode(lang('@Security check failed (CSRF). Please reload the page and try again.')));
    exit;
}

$cvr = preg_replace('/\D/', '', $_POST['cvr'] ?? '');
$lookup = cvr_lookup_by_number($conn, $cvr);

if (empty($lookup['success'])) {
    header('Location: company_settings.php?msg=cvr_sync_error&detail=' . urlencode($lookup['error'] ?? lang('@CVR lookup failed. Please fill in the details manually.')));
    exit;
}

$before = get_settings($conn);
$locked_fields = [
    'company_cvr'     => $lookup['cvr'],
    'company_name'    => $lookup['name'],
    'company_address' => $lookup['street'],
    'company_zip'     => $lookup['zipcode'],
    'company_city'    => $lookup['city'],
];

$old = [];
$new = [];
foreach ($locked_fields as $key => $val) {
    $old[$key] = $before[$key] ?? null;
    $new[$key] = $val;

    $clean_key = DB::escape($conn, $key);
    $clean_val = DB::escape($conn, $val);
    if ($db_type === 'sqlite') {
        $sql = "INSERT INTO settings (setting_key, setting_value)
                VALUES ('$clean_key', '$clean_val')
                ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value";
    } else {
        $sql = "INSERT INTO settings (setting_key, setting_value)
                VALUES ('$clean_key', '$clean_val')
                ON DUPLICATE KEY UPDATE setting_value = '$clean_val'";
    }
    DB::query($conn, $sql);
}

log_action($conn, 'UPDATE_COMPANY_CVR_SYNC', 'settings', 0, $old, $new);

header('Location: company_settings.php?msg=cvr_synced');
exit;
