<?php # /db-setup/init_demo_data.php v:1.3.0 d:2026-08-30 i:evs
# Selvstændigt, u-autentificeret førstegangs-opsætningsscript - kræver
# BEVIDST ikke admin-login (i modsætning til de andre scripts i denne
# mappe), fordi det netop bruges FØR nogen admin-bruger findes på en frisk
# installation. Dens eneste beskyttelse er tjekket nedenfor: den nægter at
# køre, hvis der allerede findes brugere. Slet filen (eller hele mappen)
# med det samme efter brug.
#
# Selve demo-data-opbygningen er udtrukket til init_demo_data.core.php's
# seed_demo_data_for($conn, $db_type) (flere-regnskaber-funktionen, Fase 2),
# så den samme logik kan genbruges af db-setup/provision_account.php mod et
# NYT regnskabs isolerede forbindelse. "Allerede har brugere"-værnet
# nedenfor lever bevidst her i det selvstændige script, ikke i selve
# funktionen - se init_demo_data.core.php's egen header for hvorfor.
/* ==========================================================================
   MINIMAL DEMO-DATABASE - Tema: H.C. Andersens eventyr
   ========================================================================== */

require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

// --- SIKKERHEDSTJEK: Nægt at køre på en base der allerede har brugere ---
$check = DB::query($conn, "SELECT COUNT(*) FROM users");
if ($check) {
    $row = DB::fetch_row($check);
    if ((int)$row[0] > 0) {
        die("STOP: Der findes allerede " . $row[0] . " bruger(e) i denne database (" . $db_type . ").\n"
          . "Scriptet nægter at køre for at undgå at blande demo-data ind i rigtige data.\n"
          . "Vil du alligevel køre det (fx i et helt tomt test-miljø), så slet users-tabellens\n"
          . "indhold manuelt først, eller peg env.ini/login-valget på en frisk, tom database.\n");
    }
}

require __DIR__ . '/init_demo_data.core.php';
seed_demo_data_for($conn, $db_type);

echo "\n";
echo "VIGTIGT: Skift kodeordet efter første login, og slet hele setup/-mappen,\n";
echo "så scripterne ikke kan køres igen eller findes af andre.\n";
