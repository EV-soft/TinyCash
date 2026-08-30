<?php # /project_actions.php v:1.4.0 d:2026-08-23 i:claude
# v1.4.0: 4 fund ved en projekt-gennemgang - se [[project-bugs-review]]:
# (2) proj_no havde ingen dublet-kontrol, (3) delete_project nulstillede kun
# 4 af 7 tabeller med en proj_id-kolonne, (4) toggle_module manglede upsert
# og fejlede tavst hvis module_projects-nøglen ikke fandtes endnu.
# logger nu projektsletning til revisionssporet
# v1.3.0: rettet en åben omdirigering - return_to gik før direkte fra POST
# til header(Location:) uden validering, kunne pege et vilkårligt eksternt
# site. Tillader nu kun en lokal, relativ sti. Projekt-/kundefunktions-gennemgang.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';

// Runtime-tjek for tabeller der kun findes efter deres egen db-setup/
// migrate_*.php er kørt - samme "stille degradering hvis ikke migreret
// endnu"-mønster som resten af appen (se fx aging_report.php).
function pa_table_exists($conn, $name) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$name'");
        return ($res && $res->fetch());
    }
    $res = DB::query($conn, "SHOW TABLES LIKE '$name'");
    return ($res && DB::num_rows($res) > 0);
}

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'create_project':
        $proj_no   = DB::escape($conn, trim($_POST['proj_no'] ?? ''));
        $cust_id   = (int)($_POST['cust_id'] ?? 0);
        $start     = DB::escape($conn, $_POST['proj_start'] ?? '');
        $stop      = DB::escape($conn, $_POST['proj_stop']  ?? '');
        $desc      = DB::escape($conn, $_POST['proj_description'] ?? '');
        $concept   = DB::escape($conn, $_POST['proj_concept'] ?? '');
        $is_active = (int)($_POST['is_active'] ?? 1);

        if (empty($proj_no)) {
            header("Location: project_edit.php?id=0&err=missing_code"); exit;
        }
        // RETTET (produkt-/projekt-gennemgang, se [[project-bugs-review]]):
        // proj_no har ingen UNIQUE-begrænsning i skemaet, og der var intet
        // applikationstjek heller - to projekter kunne trivielt få samme
        // projektkode, hvilket er den primære måde et projekt genkendes på
        // i resten af appen (fakturaer, udgifter, oversigtstabellen).
        if (DB::num_rows(DB::query($conn, "SELECT proj_id FROM projects WHERE proj_no = '$proj_no'")) > 0) {
            header("Location: project_edit.php?id=0&err=duplicate_code"); exit;
        }

        $start_sql   = $start   ? "'$start'"   : 'NULL';
        $stop_sql    = $stop    ? "'$stop'"    : 'NULL';
        $cust_sql    = $cust_id ? $cust_id     : 'NULL';

        DB::query($conn, "INSERT INTO projects
            (proj_no, cust_id, proj_start, proj_stop, proj_description, proj_concept, is_active)
            VALUES ('$proj_no', $cust_sql, $start_sql, $stop_sql, '$desc', '$concept', $is_active)");

        $new_id = DB::insert_id($conn);
        header("Location: project_view.php?id=$new_id&msg=created"); exit;

    case 'update_project':
        $proj_id   = (int)($_POST['proj_id'] ?? 0);
        $proj_no   = DB::escape($conn, trim($_POST['proj_no'] ?? ''));
        $cust_id   = (int)($_POST['cust_id'] ?? 0);
        $start     = DB::escape($conn, $_POST['proj_start'] ?? '');
        $stop      = DB::escape($conn, $_POST['proj_stop']  ?? '');
        $desc      = DB::escape($conn, $_POST['proj_description'] ?? '');
        $concept   = DB::escape($conn, $_POST['proj_concept'] ?? '');
        $is_active = (int)($_POST['is_active'] ?? 1);

        if ($proj_id <= 0 || empty($proj_no)) {
            header("Location: project_edit.php?id=$proj_id&err=missing_code"); exit;
        }
        // Samme dublet-tjek som create_project, men udelukker projektets egen
        // række (ellers ville "gem uden at ændre koden" altid fejle).
        if (DB::num_rows(DB::query($conn, "SELECT proj_id FROM projects WHERE proj_no = '$proj_no' AND proj_id != $proj_id")) > 0) {
            header("Location: project_edit.php?id=$proj_id&err=duplicate_code"); exit;
        }

        $start_sql = $start   ? "'$start'"   : 'NULL';
        $stop_sql  = $stop    ? "'$stop'"    : 'NULL';
        $cust_sql  = $cust_id ? $cust_id     : 'NULL';

        DB::query($conn, "UPDATE projects SET
            proj_no = '$proj_no',
            cust_id = $cust_sql,
            proj_start = $start_sql,
            proj_stop  = $stop_sql,
            proj_description = '$desc',
            proj_concept = '$concept',
            is_active = $is_active
            WHERE proj_id = $proj_id");

        header("Location: project_view.php?id=$proj_id&msg=saved"); exit;

    case 'delete_project':
        $proj_id = (int)($_GET['id'] ?? 0);
        if ($proj_id > 0) {
            // Hent projektets data FØR sletning, til revisionssporet.
            $old_res = DB::query($conn, "SELECT proj_id, proj_no, cust_id FROM projects WHERE proj_id = $proj_id");
            $old_row = $old_res ? DB::fetch_assoc($old_res) : null;

            // Nulstil proj_id referencer i stedet for at slette data.
            // RETTET (se [[project-bugs-review]]): denne liste dækkede kun 4
            // af de 7 tabeller i skemaet der reelt har en proj_id-kolonne -
            // recurring_invoices/recurring_invoice_lines/transactions blev
            // aldrig ryddet, og fik derfor en løs reference til et projekt-ID
            // der ikke længere findes (bl.a. relevant for en gentagen faktura
            // der stadig kører videre efter det tilknyttede projekt er slettet).
            //
            // NYT (§bugs-batch-30-review): samme fejlklasse gentaget - endnu
            // tre tabeller med en proj_id-kolonne (fixed_assets, quotes/
            // quote_lines, time_entries) er alle kommet til SIDEN sidste
            // gennemgang af netop denne liste, og blev aldrig tilføjet. Et
            // slettet projekt efterlod et anlægsaktiv, et tilbud eller en
            // timeregistrering med en løs reference til et projekt-ID der
            // ikke længere findes. For time_entries tvinges is_billable=0
            // samtidig - uden et projekt er der ingen kunde at fakturere til,
            // så "fakturerbar" ville være meningsløst og kunne aldrig reelt
            // konverteres til en faktura via time_actions.php alligevel.
            DB::query($conn, "UPDATE invoices                SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "UPDATE invoice_lines           SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "UPDATE expenses                SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "UPDATE journal                 SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "UPDATE recurring_invoices       SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "UPDATE recurring_invoice_lines  SET proj_id = NULL WHERE proj_id = $proj_id");
            DB::query($conn, "UPDATE transactions             SET proj_id = NULL WHERE proj_id = $proj_id");
            if (pa_table_exists($conn, 'fixed_assets')) {
                DB::query($conn, "UPDATE fixed_assets SET proj_id = NULL WHERE proj_id = $proj_id");
            }
            if (pa_table_exists($conn, 'quotes')) {
                DB::query($conn, "UPDATE quotes       SET proj_id = NULL WHERE proj_id = $proj_id");
                DB::query($conn, "UPDATE quote_lines  SET proj_id = NULL WHERE proj_id = $proj_id");
            }
            if (pa_table_exists($conn, 'time_entries')) {
                DB::query($conn, "UPDATE time_entries SET proj_id = NULL, is_billable = 0 WHERE proj_id = $proj_id");
            }
            if (DB::query($conn, "DELETE FROM projects WHERE proj_id = $proj_id") && $old_row) {
                log_action($conn, 'DELETE_PROJECT', 'projects', $proj_id, $old_row, null);
            }
        }
        header("Location: project_view.php?msg=deleted"); exit;

    case 'toggle_module':
        // Slå projekt-modulet til/fra fra settings-siden
        $val = ($_POST['module_projects'] ?? '0') === '1' ? '1' : '0';
        // RETTET (se [[project-bugs-review]]): et rent UPDATE ramte 0 rækker
        // og fejlede derfor helt tavst, hvis module_projects-nøglen aldrig
        // var oprettet endnu (fx en frisk installation, hvor Firmaindstillinger
        // aldrig er gemt én eneste gang) - bekræftet direkte: at slå modulet
        // til fra denne side gjorde reelt ingenting i den situation.
        // company_settings.php's egen gem-logik bruger allerede korrekt
        // upsert for samme nøgle - samme mønster her.
        if (DB::is_sqlite()) {
            DB::query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('module_projects', '$val')
                              ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
        } else {
            DB::query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('module_projects', '$val')
                              ON DUPLICATE KEY UPDATE setting_value = '$val'");
        }
        // Åben omdirigering rettet: return_to kom FØR ukontrolleret fra POST
        // og blev sendt direkte til header(Location:) - en manipuleret
        // formular kunne sende brugeren videre til et hvilket som helst
        // eksternt site (fx phishing) efter en tilsyneladende tillidsfuld
        // handling. Tillader nu kun en lokal, relativ sti (samme mønster
        // browseres egen "åben omdirigering"-beskyttelse bruger: skal starte
        // med bogstav/tal, aldrig med "//" eller indeholde "://").
        $return_to = $_POST['return_to'] ?? 'project_view.php';
        if (!preg_match('#^[A-Za-z0-9_][A-Za-z0-9_\-./?=&]*$#', $return_to) || str_starts_with($return_to, '//')) {
            $return_to = 'project_view.php';
        }
        header("Location: " . $return_to); exit;

    default:
        header("Location: project_view.php"); exit;
}
?>
