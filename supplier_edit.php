<?php # /supplier_edit.php v:1.3.1 d:2026-09-15 i:evs
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
    $days    = max(0, (int)($_POST['payment_days'] ?? 8));      // RETTET: negative dage gav et forfald før udgiftens dato
    $notes   = DB::escape($conn, $_POST['notes'] ?? '');
    $active  = isset($_POST['is_active']) ? 1 : 0;

    $raw_email = trim($_POST['email'] ?? '');
    $raw_name  = trim($_POST['supplier_name'] ?? '');

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 31):
    // valgfrie bankoplysninger - nødvendige for CSV-eksporten til
    // bankbetaling (supplier_payment_export.php). Samme "stille degradering
    // hvis ikke migreret endnu"-mønster som andre nyere felter.
    $has_bank_cols = false;
    if (DB::is_sqlite()) {
        $bc = DB::query($conn, "PRAGMA table_info(suppliers)");
        if ($bc) { while ($bcr = DB::fetch_assoc($bc)) { if ($bcr['name'] === 'supplier_reg_no') { $has_bank_cols = true; break; } } }
    } else {
        $bc = DB::query($conn, "SHOW COLUMNS FROM suppliers LIKE 'supplier_reg_no'");
        $has_bank_cols = ($bc && DB::num_rows($bc) > 0);
    }
    $reg_no_raw  = trim($_POST['supplier_reg_no'] ?? '');
    $acct_no_raw = trim($_POST['supplier_account_no'] ?? '');
    $reg_no_sql  = $reg_no_raw !== ''  ? "'" . DB::escape($conn, $reg_no_raw) . "'"  : 'NULL';
    $acct_no_sql = $acct_no_raw !== '' ? "'" . DB::escape($conn, $acct_no_raw) . "'" : 'NULL';

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): denne side
    // kaldte FØR aldrig log_action() overhovedet - i modsætning til
    // customer_edit.php/account_edit.php/vat_codes.php, som alle logger
    // ændringer af stamdata. En leverandørs kontaktoplysninger, CVR eller
    // betalingsbetingelser kunne dermed ændres helt usporbart. Henter
    // "før"-billedet FØR selve UPDATE'en, samme mønster som
    // account_edit.php's $before_row.
    $before_row = ($supplier_id > 0)
        ? DB::fetch_assoc(DB::query($conn, "SELECT * FROM suppliers WHERE supplier_id = $supplier_id"))
        : null;

    if ($raw_name === '') {
        $err = lang('@Please enter a supplier name.');
    } elseif ($raw_email !== '' && filter_var($raw_email, FILTER_VALIDATE_EMAIL) === false) {
        $err = lang('@Please enter a valid email address, or leave the field empty.');
    } elseif ($supplier_id > 0) {
        $sql = "UPDATE suppliers SET
                supplier_name = '$name', contact_person = '$contact', address = '$address',
                cvr = '$cvr', phone = '$phone', email = '$email', payment_days = $days,
                notes = '$notes', is_active = $active"
                . ($has_bank_cols ? ", supplier_reg_no = $reg_no_sql, supplier_account_no = $acct_no_sql" : "") . "
                WHERE supplier_id = $supplier_id";
    } else {
        $ins_cols = "supplier_name, contact_person, address, cvr, phone, email, payment_days, notes, is_active";
        $ins_vals = "'$name', '$contact', '$address', '$cvr', '$phone', '$email', $days, '$notes', $active";
        if ($has_bank_cols) { $ins_cols .= ", supplier_reg_no, supplier_account_no"; $ins_vals .= ", $reg_no_sql, $acct_no_sql"; }
        $sql = "INSERT INTO suppliers ($ins_cols) VALUES ($ins_vals)";
    }

    if (isset($sql) && DB::query($conn, $sql)) {
        $after_row = [
            'supplier_name' => $raw_name, 'contact_person' => $_POST['contact_person'] ?? '',
            'address' => $_POST['address'] ?? '', 'cvr' => $_POST['cvr'] ?? '',
            'phone' => $_POST['phone'] ?? '', 'email' => $raw_email,
            'payment_days' => $days, 'notes' => $_POST['notes'] ?? '', 'is_active' => $active,
        ];
        if ($supplier_id == 0) {
            $supplier_id = DB::insert_id($conn);
            log_action($conn, 'CREATE_SUPPLIER', 'suppliers', $supplier_id, null, $after_row);
        } else {
            log_action($conn, 'UPDATE_SUPPLIER', 'suppliers', $supplier_id, $before_row, $after_row);
        }
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
        htm_Field(icon: 'fa-id-card', labl: '@CVR', name: 'cvr', valu: $sup['cvr'], extr: 'align-left onblur="checkCvrDuplicate(this.value, \'supplier\', '.$supplier_id.')"', wdth: '100%');
        echo '</div>';
        // NYT (bruger-anmodet, CVR-opslag) - se customer_edit.php's samme
        // tilføjelse for den fulde begrundelse (cvr_proxy.php/cvrapi.dk).
        // "tc-info-fill" (htm_page.lib.php): mørkere tekst/ikon i dark-temaet,
        // hvor var(--color-info) er lysegrå og ellers gør ikonet ulæseligt
        // (samme rettelse som customer_edit.php's identiske knap).
        echo '<button type="button" onclick="cvrLookup(this)" class="tc-info-fill" style="flex-shrink:0; margin-bottom:2px; padding:9px 10px; background:var(--color-info); color:var(--text-light); border:none; border-radius:4px; cursor:pointer;" data-hint="'.lang('@Look up company name, address, phone and email from the CVR number').'"><i class="fa-solid fa-magnifying-glass"></i></button>';
        echo '</div>';
        htm_Field(icon: 'fa-phone', labl: '@Phone', name: 'phone', valu: $sup['phone'], extr: 'align-left', wdth: '33%');
        htm_Field(icon: 'fa-calendar-check', labl: '@Payment Days', name: 'payment_days', valu: $sup['payment_days'], type: 'number', extr: 'min="0" align-left', wdth: '34%', hint: '@Used as the default due date offset when registering an unpaid expense for this supplier.');
    echo "</div>";
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere") - se
    // customer_edit.php's samme tilføjelse for den fulde begrundelse
    // (cvr_duplicate_check.php).
    echo '<div id="cvr_dup_warning" style="display:none; margin:8px 0; padding:8px 12px; background:var(--bg-panel); border-left:4px solid var(--color-warning); border-radius:4px; font-size:0.85em;"></div>';

    echo "<div style='display:flex; width:100%;'>";
        htm_Field(icon: 'fa-envelope', labl: '@Email', name: 'email', valu: $sup['email'], type: 'email', extr: 'align-left', wdth: '50%');
        htm_Field(icon: 'fa-user-tie', labl: '@Contact Person', name: 'contact_person', valu: $sup['contact_person'], extr: 'align-left', wdth: '50%');
    echo "</div>";

    htm_Field(icon: 'fa-sticky-note', labl: '@Notes', name: 'notes', valu: $sup['notes'], type: 'textarea', extr: 'align-left', wdth: '100%');

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 31):
    // valgfrie bankoplysninger - kun vist hvis migrationen er kørt. Bruges
    // udelukkende af CSV-eksporten til bankbetaling (supplier_payment_
    // export.php) - påvirker ikke selve udgiftsflowet.
    $show_bank_fields = false;
    if (DB::is_sqlite()) {
        $bc2 = DB::query($conn, "PRAGMA table_info(suppliers)");
        if ($bc2) { while ($bcr2 = DB::fetch_assoc($bc2)) { if ($bcr2['name'] === 'supplier_reg_no') { $show_bank_fields = true; break; } } }
    } else {
        $bc2 = DB::query($conn, "SHOW COLUMNS FROM suppliers LIKE 'supplier_reg_no'");
        $show_bank_fields = ($bc2 && DB::num_rows($bc2) > 0);
    }
    if ($show_bank_fields) {
        echo "<div style='display:flex; width:100%;'>";
        htm_Field(icon: 'fa-building-columns', labl: '@Bank Reg. No.', name: 'supplier_reg_no', valu: $sup['supplier_reg_no'] ?? '', extr: 'align-left', wdth: '30%', hint: '@Optional - used for the bank payment CSV export.');
        htm_Field(icon: 'fa-money-check', labl: '@Bank Account No.', name: 'supplier_account_no', valu: $sup['supplier_account_no'] ?? '', extr: 'align-left', wdth: '70%', hint: '@Optional - used for the bank payment CSV export.');
        echo "</div>";
    }

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
        // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 28):
        // direkte link til netop DENNE leverandørs historik.
        if ($supplier_id > 0 && ($_SESSION['user_role'] ?? '') === 'admin') {
            htm_Button(icon: 'fa-clock-rotate-left', labl: '@View History', type: 'secondary', link: 'audit_log.php?table_name=suppliers&row_id='.$supplier_id, styl: 'flex:1;', attr: 'data-hint="'.lang('@See who created or edited this supplier').'"');
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
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere") - se
// customer_edit.php's samme funktion for den fulde begrundelse
// (cvr_duplicate_check.php).
function checkCvrDuplicate(cvrRaw, type, excludeId) {
    var box = document.getElementById('cvr_dup_warning');
    if (!box) return;
    var cvr = (cvrRaw || '').replace(/\D/g, '');
    if (cvr.length !== 8) { box.style.display = 'none'; return; }
    var params = new URLSearchParams({ type: type, cvr: cvr, exclude_id: excludeId || 0 });
    fetch('cvr_duplicate_check.php?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.duplicate && data.matches && data.matches.length > 0) {
                var names = data.matches.map(function(m) { return m.name; }).join(', ');
                box.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:var(--color-warning);"></i> '
                    + <?php echo json_encode(lang('@This CVR number is already registered on:')); ?> + ' <strong>' + names.replace(/</g, '&lt;') + '</strong>.';
                box.style.display = 'block';
            } else {
                box.style.display = 'none';
            }
        })
        .catch(function() { box.style.display = 'none'; });
}
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
