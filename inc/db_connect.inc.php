<?php # /inc/db_connect.inc.php v:1.3.1 d:2026-09-15 i:evs
# dump_to_sql() medtager nu triggere - manglede FØR helt, så en gendannelse ville stille fjerne append-only-beskyttelsen på journal/ledger igen
# v1.3.0: DB::fetch_assoc()/fetch_row()/num_rows()/free_result() crashede med
# et fatal error ("Call to a member function fetch() on bool") hvis den givne
# forespørgsel fejlede (fx en manglende tabel/kolonne på en installation der
# mangler migrationer) - ramte en bruger reelt via invoice_view.php. Guard
# tilføjet alle fire steder, se kommentar ved fetch_assoc().
# v1.4.0: tilføjet next_invoice_no() - invoice_post_action.php brugte før et
# kapløbs-sårbart "MAX(invoice_no)+1"-mønster. Se kommentar ved funktionen.
define('APP_VERSION', '1.3.0');
define('APP_DATE', '2026-08-30');
/* 
// --- 1. SIKKER SESSIONSSTART & SPROGVÆLGER-LOGIK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 */
/* 
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = strtolower($_GET['lang']);
    
    // Fjern ?lang= parameteren fra URL'en og genindlæs siden rent
    $clean_url = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: " . $clean_url);
    exit;
} */

// --- 2. GLOBAL DEBUG CONTROL ---
if (isset($_GET['Debug']) && $_GET['Debug'] === 'true') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    $GLOBAL_DEBUG_ALERT = '<div style="background:red; color:white; padding:5px; position:fixed; bottom:0; right:0; z-index:9999; font-size:10px; opacity:0.7;">DEBUG ACTIVE</div>';
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// --- 3. DYNAMISK KONFIGURATIONSLÆSER ---
// RETTET (bruger-anmodet konsolidering af installationsspecifikke data i
// inc/data/, hvor tinycash.sqlite allerede lå): env.ini flyttet til
// inc/data/env.ini. De to gamle stier ('inc/env.ini' og rod-'env.ini')
// beholdes SIDST i listen som bagudkompatibel fallback, så en installation
// der endnu ikke har flyttet sin fil manuelt (fx via FTP) ikke braser.
$env_file = null;
$SøgeStier = [
    __DIR__ . '/data/env.ini',
    __DIR__ . '/env.ini',
    __DIR__ . '/.env',
    __DIR__ . '/../env.ini',
    __DIR__ . '/../.env'
];

foreach ($SøgeStier as $sti) {
    if (file_exists($sti)) {
        $env_file = $sti;
        break;
    }
}

if ($env_file === null) {
    die("<div style='font-family:sans-serif; padding:50px; text-align:center;'>
            <h1 style='color:#e67e22;'>Konfigurationsfejl</h1>
            <p>Hverken <code>env.ini</code> eller <code>.env</code> kunne findes i dine mapper.</p>
         </div>");
}

$lines = @file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines !== false) {
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"' ");
            
            if ($key !== '') {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

// --- 4. HENT PARAMETRE FRA MILJØET (Med Sektioner) ---
$config = parse_ini_file($env_file, true);

// FEJLFINDING:
if ($config === false) {
    die("Fejl: Kunne ikke læse env.ini. Tjek rettigheder!");
}
if (!isset($config['ACTIVE_DB'])) {
    die("Fejl: Nøglen ACTIVE_DB blev ikke afundet i env.ini. Indhold fundet: " . implode(', ', array_keys($config)));
}
//   var_dump($config['ACTIVE_DB']);  exit; // DEBUG: Fjern denne linje når det virker

// --- TIDSZONE ---
// RETTET: TinyCash satte aldrig nogen tidszone - PHP brugte serverens php.ini (UTC på mange maskiner).
// Datoer som date('Y-m-d') (fakturadato, forfald, bogføringsdato) blev så gårsdagens mellem midnat og
// kl. 1-2 dansk tid, og alle viste tider var 1-2 timer forkerte. Nu: TIMEZONE i env.ini, ellers dansk tid.
// (Tider som SQLite selv skriver - DEFAULT CURRENT_TIMESTAMP - er altid UTC: se db_ts_local() / db_ts_param().)
$tc_timezone = trim((string)($config['TIMEZONE'] ?? ''));
if ($tc_timezone === '' || !in_array($tc_timezone, timezone_identifiers_list(), true)) $tc_timezone = 'Europe/Copenhagen';
date_default_timezone_set($tc_timezone);

// --- NYT: FLERE REGNSKABER (bruger-anmodet) ---
// Er et regnskab valgt på login-siden (gemt i $_SESSION['account_id'], se
// inc/account.lib.php), slår vi dets forbindelsesoplysninger op i
// inc/data/accounts.json og bruger DEM i stedet for den eneste, statiske
// ACTIVE_DB-sektion. Er intet regnskab valgt (accounts.json findes slet
// ikke, eller sessionen aldrig har valgt ét), springer denne gren helt over,
// og resten af filen (herunder $_SESSION['db_type']-mekanismen nedenfor)
// opfører sig 100% som før - det er det, der gør bagudkompatibiliteten for
// eksisterende ét-regnskabs-installationer strukturel, ikke tilfældig.
require_once __DIR__ . '/account.lib.php';
$account_entry = (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['account_id']))
    ? account_get($_SESSION['account_id'])
    : null;

if ($account_entry) {
    $db_type     = $account_entry['engine'];
    $db_settings = account_resolve_settings($account_entry, $config['mysql_config'] ?? []);
} else {

// --- NYT: VALGBAR DATABASE-TYPE VIA SESSION (login.php) ---
// Hvis brugeren har valgt DB-type på login-siden (gemt i $_SESSION['db_type']
// som enten 'mysql' eller 'sqlite'), bruger vi DEN i stedet for den statiske
// ACTIVE_DB fra env.ini. Er intet valgt (frisk session, eller sider der ikke
// går via login.php's vælger), falder vi tilbage til env.ini som hidtil.
// NOTE: Selve forbindelsesoplysningerne (host/bruger/kodeord/sti) kommer
// STADIG udelukkende fra env.ini's [mysql_config]/[sqlite_config] - brugeren
// vælger kun HVILKEN af de to allerede konfigurerede sektioner der bruges,
// aldrig hvilke credentials der forbindes med.
$session_override = null;
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['db_type']) && in_array($_SESSION['db_type'], ['mysql', 'sqlite'], true)) {
    $session_override = $_SESSION['db_type'] . '_config';
}

// Hent den aktive sektion fra roden af arrayet (session-valg vinder over ACTIVE_DB)
$active_section = $session_override ?? ($config['ACTIVE_DB'] ?? 'mysql_config');
// Hent kun indstillingerne for den aktive sektion
$db_settings = $config[$active_section] ?? [];
$db_type = $db_settings['DB_TYPE'] ?? 'mysql';

} // slut på "intet regnskab valgt"-grenen

// Gem den FAKTISK anvendte type tilbage i sessionen, så resten af sessionen
// (alle sider efter login) er konsistent, selv hvis valget kom fra env.ini's
// fallback i stedet for et eksplicit brugervalg.
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['db_type'] = $db_type;
}

// --- 5. DATABASE CONNECTION (DYNAMISK MOTOR) ---
if ($db_type === 'sqlite') {
    // SQLite Engine
    $db_path_rel = $db_settings['DB_PATH'] ?? 'data/tinycash.sqlite';

    // RETTET: realpath() kan kun slå stien op, hvis MÅLET allerede findes.
    // Ved en frisk installation findes tinycash.sqlite endnu ikke, så
    // realpath() på hele filstien returnerede false - hvilket gav
    // "mkdir(): Invalid path", fordi dirname(false) ikke er en gyldig sti.
    // Løsning: byg mappe-stien uden om realpath, opret den om nødvendigt,
    // og brug FØRST realpath på selve mappen (som nu altid findes).
    $db_dir_rel = dirname($db_path_rel);
    $db_dir_abs = __DIR__ . '/' . $db_dir_rel;

    if (!is_dir($db_dir_abs)) {
        mkdir($db_dir_abs, 0755, true);
    }

    $db_path_abs = realpath($db_dir_abs) . '/' . basename($db_path_rel);

    $pdo = new PDO('sqlite:' . $db_path_abs);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Global $conn skal være vores PDO objekt for at Shim virker
    $conn = $pdo;
   
} else {
    // MySQL Engine
    $db_host = $db_settings['DB_HOST'] ?? 'localhost';
    $db_user = $db_settings['DB_USER'] ?? '';
    $db_pass = $db_settings['DB_PASS'] ?? '';
    $db_name = $db_settings['DB_NAME'] ?? '';

    // RETTET (fundet under et sweep af account_manage.php's ny database-
    // opdagelsesfunktion): "@" undertrykker kun PHP-advarsler, ikke
    // undtagelser - og siden PHP 8.1 er mysqli's STANDARD fejltilstand
    // netop at KASTE en mysqli_sql_exception ved en mislykket forbindelse
    // (bekræftet direkte: PHP 8.3 her på maskinen). En hvilken som helst
    // reel MySQL-forbindelsesfejl (forkert kodeord, nede server, forkert
    // vært) endte derfor som et grimt, ufanget fatal error i stedet for den
    // pæne "MySQL forbindelse fejlede: ..."-besked nedenfor - denne
    // try/catch fanger begge fejltilstande (den ældre false-retur OG den
    // nyere kastede undtagelse) ens.
    try {
        $conn = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);
    } catch (\Throwable $e) {
        die("MySQL forbindelse fejlede: " . $e->getMessage());
    }

    if (!$conn) {
        die("MySQL forbindelse fejlede: " . mysqli_connect_error());
    }
    mysqli_set_charset($conn, "utf8mb4");
    // MySQL regner i samme tidszone som PHP: NOW() / CURRENT_TIMESTAMP og visningen af TIMESTAMP-kolonner
    @mysqli_query($conn, "SET time_zone = '" . date('P') . "'");
}

// Et tidspunkt databasen selv har skrevet (DEFAULT CURRENT_TIMESTAMP: login_log.login_time, audit_log.log_date,
// mail_log.sent_at, journal/expenses.created_at ...) - vist i lokal tid. SQLite skriver altid UTC; MySQL
// bruger allerede PHP's tidszone (SET time_zone ovenfor), så der er det uændret.
function db_ts_local($ts): string {
    $ts = (string)$ts;
    if ($ts === '' || !DB::is_sqlite()) return $ts;
    try { return (new DateTime($ts, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'); }
    catch (\Throwable $e) { return $ts; }
}
// Det modsatte: et lokalt tidspunkt (fx et filter "fra 2026-10-01 00:00:00") til sammenligning med sådan en kolonne
function db_ts_param(string $local): string {
    if (!DB::is_sqlite()) return $local;
    try { return (new DateTime($local))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    catch (\Throwable $e) { return $local; }
}


// RETTET (samme konsolidering som env.ini ovenfor): system_errors.log
// flyttet til inc/data/, som allerede har sit eget "Require all denied"
// (se inc/data/.htaccess) og rummer databasen i forvejen.
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/data/system_errors.log');

function get_settings($conn) {
    $settings = [];
    $sql = "SELECT setting_key, setting_value FROM settings";
    $res = DB::query($conn, $sql);
    if ($res) {
        while ($row = DB::fetch_assoc($res)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        // Brug DB-klassens metode i stedet for mysqli_free_result
        DB::free_result($res); 
    }
    return $settings;
}

// --- 6. INDSTILLINGER  ---
$global_settings = get_settings($conn);
$current_date_format = (!empty($global_settings['date_format'])) ? $global_settings['date_format'] : 'd.m.Y';
define('CONF_DATE_FORMAT', $current_date_format);

// SPROG-LOGIK ER FJERNET HERFRA. STYRES UDELUKKENDE AF AUTH.INC.PHP.

// FJERNET (§bugs-batch-15-review): "?CheckMail=true"-udløseren, som kørte
// inc/depot_sync.php direkte herfra. db_connect.inc.php inkluderes af
// SO GODT SOM HVER ENESTE side i hele appen - denne blok betød derfor, at
// enhver, der tilføjede "?CheckMail=true" til URL'en på en HVILKEN SOM
// HELST side, fik depot_sync.php (en ufærdig, forladt IMAP-prototype med
// tomme/ikke-eksisterende miljøvariabler og en forkert output-mappe -
// inc/storage/bilagsdepot/email/, som intet andet i appen bruger) til at
// køre - uden nogen form for adgangskontrol, logging eller dokumentation af
// at denne parameter overhovedet fandtes. depot_sync.php's egen header-
// kommentar hævdede fejlagtigt "ikke linket fra nogen menu/knap", uden at
// være klar over denne skjulte udløser. Reelt harmløst lige nu (imap_open()
// fejler stille med tomme login-oplysninger), men en skjult, udokumenteret
// kode-udløsning i det mest universelt inkluderede include i hele projektet
// er i sig selv et sikkerheds-/vedligeholdelsesproblem. depot_sync.php
// efterlades urørt (nu reelt uopnåeligt) som historisk artefakt.

// Fortolker et beløb/antal skrevet af en bruger, uanset om det er skrevet med
// dansk (komma-decimal, evt. punktum-tusindtalsseparator, fx "1.500,00") eller
// engelsk/HTML5-number-notation (punktum-decimal, fx "1500.00" eller "1500").
//
// BAGGRUND (fund 2026-08-22, §invoice-line-comma-amount-fix): en tidligere
// rettelse erstattede blot komma med punktum (str_replace(',', '.', $s)) på
// en række beløbsfelter i hele projektet. Det retter "199,95" -> "199.95"
// korrekt, MEN gør det værre for et beløb der ER hævet til noget større og
// skrevet med fuld dansk tusindtals-formatering: "1.500,00" bliver til
// "1.500.00" (TO punktummer), og PHPs (float)-cast stopper simpelthen ved det
// FØRSTE ekstra punktum og returnerer 1.5 - altså et beløb der lige er hævet
// til 1500 kr endte som 1,50 kr efter gem. Bekræftet direkte i PHP:
//   (float)str_replace(',', '.', "1.500,00") === 1.5   // FORKERT
// Denne funktion undgår det ved først at afgøre hvilket tegn der reelt er
// decimal-separatoren (det tegn der optræder SIDST i strengen), og kun fjerne
// det andet tegn som tusindtalsseparator - i stedet for blindt at antage at
// ethvert komma er decimal-tegnet.
function parse_dk_number($s): float {
    $s = trim((string)$s);
    if ($s === '') return 0.0;
    $has_comma = strpos($s, ',') !== false;
    $has_dot   = strpos($s, '.') !== false;
    if ($has_comma && $has_dot) {
        // Begge tegn til stede - det der optræder SIDST er decimal-tegnet,
        // det andet er en tusindtalsseparator og fjernes.
        if (strrpos($s, ',') > strrpos($s, '.')) {
            $s = str_replace('.', '', $s);   // punktum var tusindtalsseparator
            $s = str_replace(',', '.', $s);  // komma er decimal-tegnet
        } else {
            $s = str_replace(',', '', $s);   // komma var tusindtalsseparator (fx "1,500.00")
        }
    } elseif ($has_comma) {
        // Kun komma til stede - dansk konvention, det er decimal-tegnet.
        $s = str_replace(',', '.', $s);
    }
    // Kun punktum (eller intet separator-tegn) til stede: allerede gyldig
    // float-syntaks (matcher hvad en native <input type="number"> selv ville
    // sende), rør ikke ved den.
    return (float)$s;
}

// Oversætter en bruger-rolle (users.user_role - "admin"/"accountant"/"user",
// valgt i user_create.php/user_edit.php's "Rolle"-dropdown) til det numeriske
// adgangsniveau (users.user_level, 1-3), som ALT sidespecifikt $rLev-tjek i
// inc/auth.inc.php reelt gatekeeper på - IKKE user_role direkte.
//
// BAGGRUND (fund 2026-08-22, bruger-rapporteret: en bruger forfremmet til
// "Administrator" i rolle-dropdown'en kunne stadig ikke komme forbi noget
// niveau-3-spærret side): user_role og user_level er to helt uafhængige
// kolonner i users-tabellen, og INTET sted i koden har nogensinde holdt dem
// synkroniseret - hverken user_create.php (INSERT satte kun user_role,
// user_level faldt til kolonnens standard på 1) eller user_edit.php (UPDATE
// satte også kun user_role, rørte aldrig user_level på en eksisterende
// bruger). Den ENESTE konto der nogensinde har fået begge sat korrekt er
// bootstrap-adminkontoen fra init_demo_data.php, som sætter dem direkte i et
// enkelt DB::insert()-kald - derfor er dette hul aldrig blevet opdaget af
// nogen af denne sessions mange adgangskontrol-tests, som alle brugte netop
// den konto. Enhver bruger der er forfremmet/oprettet som admin eller
// revisor UDELUKKENDE via UI'et har derfor siddet fast på user_level=1 hele
// tiden, uanset hvad rolle-dropdown'en viste.
function role_to_level(string $role): int {
    return ['admin' => 3, 'accountant' => 2, 'user' => 1][$role] ?? 1;
}

// Momskontoen $acc + de konti den har AFLØST ved et skift til standardkontoplanen (slukket, std_ref_id = $acc) -
// som SQL-liste til "l.acc_id IN (...)". RETTET: momsrapporten, momsafstemningen, resultatopgørelsen og årsrapporten
// læste kun den NUVÆRENDE momskonto. Efter et skift midt i en periode manglede momsen der var bogført på den gamle
// konto før skiftet, og skiftets saldooverførsel blev talt med som årets moms på den nye konto.
function tc_vat_acc_list($conn, int $acc): string {
    static $has_status = null;
    if ($has_status === null) {
        if (DB::is_sqlite()) {
            $has_status = false;
            $c = DB::query($conn, "PRAGMA table_info(accounts)");
            if ($c) while ($x = DB::fetch_assoc($c)) if ($x['name'] === 'acc_status') $has_status = true;
        } else {
            $c = DB::query($conn, "SHOW COLUMNS FROM accounts LIKE 'acc_status'");
            $has_status = $c && DB::num_rows($c) > 0;
        }
    }
    $ids = [$acc];
    $st  = $has_status ? " AND acc_status = 'off'" : '';
    $res = DB::query($conn, "SELECT acc_id FROM accounts WHERE std_ref_id = $acc AND acc_id <> $acc$st");
    if ($res) while ($r = DB::fetch_row($res)) $ids[] = (int)$r[0];
    return implode(',', array_unique($ids));
}

// Den konto en bogføringsrolle (conf_acc_*) reelt bruger, hvis den findes i kontoplanen - ellers 0. Til de gamle
// "opret konto NNNN"-migreringer: efter en kontoplan fra setup_chart.php eller et skift til standardkontoplanen er
// rollen dækket af en anden konto, og den faste konto må ikke oprettes oven i (3000 er fx et standardnummer).
function tc_role_account($conn, string $key, int $default): int {
    $r = DB::fetch_assoc(DB::query($conn, "SELECT setting_value FROM settings WHERE setting_key = '" . DB::escape($conn, $key) . "'"));
    $acc = (int)(($r['setting_value'] ?? '') !== '' ? $r['setting_value'] : $default);
    return ($acc > 0 && DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = $acc"))) ? $acc : 0;
}
// Første egenkapitalkonto i kontoplanen (årsafslutningen) - 0 hvis ingen
function tc_equity_account($conn): int {
    $r = DB::fetch_row(DB::query($conn, "SELECT MIN(acc_id) FROM accounts WHERE acc_type = 'equity'"));
    return (int)($r[0] ?? 0);
}

// Erstat din nuværende is_date_locked med denne mere robuste version
// Antal hele kalenderdage fra $from til $to (negativt hvis $to ligger før) - kun datodelen tæller.
// RETTET: "(int)((strtotime($to) - strtotime($from)) / 86400)" gav én dag for lidt hen over skiftet til
// sommertid (døgnet er kun 23 timer: 28/3 -> 30/3 2026 blev 1 dag) - en faktura blev "forfalden" en dag for
// sent i rykkerlisten og restancelisten, og dagstællerne på forsiden og i listerne var forkerte.
function days_between($from, $to): int {
    $f = DateTime::createFromFormat('!Y-m-d', substr((string)$from, 0, 10));
    $t = DateTime::createFromFormat('!Y-m-d', substr((string)$to, 0, 10));
    if (!$f || !$t) return 0;
    return (int)$f->diff($t)->format('%r%a');
}

function is_date_locked($conn, $date) {
    $sql = "SELECT setting_value FROM settings WHERE setting_key = 'accounting_lock_date' LIMIT 1";
    
    // Brug din egen wrapper
    $stmt = DB::prepare($conn, $sql);
    
    if ($stmt) {
        if (DB::is_sqlite()) {
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
        }
        
        if ($row) {
            $lock_date = $row['setting_value'];
            if (!empty($lock_date) && strtotime($date) <= strtotime($lock_date)) {
                return true;
            }
        }
    }
    return false;
}

/* ==========================================================================
   RETTET: DB::num_rows() på SQLite returnerede ALTID 1 (sandt), så snart
   forespørgslen bare lykkedes - uanset om der reelt var 0 eller flere
   matchende rækker. Det gjorde fx "findes brugernavnet allerede?"-tjek i
   user_create.php altid sandt, selv for helt nye, ledige brugernavne.

   Årsagen: PDO (som SQLite bruger) har ingen pålidelig "tæl rækker uden at
   forbruge dem"-funktion for SELECT, i modsætning til MySQLi, som som
   standard bufrer hele resultatet med det samme (så num_rows() OG en
   efterfølgende fetch_assoc() begge virker på samme resultat). Det er netop
   dette MySQLi-mønster - num_rows() efterfulgt af fetch_assoc() på samme
   variabel - som bruges 14+ steder i projektet.

   Løsningen er denne lille wrapper-klasse: for SQLite SELECT-forespørgsler
   hentes ALLE rækker øjeblikkeligt ind i et PHP-array (ligesom MySQLi gør
   internt), så både optælling og efterfølgende, gentagen rækkehentning
   virker korrekt - uden at ændre opførslen for MySQL-installationer eller
   for ikke-SELECT-forespørgsler (INSERT/UPDATE/DELETE) på SQLite.
   ========================================================================== */
class SQLiteBufferedResult {
    private $rows;
    private $pointer = 0;
    private $count;

    public function __construct(array $rows) {
        $this->rows = $rows;
        $this->count = count($rows);
    }

    public function fetch($mode = 'assoc') {
        if ($this->pointer >= $this->count) return false;
        $row = $this->rows[$this->pointer];
        $this->pointer++;
        if ($mode === 'num') {
            return array_values($row);
        } elseif ($mode === 'both') {
            return array_merge($row, array_values($row));
        }
        return $row; // 'assoc'
    }

    public function numRows() {
        return $this->count;
    }
}

class DB {
    public static function is_sqlite() {
        global $db_type;
        return ($db_type === 'sqlite');
    }
    public static function query($conn, $sql) {
        global $pdo, $db_type;
        try {
            if ($db_type === 'sqlite') {
                $stmt = $pdo->query($sql);
                if ($stmt === false) return false;
                // Kun SELECT-forespørgsler bufres til et array (se klassen
                // ovenfor) - INSERT/UPDATE/DELETE returnerer stadig det rå
                // PDOStatement-objekt som hidtil, uændret opførsel.
                if (preg_match('/^\s*SELECT/i', $sql)) {
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    return new SQLiteBufferedResult($rows);
                }
                return $stmt;
            } else {
                return mysqli_query($conn, $sql);
            }
        } catch (Exception $e) {
            // Vi returnerer false, men vi logger fejlen, så du kan se den i logfilen
            error_log("DB Query Suppressed Error: " . $e->getMessage() . " | SQL: " . $sql);
            return false;
        }
    }
    // Tilføj denne metode inde i din DB klasse i inc/db_connect.inc.php
    public static function error($conn) {
        global $db_type, $pdo;
        if ($db_type === 'sqlite') {
            return $pdo->errorInfo()[2] ?? 'Unknown SQLite error';
        } else {
            return mysqli_error($conn);
        }
    }
    // fetch_assoc/fetch_row/num_rows/free_result tjekkede FØR ikke om $result
    // overhovedet var et gyldigt resultat, før de kaldte ->fetch()/mysqli_*()
    // på det. En fejlet DB::query() (fx pga. en manglende tabel/kolonne -
    // typisk på en installation hvor create_all_tables.php/migrationer ikke
    // er kørt) returnerer false, og false->fetch() er et PHP fatal error
    // ("Call to a member function fetch() on bool") i stedet for en pæn
    // fejl. Ramte reelt en bruger på en anden installation (invoice_view.php
    // via layout_settings). Guard tilføjet alle fire steder.
    public static function fetch_assoc($result) {
        global $db_type;
        if ($result === false || $result === null) return false;
        if ($result instanceof SQLiteBufferedResult) return $result->fetch('assoc');
        return ($db_type === 'sqlite') ? $result->fetch(PDO::FETCH_ASSOC) : mysqli_fetch_assoc($result);
    }
    public static function fetch_row($result) {
        global $db_type;
        if ($result === false || $result === null) return false;
        if ($result instanceof SQLiteBufferedResult) return $result->fetch('num');
        return ($db_type === 'sqlite') ? $result->fetch(PDO::FETCH_NUM) : mysqli_fetch_row($result);
    }
    // Denne metode er den, der fejler lige nu. Sørg for den står præcis sådan her:
    public static function free_result($result) {
        global $db_type;
        if ($result === false || $result === null) return;
        if ($result instanceof SQLiteBufferedResult) return; // rent PHP-array, intet at frigøre
        if ($db_type !== 'sqlite') {
            mysqli_free_result($result);
        }
        // Hvis det er sqlite, gør vi ingenting (derfor fejler den ikke)
    }
    public static function insert($conn, $table, $data) {
            global $db_type;
            $keys = array_keys($data);
            $fields = implode(", ", $keys);
            // Lav pladsholdere: SQLite/PDO bruger '?', MySQLi bruger '?' (hvis vi bruger prepared statements)
            $placeholders = implode(", ", array_fill(0, count($data), "?"));
            $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
            if ($db_type === 'sqlite') {
                global $pdo;
                $stmt = $pdo->prepare($sql);
                return $stmt->execute(array_values($data));
            } else {
                $stmt = mysqli_prepare($conn, $sql);
                if (!$stmt) return false;
                // Generer types-string (f.eks. "sss" for 3 strenge)
                $types = str_repeat("s", count($data));
                mysqli_stmt_bind_param($stmt, $types, ...array_values($data));
                return mysqli_stmt_execute($stmt);
            }
        }
    // Denne metode gør, at du kan bruge samme kode til både MySQL og SQLite
    public static function prepare_and_execute($conn, $sql, $params = []) {
        global $pdo, $db_type;
        if ($db_type === 'sqlite') {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } else {
            $stmt = mysqli_prepare($conn, $sql);
            // Her forenkler vi: Vi antager kun strenge for nu, eller du kan udvide
            $types = str_repeat("s", count($params)); 
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            return mysqli_stmt_get_result($stmt);
        }
    }
    public static function num_rows($result) {
        global $db_type;
        if ($result === false || $result === null) return 0;
        if ($result instanceof SQLiteBufferedResult) return $result->numRows();
        if ($db_type === 'sqlite') {
            // Fallback for evt. resultater der ikke gik gennem DB::query()
            // (fx et rå PDOStatement fra prepare_and_execute) - dette bør
            // normalt ikke ramme SELECT-tilfælde længere, jf. rettelsen ovenfor.
            return ($result) ? 1 : 0;
        } else {
            return mysqli_num_rows($result);
        }
    }
    // NYT: Transaktionsstøtte - kræves af expense_actions.php (og ethvert
    // andet sted, der skal garantere flere sammenhørende opdateringer enten
    // lykkes helt eller fortrydes helt, fx annullering af en bogført udgift
    // + synkronisering af journalen i samme operation).
    public static function begin_transaction($conn) {
        global $pdo, $db_type;
        return ($db_type === 'sqlite') ? $pdo->beginTransaction() : mysqli_begin_transaction($conn);
    }
    public static function commit($conn) {
        global $pdo, $db_type;
        return ($db_type === 'sqlite') ? $pdo->commit() : mysqli_commit($conn);
    }
    public static function rollback($conn) {
        global $pdo, $db_type;
        return ($db_type === 'sqlite') ? $pdo->rollBack() : mysqli_rollback($conn);
    }
    public static function prepare($conn, $sql) {
        global $pdo, $db_type;
        return ($db_type === 'sqlite') ? $pdo->prepare($sql) : mysqli_prepare($conn, $sql);
    }
    public static function fetch_array($result) {
        global $db_type;
        if ($result instanceof SQLiteBufferedResult) return $result->fetch('both');
        return ($db_type === 'sqlite') ? $result->fetch(PDO::FETCH_BOTH) : mysqli_fetch_array($result);
    }
    public static function update($conn, $table, $data, $where_field, $where_value) {
        global $db_type;
        $fields = "";
        foreach (array_keys($data) as $key) {
            $fields .= "$key = ?, ";
        }
        $fields = rtrim($fields, ", ");
        $sql = "UPDATE $table SET $fields WHERE $where_field = ?";
        $params = array_values($data);
        $params[] = $where_value; // Tilføj WHERE-værdien til sidst
        if ($db_type === 'sqlite') {
            global $pdo;
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($params);
        } else {
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) return false;
            $types = str_repeat("s", count($params));
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            return mysqli_stmt_execute($stmt);
        }
    }
    public static function dump_to_sql($conn) {
        global $db_type, $pdo;
        $sqlDump = "-- TinyCash System Dump - Type: $db_type - Date: " . date('Y-m-d H:i:s') . "\n\n";
        if ($db_type === 'sqlite') {
            $res = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            foreach ($res as $row) {
                $table = $row['name'];
                $createRes = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'");
                $create = $createRes->fetch();
                $sqlDump .= $create['sql'] . ";\n";
                $data = $pdo->query("SELECT * FROM \"$table\"");
                $data->setFetchMode(PDO::FETCH_ASSOC);
                foreach ($data as $item) {
                    // Nu indeholder $item kun kolonnenavne, og array_values() vil kun returnere data én gang
                    $vals = array_map(function($v) {
                        return is_null($v) ? 'NULL' : '"' . str_replace('"', '""', $v) . '"';
                    }, array_values($item));
                    $sqlDump .= "INSERT INTO \"$table\" VALUES (" . implode(", ", $vals) . ");\n";
                }
                $sqlDump .= "\n";
            }
            // Triggere (fx den append-only-beskyttelse migrate_append_only_ledger.php
            // opretter på journal/ledger) manglede FØR helt i dumpet - en gendannelse
            // (backup_restore_worker.php: DROP TABLE + reimport) ville derfor stille
            // fjerne beskyttelsen igen, selvom selve tabellerne kom tilbage korrekt.
            // Fundet ved samme arbejde som selve append-only-triggerne blev tilføjet.
            $trigRes = $pdo->query("SELECT sql FROM sqlite_master WHERE type='trigger'");
            foreach ($trigRes as $trig) {
                if (!empty($trig['sql'])) $sqlDump .= $trig['sql'] . ";\n";
            }
        } else {
            $res = mysqli_query($conn, "SHOW TABLES");
            while ($row = mysqli_fetch_row($res)) {
                $table = $row[0];
                $createRes = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
                $create = mysqli_fetch_row($createRes);
                $sqlDump .= "\n" . $create[1] . ";\n";
                $data = mysqli_query($conn, "SELECT * FROM `$table`");
                while ($item = mysqli_fetch_assoc($data)) {
                    $vals = array_map(function($v) use ($conn) { return is_null($v) ? 'NULL' : '"' . mysqli_real_escape_string($conn, $v) . '"'; }, array_values($item));
                    $sqlDump .= "INSERT INTO `$table` VALUES(" . implode(",", $vals) . ");\n";
                }
            }
            // Samme begrundelse som SQLite-grenen ovenfor - triggere manglede FØR i dumpet.
            $trigRes = mysqli_query($conn, "SHOW TRIGGERS");
            if ($trigRes) {
                while ($trig = mysqli_fetch_assoc($trigRes)) {
                    $createTrig = mysqli_query($conn, "SHOW CREATE TRIGGER `" . $trig['Trigger'] . "`");
                    if ($createTrig) {
                        $ct = mysqli_fetch_assoc($createTrig);
                        if (!empty($ct['SQL Original Statement'])) $sqlDump .= "\n" . $ct['SQL Original Statement'] . ";\n";
                    }
                }
            }
        }
        return $sqlDump;
    }
    // Kun DB-STRUKTUR (CREATE TABLE m.m.), ingen data. Bruges af program-backup,
    // hvor det er skemaet - ikke regnskabsdataene - der skal kunne rulles tilbage
    // sammen med koden. Cross-engine som dump_to_sql().
    public static function dump_schema($conn) {
        global $db_type, $pdo;
        $out = "-- TinyCash Schema (structure only) - Type: $db_type - Date: " . date('Y-m-d H:i:s') . "\n\n";
        if ($db_type === 'sqlite') {
            $res = $pdo->query("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 1 WHEN 'index' THEN 2 ELSE 3 END");
            foreach ($res as $row) {
                $out .= $row['sql'] . ";\n";
            }
        } else {
            $res = mysqli_query($conn, "SHOW TABLES");
            while ($row = mysqli_fetch_row($res)) {
                $table   = $row[0];
                $created = mysqli_fetch_row(mysqli_query($conn, "SHOW CREATE TABLE `$table`"));
                $out .= "\n" . $created[1] . ";\n";
            }
        }
        return $out;
    }
    // Tilføj denne til din DB klasse i inc/db_connect.inc.php
    public static function escape($conn, $string) {
        global $db_type, $pdo;
        if ($db_type === 'sqlite') {
            return str_replace("'", "''", $string); // SQLite escape
        } else {
            return mysqli_real_escape_string($conn, $string); // MySQLi escape
        }
    }
    // Alias for escape(): mange kaldesteder (expense_edit, bank_import, vat_save,
    // auto_backup m.fl.) bruger MySQLi-navnet real_escape_string(). Uden dette gav
    // de "Call to undefined method DB::real_escape_string()". Håndterer både
    // MySQL og SQLite via escape().
    public static function real_escape_string($conn, $string) {
        return self::escape($conn, $string);
    }
    // Tilføj denne til din DB klasse i inc/db_connect.inc.php
    public static function insert_id($conn) {
        global $db_type, $pdo;
        if ($db_type === 'sqlite') {
            return $pdo->lastInsertId();
        } else {
            return mysqli_insert_id($conn);
        }
    }
    // RETTET (§bugs-batch-19-review): manglede helt. Flere steder i koden
    // afgør et kapløbsforsøg ("blev DENNE forespørgsel den der skrev, eller
    // nåede en anden forespørgsel det først?") ved bagefter at SELECT'e og se
    // om den ønskede sluttilstand er opnået - men det tjekker kun OM
    // tilstanden er korrekt, ikke HVEM der satte den. To næsten-samtidige
    // forsøg kan begge se "korrekt sluttilstand" efter en atomisk
    // "UPDATE ... WHERE <forventet gammel tilstand>", selvom kun ét af dem
    // reelt ændrede rækken - taberen så bare vinderens allerede-committede
    // resultat, og ville derfor fejlagtigt tro sit eget forsøg lykkedes.
    // Den eneste pålidelige måde at vide om ens EGEN UPDATE ramte 0 eller 1+
    // rækker, er at spørge databasen om antal berørte rækker fra selve det
    // kald - ikke gætte det ud fra en efterfølgende SELECT. $result skal være
    // værdien returneret af DB::query()/DB::prepare_and_execute() for netop
    // den UPDATE/DELETE, man vil måle.
    public static function affected_rows($conn, $result = null) {
        global $db_type;
        if ($db_type === 'sqlite') {
            return ($result instanceof PDOStatement) ? $result->rowCount() : 0;
        } else {
            return mysqli_affected_rows($conn);
        }
    }
    public static function stmt_bind_param(&$stmt, $types, ...$params) {
        global $db_type;
        if ($db_type !== 'sqlite') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        // SQLite kræver ikke bind_param, da vi binder direkte i execute()
    }
    public static function stmt_execute($stmt, $params = []) {
        global $db_type;
        if ($db_type === 'sqlite') {
            // SQLite: Send parametre med direkte i execute
            return $stmt->execute($params);
        } else {
            // MySQL: Parametre er allerede bundet via bind_param
            return mysqli_stmt_execute($stmt);
        }
    }
}

// Central bogføring af én ledger-linje. Medtager registreringsdato (created_at)
// og bruger-ID KUN hvis kolonnerne findes på ledger-tabellen - så bogføring
// aldrig knækker på en database hvor migrate_ledger_audit.php endnu ikke er
// kørt, men compliance-felterne udfyldes når de er til stede. Cross-engine.
// RETTET (flere regnskaber): fakturaarkivet, e-fakturaerne og SAF-T-arkivet lå i FÆLLES mapper for alle regnskaber
// (storage/invoices/invoice_<nr>.pdf) - og alle regnskaber starter deres nummerserie samme sted. Regnskab B's faktura
// 1001 fandt regnskab A's arkiverede PDF (arkivet skrives kun én gang) - og B's kunde fik A's faktura på mail.
// Hvert regnskab - undtagen det første (standarden, hvis filer allerede ligger i roden) - får sin egen undermappe.
// Returnerer '' eller '<regnskabs-id>/'.
function tc_account_storage_subdir(): string {
    $id = (string)($_SESSION['account_id'] ?? '');
    if ($id === '' || !function_exists('account_list')) return '';
    $all = account_list(true);
    if (count($all) < 2 || (string)($all[0]['id'] ?? '') === $id) return '';
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
    return $safe !== '' ? $safe . '/' : '';
}
// Mappen <base>/<undermappe> - oprettes ved behov
function tc_account_storage_dir(string $base): string {
    $dir = rtrim($base, '/') . '/' . tc_account_storage_subdir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

// Firmaets egen standardtekst til fakturamails - '' når den ikke er sat, eller når den blot er den gamle
// engelske forudfyldning fra Firmaindstillinger ("Please find your invoice attached."), som blev gemt ved
// enhver gemning og overstyrede den oversatte tekst på kundens sprog
function tc_custom_mail_body(array $settings): string {
    $b = trim(str_replace("\r\n", "\n", (string)($settings['default_mail_body'] ?? '')));
    return ($b === '' || $b === "Please find your invoice attached.\n\nBest regards,") ? '' : $b;
}

// RETTET: et kontonummer der IKKE findes i kontoplanen blev bogført uden videre (fx en indstilling der peger
// på standardværdien 5000, som kontoplanen ikke har). Posteringerne forsvandt så stille fra saldobalance,
// balance og hovedbog (som slår kontoen op), og regnskabet balancerede ikke længere i rapporterne. Nu en
// Exception - kalderne bogfører i en transaktion og ruller tilbage med en forklaring.
// $allow_missing: kun for at NULSTILLE en sådan konto (inc/chart_switch.inc.php).
// Hvilke af disse kontonumre findes IKKE i kontoplanen? (til at afvise FØR en transaktion startes)
function ledger_accounts_missing($conn, array $acc_ids): array {
    $missing = [];
    foreach (array_unique(array_map('intval', $acc_ids)) as $a) {
        if ($a <= 0 || !DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = $a"))) $missing[] = $a;
    }
    return $missing;
}
function ledger_post($conn, $jou_id, $acc_id, $amount, bool $allow_missing = false) {
    static $known = [];
    if (!$allow_missing && !isset($known[(int)$acc_id])) {
        if (!DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = " . (int)$acc_id))) {
            $lm_key = '@The account %d does not exist in the chart of accounts - check Company Settings (posting accounts) or the chart of accounts. Nothing was posted.';
            throw new Exception(sprintf(function_exists('lang') ? lang($lm_key) : substr($lm_key, 1), (int)$acc_id));
        }
        $known[(int)$acc_id] = true;
    }
    static $has_audit = null;
    if ($has_audit === null) {
        $has_audit = false;
        if (DB::is_sqlite()) {
            $res = DB::query($conn, "PRAGMA table_info(ledger)");
            if ($res) {
                while ($r = DB::fetch_assoc($res)) {
                    if (strtolower($r['name']) === 'created_at') { $has_audit = true; break; }
                }
            }
        } else {
            $res = DB::query($conn, "SHOW COLUMNS FROM ledger LIKE 'created_at'");
            $has_audit = ($res && DB::num_rows($res) > 0);
        }
    }
    $jou_id = (int)$jou_id;
    $acc_id = (int)$acc_id;
    $amount = (float)$amount;
    if ($has_audit) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        return DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount, created_at, user_id) VALUES ($jou_id, $acc_id, $amount, '$now', $uid)");
    }
    return DB::query($conn, "INSERT INTO ledger (jou_id, acc_id, amount) VALUES ($jou_id, $acc_id, $amount)");
}

// NYT (bruger-anmodet lukning af BEK 811/2026-gennemgangens fund 3, se
// [[digital-standard-bookkeeping-registration-goal]]): journal har ingen
// fælles opretningsfunktion (i modsætning til ledger_post() ovenfor) - hvert
// af de 13 steder i koden, der selv skriver "INSERT INTO journal", bygger sin
// egen SQL-streng fra bunden med sine egne kolonner (trans_type/proj_id
// varierer fra sted til sted). I stedet for at kopiere et fuldt
// findes-kolonnen-tjek ind i alle 13, giver denne lille delte funktion KUN
// den ekstra ", created_by"-kolonne + ", <bruger-id>"-værdi, parate til at
// sættes ind i hvert steds eksisterende kolonne-/værdiliste - samme graceful
// "virker uanset om migrationen er kørt endnu"-filosofi som ledger_post()s
// eget $has_audit-tjek, blot uden at tvinge alle 13 steder til én fælles
// funktionssignatur (hvilket ville have krævet at omskrive hvert steds
// allerede forskelligartede opbygning, med langt højere risiko for fejl i
// midt i regnskabskritisk kode).
function journal_created_by_sql($conn): array {
    static $has = null;
    if ($has === null) {
        $has = false;
        if (DB::is_sqlite()) {
            $res = DB::query($conn, "PRAGMA table_info(journal)");
            if ($res) { while ($r = DB::fetch_assoc($res)) { if (strtolower($r['name']) === 'created_by') { $has = true; break; } } }
        } else {
            $res = DB::query($conn, "SHOW COLUMNS FROM journal LIKE 'created_by'");
            $has = ($res && DB::num_rows($res) > 0);
        }
    }
    if (!$has) return ['col' => '', 'val' => ''];
    return ['col' => ', created_by', 'val' => ', ' . (int)($_SESSION['user_id'] ?? 0)];
}

// Fælles, hulfrit bilagsnummer (voucher_no) på tværs af ALLE posteringstyper
// (fakturaer, kreditnotaer, udgifter, bankafstemning) - jf. bogføringsloven.
// Atomisk via en tæller-tabel (voucher_counter): kaldes inde i den kaldende
// flows egen DB::begin_transaction()/commit(), så en rullet-tilbage postering
// også frigiver nummeret igen (ingen huller fra mislykkede forsøg). Falder
// gracefult tilbage til et best-effort MAX+1 hvis migrationen (db-setup/
// migrate_voucher_counter.php) ikke er kørt endnu, ligesom ledger_post().
function next_voucher_no($conn) {
    static $has_counter = null;
    if ($has_counter === null) {
        if (DB::is_sqlite()) {
            $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='voucher_counter'");
        } else {
            $res = DB::query($conn, "SHOW TABLES LIKE 'voucher_counter'");
        }
        $has_counter = ($res && DB::num_rows($res) > 0);
    }

    if (!$has_counter) {
        return _voucher_fallback_max($conn) + 1;
    }

    $row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM voucher_counter WHERE id = 1"));
    if (!$row) {
        // Tabellen findes, men er ikke seedet endnu - seed den defensivt.
        DB::insert($conn, 'voucher_counter', ['id' => 1, 'next_no' => _voucher_fallback_max($conn) + 1]);
    }

    // UPDATE'en tager en rækkelås (MySQL InnoDB) / eksklusiv skrivelås (SQLite)
    // inden for den kaldende transaktion, så to samtidige posteringer aldrig
    // kan få samme nummer.
    DB::query($conn, "UPDATE voucher_counter SET next_no = next_no + 1 WHERE id = 1");
    $row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM voucher_counter WHERE id = 1"));
    return (int)$row['next_no'] - 1;
}

function _voucher_fallback_max($conn) {
    $mj = DB::fetch_assoc(DB::query($conn, "SELECT MAX(voucher_no) AS m FROM journal"));
    $me = DB::fetch_assoc(DB::query($conn, "SELECT MAX(voucher_no) AS m FROM expenses"));
    $mi = DB::fetch_assoc(DB::query($conn, "SELECT MAX(invoice_no) AS m FROM invoices"));
    return max((int)($mj['m'] ?? 0), (int)($me['m'] ?? 0), (int)($mi['m'] ?? 0));
}

// Det kundevendte fakturanummer (invoice_no) - SEPARAT fra voucher_no (det
// interne, fælles bilagsnummer på tværs af alle posteringstyper). Brugte før
// et "SELECT MAX(invoice_no)+1"-mønster i invoice_post_action.php, som er
// sårbart over for et kapløb: to samtidige bogføringer kunne i teorien læse
// samme MAX() før nogen af dem når at skrive, og få samme nummer - der er
// heller ingen UNIQUE-begrænsning på kolonnen som sidste sikkerhedsnet.
// Samme atomiske mønster som next_voucher_no() ovenfor, egen tæller-tabel
// (invoice_no_counter) så de to nummerserier ikke blander sig. Fundet ved en
// faktura-/fakturaflow-gennemgang.
function next_invoice_no($conn) {
    static $has_counter = null;
    if ($has_counter === null) {
        if (DB::is_sqlite()) {
            $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='invoice_no_counter'");
        } else {
            $res = DB::query($conn, "SHOW TABLES LIKE 'invoice_no_counter'");
        }
        $has_counter = ($res && DB::num_rows($res) > 0);
    }

    if (!$has_counter) {
        return _invoice_no_fallback_max($conn) + 1;
    }

    $row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM invoice_no_counter WHERE id = 1"));
    if (!$row) {
        // Tabellen findes, men er ikke seedet endnu - seed den defensivt,
        // startende fra 1001 hvis der slet ingen fakturaer er endnu (samme
        // startpunkt invoice_post_action.php altid har brugt).
        $start = max(_invoice_no_fallback_max($conn) + 1, 1001);
        DB::insert($conn, 'invoice_no_counter', ['id' => 1, 'next_no' => $start]);
    }

    // Samme atomiske UPDATE-mønster som next_voucher_no().
    DB::query($conn, "UPDATE invoice_no_counter SET next_no = next_no + 1 WHERE id = 1");
    $row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM invoice_no_counter WHERE id = 1"));
    return (int)$row['next_no'] - 1;
}

function _invoice_no_fallback_max($conn) {
    $mi = DB::fetch_assoc(DB::query($conn, "SELECT MAX(invoice_no) AS m FROM invoices"));
    return max((int)($mi['m'] ?? 0), 1000);
}

// NY (bruger-anmodet, "kan bogholderen ønske sig mere", runde 33): en
// kreditnota (invoice_credit.php) fik aldrig sit eget kundevendte
// fakturanummer - kun invoice_no=NULL, hvilket fik invoice_view.php til at
// vise "@DRAFT" for en fuldt bogført kreditnota. Egen, separat nummerserie
// (credit_note_no_counter) - samme atomiske mønster som next_invoice_no()
// ovenfor - så kreditnotaer og almindelige fakturaer aldrig deler numre.
function next_credit_note_no($conn) {
    static $has_counter = null;
    if ($has_counter === null) {
        if (DB::is_sqlite()) {
            $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='credit_note_no_counter'");
        } else {
            $res = DB::query($conn, "SHOW TABLES LIKE 'credit_note_no_counter'");
        }
        $has_counter = ($res && DB::num_rows($res) > 0);
    }

    if (!$has_counter) {
        return null; // migration ikke kørt endnu - stille degradering, samme mønster som andre nyere kolonner
    }

    $row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM credit_note_no_counter WHERE id = 1"));
    if (!$row) {
        DB::insert($conn, 'credit_note_no_counter', ['id' => 1, 'next_no' => 1]);
    }

    DB::query($conn, "UPDATE credit_note_no_counter SET next_no = next_no + 1 WHERE id = 1");
    $row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM credit_note_no_counter WHERE id = 1"));
    return (int)$row['next_no'] - 1;
}

// NY (§reel-multi-valuta-bogforing): fælles, ENESTE beregning af en fakturas
// bogførte DKK-total (netto/moms/inkl.) - genbruges nu af BÅDE invoice_post_
// action.php (selve bogføringen) OG reconcile_action.php (afstemning). Var
// FØR duplikeret i begge filer, men reconcile_action.php's kopi manglede
// selve gange-med-exch_rate-trinnet: for en almindelig DKK-faktura er det
// harmløst (intet exch_rate sat), men for en udenlandsk faktura sammenlignede
// reconcile_action.php et rent EUR/USD-beløb (fra invoice_lines) direkte mod
// de faktisk indbetalte DKK-beløb (fra bankafstemningen) - to forskellige
// valutaenheder sammenlignet som var de samme tal. Konsekvens: en 1.000 EUR-
// faktura (7.450 DKK bogført) blev markeret 'paid' efter blot én DKK-
// bankindbetaling på 1.000 DKK (langt fra hele beløbet), eller omvendt aldrig
// nåede 'paid' selvom kunden reelt havde betalt fuldt ud i EUR. Fundet under
// arbejdet med reel kursgevinst/-tab-håndtering ved betaling - den samme
// beregning skal nødvendigvis være korrekt begge steder, ellers giver en
// kursregulering ved afstemning ingen mening.
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfri rabat pr.
// linje (invoice_lines.discount_pct, db-setup/migrate_invoice_line_
// discount.php) - trækkes fra NETTOBELØBET før moms beregnes, samme
// rækkefølge som standard dansk fakturapraksis. Udtrukket til to delte
// hjælpefunktioner (samme "udtræk til fælles funktion for aldrig at kunne
// glide fra hinanden"-mønster som denne sessions øvrige delte beregninger),
// da MANGE steder i kodebasen selv genberegner en linje-/fakturatotal
// direkte fra invoice_lines i stedet for at kalde invoice_dkk_totals() (som
// kun tager ét inv_id ad gangen, upraktisk for en rapport der summerer på
// tværs af mange fakturaer) - se aging_report.php/index.php/project_view.php/
// reconcile_list.php/recurring_invoices.php/reminder_action.php/reminders.php/
// subledger_reconciliation.php for de øvrige kaldesteder, alle rettet i
// samme runde. Samme "stille degradering hvis ikke migreret endnu"-mønster
// som andre nyere kolonner - discount_pct behandles som 0 (ingen rabat)
// hvis kolonnen ikke findes endnu.
function invoice_lines_has_discount_col($conn): bool {
    static $has = null;
    if ($has === null) {
        $has = false;
        if (DB::is_sqlite()) {
            $dcc = DB::query($conn, "PRAGMA table_info(invoice_lines)");
            if ($dcc) { while ($dccr = DB::fetch_assoc($dcc)) { if ($dccr['name'] === 'discount_pct') { $has = true; break; } } }
        } else {
            $dcc = DB::query($conn, "SHOW COLUMNS FROM invoice_lines LIKE 'discount_pct'");
            $has = ($dcc && DB::num_rows($dcc) > 0);
        }
    }
    return $has;
}

// Returnerer SQL-fragmentet der ganger et linjebeløb ned med rabatten - eller
// en tom streng, hvis discount_pct-kolonnen slet ikke findes endnu. $prefix
// er tabel-aliaset i den konkrete forespørgsel (fx "il." i et JOIN), inkl.
// det afsluttende punktum, eller en tom streng hvis der ikke joines.
function invoice_discount_sql($conn, string $prefix = ''): string {
    return invoice_lines_has_discount_col($conn) ? "* (100 - COALESCE({$prefix}discount_pct, 0)) / 100.0" : "";
}

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 30): en
// udgift kan nu VALGFRIT splittes på flere konti via expense_lines - se
// db-setup/migrate_expense_lines.php. expense_get_lines() er den ENESTE
// autoritative kilde til "hvilke konti/beløb hører til denne udgift" -
// bruges konsekvent af både selve bogføringen (expense_edit.php) og
// momsafstemningen (vat_reconciliation.php), for at undgå at de to
// nogensinde kan regne forskelligt (samme princip som invoice_dkk_totals()
// ovenfor). Returnerer ALTID mindst én "linje" - findes ingen rigtige
// expense_lines-rækker for denne udgift (langt de fleste, bagudkompatibelt),
// bygges én SYNTETISK linje af selve expense-rækkens egne account_id/
// amount/vat_rate, så en kaldende funktion aldrig behøver et separat
// if/else for "har linjer eller ej".
function expense_lines_has_table($conn): bool {
    static $has = null;
    if ($has === null) {
        if (DB::is_sqlite()) {
            $r = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='expense_lines'");
            $has = ($r && DB::fetch_assoc($r)) ? true : false;
        } else {
            $r = DB::query($conn, "SHOW TABLES LIKE 'expense_lines'");
            $has = ($r && DB::num_rows($r) > 0);
        }
    }
    return $has;
}

function expense_get_lines($conn, int $exp_id): array {
    $rows = [];
    if (expense_lines_has_table($conn)) {
        $res = DB::query($conn, "SELECT account_id, amount, line_vat_rate, description FROM expense_lines WHERE exp_id = $exp_id ORDER BY line_id ASC");
        if ($res) { while ($r = DB::fetch_assoc($res)) { $rows[] = $r; } }
    }
    if (empty($rows)) {
        $exp = DB::fetch_assoc(DB::query($conn, "SELECT account_id, amount, vat_rate FROM expenses WHERE exp_id = $exp_id"));
        if (!$exp) return [];
        $rows = [['account_id' => $exp['account_id'], 'amount' => $exp['amount'], 'line_vat_rate' => $exp['vat_rate'], 'description' => null]];
    }

    // Løs hver linjes momssats: linjens egen, ellers dens konto standardsats
    // (samme "pr.-post override, ellers kontoens standard"-mønster som
    // expenses.vat_rate/accounts.vat_rate allerede brugte for én-konto-udgifter).
    $result = [];
    foreach ($rows as $r) {
        $acc_id = (int)$r['account_id'];
        $gross  = (float)$r['amount'];
        $rate   = (isset($r['line_vat_rate']) && $r['line_vat_rate'] !== null && $r['line_vat_rate'] !== '') ? (float)$r['line_vat_rate'] : null;
        if ($rate === null) {
            $acc_row = DB::fetch_assoc(DB::query($conn, "SELECT vat_rate FROM accounts WHERE acc_id = $acc_id"));
            $rate = (float)($acc_row['vat_rate'] ?? 0);
        }
        $vat = ($rate > 0) ? round($gross * $rate / (100 + $rate), 2) : 0.0;
        $net = round($gross - $vat, 2);
        $result[] = ['account_id' => $acc_id, 'gross' => $gross, 'vat_rate' => $rate, 'vat' => $vat, 'net' => $net, 'description' => $r['description'] ?? null];
    }
    return $result;
}

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 31): "Slet"
// på en kunde/leverandør er nu en gendannelig blødsletning (deleted_at) -
// se db-setup/migrate_trash_bin.php/trash_bin.php. Disse to hjælpefunktioner
// er den ENESTE autoritative kilde til "skal denne kunde/leverandør vises i
// almindelige lister/dropdowns" - bruges konsekvent alle steder en kunde/
// leverandør vælges eller listes, for at undgå at en blødslettet række dukker
// op ét sted men ikke et andet. Stille degraderet til "vis alle" (tom streng)
// hvis migrationen ikke er kørt endnu.
function customers_active_filter($conn, string $prefix = ''): string {
    static $has = null;
    if ($has === null) {
        if (DB::is_sqlite()) {
            $r = DB::query($conn, "PRAGMA table_info(customers)");
            $has = false;
            if ($r) { while ($row = DB::fetch_assoc($r)) { if ($row['name'] === 'deleted_at') { $has = true; break; } } }
        } else {
            $r = DB::query($conn, "SHOW COLUMNS FROM customers LIKE 'deleted_at'");
            $has = ($r && DB::num_rows($r) > 0);
        }
    }
    return $has ? "{$prefix}deleted_at IS NULL" : "1=1";
}

function suppliers_active_filter($conn, string $prefix = ''): string {
    static $has = null;
    if ($has === null) {
        if (DB::is_sqlite()) {
            $r = DB::query($conn, "PRAGMA table_info(suppliers)");
            $has = false;
            if ($r) { while ($row = DB::fetch_assoc($r)) { if ($row['name'] === 'deleted_at') { $has = true; break; } } }
        } else {
            $r = DB::query($conn, "SHOW COLUMNS FROM suppliers LIKE 'deleted_at'");
            $has = ($r && DB::num_rows($r) > 0);
        }
    }
    return $has ? "{$prefix}deleted_at IS NULL" : "1=1";
}

function invoice_dkk_totals($conn, int $inv_id): array {
    $disc_factor = invoice_discount_sql($conn);
    $tot_row = DB::fetch_assoc(DB::query($conn,
        "SELECT COALESCE(SUM(quantity * price_each $disc_factor), 0) AS net,
                COALESCE(SUM(quantity * price_each $disc_factor * line_vat_rate / 100.0), 0) AS vat
         FROM invoice_lines WHERE inv_id = $inv_id"));
    // Beløbene er i FAKTURAENS EGEN valuta her (den linjerne er indtastet i) -
    // afrundes til øre i den valuta først, samme rækkefølge som invoice_post_
    // action.php altid har brugt (undgår binær flydende-komma-støj i SUM()).
    $total_excl = round((float)($tot_row['net'] ?? 0), 2);
    $total_vat  = round((float)($tot_row['vat'] ?? 0), 2);

    $exch_row  = DB::fetch_assoc(DB::query($conn, "SELECT exch_rate FROM invoices WHERE inv_id = $inv_id"));
    $exch_rate = (float)($exch_row['exch_rate'] ?? 0);
    if ($exch_rate > 0) {
        $total_excl = round($total_excl * $exch_rate, 2);
        $total_vat  = round($total_vat * $exch_rate, 2);
    }
    $total_incl = round($total_excl + $total_vat, 2);

    return ['excl' => $total_excl, 'vat' => $total_vat, 'incl' => $total_incl, 'exch_rate' => $exch_rate];
}

// DELVIS KREDITNOTA: en faktura der kun er krediteret for en del, beholder sin status (sendt/betalt) - det
// kunden stadig skylder er fakturaen + dens kreditnota(er) (negative) - betalingerne. RETTET: før blev hele
// fakturaen markeret 'credited', og restbeløbet forsvandt fra debitorer, rykkere og bankafstemning.
// Kreditnotaernes beløb (DKK inkl. moms, negativt) for en faktura:
function invoice_credited_dkk($conn, int $inv_id): float {
    $sum = 0.0;
    $res = DB::query($conn, "SELECT inv_id FROM invoices WHERE credit_ref = $inv_id");
    if ($res) while ($r = DB::fetch_assoc($res)) $sum += invoice_dkk_totals($conn, (int)$r['inv_id'])['incl'];
    return round($sum, 2);
}
// Det åbne beløb på en faktura (DKK): total + kreditnotaer - betalinger
function invoice_open_dkk($conn, int $inv_id): float {
    $paid = DB::fetch_assoc(DB::query($conn, "SELECT COALESCE(SUM(amount), 0) AS paid FROM invoice_payments WHERE inv_id = $inv_id"));
    return round(invoice_dkk_totals($conn, $inv_id)['incl'] + invoice_credited_dkk($conn, $inv_id) - (float)($paid['paid'] ?? 0), 2);
}
// Samme kreditnotabeløb som et SQL-udtryk til lister (fakturaen har alias $alias)
function invoice_credited_sql($conn, string $alias = 'i'): string {
    $disc = invoice_discount_sql($conn, 'cl.');
    return "(SELECT COALESCE(SUM(cl.quantity * cl.price_each $disc * (100 + cl.line_vat_rate) / 100.0 * COALESCE(NULLIF(ci.exch_rate, 0), 1)), 0)"
         . " FROM invoices ci JOIN invoice_lines cl ON cl.inv_id = ci.inv_id WHERE ci.credit_ref = $alias.inv_id)";
}

// Nummerserie til Tilbud/Ordrebekræftelse (quotes) - egen tæller
// (quote_no_counter), adskilt fra invoice_no_counter, så et tilbuds nummer
// aldrig kan kollidere med eller "bruge af" den rigtige fakturaserie. I
// modsætning til next_invoice_no() (som bevidst kun tildeles ved selve
// BOGFØRINGEN, af hensyn til bogføringsloven) tildeles quote_no med det
// samme ved oprettelse - et tilbud er intet regnskabsdokument, så der er
// ingen tilsvarende juridisk grund til at vente.
function next_quote_no($conn) {
    static $has_counter = null;
    if ($has_counter === null) {
        if (DB::is_sqlite()) {
            $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='quote_no_counter'");
        } else {
            $res = DB::query($conn, "SHOW TABLES LIKE 'quote_no_counter'");
        }
        $has_counter = ($res && DB::num_rows($res) > 0);
    }

    if (!$has_counter) {
        $mq = DB::fetch_assoc(DB::query($conn, "SELECT MAX(quote_no) AS m FROM quotes"));
        return (int)($mq['m'] ?? 0) + 1;
    }

    $row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM quote_no_counter WHERE id = 1"));
    if (!$row) {
        DB::insert($conn, 'quote_no_counter', ['id' => 1, 'next_no' => 1]);
    }

    // Samme atomiske UPDATE-mønster som next_voucher_no()/next_invoice_no().
    DB::query($conn, "UPDATE quote_no_counter SET next_no = next_no + 1 WHERE id = 1");
    $row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM quote_no_counter WHERE id = 1"));
    return (int)$row['next_no'] - 1;
}

// -----------------------------------------------------------------------
// DB-NIVEAU APPEND-ONLY PÅ HOVEDBOGEN (bogføringsloven)
// -----------------------------------------------------------------------
// Delt mellem db-setup/migrate_append_only_ledger.php (eksisterende
// installationer) og create_all_tables.core.php (friske installationer),
// så de to steder ikke kan glide fra hinanden. Se migrate_append_only_
// ledger.php's egen, udførlige kommentar for den fulde begrundelse for
// hvert enkelt tjek. Bevidst "best effort" - kaster ALDRIG en fejl videre,
// da fraværet af triggere ikke må forhindre selve installationen/
// migrationen i at gennemføre (PHP-lagets tjek er stadig det primære værn).
// RETTET 2026-08-20 (fundet under den systematiske migrations-gennemgang
// efter migration_credit.php's flerlagsfejl): returnerede FØR kun et
// heltal (antal oprettet). Kaldende kode (migrate_append_only_ledger.php)
// tolkede "0" som "alle findes allerede" - men 0 kunne LIGE SÅ GODT betyde
// "alle 4 forsøg fejlede stille" (fx manglende TRIGGER-rettighed, ikke
// ualmindeligt på delt hosting) - fejlen forsvandt kun i PHP's egen
// error_log, usynlig for den admin der kører migrationen. Returnerer nu et
// array med både antal oprettet OG en liste over hvad der reelt fejlede,
// så kaldende kode kan vise en ærlig besked i stedet for en falsk
// "allerede anvendt".
function create_append_only_triggers($conn): array {
    global $pdo, $db_type;
    $created = 0;
    $failed = [];

    // NYT (bruger-anmodet lukning af BEK 811/2026-gennemgangens fund 1+3,
    // se [[digital-standard-bookkeeping-registration-goal]]): to tilføjelser
    // til denne allerede eksisterende funktion:
    // (a) trg_expenses_*-triggerne nedenfor - "ingen database-trigger på
    //     expenses"-hullet. expense_edit.php havde allerede PRÆCIS samme
    //     felt-begrænsning i PHP-laget (beløb/konto/dato/type låst, kun
    //     leverandør/beskrivelse/projekt frit redigerbart på en bogført
    //     udgift) - denne trigger håndhæver nu DEN SAMME, allerede besluttede
    //     politik på selve databaseniveauet, samme mønster som journal/ledger
    //     allerede fik 2026-08-20.
    // (b) journal.created_by-tjekket i selve trg_journal_restrict_update_
    //     posted - "intet created_by på journal"-hullet er lukket ved at
    //     tilføje kolonnen (se db-setup/migrate_journal_created_by.php) og nu
    //     ogsaa beskytte den her. $journal_has_created_by afgør om selve
    //     check'et inkluderes i trigger-SQL'en - uden dette tjek ville selve
    //     CREATE TRIGGER-kaldet fejle øjeblikkeligt på en installation hvor
    //     kolonne-migrationen (endnu) ikke er kørt, da kolonnen slet ikke
    //     findes i tabellen trigger'en gælder for.
    //
    // RETTET SAMTIDIG: funktionen sprang FØR enhver trigger allerede fundet
    // ved navn helt over (både SQLite- og MySQL-grenen) - en ÆNDRET
    // trigger-definition (som netop journal-opdateringen her) ville derfor
    // ALDRIG slå igennem på en installation, der allerede havde den gamle
    // udgave oprettet, uanset hvor mange gange migrationen blev kørt igen.
    // Ubetinget DROP+CREATE (samme to kald, blot uden "spring over hvis
    // findes"-tjekket foran) gør funktionen reelt idempotent OGSÅ ved
    // fremtidige definitionsændringer, ikke kun ved førstegangsoprettelse.
    $journal_has_created_by = false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(journal)");
        if ($res) { while ($r = DB::fetch_assoc($res)) { if (strtolower($r['name']) === 'created_by') { $journal_has_created_by = true; break; } } }
    } else {
        $res = DB::query($conn, "SHOW COLUMNS FROM journal LIKE 'created_by'");
        $journal_has_created_by = ($res && DB::num_rows($res) > 0);
    }

    if ($db_type === 'sqlite') {
        $jou_created_by_chk = $journal_has_created_by ? " OR NEW.created_by IS NOT OLD.created_by" : "";
        $triggers = [
            'trg_journal_no_delete_posted' => "
                CREATE TRIGGER trg_journal_no_delete_posted
                BEFORE DELETE ON journal FOR EACH ROW
                WHEN OLD.voucher_no IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'Bogforte journalposter kan ikke slettes (bogforingsloven) - brug Annuller i stedet'); END;",
            'trg_journal_restrict_update_posted' => "
                CREATE TRIGGER trg_journal_restrict_update_posted
                BEFORE UPDATE ON journal FOR EACH ROW
                WHEN OLD.voucher_no IS NOT NULL AND (
                    NEW.voucher_no IS NOT OLD.voucher_no OR NEW.jou_date IS NOT OLD.jou_date OR
                    NEW.created_at IS NOT OLD.created_at OR NEW.trans_type IS NOT OLD.trans_type OR
                    NEW.currency IS NOT OLD.currency OR (OLD.is_cancelled = 1 AND NEW.is_cancelled = 0)
                    $jou_created_by_chk
                )
                BEGIN SELECT RAISE(ABORT, 'Bogforte journalposter kan kun rettes i tekst/projekt eller annulleres (bogforingsloven)'); END;",
            'trg_ledger_no_delete_posted' => "
                CREATE TRIGGER trg_ledger_no_delete_posted
                BEFORE DELETE ON ledger FOR EACH ROW
                WHEN (SELECT voucher_no FROM journal WHERE jou_id = OLD.jou_id) IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'Bogforte hovedbogslinjer kan ikke slettes (bogforingsloven)'); END;",
            'trg_ledger_no_update_posted' => "
                CREATE TRIGGER trg_ledger_no_update_posted
                BEFORE UPDATE ON ledger FOR EACH ROW
                WHEN (SELECT voucher_no FROM journal WHERE jou_id = OLD.jou_id) IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'Bogforte hovedbogslinjer kan ikke rettes (bogforingsloven)'); END;",
            'trg_expenses_no_delete_posted' => "
                CREATE TRIGGER trg_expenses_no_delete_posted
                BEFORE DELETE ON expenses FOR EACH ROW
                WHEN OLD.voucher_no IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'Bogforte udgifter kan ikke slettes (bogforingsloven) - brug Annuller i stedet'); END;",
            'trg_expenses_restrict_update_posted' => "
                CREATE TRIGGER trg_expenses_restrict_update_posted
                BEFORE UPDATE ON expenses FOR EACH ROW
                WHEN OLD.voucher_no IS NOT NULL AND (
                    NEW.voucher_no IS NOT OLD.voucher_no OR NEW.exp_date IS NOT OLD.exp_date OR
                    NEW.account_id IS NOT OLD.account_id OR NEW.amount IS NOT OLD.amount OR
                    NEW.vat_rate IS NOT OLD.vat_rate OR NEW.currency IS NOT OLD.currency OR
                    NEW.exp_type IS NOT OLD.exp_type OR NEW.orig_currency IS NOT OLD.orig_currency OR
                    NEW.orig_amount IS NOT OLD.orig_amount OR NEW.exch_rate IS NOT OLD.exch_rate OR
                    NEW.created_at IS NOT OLD.created_at OR NEW.created_by IS NOT OLD.created_by OR
                    (OLD.is_cancelled = 1 AND NEW.is_cancelled = 0)
                )
                BEGIN SELECT RAISE(ABORT, 'Bogforte udgifter kan kun rettes i leverandor/beskrivelse/projekt/bilag eller annulleres (bogforingsloven)'); END;",
        ];
        foreach ($triggers as $name => $sql) {
            try {
                @$pdo->exec("DROP TRIGGER IF EXISTS $name");
                $pdo->exec($sql);
                $created++;
            } catch (Exception $e) {
                error_log("create_append_only_triggers: $name fejlede: " . $e->getMessage());
                $failed[$name] = $e->getMessage();
            }
        }
    } else {
        $jou_created_by_chk = $journal_has_created_by ? " OR NOT (NEW.created_by <=> OLD.created_by)" : "";
        $triggers = [
            'trg_journal_no_delete_posted' => "
                CREATE TRIGGER trg_journal_no_delete_posted BEFORE DELETE ON journal FOR EACH ROW
                BEGIN IF OLD.voucher_no IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bogforte journalposter kan ikke slettes (bogforingsloven) - brug Annuller i stedet';
                END IF; END",
            'trg_journal_restrict_update_posted' => "
                CREATE TRIGGER trg_journal_restrict_update_posted BEFORE UPDATE ON journal FOR EACH ROW
                BEGIN IF OLD.voucher_no IS NOT NULL AND (
                    NOT (NEW.voucher_no <=> OLD.voucher_no) OR NOT (NEW.jou_date <=> OLD.jou_date) OR
                    NOT (NEW.created_at <=> OLD.created_at) OR NOT (NEW.trans_type <=> OLD.trans_type) OR
                    NOT (NEW.currency <=> OLD.currency) OR (OLD.is_cancelled = 1 AND NEW.is_cancelled = 0)
                    $jou_created_by_chk
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bogforte journalposter kan kun rettes i tekst/projekt eller annulleres (bogforingsloven)';
                END IF; END",
            'trg_ledger_no_delete_posted' => "
                CREATE TRIGGER trg_ledger_no_delete_posted BEFORE DELETE ON ledger FOR EACH ROW
                BEGIN IF (SELECT voucher_no FROM journal WHERE jou_id = OLD.jou_id) IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bogforte hovedbogslinjer kan ikke slettes (bogforingsloven)';
                END IF; END",
            'trg_ledger_no_update_posted' => "
                CREATE TRIGGER trg_ledger_no_update_posted BEFORE UPDATE ON ledger FOR EACH ROW
                BEGIN IF (SELECT voucher_no FROM journal WHERE jou_id = OLD.jou_id) IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bogforte hovedbogslinjer kan ikke rettes (bogforingsloven)';
                END IF; END",
            'trg_expenses_no_delete_posted' => "
                CREATE TRIGGER trg_expenses_no_delete_posted BEFORE DELETE ON expenses FOR EACH ROW
                BEGIN IF OLD.voucher_no IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bogforte udgifter kan ikke slettes (bogforingsloven) - brug Annuller i stedet';
                END IF; END",
            'trg_expenses_restrict_update_posted' => "
                CREATE TRIGGER trg_expenses_restrict_update_posted BEFORE UPDATE ON expenses FOR EACH ROW
                BEGIN IF OLD.voucher_no IS NOT NULL AND (
                    NOT (NEW.voucher_no <=> OLD.voucher_no) OR NOT (NEW.exp_date <=> OLD.exp_date) OR
                    NOT (NEW.account_id <=> OLD.account_id) OR NOT (NEW.amount <=> OLD.amount) OR
                    NOT (NEW.vat_rate <=> OLD.vat_rate) OR NOT (NEW.currency <=> OLD.currency) OR
                    NOT (NEW.exp_type <=> OLD.exp_type) OR NOT (NEW.orig_currency <=> OLD.orig_currency) OR
                    NOT (NEW.orig_amount <=> OLD.orig_amount) OR NOT (NEW.exch_rate <=> OLD.exch_rate) OR
                    NOT (NEW.created_at <=> OLD.created_at) OR NOT (NEW.created_by <=> OLD.created_by) OR
                    (OLD.is_cancelled = 1 AND NEW.is_cancelled = 0)
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bogforte udgifter kan kun rettes i leverandor/beskrivelse/projekt/bilag eller annulleres (bogforingsloven)';
                END IF; END",
        ];
        foreach ($triggers as $name => $sql) {
            // RETTET (fundet ved samme sweep som account_manage.php's
            // database-opdagelse/inc/db_connect.inc.php's MySQL-forbindelse):
            // denne løkke havde INGEN try/catch, i modsætning til SQLite-
            // grenen ovenfor - stod derfor i direkte modstrid med funktionens
            // egen "kaster ALDRIG en fejl videre"-kontrakt lige herover.
            // Siden PHP 8.1 KASTER mysqli_query() m.fl. en mysqli_sql_
            // exception ved fejl i stedet for at returnere false (bekræftet
            // direkte, samme rodårsag som de to andre rettelser) - og den
            // helt konkrete fejl kommentaren selv nævner ("manglende TRIGGER-
            // rettighed, ikke ualmindeligt på delt hosting") ville derfor
            // have væltet HELE create_all_tables_for()/en frisk MySQL-
            // installation, i stedet for blot at springe triggerne over som
            // tilsigtet. Fanger \Throwable (ikke kun \Exception) - et hurtigt
            // sidetjek under selve rettelsen viste at et FORKERT brugt
            // mysqli-objekt kan kaste et rent \Error i stedet for en
            // mysqli_sql_exception; funktionens eget løfte er "aldrig videre
            // uanset hvad", så fangnettet skal dække begge klasser.
            try {
                // RETTET (se funktionens egen kommentar foroven): "SHOW
                // TRIGGERS... hvis findes, spring over"-tjekket er fjernet -
                // DROP+CREATE køres nu ubetinget hver gang, så en ÆNDRET
                // trigger-definition (fx journal-opdateringens nye created_by-
                // tjek) rent faktisk slår igennem ved en fornyet kørsel.
                @mysqli_query($conn, "DROP TRIGGER IF EXISTS `$name`");
                if (mysqli_query($conn, $sql)) {
                    $created++;
                } else {
                    $err = mysqli_error($conn);
                    error_log("create_append_only_triggers: $name fejlede: " . $err);
                    $failed[$name] = $err;
                }
            } catch (\Throwable $e) {
                error_log("create_append_only_triggers: $name fejlede: " . $e->getMessage());
                $failed[$name] = $e->getMessage();
            }
        }
    }
    return ['created' => $created, 'failed' => $failed];
}

// RETTET (bruger-rapport "oversættelsesforslag er stadig blankt"): sporet til
// at INGEN af de tre steder i projektet, der laver udgående HTTPS-curl-kald
// (translation_manager.php's OpenAI-kald, inc/help.lib.php's to OpenAI-kald,
// inc/enablebanking.lib.php's bank-API-kald), satte CURLOPT_CAINFO - på en
// server/udviklingsmaskine uden en systemkonfigureret CA-rodcertifikat-liste
// (bekræftet konkret her: både curl.cainfo og openssl.cafile er ukommenteret/
// tomme i php.ini) fejler ETHVERT sådant kald med curl-fejl 60 ("unable to
// get local issuer certificate"). translation_manager.php's egen
// getAiSuggestion() fangede fejlen og faldt tavst tilbage til den urørte
// engelske nøgle i stedet for et rigtigt AI-forslag - så det SÅ ud som om
// funktionen "virkede" (success:true), men gav aldrig en reel oversættelse,
// hvilket brugeren oplevede som at forslaget "stadig er blankt".
//
// Fælles løsning: bundler selv en ajourført CA-rodcertifikat-liste
// (Mozillas, hentet fra curl.se/ca/cacert.pem, samme kilde curl selv
// anbefaler) i inc/cacert.pem, og peger eksplicit på den her - så alle tre
// kaldssteder virker uafhængigt af den underliggende servers php.ini, uanset
// om den kører på Windows (hvor dette langt fra er ualmindeligt at mangle)
// eller Linux. tc_curl_init() ligger i denne fil, fordi db_connect.inc.php
// er det ene include, alle sider (og alle tre kaldssteder) reelt har til
// fælles - php2htm.lib.php er det ikke (se fx bank_integration_connect.php).
function tc_curl_init(string $url) {
    $ch = curl_init($url);
    $cacert = __DIR__ . '/cacert.pem';
    if (is_file($cacert)) {
        curl_setopt($ch, CURLOPT_CAINFO, $cacert);
        curl_setopt($ch, CURLOPT_CAPATH, dirname($cacert));
    }
    return $ch;
}

?>