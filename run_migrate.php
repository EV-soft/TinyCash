<?php # /run_migrate.php v:1.3.0 d:2026-08-30 i:evs
# v2.0.0: ALVORLIGT FUND - denne side (menuens "Database migration"-link)
# kørte reelt kun ÉN eneste, gammel migration (migrate_cust_reference.php,
# fra 2026-07-13) og var derfor stærkt forældet. De 17 øvrige migrationer
# skrevet siden (fakturavaluta, bogføringslov/append-only, delvis betaling,
# gentagne fakturaer, bankintegration, 2FA m.fl. - se
# pending-deployment-checklist) blev ALDRIG kørt fra denne side, kun ved at
# gætte de enkelte filnavne direkte i browserens adresselinje. Omskrevet til
# et rigtigt migrations-overblik: lister ALLE kendte migrationsscripts i
# db-setup/, med en direkte statustjek pr. migration (findes tabellen/
# kolonnen/kontoen/triggeren allerede?), og et link til at køre hver enkelt.
# Kører IKKE alle på én gang (hver migration er sit eget selvstændige,
# allerede idempotente script med sin egen fejlhåndtering og visning -
# at kæde dem sammen i ét kald ville blande deres separate tekst-outputs
# sammen og risikere Content-Type/header-konflikter for ingen reel gevinst).
ob_start();
require_once 'inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

// --- Generiske statustjek, genbrugt af flere migrationer nedenfor ---
// RETTET 2026-08-20: MySQL-grenene brugte "SHOW TABLES LIKE"/"SHOW COLUMNS
// FROM ... LIKE" - bruger-bekræftet at give forkert "Mangler"-status på en
// rigtig MySQL-installation for en migration der reelt allerede var anvendt
// (migration_credit.php meldte "allerede til stede" for alle tre kolonner,
// men dashboardet havde vist "Mangler"). Erstattet med information_schema-
// forespørgsler mod DATABASE() (den aktuelt valgte database) - samme
// afprøvede mønster migration_projects.php/migration_credit.php selv bruger
// til MySQL, i stedet for SHOW-varianterne, som er mere følsomme over for
// LIKE-mønster-fortolkning (% og _ er wildcards, kan i sjældne tilfælde give
// falske positive/negative for kolonne-/tabelnavne der indeholder dem) og
// generelt mindre robuste på tværs af MySQL-versioner/-konfigurationer end
// et almindeligt SELECT mod information_schema.
function rm_table_exists($conn, $table) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        return ($res && DB::fetch_assoc($res));
    }
    $res = DB::query($conn, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'");
    return ($res && DB::fetch_assoc($res));
}
function rm_column_exists($conn, $table, $column) {
    if (!rm_table_exists($conn, $table)) return false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info($table)");
        if ($res) {
            while ($row = DB::fetch_assoc($res)) {
                if (strtolower($row['name']) === strtolower($column)) return true;
            }
        }
        return false;
    }
    $res = DB::query($conn, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$column'");
    return ($res && DB::fetch_assoc($res));
}
function rm_account_exists($conn, $acc_id) {
    $res = DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = " . (int)$acc_id);
    return ($res && DB::fetch_assoc($res));
}
function rm_trigger_exists($conn, $name) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='trigger' AND name='$name'");
        return ($res && DB::fetch_assoc($res));
    }
    $res = DB::query($conn, "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$name'");
    return ($res && DB::fetch_assoc($res));
}
function rm_migration_logged($conn, $key) {
    if (!rm_table_exists($conn, 'system_migrations')) return false;
    $res = DB::query($conn, "SELECT id FROM system_migrations WHERE migration_key = '" . DB::escape($conn, $key) . "'");
    return ($res && DB::fetch_assoc($res));
}
function rm_index_exists($conn, $table, $index) {
    if (!rm_table_exists($conn, $table)) return false;
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='index' AND name='$index'");
        return ($res && DB::fetch_assoc($res));
    }
    $res = DB::query($conn, "SELECT INDEX_NAME FROM information_schema.STATISTICS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = '$index'");
    return ($res && DB::fetch_assoc($res));
}

// --- Alle kendte migrationer, ældste til nyeste ---
// RETTET (§bugs-batch-16-review): db-setup/db_migrate.php - dokumenteret i
// selve CLAUDE.md som en del af migrationssystemet - manglede helt her, fordi
// den ikke følger migrate_*.php/migration_*.php-navnekonventionen dette
// dashboard oprindeligt blev bygget ved at gennemsøge efter (se
// [[run-migrate-dashboard]]). Filen er derfor helt ureferet fra resten af
// appen (intet menupunkt, ikke listet her) - kun findbar ved at kende/gætte
// den præcise URL. Kun relevant for installationer ældre end 2026-07-06
// (friske installationer har allerede disse valuta-kolonner direkte i
// create_all_tables.core.php), men et dashboard der hævder at vise "ALLE
// kendte migrationer" bør reelt vise alle kendte migrationer.
$migrations = [
    ['file' => 'db_migrate.php', 'label' => 'Valuta-kolonner (juli 2026)', 'desc' => 'currency på expenses/invoices/journal/invoice_lines/transactions. Kører alle på én gang (ikke ét script pr. kolonne som resten af listen).',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'currency') && rm_column_exists($c, 'expenses', 'currency')],
    // TILFØJET (bruger-anmodet "find 5 fejl"-runde): fandtes i db-setup/, gør
    // sit arbejde korrekt og er fuldt idempotent, men var slet ikke listet
    // her og lå ikke linket noget andet sted i appen - en admin, der havde
    // brug for den (ældre installationer uden login_log-tabellen), kunne kun
    // finde den ved at gætte det præcise filnavn.
    ['file' => 'fix_login_log.php', 'label' => 'Login-log (reparation af ældre installationer)', 'desc' => 'Opretter kun login_log-tabellen, hvis den mangler - for installationer sat op før tabellen indgik i den almindelige opsætning.',
     'check' => fn($c) => rm_table_exists($c, 'login_log')],
    ['file' => 'migration_projects.php',        'label' => 'Projekt-modul (v1.3.0-tilføjelser)',   'desc' => 'expenses.exp_type + projects.note_expenses/note_income/note_general. Kræver at projects-tabellen allerede findes.',
     'check' => fn($c) => rm_column_exists($c, 'expenses', 'exp_type') && rm_column_exists($c, 'projects', 'note_expenses')],
    ['file' => 'migrate_equity_account.php',     'label' => 'Egenkapital-konto',                    'desc' => 'Opretter standard-egenkapitalkontoen (3000) hvis den mangler.',
     'check' => fn($c) => rm_account_exists($c, 3000)],
    ['file' => 'migrate_liability_account.php',  'label' => 'Gældskonto',                           'desc' => 'Opretter standard-gældskontoen (4000) hvis den mangler.',
     'check' => fn($c) => rm_account_exists($c, 4000)],
    ['file' => 'migrate_fiscal_years.php',       'label' => 'Regnskabsår',                          'desc' => 'Tabellen fiscal_years (understøtter forskudt regnskabsår).',
     'check' => fn($c) => rm_table_exists($c, 'fiscal_years')],
    ['file' => 'migrate_cust_reference.php',     'label' => 'Kundereference på faktura',            'desc' => 'invoices.cust_reference (fx ordrenummer/EAN).',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'cust_reference')],
    ['file' => 'migrate_cancelled_by.php',       'label' => 'Annulleret-af på udgift',              'desc' => 'expenses.cancelled_by.',
     'check' => fn($c) => rm_column_exists($c, 'expenses', 'cancelled_by')],
    ['file' => 'migration_credit.php',           'label' => 'Kreditnota-understøttelse',            'desc' => 'invoices.credit_ref + expenses.no_attachment_reason/cancelled_by.',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'credit_ref')],
    ['file' => 'migration_auto_backup.php',      'label' => 'Automatisk backup - standardindstillinger', 'desc' => 'Seeder settings-nøglerne for den automatiske krypterede off-site backup.',
     'check' => fn($c) => rm_migration_logged($c, 'auto_backup_settings_2026_08_04')],
    ['file' => 'migrate_invoice_currency.php',   'label' => 'Fremmed valuta på faktura',            'desc' => 'invoices.orig_currency + exch_rate (nødvendig for at kunne gemme en faktura overhovedet, hvis manglende - se invoices-currency-migration-gap).',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'orig_currency')],
    ['file' => 'migrate_ledger_audit.php',       'label' => 'Revisionsfelter på hovedbog',          'desc' => 'ledger.created_at + user_id.',
     'check' => fn($c) => rm_column_exists($c, 'ledger', 'created_at')],
    ['file' => 'migrate_voucher_counter.php',    'label' => 'Fælles, hulfrit bilagsnummer',         'desc' => 'Tabellen voucher_counter - uden den falder bilagsnumre tilbage til et best-effort MAX+1 (ikke fuldt hulfrit på tværs af posteringstyper).',
     'check' => fn($c) => rm_table_exists($c, 'voucher_counter')],
    ['file' => 'migrate_invoice_no_counter.php', 'label' => 'Fælles fakturanummer-tæller',          'desc' => 'Tabellen invoice_no_counter - løser en kapløbs-sårbarhed i det gamle "MAX(invoice_no)+1"-mønster.',
     'check' => fn($c) => rm_table_exists($c, 'invoice_no_counter')],
    ['file' => 'migrate_append_only_ledger.php', 'label' => 'DB-niveau append-only på hovedbogen',  'desc' => 'SQLite/MySQL-triggere der forhindrer sletning/ændring af en bogført journal-/ledger-postering, uanset adgangsvej. Sidste punkt fra bogføringslov-gennemgangen.',
     'check' => fn($c) => rm_trigger_exists($c, 'trg_journal_no_delete_posted')],
    ['file' => 'migrate_invoice_reminders.php',  'label' => 'Rykkere for forfaldne fakturaer',      'desc' => 'invoices.reminder_sent_at + reminder_count.',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'reminder_sent_at')],
    ['file' => 'migrate_invoice_payments.php',   'label' => 'Delvis betaling af fakturaer',         'desc' => 'Tabellen invoice_payments - sporer hver enkelt indbetaling mod en faktura.',
     'check' => fn($c) => rm_table_exists($c, 'invoice_payments')],
    ['file' => 'migrate_recurring_invoices.php', 'label' => 'Gentagne/faste fakturaer',             'desc' => 'Tabellerne recurring_invoices + recurring_invoice_lines.',
     'check' => fn($c) => rm_table_exists($c, 'recurring_invoices')],
    ['file' => 'migrate_bank_integration.php',   'label' => 'Rigtig bankintegration (PSD2)',        'desc' => 'Tabellen bank_connections (nu mod Enable Banking - GoCardless lukkede for nye tilmeldinger juli 2025).',
     'check' => fn($c) => rm_table_exists($c, 'bank_connections') && rm_column_exists($c, 'bank_connections', 'state_token')],
    ['file' => 'migrate_2fa.php',                'label' => 'To-faktor-login (2FA)',                'desc' => 'users.totp_secret/totp_enabled/totp_recovery_codes.',
     'check' => fn($c) => rm_column_exists($c, 'users', 'totp_enabled')],
    ['file' => 'migrate_menu_visibility.php',    'label' => 'Menu-synlighed pr. brugerniveau',      'desc' => 'Tabellen menu_visibility - styrer hvilke menu-punkter der vises for hvilke brugerniveauer (System -> Maintenance -> Menu-synlighed).',
     'check' => fn($c) => rm_table_exists($c, 'menu_visibility')],
    ['file' => 'migrate_product_sku_unique.php', 'label' => 'Unikt SKU på produkter',               'desc' => 'Unikt indeks på products.prod_sku (+ normaliserer tomme SKU\'er til NULL) - lukker et kapløb hvor to samtidige produktoprettelser kunne få samme SKU (§bugs-batch-23-review).',
     'check' => fn($c) => rm_index_exists($c, 'products', 'idx_products_sku_unique')],
    ['file' => 'migrate_suppliers.php',          'label' => 'Leverandørmodul',                      'desc' => 'Tabellen suppliers + expenses.supplier_id/due_date/paid_date - leverandørstamdata og "Ikke betalt endnu"-udgifter (kreditor-siden af Aldersfordelt restanceliste).',
     'check' => fn($c) => rm_table_exists($c, 'suppliers') && rm_column_exists($c, 'expenses', 'paid_date')],
    ['file' => 'migrate_fixed_assets.php',       'label' => 'Anlægsaktiver/afskrivninger',          'desc' => 'Tabellen fixed_assets + standardkonti 8200/2600 - anlægskartotek med rigtig, bogført lineær afskrivning.',
     'check' => fn($c) => rm_table_exists($c, 'fixed_assets')],
    ['file' => 'migrate_quotes.php',             'label' => 'Tilbud/Ordrebekræftelse',              'desc' => 'Tabellerne quotes/quote_lines/quote_no_counter - tilbud der aldrig påvirker bogføringen, kun konvertering til en rigtig fakturakladde efter accept.',
     'check' => fn($c) => rm_table_exists($c, 'quotes')],
    ['file' => 'migrate_time_tracking.php',      'label' => 'Timeregistrering',                     'desc' => 'Tabellen time_entries + projects.default_hourly_rate - loggede timer pr. projekt, der kan samles til en fakturakladde ("Opret faktura af timer").',
     'check' => fn($c) => rm_table_exists($c, 'time_entries')],
    // RETTET (bruger-anmodet "find 5 fejl"-runde): "modtaget DKK-beløb" var
    // forældet siden §currency-setting-is-cosmetic-label Fase 1 gjorde
    // bogføringsvalutaen konfigurerbar - selve koden (reconcile_action.php)
    // er allerede valuta-agnostisk, kun denne beskrivelsestekst var ikke
    // opdateret. Samme fejlklasse som about.php-rettelsen i tidligere runde.
    ['file' => 'migrate_currency_gainloss.php',  'label' => 'Kursgevinst/-tab ved betaling',         'desc' => 'Konto 7200 "Kursgevinst/-tab, valuta" - bruges ved bankafstemning når en udenlandsk faktura afsluttes med en kursforskel mellem bogført og modtaget beløb i firmaets bogføringsvaluta.',
     'check' => fn($c) => rm_account_exists($c, 7200)],
    // TILFØJET (bruger-rapporteret: langsom sidevisning ved sideskift): tre
    // manglende indekser fundet under samme fejlsøgning som rettede
    // inc/auto_backup.inc.php's tidsstempel-bug (se filens egen header-
    // kommentar) - uden dem var auto_backup_check()'s ændrings-tjek (kørt på
    // hver sidevisning, hver 7. dag) fulde tabel-scanninger.
    ['file' => 'migrate_auto_backup_indexes.php', 'label' => 'Indekser til automatisk backup-tjek',   'desc' => 'Indeks på audit_log.log_date, expenses.created_at og journal.created_at - bruges af auto_backup_check() (kørt på hver sidevisning) til at afgøre om noget er ændret siden sidste backup.',
     'check' => fn($c) => rm_index_exists($c, 'audit_log', 'idx_audit_log_date') && rm_index_exists($c, 'expenses', 'idx_expenses_created') && rm_index_exists($c, 'journal', 'idx_journal_created')],
];

htm_Header('@Database Migration', 1100);
showMenu();

htm_Card_(capt: '@Database Migration', wdth: 1100);
echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@All migrations below are idempotent - safe to run more than once. Each opens its own page with the result. Status is checked live against your current database.') . '</p>';

$headers = ['@Migration', '@Description', '@Status', '@Actions'];
$data = [];
$pending_count = 0;

foreach ($migrations as $m) {
    $applied = false;
    try {
        $applied = (bool)$m['check']($conn);
    } catch (\Throwable $e) {
        $applied = false; // en fejlet statustjek (fx en helt frisk DB uden tabellen) tolkes som "ikke anvendt endnu"
    }
    if (!$applied) $pending_count++;

    $status_html = $applied
        ? '<span style="color:var(--color-success); font-weight:bold;"><i class="fa fa-check-circle"></i> ' . lang('@Applied') . '</span>'
        : '<span style="color:var(--color-warning); font-weight:bold;"><i class="fa fa-exclamation-circle"></i> ' . lang('@Pending') . '</span>';

    $run_label = $applied ? lang('@Run again') : lang('@Run now');
    $run_style = $applied ? 'background:var(--color-secondary);' : 'background:var(--color-primary);';
    $actions = '<a href="db-setup/' . htmlspecialchars($m['file']) . '" target="_blank" style="display:inline-block; padding:5px 12px; ' . $run_style . ' color:#fff; border-radius:4px; text-decoration:none; font-size:0.85em;">' . $run_label . '</a>';

    $data[] = [htmlspecialchars($m['label']), htmlspecialchars($m['desc']), $status_html, $actions];
}

if ($pending_count > 0) {
    // RETTET (§bugs-batch-22-review, del b): htm_Banner() (ny, se
    // csrf-protection-added.md) er den rigtige funktion her - en STÅENDE
    // sidenotits, ikke et engangs-handlings-resultat (det er htm_Alert()'s
    // formål). Erstatter den tidligere håndrullede venstre-kant-boks.
    htm_Banner("<i class='fa fa-exclamation-triangle'></i> " . sprintf(lang('@%d migration(s) not yet applied on this installation.'), $pending_count), 'warning');
} else {
    htm_Alert(lang('@All known migrations are applied on this installation.'), 'success');
}

htm_Table($headers, $data, 'migrationTbl', 50, '', true,
    ['width:220px;', 'width:420px;', 'width:130px;', 'width:130px; text-align:left;']);

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
