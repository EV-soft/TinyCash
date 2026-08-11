<?php # /backup_restore.php v:1.2.0 d:2026-08-11 i:evs 
# (Lang vejledning flyttet til hjælpesystemet, vist inline)
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

// Kvittering fra backup_restore_worker.php's redirect (?msg=success|error|engine_mismatch)
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'success') {
        htm_Alert(lang('@Backup restored successfully. Please verify your data.'), 'success');
    } elseif ($_GET['msg'] === 'engine_mismatch') {
        $src = isset($_GET['src']) ? strtoupper(preg_replace('/[^a-z]/i', '', $_GET['src'])) : '?';
        $dst = isset($_GET['dst']) ? strtoupper(preg_replace('/[^a-z]/i', '', $_GET['dst'])) : '?';
        htm_Alert(
            lang('@Restore aborted: the backup was created on a different database engine and cannot be restored here.') .
            ' (' . $src . ' &rarr; ' . $dst . '). ' .
            lang('@Switch the active database engine to match the backup, then try again. No data was changed.'),
            'error'
        );
    } elseif ($_GET['msg'] === 'error') {
        htm_Alert(lang('@Restore failed. The backup file could not be read or processed.'), 'error');
    }
}

htm_Shell_('max-width:600px; margin:20px auto;');
htm_Card_('@Restore System', 600);

$confirm = addslashes(lang('@Are you absolutely sure? This will overwrite ALL existing data and cannot be undone.'));

echo '<div style="text-align:center; padding:20px;">';
echo '<i class="fa-solid fa-triangle-exclamation" style="font-size:3.5em; color:#e67e22; margin-bottom:15px;"></i>';
echo '<p style="color:#b91c1c; font-weight:bold; line-height:1.5;">' .
        lang('@Warning: Restoring OVERWRITES all current data — database, JSON settings and uploaded files — with the contents of the backup. This cannot be undone.') .
     '</p>';
echo '<p style="color:#666; line-height:1.5;">' .
        lang('@Upload a TinyCash ZIP backup created by the "Build Full Archive" or "System Configuration" tools.') .
     '</p>';

echo '<form method="post" action="backup_restore_worker.php" enctype="multipart/form-data" ' .
     'style="margin-top:25px;" onsubmit="return confirm(\'' . $confirm . '\');">';
echo '<input type="file" name="backup_file" accept=".zip" required ' .
     'style="display:block; margin:0 auto 20px auto; max-width:100%;">';
echo '<button type="submit" ' .
     'style="background:#e67e22; color:white; padding:14px 28px; border:none; border-radius:8px; font-size:1.1em; cursor:pointer; font-weight:bold;">' .
     '<i class="fa fa-rotate-left"></i> ' . lang('@Restore from Backup') . '</button>';
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
