<?php # /db-setup/migrate_mail_log.php v:1.0.0 d:2026-09-15 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): der fandtes ingen
// ét-sted-oversigt over hvad der reelt er sendt af mail (fakturaer/rykkere/
// kontoudtog/tilbudsopfølgning/backup-testmail) - kun spredte enkeltfelter
// (invoices.reminder_sent_at, quotes.followup_sent_at) der hver kun dækker
// ÉN mailtype. Ny fælles tabel, udfyldt direkte i inc/mail_handler.lib.php's
// sendTinyMail() (den ENESTE funktion i hele kodebasen der reelt sender
// mail, bekræftet ved en fuld gennemsøgning) - fanger derfor AL udgående
// post automatisk, ét sted, uden at hver enkelt afsendelsesflow (invoice_
// view.php/reminder_action.php/customer_statement_send.php/quote_followup_
// action.php m.fl.) selv skal huske at logge.
echo "Motor: $db_type\n\n";

function mml_table_exists($conn, $name) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$name'");
        return ($res && $res->fetch());
    }
    $res = DB::query($conn, "SHOW TABLES LIKE '$name'");
    return ($res && DB::num_rows($res) > 0);
}

if (mml_table_exists($conn, 'mail_log')) {
    echo "[SPRUNGET OVER] Tabellen 'mail_log' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE mail_log (
              log_id INTEGER PRIMARY KEY AUTOINCREMENT,
              sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              to_email TEXT NOT NULL,
              subject TEXT,
              success INTEGER NOT NULL,
              error_message TEXT,
              user_id INTEGER
          )"
        : "CREATE TABLE mail_log (
              log_id INT AUTO_INCREMENT PRIMARY KEY,
              sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              to_email VARCHAR(255) NOT NULL,
              subject VARCHAR(500),
              success TINYINT(1) NOT NULL,
              error_message TEXT,
              user_id INT
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'mail_log' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Al udgående mail logges nu automatisk - se den nye side mail_log.php (System -> Maintenance).\n";
?>
