<?php # /time_entry_edit.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Timeregistrering (bruger-anmodet) - opret/redigér en enkelt
# timeregistrering. Uforanderlighed: en time der allerede er sat på en
# faktura (is_invoiced=1) kan hverken redigeres eller slettes her - præcis
# samme princip som en bogført faktura/udgift/anlægsaktiv. Rettelse af en
# fejlregistreret, allerede faktureret time sker ved at kreditere/rette selve
# fakturaen (invoice_credit.php), ikke ved at ændre timen bagom om.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$s = get_settings($conn);
$module_projects = !empty($s['module_projects']) && $s['module_projects'] == '1';
if (!$module_projects) { header("Location: time_list.php"); exit; }

$table_exists = @DB::query($conn, "SELECT 1 FROM time_entries LIMIT 1") !== false;
if (!$table_exists) { header("Location: time_list.php"); exit; }

$id = (int)($_GET['id'] ?? 0);

// --- SLET (kun ikke-fakturerede) ---
if (isset($_GET['del']) && $_GET['del'] == 1) {
    $del_row = DB::fetch_assoc(DB::query($conn, "SELECT * FROM time_entries WHERE entry_id = $id"));
    // RETTET (selv-fundet ved live-test): redirectede FØR altid til
    // "msg=deleted", uanset om rækken reelt blev slettet - en allerede
    // faktureret time blev korrekt IKKE slettet af tjekket nedenfor, men
    // brugeren fik alligevel at vide "Slettet" som om intet var galt.
    if ($del_row && (int)$del_row['is_invoiced'] === 0) {
        if (DB::query($conn, "DELETE FROM time_entries WHERE entry_id = $id")) {
            log_action($conn, 'DELETE_TIME_ENTRY', 'time_entries', $id, $del_row, null);
        }
        header("Location: time_list.php?msg=deleted"); exit;
    }
    header("Location: time_list.php?msg=cannot_delete"); exit;
}

$err = '';

// --- GEM ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_entry'])) {
    if ($id > 0) {
        $existing = DB::fetch_assoc(DB::query($conn, "SELECT is_invoiced FROM time_entries WHERE entry_id = $id"));
        if ($existing && (int)$existing['is_invoiced'] === 1) {
            die(lang('@This time entry has already been invoiced and can no longer be edited.'));
        }
    }

    $proj_id      = (int)($_POST['proj_id'] ?? 0);
    $entry_date   = DB::escape($conn, $_POST['entry_date'] ?? date('Y-m-d'));
    $description  = DB::escape($conn, trim($_POST['description'] ?? ''));
    $hours        = parse_dk_number($_POST['hours'] ?? 0);
    $hourly_rate  = parse_dk_number($_POST['hourly_rate'] ?? 0);
    $vat_rate     = parse_dk_number($_POST['line_vat_rate'] ?? 25);
    // Ikke-fakturerbart tvinges igennem uden projekt - der er ingen kunde at
    // fakturere til uden et projekt, så "fakturerbar" ville være meningsløst.
    $is_billable  = ($proj_id > 0 && isset($_POST['is_billable'])) ? 1 : 0;

    if ($description === '') {
        $err = lang('@Please enter a description.');
    } elseif ($hours <= 0) {
        $err = lang('@Please enter a valid number of hours.');
    } else {
        $proj_sql = $proj_id > 0 ? $proj_id : 'NULL';
        if ($id > 0) {
            DB::query($conn, "UPDATE time_entries SET
                proj_id = $proj_sql, entry_date = '$entry_date', description = '$description',
                hours = $hours, hourly_rate = $hourly_rate, line_vat_rate = $vat_rate, is_billable = $is_billable
                WHERE entry_id = $id");
        } else {
            DB::query($conn, "INSERT INTO time_entries
                (proj_id, user_id, entry_date, description, hours, hourly_rate, line_vat_rate, is_billable)
                VALUES ($proj_sql, " . (int)($_SESSION['user_id'] ?? 0) . ", '$entry_date', '$description', $hours, $hourly_rate, $vat_rate, $is_billable)");
            $id = DB::insert_id($conn);
        }
        header("Location: time_list.php" . ($proj_id ? "?proj_id=$proj_id" : ""));
        exit;
    }
}

// --- HENT ---
if ($id > 0) {
    $t = DB::fetch_assoc(DB::query($conn, "SELECT * FROM time_entries WHERE entry_id = $id"));
    if (!$t) { header("Location: time_list.php"); exit; }
} else {
    $t = [
        'entry_id' => 0, 'proj_id' => (int)($_GET['proj_id'] ?? 0), 'entry_date' => date('Y-m-d'),
        'description' => '', 'hours' => '', 'hourly_rate' => '', 'line_vat_rate' => 25,
        'is_billable' => 1, 'is_invoiced' => 0, 'inv_id' => null,
    ];
}
$is_locked = !empty($t['is_invoiced']);

// Projekternes standard-timesats, til JS-forvalg ved projektvalg.
$proj_opts = ['0' => lang('@No project (internal, non-billable)')];
$proj_rate_map = ['0' => 0];
$pres = DB::query($conn, "SELECT proj_id, proj_no, default_hourly_rate FROM projects WHERE is_active = 1 ORDER BY proj_no ASC");
while ($p = DB::fetch_assoc($pres)) {
    $proj_opts[$p['proj_id']] = $p['proj_no'];
    $proj_rate_map[$p['proj_id']] = (float)($p['default_hourly_rate'] ?? 0);
}

htm_Header($id > 0 ? lang('@Edit Time Entry') : lang('@Log Time'));
showMenu();

if ($err) htm_Alert($err, 'error');

echo "<div style='max-width:600px; margin:20px auto;'>";
htm_Card_(capt: $id > 0 ? '@Edit Time Entry' : '@Log Time', wdth: 600, form: 'time_form');

if ($is_locked) {
    htm_Banner('<i class="fa fa-lock"></i> ' . sprintf(lang('@This time entry has already been billed on invoice #%s and cannot be changed here.'), (int)$t['inv_id']), 'info');
}

echo '<input type="hidden" name="entry_id" value="'.(int)$t['entry_id'].'">';

htm_Field(icon: 'fa-folder-open', labl: '@Project', name: 'proj_id', valu: $t['proj_id'] ?? 0, type: 'sele',
    opti: $proj_opts, extr: ($is_locked ? 'disabled' : 'onchange="applyDefaultRate()"'), wdth: '100%',
    hint: '@Required to make this entry billable - a customer is only known through its project.');

htm_Field(icon: 'fa-calendar', labl: '@Date', name: 'entry_date', valu: $t['entry_date'], type: 'date', extr: ($is_locked ? 'readonly' : ''), wdth: '50%');
htm_Field(icon: 'fa-hourglass-half', labl: '@Hours', name: 'hours', valu: $t['hours'], type: 'text', extr: ($is_locked ? 'readonly' : '') . ' id="hours_field" style="text-align:right;"', wdth: '50%', hint: '@E.g. 1,5 for one and a half hours.');

htm_Field(icon: 'fa-align-left', labl: '@Description', name: 'description', valu: $t['description'], type: 'textarea', extr: ($is_locked ? 'readonly' : ''), wdth: '100%');

echo '<div style="display:flex; width:100%; gap:10px;">';
htm_Field(icon: 'fa-coins', labl: '@Hourly Rate', name: 'hourly_rate', valu: $t['hourly_rate'], type: 'text', extr: ($is_locked ? 'readonly' : '') . ' id="rate_field" style="text-align:right;"', wdth: '50%');
htm_Field(icon: 'fa-percent', labl: '@VAT %', name: 'line_vat_rate', valu: $t['line_vat_rate'], type: 'number', extr: ($is_locked ? 'readonly' : ''), wdth: '50%');
echo '</div>';

echo '<div style="margin:10px 5px; padding:0 5px;">';
echo '<label style="font-size:0.9em; cursor:'.($is_locked ? 'default' : 'pointer').'; display:flex; align-items:center; gap:8px;">';
echo '<input type="checkbox" name="is_billable" value="1" '.($t['is_billable'] ? 'checked' : '').' '.($is_locked ? 'disabled' : '').' style="width:14px; height:14px;">';
echo lang('@Billable (will appear as unbilled hours on its project, ready to invoice)');
echo '</label></div>';

if (!$is_locked) {
    echo "<div style='display:flex; gap:10px; margin-top:20px; border-top:1px solid #eee; padding-top:20px;'>";
    htm_Button(icon: 'fa-save', labl: '@Save', type: 'success', attr: 'name="save_entry" data-hint="'.lang('@Save this time entry').'"', styl: 'flex:2;');
    if ($t['entry_id'] > 0) {
        htm_ConfirmLink(icon: 'fa-trash', labl: '@Delete', link: 'time_entry_edit.php?id='.$t['entry_id'].'&del=1',
            mess: '@Are you sure you want to delete this time entry?', type: 'danger', styl: 'flex:1; text-align:center;');
    }
    htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'time_list.php', styl: 'flex:1;');
    echo "</div>";
} else {
    htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'time_list.php', styl: 'margin-top:20px;');
}

htm_Card_end();
echo "</div>";
?>
<script>
const projRateMap = <?php echo json_encode($proj_rate_map); ?>;
function applyDefaultRate() {
    var sel = document.querySelector('[name="proj_id"]');
    var rateField = document.getElementById('rate_field');
    if (!sel || !rateField) return;
    var rate = projRateMap[sel.value];
    if (rate && !rateField.value) {
        rateField.value = rate.toLocaleString('da-DK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
}
</script>
<?php
htm_Footer();
ob_end_flush();
?>
