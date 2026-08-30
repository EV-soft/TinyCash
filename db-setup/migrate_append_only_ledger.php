<?php # /db-setup/migrate_append_only_ledger.php v:1.3.0 d:2026-08-30 i:evs
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

/* ==========================================================================
   DB-NIVEAU APPEND-ONLY PÅ HOVEDBOGEN (bogføringsloven)

   Uforanderlighed for bogførte poster er hidtil KUN håndhævet i PHP-koden
   (fx ledger_view.php/expense_edit.php's tjek af voucher_no før redigering/
   sletning tillades). Det beskytter mod almindelig brug af selve
   applikationen, men ikke mod direkte database-adgang (en SQL-klient, et
   kompromitteret DB-login, eller en fremtidig kodefejl der glemmer tjekket).
   Sidste åbne punkt fra bogføringslov-analysen. Bruger-anmodet (fra
   forslagslisten).

   v1.1.0: selve trigger-logikken er flyttet til en delt funktion,
   create_append_only_triggers() i inc/db_connect.inc.php, som også kaldes
   fra create_all_tables.core.php (friske installationer) - så de to steder
   ikke kan glide fra hinanden. Se funktionens egen kommentar for hvad hver
   trigger konkret tillader/blokerer.

   VIGTIGT: DB::dump_to_sql() medtager nu også triggere i backup-dumps
   (samme dag), så en gendannelse ikke stille fjerner denne beskyttelse igen.
   ========================================================================== */

echo "Motor: $db_type\n\n";

// RETTET (samme sweep som create_append_only_triggers()'s egen rettelse,
// se dens kommentar): "SHOW TRIGGERS" kan kaste en mysqli_sql_exception i
// stedet for at returnere false ved manglende rettighed (PHP >=8.1) - dette
// tal er kun til orientering i outputtet, må aldrig vælte selve migrationen.
$before = 0;
if (DB::is_sqlite()) {
    $before = count($pdo->query("SELECT name FROM sqlite_master WHERE type='trigger'")->fetchAll(PDO::FETCH_COLUMN));
} else {
    try {
        $r = mysqli_query($conn, "SHOW TRIGGERS");
        $before = $r ? mysqli_num_rows($r) : 0;
    } catch (\Throwable $e) {
        $before = 0;
    }
}

$result  = create_append_only_triggers($conn);
$created = $result['created'];
$failed  = $result['failed'];

// RETTET 2026-08-20: $created=0 blev FØR altid tolket som "alle findes
// allerede" - men kunne lige så godt betyde at alle forsøg fejlede stille
// (fx manglende TRIGGER-rettighed, ikke ualmindeligt på delt hosting).
// Fejlen forsvandt kun i PHP's error_log, usynlig for admin. Viser nu de
// faktiske fejl, hvis der er nogen, i stedet for en falsk "allerede anvendt".
if ($created > 0) {
    echo "[OK] $created ny(e) trigger(e) oprettet.\n";
}
if (!empty($failed)) {
    echo "[FEJL] " . count($failed) . " trigger(e) KUNNE IKKE oprettes:\n";
    foreach ($failed as $name => $msg) {
        echo "  - $name: $msg\n";
    }
    echo "Tjek at databasebrugeren har TRIGGER-rettigheden (almindeligt begrænset på delt hosting).\n";
} elseif ($created === 0) {
    echo "[SPRUNGET OVER] Alle triggere findes allerede (var $before før kørsel).\n";
}

echo "\nFærdig. Bogførte poster i journal/ledger er nu beskyttet mod ændring/sletning på selve databaseniveauet, uanset adgangsvej.\n";
if (!empty($failed)) {
    echo "OBS: se fejlene ovenfor - beskyttelsen er IKKE fuldt aktiveret endnu.\n";
}
?>
