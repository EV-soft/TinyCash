<?php # /program_backup.php v:1.3.0 d:2026-08-30 i:evs
# (Lang vejledning flyttet til hjælpesystemet, vist inline)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/program_backup.lib.php';
require_once 'inc/help.lib.php';

if (!isset($_SESSION['user_level']) || (int)$_SESSION['user_level'] < 3) {
    die(lang('@Access denied'));
}

$msg = ""; $err = "";

if (isset($_POST['create_program_zip'])) {
    $result = program_backup_build($conn);
    if ($result) {
        $msg = lang('@Program backup created successfully!') .
            '<span style="text-align:right; width: 50%; display: inline-block;">
            <a href="' . $result['path'] . '" style="display:inline-block; background:#16a34a; color:white;
            padding:8px 15px; text-decoration:none; border-radius:4px; font-weight:bold;"
            download>💾 ' . lang('@Download archive now') . '</a></span>';
    } else {
        $err = lang('@Error: Could not create program backup (is ZipArchive enabled?).');
    }
}

htm_Header('@Program Backup');
showMenu();

if ($msg) { htm_Alert($msg, 'success'); }
if ($err) { htm_Alert($err, 'error'); }

htm_Shell_('max-width:640px; margin:20px auto;');
htm_Card_('@Program Backup', 640);

echo '<div style="text-align:center; padding:20px;">
        <i class="fa-solid fa-code" style="font-size:4em; color:#0ea5e9; margin-bottom:20px;"></i>
        <p style="color:#666; line-height:1.5;">'
        . lang('@Archive the program code and database structure before and after an update.')
      . '</p>
        <form method="post" style="margin-top:25px;">
            '.csrf_field(false).'
            <button type="submit" name="create_program_zip" style="background:#0ea5e9; color:white; padding:15px 30px; border:none; border-radius:8px; font-size:1.2em; cursor:pointer; font-weight:bold;">
                <i class="fa fa-file-zipper"></i> ' . lang('@Generate Program Backup') . '
            </button>
        </form>
      </div>';

htm_Card_end();

// ── Vejledning fra hjælpesystemet (inline, oversat) ─────────────────────────
// Hvad backuppen indeholder/udelader og hvordan den indgår i 21-dages backuppen
// bor i json-data/help_system.json (+ _da), så det oversættes ordentligt.
htm_Card_('@Program Backup Guidance', 640);
$user_lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da';
$prog_help = _help_get_content('program_backup.php', $user_lang);
if ($prog_help) {
    echo '<div style="padding:5px; color:#2c3e50; line-height:1.6; font-size:0.9em;">' . $prog_help . '</div>';
} else {
    echo '<p style="color:#7f8c8d; font-style:italic; margin:0;">' . lang('@Documentation text could not be loaded from help system resource.') . '</p>';
}
htm_Card_end();

htm_Shell_end();
htm_Footer();
ob_end_flush();
?>
