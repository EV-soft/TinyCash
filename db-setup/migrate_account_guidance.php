<?php # /db-setup/migrate_account_guidance.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NYT (BEK 811/2026-gennemgang, Bilag 3 "bogføringsguide/konteringsvejledning",
// se [[digital-standard-bookkeeping-registration-goal]]): bekendtgørelsen
// kræver at et standard bogføringssystem stiller en konteringsvejledning til
// rådighed for brugeren. Fri tekst pr. konto ("brug denne konto til X, ikke
// til Y") er en enkel, fleksibel måde at opfylde det på - uden en stiv,
// centralt vedligeholdt facitliste. Vises på account_edit.php (redigering),
// chart_of_accounts.php (opslag, ikon m. tooltip) og journal_entry.php
// (direkte ved selve konteringstidspunktet - den mest håndgribelige brug).
echo "Motor: $db_type\n\n";

$has_column = false;
if (DB::is_sqlite()) {
    $res = DB::query($conn, "PRAGMA table_info(accounts)");
    if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'guidance') { $has_column = true; break; } } }
} else {
    $res = DB::query($conn, "SHOW COLUMNS FROM accounts LIKE 'guidance'");
    $has_column = ($res && DB::num_rows($res) > 0);
}

if ($has_column) {
    echo "[SPRUNGET OVER] Kolonnen 'guidance' findes allerede på accounts.\n";
} else {
    $type = DB::is_sqlite() ? 'TEXT' : 'TEXT';
    if (DB::query($conn, "ALTER TABLE accounts ADD COLUMN guidance $type")) {
        echo "[OK] Kolonnen 'guidance' tilføjet til accounts.\n";
    } else {
        echo "[FEJL] " . DB::error($conn) . "\n";
    }
}

echo "\nFaerdig. Valgfrit tekstfelt - ingen konti har en konteringsvejledning\n";
echo "sat, foer de redigeres paa account_edit.php.\n";
