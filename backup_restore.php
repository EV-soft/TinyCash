<?php # /backup_restore.php v:1.3.0 d:2026-08-30 i:evs
# (Lang vejledning flyttet til hjælpesystemet, vist inline)
# v1.3.0: viser nu besked om den automatiske sikkerhedskopi (se
# backup_restore_worker.php) + ny fejlgren (safety_backup_failed)
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/help.lib.php';

// Kun administratorer (niveau 3) må gendanne - samme gate som backup.php-hub'en
if (!isset($_SESSION['user_level']) || (int)$_SESSION['user_level'] < 3) {
    deny_access_gracefully();
}

htm_Header('@Restore System');
showMenu();

htm_Shell_('max-width:600px; margin:20px auto;');
htm_Card_('@Restore System', 600);


echo '<div style="text-align:center; padding:20px;">';
echo '<i class="fa-solid fa-triangle-exclamation" style="font-size:3.5em; color:#e67e22; margin-bottom:15px;"></i>';
echo '<p style="color:#b91c1c; font-weight:bold; line-height:1.5;">' .
        lang('@Warning: Restoring OVERWRITES all current data — database, JSON settings and uploaded files — with the contents of the backup. This cannot be undone.') .
     '</p>';
echo '<p style="color:#666; line-height:1.5;">' .
        lang('@Upload a TinyCash ZIP backup created by the "Build Full Archive" or "System Configuration" tools.') .
     '</p>';

echo '<form method="post" action="backup_restore_worker.php" enctype="multipart/form-data" ' .
     'style="margin-top:25px;" onsubmit="' . htm_confirm_js('@Are you absolutely sure? This will overwrite ALL existing data and cannot be undone.') . '">';
csrf_field();
echo '<input type="file" name="backup_file" accept=".zip" required ' .
     'style="display:block; margin:0 auto 20px auto; max-width:100%;">';
htm_Button(icon: 'fa-rotate-left', labl: '@Restore from Backup', type: 'warning',
    styl: 'padding:14px 28px; font-size:1.1em; border-radius:8px;',
    attr: 'data-hint="'.lang('@Overwrite the current database and files with this backup').'"');
echo '</form>';
echo '</div>';

htm_Card_end();

// ── Vejledning fra hjælpesystemet (inline, oversat) ─────────────────────────
// Detaljerne - hvordan data-restore virker, motor-krav og den manuelle
// gendannelse af en program-backup - bor i json-data/help_system.json (+ _da),
// så teksten oversættes ordentligt og undgår extract_translations' begrænsning.
htm_Card_('@Restore Guidance', 600);
$user_lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da';
$restore_help = _help_get_content('backup_restore.php', $user_lang);
if ($restore_help) {
    echo '<div style="padding:5px; color:#2c3e50; line-height:1.6; font-size:0.9em;">' . $restore_help . '</div>';
} else {
    echo '<p style="color:#7f8c8d; font-style:italic; margin:0;">' . lang('@Documentation text could not be loaded from help system resource.') . '</p>';
}
htm_Card_end();

htm_Shell_end();
htm_Footer();
?>
