<?php # /db-setup/migration_credit.php v:1.3.0 d:2026-08-30 i:evs
# v1.4.0: KRITISK FUND (bruger-rapporteret + diagnosticeret live mod en
# rigtig MySQL-installation) - MySQL-grenens eksistenstjek brugte
# "DB::fetch_assoc($res) !== false". PHP's mysqli_fetch_assoc() returnerer
# NULL (ikke false) når resultatet har 0 rækker - kun false ved en reelt
# fejlet forespørgsel. "null !== false" er altid SAND, så $exists blev
# ALTID true på MySQL, uanset om kolonnen reelt fandtes. Konsekvens:
# ALTER TABLE blev ALDRIG kørt for nogen af de tre kolonner på MySQL - denne
# migration har formentlig ALDRIG reelt tilføjet credit_ref/no_attachment_
# reason/cancelled_by på nogen MySQL-installation, selvom den altid har
# rapporteret succes (og selv logget migration_key'en ubetinget til
# system_migrations, jf. linje til sidst) - bekræftet direkte: en rigtig
# SELECT * FROM invoices viste INGEN credit_ref-kolonne, selvom migrationen
# gentagne gange havde meldt "allerede til stede, springer over". Rettet til
# "is_array(...)", som korrekt kun er sandt ved en reel fundet række.
# v1.5.0: Yderligere fund samme dag - selve ALTER TABLE-kaldets returværdi
# blev ALDRIG tjekket. Scriptet printede "✓ tilføjet" og talte $ok op
# UBETINGET, uanset om ALTER TABLE reelt lykkedes eller fejlede. Rettet til
# at tjekke resultatet og vise den rigtige DB::error()-besked ved fejl,
# samme mønster som søster-migrationen migrate_cancelled_by.php allerede
# bruger korrekt.
# Tilføjer credit_ref kolonne på invoices og no_attachment_reason på expenses
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';

$is_sqlite = DB::is_sqlite();
$ok = 0; $skip = 0; $fail = 0;

$alters = [
    // Kreditnota-reference på fakturaer
    ['invoices',  'credit_ref',            'INTEGER DEFAULT NULL'],
    // Bilagsmangelsbegrundelse på udgifter
    ['expenses',  'no_attachment_reason',   'TEXT DEFAULT NULL'],
    // Krediteret og annulleret af på udgifter
    ['expenses',  'cancelled_by',           'INTEGER DEFAULT NULL'],
];

foreach ($alters as [$tbl, $col, $def]) {
    // Tjek om kolonnen allerede eksisterer
    if ($is_sqlite) {
        $res = DB::query($conn, "PRAGMA table_info($tbl)");
        $exists = false;
        while ($r = DB::fetch_assoc($res)) {
            if ($r['name'] === $col) { $exists = true; break; }
        }
    } else {
        $res = DB::query($conn, "SHOW COLUMNS FROM $tbl LIKE '$col'");
        // RETTET: "!== false" var altid sandt her - mysqli_fetch_assoc()
        // returnerer NULL (ikke false) ved 0 rækker, og "null !== false" er
        // altid true. is_array() er korrekt: kun sand hvis en rigtig række
        // reelt blev fundet.
        $exists = is_array(DB::fetch_assoc($res));
    }

    if ($exists) {
        echo "<p style='color:var(--text-muted); font-family:sans-serif;'>⏭ $tbl.$col — allerede til stede, springer over.</p>";
        $skip++;
    } else {
        $alter_res = DB::query($conn, "ALTER TABLE $tbl ADD COLUMN $col $def");
        // RETTET: resultatet blev FØR aldrig tjekket - "✓ tilføjet" blev vist
        // og $ok talt op ubetinget, selv hvis selve ALTER TABLE fejlede.
        if ($alter_res) {
            echo "<p style='color:green; font-family:sans-serif;'>✓ $tbl.$col tilføjet.</p>";
            $ok++;
        } else {
            echo "<p style='color:red; font-family:sans-serif;'>❌ $tbl.$col FEJLEDE: " . htmlspecialchars(DB::error($conn)) . "</p>";
            $fail++;
        }
    }
}

// Registrer KUN migrationen som gennemført, hvis intet reelt fejlede -
// FØR blev nøglen logget ubetinget, uanset om nogen ALTER TABLE fejlede,
// hvilket er præcis hvorfor denne migration tidligere så ud til at være
// "kørt" på live, selvom kolonnen aldrig reelt kom med.
if ($fail === 0) {
    $mig_key = 'credit_note_columns_2026_08_05';
    $mig_sql = $is_sqlite
        ? "INSERT OR IGNORE INTO system_migrations (migration_key) VALUES ('$mig_key')"
        : "INSERT IGNORE INTO system_migrations (migration_key) VALUES ('$mig_key')";
    DB::query($conn, $mig_sql);
}

echo "<p style='font-family:sans-serif; margin-top:10px; font-weight:bold;'>Migration fuldført — $ok kolonner tilføjet, $skip sprunget over, $fail fejlede.</p>";
if ($fail > 0) {
    echo "<p style='color:red; font-family:sans-serif;'>Migrationen er IKKE registreret som fuldført, fordi mindst én kolonne fejlede - ret fejlen ovenfor og kør scriptet igen.</p>";
}
?>
