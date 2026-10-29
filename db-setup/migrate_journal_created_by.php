<?php # /db-setup/migrate_journal_created_by.php v:1.3.1 d:2026-09-15 i:evs
// auth.inc.php bruger CWD-relative includes; chdir til roden så det virker fra db-setup/.
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

/* ==========================================================================
   BOGFØRINGSLOV: BRUGER-ID PÅ SELVE JOURNALPOSTEN (BEK 811/2026-gennemgang,
   se [[digital-standard-bookkeeping-registration-goal]])

   ledger fik allerede sit eget created_at+user_id 2026-08-30 (se
   migrate_ledger_audit.php) - men selve journal-raden (headeren for en
   postering) havde intet tilsvarende felt. Enhver bogført postering har
   altid mindst én ledger-linje, så "hvem bogførte" kunne i praksis altid
   findes INDIREKTE (journal.jou_id -> ledger.user_id) - men bekendtgørelsens
   krav om "bruger-id for hver bogført transaktion" opfyldes mere direkte og
   utvetydigt ved at have feltet på selve journal-raden, ikke kun på dens
   underliggende linjer.

   Samme "ingen DEFAULT CURRENT_TIMESTAMP ved ALTER på SQLite"-forbehold som
   migrate_ledger_audit.php - irrelevant her (created_by er et heltal, ikke
   et tidsstempel), nævnt kun for sammenhængens skyld.

   Kører create_append_only_triggers() til sidst, EFTER kolonnen er
   tilføjet - trg_journal_restrict_update_posted udvides da automatisk til
   også at beskytte denne nye kolonne (se dens egen kommentar i inc/
   db_connect.inc.php for hvorfor rækkefølgen er vigtig: en trigger der
   refererer til en endnu ikke-eksisterende kolonne ville fejle øjeblikkeligt
   ved selve CREATE TRIGGER-kaldet).
   ========================================================================== */

echo "Motor: $db_type\n\n";

function journal_has_column($conn, $col) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(journal)");
        if ($res) {
            while ($row = DB::fetch_assoc($res)) {
                if (strtolower($row['name']) === strtolower($col)) return true;
            }
        }
        return false;
    }
    $res = DB::query($conn, "SHOW COLUMNS FROM journal LIKE '" . DB::escape($conn, $col) . "'");
    return ($res && DB::num_rows($res) > 0);
}

if (journal_has_column($conn, 'created_by')) {
    echo "[SPRUNGET OVER] Kolonnen 'created_by' findes allerede på journal.\n";
} else {
    $type = DB::is_sqlite() ? 'INTEGER' : 'INT DEFAULT NULL';
    if (DB::query($conn, "ALTER TABLE journal ADD COLUMN created_by $type")) {
        echo "[OK] Kolonnen 'created_by' tilføjet til journal.\n";
    } else {
        echo "[FEJL] created_by: " . DB::error($conn) . "\n";
    }
}

echo "\nOpdaterer append-only-triggerne, så den nye kolonne også beskyttes...\n";
$trg_result = create_append_only_triggers($conn);
if (!empty($trg_result['failed'])) {
    echo "[FEJL] " . count($trg_result['failed']) . " trigger(e) kunne ikke (gen)oprettes:\n";
    foreach ($trg_result['failed'] as $name => $msg) {
        echo "  - $name: $msg\n";
    }
    echo "Tjek at databasebrugeren har TRIGGER-rettigheden (almindeligt begrænset på delt hosting).\n";
} else {
    echo "[OK] " . $trg_result['created'] . " trigger(e) oprettet/opdateret.\n";
}

echo "\nFærdig. Nye bogførte posteringer får nu bruger-ID direkte på journal-raden, og bogførte journalposter kan ikke få dette felt ændret bagefter.\n";
?>
