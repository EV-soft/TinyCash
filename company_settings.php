<?php # /company_settings.php v:1.3.1 d:2026-09-15 i:evs
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
require_once 'inc/field_visibility.inc.php';

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): invoice_view.php
// har allerede kigget efter images/logo.png og vist den på fakturaen - men
// der var INGEN måde at uploade/skifte det logo gennem brugerfladen, kun
// direkte filsystem-/FTP-adgang til serveren. Uden det viste hver eneste
// faktura teksten "Logo missing" direkte til kunden. Egen, lille formular
// (ikke via den store indstillings-formular nedenfor, som ikke har
// multipart/form-data) - samme udvidelses-hvidliste/tilfældige-filnavn-
// mønster som expense_edit.php's bilagsupload, men gemmer altid som
// "logo.<ext>" (fast, forudsigeligt navn - invoice_view.php skal kunne
// finde den uden en database-opslag), og sletter altid alle ANDRE
// logo.*-filer først, så der aldrig ligger flere modstridende logoer.
$LOGO_EXTS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo'])) {
    if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $LOGO_EXTS, true)) {
            header("Location: company_settings.php?msg=logo_bad_type"); exit;
        }
        foreach ($LOGO_EXTS as $e) { @unlink(__DIR__ . '/images/logo.' . $e); }
        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], __DIR__ . '/images/logo.' . $ext)) {
            log_action($conn, 'UPDATE_COMPANY_LOGO', 'settings', 0, null, ['action' => 'uploaded', 'ext' => $ext]);
            header("Location: company_settings.php?msg=logo_updated"); exit;
        }
    }
    header("Location: company_settings.php?msg=logo_error"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_logo'])) {
    foreach ($LOGO_EXTS as $e) { @unlink(__DIR__ . '/images/logo.' . $e); }
    log_action($conn, 'UPDATE_COMPANY_LOGO', 'settings', 0, null, ['action' => 'removed']);
    header("Location: company_settings.php?msg=logo_removed"); exit;
}

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
    $conf_acc_keys = ['conf_acc_bank', 'conf_acc_debitor', 'conf_acc_creditor', 'conf_acc_sales', 'conf_acc_vat', 'conf_acc_purchase_vat', 'conf_acc_fx', 'conf_acc_bad_debt'];
    $before_settings = get_settings($conn);

    // RETTET (BEK 811/2026-gennemgang, Bilag 3 "låste stamoplysninger", se
    // [[digital-standard-bookkeeping-registration-goal]]): navn/adresse/CVR-nr
    // må kun opdateres via et officielt CVR-opslag (company_cvr_sync_action.php),
    // ALDRIG ved frit indtastet tekst - heller ikke via et forfalsket POST-kald
    // direkte mod denne handler. Felterne er ganske vist readonly/styret i selve
    // formularen (se nedenfor), men det er kun kosmetisk beskyttelse; springes
    // derfor eksplicit over her, uanset hvad der måtte stå i $_POST['set'].
    $cvr_locked_keys = ['company_cvr', 'company_name', 'company_address', 'company_zip', 'company_city'];

    // RETTET: låsedatoen kunne flyttes BAGUD eller slettes her - også til før et regnskabsår der er lukket ved
    // årsafslutning (year_end_close.php sætter den), så en lukket periode kunne bogføres i igen. Og ændringen
    // blev ikke logget. Den kan nu ikke komme før slutningen af det senest lukkede regnskabsår, og enhver
    // ændring skrives i revisionssporet.
    if (isset($_POST['set']['accounting_lock_date'])) {
        $ld_new = trim((string)$_POST['set']['accounting_lock_date']);
        $ld_old = (string)(get_settings($conn)['accounting_lock_date'] ?? '');
        $ld_floor_row = @DB::query($conn, "SELECT MAX(end_date) AS d FROM fiscal_years WHERE is_closed = 1");
        $ld_floor = $ld_floor_row ? (string)(DB::fetch_assoc($ld_floor_row)['d'] ?? '') : '';
        if ($ld_floor !== '' && ($ld_new === '' || $ld_new < $ld_floor)) {
            $_POST['set']['accounting_lock_date'] = $ld_old !== '' ? $ld_old : $ld_floor;
            $cs_lock_err = sprintf(lang('@The lock date cannot be earlier than the end of the last closed fiscal year (%s).'), date(CONF_DATE_FORMAT, strtotime($ld_floor)));
        } elseif ($ld_new !== $ld_old && function_exists('log_action')) {
            log_action($conn, 'CHANGE_LOCK_DATE', 'settings', 0, ['accounting_lock_date' => $ld_old], ['accounting_lock_date' => $ld_new]);
        }
    }
    foreach ($_POST['set'] as $key => $val) {
        if (in_array($key, $cvr_locked_keys, true)) continue;
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

    header("Location: company_settings.php?msg=updated" . (!empty($cs_lock_err) ? '&lock_err=1' : ''));
    exit;
}

$comp = get_settings($conn);
$msg = (isset($_GET['msg']) && $_GET['msg'] == 'updated') ? lang('@Settings updated successfully') : "";
if (!empty($_GET['lock_err'])) {
    $ld_floor_row = @DB::query($conn, "SELECT MAX(end_date) AS d FROM fiscal_years WHERE is_closed = 1");
    $ld_floor = $ld_floor_row ? (string)(DB::fetch_assoc($ld_floor_row)['d'] ?? '') : '';
    if ($ld_floor !== '') $cs_lock_err = sprintf(lang('@The lock date cannot be earlier than the end of the last closed fiscal year (%s).'), date(CONF_DATE_FORMAT, strtotime($ld_floor)));
}

htm_Header(capt: '@Settings');
showMenu();

// NYT (bruger-anmodet): samme højreklik-skjul-mønster som menuen (inc/field_
// visibility.inc.php). BEMÆRK: siden er allerede admin-only ($rLev=3), så
// at skjule et felt her for L1/L2 har ingen praktisk effekt i dag - bygget
// alligevel for konsistens, jf. brugerens eksplicitte godkendelse.
$fv_overrides = get_field_visibility_overrides($conn);
$fv_is_admin  = true;
$fv_uLev      = (int)($_SESSION['user_level'] ?? 1);

if($msg) htm_Alert(text: $msg, type: 'success');
if (!empty($cs_lock_err)) htm_Alert(text: $cs_lock_err, type: 'error');
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'logo_updated') htm_Alert(lang('@Logo updated successfully.'), 'success');
    elseif ($_GET['msg'] === 'logo_removed') htm_Alert(lang('@Logo removed.'), 'success');
    elseif ($_GET['msg'] === 'logo_bad_type') htm_Alert(lang('@Error: Please upload a PNG, JPG, WEBP or GIF image.'), 'error');
    elseif ($_GET['msg'] === 'logo_error') htm_Alert(lang('@Error: Could not save the uploaded logo.'), 'error');
    elseif ($_GET['msg'] === 'cvr_synced') htm_Alert(lang('@Company name and address fetched and locked from the official CVR register.'), 'success');
    elseif ($_GET['msg'] === 'cvr_sync_error') htm_Alert(lang('@CVR lookup failed:') . ' ' . htmlspecialchars($_GET['detail'] ?? ''), 'error');
}

// Find det aktuelle logo (uanset hvilken af de tilladte endelser det blev
// gemt med) - invoice_view.php gør nøjagtig samme tjek for at vise det.
$current_logo_path = null;
foreach ($LOGO_EXTS as $e) {
    if (file_exists(__DIR__ . '/images/logo.' . $e)) { $current_logo_path = 'images/logo.' . $e; break; }
}

htm_Card_(capt: '@Company Logo', wdth: 550);
echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' . lang('@Shown at the top of every invoice and quote. Recommended: a transparent PNG, roughly 60px tall.') . '</p>';
if ($current_logo_path) {
    echo '<div style="margin-bottom:15px;"><img src="' . htmlspecialchars($current_logo_path) . '?t=' . time() . '" style="max-height:80px; max-width:100%; border:1px solid var(--border-color); border-radius:4px; padding:8px; background:#fff;"></div>';
} else {
    echo '<div style="margin-bottom:15px; padding:12px; color:var(--text-muted); font-style:italic; border:1px dashed var(--border-color); border-radius:4px;">' . lang('@No logo uploaded yet - invoices currently show "Logo missing" instead.') . '</div>';
}
echo '<form method="POST" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
csrf_field();
echo '<input type="file" name="logo_file" accept=".png,.jpg,.jpeg,.webp,.gif" required>';
echo '<button type="submit" name="upload_logo" value="1" style="background:var(--color-primary); color:white; border:none; padding:8px 16px; border-radius:4px; cursor:pointer; font-weight:bold;"><i class="fa fa-upload"></i> ' . lang('@Upload') . '</button>';
echo '</form>';
if ($current_logo_path) {
    echo '<form method="POST" style="margin-top:8px;" onsubmit="return confirm(\'' . addslashes(lang('@Remove the current logo? Invoices will show placeholder text until you upload a new one.')) . '\');">';
    csrf_field();
    echo '<button type="submit" name="remove_logo" value="1" style="background:none; border:none; color:var(--color-danger); cursor:pointer; font-size:0.85em; padding:0;"><i class="fa fa-trash"></i> ' . lang('@Remove logo') . '</button>';
    echo '</form>';
}
htm_Card_end();

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

// RETTET (BEK 811/2026-gennemgang, Bilag 3 "låste stamoplysninger", se
// [[digital-standard-bookkeeping-registration-goal]]): firmanavn/CVR-nr/
// adresse/postnr/by må ikke længere frit kunne indtastes - de må kun
// opdateres via et officielt CVR-opslag (company_cvr_sync_action.php), som
// slår nummeret op SERVER-SIDE og skriver kun det, det officielle svar
// faktisk indeholder. Firmanavn/adresse/postnr/by er derfor readonly;
// CVR-nummerfeltet er bevidst stadig et almindeligt tekstfelt (det er selve
// opslagsnøglen), men gemmes KUN via knappen nedenfor - den almindelige
// Gem-knap ignorerer det (se skip-listen i gemme-handleren ovenfor).
htm_Field(icon: 'fa-building', labl: '@Company Name', name: 'set[company_name]', valu: $comp['company_name'] ?? '', legd:'align-left', extr: 'readonly', hint: '@Locked - can only be updated via an official CVR lookup (see the button next to the CVR Number field).');
echo "<div style='display:flex; width:100%; gap:6px; align-items:flex-end;'>";
    echo '<div style="width:34%; display:flex; gap:4px; align-items:flex-end;">';
    echo '<div style="flex:1;">';
    htm_Field(icon: 'fa-id-card', labl: '@CVR Number', name: 'set[company_cvr]', valu: $comp['company_cvr'] ?? '', extr: 'align-left', wdth: '100%', legd:'align-left', hint: '@Enter the CVR number and press the button to fetch and lock the official name/address. The ordinary Save button below does not change this field.');
    echo '</div>';
    echo '<button type="button" onclick="companyCvrLookup(this)" class="tc-info-fill" style="flex-shrink:0; margin-bottom:2px; padding:9px 10px; background:var(--color-info); color:var(--text-light); border:none; border-radius:4px; cursor:pointer;" data-hint="'.lang('@Fetch and lock company name, address, zip and city from the official CVR register').'"><i class="fa-solid fa-magnifying-glass"></i></button>';
    echo '</div>';
    htm_Field(icon: 'fa-envelope', labl: '@Email', name: 'set[company_email]', valu: $comp['company_email'] ?? '', type: 'email', wdth: '66%', legd:'align-left');
echo "</div>";
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
htm_Field(icon: 'fa-city', labl: '@City (for signing location)', name: 'set[company_city]', valu: $comp['company_city'] ?? '', wdth: '30%', legd:'align-left', extr: 'readonly', hint: '@Locked - can only be updated via an official CVR lookup.');
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): company_zip
// blev allerede læst af export_oioubl.php, men havde intet indtastningsfelt
// nogen steder i UI'et - kunne kun sættes ved direkte databaseadgang. En
// OIOUBL-e-faktura ville derfor altid have et tomt postnummer.
htm_Field(icon: 'fa-mail-bulk', labl: '@Postal Code', name: 'set[company_zip]', valu: $comp['company_zip'] ?? '', wdth: '18%', legd:'align-left', extr: 'readonly', hint: '@Locked - can only be updated via an official CVR lookup.');
htm_Field(
    icon: 'fa-map-marker-alt',
    labl: '@Address',
    name: 'set[company_address]',
    valu: $comp['company_address'] ?? '',
    type: 'textarea',
    legd: 'align-left',
    extr: 'readonly',
    hint: '@Locked - can only be updated via an official CVR lookup.'
);
htm_Field(icon: 'fa-phone', labl: '@Phone Number', name: 'set[company_phone]', valu: $comp['company_phone'] ?? '', wdth: '33%', extr:'align-right', legd:'align-center');
htm_Field(icon: 'fa-university', labl: '@Reg. No.', name: 'set[bank_reg]', valu: $comp['bank_reg'] ?? '', wdth: '34%', legd:'align-left');
htm_Field(icon: 'fa-piggy-bank', labl: '@Account No.', name: 'set[bank_acc]', valu: $comp['bank_acc'] ?? '', wdth: '33%', legd:'align-left');
// NYT (bruger-anmodet, samme fund som betalingsblokken på fakturaen): begge
// blev allerede læst af invoice_view.php's "Online"-fodfelt med falske
// fallbacks ('00000'/'www.company.com') - manglede helt et indtastningsfelt.
htm_Field(icon: 'fa-mobile-screen', labl: '@MobilePay Number', name: 'set[company_mobilepay]', valu: $comp['company_mobilepay'] ?? '', wdth: '50%', legd:'align-left');
htm_Field(icon: 'fa-globe', labl: '@Website', name: 'set[company_web]', valu: $comp['company_web'] ?? '', wdth: '50%', legd:'align-left');
htm_Field(icon: 'fa-info-circle', labl: '@Extra Info', name: 'set[company_extra]', valu: $comp['company_extra'] ?? '', wdth: '100%', legd:'align-left');
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): export_oioubl.php
// læste allerede default_payment_terms med et hardkodet dansk fallback
// ("Betaling bedes overført til vores bankkonto."), men feltet fandtes
// ingen steder i UI'et - kunne kun sættes ved direkte databaseadgang. Hver
// eneste OIOUBL-e-faktura ville derfor altid vise nøjagtig samme
// betalingstekst, uanset virksomhedens reelle betalingsbetingelser.
htm_Field(icon: 'fa-file-invoice-dollar', labl: '@Payment Terms (shown on OIOUBL e-invoices)', name: 'set[default_payment_terms]', valu: $comp['default_payment_terms'] ?? 'Betaling bedes overført til vores bankkonto.', type: 'textarea', legd: 'align-left', extr: 'rows="2"');
echo '<br><br><hr><br>';
htm_Field(icon: 'fa-comment-alt', labl: '@Default Email Message', name: 'set[default_mail_body]', valu: tc_custom_mail_body($comp), type: 'textarea', legd: 'align-left', extr: 'rows="3" placeholder="' . htmlspecialchars(lang('@Empty: a translated standard text in the language of the customer')) . '"');      // RETTET: var forudfyldt med engelsk tekst, der blev gemt og overstyrede den oversatte
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): invoice_footer
// fandtes allerede som en udfyldt værdi i settings-tabellen ("Tak fordi du
// handler i eventyrbutikken."), men INTET sted i hele kodebasen læste den
// - reelt død data, enten en rest af en fjernet funktion eller en
// påbegyndt funktion der aldrig blev færdig. Genopliver den nu som en
// rigtig "tak for handlen"-tekst nederst på selve fakturavisningen
// (invoice_view.php).
htm_Field(icon: 'fa-heart', labl: '@Invoice Footer Message (shown at the bottom of invoices)', name: 'set[invoice_footer]', valu: $comp['invoice_footer'] ?? '', type: 'textarea', legd: 'align-left', extr: 'rows="2"');

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

    // RETTET (bruger-anmodet, "kun 5 valuta-navne, kan det kombineres med
    // sprogtabellen"): var før en fastlåst, hardkodet 5-valuta-liste (kun
    // dansk tekst, aldrig kørt gennem lang()). Udleder nu valuta-listen fra
    // languages.json's 'currency'/'currency_name'-felter (samme felter
    // inc/currency_widget.php's valutaomregner og about.php's flag-visning
    // nu deler kilde med) - én valuta-linje pr. UNIKT valutakode, ikke pr.
    // sprog (flere sprog deler fx EUR), i den rækkefølge de først optræder i
    // sprogtabellen. Falder tilbage til den oprindelige 5-liste hvis
    // languages.json af en eller anden grund ikke kan læses, så siden aldrig
    // viser en tom valuta-vælger.
    $currency_options = [];
    $lang_data_cur = @json_decode(file_get_contents('json-data/languages.json'), true);
    if (!empty($lang_data_cur['language'])) {
        foreach ($lang_data_cur['language'] as $l) {
            $ccode = $l['currency'] ?? null;
            if ($ccode && !isset($currency_options[$ccode])) {
                $currency_options[$ccode] = $ccode . ' - ' . ($l['currency_name'] ?? $ccode);
            }
        }
    }
    if (empty($currency_options)) {
        $currency_options = [
            'DKK' => 'DKK - Danske Kroner',
            'EUR' => 'EUR - Euro',
            'USD' => 'USD - US Dollar',
            'SEK' => 'SEK - Svenske Kroner',
            'NOK' => 'NOK - Norske Kroner'
        ];
    }

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

    require_once __DIR__ . '/inc/accounts.inc.php';     // valglisten: slukkede konti udeladt (de valgte beholdes), dæmpede nederst
    $accounts_res = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE " . acc_visible_sql($conn, '', array_keys(acc_protected_ids($conn))) . " ORDER BY " . acc_order_sql($conn) . "acc_id ASC");
    $account_options = [];
    while ($row = DB::fetch_assoc($accounts_res)) {
        $account_options[$row['acc_id']] = $row['acc_id'] . ' - ' . acc_label($row['acc_name'], $row['acc_status'] ?? null);
    }
    // RETTET: peger en indstilling på en konto der IKKE findes i kontoplanen (fx standardværdien 5000 på en
    // kontoplan uden den), viste valglisten i stedet den FØRSTE konto - og når formularen blev gemt (for en
    // helt anden ændring, fx adressen), blev bank-/kreditorkontoen stille skiftet til den. Den gemte værdi
    // vises nu som sit eget, tydeligt markerede valg, så intet ændres uden at brugeren vælger det.
    $cs_defaults = ['conf_acc_bank' => 5000, 'conf_acc_debitor' => 8100, 'conf_acc_creditor' => 4000, 'conf_acc_sales' => 1000,
                    'conf_acc_vat' => 6900, 'conf_acc_purchase_vat' => 6910, 'conf_acc_fx' => 7200, 'conf_acc_bad_debt' => 7100];
    foreach ($cs_defaults as $cs_k => $cs_def) {
        $cs_v = (int)($comp[$cs_k] ?? $cs_def);
        if ($cs_v > 0 && !isset($account_options[$cs_v])) $account_options[$cs_v] = $cs_v . ' - ⚠ ' . lang('@not in the chart of accounts');
    }
    ksort($account_options);

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

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): bruges af
    // invoice_writeoff.php når en uerholdelig faktura afskrives som tab -
    // se db-setup/migrate_bad_debt_account.php.
    render_hideable_field($fv_overrides, 'company_settings.conf_acc_bad_debt', $fv_is_admin, $fv_uLev, function() use ($comp, $account_options) {
        htm_Field(
            icon: 'fa-file-invoice-dollar',
            labl: '@Bad Debt Loss Account',
            name: 'set[conf_acc_bad_debt]',
            valu: $comp['conf_acc_bad_debt'] ?? 7100,
            type: 'sele',
            opti: $account_options,
            wdth: 'calc(50% - 8px)',
            legd: 'align-left',
            hint: lang('@Used when an uncollectible invoice is formally written off as a bad debt loss (invoice_view.php -> "Write Off as Bad Debt").')
        );
    }, true); // has_own_box: htm_Field() tegner allerede sin egen fieldset-kant

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

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 25):
    // ingen side sporede SKATs momsfrist - vat_report.php lod allerede
    // vælge periodetype (måned/kvartal/halvår), men intet mindede om selve
    // fristen. BEVIDST ikke en automatisk udregnet SKAT-formel (den varierer
    // reelt efter virksomhedens konkrete momsperiode og kan ikke garanteres
    // korrekt herfra) - i stedet et almindeligt, bogholder-indtastet
    // datofelt, samme "sikre, ikke-gættende" mønster som PSD2-udløbs-
    // advarslen (§bookkeeper-wishlist-round23). Dashboard-advarslen
    // (index.php) opdaterer sig ikke af sig selv - forbliver bevidst "forfalden"
    // indtil bogholderen selv sætter den næste dato, efter angivelsen er indsendt.
    htm_Field(
        icon: 'fa-calendar-check',
        labl: '@Next VAT Deadline',
        name: 'set[next_vat_deadline]',
        valu: $comp['next_vat_deadline'] ?? '',
        type: 'date',
        wdth: 'calc(50% - 8px)',
        hint: '@When your next VAT return is due at SKAT. You set this manually - it is not calculated automatically. A dashboard warning appears when it is close or overdue.',
        legd: 'align-left'
    );

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 27):
    // reminder_action.php sendte tidligere PRÆCIS samme mailtekst uanset om
    // det var 1. eller 4. rykker - se dens egen kommentar for de nye,
    // optrappende skabeloner. Beløbet her BEVIDST kun nævnt i mailteksten
    // fra 2. rykker og frem, aldrig automatisk lagt oveni det opkrævede
    // beløb eller posteret - samme "sikre, ikke-gættende"-princip som
    // momsfristen ovenfor.
    htm_Field(
        icon: 'fa-triangle-exclamation',
        labl: '@Reminder Fee Amount',
        name: 'set[reminder_fee_amount]',      // (rykkerne nævner højst 100 kr og kun når loven tillader det - se reminder_action.php)
        valu: $comp['reminder_fee_amount'] ?? '',
        type: 'text',
        wdth: 'calc(50% - 8px)',
        hint: '@Optional fee mentioned in the 2nd reminder and later (e.g. 100). Not automatically added to the invoice or posted - purely informational text in the reminder email.',
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
// NYT (BEK 811/2026-gennemgang, Bilag 3 "dataportabilitet", se
// [[digital-standard-bookkeeping-registration-goal]]): modstykket til
// eksport-knappen ovenfor - lader en SAF-T-fil (egen eller et andet
// systems) blive uploadet og gjort permanent tilgængelig som en
// skrivebeskyttet oversigt, se saft_import.phps egen begrundelse.
htm_Button(
    icon: 'fa-upload',
    labl: '@Import SAF-T',
    type: 'info',
    link: 'saft_import.php',
    attr: 'data-hint="@Upload and keep a SAF-T file (your own, or from another bookkeeping system) permanently accessible as a read-only overview."'
);
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
echo '<a href="backup.php" data-hint="' . lang('@Go to Backup Management') . '" style="display:block; background:var(--bg-panel); border:1px solid var(--border-color); border-radius:8px; padding:14px 18px; text-decoration:none; color:var(--text-main); font-size:0.9em;">';
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

// RETTET (BEK 811/2026-gennemgang, Bilag 3 "låste stamoplysninger", se
// [[digital-standard-bookkeeping-registration-goal]]): slog FØR kun op og
// fyldte felterne client-side - brugeren skulle derefter selv huske at
// trykke den store Gem-knap, og der var intet der forhindrede at skrive
// noget andet end det, opslaget faktisk fandt. Sender nu i stedet en rigtig
// (skjult, dynamisk bygget - kan ikke indlejres i siden formular) POST
// direkte til company_cvr_sync_action.php, som slår nummeret op SERVER-SIDE
// og kun gemmer det, det officielle svar indeholder. Fuld sideindlæsning
// bagefter (samme mønster som logo-uploaden ovenfor), ikke en AJAX-visning.
function companyCvrLookup(btn) {
    var cvrField = document.querySelector('[name="set[company_cvr]"]');
    var cvr = (cvrField.value || '').replace(/\D/g, '');
    if (cvr.length !== 8) {
        sysAlert(<?php echo json_encode(lang('@Please enter a valid 8-digit CVR number first.')); ?>);
        return;
    }
    var icon = btn.querySelector('i');
    icon.className = 'fa-solid fa-spinner fa-spin';
    btn.disabled = true;

    var csrfToken = document.querySelector('[name="csrf_token"]').value;
    var f = document.createElement('form');
    f.method = 'post';
    f.action = 'company_cvr_sync_action.php';
    f.style.display = 'none';
    var fields = { cvr: cvr, csrf_token: csrfToken };
    for (var k in fields) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = k;
        inp.value = fields[k];
        f.appendChild(inp);
    }
    document.body.appendChild(f);
    f.submit();
}
</script>
<?php
ob_end_flush();
?>
