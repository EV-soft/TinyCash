<?php # /inc/engine_migrate.inc.php v:1.3.1 d:2026-10-29 i:evs
# Konvertering af ét regnskab mellem databasemotorer (SQLite <-> MySQL), begge veje. Bruger-anmodet
# ("findes der en synkroniseringsmulighed mellem db-typer" -> "begge").
#
# Der er INGEN motor-uafhængig vej i resten af systemet: DB::dump_to_sql() skriver motorens EGET SQL
# (MySQL: backticks, AUTO_INCREMENT, ENGINE=InnoDB; SQLite: sin egen CREATE TABLE + triggere), og
# backup_restore_worker.php afviser direkte at gendanne et dump fra den anden motor (engine_mismatch).
# Denne fil kopierer i stedet tabel for tabel GENERISK: den læser kildens kolonner, bygger et tilsvarende
# skema i målmotorens syntaks (type-mapning nedenfor), og flytter rækkerne med forberedte forespørgsler
# (parametre - så citering/escape/NULL/binært håndteres sikkert på tværs). Append-only-triggerne
# (journal/ledger/expenses) genskabes til sidst på målet med create_append_only_triggers().
#
# Skemaet tages fra KILDEN som den faktisk ser ud (alle migreringer allerede anvendt) - ikke fra appens
# spredte skemadefinitioner - så en konvertering bevarer præcis det regnskab, der findes nu.
#
# BEGRÆNSNINGER (bevidst): kopierer tabeller, kolonner, rækker og append-only-triggerne. Indekser (ud over
# primærnøglen) og fremmednøgle-begrænsninger genskabes ikke - TinyCash håndhæver sine regler i PHP-laget
# og med append-only-triggerne, ikke med FK-constraints, så det påvirker ikke driften. Kør altid en backup
# først, og konvertér til en TOM / ny måldatabase.

if (!function_exists('tc_eng_target_settings')) {

// Målmotorens forbindelsesindstillinger fra env.ini ($config), ud fra kildemotoren
function tc_eng_target_settings(string $src_type, array $config): array {
    if ($src_type === 'sqlite') {
        $m = $config['mysql_config'] ?? [];
        return ['type' => 'mysql', 'host' => $m['DB_HOST'] ?? 'localhost', 'user' => $m['DB_USER'] ?? '',
                'pass' => $m['DB_PASS'] ?? '', 'name' => $m['DB_NAME'] ?? ''];
    }
    $s = $config['sqlite_config'] ?? [];
    return ['type' => 'sqlite', 'path' => $s['DB_PATH'] ?? 'data/tinycash.sqlite'];
}

// Åbn en forbindelse til målet. $override_path/$override_name lader en test (eller brugeren) skrive til en
// ANDEN fil/database end den aktive - så kilden aldrig kan overskrives ved et uheld.
function tc_eng_open_target(array $t, ?string $override = null) {
    if ($t['type'] === 'sqlite') {
        $rel = $override ?? $t['path'];
        $abs = (preg_match('~^([A-Za-z]:[\\\\/]|/)~', $rel)) ? $rel : (dirname(__DIR__) . '/inc/' . $rel);
        $dir = dirname($abs);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . $abs);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return ['type' => 'sqlite', 'pdo' => $pdo, 'where' => $abs];
    }
    $name = $override ?? $t['name'];
    if ($name === '') throw new Exception('MySQL-databasenavnet (DB_NAME i [mysql_config]) er tomt i env.ini.');
    $c = @mysqli_connect($t['host'], $t['user'], $t['pass'], $name);
    if (!$c) throw new Exception('MySQL-forbindelse fejlede: ' . mysqli_connect_error());
    mysqli_set_charset($c, 'utf8mb4');
    return ['type' => 'mysql', 'conn' => $c, 'where' => $t['user'] . '@' . $t['host'] . '/' . $name];
}

// Kildens tabeller
function tc_eng_tables($conn, string $type): array {
    $out = [];
    if ($type === 'sqlite') {
        $r = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        while ($x = DB::fetch_row($r)) $out[] = $x[0];
    } else {
        $r = DB::query($conn, "SHOW TABLES");
        while ($x = DB::fetch_row($r)) $out[] = $x[0];
    }
    return $out;
}

// Kildens kolonner: [['name'=>, 'type'=>råtype, 'notnull'=>bool, 'pk'=>bool, 'auto'=>bool], ...]
function tc_eng_columns($conn, string $type, string $table): array {
    $cols = [];
    if ($type === 'sqlite') {
        $r = DB::query($conn, "PRAGMA table_info(\"" . str_replace('"', '""', $table) . "\")");
        while ($x = DB::fetch_assoc($r)) {
            $cols[] = ['name' => $x['name'], 'type' => strtoupper(trim((string)$x['type'])),
                       'notnull' => (int)$x['notnull'] === 1, 'pk' => (int)$x['pk'] > 0, 'auto' => false];
        }
        // AUTOINCREMENT: en enkelt INTEGER PRIMARY KEY er SQLites rowid-alias (auto)
        $pk = array_values(array_filter($cols, fn($c) => $c['pk']));
        if (count($pk) === 1 && strpos($pk[0]['type'], 'INT') !== false) {
            foreach ($cols as &$c) if ($c['name'] === $pk[0]['name']) $c['auto'] = true;
            unset($c);
        }
    } else {
        $r = DB::query($conn, "SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        while ($x = DB::fetch_assoc($r)) {
            $cols[] = ['name' => $x['Field'], 'type' => strtoupper(trim((string)$x['Type'])),
                       'notnull' => strtoupper((string)$x['Null']) === 'NO', 'pk' => strtoupper((string)$x['Key']) === 'PRI',
                       'auto' => stripos((string)$x['Extra'], 'auto_increment') !== false];
        }
    }
    return $cols;
}

// Kildetype -> målmotorens kolonnetype
function tc_eng_map_type(string $raw, string $dst_type, bool $pk): string {
    $r = strtoupper($raw);
    $is_int  = preg_match('/INT|SERIAL|BOOL/', $r);
    $is_real = preg_match('/REAL|FLOA|DOUB|DEC|NUMERIC|FIXED/', $r);
    $is_blob = preg_match('/BLOB|BINARY/', $r);
    if ($dst_type === 'mysql') {
        if ($is_int)  return 'BIGINT';
        if ($is_real) return 'DECIMAL(20,6)';
        if ($is_blob) return 'LONGBLOB';
        return $pk ? 'VARCHAR(191)' : 'LONGTEXT';      // en tekst-primærnøgle kan ikke være LONGTEXT i MySQL
    }
    // -> sqlite
    if ($is_int)  return 'INTEGER';
    if ($is_real) return 'REAL';
    if ($is_blob) return 'BLOB';
    return 'TEXT';
}

// CREATE TABLE i målmotorens syntaks
function tc_eng_create_sql(string $table, array $cols, string $dst_type): string {
    $q  = $dst_type === 'mysql' ? '`' : '"';
    $qq = fn($s) => $q . str_replace($q, $q . $q, $s) . $q;
    $pk = array_values(array_filter($cols, fn($c) => $c['pk']));
    $single_auto = count($pk) === 1 && $pk[0]['auto'];
    $lines = [];
    foreach ($cols as $c) {
        $def = $qq($c['name']) . ' ' . tc_eng_map_type($c['type'], $dst_type, $c['pk']);
        if ($single_auto && $c['name'] === $pk[0]['name']) {
            $def .= $dst_type === 'mysql' ? ' AUTO_INCREMENT PRIMARY KEY' : ' PRIMARY KEY AUTOINCREMENT';
        } elseif ($c['notnull']) {
            $def .= ' NOT NULL';
        }
        $lines[] = $def;
    }
    if ($pk && !$single_auto) {
        $lines[] = 'PRIMARY KEY (' . implode(', ', array_map(fn($c) => $qq($c['name']), $pk)) . ')';
    }
    $sql = 'CREATE TABLE ' . $qq($table) . " (\n  " . implode(",\n  ", $lines) . "\n)";
    if ($dst_type === 'mysql') $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return $sql;
}

// Hovedfunktion: kopiér alle tabeller fra kilde til mål. Returnerer en log-liste. Kaster ved fejl.
// $dst = ['type'=>, 'pdo'|'conn'=>, 'where'=>] fra tc_eng_open_target().
function tc_engine_migrate($src_conn, string $src_type, array $dst, array &$log): void {
    $dh = $dst['type'] === 'sqlite' ? $dst['pdo'] : $dst['conn'];
    $exec = function (string $sql) use ($dst, $dh) {
        if ($dst['type'] === 'sqlite') { $dh->exec($sql); }
        else if (!mysqli_query($dh, $sql)) throw new Exception('MySQL: ' . mysqli_error($dh) . ' | ' . substr($sql, 0, 200));
    };
    $tables = tc_eng_tables($src_conn, $src_type);
    $log[] = count($tables) . ' tabeller fundet i kilden (' . $src_type . ').';

    if ($dst['type'] === 'sqlite') { $dh->exec('PRAGMA foreign_keys = OFF'); $dh->beginTransaction(); }
    else { mysqli_query($dh, 'SET FOREIGN_KEY_CHECKS = 0'); mysqli_begin_transaction($dh); }
    try {
        foreach ($tables as $table) {
            $cols = tc_eng_columns($src_conn, $src_type, $table);
            if (!$cols) { $log[] = "[sprunget] $table: ingen kolonner."; continue; }
            $qd = $dst['type'] === 'mysql' ? '`' : '"';
            $drop = 'DROP TABLE IF EXISTS ' . $qd . str_replace($qd, $qd . $qd, $table) . $qd;
            $exec($drop);
            $exec(tc_eng_create_sql($table, $cols, $dst['type']));

            // Rækker - forberedt INSERT, bundne parametre (sikker på tværs af motorer)
            $colnames = array_column($cols, 'name');
            $qd_list = implode(', ', array_map(fn($n) => $qd . str_replace($qd, $qd . $qd, $n) . $qd, $colnames));
            $rows = 0;
            if ($dst['type'] === 'sqlite') {
                $ph = implode(', ', array_fill(0, count($colnames), '?'));
                $stmt = $dh->prepare('INSERT INTO "' . str_replace('"', '""', $table) . "\" ($qd_list) VALUES ($ph)");
                $sr = DB::query($src_conn, 'SELECT * FROM ' . ($src_type === 'mysql' ? "`$table`" : "\"$table\""));
                while ($row = DB::fetch_assoc($sr)) { $stmt->execute(array_values($row)); $rows++; }
            } else {
                $ph = implode(', ', array_fill(0, count($colnames), '?'));
                $stmt = mysqli_prepare($dh, "INSERT INTO `" . str_replace('`', '``', $table) . "` ($qd_list) VALUES ($ph)");
                if (!$stmt) throw new Exception('MySQL prepare: ' . mysqli_error($dh));
                $sr = DB::query($src_conn, 'SELECT * FROM ' . ($src_type === 'mysql' ? "`$table`" : "\"$table\""));
                while ($row = DB::fetch_assoc($sr)) {
                    $vals = array_values($row);
                    $types = str_repeat('s', count($vals));            // alt som streng; MySQL caster til kolonnetypen
                    $refs = [];
                    foreach ($vals as $k => $v) $refs[$k] = &$vals[$k];
                    mysqli_stmt_bind_param($stmt, $types, ...$refs);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception("MySQL insert $table: " . mysqli_stmt_error($stmt));
                    $rows++;
                }
                mysqli_stmt_close($stmt);
            }
            // Kontrol: samme antal rækker i kilde og mål
            $src_cnt = (int)DB::fetch_row(DB::query($src_conn, 'SELECT COUNT(*) FROM ' . ($src_type === 'mysql' ? "`$table`" : "\"$table\"")))[0];
            if ($rows !== $src_cnt) throw new Exception("$table: kopierede $rows af $src_cnt rækker.");
            $log[] = sprintf('%-30s %6d rækker', $table, $rows);
        }
        if ($dst['type'] === 'sqlite') { $dh->commit(); $dh->exec('PRAGMA foreign_keys = ON'); }
        else { mysqli_commit($dh); mysqli_query($dh, 'SET FOREIGN_KEY_CHECKS = 1'); }
    } catch (Throwable $e) {
        if ($dst['type'] === 'sqlite') { $dh->rollBack(); }
        else { mysqli_rollback($dh); }
        throw $e;
    }

    // Append-only-triggerne genskabes på målet (create_append_only_triggers bruger de globale $pdo/$db_type)
    global $conn, $pdo, $db_type;
    $sc = $conn; $sp = $pdo ?? null; $st = $db_type;
    $db_type = $dst['type'];
    $conn    = $dh;
    $pdo     = $dst['type'] === 'sqlite' ? $dh : $sp;
    try {
        if (function_exists('create_append_only_triggers')) {
            $res = create_append_only_triggers($conn);
            $log[] = 'Append-only-triggere på målet: ' . (int)($res['created'] ?? 0) . ' oprettet'
                   . (!empty($res['failed']) ? ', ' . count($res['failed']) . ' fejlede' : '') . '.';
        }
    } finally {
        $conn = $sc; $pdo = $sp; $db_type = $st;
    }
    $log[] = 'Færdig. Mål: ' . $dst['where'];
}

}
