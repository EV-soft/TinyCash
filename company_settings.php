<?php # /company_settings.php v:1.1.0 d:2026-07-05 i:evs
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    foreach ($_POST['set'] as $key => $val) {
        $key = DB::real_escape_string($conn, $key);
        $val = DB::real_escape_string($conn, $val);
        DB::query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$val') ON DUPLICATE KEY UPDATE setting_value = '$val'");
    }
    header("Location: company_settings.php?msg=updated");
    exit;
}

$s = get_settings($conn);
$msg = (isset($_GET['msg']) && $_GET['msg'] == 'updated') ? lang('@Settings updated successfully') : "";

htm_Header(capt: 'Tiny Cash');
showMenu();

if($msg) htm_Alert(text: $msg, type: 'success');

htm_Card_(capt: '@Company Information', wdth: 650, info: '', form: 'post');

htm_InputGroup(icon: 'fa-building', labl: '@Company Name', name: 'set[company_name]', valu: $s['company_name'] ?? '', legd:'align-left');
htm_InputGroup(icon: 'fa-id-card', labl: '@CVR Number', name: 'set[company_cvr]', valu: $s['company_cvr'] ?? '', wdth: '34%', legd:'align-left');
htm_InputGroup(icon: 'fa-envelope', labl: '@Email', name: 'set[company_email]', valu: $s['company_email'] ?? '', type: 'email', wdth: '66%', legd:'align-left');
htm_InputGroup(icon: 'fa-map-marker-alt', labl: '@Address', name: 'set[company_address]', valu: $s['company_address'] ?? '', type: 'textarea', legd:'align-left');
htm_InputGroup(icon: 'fa-phone', labl: '@Phone Number', name: 'set[company_phone]', valu: $s['company_phone'] ?? '', wdth: '33%', extr:'align-right', legd:'align-center');
htm_InputGroup(icon: 'fa-university', labl: '@Reg. No.', name: 'set[bank_reg]', valu: $s['bank_reg'] ?? '', wdth: '34%', legd:'align-left');
htm_InputGroup(icon: 'fa-piggy-bank', labl: '@Account No.', name: 'set[bank_acc]', valu: $s['bank_acc'] ?? '', wdth: '33%', legd:'align-left');
htm_InputGroup(icon: 'fa-info-circle', labl: '@Extra Info', name: 'set[company_extra]', valu: $s['company_extra'] ?? '', wdth: '100%', legd:'align-left');
echo '<br><br><hr><br>';
htm_InputGroup(icon: 'fa-comment-alt', labl: '@Default Email Message', name: 'set[default_mail_body]', valu: $s['default_mail_body'] ?? "Please find your invoice attached.\n\nBest regards,", type: 'textarea', legd: 'align-left', extr: 'rows="3"');


// --- INTEGRATION AF DATOFORMAT & REGNSKABSLÅSEDATO (DELER LINJE VIA WDTH) ---
// --- BOGFØRINGSLOVEN & SYSTEMKONTI (SAMLET I ÉN GRUPPE) ---
echo '<fieldset class="field-group" style="margin-bottom: 25px; border-color: #3498db !important; width: 100%; box-sizing: border-box; clear: both;">';
    echo '<legend style="color: #3498db;">' . lang('@Accounting Settings') . '</legend>';
    echo '<div style="display: flex; flex-wrap: wrap; gap: 0 15px; width: 100%; box-sizing: border-box;">';

        // 1. Datoformat
        htm_InputGroup(
            icon: 'fa-calendar-days', 
            labl: '@Date Format', 
            name: 'set[date_format]', 
            valu: CONF_DATE_FORMAT, 
            type: 'select', 
            opti: [
                'd.m.Y' => '09.06.2026 (DD.MM.ÅÅÅÅ)',
                'Y-m-d' => '2026-06-09 (ÅÅÅÅ-MM-DD)',
                'd/m/Y' => '09/06/2026 (DD/MM/ÅÅÅÅ)',
                'd-m-Y' => '09-06-2026 (DD-MM-ÅÅÅÅ)'
            ],
            wdth: 'calc(50% - 8px)',
            legd: 'align-left'
        );

        // 2. Regnskabslåsedato
        htm_InputGroup(
            icon: 'fa-lock', 
            labl: '@Accounting Lock Date', 
            name: 'set[accounting_lock_date]', 
            valu: $s['accounting_lock_date'] ?? '', 
            type: 'date',
            wdth: 'calc(50% - 8px)',
            hint: '@Transactions on or before this date cannot be created, edited, or deleted in the journal.',
            legd: 'align-left'
        );

        // Hent kontoplanen dynamisk til dropdown-menuerne
        $accounts_res = DB::query($conn, "SELECT acc_id, acc_name FROM accounts ORDER BY acc_id ASC");
        $account_options = [];
        while ($row = DB::fetch_assoc($accounts_res)) {
            $account_options[$row['acc_id']] = $row['acc_id'] . ' - ' . $row['acc_name'];
        }

        // 3. Standard Bankkonto
        htm_InputGroup(
            icon: 'fa-university', 
            labl: '@Default Bank Account', 
            name: 'set[conf_acc_bank]', 
            valu: $s['conf_acc_bank'] ?? 5000, 
            type: 'select', 
            opti: $account_options,
            wdth: 'calc(50% - 8px)',
            legd: 'align-left'
        );

        // 4. Standard Debitorkonto
        htm_InputGroup(
            icon: 'fa-users', 
            labl: '@Default Debitor Account', 
            name: 'set[conf_acc_debitor]', 
            valu: $s['conf_acc_debitor'] ?? 8100, 
            type: 'select', 
            opti: $account_options,
            wdth: 'calc(50% - 8px)',
            legd: 'align-left'
        );

    echo '</div>';
echo '</fieldset>';

// --- INFORMATION OM EKSTERN SIKKERHEDSKOPIERING (BOGFØRINGSLOVEN) ---
echo '<div style="margin-top: 15px; padding: 15px; background: #fff9f3; border-left: 4px solid #e67e22; border-radius: 4px; text-align: left; box-sizing: border-box; width: 100%; clear: both;">';
    echo '<strong style="color: #d35400; font-size: 13px;">';
        echo '<i class="fa-solid fa-cloud-arrow-down"></i> ' . lang('@Important regarding backup (Bookkeeping Act):');
    echo '</strong>';
    echo '<p style="margin: 5px 0 0 0; font-size: 11px; color: #555; line-height: 1.5;">';
        echo lang('@The system automatically saves backup files in the backups folder on the server. To comply with legal data protection requirements, you must regularly download these .zip files and store them on an external data medium (e.g., a local hard drive, USB drive, or secure cloud storage).');
    echo '</p>';
    echo '<div style="margin-top: 10px;">';
        echo '<a href="storage_browser.php?folder=backups" style="font-size: 11px; color: #2980b9; text-decoration: none; font-weight: bold;">';
            echo '<i class="fa-solid fa-folder-open"></i> ' . lang('@Go to System File Browser to Download Backups');
        echo '</a>';
    echo '</div>';
echo '</div><br>';

htm_Button(
    icon: 'fa-download', 
    labl: '@Export SAF-T', 
    type: 'info', 
    attr: 'onclick="triggerSaftExport(this)" id="saftBtn"
          data-hint= "@Eksporter virksomhedens regnskabsdata i XML-standardformat kaldet SAF-T (Standard Audit File for Tax)"
          '
);

htm_Button(icon: 'fa-save', labl: '@Save Company Settings', type: 'success', attr: 'name="save_settings"', styl: 'width:100%; padding:15px; font-weight:bold; margin-top:10px;', cont: '<div style="margin-top:30px; border-top:1px solid #eee; padding-top:20px;"></div>');
htm_Card_end();
htm_Footer(); 
?>
<script>
function triggerSaftExport(btn) {
    const oldIcon = 'fa-download';
    const oldText = "<?php echo lang('@Export SAF-T'); ?>";
    btn.disabled = true;
    btn.innerHTML = `<i class="fa fa-spinner fa-spin"></i> <?php echo lang('@Generating...'); ?>`;
    window.location.href = 'export_saft.php';
    setTimeout(() => {
        btn.innerHTML = `<i class="fa fa-check"></i> <?php echo lang('@Export Complete'); ?>`;
        btn.className = btn.className.replace('btn-info', 'btn-success');
        setTimeout(() => {
            btn.disabled = false;
            btn.className = btn.className.replace('btn-success', 'btn-info');
            btn.innerHTML = `<i class="fa ${oldIcon}"></i> ${oldText}`;
        }, 3000);
    }, 1000);
}
</script>
<?php
ob_end_flush();
?>