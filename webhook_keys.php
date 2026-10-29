<?php # /webhook_keys.php v:1.3.1 d:2026-09-15 i:evs
# NY SIDE (bruger-anmodet: "webhook til automatisk fakturaoprettelse fra
# webshop/POS"): admin-side til at oprette/tilbagekalde API-nøgler til
# webhook_invoice.php (se inc/webhook.lib.php for selve nøgleregistret).
# Klarteksten for en nøgle vises KUN én gang, direkte efter oprettelse -
# bevidst INGEN redirect (Post/Redirect/Get) lige efter generering, da
# klarteksten ellers ville være tabt for evigt (kun dens hash er gemt) -
# samme "vis kun ved selve oprettelsen"-princip som ethvert andet API-nøgle-
# flow. Genindlæses siden efter dette (F5/tilbage), vises nøglen ikke igen -
# forklaret direkte i selve advarslen nedenfor.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/webhook.lib.php';

$msg = '';
$msg_type = 'success';
$newly_generated_key = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_key'])) {
    $label = trim($_POST['label'] ?? '');
    if ($label === '') {
        $msg = lang('@Please enter a label for the key (e.g. which system it is for).');
        $msg_type = 'error';
    } else {
        // account_id: null her - denne installation er single-regnskab,
        // ELLER nøglen skal give adgang til det regnskab, siden lige nu selv
        // er forbundet til. Flere-regnskabers-installationer, der ønsker en
        // nøgle pr. regnskab, opretter den fra denne side mens de er logget
        // ind PÅ det pågældende regnskab.
        $target_account_id = $_SESSION['account_id'] ?? null;
        $newly_generated_key = webhook_key_generate($label, $target_account_id);
        log_action($conn, 'CREATE_WEBHOOK_KEY', 'webhook_keys', 0, null, ['label' => $label]);
    }
}

// RETTET: listen og "fortryd" omfattede ALLE regnskabers nøgler - en administrator i ét regnskab så og kunne
// fortryde (lukke) et andet regnskabs webbutik / integration. Kun dette regnskabs nøgler (og gamle uden regnskab).
function wk_mine(array $keys): array {
    $cur = (string)($_SESSION['account_id'] ?? '');
    return array_values(array_filter($keys, fn($k) => empty($k['account_id']) || (string)$k['account_id'] === $cur));
}
if (isset($_GET['revoke'])) {
    $id = (string)$_GET['revoke'];
    $all = wk_mine(webhook_key_list());
    $target = null;
    foreach ($all as $k) { if (($k['id'] ?? null) === $id) { $target = $k; break; } }
    if ($target && webhook_key_revoke($id)) {
        log_action($conn, 'REVOKE_WEBHOOK_KEY', 'webhook_keys', 0, ['label' => $target['label'] ?? null], ['revoked' => true]);
        header('Location: webhook_keys.php?msg=revoked');
        exit;
    }
}
if (isset($_GET['msg']) && $_GET['msg'] === 'revoked') {
    $msg = lang('@Key revoked - any integration using it will stop working immediately.');
    $msg_type = 'success';
}

htm_Header(capt: '@Webhook API Keys', mwidth: 1000);
showMenu();

echo '<div style="max-width:1000px; margin:20px auto 0; padding:0 5px;">';
echo '  <h1 style="margin:0; color: var(--text-main, #2c3e50); text-align:center;"><i class="fa-solid fa-link" style="color:var(--color-primary);"></i> ' . lang('@Webhook API Keys') . '</h1>';
echo '</div>';

if ($msg) htm_Alert(text: $msg, type: $msg_type, width: 1000);

if ($newly_generated_key) {
    htm_Card_(capt: '🔑 ' . lang('@New key generated'), wdth: 800);
    echo '<p style="color:var(--color-danger); font-weight:bold;">' . lang('@Copy this key now - it will not be shown again. Reloading this page will not bring it back; you would need to revoke it and generate a new one instead.') . '</p>';
    echo '<div style="display:flex; gap:8px; align-items:center;">';
    echo '<input type="text" readonly value="' . htmlspecialchars($newly_generated_key, ENT_QUOTES) . '" onclick="this.select();" style="flex:1; font-family:monospace; padding:10px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-panel); color:var(--text-main);">';
    echo '</div>';
    htm_Card_end();
}

// --- KORT: Opret ny nøgle ---
htm_Card_(capt: '🆕 ' . lang('@Generate new key'), wdth: 800, form: 'generate_key_form', fold: true);
echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@A key lets an external system (webshop/POS) create draft invoices via webhook_invoice.php, without logging in.') . '</p>';
echo '<div style="margin-bottom:12px;"><label>' . lang('@Label') . '</label><br>
      <input type="text" name="label" required placeholder="' . htmlspecialchars(lang('@e.g. Webshop production'), ENT_QUOTES) . '" style="width:100%; padding:8px; box-sizing:border-box;"></div>';
htm_Button(icon: 'fa-key', labl: '@Generate Key', attr: 'name="generate_key" value="1"');
htm_Card_end();

// --- KORT: Eksisterende nøgler ---
htm_Card_(capt: '🗂️ ' . lang('@Existing keys'), wdth: 800, fold: true);
$keys = wk_mine(webhook_key_list());
if (empty($keys)) {
    echo '<p style="color:var(--text-muted);">' . lang('@No keys generated yet.') . '</p>';
} else {
    $head = ['@Label', '@Key (last 4)', '@Created', '@Last Used', '@Status', '@Actions'];
    $data = [];
    foreach ($keys as $k) {
        $revoked = !empty($k['revoked']);
        $status_badge = $revoked ? htm_Badge('@Revoked', 'secondary', false) : htm_Badge('@Active', 'success', false);
        $action = $revoked ? '' : htm_ConfirmLink(
            icon: 'fa-ban', labl: '@Revoke',
            link: 'webhook_keys.php?revoke=' . urlencode($k['id']),
            mess: '@Revoke this key? Any integration using it will stop working immediately - this cannot be undone.',
            type: 'danger', styl: 'padding:4px 10px; font-size:12px;', attr: 'data-hint="' . lang('@Revoke the key - integrations using it stop working (you are asked first)') . '"', echo: false
        );
        $data[] = [
            htmlspecialchars($k['label'] ?? ''),
            '<span style="font-family:monospace;">…' . htmlspecialchars($k['key_last4'] ?? '') . '</span>',
            htmlspecialchars($k['created_at'] ?? ''),
            htmlspecialchars($k['last_used_at'] ?? lang('@Never')),
            $status_badge,
            $action,
        ];
    }
    htm_Table($head, $data, 'webhookKeysTbl', 100, '', true, [], '400px');
}
htm_Card_end();

// --- KORT: Integrationsvejledning ---
$base_url = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'dit-domæne') . dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$base_url = rtrim($base_url, '/') . '/webhook_invoice.php';
htm_Card_(capt: '📖 ' . lang('@How to use it'), wdth: 800, fold: 'closed');
echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@Send a POST request with a JSON body. The key can be sent as either header - use whichever your integration platform supports.') . '</p>';

echo '<p style="font-size:0.8em; font-weight:bold; color:var(--text-muted); margin-bottom:4px;">' . htmlspecialchars('X-Webhook-Key') . '</p>';
echo '<pre style="background:var(--bg-panel); padding:12px; border-radius:6px; overflow-x:auto; font-size:0.85em; white-space:pre-wrap;">' . htmlspecialchars(
'curl -X POST "' . $base_url . '" \\
  -H "X-Webhook-Key: tcwh_..." \\
  -H "Content-Type: application/json" \\
  -d \'{
    "external_id": "SHOP-ORDER-12345",
    "customer": {"email": "kunde@example.com", "name": "Kunde Navn"},
    "lines": [{"sku": "GLASSKO-1", "quantity": 2}],
    "note": "Bestilt via webshop"
  }\''
) . '</pre>';

// NYT (bruger-anmodet): samme eksempel med det alternative
// "Authorization: Bearer <nøgle>"-header-format - se webhook_invoice.php's
// egen godkendelseslogik, som accepterer begge headers ligeværdigt.
echo '<p style="font-size:0.8em; font-weight:bold; color:var(--text-muted); margin-bottom:4px;">' . htmlspecialchars('Authorization: Bearer') . '</p>';
echo '<pre style="background:var(--bg-panel); padding:12px; border-radius:6px; overflow-x:auto; font-size:0.85em; white-space:pre-wrap;">' . htmlspecialchars(
'curl -X POST "' . $base_url . '" \\
  -H "Authorization: Bearer tcwh_..." \\
  -H "Content-Type: application/json" \\
  -d \'{
    "external_id": "SHOP-ORDER-12345",
    "customer": {"email": "kunde@example.com", "name": "Kunde Navn"},
    "lines": [{"sku": "GLASSKO-1", "quantity": 2}],
    "note": "Bestilt via webshop"
  }\''
) . '</pre>';
echo '<p style="color:var(--text-muted); font-size:0.8em;">' . lang('@Note: on some shared hosting, the Authorization header is stripped by PHP-CGI/FastCGI before PHP ever sees it. If Authorization does not work, use X-Webhook-Key instead.') . '</p>';
echo '<p style="color:var(--text-muted); font-size:0.85em;">' . lang('@Always creates a DRAFT invoice, never posts it directly - a bookkeeper must review and send/post it as usual. The product\'s SKU must already exist in the product catalog; price and VAT rate are taken from the product unless overridden in the payload. If the customer\'s email does not already exist, a new customer is created automatically.') . '</p>';
htm_Card_end();

htm_Footer();
