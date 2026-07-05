<?php # inc/db_migrate.php v:1.1.0 d:2026-07-02 i:evs
require_once __DIR__ . '/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once __DIR__ . '/php2htm.lib.php';

// Adgangskontrol
session_name('TCC_V100_SESSION');
session_start();
if (!isset($_SESSION['user_level']) || $_SESSION['user_level'] < 3) {
    die("Access Denied");
}

// Definer alle dine migrationer her
$migrations = [
    'add_jou_id'      => "ALTER TABLE invoices ADD COLUMN jou_id INTEGER DEFAULT NULL",
    'add_inv_status'  => "ALTER TABLE invoices ADD COLUMN inv_status_new VARCHAR(20) DEFAULT 'draft'",
    'add_trans_type'  => "ALTER TABLE journal ADD COLUMN trans_type VARCHAR(20) DEFAULT 'manual'",
    'add_voucher_no'  => "ALTER TABLE ledger ADD COLUMN voucher_no INT(11) DEFAULT NULL"
];
// 1. Header kaldes (indeholder menuen)
htm_Header("Migration Engine");
showMenu();

// 2. Start containeren for at sikre kontrast
echo '<div class="cardW000">';
echo '<h1>Database Migration</h1>';

foreach ($migrations as $key => $sql) {
    try {
        DB::query($conn, $sql);
        echo "<p style='color:var(--color-success);'>✅ Success: $key</p>";
    } catch (Exception $e) {
        echo "<p style='color:var(--color-danger);'>⚠️ Notice: $key - " . $e->getMessage() . "</p>";
    }
}

echo '</div>'; // Slut container

// 3. Footer kaldes
htm_Footer();
?>