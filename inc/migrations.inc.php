<?php # /inc/migrations.inc.php v:1.3.1 d:2026-09-15 i:evs
// Alle kendte databasemigrationer med et live statustjek - fælles for run_migrate.php (oversigten) og systemsundheden på
// forsiden (inc/health.inc.php). En ny migration tilføjes nederst i migration_registry().

// --- Generiske statustjek, genbrugt af flere migrationer nedenfor ---
// RETTET 2026-08-20: MySQL-grenene brugte "SHOW TABLES LIKE"/"SHOW COLUMNS FROM... Erstattet med
// information_schema-forespørgsler mod DATABASE (den aktuelt valgte database) - samme afprøvede mønster
// migration_projects.php/migration_credit.php selv bruger til MySQL, i stedet for SHOW-varianterne, som er mere
// følsomme over for LIKE-mønster-fortolkning (% og _ er wildcards, kan i sjældne tilfælde give falske
// positive/negative for kolonne-/tabelnavne der indeholder dem) og generelt mindre robuste på tværs af
// MySQL-versioner/-konfigurationer end et almindeligt SELECT mod information_schema.
function rm_table_exists($conn, $table) {
    return db_has_table($conn, (string)$table);
}
function rm_column_exists($conn, $table, $column) {
    return db_has_column($conn, (string)$table, (string)$column);
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
// Findes alle landepakkens momskoder? (den der fjerner en kode med vilje, kan bare køre migrationen igen - den er idempotent)
function rm_vat_codes_complete($conn) {
    foreach ((array)tc_country_pack('vat.codes', []) as $vc) {
        if (is_array($vc) && isset($vc['id']) && !DB::fetch_assoc(DB::prepare_and_execute($conn, "SELECT vat_id FROM vat_codes WHERE vat_id = ?", [(string)$vc['id']]))) return false;
    }
    return true;
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
// RETTET: db-setup/db_migrate.php - dokumenteret i selve CLAUDE.md som en del af migrationssystemet - manglede
// helt her, fordi den ikke følger migrate_*.php/migration_*.php-navnekonventionen dette dashboard oprindeligt blev
// bygget ved at gennemsøge efter. Filen er derfor helt ureferet fra resten af appen (intet menupunkt, ikke listet
// her) - kun findbar ved at kende/gætte den præcise URL.
function migration_registry(): array {
    return [
    ['file' => 'db_migrate.php', 'label' => 'Valuta-kolonner (juli 2026)', 'desc' => 'currency på expenses/invoices/journal/invoice_lines/transactions. Kører alle på én gang (ikke ét script pr. kolonne som resten af listen).',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'currency') && rm_column_exists($c, 'expenses', 'currency')],
    // TILFØJET: fandtes i db-setup/, gør sit arbejde korrekt og er fuldt idempotent, men var slet ikke listet her
    // og lå ikke linket noget andet sted i appen - en admin, der havde brug for den (ældre installationer uden
    // login_log-tabellen), kunne kun finde den ved at gætte det præcise filnavn.
    ['file' => 'fix_login_log.php', 'label' => 'Login-log (reparation af ældre installationer)', 'desc' => 'Opretter kun login_log-tabellen, hvis den mangler - for installationer sat op før tabellen indgik i den almindelige opsætning.',
     'check' => fn($c) => rm_table_exists($c, 'login_log')],
    ['file' => 'migration_projects.php',        'label' => 'Projekt-modul (v1.3.0-tilføjelser)',   'desc' => 'expenses.exp_type + projects.note_expenses/note_income/note_general. Kræver at projects-tabellen allerede findes.',
     'check' => fn($c) => rm_column_exists($c, 'expenses', 'exp_type') && rm_column_exists($c, 'projects', 'note_expenses')],
    ['file' => 'migrate_equity_account.php',     'label' => 'Egenkapital-konto',                    'desc' => 'Opretter standard-egenkapitalkontoen (3000) hvis den mangler.',
     'check' => fn($c) => rm_account_exists($c, 3000) || tc_equity_account($c) > 0],      // (eller en anden egenkapitalkonto, fx standardkontoplanens)
    ['file' => 'migrate_liability_account.php',  'label' => 'Gældskonto',                           'desc' => 'Opretter standard-gældskontoen (4000) hvis den mangler.',
     'check' => fn($c) => rm_account_exists($c, 4000) || tc_role_account($c, 'conf_acc_creditor', 4000) > 0],
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
    // RETTET: "modtaget DKK-beløb" var forældet siden Fase 1 gjorde bogføringsvalutaen konfigurerbar - selve koden
    // (reconcile_action.php) er allerede valuta-agnostisk, kun denne beskrivelsestekst var ikke opdateret. Samme
    // fejlklasse som about.php-rettelsen i tidligere runde.
    ['file' => 'migrate_currency_gainloss.php',  'label' => 'Kursgevinst/-tab ved betaling',         'desc' => 'Konto 7200 "Kursgevinst/-tab, valuta" - bruges ved bankafstemning når en udenlandsk faktura afsluttes med en kursforskel mellem bogført og modtaget beløb i firmaets bogføringsvaluta.',
     'check' => fn($c) => rm_account_exists($c, 7200) || tc_role_account($c, 'conf_acc_fx', 7200) > 0],
    // TILFØJET (bruger-rapporteret: langsom sidevisning ved sideskift): tre
    // manglende indekser fundet under samme fejlsøgning som rettede
    // inc/auto_backup.inc.php's tidsstempel-bug (se filens egen header-
    // kommentar) - uden dem var auto_backup_check()'s ændrings-tjek (kørt på
    // hver sidevisning, hver 7. dag) fulde tabel-scanninger.
    ['file' => 'migrate_auto_backup_indexes.php', 'label' => 'Indekser til automatisk backup-tjek',   'desc' => 'Indeks på audit_log.log_date, expenses.created_at og journal.created_at - bruges af auto_backup_check() (kørt på hver sidevisning) til at afgøre om noget er ændret siden sidste backup.',
     'check' => fn($c) => rm_index_exists($c, 'audit_log', 'idx_audit_log_date') && rm_index_exists($c, 'expenses', 'idx_expenses_created') && rm_index_exists($c, 'journal', 'idx_journal_created')],
    // TILFØJET: denne liste er en hårdkodet registrering, ikke en mappe-scanning af db-setup/ - en ny migration
    // bliver ALDRIG synlig her af sig selv, uanset hvor idempotent/korrekt selve scriptet er. Se.
    ['file' => 'migrate_customer_no_reminders.php', 'label' => 'Fravalg af rykkere pr. kunde',          'desc' => 'customers.cust_no_reminders - udelukker en kunde fra automatiske betalingsrykkere (reminders.php), håndhævet både i UI og server-side (reminder_action.php).',
     'check' => fn($c) => rm_column_exists($c, 'customers', 'cust_no_reminders')],
    ['file' => 'migrate_customer_credit_limit.php', 'label' => 'Kreditgrænse pr. kunde',                'desc' => 'customers.credit_limit - valgfri kreditgrænse, udvider den eksisterende saldo-advarsel ved fakturaoprettelse (customer_balance_check.php) til også at tjekke samlet udestående mod grænsen.',
     'check' => fn($c) => rm_column_exists($c, 'customers', 'credit_limit')],
    ['file' => 'migrate_bad_debt_account.php',       'label' => 'Tab på debitorer-konto',                'desc' => 'Opretter standardkontoen 7100 "Tab på debitorer" - bruges når en uerholdelig faktura afskrives som tab (invoice_view.php -> "Afskriv som tab").',
     'check' => fn($c) => rm_account_exists($c, 7100) || tc_role_account($c, 'conf_acc_bad_debt', 7100) > 0],
    ['file' => 'migrate_stock.php',                  'label' => 'Varemodtagelse og lageroptælling',      'desc' => 'Tabellen stock_movements (lagerbevægelser) og varelagerkontoen 8150 "Varelager" - varemodtagelse, lageroptælling og bogføring af lagerværdien.',
     'check' => fn($c) => rm_table_exists($c, 'stock_movements') && tc_role_account($c, 'conf_acc_inventory', 8150) > 0],
    ['file' => 'migrate_reminder_fees.php',          'label' => 'Rykkergebyrer bogføres',                'desc' => 'Tabellen invoice_fees og gebyrkontoen 1090 "Rykkergebyrer" (momsfri) - et rykkergebyr bogføres på debitor og lægges på fakturaen, når rykkeren sendes. Uden migrationen kræves intet gebyr.',
     'check' => fn($c) => rm_table_exists($c, 'invoice_fees') && tc_role_account($c, 'conf_acc_reminder_fee', 1090) > 0],
    ['file' => 'migrate_account_budgets.php',        'label' => 'Budget pr. konto',                      'desc' => 'Tabellen account_budgets - ét årligt budgetbeløb pr. konto, sat på budget_edit.php og sammenlignet mod det faktiske på report_income.php.',
     'check' => fn($c) => rm_table_exists($c, 'account_budgets')],
    ['file' => 'migrate_user_active_status.php',     'label' => 'Deaktiver brugerkonto',                 'desc' => 'users.is_active - lader en admin deaktivere en brugerkonto (blokerer nye logins OG dræber en allerede aktiv session med det samme, tjekket ved hver sideindlæsning).',
     'check' => fn($c) => rm_column_exists($c, 'users', 'is_active')],
    ['file' => 'migrate_field_visibility.php',       'label' => 'Højreklik-skjul for felter',             'desc' => 'Tabellen field_visibility - samme princip som menu_visibility, men for enkelte formularfelter (kreditgrænse, rykker-fravalg, konto-aktiv, tab-på-debitorer-konto m.fl.).',
     'check' => fn($c) => rm_table_exists($c, 'field_visibility')],
    ['file' => 'migrate_bank_consent_expiry.php',    'label' => 'PSD2-samtykke-udløbsdato',              'desc' => 'bank_connections.consent_expires_at - advarer før en automatisk bankforbindelses samtykke udløber, i stedet for at den stille holder op med at virke. Kræver at leverandørkonto-modulet allerede er sat op (migrate_bank_integration.php).',
     'check' => fn($c) => rm_column_exists($c, 'bank_connections', 'consent_expires_at')],
    ['file' => 'migrate_invoice_line_discount.php',  'label' => 'Rabat pr. fakturalinje',                 'desc' => 'invoice_lines.discount_pct - valgfri procent-rabat pr. linje, trukket fra nettobeløbet før moms. Se invoice_dkk_totals() (inc/db_connect.inc.php) for den autoritative formel.',
     'check' => fn($c) => rm_column_exists($c, 'invoice_lines', 'discount_pct')],
    ['file' => 'migrate_prod_stock_decimal.php',     'label' => 'Lagerantal med decimaler (MySQL)',       'desc' => 'products.prod_stock INT -> DECIMAL(15,3) på MySQL - decimaler (fx 7,5 kg) blev skåret af. SQLite er upåvirket.',
     'check' => function ($c) { if (DB::is_sqlite()) return true; $r = DB::query($c, "SHOW COLUMNS FROM products LIKE 'prod_stock'"); $col = $r ? DB::fetch_assoc($r) : null; return $col && stripos((string)$col['Type'], 'int') === false; }],
    ['file' => 'migrate_product_category.php',       'label' => 'Produktkategorier',                     'desc' => 'products.prod_category - valgfrit gruppe-/kategorifelt, bruges til "salg pr. kategori"-panelet på inventory_status.php.',
     'check' => fn($c) => rm_column_exists($c, 'products', 'prod_category')],
    ['file' => 'migrate_customer_language.php',      'label' => 'Kunde-specifik fakturasprog',           'desc' => 'customers.cust_language - fakturaen kan nu vises/sendes på kundens eget sprog i stedet for bogholderens, hvis udfyldt.',
     'check' => fn($c) => rm_column_exists($c, 'customers', 'cust_language')],
    ['file' => 'migrate_expense_lines.php',          'label' => 'Udgifts-linjeopdeling på flere konti',  'desc' => 'expense_lines - en udgift/bilag kan nu splittes på flere konti, i stedet for kun ét account_id. Se expense_get_lines() (inc/db_connect.inc.php) for den autoritative kilde.',
     'check' => fn($c) => rm_table_exists($c, 'expense_lines')],
    ['file' => 'migrate_trash_bin.php',              'label' => 'Papirkurv for kunder/leverandører',      'desc' => 'customers/suppliers.deleted_at - "Slet" bliver en gendannelig blødsletning i stedet for permanent, se trash_bin.php.',
     'check' => fn($c) => rm_column_exists($c, 'customers', 'deleted_at')],
    ['file' => 'migrate_product_ean.php',            'label' => 'EAN/stregkode på produkter',            'desc' => 'products.prod_ean - valgfrit stregkodefelt.',
     'check' => fn($c) => rm_column_exists($c, 'products', 'prod_ean')],
    ['file' => 'migrate_supplier_bank_details.php',  'label' => 'Leverandør-bankoplysninger',             'desc' => 'suppliers.supplier_reg_no/supplier_account_no - nødvendigt for CSV-eksporten til bankbetaling (supplier_payment_export.php).',
     'check' => fn($c) => rm_column_exists($c, 'suppliers', 'supplier_reg_no')],
    ['file' => 'migrate_invoice_templates.php',      'label' => 'Fakturaskabeloner (linjebundter)',       'desc' => 'invoice_line_templates/invoice_line_template_items - gem og genbrug et navngivet sæt fakturalinjer, se invoice_template_action.php.',
     'check' => fn($c) => rm_table_exists($c, 'invoice_line_templates')],
    ['file' => 'migrate_template_discount.php',      'label' => 'Rabat på fakturaskabeloner',             'desc' => 'invoice_line_template_items.discount_pct - en fakturaskabelon gemmer nu også linjerabatten (kræver Fakturaskabeloner).',
     'check' => fn($c) => !rm_table_exists($c, 'invoice_line_template_items') || rm_column_exists($c, 'invoice_line_template_items', 'discount_pct')],
    ['file' => 'migrate_credit_note_no.php',         'label' => 'Kreditnota-nummerserie',                 'desc' => 'invoices.credit_note_no + credit_note_no_counter - kreditnotaer fik aldrig sit eget kundevendte nummer (kun voucher_no), se next_credit_note_no() (inc/db_connect.inc.php).',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'credit_note_no')],
    ['file' => 'migrate_bank_match_rules.php',       'label' => 'Bank-genkendelsesregler',                'desc' => 'bank_match_rules - "hvis beskrivelse indeholder X -> konto Y", foreslås automatisk i reconcile_list.php, administreres på bank_rules.php.',
     'check' => fn($c) => rm_table_exists($c, 'bank_match_rules')],
    ['file' => 'migrate_customer_contacts.php',      'label' => 'Flere kontaktpersoner pr. kunde',        'desc' => 'customer_contacts - en kunde kan have flere navngivne kontaktpersoner (fx separat fakturamodtager), se customer_edit.php.',
     'check' => fn($c) => rm_table_exists($c, 'customer_contacts')],
    ['file' => 'migrate_invoice_external_ref.php',   'label' => 'Webhook-fakturaer (eksternt ordre-id)',  'desc' => 'invoices.external_ref + indeks - lader webhook_invoice.php genkende og afvise et gentaget/duplikeret webhook-kald i stedet for at oprette samme faktura to gange. Se webhook_keys.php.',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'external_ref') && rm_index_exists($c, 'invoices', 'idx_invoices_external_ref')],
    ['file' => 'migrate_journal_created_by.php',     'label' => 'Bruger-id på journalposten (BEK 811)',   'desc' => 'journal.created_by + opgraderer append-only-triggerne (udvider trg_journal_restrict_update_posted og tilføjer trg_expenses_no_delete_posted/trg_expenses_restrict_update_posted) - se [[digital-standard-bookkeeping-registration-goal]]. KØR DENNE selv hvis journal.created_by allerede findes, for at opgradere triggerne.',
     'check' => fn($c) => rm_column_exists($c, 'journal', 'created_by')],
    ['file' => 'migrate_bank_reconciliations.php',   'label' => 'Gemte bankafstemninger (BEK 811)',       'desc' => 'bank_reconciliations - gør en udført bankafstemning (bank_balance_check.php) til en permanent, dateret, append-only post i stedet for en ren on-demand-visning. Se [[digital-standard-bookkeeping-registration-goal]].',
     'check' => fn($c) => rm_table_exists($c, 'bank_reconciliations')],
    ['file' => 'migrate_account_guidance.php',       'label' => 'Konteringsvejledning pr. konto (BEK 811)', 'desc' => 'accounts.guidance - fri konteringsvejledning pr. konto, vist på account_edit.php/chart_of_accounts.php/journal_entry.php. Se [[digital-standard-bookkeeping-registration-goal]].',
     'check' => fn($c) => rm_column_exists($c, 'accounts', 'guidance')],
    ['file' => 'migrate_account_status.php',         'label' => 'Kontoplanens synlighed',                'desc' => 'accounts.acc_status (aktiv / dæmpet / slukket) - kun de konti du bruger vises i valglisterne; Erhvervsstyrelsens standardkontoplan kan derefter importeres (slukket) på kontoplan-siden.',
     'check' => fn($c) => rm_column_exists($c, 'accounts', 'acc_status')],
    // RETTET: fire migreringer fandtes i db-setup/, men stod ikke her - en eksisterende installation fik aldrig besked
    ['file' => 'migrate_recurring_expenses.php',     'label' => 'Faste udgifter',                        'desc' => 'recurring_expenses - skabeloner der laver en ubogført udgift hver periode.',
     'check' => fn($c) => rm_table_exists($c, 'recurring_expenses')],
    ['file' => 'migrate_mail_log.php',               'label' => 'Mail-log',                              'desc' => 'mail_log - alle udgående mails logges centralt (mail_log.php).',
     'check' => fn($c) => rm_table_exists($c, 'mail_log')],
    ['file' => 'migrate_product_cost_price.php',     'label' => 'Kostpris på varer',                     'desc' => 'products.prod_cost_price - avance / dækningsbidrag pr. vare.',
     'check' => fn($c) => rm_column_exists($c, 'products', 'prod_cost_price')],
    ['file' => 'migrate_quote_followup.php',         'label' => 'Opfølgning på tilbud',                  'desc' => 'quotes.followup_sent_at / followup_count - påmindelse om tilbud der venter på svar.',
     'check' => fn($c) => rm_column_exists($c, 'quotes', 'followup_count')],
    ['file' => 'migrate_shop_connections.php',       'label' => 'Webbutikker',                           'desc' => 'shop_connections/shop_orders - tilknyt en webbutik med dens egne valg (faktura pr. ordre eller samlet salg pr. dag, automatisk bogføring, mellemregningskonto ...), se shop_connections.php.',
     'check' => fn($c) => rm_table_exists($c, 'shop_connections') && rm_table_exists($c, 'shop_orders')],
    ['file' => 'migrate_invoice_layouts.php',        'label' => 'Flere fakturalayouts',                  'desc' => 'invoice_layouts/invoice_layout_blocks + customers.cust_layout_id + invoices.inv_layout_id - gem flere navngivne layouts i Design Mode, vælg layout pr. kunde og pr. faktura (PDF\'en følger med). Det nuværende layout bliver "Standard".',
     'check' => fn($c) => rm_table_exists($c, 'invoice_layouts') && rm_column_exists($c, 'invoices', 'inv_layout_id') && rm_column_exists($c, 'invoice_layouts', 'doc_type') && rm_table_exists($c, 'invoice_layout_shapes')],      // (doc_type: egne tilbudslayouts; shapes: grafik i layouts)
    ['file' => 'migrate_customer_ean.php',           'label' => 'EAN-nummer på kunder',                  'desc' => 'customers.cust_ean - offentlige kunder modtager OIOUBL-e-fakturaen på deres EAN-nummer i stedet for CVR.',
     'check' => fn($c) => rm_column_exists($c, 'customers', 'cust_ean')],
    ['file' => 'migrate_vat_code_types.php',         'label' => 'Momskodernes type',                     'desc' => 'vat_codes.vat_type - salg, køb, EU-varer/-ydelser, eksport, fritaget eller omvendt betalingspligt. Grundlaget for momsangivelsens felter (landepakker).',
     'check' => fn($c) => rm_column_exists($c, 'vat_codes', 'vat_type')],
    ['file' => 'migrate_party_country.php',          'label' => 'Land og momsnummer på kunder/leverandører', 'desc' => 'customers.cust_country/cust_vat_id og suppliers.country/vat_id - grundlaget for EU-handel og landepakker. Tomt land = virksomhedens eget land.',
     'check' => fn($c) => rm_column_exists($c, 'customers', 'cust_vat_id') && (!rm_table_exists($c, 'suppliers') || rm_column_exists($c, 'suppliers', 'vat_id'))],
    ['file' => 'migrate_eu_vat_codes.php',           'label' => 'Momskoder til EU-handel og eksport',     'desc' => 'Landepakkens momskoder for EU-salg, eksport og køb med omvendt betalingspligt (momsangivelsens rubrik A/B/C og moms af køb i udlandet). Kobler standardkontoplanens udlandskonti.',
     'check' => fn($c) => rm_column_exists($c, 'vat_codes', 'vat_type') && rm_vat_codes_complete($c)],
    ['file' => 'migrate_customer_peppol_id.php',     'label' => 'Peppol-ID på kunder',                   'desc' => 'customers.cust_peppol_id - kundens adresse i Peppol-netværket til Peppol BIS 3-e-fakturaer (fx 0184:12345678). Tomt = udledes af EAN/CVR.',
     'check' => fn($c) => rm_column_exists($c, 'customers', 'cust_peppol_id')],
    ['file' => 'migrate_oss.php',                    'label' => 'EU-OSS på fakturaer',                   'desc' => 'invoices.inv_oss - modtagerlandet for et salg til en privat kunde i et andet EU-land (EU-OSS). Grundlaget for OSS-angivelsen.',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'inv_oss') && rm_column_exists($c, 'invoices', 'inv_oss_off')],      // (inv_oss_off: ydelse beskattet i eget land)
    ['file' => 'migrate_ioss.php',                   'label' => 'IOSS på fakturaer og webbutikker',      'desc' => 'invoices.inv_ioss - modtagerlandet for et IOSS-salg (varer på højst 150 EUR sendt fra et land uden for EU til en forbruger i EU) og shop_connections.ioss. Grundlaget for den månedlige IOSS-angivelse.',
     'check' => fn($c) => rm_column_exists($c, 'invoices', 'inv_ioss') && (!rm_table_exists($c, 'shop_connections') || rm_column_exists($c, 'shop_connections', 'ioss'))],
    ];
}

// De migrationer der ikke er kørt på denne installation (et statustjek der fejler, tæller som ikke kørt)
function migrations_pending($conn): array {
    $out = [];
    foreach (migration_registry() as $m) {
        try { $ok = (bool)$m['check']($conn); } catch (\Throwable $e) { $ok = false; }
        if (!$ok) $out[] = $m;
    }
    return $out;
}
