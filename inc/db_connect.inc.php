<?php # inc/db_connect.inc.php v:1.2.0 d:2026-08-11 i:evs 
define('APP_VERSION', '1.2.0');
define('APP_DATE', '2026-08-11');
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
$env_file = null;
$SøgeStier = [
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

    $conn = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);
    
    if (!$conn) {
        die("MySQL forbindelse fejlede: " . mysqli_connect_error());
    }
    mysqli_set_charset($conn, "utf8mb4");
}


ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/system_errors.log'); 

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

// --- 7. URL-KONTROL TIL MAILHENTNING ---
if (isset($_GET['CheckMail']) && $_GET['CheckMail'] === 'true') {
    $sync_script = __DIR__ . '/depot_sync.php';
    if (file_exists($sync_script)) {
        require_once $sync_script;
        echo "<div style='background:#2ecc71; color:white; padding:10px; text-align:center; font-family:sans-serif;'>E-mail synkronisering fuldført!</div>";
    } else {
        echo "<div style='background:#e74c3c; color:white; padding:10px; text-align:center; font-family:sans-serif;'>Fejl: depot_sync.php blev ikke fundet i inc/ mappen.</div>";
    }
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
    public static function fetch_assoc($result) {
        global $db_type;
        if ($result instanceof SQLiteBufferedResult) return $result->fetch('assoc');
        return ($db_type === 'sqlite') ? $result->fetch(PDO::FETCH_ASSOC) : mysqli_fetch_assoc($result);
    }
    public static function fetch_row($result) {
        global $db_type;
        if ($result instanceof SQLiteBufferedResult) return $result->fetch('num');
        return ($db_type === 'sqlite') ? $result->fetch(PDO::FETCH_NUM) : mysqli_fetch_row($result);
    }
    // Denne metode er den, der fejler lige nu. Sørg for den står præcis sådan her:
    public static function free_result($result) {
        global $db_type;
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

?>