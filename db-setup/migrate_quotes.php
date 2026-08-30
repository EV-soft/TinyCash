<?php # /db-setup/migrate_quotes.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION: Tilbud/Ordrebekræftelse (bruger-anmodet, fra "hvilke
// funktioner mangler TinyCash"-gennemgangen). To NYE, selvstændige tabeller
// (quotes/quote_lines) - IKKE en udvidelse af invoices/invoice_lines, fordi
// et tilbud/en ordrebekræftelse bevidst er et FØR-bogholderi-dokument: intet
// heraf må nogensinde røre journal/ledger, momsopgørelsen eller den
// aldersfordelte restanceliste, før det reelt er blevet til en rigtig
// faktura. At blande det ind i invoices-tabellen ville kræve ekstra
// WHERE-filtrering i hver eneste eksisterende rapport, der allerede
// forudsætter at enhver invoices-række er en rigtig faktura (aging_report,
// vat_report, report_income, sales_hub) - en reel risiko for at et tilbud
// ved en fejl kunne lække ind i et regnskabstal. Adskilte tabeller gør denne
// klasse fejl umulig i stedet for blot usandsynlig.
//
// "Tilbud" og "Ordrebekræftelse" er bevidst SAMME underliggende dokument,
// ikke to separate moduler - kun titlen på selve udskriften (quote_view.php)
// skifter afhængig af status ('sent' = Tilbud, 'accepted'/'converted' =
// Ordrebekræftelse), præcis som e-conomic/Billy/Dinero også gør det i
// praksis. Godkendes tilbuddet, konverteres det til en helt almindelig
// KLADDE-faktura (samme "generér en kladde, aldrig bogført direkte"-princip
// som recurring_invoices, se inc/recurring_invoices.inc.php) - hele den
// eksisterende, allerede grundigt testede bogførings-pipeline (invoice_
// edit.php -> invoice_post_action.php) genbruges uændret derfra.
echo "Motor: $db_type\n\n";

function mq_table_exists($conn, $name) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$name'");
        return ($res && $res->fetch());
    }
    $res = DB::query($conn, "SHOW TABLES LIKE '$name'");
    return ($res && DB::num_rows($res) > 0);
}

// --- 1. Tabellen 'quotes' ---
if (mq_table_exists($conn, 'quotes')) {
    echo "[SPRUNGET OVER] Tabellen 'quotes' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE quotes (
              quote_id INTEGER PRIMARY KEY AUTOINCREMENT,
              quote_no INTEGER,
              cust_id INTEGER,
              quote_date DATE,
              valid_until DATE,
              status TEXT DEFAULT 'draft',
              cust_reference TEXT,
              delivery_address TEXT,
              quote_note TEXT,
              proj_id INTEGER,
              converted_invoice_id INTEGER,
              accepted_at TIMESTAMP,
              rejected_at TIMESTAMP,
              created_by INTEGER,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          )"
        : "CREATE TABLE quotes (
              quote_id INT AUTO_INCREMENT PRIMARY KEY,
              quote_no INT,
              cust_id INT,
              quote_date DATE,
              valid_until DATE,
              status VARCHAR(20) DEFAULT 'draft',
              cust_reference VARCHAR(255),
              delivery_address TEXT,
              quote_note TEXT,
              proj_id INT,
              converted_invoice_id INT,
              accepted_at DATETIME,
              rejected_at DATETIME,
              created_by INT,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'quotes' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

// --- 2. Tabellen 'quote_lines' ---
if (mq_table_exists($conn, 'quote_lines')) {
    echo "[SPRUNGET OVER] Tabellen 'quote_lines' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE quote_lines (
              line_id INTEGER PRIMARY KEY AUTOINCREMENT,
              quote_id INTEGER,
              line_text TEXT,
              quantity NUMERIC,
              price_each NUMERIC,
              line_vat_rate NUMERIC,
              prod_id INTEGER,
              proj_id INTEGER
          )"
        : "CREATE TABLE quote_lines (
              line_id INT AUTO_INCREMENT PRIMARY KEY,
              quote_id INT,
              line_text TEXT,
              quantity NUMERIC(15,2),
              price_each NUMERIC(15,2),
              line_vat_rate NUMERIC(5,2),
              prod_id INT,
              proj_id INT
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabellen 'quote_lines' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

// --- 3. Tabellen 'quote_no_counter' - egen, atomisk nummerserie for
//     tilbud/ordrebekræftelser, adskilt fra invoice_no_counter, så de to
//     nummerserier aldrig blander sig. Samme mønster som next_invoice_no()/
//     next_voucher_no() i inc/db_connect.inc.php. ---
if (mq_table_exists($conn, 'quote_no_counter')) {
    echo "[SPRUNGET OVER] Tabellen 'quote_no_counter' findes allerede.\n";
} else {
    $sql = DB::is_sqlite()
        ? "CREATE TABLE quote_no_counter (id INTEGER PRIMARY KEY, next_no INTEGER NOT NULL)"
        : "CREATE TABLE quote_no_counter (id INT PRIMARY KEY, next_no INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (DB::query($conn, $sql)) {
        DB::insert($conn, 'quote_no_counter', ['id' => 1, 'next_no' => 1]);
        echo "[OK] Tabellen 'quote_no_counter' oprettet og sat til at starte ved 1.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Du kan nu oprette tilbud under Salg -> Tilbud.\n";
echo "Et tilbud påvirker ALDRIG hovedbogen, momsopgørelsen eller restancelisten -\n";
echo "kun når det konverteres til en rigtig faktura (efter kundens accept)\n";
echo "opstår der en almindelig fakturakladde, som bogføres helt normalt derfra.\n";
