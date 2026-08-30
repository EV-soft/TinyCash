<?php # /inc/auto_backup_integration.php v:1.3.0 d:2026-08-30 i:evs
# gendannelsesvejledningen nævner nu det indbyggede offline-dekrypteringsværktøj før openssl-kommandoen, bruger-anmodet
// ════════════════════════════════════════════════════════════════════════════
// INTEGRATION — to steder skal ændres i eksisterende filer:
// ════════════════════════════════════════════════════════════════════════════

// ── 1. htm_page.lib.php → htm_Footer() ──────────────────────────────────────
// Tilføj disse linjer EFTER include_once 'notepad.inc.php':
//
//    if (function_exists('auto_backup_check') && isset($GLOBALS['conn'])) {
//        auto_backup_check($GLOBALS['conn']);
//    }
//
// Og øverst i htm_Footer() eller i htm_Header():
//    require_once __DIR__ . '/auto_backup.inc.php';
//
// ── 2. backup.php → kald render_auto_backup_settings($conn) ─────────────────
// RETTET (§backup-manual-vs-automatic-separation, bruger-rapporteret "backup
// er ikke et klart begreb"): kaldtes FØR fra company_settings.php - flyttet
// til backup.php's egen "🤖 Automatisk Backup"-sektion, samlet med resten af
// alt der hedder backup i stedet for spredt ud på en side om firmanavn/moms/
// valuta. Se nedenfor for selve kaldet.

// ════════════════════════════════════════════════════════════════════════════
// BACKUP-SEKTION til backup.php
// Indsæt som selvstændig htm_Card_ sektion på siden
// ════════════════════════════════════════════════════════════════════════════

function render_auto_backup_settings($conn) {
    // Sikrer at _ab_save() (cross-engine settings-gem) og auto_backup_check()
    // er tilgængelige uanset include-rækkefølge fra kaldesiden.
    require_once __DIR__ . '/auto_backup.inc.php';

    $settings = [];
    $res = DB::query($conn, "SELECT setting_key, setting_value FROM settings");
    while ($s = DB::fetch_assoc($res)) { $settings[$s['setting_key']] = $s['setting_value']; }

    $backup_mail  = htmlspecialchars($settings['auto_backup_mail'] ?? '');
    $pass_in_db   = trim($settings['auto_backup_password'] ?? '') !== '';
    $pass_in_env  = _ab_master_password() !== '';
    $last_ts      = (int)($settings['auto_backup_last'] ?? 0);
    $last_error   = htmlspecialchars($settings['auto_backup_error']    ?? '');
    $days_since   = $last_ts > 0 ? floor((time() - $last_ts) / 86400) : null;

    // Håndter gem
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_auto_backup'])) {
        $new_mail = trim($_POST['auto_backup_mail'] ?? '');
        // Kun destinations-mailen gemmes i settings (ikke hemmelig). Master-
        // kodeordet håndteres udelukkende via env.ini - se blokken nedenfor.
        _ab_save($conn, 'auto_backup_mail', $new_mail);
        // Tving backup ved næste sidevisning hvis ønsket
        if (isset($_POST['auto_backup_now'])) {
            _ab_save($conn, 'auto_backup_last', 0);
        }
        htm_Alert(lang('@Auto backup settings saved'), 'success');
        $backup_mail = htmlspecialchars($new_mail);
    }
    // Ryd den gamle master-kodeord-værdi ud af databasen (efter den er sat i env.ini).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_db_password'])) {
        DB::query($conn, "DELETE FROM settings WHERE setting_key = 'auto_backup_password'");
        htm_Alert(lang('@Master password removed from the database.'), 'success');
        $pass_in_db = false;
    }

    htm_Card_('🔐 ' . lang('@Automatic Encrypted Backup'), '650');

    // Status-boks
    echo '<div style="margin-bottom:15px; padding:12px; border-radius:6px; border:1px solid var(--border-color); background:var(--bg-panel); font-size:13px;">';
    if ($last_ts > 0) {
        $status_color = ($days_since <= 7) ? 'var(--color-success)' : 'var(--color-warning)';
        echo '<div style="color:'.$status_color.'; font-weight:bold;">✓ ' . lang('@Last auto backup') . ': ' . date(CONF_DATE_FORMAT . ' H:i', $last_ts) . ' (' . $days_since . ' ' . lang('@days ago') . ')</div>';
    } else {
        echo '<div style="color:var(--text-muted); font-style:italic;">'.lang('@No automatic backup has been run yet').'</div>';
    }
    if (!empty($last_error)) {
        echo '<div style="color:var(--color-danger); margin-top:6px;"><i class="fa fa-exclamation-triangle"></i> ' . $last_error . '</div>';
    }
    if (!function_exists('openssl_encrypt')) {
        echo '<div style="color:var(--color-warning); margin-top:6px;"><i class="fa fa-lock"></i> ' . lang('@Warning: openssl not available — backups will be sent unencrypted') . '</div>';
    }
    echo '</div>';

    // ── Master-kodeord: styres i inc/data/env.ini, aldrig i databasen ───────
    // RETTET: env.ini flyttet til inc/data/env.ini (konsolidering af
    // installationsspecifikke data) - tekststrengene herunder opdateret,
    // hvilket bevidst danner NYE lang()-nøgler (se languages.json).
    echo '<div style="margin-bottom:15px; padding:12px; border-radius:6px; border:1px solid var(--border-color); background:var(--bg-panel); font-size:13px;">';
    echo '<div style="font-weight:bold; margin-bottom:6px;"><i class="fa fa-key"></i> ' . lang('@Master password (encryption)') . '</div>';
    if ($pass_in_env) {
        echo '<div style="color:var(--color-success);">✓ ' . lang('@Configured in inc/data/env.ini — never stored in the database and never emailed.') . '</div>';
    } elseif ($pass_in_db) {
        echo '<div style="color:var(--color-warning);"><i class="fa fa-exclamation-triangle"></i> ' . lang('@Your master password is still stored in the database, where it leaks into unencrypted data backups. Move it to inc/data/env.ini using the snippet below, then remove the database copy.') . '</div>';
    } else {
        echo '<div style="color:var(--text-muted);">' . lang('@Not set — backups are sent UNENCRYPTED until you add a master password to inc/data/env.ini.') . '</div>';
    }
    echo '<div style="margin-top:8px; color:var(--text-muted);">' . lang('@Add or edit this section in inc/data/env.ini and set a strong password you keep safe off-site:') . '</div>';
    echo '<pre style="margin:6px 0 0 0; padding:8px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:4px; font-size:12px; overflow-x:auto;">[backup_config]' . "\n" . 'BACKUP_PASSWORD="your-strong-password"</pre>';
    if ($pass_in_db) {
        if ($pass_in_env) {
            echo '<form method="POST" style="margin-top:8px;" onsubmit="return confirm(\'' . addslashes(lang('@Remove the master password from the database? Ensure it is set in inc/data/env.ini and saved off-site first.')) . '\');">';
            csrf_field();
            echo '<button type="submit" name="remove_db_password" value="1" style="background:var(--color-danger); color:white; border:none; padding:7px 14px; border-radius:4px; cursor:pointer; font-size:13px;"><i class="fa fa-trash"></i> ' . lang('@Remove master password from database') . '</button>';
            echo '</form>';
        } else {
            echo '<div style="margin-top:8px; font-size:12px; color:var(--color-warning);">' . lang('@Set BACKUP_PASSWORD in inc/data/env.ini first, then reload — a Remove button will appear to delete the database copy.') . '</div>';
        }
    }
    echo '</div>';

    echo '<form method="POST">';
    csrf_field();
    htm_Field(
        icon: 'fa-envelope',
        labl: '@Backup email address',
        name: 'auto_backup_mail',
        valu: $backup_mail,
        type: 'email',
        hint: '@Encrypted backup is sent to this address every 7 days when changes have been made',
        wdth: '60%'
    );
    echo '<div style="margin-top:15px; display:flex; gap:10px; align-items:center;">';
    echo '<button type="submit" name="save_auto_backup" value="1" style="background:var(--color-primary); color:white; border:none; padding:9px 20px; border-radius:4px; cursor:pointer; font-weight:bold;"><i class="fa fa-save"></i> ' . lang('@Save') . '</button>';
    echo '<label style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--text-muted); cursor:pointer;">';
    echo '<input type="checkbox" name="auto_backup_now" value="1"> ' . lang('@Trigger backup on next page load') . '</label>';
    echo '</div>';
    echo '</form>';

    // Vejledning til gendannelse - nævner nu FØRST det indbyggede offline-
    // dekrypteringsværktøj (backup_decrypt_offline.html) som den nemme metode
    // (ingen terminal nødvendig, virker uden serverforbindelse hvis filen er
    // gemt lokalt på forhånd) - samme rettelse som mail-brødteksten i
    // inc/auto_backup.inc.php fik. openssl-kommandoen bevares som alternativ.
    echo '<details style="margin-top:15px;">';
    echo '<summary style="cursor:pointer; font-size:13px; color:var(--text-muted);">'.lang('@How to restore from encrypted backup').'</summary>';
    echo '<div style="margin-top:10px; padding:10px; background:var(--bg-card); border-radius:4px; font-size:12px; border:1px solid var(--border-color);">';
    echo '<strong>'.lang('@Easiest method — no terminal needed:').'</strong><br>';
    echo sprintf(lang('@Open %s (System -> Maintenance -> Backup Management -> Offline Decryption Tool). Runs entirely in your browser — save this file locally so it also works if the server is down.'), '<a href="backup_decrypt_offline.html" target="_blank">backup_decrypt_offline.html</a>').'<br><br>';
    echo '<strong>'.lang('@Alternative method — via terminal:').'</strong><br>';
    echo '<div style="font-family:monospace; margin-top:6px;">';
    echo lang('@Step 1 — Decrypt the file (replace MASTER_PASSWORD with your own):').'<br>';
    echo 'openssl enc -d -aes-256-cbc -pbkdf2 -md sha256 -in tinycash_backup.zip.enc -out backup.zip -pass pass:MASTER_PASSWORD<br><br>';
    echo lang('@Step 2 — Extract the ZIP and replace tinycash.sqlite on the server.');
    echo '</div><br>';
    echo '<span style="color:var(--color-warning);">'.lang('@The master password is never included in the backup email — supply the one you saved yourself.').'</span>';
    echo '</div></details>';

    htm_Card_end();
}

// Kald i backup.php (§backup-manual-vs-automatic-separation):
// render_auto_backup_settings($conn);
?>
