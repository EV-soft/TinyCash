<?php # /company_settings.php v:1.3.0 d:2026-08-30 i:evs
# logger nu ændringer af de særlige conf_acc_*-posteringskonti til revisionssporet
# (Opdelt i fire tematiske kort med side-overskrift "Indstillinger" - gemme-logik uaendret)
# v1.3.0: selskabsform/ledelsesnavn/by tilføjet - bruges på årsrapportens
# ledelsespåtegning (annual_report.php, §regnskabslov-status)
# v1.3.1: fjernet ubetinget DEBUG-logning ved hver gemning (leftover fra en
# tidligere fejlsøgning af linjeskift i company_address) - fyldte fejlloggen
# med støj og udløste falske "frisk fejl"-visninger i about.php (v1.3.0)
# KRITISK (§bugs-batch-15-review): siden havde INTET niveau-tjek overhovedet,
# selvom den styrer de fem conf_acc_*-posteringskonti (allerede logget til
# revisionssporet netop pga. deres høje følsomhed, se ovenfor), valuta,
# datoformat, modul-til/fra samt destinations-mailen for den automatiske
# krypterede backup - enhver logget-ind niveau-1-bruger kunne ændre dem alle.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {

    // Hent de nuværende conf_acc_*-værdier FØR gemning, til revisionssporet -
    // disse styrer, hvor ALT fremtidig automatisk postering lander (bank,
    // debitor, salg, moms), kvalitativt anderledes end almindelige felter
    // som firmanavn/adresse. En ændring her kunne stille og roligt omdirigere
    // fremtidige posteringer uden spor. Bruger-anmodet.
    // RETTET (leverandørmodul, se db-setup/migrate_suppliers.php): tilføjet
    // conf_acc_creditor til revisionslisten - styrer, hvor "Ikke betalt
    // endnu"-udgifter krediteres i stedet for banken, lige så følsom som de
    // øvrige særlige posteringskonti.
    $conf_acc_keys = ['conf_acc_bank', 'conf_acc_debitor', 'conf_acc_creditor', 'conf_acc_sales', 'conf_acc_vat', 'conf_acc_purchase_vat', 'conf_acc_fx'];
    $before_settings = get_settings($conn);

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

    // Log kun hvis en eller flere af de særlige conf_acc_*-konti faktisk
    // ændrede sig - ikke ved en almindelig gemning der ikke rørte dem.
    $acc_old = []; $acc_new = [];
    foreach ($conf_acc_keys as $k) {
        $old_val = $before_settings[$k] ?? null;
        $new_val = isset($_POST['set'][$k]) ? trim($_POST['set'][$k]) : $old_val;
        if ((string)$old_val !== (string)$new_val) {
            $acc_old[$k] = $old_val;
            $acc_new[$k] = $new_val;
        }
    }
    if (!empty($acc_new)) {
        log_action($conn, 'UPDATE_POSTING_ACCOUNTS', 'settings', 0, $acc_old, $acc_new);
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
csrf_field();

// =====================================================================
// KORT 1: VIRKSOMHED - OPLYSNINGER
// =====================================================================
htm_Card_(capt: '@Company - Information', wdth: 650);

htm_Field(icon: 'fa-building', labl: '@Company Name', name: 'set[company_name]', valu: $comp['company_name'] ?? '', legd:'align-left');
htm_Field(icon: 'fa-id-card', labl: '@CVR Number', name: 'set[company_cvr]', valu: $comp['company_cvr'] ?? '', wdth: '34%', legd:'align-left');
htm_Field(icon: 'fa-envelope', labl: '@Email', name: 'set[company_email]', valu: $comp['company_email'] ?? '', type: 'email', wdth: '66%', legd:'align-left');
htm_Field(
    icon: 'fa-scale-balanced',
    labl: '@Legal Form',
    name: 'set[company_legal_form]',
    valu: $comp['company_legal_form'] ?? '',
    type: 'sele',
    opti: [
        '' => '-- ' . lang('@Select') . ' --',
        'Enkeltmandsvirksomhed' => 'Enkeltmandsvirksomhed',
        'I/S' => 'I/S (Interessentskab)',
        'IVS' => 'IVS',
        'ApS' => 'ApS',
        'A/S' => 'A/S',
        'Andet' => lang('@Other'),
    ],
    wdth: '48%',
    legd:'align-left',
    hint: '@Used on the annual report cover page.'
);
htm_Field(icon: 'fa-user-tie', labl: '@Management Name (for signing)', name: 'set[company_management_name]', valu: $comp['company_management_name'] ?? '', wdth: '52%', legd:'align-left', hint: '@Name of the director/owner who signs the annual report.');
htm_Field(icon: 'fa-city', labl: '@City (for signing location)', name: 'set[company_city]', valu: $comp['company_city'] ?? '', wdth: '48%', legd:'align-left');
htm_Field(
    icon: 'fa-map-marker-alt',
    labl: '@Address',
    name: 'set[company_address]',
    valu: $comp['company_address'] ?? '',
    type: 'textarea',
    legd: 'align-left'
);
htm_Field(icon: 'fa-phone', labl: '@Phone Number', name: 'set[company_phone]', valu: $comp['company_phone'] ?? '', wdth: '33%', extr:'align-right', legd:'align-center');
htm_Field(icon: 'fa-university', labl: '@Reg. No.', name: 'set[bank_reg]', valu: $comp['bank_reg'] ?? '', wdth: '34%', legd:'align-left');
htm_Field(icon: 'fa-piggy-bank', labl: '@Account No.', name: 'set[bank_acc]', valu: $comp['bank_acc'] ?? '', wdth: '33%', legd:'align-left');
htm_Field(icon: 'fa-info-circle', labl: '@Extra Info', name: 'set[company_extra]', valu: $comp['company_extra'] ?? '', wdth: '100%', legd:'align-left');
echo '<br><br><hr><br>';
htm_Field(icon: 'fa-comment-alt', labl: '@Default Email Message', name: 'set[default_mail_body]', valu: $comp['default_mail_body'] ?? "Please find your invoice attached.\n\nBest regards,", type: 'textarea', legd: 'align-left', extr: 'rows="3"');

htm_Card_end();

// =====================================================================
// KORT 2: REGNSKAB - INDSTILLINGER / EXPORT
// =====================================================================
htm_Card_(capt: '@Accounting - Settings / Export', wdth: 650);

echo '<div style="display: flex; flex-wrap: wrap; gap: 0 15px; width: 100%; box-sizing: border-box;">';

    htm_Field(
        icon: 'fa-calendar-days',
        labl: '@Date Format',
        name: 'set[date_format]',
        valu: CONF_DATE_FORMAT,
        type: 'sele',
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

    htm_Field(
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

    htm_Field(
        icon: 'fa-university',
        labl: '@Default Bank Account',
        name: 'set[conf_acc_bank]',
        valu: $comp['conf_acc_bank'] ?? 5000,
        type: 'sele',
        opti: $account_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left'
    );

    htm_Field(
        icon: 'fa-users',
        labl: '@Default Debitor Account',
        name: 'set[conf_acc_debitor]',
        valu: $comp['conf_acc_debitor'] ?? 8100,
        type: 'sele',
        opti: $account_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left'
    );

    htm_Field(
        icon: 'fa-truck-ramp-box',
        labl: '@Default Creditor Account',
        name: 'set[conf_acc_creditor]',
        valu: $comp['conf_acc_creditor'] ?? 4000,
        type: 'sele',
        opti: $account_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left',
        hint: lang('@Used when an expense is registered as "not yet paid" - credited instead of the bank account until it is marked paid.')
    );

    htm_Field(
        icon: 'fa-cash-register',
        labl: '@Default Sales Account',
        name: 'set[conf_acc_sales]',
        valu: $comp['conf_acc_sales'] ?? 1000,
        type: 'sele',
        opti: $account_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left'
    );

    htm_Field(
        icon: 'fa-percent',
        labl: '@Default Output VAT Account',
        name: 'set[conf_acc_vat]',
        valu: $comp['conf_acc_vat'] ?? 6900,
        type: 'sele',
        opti: $account_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left'
    );

    htm_Field(
        icon: 'fa-percent',
        labl: '@Default Input (Purchase) VAT Account',
        name: 'set[conf_acc_purchase_vat]',
        valu: $comp['conf_acc_purchase_vat'] ?? 6910,
        type: 'sele',
        opti: $account_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left'
    );

    // NYT (§reel-multi-valuta-bogforing): bruges af reconcile_action.php når
    // en udenlandsk faktura afsluttes med en kursforskel mellem det bogførte
    // DKK-beløb og det faktisk indbetalte - se db-setup/migrate_currency_
    // gainloss.php.
    htm_Field(
        icon: 'fa-money-bill-transfer',
        labl: '@Currency Gain/Loss Account',
        name: 'set[conf_acc_fx]',
        valu: $comp['conf_acc_fx'] ?? 7200,
        type: 'sele',
        opti: $account_options,
        wdth: 'calc(50% - 8px)',
        legd: 'align-left',
        hint: lang('@Used when a foreign-currency invoice is settled at a different exchange rate than it was invoiced at - the difference is posted here as a currency gain or loss.')
    );

    htm_Field(
        icon: 'fa-paperclip',
        labl: '@Attachment required above (kr)',
        name: 'set[conf_attachment_limit]',
        valu: $comp['conf_attachment_limit'] ?? 500,
        type: 'text',
        hint: '@Expenses at or above this amount require an attached voucher. Below it, a reason for the missing attachment must be given instead.',
        wdth: 'calc(50% - 8px)',
        legd: 'align-left'
    );

    htm_Field(
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
// RETTET (§bugs-batch-12-review): knappen kaldte export_saft.php, som ikke
// fandtes noget sted i projektet - hvert klik endte på en rå 404. Filen er
// nu bygget (rigtig OECD SAF-T Financial-lignende eksport af kontoplan,
// kunder, leverandører og hele hovedbogen for et valgt år) - knappen linker
// til dens periode-valgsside i stedet for at forsøge et direkte download.
echo '<div style="margin-top:20px; border-top:1px solid var(--border-color); padding-top:15px; text-align:left;">';
// RETTET (§currency-setting-is-cosmetic-label, Fase 2): SAF-T er en dansk
// SKAT-specifik eksport - knappen er derfor grå/deaktiveret når firmaets
// bogføringsvaluta ikke er DKK (siden selv spærrer stadig direkte adgang
// via require_dkk_base_currency(), dette er kun for at undgå en dødt link).
$__base_currency_saft = strtoupper($comp['currency'] ?? 'DKK');
if ($__base_currency_saft === 'DKK') {
    htm_Button(
        icon: 'fa-download',
        labl: '@Export SAF-T',
        type: 'info',
        link: 'export_saft.php',
        attr: 'data-hint="@Eksporter virksomhedens regnskabsdata i XML-standardformat kaldet SAF-T (Standard Audit File for Tax)"'
    );
} else {
    htm_Button(
        icon: 'fa-download',
        labl: '@Export SAF-T',
        type: 'secondary',
        link: '',
        attr: 'disabled data-hint="'.lang('@This feature follows Danish-specific tax and bookkeeping rules, and is only available when your company\'s base currency is DKK.').'"'
    );
}
echo '</div>';

htm_Card_end();

// =====================================================================
// KORT 3: PROGRAM - MODULINDSTILLINGER
// =====================================================================
htm_Card_(capt: '@Program - Module Settings', wdth: 650);

$proj_active = !empty($comp['module_projects']) && $comp['module_projects'] == '1';
echo '<div style="display: flex; flex-wrap: wrap; gap: 0 15px; width: 100%; box-sizing: border-box; align-items: center;">';

    htm_Field(
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
    htm_Field(
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
    htm_Button(icon: 'fa-save', labl: '@Save Company Settings', type: 'success', attr: 'name="save_settings" data-hint="'.lang('@Save all company and accounting settings above').'"', styl: 'width:100%; padding:15px; font-weight:bold;');
echo '</div>';

echo '</form>';

// RETTET (bruger-rapporteret, samme oprydning som automatisk-backup-boksen
// nedenfor): "Sikkerhed - Om Backup"-kortet er flyttet til backup.php's
// Manuel Backup-sektion (det handler om backups/-mappen, som kun de manuelle
// handlinger reelt skriver til - se rettelsen der for hvorfor "automatically"
// i den gamle tekst selv var en del af forvirringen).

// RETTET (bruger-rapporteret: "backup" var ikke et klart begreb): den fulde
// automatisk-backup-boks (status/fejl/opsætning) er flyttet til backup.php,
// under sin egen "🤖 Automatisk Backup"-sektion, samlet med resten af alt
// der hedder backup i stedet for spredt ud på en helt anden side om
// firmaindstillinger. Et kort krydslink herfra, så man stadig finder den.
echo '<div style="max-width:650px; margin:0 auto 20px; padding:0 5px;">';
echo '<a href="backup.php" style="display:block; background:var(--bg-panel); border:1px solid var(--border-color); border-radius:8px; padding:14px 18px; text-decoration:none; color:var(--text-main); font-size:0.9em;">';
echo '<i class="fa-solid fa-shield-halved" style="color:var(--color-primary); margin-right:8px;"></i>' . lang('@Automatic backup status and setup has moved to Backup Management') . ' <i class="fa-solid fa-arrow-right" style="margin-left:4px;"></i>';
echo '</a>';
echo '</div>';

htm_Footer();
?>
<script>
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
