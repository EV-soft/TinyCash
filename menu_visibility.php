<?php # /menu_visibility.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: konfigurerbar menu-synlighed pr. brugerniveau
# Bruger-anmodet: en tabel over ALLE menu-punkter, hvor man kan indstille om
# hvert punkt skal vises for hvert af de 3 brugerniveauer. Læser/skriver
# tabellen menu_visibility, som showMenu() selv slår op i (se
# inc/menu.inc.php's get_menu_visibility_overrides()/get_menu_structure()).
#
# VIGTIGT: dette er UDELUKKENDE et menu-oversigts-/UX-lag, IKKE
# adgangskontrol. At skjule et punkt her forhindrer ikke direkte URL-adgang
# til siden, hvis siden selv (auth.inc.php + $rLev) tillader det for
# brugerens niveau - se advarselsboksen nedenfor i selve UI'et.
#
# Menu-punktets nøgle (fx "invoice_edit.php?id=0") kan indeholde tegn som
# "?" og "." - lægges den direkte ind i et form-feltnavns []-syntaks,
# konverterer PHP automatisk punktummer til underscore ("kendt PHP-fælde").
# Derfor bæres nøglen i stedet som en almindelig hidden-felt-VÆRDI, indekseret
# med et rent numerisk radnummer i feltnavnet - se formularen nedenfor.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$msg = '';

// Flad liste af alle "rigtige" menu-punkter (dividere og selve Log ud
// udelades - der er intet meningsfuldt at indstille for dem).
function flatten_menu_items(array $menu, int $depth = 0): array {
    $rows = [];
    foreach ($menu as $key => $val) {
        $key = (string)$key;
        if (strpos($key, '---') === 0) continue;
        if ($key === 'logout.php') continue;
        $label = is_array($val) ? strip_tags($val['label'] ?? $key) : strip_tags((string)$val);
        $rows[] = ['key' => $key, 'label' => trim($label), 'depth' => $depth];
        if (is_array($val) && isset($val['submenu'])) {
            $rows = array_merge($rows, flatten_menu_items($val['submenu'], $depth + 1));
        }
    }
    return $rows;
}

// -----------------------------------------------------------------------
// BRUGER-ANMODET UDVIDELSE: da tabellen ovenfor eksplicit advarer om at
// menu-synlighed IKKE er adgangskontrol, er det naturlige næste spørgsmål
// "hvad ER så den reelle beskyttelse for hvert punkt?". Herunder statisk
// kildekode-scanning (ingen include/eval af filerne selv - kun tekstsøgning)
// efter de to konventioner der faktisk bruges i kodebasen:
//   1) $rLev = N; sat FØR require_once 'inc/auth.inc.php' på almindelige
//      rod-sider (se auth.inc.php's $uLev < $rLev-tjek).
//   2) $_SESSION['user_role'] !== 'admin' - en helt separat, parallel
//      konvention brugt af db-setup/-migrationsscripts (se
//      [[db-setup-remaining-review]]), som IKKE bruger $rLev overhovedet.
// Scanningen kan ikke se dynamisk/betinget logik (fx et $rLev sat inde i en
// if-sætning), så resultatet er vejledende, ikke en garanti - præcis samme
// forbehold som allerede gælder for selve menu-synligheds-tabellen.
// -----------------------------------------------------------------------
function detect_access_gate(string $path): array {
    $src = @file_get_contents($path);
    if ($src === false) {
        return ['has_auth' => false, 'gate' => null, 'level' => null];
    }
    $has_auth = (strpos($src, 'auth.inc.php') !== false);
    if (preg_match('/\$rLev\s*=\s*(\d+)\s*;/', $src, $m)) {
        return ['has_auth' => $has_auth, 'gate' => 'level', 'level' => (int)$m[1]];
    }
    if (preg_match('/user_role[\'"]?\]\s*(?:!==|===|!=|==)\s*[\'"]admin[\'"]/', $src)) {
        return ['has_auth' => $has_auth, 'gate' => 'admin_role', 'level' => null];
    }
    // RETTET (bruger-anmodet "find 5 fejl"-runde, selv-fundet): en TREDJE
    // reel spærre-konvention findes også i kodebasen - direkte
    // $_SESSION['user_level'] < N, afsluttet med enten deny_access_gracefully()
    // (backup.php/error_log.php/backup_restore.php) eller et simpelt die()
    // (full_project_backup.php/program_backup.php) - som hverken $rLev- eller
    // user_role-mønstret ovenfor fangede. Uden dette blev flere reelt
    // admin-only-sider fejlagtigt vist som kun "enhver logget ind bruger" i
    // selve denne revisionstabel - en unøjagtighed i den anden retning
    // (undervurderer beskyttelsen), men stadig en reel fejl i et værktøj hvis
    // eneste formål er præcision. Selve "<"-retningen er det der adskiller en
    // rigtig spærre fra fx about.php's ">= 3"-tjek (som kun viser EKSTRA
    // indhold til admins, ikke spærrer siden) - kræver derfor ikke at
    // die()/deny_access_gracefully() ligger i selve if-blokken, kun at et af
    // dem findes et sted i filen.
    if (preg_match('/user_level[\'"]?\]\s*<\s*(\d+)/', $src, $m)
        && preg_match('/\bdie\s*\(|\bexit\s*\(|deny_access_gracefully\s*\(/', $src)) {
        return ['has_auth' => $has_auth, 'gate' => 'level', 'level' => (int)$m[1]];
    }
    return ['has_auth' => $has_auth, 'gate' => null, 'level' => null];
}

function access_badge(array $g): string {
    if (!$g['has_auth']) {
        return '<span style="color:var(--color-danger); font-weight:bold;" data-hint="' . htmlspecialchars(lang('@No auth.inc.php require found - this file may be reachable without logging in'), ENT_QUOTES) . '">' . lang('@No login check found') . '</span>';
    }
    if ($g['gate'] === 'level') {
        return '<span style="color:var(--color-success);">' . sprintf(lang('@Level %d'), $g['level']) . '</span>';
    }
    if ($g['gate'] === 'admin_role') {
        return '<span style="color:var(--color-purple);">' . lang('@Admin only (role check)') . '</span>';
    }
    return '<span style="color:var(--color-warning);" data-hint="' . htmlspecialchars(lang('@auth.inc.php is required, but no $rLev or admin-role check was found - defaults to any logged-in user'), ENT_QUOTES) . '">' . lang('@Any logged-in user') . '</span>';
}

function dir_has_htaccess(string $path): bool {
    $dir = dirname($path);
    return file_exists(($dir === '' ? '.' : $dir) . '/.htaccess');
}

function htaccess_badge(string $path): string {
    return dir_has_htaccess($path)
        ? '<span style="color:var(--color-success);">' . lang('@Yes') . '</span>'
        : '<span style="color:var(--color-warning);">' . lang('@No') . '</span>';
}

// Scanner rod, db-setup/ og tools/ for .php-filer der IKKE optræder som en
// menu-nøgle nogen steder i den fulde (u-flade) menustruktur. Rene
// include/lib-filer (.inc.php/.lib.php/.core.php) medtages ikke - de er
// aldrig ment som selvstændige sider man kan navigere til.
function scan_files_without_menu_entry(array $known_keys): array {
    $dirs = ['' => 'root', 'db-setup/' => 'db-setup', 'tools/' => 'tools'];
    $out = [];
    foreach ($dirs as $prefix => $label) {
        $files = glob($prefix . '*.php');
        if (!$files) continue;
        sort($files);
        foreach ($files as $rel) {
            $base = basename($rel);
            if (preg_match('/\.(inc|lib|core)\.php$/', $base)) continue; // ikke en selvstændig side
            if (isset($known_keys[$base]) || isset($known_keys[$rel])) continue;
            $out[] = ['path' => $rel, 'category' => $label];
        }
    }
    return $out;
}

// --- NULSTIL TIL STANDARD ---
if (isset($_GET['reset'])) {
    DB::query($conn, "DELETE FROM menu_visibility");
    log_action($conn, 'RESET_MENU_VISIBILITY', 'menu_visibility', 0, null, null);
    header("Location: menu_visibility.php?msg=reset"); exit;
}

// --- GEM ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_visibility'])) {
    $before = get_menu_visibility_overrides($conn);
    $seen_keys = [];

    foreach ($_POST['items'] ?? [] as $row) {
        $key = trim((string)($row['key'] ?? ''));
        if ($key === '') continue;
        $seen_keys[] = $key;
        $levels = $row['levels'] ?? [];
        $l1 = in_array('1', $levels, true) ? 1 : 0;
        $l2 = in_array('2', $levels, true) ? 1 : 0;
        $l3 = in_array('3', $levels, true) ? 1 : 0;

        $key_esc = DB::escape($conn, $key);
        if (DB::is_sqlite()) {
            DB::query($conn, "INSERT INTO menu_visibility (item_key, level_1, level_2, level_3) VALUES ('$key_esc', $l1, $l2, $l3)
                               ON CONFLICT(item_key) DO UPDATE SET level_1=$l1, level_2=$l2, level_3=$l3");
        } else {
            DB::query($conn, "INSERT INTO menu_visibility (item_key, level_1, level_2, level_3) VALUES ('$key_esc', $l1, $l2, $l3)
                               ON DUPLICATE KEY UPDATE level_1=$l1, level_2=$l2, level_3=$l3");
        }
    }

    log_action($conn, 'UPDATE_MENU_VISIBILITY', 'menu_visibility', 0, null, ['items_changed' => count($seen_keys)]);
    header("Location: menu_visibility.php?msg=saved"); exit;
}

htm_Header('@Menu Visibility', 1000);
showMenu();

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'saved') htm_Alert(lang('@Menu visibility saved.'), 'success');
    elseif ($_GET['msg'] === 'reset') htm_Alert(lang('@All menu visibility settings reset to defaults.'), 'success');
}

htm_Card_(capt: '@Menu Visibility per User Level', wdth: 1000, fold: true); // TEST: fold-toggle

htm_Banner('<i class="fa fa-exclamation-triangle"></i> ' . lang('@This only controls what appears in the menu, not who can actually open the page. A hidden item can usually still be reached by typing its address directly, unless the page itself also restricts access for that level. Use this to declutter the menu for less experienced users, not as a security measure.'), 'warning');

$menu_structure = get_menu_structure($conn);
$items          = flatten_menu_items($menu_structure);
$overrides      = get_menu_visibility_overrides($conn);

echo '<form method="post">';
csrf_field();
echo '<div style="overflow-x:auto;">';
echo '<table style="width:100%; border-collapse:collapse;" id="menuVisTbl">';
echo '<thead><tr style="border-bottom:2px solid var(--border-color);">';
echo '<th style="text-align:left; padding:8px;">' . lang('@Menu Item') . '</th>';
// RETTET (bruger-anmodet terminologiskift): "Level 1/2/3" + "Beginner/
// Experienced/Developer" beskrev niveauerne som en brugers ERFARING, hvilket
// ikke matchede hvad denne side reelt styrer (hvor MEGET af menuen der vises,
// ikke brugerens dygtighed). Ny terminologi beskriver visningens OMFANG i
// stedet, med samme niveau-tal bevaret som L1/L2/L3 andre steder i appen
// (fx toggleTestLevel()-knappen).
echo '<th style="padding:8px; text-align:center; width:110px;">' . lang('@Minimal View') . '<br><small style="font-weight:normal; color:var(--text-muted);">' . lang('@For everyday use') . '</small></th>';
echo '<th style="padding:8px; text-align:center; width:110px;">' . lang('@Custom View') . '<br><small style="font-weight:normal; color:var(--text-muted);">' . lang("@The company's current needs") . '</small></th>';
echo '<th style="padding:8px; text-align:center; width:110px;">' . lang('@Maximum View') . '<br><small style="font-weight:normal; color:var(--text-muted);">' . lang("@Administrator's needs") . '</small></th>';
// NY KOLONNE (bruger-anmodet): den faktiske adgangsspærre for siden bag
// hvert menu-punkt, se detect_access_gate()/access_badge() ovenfor.
echo '<th style="padding:8px; text-align:left; width:170px;">' . lang('@Actual Access Restriction') . '</th>';
echo '</tr></thead><tbody>';

// Nøgler kendes både med og uden foranstillet "/" og forespørgselsstreng,
// og bruges også af scan_files_without_menu_entry() nedenfor til at afgøre
// hvilke filer der IKKE har noget menu-punkt overhovedet.
$known_keys = [];

foreach ($items as $i => $item) {
    $key    = $item['key'];
    $vis    = $overrides[$key] ?? get_menu_visibility_defaults($key);
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $item['depth']);
    $prefix = $item['depth'] > 0 ? '↳ ' : '';
    $rowBg  = $item['depth'] === 0 ? ' style="background:var(--bg-panel); font-weight:600;"' : '';

    $file_only              = preg_replace('/[?#].*$/', '', $key);
    $known_keys[$file_only] = true;
    $gate                   = (substr($file_only, -4) === '.php' && is_file($file_only)) ? detect_access_gate($file_only) : null;

    echo '<tr' . $rowBg . '>';
    // Skjult felt lagt INDE i første <td> (ikke løst mellem <tr>/<td>) -
    // browserens HTML-parser flytter ellers et <input> der er direkte barn
    // af <tr> uden for hele tabellen (gyldig men overraskende HTML5-parsing-
    // regel), hvilket kunne rode layoutet til uden nødvendigvis at ødelægge
    // selve indsendelsen, men er unødigt skrøbeligt.
    echo '<td style="padding:6px 8px; border-bottom:1px solid var(--border-subtle);">'
        . '<input type="hidden" name="items[' . $i . '][key]" value="' . htmlspecialchars($key, ENT_QUOTES) . '">'
        . $indent . $prefix . htmlspecialchars($item['label'])
        . ' <small style="color:var(--text-muted); font-family:monospace; font-weight:normal;">(' . htmlspecialchars($key) . ')</small></td>';
    foreach ([1, 2, 3] as $lvl) {
        $checked = !empty($vis[$lvl]) ? 'checked' : '';
        echo '<td style="padding:6px 8px; text-align:center; border-bottom:1px solid var(--border-subtle);">'
            . '<input type="checkbox" name="items[' . $i . '][levels][]" value="' . $lvl . '" ' . $checked . ' style="width:18px; height:18px; cursor:pointer;">'
            . '</td>';
    }
    echo '<td style="padding:6px 8px; border-bottom:1px solid var(--border-subtle); font-size:0.9em;">'
        . ($gate !== null ? access_badge($gate) : '<span style="color:var(--text-muted);">–</span>')
        . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

echo '<div style="margin-top:20px; display:flex; gap:10px;">';
htm_Button(icon: 'fa-save', labl: '@Save', type: 'success', link: '', attr: 'name="save_visibility" data-hint="'.lang('@Save the visibility settings above').'"');
echo '</div>';
echo '</form>';

htm_ConfirmLink(
    icon: 'fa-undo', labl: '@Reset All to Defaults', link: 'menu_visibility.php?reset=1',
    mess: '@Reset ALL menu visibility settings back to the built-in defaults? This cannot be undone.',
    type: 'secondary', styl: 'margin-top:10px; display:inline-block;',
    attr: 'data-hint="'.lang('@Discard all custom visibility settings and restore the defaults').'"'
);

htm_Card_end();

// -------------------------------------------------------------------------
// NY SEKTION (bruger-anmodet): sider der slet ikke har noget menu-punkt kan
// heller ikke ses/vurderes i tabellen ovenfor - de er "usynlige" for dette
// værktøj indtil nu. Lister alle .php-filer i rod, db-setup/ og tools/ der
// ikke matcher nogen kendt menu-nøgle, sammen med samme adgangs-badge som
// ovenfor og hvorvidt filens mappe har en .htaccess (webserver-niveau
// blokering, som er den sidste linje af forsvar hvis en fil hverken har en
// menu-genvej eller en indbygget adgangsspærre).
// -------------------------------------------------------------------------
// Sammenklappet som standard (bruger-anmodet) - listen er lang (94 filer på
// dev-installationen), og de fleste besøg på siden drejer sig om selve
// menu-synligheds-tabellen ovenfor, ikke denne revisionsliste. Brugte
// tidligere et håndrullet <details>/<summary>-mønster; erstattet med
// htm_Card_()'s egen fold:'closed' (se inc/php2htm.lib.php).
htm_Card_(capt: '@Pages Without a Menu Entry', wdth: 1000, fold: 'closed');

$no_menu_files = scan_files_without_menu_entry($known_keys);

htm_Banner('<i class="fa fa-info-circle"></i> ' . lang('@These .php files exist in the root, db-setup/ or tools/ folders but are not linked from any menu, so they cannot be reviewed in the table above. Some are legitimate (a POST handler, an admin script meant to be run once) - use the access/lock columns to judge which ones deserve a closer look.'), 'info');

echo '<div style="margin-bottom:10px; display:flex; gap:8px; align-items:center;">'
    . '<input type="text" id="noMenuTbl_search" onkeyup="filterTable(\'noMenuTbl\')" placeholder="' . htmlspecialchars(lang('@Search...'), ENT_QUOTES) . '" style="padding:6px 10px; width:250px;">'
    . '<button type="button" onclick="clearSearch(\'noMenuTbl\')" style="padding:6px 12px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-panel); cursor:pointer;">' . lang('@Clear') . '</button>'
    . '<span style="color:var(--text-muted); font-size:0.9em;">' . sprintf(lang('@%d files found'), count($no_menu_files)) . '</span>'
    . '</div>';

echo '<div style="overflow-x:auto;">';
echo '<table style="width:100%; border-collapse:collapse;" id="noMenuTbl">';
echo '<thead><tr style="border-bottom:2px solid var(--border-color);">';
echo '<th style="text-align:left; padding:8px;">' . lang('@File') . '</th>';
echo '<th style="text-align:left; padding:8px; width:110px;">' . lang('@Folder') . '</th>';
echo '<th style="text-align:left; padding:8px; width:170px;">' . lang('@Actual Access Restriction') . '</th>';
echo '<th style="text-align:center; padding:8px; width:130px;">' . lang('@Folder Has .htaccess') . '</th>';
echo '</tr></thead><tbody>';

foreach ($no_menu_files as $f) {
    $gate = detect_access_gate($f['path']);
    echo '<tr>';
    echo '<td style="padding:6px 8px; border-bottom:1px solid var(--border-subtle); font-family:monospace;">' . htmlspecialchars($f['path']) . '</td>';
    echo '<td style="padding:6px 8px; border-bottom:1px solid var(--border-subtle);">' . htmlspecialchars($f['category']) . '</td>';
    echo '<td style="padding:6px 8px; border-bottom:1px solid var(--border-subtle); font-size:0.9em;">' . access_badge($gate) . '</td>';
    echo '<td style="padding:6px 8px; border-bottom:1px solid var(--border-subtle); text-align:center;">' . htaccess_badge($f['path']) . '</td>';
    echo '</tr>';
}
if (!$no_menu_files) {
    echo '<tr><td colspan="4" style="padding:12px; text-align:center; color:var(--text-muted);">' . lang('@None found.') . '</td></tr>';
}
echo '</tbody></table></div>';

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
