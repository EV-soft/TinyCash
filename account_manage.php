<?php # /account_manage.php v:1.3.0 d:2026-08-30 i:evs
# NY SIDE (flere-regnskaber-funktionen, Fase 2): admin-side til at oprette
# nye demo-regnskaber, konvertere et demo-regnskab til et aktivt, og nedlægge
# (blidt deaktivere) et regnskab - se plan-filen for det fulde design.
#
# Selve database-arbejdet (skema+demo-data ved oprettelse, DELETE-oprydning
# + tæller-nulstilling ved konvertering) foregår ALDRIG her direkte, men
# håndteres af db-setup/provision_account.php på en isoleret forbindelse til
# mål-regnskabet (se dens header for hvorfor). "Nedlæg"/"Genaktiver" rører
# ingen database overhovedet - de er en ren accounts.json-flag-ændring - og
# håndteres derfor direkte her.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

// --- Selvhelbredende registrering af DENNE forbindelses egen database ---
// GAP fundet af bruger ("hvordan tilgår man de gamle regnskaber når der er
// oprettet 2 nye"): login.php's vælger viser KUN registrerede regnskaber -
// den oprindelige database (env.ini/ACTIVE_DB-fallbacken, brugt når intet
// account_id er valgt) bliver ALDRIG automatisk tilføjet til accounts.json
// bare fordi andre regnskaber oprettes. Så snart 2+ regnskaber er
// registreret, forsvinder den oprindelige database derfor stille fra
// vælgeren, selvom dens data stadig ligger urørt - kun adgangsVEJEN til den
// via login-siden er væk.
//
// Løsning: hver gang denne side indlæses, tjekkes om den database vi
// FAKTISK er forbundet til lige nu allerede har en post i registret (uanset
// om den matcher på sti/db-navn) - findes ingen, tilføjes den automatisk.
// Idempotent og retroaktivt selvhelbredende: virker både for en helt tom
// accounts.json (0 -> 1) OG for et allerede ikke-tomt register, hvor den
// oprindelige database blot aldrig kom med (præcis brugerens situation).
//
// Delt med "Registrer en eksisterende database"-formularen nedenfor
// (account_find_matching_account()), som bruger nøjagtig samme sti-/navn-
// sammenligning til at afvise dobbelt-registrering af samme fysiske
// database under to forskellige id'er/navne (fundet ved samme sweep som
// nedenstående last-active-værn - ville ellers vise 2 "regnskaber" i
// vælgeren, der reelt er den samme database).
function account_find_matching_account(string $db_type, ?string $path_or_name): ?array {
    foreach (account_list(true) as $existing) {
        if (($existing['engine'] ?? '') !== $db_type) continue;
        if ($db_type === 'sqlite' && ($existing['db_path'] ?? '') === $path_or_name) return $existing;
        if ($db_type === 'mysql'  && ($existing['db_name'] ?? '') === $path_or_name) return $existing;
    }
    return null;
}

function account_ensure_current_connection_registered($conn, string $db_type, array $db_settings): void {
    $target_path = ($db_type === 'sqlite') ? ($db_settings['DB_PATH'] ?? 'data/tinycash.sqlite') : null;
    $target_name = ($db_type === 'mysql')  ? ($db_settings['DB_NAME'] ?? '') : null;

    if (account_find_matching_account($db_type, $db_type === 'sqlite' ? $target_path : $target_name)) return;

    $company_settings = get_settings($conn);
    $name = trim($company_settings['company_name'] ?? '') ?: lang('@Original ledger');
    $entry = [
        'id'      => account_generate_id($name),
        'name'    => $name,
        'engine'  => $db_type,
        'is_demo' => false,
        'active'  => true,
    ];
    if ($db_type === 'sqlite') {
        $entry['db_path'] = $target_path;
    } else {
        $entry['db_name'] = $target_name;
    }
    account_save($entry);
}
account_ensure_current_connection_registered($conn, $db_type, $db_settings ?? []);

// --- Foreslå gamle/glemte databaser til "Registrer en eksisterende
// database" (bruger-anmodet: "kan den ikke selv foreslå gamle
// placeringer") ---
// SQLite: gennemsøger inc/data/ og inc/data/accounts/ for .sqlite-filer,
// der ikke allerede har en post i registret - matcher lige præcis den
// situation der udløste hele "usynligt regnskab"-gappet: inc/data/ har
// længe rummet manuelt kopierede .sqlite-varianter fra tidligere sessioner
// (.bak_*, -adv, -live osv., se installation-data-consolidation i
// hukommelsen), som ingen af de eksisterende handlinger nogensinde kiggede
// efter. For hver kandidat forsøges company_name læst ud (rent læse-kig,
// ingen skrivning) til et bedre navneforslag end selve filnavnet - fejler
// det (ikke en gyldig/opsat TinyCash-database), falder forslaget bare
// tilbage til filnavnet.
// RETTET (§find-fem-fejl, flere-regnskaber-sweep): scanningerne herunder
// viste FØR ethvert fund uden at tjekke om det overhovedet lignede en
// TinyCash-database - for SQLite enhver .sqlite-fil i inc/data(/accounts),
// for MySQL enhver database på den delte server (kun systemdatabaser
// udelukket, se account_scan_mysql_candidates()). På en delt MySQL-server
// med andre applikationers databaser (eller en tilfældig fremmed .sqlite-
// fil kopieret ind i inc/data/ af en anden grund) ville disse fremmede
// databaser optræde som et-klik-"Registrer"-kandidater - et uheldigt klik
// ville binde et regnskab til en helt urelateret database, som TinyCash
// derefter ville forsøge at læse/skrive users/settings/invoices-tabeller i.
// Kræver nu mindst users- OG settings-tabellerne til stede, før noget
// overhovedet vises som en kandidat.
function _account_looks_like_tinycash_sqlite(string $abs_path): bool {
    try {
        $peek = new PDO('sqlite:' . $abs_path);
        $peek->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $peek->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        return in_array('users', $tables, true) && in_array('settings', $tables, true);
    } catch (\Throwable $e) {
        return false;
    }
}

function account_scan_sqlite_candidates(): array {
    $candidates = [];
    $dirs = ['inc/data', 'inc/data/accounts'];
    foreach ($dirs as $dir) {
        foreach (glob($dir . '/*.sqlite') ?: [] as $abs_path) {
            // db_path-konventionen (account_resolve_settings()) er relativ
            // til inc/, ikke til projektroden - "inc/data/x.sqlite" bliver
            // altså til "data/x.sqlite".
            $rel_to_inc = substr($abs_path, strlen('inc/'));
            if (account_find_matching_account('sqlite', $rel_to_inc)) continue;
            if (!_account_looks_like_tinycash_sqlite($abs_path)) continue;

            $suggested_name = basename($abs_path, '.sqlite');
            try {
                $peek = new PDO('sqlite:' . $abs_path);
                $peek->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $row = $peek->query("SELECT setting_value FROM settings WHERE setting_key = 'company_name'")->fetch();
                if (!empty($row[0])) $suggested_name = $row[0];
            } catch (\Throwable $e) {
                // Ikke en læsbar/opsat TinyCash-database - vis kandidaten
                // alligevel, blot med filnavnet som eneste forslag.
            }
            $candidates[] = ['engine' => 'sqlite', 'location' => $rel_to_inc, 'suggested_name' => $suggested_name];
        }
    }
    return $candidates;
}

// MySQL: lister databaser på den ALLEREDE konfigurerede server (samme
// [mysql_config]-legitimationsoplysninger som resten af appen bruger, aldrig
// nye) via SHOW DATABASES, minus systemdatabaser og allerede registrerede.
// Springes helt over hvis intet MySQL-brugernavn er sat op i env.ini -
// ingen grund til at forsøge en anonym forbindelse mod en tilfældig lokal
// MySQL-server.
function account_scan_mysql_candidates(array $mysql_config): array {
    if (empty($mysql_config['DB_USER'])) return [];
    $candidates = [];
    // "@" undertrykker kun advarsler, ikke undtagelser - siden PHP 8.1 er
    // mysqli's standardtilstand at KASTE en mysqli_sql_exception ved en
    // mislykket forbindelse (samme fund som førte til rettelsen i
    // inc/db_connect.inc.php's eget MySQL-forbindelsesspor). Denne funktion
    // skal blot vise 0 forslag, ikke vælte hele siden, hvis MySQL-serveren
    // ikke kan nås lige nu.
    try {
        $probe = @mysqli_connect($mysql_config['DB_HOST'] ?? 'localhost', $mysql_config['DB_USER'], $mysql_config['DB_PASS'] ?? '');
    } catch (\Throwable $e) {
        return [];
    }
    if (!$probe) return [];
    $system_dbs = ['information_schema', 'mysql', 'performance_schema', 'sys'];
    try {
        $res = mysqli_query($probe, 'SHOW DATABASES');
    } catch (\Throwable $e) {
        mysqli_close($probe);
        return [];
    }
    if ($res) {
        while ($row = mysqli_fetch_row($res)) {
            $db_name = $row[0];
            if (in_array($db_name, $system_dbs, true)) continue;
            if (account_find_matching_account('mysql', $db_name)) continue;
            if (!_account_looks_like_tinycash_mysql($probe, $db_name)) continue;
            $candidates[] = ['engine' => 'mysql', 'location' => $db_name, 'suggested_name' => $db_name];
        }
    }
    mysqli_close($probe);
    return $candidates;
}

// Samme "ligner det TinyCash?"-tjek som SQLite-siden ovenfor, se den
// funktions kommentar. Forespørger information_schema i stedet for at
// skifte den aktive database på $probe, og fejler stille (viser kandidaten
// ikke) hvis selve tjekket støder på noget uventet - aldrig fatalt.
function _account_looks_like_tinycash_mysql($probe, string $db_name): bool {
    try {
        $safe = mysqli_real_escape_string($probe, $db_name);
        $res  = mysqli_query($probe, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$safe' AND TABLE_NAME IN ('users','settings')");
    } catch (\Throwable $e) {
        return false;
    }
    if (!$res) return false;
    $found = [];
    while ($row = mysqli_fetch_row($res)) { $found[] = $row[0]; }
    return in_array('users', $found, true) && in_array('settings', $found, true);
}

$msg = '';
$msg_type = 'success';
$just_created_id = null;

// --- POST: registrer et nyt demo-regnskab. Selve skema-/demo-data-
// opbygningen sker IKKE her, men på næste sides side-effekt (en auto-
// afsendt formular til provision_account.php) - se htm_Card_ nedenfor.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_demo'])) {
    $name       = trim($_POST['name'] ?? '');
    $engine     = (($_POST['engine'] ?? 'sqlite') === 'mysql') ? 'mysql' : 'sqlite';
    $db_name_in = trim($_POST['db_name'] ?? '');

    if ($name === '') {
        $msg = lang('@Please enter a name for the ledger.'); $msg_type = 'error';
    } elseif ($engine === 'mysql' && $db_name_in === '') {
        $msg = lang('@Please enter the name of an already-created, empty MySQL database.'); $msg_type = 'error';
    } else {
        $new_id = account_generate_id($name);
        $entry = [
            'id'      => $new_id,
            'name'    => $name,
            'engine'  => $engine,
            'is_demo' => true,
            'active'  => true,
        ];
        if ($engine === 'sqlite') {
            $entry['db_path'] = 'data/accounts/' . $new_id . '.sqlite';
        } else {
            $entry['db_name'] = $db_name_in;
        }
        if (account_save($entry)) {
            log_action($conn, 'CREATE_ACCOUNT', 'accounts', 0, null, $entry);
            $just_created_id = $new_id;
        } else {
            $msg = lang('@Could not save the new ledger registration.'); $msg_type = 'error';
        }
    }
}

// --- POST: registrer en ALLEREDE eksisterende database (fx den
// oprindelige, fra FØR flere-regnskaber-funktionen fandtes) - ingen
// skema-/data-opbygning, kun en ren registrering. Den generelle løsning på
// gappet ovenfor: uanset hvordan et regnskab er blevet "usynligt" i login-
// vælgeren, kan det altid bringes tilbage herfra, blot man kender dets
// sti/database-navn - fuldstændig uafhængigt af hvilket regnskab man selv
// er logget ind på lige nu.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_existing'])) {
    $ex_name   = trim($_POST['ex_name'] ?? '');
    $ex_engine = (($_POST['ex_engine'] ?? 'sqlite') === 'mysql') ? 'mysql' : 'sqlite';
    $ex_loc    = trim($_POST['ex_loc'] ?? '');

    $ex_duplicate = ($ex_name !== '' && $ex_loc !== '') ? account_find_matching_account($ex_engine, $ex_loc) : null;

    if ($ex_name === '' || $ex_loc === '') {
        $msg = lang('@Please enter both a name and the database location.'); $msg_type = 'error';
    } elseif ($ex_duplicate) {
        // Fundet ved samme sweep som last-active-værnet nedenfor: uden dette
        // tjek kunne samme fysiske database ende registreret under 2
        // forskellige id'er/navne, og fremstå som 2 selvstændige regnskaber
        // i login-vælgeren, selvom de reelt viser identiske data.
        $msg = lang('@This database is already registered as') . ' "' . htmlspecialchars($ex_duplicate['name'] ?? $ex_duplicate['id']) . '".';
        $msg_type = 'error';
    } else {
        $ex_id = account_generate_id($ex_name);
        $entry = [
            'id'      => $ex_id,
            'name'    => $ex_name,
            'engine'  => $ex_engine,
            'is_demo' => false,
            'active'  => true,
        ];
        if ($ex_engine === 'sqlite') {
            $entry['db_path'] = $ex_loc;
        } else {
            $entry['db_name'] = $ex_loc;
        }
        if (account_save($entry)) {
            log_action($conn, 'REGISTER_EXISTING_ACCOUNT', 'accounts', 0, null, $entry);
            $msg = lang('@Existing database registered.'); $msg_type = 'success';
        } else {
            $msg = lang('@Could not save the new ledger registration.'); $msg_type = 'error';
        }
    }
}

// --- GET: nedlæg/genaktiver et regnskab - kun en flag-ændring i
// accounts.json, ingen database røres. Samme lette GET+confirm-mønster som
// resten af appens listesider allerede bruger til rutinemæssige handlinger
// (fx supplier_list.php's slet-knap via htm_ConfirmLink()).
if (isset($_GET['deactivate'])) {
    $id     = (string)$_GET['deactivate'];
    $target = account_get($id);
    if ($target) {
        // ALVORLIGT gap fundet ved sweep efter forrige fund: nedlægges det
        // SIDSTE aktive regnskab, viser login.php hverken vælger eller
        // fejl ved næste login - den falder helt stille tilbage til env.
        // ini's rå ACTIVE_DB-forbindelse i stedet (samme mekanisme som
        // "intet regnskab valgt endnu" bruger). Reproduceret konkret: en
        // frisk login-session landede uden varsel på en HELT ANDEN
        // database end den der lige var "nedlagt" - reel risiko for at
        // bogføre i det forkerte regnskab. Værnet her forhindrer at denne
        // tilstand overhovedet kan opstås via UI'en.
        $other_active = array_filter(account_list(), fn($a) => ($a['id'] ?? null) !== $id);
        if (!empty($target['active']) && empty($other_active)) {
            header('Location: account_manage.php?msg=last_active_blocked');
            exit;
        }
        if (account_deactivate($id)) {
            log_action($conn, 'DEACTIVATE_ACCOUNT', 'accounts', 0, $target, ['active' => false]);
            header('Location: account_manage.php?msg=deactivated');
            exit;
        }
    }
}
if (isset($_GET['reactivate'])) {
    $id     = (string)$_GET['reactivate'];
    $target = account_get($id);
    if ($target && account_save(['id' => $id, 'active' => true])) {
        log_action($conn, 'REACTIVATE_ACCOUNT', 'accounts', 0, $target, ['active' => true]);
        header('Location: account_manage.php?msg=reactivated');
        exit;
    }
}

if (isset($_GET['msg'])) {
    $known_msgs = [
        'deactivated'          => ['@Ledger deactivated. It no longer appears in the login list, but its database is untouched.', 'success'],
        'reactivated'          => ['@Ledger reactivated.', 'success'],
        'last_active_blocked'  => ['@You cannot deactivate the last active ledger - new logins would silently fall back to the raw default database instead of showing an error. Register or create at least one other ledger first.', 'error'],
    ];
    if (isset($known_msgs[$_GET['msg']])) {
        [$msg_key, $msg_type] = $known_msgs[$_GET['msg']];
        $msg = lang($msg_key);
    }
}

htm_Header(capt: '@Accounts', mwidth: 1000);
showMenu();

echo '<div style="max-width:1000px; margin:20px auto 0; padding:0 5px;">';
echo '  <h1 style="margin:0; color: var(--text-main, #2c3e50); text-align:center;"><i class="fa-solid fa-folder-tree" style="color:var(--color-primary);"></i> ' . lang('@Accounts') . '</h1>';
echo '</div>';

if ($msg) htm_Alert(text: $msg, type: $msg_type, width: 1000);

if ($just_created_id) {

    htm_Card_(capt: '⏳ ' . lang('@Building ledger...'), wdth: 700);
    echo '<p>' . lang('@Setting up the new ledger. This only takes a moment.') . '</p>';
    echo '<form id="provisionForm" method="post" action="db-setup/provision_account.php">';
    csrf_field();
    echo '<input type="hidden" name="do" value="provision">';
    echo '<input type="hidden" name="account_id" value="' . htmlspecialchars($just_created_id, ENT_QUOTES) . '">';
    echo '</form>';
    echo '<script>document.getElementById("provisionForm").submit();</script>';
    htm_Card_end();

} else {

    // --- KORT 1: Opret nyt demo-regnskab ---
    htm_Card_(capt: '🆕 ' . lang('@Create new demo ledger'), wdth: 700, form: 'create_demo_form', fold: true);
    echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@Creates a brand new, fully separate database seeded with demo data (H.C. Andersen fairy-tale theme) - completely isolated from your other ledgers.') . '</p>';
    echo '<div style="margin-bottom:12px;"><label>' . lang('@Name') . '</label><br>
          <input type="text" name="name" required style="width:100%; padding:8px; box-sizing:border-box;"></div>';
    echo '<div style="margin-bottom:12px;"><label>' . lang('@Database engine') . '</label><br>';
    htm_Select('engine', ['sqlite' => 'SQLite', 'mysql' => 'MySQL'], 'sqlite',
        'width:100%; padding:8px; box-sizing:border-box;',
        'onchange="document.getElementById(\'mysqlDbNameRow\').style.display = (this.value===\'mysql\') ? \'block\' : \'none\';"');
    echo '</div>';
    echo '<div id="mysqlDbNameRow" style="display:none; margin-bottom:12px;">
            <label>' . lang('@Existing empty MySQL database name') . '</label><br>
            <input type="text" name="db_name" style="width:100%; padding:8px; box-sizing:border-box;">
            <div style="font-size:0.8em; color:var(--text-muted); margin-top:4px;">' . lang('@TinyCash never creates the MySQL database itself - create an empty one via your hosting control panel first, using the same server credentials already configured in env.ini, then enter its name here.') . '</div>
          </div>';
    htm_Button(icon: 'fa-plus', labl: '@Create', attr: 'name="create_demo" value="1"');
    htm_Card_end();

    // --- KORT 1b: Registrer en allerede eksisterende database ---
    // Den generelle løsning på "regnskabet forsvandt fra login-vælgeren"-
    // gappet: en administrator kan altid bringe ETHVERT eksisterende
    // regnskab (inkl. denne installations OPRINDELIGE database, fra før
    // flere-regnskaber-funktionen fandtes) tilbage i listen herfra, blot
    // stien/database-navnet kendes - helt uafhængigt af hvilket regnskab
    // man selv er logget ind på lige nu.
    $current_loc_hint = ($db_type === 'mysql') ? ($db_settings['DB_NAME'] ?? '') : ($db_settings['DB_PATH'] ?? 'data/tinycash.sqlite');
    $candidates = array_merge(account_scan_sqlite_candidates(), account_scan_mysql_candidates($config['mysql_config'] ?? []));
    htm_Card_(capt: '🔗 ' . lang('@Register an existing database'), wdth: 700, fold: 'closed');
    echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@Adds an already set-up database - for example this installation\'s original ledger from before multiple ledgers existed - to the ledger list below, without touching its data. Use this if a ledger has become invisible in the login picker.') . '</p>';

    if (!empty($candidates)) {
        // Fundne, endnu ikke registrerede databaser - bruger-anmodet
        // ("kan den ikke selv foreslå gamle placeringer"): gennemsøgt af
        // account_scan_sqlite_candidates()/account_scan_mysql_candidates()
        // ovenfor. Navnet kan stadig rettes inden man trykker Registrer -
        // kun sti/motor er faste (skjulte felter), taget direkte fra fundet.
        echo '<p style="font-size:0.85em; color:var(--text-muted); margin-bottom:6px;"><i class="fa fa-magnifying-glass"></i> ' . lang('@Found on this server, not yet registered:') . '</p>';
        foreach ($candidates as $c) {
            echo '<form method="post" style="display:flex; gap:6px; align-items:center; margin-bottom:8px;">';
            csrf_field();
            echo '<input type="text" name="ex_name" value="' . htmlspecialchars($c['suggested_name'], ENT_QUOTES) . '" style="flex:1; padding:6px 8px; box-sizing:border-box;">';
            echo '<span style="font-size:0.8em; color:var(--text-muted); white-space:nowrap;">' . htmlspecialchars(strtoupper($c['engine'])) . ' &middot; ' . htmlspecialchars($c['location']) . '</span>';
            echo '<input type="hidden" name="ex_engine" value="' . htmlspecialchars($c['engine'], ENT_QUOTES) . '">';
            echo '<input type="hidden" name="ex_loc" value="' . htmlspecialchars($c['location'], ENT_QUOTES) . '">';
            htm_Button(icon: 'fa-link', labl: '@Register', type: 'secondary', styl: 'padding:6px 12px; font-size:12px; white-space:nowrap;', attr: 'name="register_existing" value="1"');
            echo '</form>';
        }
        echo '<p style="font-size:0.8em; color:var(--text-muted); margin:12px 0 6px;">' . lang('@Or enter one manually:') . '</p>';
    }

    echo '<form method="post">';
    csrf_field();
    echo '<div style="margin-bottom:12px;"><label>' . lang('@Name') . '</label><br>
          <input type="text" name="ex_name" required style="width:100%; padding:8px; box-sizing:border-box;"></div>';
    echo '<div style="margin-bottom:12px;"><label>' . lang('@Database engine') . '</label><br>';
    htm_Select('ex_engine', ['sqlite' => 'SQLite', 'mysql' => 'MySQL'], $db_type,
        'width:100%; padding:8px; box-sizing:border-box;', '');
    echo '</div>';
    echo '<div style="margin-bottom:12px;">
            <label>' . lang('@Database location (SQLite file path relative to inc/, or MySQL database name)') . '</label><br>
            <input type="text" name="ex_loc" value="' . htmlspecialchars($current_loc_hint, ENT_QUOTES) . '" style="width:100%; padding:8px; box-sizing:border-box;">
            <div style="font-size:0.8em; color:var(--text-muted); margin-top:4px;">' . lang('@Pre-filled with the database this page is currently connected to - change it if you mean a different one.') . '</div>
          </div>';
    htm_Button(icon: 'fa-link', labl: '@Register', type: 'secondary', attr: 'name="register_existing" value="1"');
    echo '</form>';
    htm_Card_end();

    // --- KORT 2: Konverter demo til aktiv ---
    $demo_accounts = array_values(array_filter(account_list(true), fn($a) => !empty($a['is_demo']) && !empty($a['active'])));
    htm_Card_(capt: '🔄 ' . lang('@Convert demo to active ledger'), wdth: 1000, fold: true);
    if (empty($demo_accounts)) {
        echo '<p style="color:var(--text-muted);">' . lang('@No demo ledgers to convert.') . '</p>';
    } else {
        echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@Permanently deletes ALL demo data (customers, invoices, journal entries etc.) and resets the voucher/invoice/quote number counters to 1, so the ledger is ready for real bookkeeping. The accounts, VAT codes and admin user are kept.') . '</p>';
        $confirm_convert = htmlspecialchars(addslashes(lang('@This permanently deletes all demo data in this ledger and cannot be undone. Continue?')), ENT_QUOTES);
        foreach ($demo_accounts as $acc) {
            echo '<div style="display:flex; justify-content:space-between; align-items:center; padding:10px; border-bottom:1px solid var(--border-subtle);">';
            echo '  <span><i class="fa fa-building"></i> ' . htmlspecialchars($acc['name'] ?? $acc['id']) . ' <span style="color:var(--text-muted); font-size:0.85em;">(' . htmlspecialchars(strtoupper($acc['engine'] ?? '')) . ')</span></span>';
            echo '  <form method="post" action="db-setup/provision_account.php" style="margin:0;" onsubmit="return confirm(\'' . $confirm_convert . '\');">';
            csrf_field();
            echo '    <input type="hidden" name="do" value="convert">';
            echo '    <input type="hidden" name="account_id" value="' . htmlspecialchars($acc['id'], ENT_QUOTES) . '">';
            htm_Button(icon: 'fa-arrow-right', labl: '@Convert to active', type: 'warning');
            echo '  </form>';
            echo '</div>';
        }
    }
    htm_Card_end();

    // --- KORT 3: Alle regnskaber (oversigt + nedlæg/genaktiver) ---
    htm_Card_(capt: '🗂️ ' . lang('@All ledgers'), wdth: 1000, fold: true);
    $all_accounts = account_list(true);
    if (empty($all_accounts)) {
        echo '<p style="color:var(--text-muted);">' . lang('@No ledgers registered yet - this installation runs on its single, original database (env.ini), exactly as before this feature existed.') . '</p>';
    } else {
        $head = ['@Name', '@Engine', '@Type', '@Status', '@Created', '@Actions'];
        $data = [];
        $active_count = count(account_list());
        foreach ($all_accounts as $acc) {
            $type_badge   = !empty($acc['is_demo']) ? htm_Badge('@Demo', 'warning', false) : htm_Badge('@Active', 'success', false);
            $status_badge = !empty($acc['active'])  ? htm_Badge('@Active', 'success', false) : htm_Badge('@Deactivated', 'secondary', false);
            if (!empty($acc['active']) && $active_count <= 1) {
                // Samme værn som deaktiverings-handleren håndhæver server-
                // side - vist her så det aldrig virker klikbart i første
                // omgang, i stedet for kun at ramme en fejlbesked bagefter.
                $action = '<span data-hint="' . htmlspecialchars(lang('@This is the last active ledger - deactivating it would leave no way to reach it at login.'), ENT_QUOTES) . '" style="color:var(--text-muted); font-size:12px;"><i class="fa-solid fa-lock"></i> ' . lang('@Deactivate') . '</span>';
            } elseif (!empty($acc['active'])) {
                $action = htm_ConfirmLink(
                    icon: 'fa-box-archive', labl: '@Deactivate',
                    link: 'account_manage.php?deactivate=' . urlencode($acc['id']),
                    mess: '@Deactivate this ledger? It disappears from the login list, but its database is kept untouched and can be reactivated any time.',
                    type: 'secondary', styl: 'padding:4px 10px; font-size:12px;', echo: false
                );
            } else {
                $action = htm_ConfirmLink(
                    icon: 'fa-rotate-left', labl: '@Reactivate',
                    link: 'account_manage.php?reactivate=' . urlencode($acc['id']),
                    mess: '@Reactivate this ledger?',
                    type: 'success', styl: 'padding:4px 10px; font-size:12px;', echo: false
                );
            }
            $data[] = [
                htmlspecialchars($acc['name'] ?? $acc['id']),
                htmlspecialchars(strtoupper($acc['engine'] ?? '')),
                $type_badge,
                $status_badge,
                htmlspecialchars($acc['created_at'] ?? ''),
                $action,
            ];
        }
        htm_Table($head, $data, 'accountsTbl', 100, '', true, [], '400px');
    }
    htm_Card_end();

}

htm_Footer();
