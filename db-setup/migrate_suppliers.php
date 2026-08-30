<?php # /db-setup/migrate_suppliers.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT: Leverandørmodul (bruger-anmodet, "byg leverandørmodul og aldersfordelt
// restanceliste"). Ny stamdata-tabel 'suppliers' - samme rolle på købssiden
// som 'customers' allerede har på salgssiden. Kobles til expenses via en ny,
// valgfri supplier_id-kolonne (den eksisterende fritekst-kolonne 'supplier'
// bevares uændret og fortsat udfyldt automatisk, så AI-scan/eksisterende
// rapporter der læser den rå tekst intet mærker).
//
// Introducerer samtidig et rigtigt, om end minimalt, "leverandørgæld"-begreb:
// expenses.due_date + expenses.paid_date. Historisk har EN HVER udgift i
// TinyCash altid krediteret bankkontoen med det samme ved oprettelse (se
// expense_edit.php's ledger_post()-kald) - der har aldrig eksisteret nogen
// egentlig "ikke betalt endnu"-tilstand. Fremover kan en ny udgift valgfrit
// oprettes som "ikke betalt endnu" (krediterer i stedet den konfigurerede
// kreditor-/gældskonto, se company_settings.php's nye "Standard kreditor-
// konto"), og senere afsluttes med en rigtig, bogført betaling (expense_
// actions.php?action=mark_paid) der krediterer banken og debiterer gælds-
// kontoen. Dette gør en reel, bogført aldersfordelt restanceliste for
// leverandører mulig (se aging_report.php) - IKKE en tavs metadata-fane.
//
// Bagudkompatibilitet: ALLE eksisterende udgifter har allerede krediteret
// banken direkte og skal derfor betragtes som betalt på deres egen dato -
// backfillet nedenfor sætter paid_date = exp_date for enhver eksisterende
// række, så de aldrig fejlagtigt dukker op som "skyldige" i den nye
// restanceliste.
echo "Motor: $db_type\n\n";

function ms_table_exists($conn, $name) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$name'");
        return ($res && $res->fetch());
    }
    $res = DB::query($conn, "SHOW TABLES LIKE '$name'");
    return ($res && DB::num_rows($res) > 0);
}

function ms_column_exists($conn, $table, $column) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info($table)");
        if ($res) {
            while ($row = DB::fetch_assoc($res)) {
                if (strtolower($row['name']) === strtolower($column)) return true;
            }
        }
        return false;
    }
    $res = DB::query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && DB::num_rows($res) > 0);
}

// --- 1. Tabellen 'suppliers' ---
if (ms_table_exists($conn, 'suppliers')) {
    echo "[SPRUNGET OVER] Tabellen 'suppliers' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE suppliers (
              supplier_id INTEGER PRIMARY KEY AUTOINCREMENT,
              supplier_name TEXT NOT NULL,
              contact_person TEXT,
              address TEXT,
              cvr TEXT,
              phone TEXT,
              email TEXT,
              payment_days INTEGER DEFAULT 8,
              notes TEXT,
              is_active INTEGER DEFAULT 1,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE suppliers (
              supplier_id INT AUTO_INCREMENT PRIMARY KEY,
              supplier_name VARCHAR(255) NOT NULL,
              contact_person VARCHAR(255),
              address TEXT,
              cvr VARCHAR(20),
              phone VARCHAR(50),
              email VARCHAR(255),
              payment_days INT DEFAULT 8,
              notes TEXT,
              is_active TINYINT(1) DEFAULT 1,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'suppliers' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

// --- 2. expenses.supplier_id (valgfri kobling til suppliers, fritekstfeltet
//     'supplier' bevares uændret som visnings-/AI-scan-felt) ---
if (ms_column_exists($conn, 'expenses', 'supplier_id')) {
    echo "[SPRUNGET OVER] Kolonnen 'supplier_id' findes allerede på expenses.\n";
} else {
    $sql = DB::is_sqlite()
        ? "ALTER TABLE expenses ADD COLUMN supplier_id INTEGER"
        : "ALTER TABLE expenses ADD COLUMN supplier_id INT";
    if (DB::query($conn, $sql)) {
        echo "[OK] Kolonnen 'supplier_id' tilføjet til expenses.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

// --- 3. expenses.due_date / paid_date (leverandørgæld) ---
if (ms_column_exists($conn, 'expenses', 'due_date')) {
    echo "[SPRUNGET OVER] Kolonnen 'due_date' findes allerede på expenses.\n";
} else {
    if (DB::query($conn, "ALTER TABLE expenses ADD COLUMN due_date DATE")) {
        echo "[OK] Kolonnen 'due_date' tilføjet til expenses.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

if (ms_column_exists($conn, 'expenses', 'paid_date')) {
    echo "[SPRUNGET OVER] Kolonnen 'paid_date' findes allerede på expenses.\n";
} else {
    if (DB::query($conn, "ALTER TABLE expenses ADD COLUMN paid_date DATE")) {
        echo "[OK] Kolonnen 'paid_date' tilføjet til expenses.\n";

        // Bagudkompatibilitet (se filens header-kommentar): ALLE eksisterende
        // udgifter har allerede krediteret banken direkte ved oprettelse -
        // de skal betragtes som betalt på deres egen dato, IKKE som en
        // pludselig bunke "skyldige" beløb i den nye restanceliste.
        $backfill = DB::query($conn, "UPDATE expenses SET paid_date = exp_date WHERE paid_date IS NULL");
        if ($backfill) {
            echo "[OK] Eksisterende udgifter markeret som betalt på deres oprindelige dato (bagudkompatibilitet).\n";
        } else {
            echo "[FEJL] Bagudkompatibilitets-backfill: " . DB::error($conn) . "\n";
        }
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Du kan nu oprette leverandører under Indkøb -> Leverandører,\n";
echo "og se den aldersfordelte restanceliste under Regnskab -> Restanceliste.\n";
echo "Husk evt. at sætte 'Standard kreditorkonto' under Firmaindstillinger,\n";
echo "hvis du vil bruge 'Ikke betalt endnu' ved registrering af en udgift.\n";
