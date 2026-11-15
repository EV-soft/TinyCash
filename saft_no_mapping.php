<?php # /saft_no_mapping.php v:1.3.1 d:2026-09-15 i:evs
// Norsk SAF-T: hver konto skal have en kode fra Skatteetatens næringsspesifikasjon (GroupingCategory/GroupingCode).
// Siden viser forslaget for hver konto (inc/saft_no.inc.php: kontonummeret selv, nærmeste kode eller kontotypens
// standard) og gemmer de valgte koder i indstillingen saft_no_grouping. Kun valg der afviger fra forslaget gemmes,
// så et senere bedre forslag (fx efter en omnummerering) slår igennem for resten.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/saft_no.inc.php';
require_country_feature($conn, 'saft_no');

$codes = saft_no_codes();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grouping'])) {
    $posted = (array)($_POST['grp'] ?? []);
    $old = saft_no_saved($conn);
    $new = [];
    $res = DB::query($conn, "SELECT acc_id, acc_type FROM accounts");
    while ($res && ($a = DB::fetch_assoc($res))) {
        $id = (int)$a['acc_id'];
        $c = array_key_exists((string)$id, $posted) ? (string)$posted[$id] : (string)($old[$id] ?? '');
        if ($c === '' || !isset($codes[$c])) continue;
        if ($c !== saft_no_suggest($id, (string)$a['acc_type'])['code'] || isset($old[$id])) $new[$id] = $c;
    }
    ksort($new);
    $json = json_encode($new, JSON_FORCE_OBJECT);
    DB::prepare_and_execute($conn, "DELETE FROM settings WHERE setting_key = ?", ['saft_no_grouping']);
    DB::insert($conn, 'settings', ['setting_key' => 'saft_no_grouping', 'setting_value' => $json]);
    tc_settings_forget();
    if ($new != $old) log_action($conn, 'UPDATE_SAFT_GROUPING', 'settings', 0, $old, $new);
    redirect('saft_no_mapping.php', '@SAF-T mapping saved');
}

$map = saft_no_mapping($conn);
// Valglisten pr. kontotype: kun koder af kategorier typen kan høre til (+ den valgte, hvis den ligger udenfor)
$opts_by_type = [];
foreach (SAFT_NO_TYPE_CATS as $type => [$cats]) {
    foreach ($codes as $c => [$cat, $desc]) if (in_array($cat, $cats, true)) $opts_by_type[$type][(string)$c] = $c . ' - ' . $desc;
}
$how_txt = ['saved' => '', 'exact' => '', 'near' => lang('@Suggested - nearest code'), 'type' => lang('@Suggested - from account type')];

htm_Header(capt: '@SAF-T mapping', mwidth: 1000);
showMenu();
htm_Card_(capt: '@SAF-T mapping', wdth: 1000, form: 'saftMapForm');
htm_Note(lang('@Norway: every account in the SAF-T file must have a code from the business specification (næringsspesifikasjon). The suggestion is the account number itself when it is such a code (charts following NS 4102), otherwise the nearest code or a code from the account type. Check the marked rows.'), 'margin-top:0; font-size:0.9em;');
$data = [];
foreach ($map as $id => $m) {
    $opts = $opts_by_type[$m['acc_type']] ?? $opts_by_type['expense'];
    if (!isset($opts[$m['code']])) $opts = [$m['code'] => $m['code'] . ' - ' . ($codes[$m['code']][1] ?? '')] + $opts;
    $unsure = in_array($m['how'], ['near', 'type'], true);
    $sel = htm_Select("grp[$id]", $opts, $m['code'], 'width:100%; padding:4px;' . ($unsure ? ' border:1px dashed var(--color-warning) !important;' : ''), '', false);
    if ($unsure) $sel .= ' <span style="font-size:0.75em; color:var(--color-warning); font-weight:bold; white-space:nowrap;">' . $how_txt[$m['how']] . '</span>';
    $data[] = [$id, htmlspecialchars($m['acc_name']), htmlspecialchars($m['category']), $sel];
}
htm_Table(['@No.', '@Account Name', '@Category', '@Grouping code'], $data, 'saftMapTbl', 1000);
echo '<div style="display:flex; justify-content:space-between; margin-top:15px;">';
htm_Button(icon: 'fa-save', labl: '@Save', type: 'primary', attr: 'type="submit" name="save_grouping" value="1"');
htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'export_saft.php');
echo '</div>';
htm_Card_end();
htm_Footer();
