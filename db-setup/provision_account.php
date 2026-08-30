<?php # /db-setup/provision_account.php v:1.3.0 d:2026-08-30 i:evs
# NY FIL (flere-regnskaber-funktionen, Fase 2) - admin-gated arbejdshest for
# account_manage.php's to database-berørende handlinger:
#   - provision : bygger skema + lægger demo-data ind på et NYT regnskab.
#   - convert   : tømmer et demo-regnskab for demo-data og nulstiller dets
#                 tællere, så det kan tages i brug som et rigtigt regnskab.
# "Nedlæg regnskab" (blid deaktivering) håndteres IKKE her - den rører aldrig
# nogen database og ligger derfor direkte i account_manage.php.
#
# CENTRAL ARKITEKTONISK BEGRÆNSNING (se plan-filens §"Central arkitektonisk
# begrænsning"): DB::-klassen og create_append_only_triggers() (begge i
# inc/db_connect.inc.php) læser $pdo/$db_type som GLOBALE variable internt
# for SQLite-grenen, uanset hvilken $conn der medsendes som parameter. Man
# kan derfor ikke bare åbne endnu en PDO-forbindelse og sende den til
# DB::query() - den ville stadig ramme adminens EGEN ambiente database. Denne
# fil løser det ved MIDLERTIDIGT at ombytte de globale $pdo/$conn/$db_type
# til mål-regnskabets egen forbindelse, mens create_all_tables_for()/
# seed_demo_data_for()/DELETE-oprydningen kører, og gendanne dem bagefter i
# et finally-block, uanset udfald - adminens egen forbindelse røres aldrig
# af selve skema-/data-arbejdet.
/* ==========================================================================
   FORUDSÆTNING: kaldes kun fra account_manage.php (samme mønster som andre
   *_action.php-filer) - $_POST['account_id'] skal allerede være et
   REGISTRERET regnskab i inc/data/accounts.json.
   ========================================================================== */

chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/../inc/audit.inc.php';
header('Content-Type: text/plain; charset=utf-8');

$action     = $_POST['do'] ?? '';
$account_id = (string)($_POST['account_id'] ?? '');
$account_entry = $account_id !== '' ? account_get($account_id) : null;

if (!$account_entry) {
    die("FEJL: Ukendt regnskab (id: " . htmlspecialchars($account_id) . ").\n");
}
if (!in_array($action, ['provision', 'convert'], true)) {
    die("FEJL: Ukendt handling.\n");
}
if ($action === 'convert' && empty($account_entry['is_demo'])) {
    die("FEJL: Kun demo-regnskaber kan konverteres til aktive.\n");
}

// -------------------------------------------------------------------------
// Åbner en helt selvstændig forbindelse til mål-regnskabet - aldrig via
// inc/db_connect.inc.php (som altid ville forbinde til DENNE sessions eget
// valgte regnskab), men ved at genbruge nøjagtig samme forbindelseskode som
// inc/db_connect.inc.php selv bruger for hver af de to motorer.
// -------------------------------------------------------------------------
function _provision_open_connection(array $db_settings, string $db_type) {
    if ($db_type === 'sqlite') {
        $db_path_rel = $db_settings['DB_PATH'] ?? '';
        $db_dir_rel  = dirname($db_path_rel);
        $db_dir_abs  = __DIR__ . '/../inc/' . $db_dir_rel;
        if (!is_dir($db_dir_abs)) {
            mkdir($db_dir_abs, 0755, true);
        }
        $db_path_abs = realpath($db_dir_abs) . '/' . basename($db_path_rel);
        $pdo = new PDO('sqlite:' . $db_path_abs);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
    $host = $db_settings['DB_HOST'] ?? 'localhost';
    $user = $db_settings['DB_USER'] ?? '';
    $pass = $db_settings['DB_PASS'] ?? '';
    $name = $db_settings['DB_NAME'] ?? '';
    $mysqli_conn = @mysqli_connect($host, $user, $pass, $name);
    if (!$mysqli_conn) {
        throw new Exception("MySQL-forbindelse til regnskabet fejlede: " . mysqli_connect_error());
    }
    mysqli_set_charset($mysqli_conn, 'utf8mb4');
    return $mysqli_conn;
}

// Kører $fn($target_conn, $target_type) mod mål-regnskabets EGEN, isolerede
// forbindelse - ombytter de globale $pdo/$conn/$db_type imens (se filens
// header), og gendanner dem altid bagefter, også hvis $fn kaster en fejl.
function _provision_with_isolated_connection(array $account_entry, array $mysql_config, callable $fn): void {
    global $conn, $pdo, $db_type;
    $saved_conn = $conn;
    $saved_pdo  = $pdo ?? null;
    $saved_type = $db_type;

    $target_type     = $account_entry['engine'] ?? 'sqlite';
    $target_settings = account_resolve_settings($account_entry, $mysql_config);
    $target_handle   = _provision_open_connection($target_settings, $target_type);

    $db_type = $target_type;
    $conn    = $target_handle;
    if ($target_type === 'sqlite') {
        $pdo = $target_handle;
    }

    try {
        $fn($target_handle, $target_type);
    } finally {
        if ($target_type === 'mysql' && $target_handle) {
            mysqli_close($target_handle);
        }
        // SQLite-PDO'en lukkes ikke eksplicit - PHP frigiver den, når den
        // sidste reference (her $target_handle) forsvinder ved funktionens
        // afslutning.
        $conn    = $saved_conn;
        $pdo     = $saved_pdo;
        $db_type = $saved_type;
    }
}

// Findes tabellen $table på den AKTUELT globalt forbundne motor? Bruges kun
// inde i den ombyttede tilstand ovenfor, så den altid spørger mål-regnskabet,
// aldrig adminens egen forbindelse.
function _provision_table_exists($conn, string $db_type, string $table): bool {
    if ($db_type === 'sqlite') {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='" . DB::escape($conn, $table) . "'");
    } else {
        $res = DB::query($conn, "SHOW TABLES LIKE '" . DB::escape($conn, $table) . "'");
    }
    return (bool)($res && DB::fetch_assoc($res));
}

$mysql_config = $config['mysql_config'] ?? [];

if ($action === 'provision') {

    echo "Opretter regnskab '" . $account_entry['name'] . "' (" . $account_entry['engine'] . ")...\n";
    echo str_repeat('-', 50) . "\n";

    require_once __DIR__ . '/init_demo_data.core.php';
    require_once __DIR__ . '/create_all_tables.core.php';

    try {
        _provision_with_isolated_connection($account_entry, $mysql_config, function ($t_conn, $t_type) {
            seed_demo_data_for($t_conn, $t_type);
            echo "\n";
            create_all_tables_for($t_conn, $t_type);
        });
        echo "\n[OK] Regnskabet er klar.\n";
        log_action($conn, 'PROVISION_ACCOUNT', 'accounts', 0, null, ['account_id' => $account_id, 'name' => $account_entry['name']]);
    } catch (Throwable $e) {
        echo "\n[FEJL] Kunne ikke oprette regnskabet: " . $e->getMessage() . "\n";
    }

} elseif ($action === 'convert') {

    echo "Konverterer demo-regnskab '" . $account_entry['name'] . "' til et aktivt regnskab...\n";
    echo str_repeat('-', 50) . "\n";

    // Ekskluderet med vilje: users, accounts, vat_codes, std_accounts
    // (kontoplan/momskoder/brugere er ikke "demo-data", de er selve
    // grundlaget nye posteringer skal bruge), menu_visibility (UI-
    // indstilling, ikke bogføringsdata), settings (firmaoplysninger
    // redigeres i stedet via company_settings.php), system_migrations
    // (historik over hvilke migrationer der er kørt PÅ regnskabet).
    $data_tables = [
        'invoice_lines', 'invoices', 'invoice_payments',
        'recurring_invoice_lines', 'recurring_invoices',
        'ledger', 'journal',
        'expenses',
        'bank_statement_temp', 'bank_connections',
        'transactions',
        'products', 'customers',
        'projects', 'fiscal_years',
        'login_log', 'audit_log', 'layout_settings', 'tbl_maillog',
        // Nyere tilvalgsfunktioner - findes kun hvis de relevante
        // migrationer er kørt på dette regnskab, tjekkes derfor for
        // eksistens enkeltvis nedenfor.
        'quote_lines', 'quotes',
        'suppliers', 'fixed_assets', 'time_entries',
    ];
    $counter_tables = ['voucher_counter', 'invoice_no_counter', 'quote_no_counter'];

    // RETTET (§find-fem-fejl, flere-regnskaber-sweep): en fejlet DELETE
    // (echo "[FEJL] ...") og en ukontrolleret fallback-INSERT herunder
    // stoppede FØR ikke selve løkken, og $had_errors fandtes slet ikke -
    // konverteringen fortsatte til "[OK] Regnskabet er nu markeret som
    // aktivt", uanset om én eller flere tabeller reelt fejlede at blive
    // tømt/nulstillet. En admin der kun skummede outputtet (typisk en lang
    // liste af [OK]-linjer) kunne derfor tro regnskabet var rent, mens en
    // enkelt tabel stadig indeholdt demo-data. Nu spores fejl eksplicit, og
    // hverken "regnskabet er aktivt"-flaget eller den positive slutbesked
    // sættes, hvis noget reelt fejlede undervejs.
    $had_errors = false;
    try {
        _provision_with_isolated_connection($account_entry, $mysql_config, function ($t_conn, $t_type) use ($data_tables, $counter_tables, &$had_errors) {
            foreach ($data_tables as $table) {
                if (!_provision_table_exists($t_conn, $t_type, $table)) {
                    continue;
                }
                if (DB::query($t_conn, "DELETE FROM $table")) {
                    echo "[OK] $table tømt.\n";
                } else {
                    echo "[FEJL] Kunne ikke tømme $table: " . DB::error($t_conn) . "\n";
                    $had_errors = true;
                }
            }
            foreach ($counter_tables as $table) {
                if (!_provision_table_exists($t_conn, $t_type, $table)) {
                    continue;
                }
                $upd = DB::query($t_conn, "UPDATE $table SET next_no = 1 WHERE id = 1");
                if (DB::affected_rows($t_conn, $upd) === 0) {
                    if (!DB::insert($t_conn, $table, ['id' => 1, 'next_no' => 1])) {
                        echo "[FEJL] Kunne ikke nulstille $table: " . DB::error($t_conn) . "\n";
                        $had_errors = true;
                        continue;
                    }
                }
                echo "[OK] $table nulstillet til 1.\n";
            }
        });

        if ($had_errors) {
            echo "\n[FEJL] Konverteringen stødte på fejl undervejs (se [FEJL]-linjerne ovenfor) - regnskabet er BEVIDST IKKE markeret som aktivt endnu, så det trygt kan forsøges igen.\n";
        } else {
            account_save(['id' => $account_id, 'is_demo' => false]);
            echo "\n[OK] Regnskabet er nu markeret som aktivt (ikke længere demo).\n";
            log_action($conn, 'CONVERT_ACCOUNT_TO_ACTIVE', 'accounts', 0, null, ['account_id' => $account_id, 'name' => $account_entry['name']]);
        }
    } catch (Throwable $e) {
        echo "\n[FEJL] Konvertering fejlede: " . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Faerdig. Gaa tilbage til Regnskaber-siden (account_manage.php) i browserens tilbage-knap.\n";
