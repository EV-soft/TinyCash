<?php # /db-setup/migrate_template_discount.php v:1.0.0 d:2026-11-04 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// Linjerabat (%) på fakturaskabelonernes linjer - en skabelon gemte ikke rabatten, så en linje indsat fra en
// skabelon fik fuld pris. Kræver at migrate_invoice_templates.php er kørt (tabellen findes).
echo "Motor: $db_type\n\n";

if (!db_has_table($conn, 'invoice_line_template_items')) {
    echo "[SPRUNGET OVER] Tabellen 'invoice_line_template_items' findes ikke - kør migrate_invoice_templates.php først.\n";
} elseif (db_has_column($conn, 'invoice_line_template_items', 'discount_pct')) {
    echo "[SPRUNGET OVER] Kolonnen 'discount_pct' findes allerede på invoice_line_template_items.\n";
} else {
    $type = DB::is_sqlite() ? 'NUMERIC DEFAULT 0' : 'DECIMAL(5,2) DEFAULT 0';
    if (DB::query($conn, "ALTER TABLE invoice_line_template_items ADD COLUMN discount_pct $type")) {
        echo "[OK] Kolonnen 'discount_pct' tilføjet til invoice_line_template_items.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Fakturaskabeloner gemmer nu linjerabatten.\n";
