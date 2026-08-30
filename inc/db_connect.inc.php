<?php # /inc/db_connect.inc.php v:1.3.0 d:2026-08-30 i:evs
# dump_to_sql() medtager nu triggere - manglede FØR helt, så en gendannelse ville stille fjerne append-only-beskyttelsen på journal/ledger igen
# v1.3.0: DB::fetch_assoc()/fetch_row()/num_rows()/free_result() crashede med
# et fatal error ("Call to a member function fetch() on bool") hvis den givne
# forespørgsel fejlede (fx en manglende tabel/kolonne på en installation der
# mangler migrationer) - ramte en bruger reelt via invoice_view.php. Guard
# tilføjet alle fire steder, se kommentar ved fetch_assoc().
# v1.4.0: tilføjet next_invoice_no() - invoice_post_action.php brugte før et
# kapløbs-sårbart "MAX(invoice_no)+1"-mønster. Se kommentar ved funktionen.
define('APP_VERSION', '1.2.3');
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

// Erstat din nuværende is_date_locked med denne mere robuste version
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
function ledger_post($conn, $jou_id, $acc_id, $amount) {
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
function invoice_dkk_totals($conn, int $inv_id): array {
    $tot_row = DB::fetch_assoc(DB::query($conn,
        "SELECT COALESCE(SUM(quantity * price_each), 0) AS net,
                COALESCE(SUM(quantity * price_each * line_vat_rate / 100.0), 0) AS vat
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

    if ($db_type === 'sqlite') {
        $existing = $pdo->query("SELECT name FROM sqlite_master WHERE type='trigger'")->fetchAll(PDO::FETCH_COLUMN);
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
        ];
        foreach ($triggers as $name => $sql) {
            if (in_array($name, $existing, true)) continue;
            try { $pdo->exec($sql); $created++; } catch (Exception $e) {
                error_log("create_append_only_triggers: $name fejlede: " . $e->getMessage());
                $failed[$name] = $e->getMessage();
            }
        }
    } else {
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
                $check = mysqli_query($conn, "SHOW TRIGGERS WHERE `Trigger` = '$name'");
                if ($check && mysqli_num_rows($check) > 0) continue;
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