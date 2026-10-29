<?php # /db-setup/migrate_invoice_external_ref.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (webhook-fakturaoprettelse, §webhook-invoice-feature):
// invoices.external_ref bærer webshoppens/POS'ens eget ordre-id, så et
// gentaget/duplikeret webhook-kald (netværks-timeout + retry er normalt for
// denne slags integrationer) kan genkendes og afvises i stedet for at
// oprette den samme faktura to gange. NULL for alle fakturaer oprettet uden
// om webhooken (uændret adfærd).
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(invoices)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'external_ref') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM invoices LIKE 'external_ref'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'external_ref' findes allerede på invoices.\n";
} else {
    $type = DB::is_sqlite() ? 'TEXT' : 'VARCHAR(255)';
    if (DB::query($conn, "ALTER TABLE invoices ADD COLUMN external_ref $type")) {
        echo "[OK] Kolonnen 'external_ref' tilføjet til invoices.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
        exit;
    }
}

// Indeks til hurtigt opslag ved idempotens-tjek (webhook_invoice.php slår op
// pr. kald). Ikke UNIQUE - en NULL-værdi (alle almindelige fakturaer) må
// gerne forekomme ubegrænset mange gange; kun webhookens eget PHP-lag
// håndhæver entydighed pr. ikke-NULL external_ref.
$idx_exists = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA index_list(invoices)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if ($row['name'] === 'idx_invoices_external_ref') { $idx_exists = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW INDEX FROM invoices WHERE Key_name = 'idx_invoices_external_ref'");
    $idx_exists = ($res && DB::num_rows($res) > 0);
}
if ($idx_exists) {
    echo "[SPRUNGET OVER] Indeks 'idx_invoices_external_ref' findes allerede.\n";
} else {
    if (DB::query($conn, "CREATE INDEX idx_invoices_external_ref ON invoices (external_ref)")) {
        echo "[OK] Indeks 'idx_invoices_external_ref' oprettet.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFærdig. Bruges af webhook_invoice.php til at afvise gentagne/duplikerede\n";
echo "webhook-kald (samme external_ref) i stedet for at oprette dobbelt-fakturaer.\n";
