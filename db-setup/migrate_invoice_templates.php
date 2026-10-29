<?php # /db-setup/migrate_invoice_templates.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 32):
// ingen måde at gemme et navngivet sæt fakturalinjer til genbrug - kun
// enkelt-produkter (auto-udfylder ÉN linje) eller "Dupliker faktura" (kræver
// en allerede eksisterende faktura). Se invoice_template_action.php for
// selve brugen (gem/indsæt fra invoice_edit.php).
echo "Motor: $db_type\n\n";

if (DB::is_sqlite()) {
    $has_table = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='invoice_line_templates'");
    $has_table = $has_table && DB::fetch_assoc($has_table);
} else {
    $has_table = DB::query($conn, "SHOW TABLES LIKE 'invoice_line_templates'");
    $has_table = $has_table && DB::num_rows($has_table) > 0;
}

if ($has_table) {
    echo "[SPRUNGET OVER] Tabellen 'invoice_line_templates' findes allerede.\n";
} else {
    if (DB::is_sqlite()) {
        $ok1 = DB::query($conn, "CREATE TABLE invoice_line_templates (
            template_id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            created_by INTEGER,
            created_at TEXT
        )");
        $ok2 = DB::query($conn, "CREATE TABLE invoice_line_template_items (
            item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id INTEGER NOT NULL,
            line_text TEXT,
            quantity NUMERIC,
            price_each NUMERIC,
            line_vat_rate NUMERIC,
            prod_id INTEGER
        )");
    } else {
        $ok1 = DB::query($conn, "CREATE TABLE invoice_line_templates (
            template_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            created_by INT,
            created_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok2 = DB::query($conn, "CREATE TABLE invoice_line_template_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            line_text VARCHAR(255),
            quantity DECIMAL(15,2),
            price_each DECIMAL(15,2),
            line_vat_rate DECIMAL(5,2),
            prod_id INT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ($ok1 && $ok2) {
        echo "[OK] Tabellerne 'invoice_line_templates'/'invoice_line_template_items' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Ingen skabeloner findes endnu - opret dem fra invoice_edit.php.\n";
?>
