<?php # /inc/auto_backup.inc.php v:1.2.0 d:2026-08-11 i:evs 
# Automatisk krypteret backup sendt til backup-mail
# Bruger DB::dump_to_sql() og DB::is_sqlite() fra db_connect.inc.php
# Udløses når: 21+ dage siden sidste backup OG der er sket ændringer siden da

function auto_backup_check($conn) {
    global $db_type, $pdo, $db_settings;
    if (!$conn) return;

    // ── 1. Tjek interval ──────────────────────────────────────────────────────
    $res     = @DB::query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'auto_backup_last'");
    $row     = $res ? DB::fetch_assoc($res) : null;
    $last_ts = $row ? (int)$row['setting_value'] : 0;

    if ((time() - $last_ts) / 86400 < 21) return;

    // ── 2. Er der ændringer siden seneste backup? ─────────────────────────────
    $last_dt = date('Y-m-d H:i:s', $last_ts ?: 0);
    $changed = false;

    $chk = @DB::query($conn, "SELECT COUNT(*) FROM audit_log WHERE log_date > '$last_dt'");
    if ($chk) { $r = DB::fetch_row($chk); if ((int)$r[0] > 0) $changed = true; }

    if (!$changed) {
        foreach (['expenses', 'invoices', 'journal', 'transactions'] as $tbl) {
            $chk2 = @DB::query($conn, "SELECT COUNT(*) FROM $tbl WHERE created_at > '$last_dt'");
            if ($chk2) { $r2 = DB::fetch_row($chk2); if ((int)$r2[0] > 0) { $changed = true; break; } }
        }
    }

    if (!$changed) {
        _ab_save($conn, 'auto_backup_last', time());
        return;
    }

    // ── 3. Hent indstillinger ─────────────────────────────────────────────────
    $s       = [];
    $set_res = @DB::query($conn, "SELECT setting_key, setting_value FROM settings");
    while ($row = DB::fetch_assoc($set_res)) { $s[$row['setting_key']] = $row['setting_value']; }

    // Backup-destination + krypterings-kodeord konfigureres i Firmaindstillinger
    // (settings-tabellen). Selve SMTP-afsenderen kommer fra appens centrale
    // mail-konfiguration (inc/mail_config.inc.php -> MAIL_* fra env.ini), samme
    // kilde som al anden udgående post i systemet.
    $backup_mail  = trim($s['auto_backup_mail'] ?? '');
    $company_name = trim($s['company_name']     ?? 'TinyCash');

    // Master-kodeordet læses fra env.ini [backup_config] - ALDRIG fra databasen,
    // så det ikke lækker i en ukrypteret regnskabs-backup. Overgangs-fallback til
    // den gamle settings-placering, så eksisterende opsætning ikke pludselig
    // sender ukrypteret før nøglen er flyttet til env.ini.
    $backup_pass = _ab_master_password();
    if ($backup_pass === '') {
        $backup_pass = trim($s['auto_backup_password'] ?? '');
    }

    require_once __DIR__ . '/mail_config.inc.php';

    if (empty($backup_mail)) {
        _ab_log($conn, 'Auto backup: mangler auto_backup_mail i Firmaindstillinger');
        return;
    }
    if (!defined('MAIL_HOST') || MAIL_HOST === '') {
        _ab_log($conn, 'Auto backup: SMTP-afsender mangler (MAIL_HOST i env.ini/[mail_config])');
        return;
    }

    // ── 4. Byg ZIP ───────────────────────────────────────────────────────────
    if (!class_exists('ZipArchive')) {
        _ab_log($conn, 'Auto backup: PHP ZipArchive-extension er ikke aktiveret');
        return;
    }

    $tmp_dir    = rtrim(sys_get_temp_dir(), '/') . '/';
    $date_stamp = date('Y-m-d_His');
    $is_sqlite  = DB::is_sqlite();
    $engine     = $is_sqlite ? 'sqlite' : 'mysql';
    $zip_file   = $tmp_dir . 'tc_backup_' . $date_stamp . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        _ab_log($conn, 'Auto backup: kunne ikke oprette midlertidig ZIP-fil i ' . $tmp_dir);
        return;
    }

    // Database-dump via den eksisterende DB::dump_to_sql()
    $sql_dump = DB::dump_to_sql($conn);
    $dump_filename = $is_sqlite ? 'tinycash_sqlite.sql' : 'tinycash_mysql.sql';
    $zip->addFromString($dump_filename, $sql_dump);

    // Ved SQLite: inkluder også den rå .sqlite-fil for komplet gendannelse
    if ($is_sqlite) {
        $db_path = $db_settings['DB_PATH'] ?? 'data/tinycash.sqlite';
        $db_abs  = realpath(__DIR__ . '/' . $db_path)
                 ?: realpath(__DIR__ . '/../' . $db_path);
        if ($db_abs && file_exists($db_abs)) {
            $zip->addFile($db_abs, 'tinycash.sqlite');
        }
    }

    // Uploads (kvitteringer og bilag)
    $uploads_dir = __DIR__ . '/../uploads/';
    if (is_dir($uploads_dir)) {
        foreach (glob($uploads_dir . '*') as $f) {
            if (is_file($f)) $zip->addFile($f, 'uploads/' . basename($f));
        }
    }

    // Program-backup (kildekode + DB-struktur) foldes ind under program/, så den
    // automatiske 21-dages backup er én samlet backup af BÅDE data og program.
    require_once __DIR__ . '/program_backup.lib.php';
    program_backup_add_to_zip($zip, $conn, 'program/');

    // Info-fil
    $zip->addFromString('backup_info.txt',
        "TinyCash Auto Backup\n" .
        "Dato:    " . date('d.m.Y H:i:s') . "\n" .
        "Motor:   " . strtoupper($engine) . "\n" .
        "Firma:   " . $company_name . "\n" .
        "Version: " . (defined('APP_VERSION') ? APP_VERSION : '?') . "\n"
    );
    $zip->close();

    // ── 5. Krypter med AES-256-CBC (OpenSSL-kompatibelt format) ──────────────
    // Filformatet er PRÆCIS det openssl's kommandolinje selv laver og læser:
    //   "Salted__" + 8-byte salt + ciffertekst
    // Nøgle+IV udledes med PBKDF2-HMAC-SHA256 (10.000 iterationer) af master-
    // kodeordet + saltet - identisk med `openssl enc -pbkdf2 -md sha256`. Derved
    // kan backuppen dekrypteres med ÉT standard openssl-kald på en hvilken som
    // helst maskine i en katastrofe, uden TinyCash-koden. Master-kodeordet
    // sendes ALDRIG i mailen - kun brugeren kender det (se mail-body nedenfor).
    $encrypted = false;
    $send_file = $zip_file;

    if (function_exists('openssl_encrypt') && !empty($backup_pass)) {
        $enc_file  = $zip_file . '.enc';
        $salt      = random_bytes(8);
        $key_iv    = hash_pbkdf2('sha256', $backup_pass, $salt, 10000, 48, true); // 32 nøgle + 16 IV
        $key       = substr($key_iv, 0, 32);
        $iv        = substr($key_iv, 32, 16);
        $zip_data  = file_get_contents($zip_file);
        $cipher    = openssl_encrypt($zip_data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher !== false) {
            file_put_contents($enc_file, "Salted__" . $salt . $cipher);
            @unlink($zip_file);
            $send_file = $enc_file;
            $encrypted = true;
        }
    }

    // ── 6. Send mail via PHPMailer ───────────────────────────────────────────
    // Samme PHPMailer-installation som resten af systemet (inc/phpmailer/).
    $mailer_path = null;
    foreach ([
        __DIR__ . '/phpmailer/',
        __DIR__ . '/../vendor/phpmailer/phpmailer/src/',
        __DIR__ . '/PHPMailer/src/',
    ] as $p) {
        if (file_exists($p . 'PHPMailer.php')) { $mailer_path = $p; break; }
    }

    if (!$mailer_path) {
        _ab_log($conn, 'Auto backup: PHPMailer ikke fundet — backup ikke afsendt');
        @unlink($send_file);
        return;
    }

    require_once $mailer_path . 'Exception.php';
    require_once $mailer_path . 'PHPMailer.php';
    require_once $mailer_path . 'SMTP.php';

    $attach_ext  = $encrypted ? '.zip.enc' : '.zip';
    $attach_name = 'tinycash_backup_' . $date_stamp . '_' . $engine . $attach_ext;

    $enc_info = $encrypted
        ? "Kryptering:  AES-256-CBC (PBKDF2-SHA256, 10.000 iterationer)\n" .
          "Kodeord:     Dit MASTER-kodeord. Det sendes ALDRIG i denne mail - kun du kender det.\n"
        : "Kryptering:  INGEN - sæt et master-kodeord i Firmaindstillinger -> Automatisk backup.\n";

    // Erstat DIT_MASTER_KODEORD med dit eget. -md sha256 gør nøgleudledningen entydig.
    $restore_sqlite = "1. Dekrypter (indtast dit master-kodeord):\n   openssl enc -d -aes-256-cbc -pbkdf2 -md sha256 -in $attach_name -out backup.zip -pass pass:DIT_MASTER_KODEORD\n2. Udpak ZIP og erstat tinycash.sqlite på serveren.\n";
    $restore_mysql  = "1. Dekrypter (indtast dit master-kodeord):\n   openssl enc -d -aes-256-cbc -pbkdf2 -md sha256 -in $attach_name -out backup.zip -pass pass:DIT_MASTER_KODEORD\n2. Udpak ZIP og importer tinycash_mysql.sql:\n   mysql -u BRUGER -p DATABASE < tinycash_mysql.sql\n";

    $body =
        "Automatisk backup fra " . $company_name . "\n\n" .
        "Dato:        " . date('d.m.Y H:i') . "\n" .
        "Database:    " . strtoupper($engine) . "\n" .
        $enc_info . "\n" .
        "--- Gendannelsesvejledning ---\n" .
        ($is_sqlite ? $restore_sqlite : $restore_mysql) . "\n" .
        "Denne mail er sendt automatisk af TinyCash.\n";

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = (MAIL_PORT == 465) ? 'ssl' : 'tls';
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(MAIL_FROM, $company_name . ' Backup');
        $mail->addAddress($backup_mail);
        $mail->Subject    = '[TinyCash] Automatisk backup — ' . $company_name . ' — ' . date('d.m.Y');
        $mail->Body       = $body;
        $mail->addAttachment($send_file, $attach_name);
        $mail->send();

        // Gem succes-status
        $now = time();
        _ab_save($conn, 'auto_backup_last', $now);
        _ab_save($conn, 'last_backup_time', $now);
        _ab_save($conn, 'auto_backup_error', '');

    } catch (Exception $e) {
        _ab_log($conn, 'Auto backup mail-fejl: ' . $e->getMessage());
    }

    @unlink($send_file); // Ryd krypteret fil fra server
}

// ── Hjælpefunktioner ─────────────────────────────────────────────────────────
function _ab_save($conn, $key, $value) {
    global $db_type;
    $safe_val = DB::real_escape_string($conn, (string)$value);
    $safe_key = DB::real_escape_string($conn, $key);
    $sql = $db_type === 'sqlite'
        ? "INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('$safe_key', '$safe_val')"
        : "INSERT INTO settings (setting_key, setting_value) VALUES ('$safe_key', '$safe_val') ON DUPLICATE KEY UPDATE setting_value = '$safe_val'";
    @DB::query($conn, $sql);
}

function _ab_log($conn, $msg) {
    _ab_save($conn, 'auto_backup_error', $msg);
    error_log('[TinyCash Auto Backup] ' . $msg);
}

// Master-kodeordet til backup-kryptering hentes fra env.ini [backup_config],
// IKKE fra databasen - så det ikke havner i database-dumps eller nogen backup.
function _ab_master_password() {
    $ini = @parse_ini_file(__DIR__ . '/env.ini', true);
    return (is_array($ini) && isset($ini['backup_config']['BACKUP_PASSWORD']))
        ? trim($ini['backup_config']['BACKUP_PASSWORD'])
        : '';
}

// ── Mangler real_escape_string på DB-klassen? Tilføj her som fallback ────────
if (!method_exists('DB', 'real_escape_string')) {
    // Allerede defineret i db_connect.inc.php som DB::escape() — brug den
}
