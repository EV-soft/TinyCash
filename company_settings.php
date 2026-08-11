<?php # /company_settings.php v:1.2.0 d:2026-08-11 i:evs 
# (Opdelt i fire tematiske kort med side-overskrift "Indstillinger" - gemme-logik uaendret)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {

    if (strpos($_POST['set']['company_address'], "\n") !== false) {
        error_log("DEBUG: Linjeskift fundet i POST-data!");
    } else {
        error_log("DEBUG: INGEN linjeskift i POST-data. Browseren sender dem ikke.");
    }

    foreach ($_POST['set'] as $key => $val) {
        $clean_key = DB::escape($conn, $key);
        $val = trim($val);

        if ($val === '') {
            DB::query($conn, "DELETE FROM settings WHERE setting_key = '$clean_key'");
        } else {
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

            $res = DB::query($conn, $sql);
            if (!$res) {
                error_log("Fejl ved lagring af $clean_key: " . DB::error($conn));
            }
        }
    }
    header("Location: company_settings.php?msg=updated");
    exit;
}

$comp = get_settings($conn);
$msg = (isset($_GET['msg']) && $_GET['msg'] == 'updated') ? lang('@Settings updated successfully') : "";

htm_Header(capt: '@Settings');
showMenu();

if($msg) htm_Alert(text: $msg, type: 'success');

// --- SIDE-OVERSKRIFT ---
echo '<div style="max-width:650px; margin:20px auto 0; padding:0 5px;">';
echo '  <h1 style="margin:0; color: var(--text-main, #2c3e50); text-align:center;"><i class="fa-solid fa-gear" style="color:var(--color-primary);"></i> ' . lang('@Settings') . '</h1>';
echo '</div>';

// Alle indstillinger gemmes via EN faelles form, saa den enkelte Gem-knap
// under kortene sender felter fra alle tre kort paa en gang (uaendret handler).
echo '<form method="post">';

// =====================================================================
// KORT 1: VIRKSOMHED - OPLYSNINGER
// =====================================================================
htm_Card_(capt: '@Company - Information', wdth: 650);

htm_InputGroup(icon: 'fa-building', labl: '@Company Name', name: 'set[company_name]', valu: $comp['company_name'] ?? '', legd:'align-left');
htm_InputGroup(icon: 'fa-id-card', labl: '@CVR Number', name: 'set[company_cvr]', valu: $comp['company_cvr'] ?? '', wdth: '34%', legd:'align-left');
htm_InputGroup(icon: 'fa-envelope', labl: '@Email', name: 'set[company_email]', valu: $comp['company_email'] ?? '', type: 'email', wdth: '66%', legd:'align-left');
htm_InputGroup(
    icon: 'fa-map-marker-alt',
    labl: '@Address',
    name: 'set[company_address]',
    valu: $comp['company_address'] ?? '',
    type: 'textarea',
    legd: 'align-left'
);
htm_InputGroup(icon: 'fa-phone', labl: '@Phone Number', name: 'set[company_phone]', valu: $comp['company_phone'] ?? '', wdth: '33%', extr:'align-right', legd:'align-center');
htm_InputGroup(icon: 'fa-university', labl: '@Reg. No.', name: 'set[bank_reg]', valu: $comp['bank_reg'] ?? '', wdth: '34%', legd:'align-left');
htm_InputGroup(icon: 'fa-piggy-bank', labl: '@Account No.', name: 'set[bank_acc]', valu: $comp['bank_acc'] ?? '', wdth: '33%', legd:'align-left');
htm_InputGroup(icon: 'fa-info-circle', labl: '@Extra Info', name: 'set[company_extra]', valu: $comp['company_extra'] ?? '', wdth: '100%', legd:'align-left');
echo '<br><br><hr><br>';
htm_InputGroup(icon: 'fa-comment-alt', labl: '@Default Email Message', name: 'set[default_mail_body]', valu: $comp['default_mail_body'] ?? "Please find your invoice attached.\n\nBest regards,", type: 'textarea', legd: 'align-left', extr: 'rows="3"');

htm_Card_end();

// =====================================================================
// KORT 2: REGNSKAB - INDSTILLINGER / EXPORT
// =====================================================================
htm_Card_(capt: '@Accounting - Settings / Export', wdth: 650);

echo '<div style="display: flex; flex-wrap: wrap; gap: 0 15px; width: 100%; box-sizing: border-box;">';

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

    $currency_options = [
        'DKK' => 'DKK - Danske Kroner',
        'EUR' => 'EUR - Euro',
        'USD' => 'USD - US Dollar',
        'SEK' => 'SEK - Svenske Kroner',
        'NOK' => 'NOK - Norske Kroner'
    ];

    htm_InputGroup(
        icon: 'fa-money-bill-wave',
        labl: '@Currency',
        name: 'set[currency]',
        valu: $comp['currency'] ?? 'DKK',
        type: 'sele',
        opti: $currency_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left',
        hint: '<span style="color:var(--color-warning); font-weight:bold;">' . lang('@Warning: Changing currency may affect historical data visibility.') . '</span>'
    );

    $accounts_res = DB::query($conn, "SELECT acc_id, acc_name FROM accounts ORDER BY acc_id ASC");
    $account_options = [];
    while ($row = DB::fetch_assoc($accounts_res)) {
        $account_options[$row['acc_id']] = $row['acc_id'] . ' - ' . $row['acc_name'];
    }

    htm_InputGroup(
        icon: 'fa-university',
        labl: '@Default Bank Account',
        name: 'set[conf_acc_bank]',
        valu: $comp['conf_acc_bank'] ?? 5000,
        type: 'select',
        opti: $account_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left'
    );

    htm_InputGroup(
        icon: 'fa-users',
        labl: '@Default Debitor Account',
        name: 'set[conf_acc_debitor]',
        valu: $comp['conf_acc_debitor'] ?? 8100,
        type: 'select',
        opti: $account_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left'
    );

    htm_InputGroup(
        icon: 'fa-lock',
        labl: '@Accounting Lock Date',
        name: 'set[accounting_lock_date]',
        valu: $comp['accounting_lock_date'] ?? '',
        type: 'date',
        wdth: 'calc(50% - 8px)',
        hint: '@Transactions on or before this date cannot be created, edited, or deleted in the journal.',
        legd: 'align-left'
    );

echo '</div>';

// --- EXPORT ---
echo '<div style="margin-top:20px; border-top:1px solid var(--border-color); padding-top:15px; text-align:left;">';
htm_Button(
    icon: 'fa-download',
    labl: '@Export SAF-T',
    type: 'info',
    attr: 'onclick="triggerSaftExport(this); return false;" id="saftBtn"
          data-hint="@Eksporter virksomhedens regnskabsdata i XML-standardformat kaldet SAF-T (Standard Audit File for Tax)"'
);
echo '</div>';

htm_Card_end();

// =====================================================================
// KORT 3: PROGRAM - MODULINDSTILLINGER
// =====================================================================
htm_Card_(capt: '@Program - Module Settings', wdth: 650);

$proj_active = !empty($comp['module_projects']) && $comp['module_projects'] == '1';
echo '<div style="display: flex; flex-wrap: wrap; gap: 0 15px; width: 100%; box-sizing: border-box; align-items: center;">';

    htm_InputGroup(
        icon: 'fa-folder-open',
        labl: '@Project Module',
        name: 'set[module_projects]',
        valu: $proj_active ? '1' : '0',
        type: 'sele',
        opti: ['1' => '✅ ' . lang('@Active'), '0' => '⬜ ' . lang('@Inactive')],
        wdth: 'calc(50% - 8px)',
        legd: 'align-left',
        hint: '@Activate to enable project tracking — adds ProjectCode field to expenses, invoices and bank reconciliation. Shows Projects menu.'
    );

    // Info-tekst ved siden af dropdown
    echo '<div style="flex:1; padding: 10px; font-size: 0.88em; color: var(--text-muted); line-height: 1.5;">';
    if ($proj_active) {
        echo '<i class="fa fa-check-circle" style="color:var(--color-success);"></i> ';
        echo lang('@Module is active.') . ' ';
        echo '<a href="project_view.php" style="color:var(--color-primary);">' . lang('@Go to Projects') . ' →</a>';
    } else {
        echo '<i class="fa fa-info-circle" style="color:var(--color-secondary);"></i> ';
        echo lang('@Activate to track expenses, hours and invoices per project/customer.');
    }
    echo '</div>';

    // --- Valuta-modul (fremmed valuta på fakturaer/udgifter + omregner) ---
    $curr_active = !empty($comp['module_currency']) && $comp['module_currency'] == '1';
    htm_InputGroup(
        icon: 'fa-coins',
        labl: '@Foreign Currency Module',
        name: 'set[module_currency]',
        valu: $curr_active ? '1' : '0',
        type: 'sele',
        opti: ['1' => '✅ ' . lang('@Active'), '0' => '⬜ ' . lang('@Inactive')],
        wdth: 'calc(50% - 8px)',
        legd: 'align-left',
        hint: '@Activate to register invoices and expenses in foreign currency with exchange rates, and show the currency converter.'
    );

    echo '<div style="flex:1; padding: 10px; font-size: 0.88em; color: var(--text-muted); line-height: 1.5;">';
    if ($curr_active) {
        echo '<i class="fa fa-check-circle" style="color:var(--color-success);"></i> ';
        echo lang('@Module is active.');
    } else {
        echo '<i class="fa fa-info-circle" style="color:var(--color-secondary);"></i> ';
        echo lang('@Activate to register invoices and receipts in foreign currency with exchange rates.');
    }
    echo '</div>';

echo '</div>';

htm_Card_end();

// --- FAELLES GEM-KNAP (gemmer felter fra alle tre kort ovenfor) ---
echo '<div style="max-width:650px; margin:0 auto 20px; padding:0 5px;">';
    htm_Button(icon: 'fa-save', labl: '@Save Company Settings', type: 'success', attr: 'name="save_settings"', styl: 'width:100%; padding:15px; font-weight:bold;');
echo '</div>';

echo '</form>';

// =====================================================================
// KORT 4: SIKKERHED - OM BACKUP
// =====================================================================
htm_Card_(capt: '@Security - About Backup', wdth: 650);

echo '<div style="padding: 15px; background: var(--bg-panel); border-left: 4px solid var(--color-warning); border-radius: 4px; text-align: left; box-sizing: border-box; width: 100%;">';
    echo '<strong style="color: var(--color-warning); font-size: 14px;">';
        echo '<i class="fa-solid fa-cloud-arrow-down"></i> ' . lang('@Important regarding backup (Bookkeeping Act):');
    echo '</strong>';
    echo '<p style="margin: 5px 0 0 0; font-size: 13px; color: var(--text-main); line-height: 1.5;">';
        echo lang('@The system automatically saves backup files in the backups folder on the server. To comply with legal data protection requirements, you must regularly download these .zip files and store them on an external data medium (e.g., a local hard drive, USB drive, or secure cloud storage).');
    echo '</p>';
    echo '<div style="margin-top: 10px;">';
        echo '<a href="storage_browser.php?folder=backups" style="font-size: 13px; color: var(--color-primary); text-decoration: none; font-weight: bold;">';
            echo '<i class="fa-solid fa-folder-open"></i> ' . lang('@Go to System File Browser to Download Backups');
        echo '</a>';
    echo '</div>';
echo '</div>';

htm_Card_end();

// --- AUTOMATISK KRYPTERET OFF-SITE BACKUP (21-dages interval) ---
require_once 'inc/auto_backup_integration.php';
render_auto_backup_settings($conn);

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

document.querySelector('form').addEventListener('submit', function(e) {
    const currencyInput = document.querySelector('select[name="set[currency]"]');
    const originalValue = "<?php echo $comp['currency'] ?? 'DKK'; ?>";
    if (currencyInput.value !== originalValue) {
        if (!confirm("<?php echo lang('@Warning: You are changing the base currency. This may cause inconsistencies in historical reports. Are you sure?'); ?>")) {
            e.preventDefault();
        }
    }
});
</script>
<?php
ob_end_flush();
?>
