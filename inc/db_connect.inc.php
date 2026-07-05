<?php # inc/db_connect.inc.php v:1.1.0 d:2026-07-02 i:evs
define('APP_VERSION', '1.1.0');
define('APP_DATE', '2026-07-05');
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
   
// Hent den aktive sektion fra roden af arrayet
$active_section = $config['ACTIVE_DB'] ?? 'mysql_config'; 
// Hent kun indstillingerne for den aktive sektion
$db_settings = $config[$active_section] ?? [];
$db_type = $db_settings['DB_TYPE'] ?? 'mysql';

// --- 5. DATABASE CONNECTION (DYNAMISK MOTOR) ---
if ($db_type === 'sqlite') {
    // SQLite Engine
    $db_path_rel = $db_settings['DB_PATH'] ?? 'data/tinycash.sqlite';
    $db_path_abs = realpath(__DIR__ . '/' . $db_path_rel);

    // Opret mappen hvis den mangler
    if (!file_exists(dirname($db_path_abs))) {
        mkdir(dirname($db_path_abs), 0755, true);
    }

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

class DB {
    public static function is_sqlite() {
        global $db_type;
        return ($db_type === 'sqlite');
    }public static function query($conn, $sql) {
        global $pdo, $db_type;
        return ($db_type === 'sqlite') ? $pdo->query($sql) : mysqli_query($conn, $sql);
    }
    public static function fetch_assoc($result) {
        global $db_type;
        return ($db_type === 'sqlite') ? $result->fetch(PDO::FETCH_ASSOC) : mysqli_fetch_assoc($result);
    }
    public static function fetch_row($result) {
        global $db_type;
        return ($db_type === 'sqlite') ? $result->fetch(PDO::FETCH_NUM) : mysqli_fetch_row($result);
    }
    // Denne metode er den, der fejler lige nu. Sørg for den står præcis sådan her:
    public static function free_result($result) {
        global $db_type;
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
        if ($db_type === 'sqlite') {
            // SQLite har ikke en direkte num_rows. 
            // Vi tæller rækkerne ved at hente dem alle, hvis nødvendigt, 
            // men til et if-tjek er det nok at returnere 1 hvis resultatet findes.
            return ($result) ? 1 : 0;
        } else {
            return mysqli_num_rows($result);
        }
    }
    public static function prepare($conn, $sql) {
        global $pdo, $db_type;
        return ($db_type === 'sqlite') ? $pdo->prepare($sql) : mysqli_prepare($conn, $sql);
    }
    public static function fetch_array($result) {
        global $db_type;
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
