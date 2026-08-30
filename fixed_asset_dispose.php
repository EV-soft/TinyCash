<?php # /fixed_asset_dispose.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: Anlægsaktiver/afskrivninger - afhændelse (solgt eller
# kasseret). Fjerner aktivets resterende bogførte værdi (netto) fra
# aktivkontoen og bogfører en eventuel gevinst/tab på afskrivningskontoen:
#   KREDIT aktivkonto        = − bogført værdi (fjerner resten fra bøgerne)
#   DEBET  bank               = + salgsprovenu (0 hvis kasseret)
#   DEBET/KREDIT afskrivningskonto = differencen (tab hvis solgt under bogført
#   værdi, gevinst hvis solgt over - bevidst bogført på samme konto som den
#   løbende afskrivning, for ikke at kræve endnu en kontovælger for et
#   sjældent forekommende beløb, jf. den flade kontoplan-filosofi ellers brugt
#   i projektet, se inc/annual_report.lib.php's egen kommentar herom).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: fixed_asset_list.php"); exit; }

$res   = DB::query($conn, "SELECT * FROM fixed_assets WHERE asset_id = $id");
$asset = $res ? DB::fetch_assoc($res) : null;
if (!$asset) { header("Location: fixed_asset_list.php"); exit; }
if ($asset['status'] !== 'active') { header("Location: fixed_asset_list.php"); exit; }

$nbv = round((float)$asset['acquisition_cost'] - (float)$asset['accumulated_depreciation'], 2);
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_dispose'])) {
    $dispose_date = trim($_POST['disposed_date'] ?? date('Y-m-d'));
    $proceeds     = parse_dk_number($_POST['disposal_proceeds'] ?? 0);

    if (is_date_locked($conn, $dispose_date)) {
        $err = lang('@The disposal date is in a locked accounting period.');
    } else {
        DB::begin_transaction($conn);
        try {
            // Atomisk claim - samme mønster som resten af sessionens
            // kapløbsrettelser: kun ét afhændelsesforsøg må reelt gennemføres.
            $claim = DB::prepare_and_execute($conn,
                "UPDATE fixed_assets SET status = 'disposed', disposed_date = ?, disposal_proceeds = ? WHERE asset_id = ? AND status = 'active'",
                [$dispose_date, $proceeds, $id]);
            if (!$claim || DB::affected_rows($conn, $claim) < 1) {
                throw new Exception(lang('@This asset was already disposed of by another, simultaneous request.'));
            }

            $gain_loss  = round($proceeds - $nbv, 2);
            $acc_bank   = (int)(get_settings($conn)['conf_acc_bank'] ?? 5000);
            $voucher_no = next_voucher_no($conn);
            $jou_text   = DB::escape($conn, lang('@Disposal of fixed asset:') . ' ' . $asset['asset_name']);
            // RETTET (§currency-setting-is-cosmetic-label): journal.currency
            // blev aldrig sat (faldt tilbage til skemaets DEFAULT 'DKK').
            $jou_currency = DB::escape($conn, $global_settings['currency'] ?? 'DKK');

            DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency)
                VALUES ('" . DB::escape($conn, $dispose_date) . "', '$jou_text', 'fixed_asset_disposal', $voucher_no, '$jou_currency')");
            $jou_id = DB::insert_id($conn);

            if ($nbv != 0)      ledger_post($conn, $jou_id, (int)$asset['asset_account_id'], $nbv * -1);
            if ($proceeds != 0) ledger_post($conn, $jou_id, $acc_bank, $proceeds);
            if ($gain_loss != 0) ledger_post($conn, $jou_id, (int)$asset['depreciation_account_id'], $gain_loss * -1);

            log_action($conn, 'DISPOSE_FIXED_ASSET', 'fixed_assets', $id,
                ['status' => 'active', 'net_book_value' => $nbv],
                ['status' => 'disposed', 'proceeds' => $proceeds, 'gain_loss' => $gain_loss, 'disposal_voucher_no' => $voucher_no]);

            DB::commit($conn);
            header("Location: fixed_asset_list.php?msg=disposed"); exit;
        } catch (Exception $e) {
            DB::rollback($conn);
            $err = lang('@Error:') . ' ' . $e->getMessage();
        }
    }
}

htm_Header(lang('@Dispose Fixed Asset'));
showMenu();

if ($err) htm_Alert($err, 'error');

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

echo "<div style='max-width:600px; margin:20px auto;'>";
htm_Card_(capt: '@Dispose Fixed Asset', wdth: 600);

$notice = '<strong><i class="fa fa-info-circle"></i> ' . lang('@What happens when you dispose of this asset:') . '</strong><ul style="margin:8px 0 0 0; padding-left:20px; line-height:1.8;">'
    . '<li>' . sprintf(lang('@The remaining net book value (%s) is removed from the asset account'), number_format($nbv, 2, ',', '.') . ' ' . $cur) . '</li>'
    . '<li>' . lang('@Any sale proceeds are posted to the bank account') . '</li>'
    . '<li>' . lang('@Any gain or loss versus the net book value is posted to the depreciation account') . '</li>'
    . '<li>' . lang('@No further depreciation can be posted for this asset afterwards') . '</li>'
    . '</ul>';
htm_Banner($notice, 'warning');

echo '<div style="margin-bottom:15px; padding:12px; background:var(--bg-panel); border-radius:6px;">';
echo '<strong>' . htmlspecialchars($asset['asset_name']) . '</strong><br>';
echo '<span style="font-size:0.9em; color:var(--text-muted);">' . lang('@Net Book Value') . ': ' . number_format($nbv, 2, ',', '.') . ' ' . $cur . '</span>';
echo '</div>';

echo '<form method="post">';
csrf_field();
htm_Field(icon: 'fa-calendar', labl: '@Disposal Date', name: 'disposed_date', valu: date('Y-m-d'), type: 'date', extr: 'required leg:left', wdth: '50%');
htm_Field(icon: 'fa-money-bill-wave', labl: '@Sale Proceeds', name: 'disposal_proceeds', valu: '0,00', type: 'text',
    extr: 'leg:left style="text-align:right;"', wdth: '50%', hint: '@Enter 0 if the asset was scrapped with no sale value.');

echo '<div style="margin-top:20px; display:flex; gap:10px; border-top:1px solid var(--border-color); padding-top:20px;">';
echo '<button type="submit" name="confirm_dispose" value="1" style="flex:2; background:var(--color-danger); color:white; border:none; padding:12px; border-radius:4px; font-weight:bold; cursor:pointer;">'
   . '<i class="fa fa-box-open"></i> ' . lang('@Confirm Disposal') . '</button>';
echo '<a href="fixed_asset_edit.php?id=' . $id . '" style="flex:1; background:var(--bg-panel); color:var(--text-main); border:1px solid var(--border-color); padding:12px; border-radius:4px; font-weight:bold; text-align:center; text-decoration:none; display:flex; align-items:center; justify-content:center;">' . lang('@Cancel') . '</a>';
echo '</div>';
echo '</form>';

htm_Card_end();
echo "</div>";
htm_Footer();
ob_end_flush();
?>
