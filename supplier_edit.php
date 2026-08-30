<?php # /supplier_edit.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Leverandørmodul - opret/redigér en leverandør. Samme
# strukturmønster som customer_edit.php (kunde-siden), inkl. server-side
# e-mail-validering (§bugs-batch-22-review's fund på kundesiden gentaget
# forebyggende her).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$supplier_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = ""; $err = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = DB::escape($conn, trim($_POST['supplier_name'] ?? ''));
    $contact = DB::escape($conn, $_POST['contact_person'] ?? '');
    $address = DB::escape($conn, $_POST['address'] ?? '');
    $cvr     = DB::escape($conn, $_POST['cvr'] ?? '');
    $phone   = DB::escape($conn, $_POST['phone'] ?? '');
    $email   = DB::escape($conn, $_POST['email'] ?? '');
    $days    = (int)($_POST['payment_days'] ?? 8);
    $notes   = DB::escape($conn, $_POST['notes'] ?? '');
    $active  = isset($_POST['is_active']) ? 1 : 0;

    $raw_email = trim($_POST['email'] ?? '');
    $raw_name  = trim($_POST['supplier_name'] ?? '');

    if ($raw_name === '') {
        $err = lang('@Please enter a supplier name.');
    } elseif ($raw_email !== '' && filter_var($raw_email, FILTER_VALIDATE_EMAIL) === false) {
        $err = lang('@Please enter a valid email address, or leave the field empty.');
    } elseif ($supplier_id > 0) {
        $sql = "UPDATE suppliers SET
                supplier_name = '$name', contact_person = '$contact', address = '$address',
                cvr = '$cvr', phone = '$phone', email = '$email', payment_days = $days,
                notes = '$notes', is_active = $active
                WHERE supplier_id = $supplier_id";
    } else {
        $sql = "INSERT INTO suppliers (supplier_name, contact_person, address, cvr, phone, email, payment_days, notes, is_active)
                VALUES ('$name', '$contact', '$address', '$cvr', '$phone', '$email', $days, '$notes', $active)";
    }

    if (isset($sql) && DB::query($conn, $sql)) {
        if ($supplier_id == 0) $supplier_id = DB::insert_id($conn);
        $msg = lang('@Supplier saved successfully');
    } elseif (!isset($err) || $err === '') {
        $err = lang('@SQL Error:') . " " . DB::error($conn);
    }
}

if (isset($err) && $err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['supplier_name'])) {
    // Bevar brugerens indtastede data ved en valideringsfejl (samme rettelse
    // som customer_edit.php's §bugs-batch-22-review) - genindlæsning fra
    // databasen ville ellers kassere alle øvrige felters ændringer.
    $sup = [
        'supplier_name' => $_POST['supplier_name'], 'contact_person' => $_POST['contact_person'] ?? '',
        'address' => $_POST['address'] ?? '', 'cvr' => $_POST['cvr'] ?? '',
        'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '',
        'payment_days' => (int)($_POST['payment_days'] ?? 8), 'notes' => $_POST['notes'] ?? '',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
} elseif ($supplier_id > 0) {
    $res = DB::query($conn, "SELECT * FROM suppliers WHERE supplier_id = $supplier_id");
    $sup = $res ? DB::fetch_assoc($res) : null;
    if (!$sup) {
        header("Location: supplier_list.php");
        exit;
    }
} else {
    $sup = [
        'supplier_name' => '', 'contact_person' => '', 'address' => '', 'cvr' => '',
        'phone' => '', 'email' => '', 'payment_days' => 8, 'notes' => '', 'is_active' => 1,
    ];
}

$title = $supplier_id > 0 ? lang('@Edit Supplier') . ": " . ($sup['supplier_name'] ?: '-') : lang('@Add New Supplier');
htm_Header($title);
showMenu();

if ($msg) htm_Alert($msg, 'success');
if ($err) htm_Alert($err, 'error');

echo "<div style='margin: 20px auto; width: fit-content;'>";
htm_Card_(capt: '@Supplier Information', wdth: 550, form: 'edit_supplier_form');
    echo "<div style='display:flex; width:100%; gap:10px;'>";
        htm_Field(icon: 'fa-id-badge', labl: '@ID', name: 'supplier_id_display', valu: $supplier_id > 0 ? $supplier_id : '-', type: 'view', extr: 'align-center', wdth: '15%');
        htm_Field(icon: 'fa-industry', labl: '@Supplier Name', name: 'supplier_name', valu: $sup['supplier_name'], extr: 'align-left required', wdth: '85%');
    echo "</div>";
    htm_Field(icon: 'fa-map-marker-alt', labl: '@Address', name: 'address', valu: $sup['address'], type: 'textarea', extr: 'align-left', wdth: '100%');

    echo "<div style='display:flex; width:100%; gap:6px; align-items:flex-end;'>";
        echo '<div style="width:33%; display:flex; gap:4px; align-items:flex-end;">';
        echo '<div style="flex:1;">';
        htm_Field(icon: 'fa-id-card', labl: '@CVR', name: 'cvr', valu: $sup['cvr'], extr: 'align-left', wdth: '100%');
        echo '</div>';
        // NYT (bruger-anmodet, CVR-opslag) - se customer_edit.php's samme
        // tilføjelse for den fulde begrundelse (cvr_proxy.php/cvrapi.dk).
        echo '<button type="button" onclick="cvrLookup(this)" style="flex-shrink:0; margin-bottom:2px; padding:9px 10px; background:var(--color-info); color:var(--text-light); border:none; border-radius:4px; cursor:pointer;" data-hint="'.lang('@Look up company name, address, phone and email from the CVR number').'"><i class="fa-solid fa-magnifying-glass"></i></button>';
        echo '</div>';
        htm_Field(icon: 'fa-phone', labl: '@Phone', name: 'phone', valu: $sup['phone'], extr: 'align-left', wdth: '33%');
        htm_Field(icon: 'fa-calendar-check', labl: '@Payment Days', name: 'payment_days', valu: $sup['payment_days'], type: 'number', extr: 'min="0" align-left', wdth: '34%', hint: '@Used as the default due date offset when registering an unpaid expense for this supplier.');
    echo "</div>";

    echo "<div style='display:flex; width:100%;'>";
        htm_Field(icon: 'fa-envelope', labl: '@Email', name: 'email', valu: $sup['email'], type: 'email', extr: 'align-left', wdth: '50%');
        htm_Field(icon: 'fa-user-tie', labl: '@Contact Person', name: 'contact_person', valu: $sup['contact_person'], extr: 'align-left', wdth: '50%');
    echo "</div>";

    htm_Field(icon: 'fa-sticky-note', labl: '@Notes', name: 'notes', valu: $sup['notes'], type: 'textarea', extr: 'align-left', wdth: '100%');

    echo '<div style="margin:10px 5px; padding:0 5px;">';
    echo '<label style="font-size:0.9em; cursor:pointer; display:flex; align-items:center; gap:8px;">';
    echo '<input type="checkbox" name="is_active" value="1" ' . ($sup['is_active'] ? 'checked' : '') . ' style="width:14px; height:14px;">';
    echo lang('@Active (shown in the supplier dropdown when registering an expense)');
    echo '</label></div>';

    echo "<div style='display:flex; gap:10px; margin-top:20px; border-top:1px solid #eee; padding-top:20px;'>";
        htm_Button(icon: 'fa-save', labl: '@Save Changes', type: 'success', link: '', styl: 'flex:2;', attr: 'onclick="document.getElementById(\'edit_supplier_form\').submit();" data-hint="'.lang('@Save this supplier').'"');

        if ($supplier_id > 0) {
            htm_Button(icon: 'fa-file-invoice-dollar', labl: '@Supplier Statement', type: 'info', link: 'supplier_statement.php?id='.$supplier_id, styl: 'flex:1;', attr: 'data-hint="'.lang('@View this supplier\'s expense and payment history').'"');
        }

        if ($supplier_id > 0) {
            htm_ConfirmLink(
                icon: 'fa-trash',
                labl: '@Delete',
                link: 'supplier_list.php?del='.$supplier_id,
                mess: '@Are you sure you want to delete this supplier? This cannot be undone.',
                type: 'danger',
                styl: 'flex:1; text-align:center;',
                attr: 'data-hint="'.lang('@Delete this supplier - only possible if no expenses are linked').'"'
            );
        }

        htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'supplier_list.php', styl: 'flex:1;', attr: 'data-hint="'.lang('@Return to the supplier list without saving').'"');
    echo "</div>";

htm_Card_end();

echo "</div>";
?>
<script>
// NYT (bruger-anmodet, CVR-opslag) - se customer_edit.php's samme funktion
// for den fulde begrundelse (cvr_proxy.php/cvrapi.dk).
function cvrLookup(btn) {
    var cvrField = document.querySelector('[name="cvr"]');
    var cvr = (cvrField.value || '').replace(/\D/g, '');
    if (cvr.length !== 8) {
        alert(<?php echo json_encode(lang('@Please enter a valid 8-digit CVR number first.')); ?>);
        return;
    }
    var icon = btn.querySelector('i');
    var origClass = icon.className;
    icon.className = 'fa-solid fa-spinner fa-spin';
    btn.disabled = true;

    fetch('cvr_proxy.php?cvr=' + cvr)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                alert(data.error || <?php echo json_encode(lang('@CVR lookup failed.')); ?>);
                return;
            }
            var nameField  = document.querySelector('[name="supplier_name"]');
            var addrField  = document.querySelector('[name="address"]');
            var phoneField = document.querySelector('[name="phone"]');
            var emailField = document.querySelector('[name="email"]');
            if (nameField && data.name) nameField.value = data.name;
            if (addrField && data.address) addrField.value = data.address;
            if (phoneField && !phoneField.value && data.phone) phoneField.value = data.phone;
            if (emailField && !emailField.value && data.email) emailField.value = data.email;
        })
        .catch(function() {
            alert(<?php echo json_encode(lang('@Could not reach the CVR lookup service.')); ?>);
        })
        .finally(function() {
            icon.className = origClass;
            btn.disabled = false;
        });
}
</script>
<?php
htm_Footer();
ob_end_flush();
?>
