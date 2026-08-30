<?php # /account_delete.php v:1.3.0 d:2026-08-30 i:evs
# Sletter en konto fra kontoplanen efter FIRE sikkerhedstjek: (1) ingen
# posteringer i ledger, (2) ingen produkter peger på kontoen, (3) kontoen er
# ikke en af de konfigurerede særlige posteringskonti (conf_acc_bank/
# debitor/kreditor/sales/vat/purchase_vat under Firmaindstillinger) - dette
# tjek fanger kontoen selv når den endnu ikke har nogen posteringer (fx lige
# efter en frisk installation), hvor fremtidig automatisk bogføring
# (fakturering, bankafstemning, moms) ellers ville pege på et ikke-
# eksisterende acc_id, (4) kontoen er ikke valgt som aktiv- eller
# afskrivningskonto på et anlægsaktiv (se nedenfor). Sletningen logges til
# revisionssporet. Samme niveau-3-krav som chart_of_accounts.php (manglede
# FØR helt på denne side, selvom den kun nås derfra).
#
# NYT (§bugs-batch-28-review): fund #2 - tjek (4) manglede. Et anlægsaktivs
# afskrivningskonto (fixed_assets.depreciation_account_id) får INGEN
# postering i ledger før første "Kør afskrivninger" - kun selve
# anskaffelsen (asset_account_id) bogføres med det samme. En nyoprettet
# afskrivningskonto var derfor reelt IKKE beskyttet af nogen af de tre
# oprindelige tjek (ikke i ledger endnu, ikke et produkt, ikke en global
# conf_acc_*-indstilling - den vælges pr. aktiv, ikke ét sted i Firma-
# indstillinger) og kunne slettes uden varsel. Samme situation kan i
# princippet også ramme selve aktivkontoen, hvis den førnævnte postering af
# en eller anden grund aldrig nåede ledger. Da hverken ledger eller accounts
# håndhæver en fremmednøgle på acc_id (se db-setup/create_all_tables.core.
# php), ville en efterfølgende afskrivningskørsel eller afhændelse derefter
# stille bogføre til et acc_id der ikke længere findes - posteringen ville
# stadig balancere i journal/ledger, men forsvinde fra alle rapporter der
# joiner til accounts (resultatopgørelse, balance), en tavs regnskabsfejl.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // 1. SIKKERHEDSTJEK: Er kontoen i brug i din ledger (bogføring)?
    $check_ledger = DB::query($conn, "SELECT led_id FROM ledger WHERE acc_id = $id LIMIT 1");

    // 2. SIKKERHEDSTJEK: Er kontoen i brug på produkter?
    $check_products = DB::query($conn, "SELECT prod_id FROM products WHERE acc_id = $id LIMIT 1");

    // 3. SIKKERHEDSTJEK (se [[bugs-batch-10-review]]): er kontoen en af de
    // konfigurerede særlige posteringskonti (Firmaindstillinger)? Disse
    // tjekkes IKKE af ledger-tjekket ovenfor på en frisk installation, hvor
    // kontoen endnu ikke har nogen posteringer - men al fremtidig automatisk
    // bogføring (fakturering, bankafstemning, moms) ville derefter stille
    // henvise til et acc_id der ikke længere findes.
    //
    // RETTET (§bugs-batch-24-review): to fund i samme tjek.
    //  (1) manglede conf_acc_creditor helt - leverandørmodulets nye
    //      "Ikke betalt endnu"-postering (se db-setup/migrate_suppliers.php)
    //      krediterer denne konto helt uden om chartet, nøjagtig samme
    //      situation som de fem oprindelige konti dette tjek allerede
    //      beskytter mod.
    //  (2) faldback var "?? 0" for alle fem oprindelige konti, IKKE de
    //      samme faktiske standardværdier (5000/8100/1000/6900/6910) som
    //      selve posteringskoden ellers bruger overalt (expense_edit.php,
    //      vat_report.php, m.fl.) - hvis en indstilling aldrig er blevet
    //      eksplicit gemt (fx en frisk installation, eller company_settings.
    //      php's egen gem-logik der SLETTER nøglen helt ved et tomt felt),
    //      var kontoen reelt IKKE beskyttet her, selvom resten af appen
    //      allerede stille postering til den via sin egen standardværdi.
    //      Bruger nu nøjagtig samme standardværdier alle andre steder.
    $company_settings = get_settings($conn);
    $special_accounts = array_filter([
        (int)($company_settings['conf_acc_bank']         ?? 5000),
        (int)($company_settings['conf_acc_debitor']      ?? 8100),
        (int)($company_settings['conf_acc_creditor']     ?? 4000),
        (int)($company_settings['conf_acc_sales']        ?? 1000),
        (int)($company_settings['conf_acc_vat']          ?? 6900),
        (int)($company_settings['conf_acc_purchase_vat'] ?? 6910),
        // NYT (§reel-multi-valuta-bogforing): se db-setup/migrate_currency_
        // gainloss.php - samme "glemt ved næste tilføjelse"-risiko som
        // conf_acc_creditor blev fundet at have i §bugs-batch-24-review.
        // Tilføjet fra dag ét denne gang i stedet for at vente på et fund.
        (int)($company_settings['conf_acc_fx']           ?? 7200),
    ]);
    $is_special_account = in_array($id, $special_accounts, true);

    // Tjek (4): er kontoen valgt som aktiv- eller afskrivningskonto på et
    // (ikke-annulleret) anlægsaktiv? Tabellen findes kun efter db-setup/
    // migrate_fixed_assets.php er kørt - runtime-tjekket herunder holder
    // dette tjek ufarligt på en installation der endnu ikke har kørt den.
    $used_by_asset = false;
    $fa_table_exists = false;
    if (DB::is_sqlite()) {
        $tcheck = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='fixed_assets'");
        $fa_table_exists = $tcheck && DB::num_rows($tcheck) > 0;
    } else {
        $tcheck = DB::query($conn, "SHOW TABLES LIKE 'fixed_assets'");
        $fa_table_exists = $tcheck && DB::num_rows($tcheck) > 0;
    }
    if ($fa_table_exists) {
        $check_assets = DB::query($conn,
            "SELECT asset_id FROM fixed_assets WHERE status != 'cancelled' AND (asset_account_id = $id OR depreciation_account_id = $id) LIMIT 1");
        $used_by_asset = $check_assets && DB::num_rows($check_assets) > 0;
    }

    if ($used_by_asset) {
        htm_Header('@Error');
        echo "<div style='max-width:600px; margin:50px auto;'>";
        htm_Alert('@Cannot delete account: it is assigned as the asset or depreciation account on a fixed asset.', 'error');
        htm_Button('fa-arrow-left', '@Back', 'secondary', 'chart_of_accounts.php', '', 'data-hint="'.lang('@Return to the chart of accounts').'"');
        echo "</div>";
        htm_Footer();
        exit;
    }

    if ($is_special_account) {
        htm_Header('@Error');
        echo "<div style='max-width:600px; margin:50px auto;'>";
        htm_Alert('@Cannot delete account: it is configured as a special posting account in Company Settings.', 'error');
        htm_Button('fa-arrow-left', '@Back', 'secondary', 'chart_of_accounts.php', '', 'data-hint="'.lang('@Return to the chart of accounts').'"');
        echo "</div>";
        htm_Footer();
        exit;
    }

    if (DB::num_rows($check_ledger) > 0) {
        // Stop! Der er posteringer på kontoen
        htm_Header('@Error');
        echo "<div style='max-width:600px; margin:50px auto;'>";
        htm_Alert('@Cannot delete account: It has existing transactions in the ledger.', 'error');
        htm_Button('fa-arrow-left', '@Back', 'secondary', 'chart_of_accounts.php', '', 'data-hint="'.lang('@Return to the chart of accounts').'"');
        echo "</div>";
        htm_Footer();
        exit;
    } 
    elseif (DB::num_rows($check_products) > 0) {
        // Stop! Der er produkter tilknyttet
        htm_Header('@Error');
        echo "<div style='max-width:600px; margin:50px auto;'>";
        htm_Alert('@Cannot delete account: It is assigned to one or more products.', 'error');
        htm_Button('fa-arrow-left', '@Back', 'secondary', 'chart_of_accounts.php', '', 'data-hint="'.lang('@Return to the chart of accounts').'"');
        echo "</div>";
        htm_Footer();
        exit;
    }
    else {
        // Hent kontoens data FØR sletning, så revisionssporet kan vise hvad
        // der reelt blev slettet, ikke bare at "noget med id $id" er væk.
        $old_res = DB::query($conn, "SELECT acc_id, acc_name, acc_type FROM accounts WHERE acc_id = $id");
        $old_row = $old_res ? DB::fetch_assoc($old_res) : null;

        // OK - Ingen afhængigheder fundet, vi kan slette den
        $sql = "DELETE FROM accounts WHERE acc_id = $id";
        if (DB::query($conn, $sql)) {
            if ($old_row) log_action($conn, 'DELETE_ACCOUNT', 'accounts', $id, $old_row, null);
            header("Location: chart_of_accounts.php?msg=deleted");
            exit;
        } else {
            die("SQL fejl ved sletning: " . DB::error($conn));
        }
    }
} else {
    header("Location: chart_of_accounts.php");
    exit;
}
?>
