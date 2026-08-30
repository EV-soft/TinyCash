<?php # /db-setup/create_all_tables.php v:1.3.0 d:2026-08-30 i:evs
# v1.3.0: en frisk installation (init_demo_data.php + denne fil) var reelt
# ikke køreklar - manglede hele projects-tabellen, proj_id på 5 tabeller,
# fiscal_years-tabellen, og flere invoices/expenses-kolonner (cust_reference,
# credit_ref, orig_currency, exch_rate, exp_type, no_attachment_reason,
# cancelled_by) som kun fandtes via migrate_*.php-scripts der ikke længere
# står i db-setup/README.md. Tilføjet direkte til basis-skemaet + README
# opdateret. Verificeret ved en reel kørsel mod en helt tom database.
# v1.4.0: selve tabel-opsætningen flyttet til create_all_tables.core.php, så
# den kan genbruges af bootstrap_index.php (den midlertidige førstegangs-
# opsætningsside i ZIP-fresh-install-pakken, som ikke kan admin-logge ind
# først). Denne fil er nu kun auth-gate + kald af kernefilen.
# create_all_tables.core.php definerer nu create_all_tables_for($conn,
# $db_type) som en kaldbar funktion (flere-regnskaber-funktionen, Fase 2,
# se db-setup/provision_account.php) i stedet for et selv-kørende script -
# denne fil kalder den eksplicit efter require, uændret adfærd for denne
# kalder.
/* ==========================================================================
   OPRETTER ALLE TABELLER FRA DET FULDE TINYCASH-SKEMA (hvis de mangler).
   Se README.md i denne mappe for rækkefølge og formål.

   SIKKERHED: kræver login som admin (samme regel som resten af systemets
   administrative sider) - ikke længere tilgængelig for hvem som helst.
   100% SIKKERT AT GENKØRE: bruger udelukkende CREATE TABLE IF NOT EXISTS.

   v1.2.0: manglede chdir(dirname(__DIR__)) - eneste fil i db-setup/ der
   aldrig fik den rettelse resten af mappen fik (se subdir-scripts-need-chdir
   i hukommelsen). auth.inc.php's egne require_once-kald er CWD-relative
   ('inc/php2htm.lib.php'), så uden chdir() leder den forkert sted når
   scriptet køres direkte fra db-setup/ (ellers 500/fatal error).
   ========================================================================== */

    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500/fatal error).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/create_all_tables.core.php';
create_all_tables_for($conn, $db_type);
