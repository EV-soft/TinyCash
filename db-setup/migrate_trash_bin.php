<?php # /db-setup/migrate_trash_bin.php v:1.3.1 d:2026-09-15 i:evs
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 31):
// sletning af en kunde/leverandør var permanent og uigenkaldelig (siden
// sagde det ligefrem: "Dette kan ikke fortrydes") - kun beskyttet mod at
// slette en kunde/leverandør der reelt er koblet til fakturaer/udgifter
// (det tjek bevares UÆNDRET). En slettet, ubrugt testkunde var tabt for
// evigt ved et uheld. Tilføjer deleted_at til begge tabeller - "Slet"
// sætter nu blot denne dato i stedet for at fjerne rækken, og en ny
// trash_bin.php lader en admin gendanne (eller slette permanent, med
// samme sikkerhedstjek som hidtil) fra en oversigt.
echo "Motor: $db_type\n\n";

foreach (['customers', 'suppliers'] as $table) {
    $has_column = false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info($table)");
        if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === 'deleted_at') { $has_column = true; break; } } }
    } else {
        $res = DB::query($conn, "SHOW COLUMNS FROM $table LIKE 'deleted_at'");
        $has_column = ($res && DB::num_rows($res) > 0);
    }

    if ($has_column) {
        echo "[SPRUNGET OVER] Kolonnen 'deleted_at' findes allerede på $table.\n";
    } else {
        $type = DB::is_sqlite() ? 'TEXT' : 'DATETIME';
        if (DB::query($conn, "ALTER TABLE $table ADD COLUMN deleted_at $type")) {
            echo "[OK] Kolonnen 'deleted_at' tilføjet til $table.\n";
        } else {
            echo "[FEJL] " . DB::error($conn) . "\n";
        }
    }
}

echo "\nFærdig. NULL = ikke slettet (uændret for alle eksisterende rækker).\n";
?>
