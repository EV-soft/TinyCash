<?php # /fixed_asset_edit.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Anlægsaktiver/afskrivninger (bruger-anmodet). Opret/redigér et
# anlægsaktiv. Anskaffelsen bogføres DIREKTE her ved oprettelse (DEBET
# aktivkonto, KREDIT bank) - IKKE via expense_edit.php, som kun tillader
# konti af typen 'expense' (et anlægsaktiv er en investering, ikke en udgift).
# Samme uforanderlighedsprincip som resten af posteringsflowet: er aktivet
# først bogført (voucher_no sat), kan de finansielle felter (anskaffelsessum/
# -dato, levetid, scrapværdi, konti) ikke ændres - kun beskrivende felter
# (navn, beskrivelse, projekt). Rettelser sker via afhændelse + en ny
# oprettelse, ligesom en fejlregistreret udgift håndteres.
# v1.3.1: SEVERE FIND (§bugs-batch-28-review) - Save-knappen brugte
# htm_Button()'s sædvanlige "onclick=...submit()" mønster, MEN POST-
# håndteringen nedenfor krævede desuden isset($_POST['action_save']) - et
# mønster lånt fra expense_edit.php, hvor "action_save" derimod er en RIGTIG
# submit-knaps eget name/value, altid inkluderet ved et klik. Et program-
# matisk .submit()-kald (JS'ens native HTMLFormElement.submit()) sender
# ALDRIG den klikkede knaps name/value med - kun en ægte klik-udløst
# indsendelse gør. Resultatet: et rigtigt museklik på "Gem" gjorde reelt
# INGENTING - ingen fejlmeddelelse, intet nyt aktiv, formularen nulstillede
# bare stille til tom igen. Bekræftet direkte (curl uden action_save i
# POST-kroppen gav 0 nye rækker; med action_save=1 tilføjet virkede det).
# Rettet ved at fjerne isset()-kravet - siden har kun ét formular/POST-flow,
# så tjekket var aldrig nødvendigt for at skelne mellem flere knapper.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$err = '';

$asset = [
    'asset_name' => '', 'description' => '', 'acquisition_date' => date('Y-m-d'),
    'acquisition_cost' => '', 'residual_value' => 0, 'useful_life_years' => 5,
    'asset_account_id' => 8200, 'depreciation_account_id' => 2600, 'proj_id' => null,
    'accumulated_depreciation' => 0, 'last_depreciated_date' => null, 'status' => 'active',
    'voucher_no' => null,
];
$is_posted = false;

if ($id > 0) {
    $res = DB::query($conn, "SELECT * FROM fixed_assets WHERE asset_id = $id");
    $row = $res ? DB::fetch_assoc($res) : null;
    if (!$row) { header("Location: fixed_asset_list.php"); exit; }
    $asset = $row;
    $is_posted = !empty($asset['voucher_no']);
}

$asset_accounts = [];
$ares = DB::query($conn, "SELECT acc_id, acc_name FROM accounts WHERE acc_type = 'asset' ORDER BY acc_id ASC");
if ($ares) { while ($a = DB::fetch_assoc($ares)) { $asset_accounts[$a['acc_id']] = $a['acc_id'] . ' - ' . htmlspecialchars($a['acc_name']); } }

$exp_accounts = [];
$eres = DB::query($conn, "SELECT acc_id, acc_name FROM accounts WHERE acc_type = 'expense' ORDER BY acc_id ASC");
if ($eres) { while ($a = DB::fetch_assoc($eres)) { $exp_accounts[$a['acc_id']] = $a['acc_id'] . ' - ' . htmlspecialchars($a['acc_name']); } }

$s = get_settings($conn);
$module_projects = !empty($s['module_projects']) && $s['module_projects'] == '1';
$proj_options = ['' => lang('@No project')];
if ($module_projects) {
    $pres = DB::query($conn, "SELECT proj_id, proj_no FROM projects WHERE is_active = 1 ORDER BY proj_no ASC");
    if ($pres) { while ($p = DB::fetch_assoc($pres)) { $proj_options[$p['proj_id']] = htmlspecialchars($p['proj_no']); } }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['asset_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $proj_id     = (int)($_POST['proj_id'] ?? 0);

    if ($name === '') {
        $err = lang('@Please enter a name for the asset.');
    } elseif ($is_posted) {
        // Kun beskrivende felter kan ændres på et allerede bogført aktiv -
        // samme mønster som expense_edit.php's finansielle lås.
        DB::query($conn, "UPDATE fixed_assets SET
            asset_name = '" . DB::escape($conn, $name) . "',
            description = '" . DB::escape($conn, $description) . "',
            proj_id = " . ($proj_id > 0 ? $proj_id : 'NULL') . "
            WHERE asset_id = $id");
        header("Location: fixed_asset_list.php?msg=saved"); exit;
    } else {
        $acq_date  = trim($_POST['acquisition_date'] ?? date('Y-m-d'));
        $acq_cost  = parse_dk_number($_POST['acquisition_cost'] ?? 0);
        $residual  = parse_dk_number($_POST['residual_value'] ?? 0);
        $life      = max(1, (int)($_POST['useful_life_years'] ?? 5));
        $asset_acc = (int)($_POST['asset_account_id'] ?? 0);
        $dep_acc   = (int)($_POST['depreciation_account_id'] ?? 0);

        if ($acq_cost <= 0) {
            $err = lang('@Please enter a valid acquisition cost.');
        } elseif ($residual >= $acq_cost) {
            $err = lang('@Residual value must be lower than the acquisition cost.');
        } elseif (!isset($asset_accounts[$asset_acc])) {
            $err = lang('@Please select a valid asset account.');
        } elseif (!isset($exp_accounts[$dep_acc])) {
            $err = lang('@Please select a valid depreciation account.');
        } elseif (is_date_locked($conn, $acq_date)) {
            $err = lang('@The acquisition date is in a locked accounting period and cannot be saved.');
        } else {
            DB::begin_transaction($conn);
            try {
                $voucher_no = next_voucher_no($conn);
                $proj_sql   = ($module_projects && $proj_id > 0) ? $proj_id : 'NULL';

                DB::query($conn, "INSERT INTO fixed_assets
                    (asset_name, description, acquisition_date, acquisition_cost, residual_value,
                     useful_life_years, asset_account_id, depreciation_account_id, voucher_no,
                     proj_id, created_by)
                    VALUES ('" . DB::escape($conn, $name) . "', '" . DB::escape($conn, $description) . "',
                     '" . DB::escape($conn, $acq_date) . "', $acq_cost, $residual, $life,
                     $asset_acc, $dep_acc, $voucher_no, $proj_sql, " . (int)($_SESSION['user_id'] ?? 0) . ")");
                $new_asset_id = DB::insert_id($conn);

                $acc_bank = (int)($s['conf_acc_bank'] ?? 5000);
                $jou_text = DB::escape($conn, lang('@Acquisition of fixed asset:') . ' ' . $name);
                // RETTET (§currency-setting-is-cosmetic-label): journal.currency
                // blev aldrig sat (faldt tilbage til skemaets DEFAULT 'DKK').
                $jou_currency = DB::escape($conn, $s['currency'] ?? 'DKK');
                DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, proj_id, currency)
                    VALUES ('" . DB::escape($conn, $acq_date) . "', '$jou_text', 'fixed_asset_acquisition', $voucher_no, $proj_sql, '$jou_currency')");
                $jou_id = DB::insert_id($conn);

                ledger_post($conn, $jou_id, $asset_acc, $acq_cost);   // DEBET aktivkonto (aktivet oprettes)
                ledger_post($conn, $jou_id, $acc_bank, $acq_cost * -1); // KREDIT bank (betalt med det samme)

                log_action($conn, 'CREATE_FIXED_ASSET', 'fixed_assets', $new_asset_id, null,
                    ['asset_name' => $name, 'acquisition_cost' => $acq_cost, 'voucher_no' => $voucher_no]);

                DB::commit($conn);
                header("Location: fixed_asset_list.php?msg=created"); exit;
            } catch (Exception $e) {
                DB::rollback($conn);
                $err = lang('@Error:') . ' ' . $e->getMessage();
            }
        }

        // Bevar brugerens indtastning ved en valideringsfejl, i stedet for
        // at nulstille formularen til de tomme standardværdier.
        $asset = array_merge($asset, [
            'asset_name' => $name, 'description' => $description, 'acquisition_date' => $acq_date,
            'acquisition_cost' => $acq_cost, 'residual_value' => $residual, 'useful_life_years' => $life,
            'asset_account_id' => $asset_acc ?: $asset['asset_account_id'],
            'depreciation_account_id' => $dep_acc ?: $asset['depreciation_account_id'],
            'proj_id' => $proj_id,
        ]);
    }
}

$title = $id > 0 ? lang('@Edit Fixed Asset') : lang('@Register Fixed Asset');
htm_Header($title);
showMenu();

if ($err) htm_Alert($err, 'error');

echo "<div style='max-width:650px; margin:20px auto;'>";
htm_Card_(capt: $title, wdth: 650, form: 'fa_form');

if ($is_posted) {
    htm_Banner('<i class="fa fa-lock"></i> ' . lang('@This asset is already posted. Amount, dates, life and accounts cannot be changed - only the name/description below. Use Dispose to remove it from the books instead.'), 'info');
}

htm_Field(icon: 'fa-tag', labl: '@Asset Name', name: 'asset_name', valu: $asset['asset_name'], extr: 'required leg:left', wdth: '100%');
htm_Field(icon: 'fa-align-left', labl: '@Description', name: 'description', valu: $asset['description'], type: 'textarea', extr: 'leg:left', wdth: '100%');

echo '<div style="display:flex; width:100%; gap:10px;">';
htm_Field(icon: 'fa-calendar', labl: '@Acquisition Date', name: 'acquisition_date', valu: $asset['acquisition_date'], type: 'date',
    extr: ($is_posted ? 'readonly' : 'required'), wdth: '50%');
htm_Field(icon: 'fa-coins', labl: '@Acquisition Cost', name: 'acquisition_cost',
    valu: number_format((float)$asset['acquisition_cost'], 2, ',', ''), type: 'text',
    extr: ($is_posted ? 'readonly' : 'required') . ' style="text-align:right;"', wdth: '50%');
echo '</div>';

echo '<div style="display:flex; width:100%; gap:10px;">';
htm_Field(icon: 'fa-hourglass-half', labl: '@Useful Life (years)', name: 'useful_life_years', valu: $asset['useful_life_years'], type: 'number',
    extr: ($is_posted ? 'readonly' : 'required') . ' min="1"', wdth: '50%');
htm_Field(icon: 'fa-recycle', labl: '@Residual Value', name: 'residual_value',
    valu: number_format((float)$asset['residual_value'], 2, ',', ''), type: 'text',
    extr: ($is_posted ? 'readonly' : '') . ' style="text-align:right;"', wdth: '50%',
    hint: '@Estimated scrap/resale value at the end of the useful life - not depreciated below this.');
echo '</div>';

echo '<div style="display:flex; width:100%; gap:10px;">';
if ($is_posted) {
    htm_Field(icon: 'fa-university', labl: '@Asset Account', name: 'asset_account_id_display', valu: $asset_accounts[$asset['asset_account_id']] ?? $asset['asset_account_id'], type: 'view', wdth: '50%');
    htm_Field(icon: 'fa-chart-line', labl: '@Depreciation Account', name: 'depreciation_account_id_display', valu: $exp_accounts[$asset['depreciation_account_id']] ?? $asset['depreciation_account_id'], type: 'view', wdth: '50%');
} else {
    htm_Field(icon: 'fa-university', labl: '@Asset Account', name: 'asset_account_id', valu: $asset['asset_account_id'], type: 'sele', opti: $asset_accounts, wdth: '50%',
        hint: '@The balance sheet account this asset\'s book value is posted to (acc_type = asset).');
    htm_Field(icon: 'fa-chart-line', labl: '@Depreciation Account', name: 'depreciation_account_id', valu: $asset['depreciation_account_id'], type: 'sele', opti: $exp_accounts, wdth: '50%',
        hint: '@The P&L expense account depreciation is posted to (acc_type = expense). Also used for any gain/loss on disposal.');
}
echo '</div>';

if ($module_projects) {
    htm_Field(icon: 'fa-folder-open', labl: '@Project Code', name: 'proj_id', valu: $asset['proj_id'] ?? '', type: 'sele', opti: $proj_options, wdth: '50%');
}

if ($is_posted) {
    $nbv = (float)$asset['acquisition_cost'] - (float)$asset['accumulated_depreciation'];
    echo '<div style="margin:15px 5px; padding:12px; background:var(--bg-panel); border-radius:6px; font-size:0.9em;">';
    echo '<div style="display:flex; justify-content:space-between;"><span>' . lang('@Accumulated Depreciation') . ':</span><strong>' . number_format((float)$asset['accumulated_depreciation'], 2, ',', '.') . '</strong></div>';
    echo '<div style="display:flex; justify-content:space-between; margin-top:4px;"><span>' . lang('@Net Book Value') . ':</span><strong>' . number_format($nbv, 2, ',', '.') . '</strong></div>';
    if (!empty($asset['last_depreciated_date'])) {
        echo '<div style="display:flex; justify-content:space-between; margin-top:4px; color:var(--text-muted);"><span>' . lang('@Last depreciated through') . ':</span><span>' . date(CONF_DATE_FORMAT, strtotime($asset['last_depreciated_date'])) . '</span></div>';
    }
    echo '</div>';
}

echo "<div style='display:flex; gap:10px; margin-top:20px; border-top:1px solid #eee; padding-top:20px;'>";
htm_Button(icon: 'fa-save', labl: '@Save', type: 'success', link: '', styl: 'flex:2;', attr: 'onclick="document.getElementById(\'fa_form\').submit();" name="action_save" data-hint="'.lang('@Save this asset').'"');
if ($id > 0 && $asset['status'] === 'active') {
    // NYT (§bugs-batch-26-review): "Fortryd registrering" - kun muligt mens
    // aktivet endnu ikke er afskrevet (den eneste økonomiske hændelse er
    // selve anskaffelsen) - se fixed_asset_actions.php?action=cancel.
    // "Afhænd" er for et REELT salg/kasseret aktiv, ikke en fejltastning.
    if ((float)$asset['accumulated_depreciation'] == 0) {
        htm_ConfirmLink(
            icon: 'fa-rotate-left', labl: '@Undo Registration',
            link: 'fixed_asset_actions.php?action=cancel&id='.$id,
            mess: '@Undo this asset registration? This reverses the acquisition posting completely - use this only to correct a mistake, not for a real sale or scrapping.',
            type: 'danger', styl: 'flex:1; text-align:center; padding:8px 16px;',
            attr: 'data-hint="'.lang('@Reverse the acquisition posting and remove this asset - only possible before any depreciation has been posted').'"'
        );
    }
    htm_Button(icon: 'fa-box-open', labl: '@Dispose', type: 'warning', link: 'fixed_asset_dispose.php?id='.$id, styl: 'flex:1;', attr: 'data-hint="'.lang('@Remove this asset from the books (sold or scrapped)').'"');
}
htm_Button(icon: 'fa-arrow-left', labl: '@Back', type: 'secondary', link: 'fixed_asset_list.php', styl: 'flex:1;', attr: 'data-hint="'.lang('@Return to the asset register without saving').'"');
echo "</div>";

htm_Card_end();
echo "</div>";
htm_Footer();
ob_end_flush();
?>
