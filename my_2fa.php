<?php # /my_2fa.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: To-faktor-login, TOTP
# Selvbetjening - enhver logget-ind bruger administrerer sin EGEN 2FA her
# (i modsætning til user_edit.php, som er admin-only og redigerer ANDRE
# brugere). Fra forslagslisten (§Sikkerhed). Se inc/totp.lib.php for selve
# TOTP-implementationen (verificeret mod RFC 4226's officielle test-vektorer).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/totp.lib.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$msg = ''; $err = '';

$u = DB::fetch_assoc(DB::query($conn, "SELECT * FROM users WHERE user_id = $user_id"));
if (!$u) die(lang('@User not found.'));
$totp_enabled = !empty($u['totp_enabled']);

// --- 1. START OPSÆTNING: generér en ny hemmelighed (IKKE gemt i DB endnu -
// kun i sessionen, indtil brugeren har bekræftet med en rigtig kode fra sin
// app, så vi aldrig aktiverer 2FA med en hemmelighed der ikke reelt blev
// sat korrekt op). ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_setup']) && !$totp_enabled) {
    $_SESSION['pending_totp_secret'] = totp_generate_secret();
}

// --- 2. BEKRÆFT OPSÆTNING: brugeren indtaster koden fra sin app for at
// bevise at den blev sat rigtigt op, FØR 2FA reelt slås til. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_setup']) && !$totp_enabled) {
    $pending = $_SESSION['pending_totp_secret'] ?? '';
    if ($pending && totp_verify($pending, $_POST['totp_code'] ?? '')) {
        $codes = totp_generate_recovery_codes(8);
        $hashed_codes = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $codes);

        DB::query($conn, "UPDATE users SET totp_secret = '" . DB::escape($conn, $pending) . "',
                          totp_enabled = 1,
                          totp_recovery_codes = '" . DB::escape($conn, json_encode($hashed_codes)) . "'
                          WHERE user_id = $user_id");
        log_action($conn, 'ENABLE_2FA', 'users', $user_id, ['totp_enabled' => 0], ['totp_enabled' => 1]);

        unset($_SESSION['pending_totp_secret']);
        $_SESSION['show_recovery_codes_once'] = $codes; // vist ÉN gang nedenfor, så ryddet
        $totp_enabled = true;
        $u['totp_enabled'] = 1;
    } else {
        $err = lang('@Incorrect code. Please try again with the current code from your app.');
    }
}

// --- 3. SLÅ FRA: kræver nuværende adgangskode som bekræftelse - en
// kapret/efterladt session skal ikke alene kunne slå beskyttelsen fra. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_2fa']) && $totp_enabled) {
    if (password_verify($_POST['confirm_password'] ?? '', $u['password_hash'])) {
        DB::query($conn, "UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery_codes = NULL WHERE user_id = $user_id");
        log_action($conn, 'DISABLE_2FA', 'users', $user_id, ['totp_enabled' => 1], ['totp_enabled' => 0]);
        $totp_enabled = false;
        $u['totp_enabled'] = 0;
        $msg = lang('@Two-factor login has been disabled.');
    } else {
        $err = lang('@Incorrect password.');
    }
}

// --- 4. NYE GENDANNELSESKODER: de gamle er engangskoder, så en bruger der
// har brugt flere af dem kan generere et nyt sæt - kræver også adgangskode. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_codes']) && $totp_enabled) {
    if (password_verify($_POST['confirm_password'] ?? '', $u['password_hash'])) {
        $codes = totp_generate_recovery_codes(8);
        $hashed_codes = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $codes);
        DB::query($conn, "UPDATE users SET totp_recovery_codes = '" . DB::escape($conn, json_encode($hashed_codes)) . "' WHERE user_id = $user_id");
        log_action($conn, 'REGENERATE_2FA_RECOVERY_CODES', 'users', $user_id, null, null);
        $_SESSION['show_recovery_codes_once'] = $codes;
    } else {
        $err = lang('@Incorrect password.');
    }
}

htm_Header('@Two-Factor Login');
showMenu();

if ($msg) htm_Alert($msg, 'success');
if ($err) htm_Alert($err, 'error');

htm_Card_(capt: '@Two-Factor Login (2FA)', wdth: 650);

// --- Engangs-visning af gendannelseskoder (lige oprettet/regenereret) ---
if (!empty($_SESSION['show_recovery_codes_once'])) {
    $codes = $_SESSION['show_recovery_codes_once'];
    unset($_SESSION['show_recovery_codes_once']);
    echo '<div style="background:var(--bg-panel); border:2px solid var(--color-warning); border-radius:8px; padding:15px 20px; margin-bottom:20px;">';
    echo '<h4 style="margin-top:0;"><i class="fa fa-key"></i> ' . lang('@Recovery Codes - Save These Now') . '</h4>';
    echo '<p style="font-size:0.9em; color:var(--text-muted);">' . lang('@Each code can be used once, instead of your authenticator app, if you lose access to your phone. They will not be shown again - copy or print them now.') . '</p>';
    echo '<div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-family:monospace; font-size:1.1em; background:var(--bg-card); padding:12px; border-radius:6px;">';
    foreach ($codes as $c) { echo '<div>' . htmlspecialchars($c) . '</div>'; }
    echo '</div></div>';
}

if ($totp_enabled) {
    echo '<p><span style="color:var(--color-success); font-weight:bold;"><i class="fa fa-shield-alt"></i> ' . lang('@Two-factor login is ON') . '</span></p>';
    echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@A code from your authenticator app is now required every time you log in, in addition to your password.') . '</p>';

    echo '<form method="post" style="margin-top:20px; padding-top:20px; border-top:1px solid var(--border-color);">';
    csrf_field();
    htm_Field(icon: 'fa-lock', labl: '@Confirm Password', name: 'confirm_password', type: 'password', hint: '@Required to regenerate codes or disable 2FA');
    echo '<div style="display:flex; gap:10px; margin-top:10px;">';
    htm_Button(icon: 'fa-sync', labl: '@Generate New Recovery Codes', type: 'secondary', link: '', attr: 'name="regenerate_codes" data-hint="'.lang('@Invalidate the old recovery codes and generate a new set').'"');
    htm_Button(icon: 'fa-shield-alt', labl: '@Disable 2FA', type: 'danger', link: '', attr: 'name="disable_2fa" data-hint="'.lang('@Turn off two-factor login for your account').'"');
    echo '</div></form>';
} elseif (!empty($_SESSION['pending_totp_secret'])) {
    $secret = $_SESSION['pending_totp_secret'];
    $spaced = trim(chunk_split($secret, 4, ' '));
    $uri    = totp_provisioning_uri($secret, $u['username'], 'TinyCash');

    echo '<ol style="line-height:2.2;">';
    echo '<li>' . lang('@Open your authenticator app (Google Authenticator, Authy, 1Password, Bitwarden, etc.) and choose "Add account" / "Enter code manually".') . '</li>';
    echo '<li>' . lang('@Enter this secret key exactly as shown:') . '<br>';
    echo '<code style="display:inline-block; margin-top:6px; padding:10px 14px; background:var(--bg-panel); border-radius:6px; font-size:1.15em; letter-spacing:1px;">' . htmlspecialchars($spaced) . '</code></li>';
    echo '<li>' . lang('@Enter the 6-digit code your app now shows, to confirm it was set up correctly:') . '</li>';
    echo '</ol>';

    echo '<form method="post" style="display:flex; gap:10px; align-items:flex-end;">';
    csrf_field();
    htm_Field(icon: 'fa-key', labl: '@6-digit Code', name: 'totp_code', type: 'text', extr: 'maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" autofocus', wdth: '160px');
    htm_Button(icon: 'fa-check', labl: '@Confirm and Enable', type: 'success', link: '', attr: 'name="confirm_setup" data-hint="'.lang('@Verify the code and turn on two-factor login').'"');
    echo '</form>';
    echo '<p style="font-size:0.8em; color:var(--text-muted); margin-top:15px; word-break:break-all;">' . lang('@Manual entry URI (advanced):') . ' <code>' . htmlspecialchars($uri) . '</code></p>';
} else {
    echo '<p><span style="color:var(--text-muted);"><i class="fa fa-shield-alt"></i> ' . lang('@Two-factor login is OFF') . '</span></p>';
    echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@Adds a second step at login - a 6-digit code from an authenticator app on your phone, in addition to your password. Recommended for any account with admin access.') . '</p>';
    echo '<form method="post">';
    csrf_field();
    htm_Button(icon: 'fa-shield-alt', labl: '@Enable 2FA', type: 'success', link: '', attr: 'name="start_setup" data-hint="'.lang('@Start setting up two-factor login').'"');
    echo '</form>';
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
