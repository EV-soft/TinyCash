<?php # /inc/menu.inc.php v:1.3.1 d:2026-09-15 i:evs
# v1.10.0: Menu-strukturen udtrukket til get_menu_structure() (genbruges nu
# af den nye menu_visibility.php, tilføjet under System -> Maintenance), +
# niveau-synlighed er nu konfigurerbar pr. menu-punkt (tabellen
# menu_visibility, ny admin-side) i stedet for hårdkodet i showMenu() selv.
# Bruger-anmodet.
# v1.9.0: Maintenance (undermenu med 7 punkter) flyttet op over
# Brugerstyring/2FA/Storage Browser - som en flyout-undermenu placeret nær
# bunden af System-menuen risikerede den at blive skåret af i bunden af
# vinduet (Fejllog/Revisionsspor, de to sidste punkter, var svære at se).
# Bruger-rapporteret.
# v1.8.0: tilføjet my_2fa.php under System (to-faktor-login, fra
# forslagslisten, §Sikkerhed)
# v1.7.0: tilføjet bank_integration.php under Accounting (rigtig
# bankintegration/PSD2, fra forslagslisten)
# v1.6.0: tilføjet recurring_invoices.php under Sales (nye gentagne/faste
# fakturaer, fra forslagslisten)
# v1.5.0: tilføjet reminders.php under Sales (ny rykkerfunktion for forfaldne
# fakturaer, bruger-anmodet, fra forslagslisten)
# v1.4.0: tilføjet audit_log.php under System -> Maintenance (ny revisionsspor-
# visning, bruger-anmodet)
# v1.3.0: #tc-sidebar z-index hævet fra 9200 til 10001 - logud-knappen nederst
# blev delvist skjult bag floating-action-bar (bruger-rapporteret); desuden
# niveau-knap flyttet til visnings-gruppen i topnav og logud-knappen forenklet
// Selve menu-træet, udtrukket til sin egen funktion (v1.10.0) - genbruges nu
// af BÅDE showMenu() (rendering) OG menu_visibility.php (den nye admin-side
// til at styre hvilke punkter der vises pr. brugerniveau). Før lå hele
// strukturen kun inline i showMenu(), så en separat "liste alle menu-
// punkter"-side ville have skullet vedligeholde sin egen kopi og uundgåeligt
// glide ud af sync med den ægte menu over tid. $conn kan være null (fx før
// login) - modul-baserede punkter (Projekter) udelades i så fald.
function get_menu_structure($conn): array {
    $module_projects = false;
    // NYT (§currency-setting-is-cosmetic-label, Fase 2): momsrapporten
    // (vat_report.php) er formet efter dansk momsindberetning (TastSelv-
    // afrunding) og udelades derfor af menuen for en virksomhed, der bruger
    // en anden bogføringsvaluta end DKK - siden selv spærrer stadig direkte
    // adgang via require_dkk_base_currency().
    $is_dkk_base = true;
    if ($conn) {
        $company_settings = get_settings($conn);
        $module_projects  = !empty($company_settings['module_projects']) && $company_settings['module_projects'] == '1';
        $is_dkk_base      = strtoupper($company_settings['currency'] ?? 'DKK') === 'DKK';
    }

    $menu = [
        'index.php'  => ['label' => '🏠 ' . lang('@Overview'),   'hint' => lang('@Dashboard and quick stats')],
        // search.php flyttet UD af selve menuen (bruger-anmodet) - har i
        // stedet fået sin egen flydende knap, samme mønster som notesblokken
        // (se inc/notepad.inc.php + inc/htm_page.lib.php's htm_Footer()).
        'sales'      => ['label' => '📄 ' . lang('@Sales'),      'hint' => lang('@Manage invoices and customers'),
            'submenu' => [
                'sales_hub.php'              => '🚀 ' . lang('@Sales Hub Dashboard'),
                'invoice_edit.php?id=0'      => '➕ ' . lang('@Create New Invoice'),
                // NYT (bruger-anmodet): Tilbud/Ordrebekræftelse - se
                // db-setup/migrate_quotes.php for den fulde begrundelse.
                'quote_list.php'             => '📝 ' . lang('@Quotes'),
                'quote_edit.php?id=0'        => '➕ ' . lang('@Create New Quote'),
                // NYT (bruger-anmodet): "Indkøb" har et direkte underpunkt til
                // leverandørlisten (supplier_list.php), men "Salg" havde intet
                // tilsvarende direkte punkt til kundelisten - kun den
                // kombinerede fakturaoversigt (sales_hub.php, allerede linket
                // ovenfor). Kundelisten lever selv inde i sales_hub.php (der
                // findes ingen separat customer_list.php), så punktet her
                // peger på samme side med et anker (#c_card, sales_hub.php's
                // kundekort-formular-id) direkte til kundesektionen, i stedet
                // for at duplikere kundelisten i en helt ny fil.
                'sales_hub.php#c_card'       => '👥 ' . lang('@Customers'),
                'reminders.php'              => '⏰ ' . lang('@Payment Reminders'),
                // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"):
                // parallel til reminders.php, blot for udløbne/snart-
                // udløbne SENDTE tilbud i stedet for forfaldne fakturaer -
                // se db-setup/migrate_quote_followup.php.
                'quote_followup.php'         => '📨 ' . lang('@Quote Follow-up'),
                // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"):
                // masse-udsendelse af kontoudtog til alle kunder med en åben
                // saldo - hidtil kunne customer_statement_send.php kun sende
                // ét kontoudtog ad gangen, manuelt pr. kunde.
                'customer_statements.php'    => '📊 ' . lang('@Account Statements'),
                // NYT (bruger-anmodet, "kan bogholderen ønske sig mere",
                // runde 27): ingen side kunne samle ALT om én kunde til ét
                // dokument til en GDPR-indsigtsanmodning - se filens egen
                // kommentar for hvorfor sletning/anonymisering bevidst ikke
                // er en del af det.
                'customer_data_export.php'   => '🔒 ' . lang('@Customer Data Export (GDPR)'),
                'recurring_invoices.php'     => '🔁 ' . lang('@Recurring Invoices'),
                'mail_inbox.php?box=invoice' => '🗂️ ' . lang('@Invoice Copies Inbox'),
            ]],
        'expenses'   => ['label' => '🛒 ' . lang('@Purchases'),   'hint' => lang('@Manage expenses, vouchers and supplier invoices'),
            'submenu' => [
                'expense_list.php'           => '📋 ' . lang('@Expense List'),
                'expense_edit.php?id=0'      => '📥 ' . lang('@Register Expense'),
                'recurring_expenses.php'     => '🔁 ' . lang('@Recurring Expenses'),
                'supplier_list.php'          => '🏭 ' . lang('@Suppliers'),
                // NYT (bruger-anmodet, "kan bogholderen ønske sig mere",
                // runde 31): CSV-eksport til bankbetaling af flere udgifter
                // på én gang - se filens egen kommentar for scope.
                'supplier_payment_export.php'=> '🏦 ' . lang('@Supplier Payment Export'),
                '---'                        => '---',
                'mail_inbox.php?box=voucher' => '📬 ' . lang('@Voucher Inbox'),
            ]],
        'inventory'  => ['label' => '📦 ' . lang('@Inventory'),   'hint' => lang('@Stock levels and products'),
            'submenu' => [
                'inventory_status.php'       => '📉 ' . lang('@Stock Status'),
                'product_edit.php?id=0'      => '➕ ' . lang('@Create New Product'),
            ]],
        'accounting' => ['label' => '💰 ' . lang('@Accounting'),  'hint' => lang('@Ledger, banking, reports and settings'),
            'submenu' => [
                // RETTET (bruger-anmodet): et menu-punkt der selv åbner endnu en
                // undermenu (en "flyout") placeres nu ØVERST i sin forældre-liste
                // - ikke fordi det er vigtigere, men fordi flyout'en (i top-
                // navigations-tilstand) altid åbner ud for SIN EGEN vandrette
                // position i den overliggende dropdown (se .sub-submenu's
                // "top:0" i CSS'en). Jo længere nede i listen punktet står, jo
                // højere nede på skærmen begynder flyout'en - og med seks
                // undermenu-punkter (Rapporter) kunne den nemt løbe ud over
                // vinduets underkant på et almindeligt skærmhøjde. Placeret
                // øverst giver flyout'en mest mulig plads nedad.
                'reports' => ['label' => '📊 ' . lang('@Reports') . ' <span style="float:right">▶</span>',
                    'submenu' => array_merge(
                        [
                            // RETTET (bruger-anmodet, samme begrundelse som
                            // Rapporter/Vedligeholdelse selv fik tidligere): et
                            // punkt der selv åbner en flyout placeres ØVERST i
                            // sin forældre-liste - flyout'en (i top-navigations-
                            // tilstand) åbner altid ud for SIN EGEN vandrette
                            // position i den overliggende dropdown, så jo længere
                            // nede i listen den står, jo højere nede på skærmen
                            // begynder den - og med seks undermenu-punkter kunne
                            // Revision nemt løbe ud over vinduets underkant hvis
                            // den lå nederst (hvor den stod FØR).
                            'audit' => ['label' => '🔍 ' . lang('@Audit') . ' <span style="float:right">▶</span>',
                                'submenu' => [
                                    'account_card.php'             => '🗒️ ' . lang('@Account Card'),
                                    'trial_balance.php'             => '🧮 ' . lang('@Trial Balance'),
                                    'sequence_check.php'            => '🔢 ' . lang('@Sequence Number Check'),
                                    'entry_balance_check.php'       => '⚖️ ' . lang('@Journal Entry Balance Check'),
                                    'subledger_reconciliation.php'  => '🔗 ' . lang('@Subledger Reconciliation'),
                                    // NYT (bruger-anmodet, "kan bogholderen
                                    // ønske sig mere", runde 26): samme
                                    // afstemningsprincip som Subledger
                                    // Reconciliation ovenfor, blot mod den
                                    // FAKTISKE saldo på selve bankkontoudtoget
                                    // - se bank_balance_check.php's egen
                                    // kommentar.
                                    'bank_balance_check.php'        => '🏦 ' . lang('@Bank Balance Reconciliation'),
                                    // NYT (bruger-anmodet, "kan bogholderen
                                    // ønske sig mere"): samme afstemnings-
                                    // princip som Subledger Reconciliation
                                    // ovenfor, blot for momskontiene - se
                                    // vat_reconciliation.php's egen kommentar.
                                    'vat_reconciliation.php'        => '🧾 ' . lang('@VAT Reconciliation'),
                                    'segregation_audit.php'         => '👀 ' . lang('@Segregation of Duties Audit'),      // (fire-øjne-princippet - 👤 var mørkegrå på den mørke menu)
                                    // NYT (bruger-anmodet, "kan bogholderen
                                    // ønske sig mere"): login_log-tabellen
                                    // blev allerede skrevet til ved hvert
                                    // login-forsøg, men var usynlig - se
                                    // login_history.php's egen kommentar.
                                    'login_history.php'             => '🔐 ' . lang('@Login History'),
                                    // NYT (bruger-anmodet, "kan bogholderen
                                    // ønske sig mere"): mail_log-tabellen
                                    // udfyldes nu automatisk af den fælles
                                    // sendTinyMail() - se mail_log.php's
                                    // egen kommentar.
                                    'mail_log.php'                  => '📧 ' . lang('@Sent Mail Log'),
                                    // NYT (bruger-anmodet, "kan bogholderen
                                    // ønske sig mere", runde 31): "Slet" på en
                                    // kunde/leverandør er nu en gendannelig
                                    // blødsletning - se filens egen kommentar.
                                    'trash_bin.php'                 => '🗑️ ' . lang('@Trash Bin'),
                                    // NYT (bruger-anmodet, "kan bogholderen
                                    // ønske sig mere", runde 26): kun fulde
                                    // systembackups fandtes - ingen måde at
                                    // hente et rent bilagsarkiv for ét
                                    // regnskabsår til revisor/bogføringslovens
                                    // 5-års opbevaringspligt - se filens egen
                                    // kommentar.
                                    'voucher_archive_export.php'    => '🗃️ ' . lang('@Voucher Archive Export'),
                                ]],
                        ],
                        ['ledger_view.php'        => '📖 ' . lang('@General Ledger'),
                         'journal_entry.php'      => '✍️ ' . lang('@Manual Journal Entry')],
                        $is_dkk_base ? ['vat_report.php' => '🧾 ' . lang('@VAT Report')] : [],
                        [
                            'aging_report.php'       => '📆 ' . lang('@Aging Report'),
                            'report_income.php'      => '📊 ' . lang('@Profit & Loss Report'),
                            // NYT (bruger-anmodet, "kan bogholderen ønske
                            // sig mere"): budget_edit.php - se dens egen
                            // kommentar og report_income.php's nye
                            // afvigelseskolonne.
                            'budget_edit.php'        => '🎯 ' . lang('@Account Budgets'),
                            'balance_sheet.php'      => '⚖️ ' . lang('@Balance Sheet'),
                            'annual_report.php'      => '📜 ' . lang('@Annual Report'),
                        ]
                    )],
                '---_1'                      => '---',
                'bank_import_step1.php'      => '📥 ' . lang('@Import Bank File'),
                'bank_integration.php'       => '🏦 ' . lang('@Bank Integration (PSD2)'),
                'reconcile_list.php'         => '⚖️ ' . lang('@Bank Reconciliation'),
                'bank_rules.php'             => '🔮 ' . lang('@Bank Matching Rules'),
                'settings_fees.php'          => '⚙️ ' . lang('@Fee Rules'),
                '---'                        => '---',
                'fixed_asset_list.php'       => '🏗️ ' . lang('@Fixed Assets'),
                'chart_of_accounts.php'      => '📑 ' . lang('@Chart of Accounts'),
            ]],
        /* 'production' => ['label' => '🛠️ ' . lang('@Production'),  'hint' => lang('@Production lines and management'),
            'submenu' => [
                'xx.php'                     => '📉 ' . lang('@No content'),
            ]], */ 
        'system'     => ['label' => '⚙️ ' . lang('@System'),      'hint' => lang('@Settings and user management'),
            'submenu' => [
                // RETTET (bruger-anmodet, samme begrundelse som Regnskab ->
                // Rapporter ovenfor): "Vedligeholdelse" åbner selv en flyout med
                // otte punkter - endnu mere udsat for at løbe ud over vinduets
                // underkant end Rapporter, og derfor placeret allerøverst.
                'maintenance'              => ['label' => '🛠️ ' . lang('@Maintenance') . ' <span style="float:right">▶</span>',
                    'submenu' => [
                        'backup.php'              => '📥 ' . lang('@Backup Management'),
                        'backup_restore.php'      => '🔄 ' . lang('@Restore System'),
                        // NYT (bruger-anmodet, "kan bogholderen ønske sig
                        // mere", runde 30): verificerer en backup uden at
                        // overskrive den rigtige database - se filens egen
                        // kommentar.
                        'backup_test_restore.php' => '🧪 ' . lang('@Backup Test Restore'),
                        'setup_chart.php'         => ['label' => '📑 ' . lang('@Chart of Accounts Proposal'), 'hint' => lang('@Only Danish layout')],
                        'run_migrate.php'         => ['label' => '📦 ' . lang('@Database migration'),         'hint' => lang('@Update database structure')],
                        'db-setup/migrate_engine.php' => ['label' => '🔀 ' . lang('@Convert Database Engine'),  'hint' => lang('@Convert the ledger between SQLite and MySQL')],
                        'translation_manager.php' => '🌐 ' . lang('@Language Editor'),
                        'error_log.php'           => '⚠️ ' . lang('@Error Log'),
                        'audit_log.php'           => '📜 ' . lang('@Audit Log'),
                        'menu_visibility.php'     => '👁️ ' . lang('@Menu Visibility'),
                    ]],
                '---_2'                      => '---',
                'account_manage.php'         => '🗂️ ' . lang('@Accounts'),
                'company_settings.php'       => '🏢 ' . lang('@Settings'),
                'vat_codes.php'              => '🧾 ' . lang('@VAT Codes & Rates'),
                'user_list.php'              => '🔑 ' . lang('@User Management'),
                'my_2fa.php'                 => '🔐 ' . lang('@Two-Factor Login'),
                'storage_browser.php'        => '📁 ' . lang('@Storage Browser'),
                'webhook_keys.php'           => '🔗 ' . lang('@Webhook API Keys'),
                'shop_connections.php'       => '🛒 ' . lang('@Web Shops'),
                '---_3'                      => '---',
                'ai_help.php'                => 'ℹ️ ' . lang('@AI support'),
                // NYT (bruger-anmodet): offentlig, login-fri AI-manual (se
                // docs/ai_manual.md) - en ekstern AI/support-chatbot kan
                // hente den direkte, men et menu-link her gør den nem at
                // finde/dele for en administrator.
                'ai_manual.php'              => '🤖 ' . lang('@AI Manual'),
                'about.php'                  => 'ℹ️ ' . lang('@About TinyCash'),
            ]],
        'logout.php' => ['label' => '🚪 ' . lang('@Logout'), 'hint' => ''],
    ];

    if ($module_projects) {
        $new_menu = [];
        foreach ($menu as $key => $val) {
            $new_menu[$key] = $val;
            if ($key === 'accounting') {
                $new_menu['projects'] = ['label' => '📁 ' . lang('@Projects'),
                    'hint'    => lang('@Project tracking — hours, expenses and invoicing per customer project'),
                    'submenu' => [
                        'project_view.php'      => '📊 ' . lang('@Project Overview'),
                        'project_edit.php?id=0' => '➕ ' . lang('@New Project'),
                        // NYT (bruger-anmodet): Timeregistrering - kræver selv
                        // Projekt-modulet aktivt, se db-setup/migrate_time_tracking.php.
                        'time_list.php'         => '⏱️ ' . lang('@Time Tracking'),
                    ]];
            }
        }
        $menu = $new_menu;
    }

    return $menu;
}

// Standardsynlighed for et menu-punkt, INDEN der er gemt nogen eksplicit
// overstyring i menu_visibility - de oprindelige hårdkodede regler fra før
// denne funktion fandtes. Delt mellem showMenu() OG menu_visibility.php
// (som forudfylder sine afkrydsningsfelter herfra for punkter uden en gemt
// række endnu), så de to aldrig kan glide fra hinanden.
function get_menu_visibility_defaults(string $url): array {
    if (in_array($url, ['accounting', 'system'], true)) return [1 => false, 2 => true, 3 => true];
    if (in_array($url, ['production', 'system'], true)) return [1 => false, 2 => false, 3 => true];
    if (in_array($url, ['settings_fees.php','chart_of_accounts.php','user_list.php','storage_browser.php','journal_entry.php','sequence_check.php','entry_balance_check.php','subledger_reconciliation.php','bank_balance_check.php','vat_reconciliation.php','budget_edit.php','segregation_audit.php','login_history.php','mail_log.php','voucher_archive_export.php','trash_bin.php','maintenance'], true)) return [1 => false, 2 => false, 3 => true];
    return [1 => true, 2 => true, 3 => true];
}

// Læser de gemte niveau-synligheds-overstyringer fra menu_visibility.php
// (tabellen menu_visibility) - ét opslag pr. sidevisning, ikke pr. menu-
// punkt. Mangler en række for et givent punkt, er det synligt for alle tre
// niveauer som standard (bevarer nøjagtig samme adfærd som før denne
// funktion fandtes, for ethvert punkt der ikke er blevet ændret).
function get_menu_visibility_overrides($conn): array {
    $overrides = [];
    if (!$conn) return $overrides;
    $res = @DB::query($conn, "SELECT item_key, level_1, level_2, level_3 FROM menu_visibility");
    if ($res) {
        while ($row = DB::fetch_assoc($res)) {
            $overrides[$row['item_key']] = [
                1 => (int)$row['level_1'] === 1,
                2 => (int)$row['level_2'] === 1,
                3 => (int)$row['level_3'] === 1,
            ];
        }
    }
    return $overrides;
}

function showMenu() {
    global $conn;

    $uLev = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 1;

    $adminExists = true;
    if ($conn) {
        $res = @DB::query($conn, "SELECT COUNT(*) FROM users WHERE user_role = 'admin'");
        if ($res) {
            $row = DB::fetch_row($res);
            $adminExists = ($row[0] > 0);
        }
    }

    $company_name = '';
    if ($conn) {
        $company_settings = get_settings($conn);
        $company_name     = trim($company_settings['company_name'] ?? '');
    }

    // Backup-advarsel - RETTET (bruger-rapporteret: "hvor er advarsels-banner
    // når menuen er i sidepanel") - beregningen lå FØR kun nede ved selve
    // #top-nav-renderingen, som CSS'en (body.sidebar-mode #top-nav {
    // display:none}) skjuler HELT, når brugeren har skiftet til sidepanel-
    // visning - #tc-sidebar-blokken havde aldrig fået sin egen kopi af
    // advarslen. Flyttet herop, så BEGGE menu-visninger kan bruge samme
    // beregnede $days_old/$alert_msg, i stedet for at risikere at de to
    // kopier glider fra hinanden over tid.
    $days_old = 0;
    if ($conn) {
        $b_res = @DB::query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'last_backup_time'");
        if ($b_res && $b_row = DB::fetch_assoc($b_res)) {
            $days_old = (int)floor((time() - (int)$b_row['setting_value']) / 86400);
        } else {
            $days_old = 999;
        }
    }
    $show_backup_warning = ($days_old > 10);
    $backup_alert_msg = $show_backup_warning
        ? sprintf(lang('@Warning: Backup is').' %d '.lang('@days old. Remember that you can lose everything you have created since then.'), $days_old)
        : '';
    // Kort udgave til den lodrette sidepanel-strimmel (kun første sætning,
    // afledt af $backup_alert_msg selv i stedet for en ny oversættelsesnøgle)
    // - den fulde tekst er for lang til at stå læsbart i en smal, roteret
    // bane; den fulde tekst vises stadig som data-hint (tooltip) ved museover.
    $backup_alert_short = $backup_alert_msg;
    if ($show_backup_warning) {
        $dot_pos = strpos($backup_alert_msg, '.');
        if ($dot_pos !== false) $backup_alert_short = substr($backup_alert_msg, 0, $dot_pos + 1);
    }

    $menu = get_menu_structure($conn);

    if (!$adminExists) {
        // Bootstrap-punkt, bevidst UNDTAGET fra det konfigurerbare
        // synligheds-system nedenfor - uden en admin-konto endnu må dette
        // punkt aldrig kunne skjules, ellers kunne man reelt låse sig selv
        // ude af at kunne oprette den allerførste admin via menuen.
        // RETTET (§bugs-batch-16-review): pegede på user_edit.php?id=0 - en
        // ren REDIGERINGS-side (UPDATE ... WHERE user_id=0, og GET-visningen
        // dør med "User not found" for id=0) som aldrig kunne oprette noget
        // som helst. user_create.php er den egentlige opret-side, og har nu
        // samme "ingen admin findes endnu"-undtagelse.
        $menu['system']['submenu'] = array_merge(
            ['user_create.php' => '🆕 ' . lang('@Create First Admin')],
            $menu['system']['submenu']
        );
    }

    $current_page = explode('?', basename($_SERVER['SCRIPT_NAME']))[0];
    $current_box  = isset($_GET['box']) ? $_GET['box'] : '';

    // ── Hjælpefunktion: er dette menu-punkt aktivt? ──────────────────────────
    // RETTET (bruger-anmodet menu-omrokering, Regnskab -> Rapporter): tjekkede
    // kun ÉT niveau af undermenu-nøgler - virkede fint mens hvert punkt i
    // fx Regnskab's undermenu var en direkte .php-fil, men en NESTET
    // undermenu (samme mønster som System -> Vedligeholdelse allerede brugte)
    // har kun nøglen "reports"/"maintenance" på dette niveau, ikke de
    // faktiske sidenavne længere nede - så det yderste menu-punkt ("Regnskab")
    // mistede sin aktiv-markering når man besøgte fx balance_sheet.php.
    // Rekursiv nu, så den finder $current_page uanset hvor dybt den er nestet.
    $isActiveItem = function($url, $config) use (&$isActiveItem, $current_page, $current_box) {
        if ($current_page === $url) return true;
        if (!isset($config['submenu'])) return false;
        foreach ($config['submenu'] as $subKey => $subVal) {
            if (is_array($subVal) && isset($subVal['submenu'])) {
                if ($isActiveItem($subKey, $subVal)) return true;
                continue;
            }
            if (strpos((string)$subKey, $current_page) === false) continue;
            if ($current_page === 'mail_inbox.php') {
                $target_box = (strpos((string)$subKey, 'box=invoice') !== false) ? 'invoice' : 'voucher';
                $actual_box = ($current_box === 'invoice') ? 'invoice' : 'voucher';
                if ($target_box === $actual_box) return true;
            } else {
                return true;
            }
        }
        return false;
    };

    // ── Niveau-synlighed: konfigurerbar via menu_visibility.php, med de
    // oprindelige hårdkodede regler som fallback-standardværdi for punkter
    // der ALDRIG er blevet gemt eksplicit (dvs. før denne funktion fandtes,
    // eller for nye punkter tilføjet siden). VIGTIGT: dette er udelukkende
    // en menu-oversigt/UX-lag - det er IKKE adgangskontrol. Et skjult punkt
    // forhindrer ikke direkte URL-adgang til siden - det styres udelukkende
    // af siden selv (auth.inc.php + $rLev). Se menu_visibility.php's egen
    // advarselstekst.
    $overrides = get_menu_visibility_overrides($conn);
    // RETTET (§bugs-batch-16-review): "Opret første admin"-punktet blev
    // allerede tvangsindsat i $menu['system']['submenu'] ovenfor "uden at
    // kunne skjules" - men det hjalp intet, hvis selve FORÆLDRE-kategorien
    // "system" er skjult for brugerens niveau (get_menu_visibility_defaults()
    // returnerer [1=>false,2=>true,3=>true] for den) - præcis niveau 1 er
    // det mest sandsynlige niveau for en bruger der reelt står uden nogen
    // admin. Hele "System"-sektionen (og dermed bootstrap-punktet i den)
    // blev derfor aldrig vist til netop den bruger, exemption'en var lavet
    // til at beskytte. Tvinger nu "system"-kategorien synlig, når ingen
    // admin findes, uanset niveau.
    $isAllowed = function($url) use ($uLev, $overrides, $adminExists) {
        if (!$adminExists && $url === 'system') return true;
        $allowedByLevel = $overrides[$url] ?? get_menu_visibility_defaults($url);
        return $allowedByLevel[$uLev] ?? true;
    };
    $isSubAllowed = $isAllowed; // samme opslag, samme tabel - blot kaldt fra to render-steder

    // NYT (bruger-anmodet: højreklik-skjul-funktionen skal kunne vise/skifte
    // L1 og L2 UAFHÆNGIGT af hinanden, ikke kun en samlet "skjul begge"-
    // knap) - bygger data-hidden-l1/data-hidden-l2-attributter ud fra det
    // allerede indlæste $overrides for hvert punkt, så context-menuen kan
    // vise korrekt AFKRYDSET tilstand med det samme uden endnu et AJAX-kald.
    // Kun beregnet/udskrevet for admins - ingen ekstra markup for alle andre.
    $isAdminUser = (($_SESSION['user_role'] ?? '') === 'admin');
    $ctxAttrs = function($url, $hasChildren = false) use ($isAdminUser, $overrides) {
        if (!$isAdminUser) return '';
        $vis = $overrides[$url] ?? get_menu_visibility_defaults($url);
        $h1 = empty($vis[1]) ? '1' : '0';
        $h2 = empty($vis[2]) ? '1' : '0';
        return ' data-menukey="'.htmlspecialchars($url, ENT_QUOTES).'"'
            . ($hasChildren ? ' data-haschildren="1"' : '')
            . ' data-hidden-l1="'.$h1.'" data-hidden-l2="'.$h2.'"';
    };

    // ── Fælles kontekst til utility-widgets ──────────────────────────────────
    $currentTheme = $_COOKIE['theme'] ?? 'light';
    $themeIcons   = ['light' => '☀️', 'custom' => '✨', 'dark' => '🌙'];
    $currentIcon  = $themeIcons[$currentTheme] ?? '☀️';
    $current_l    = $_SESSION['lang'] ?? 'da';
    // RETTET (bruger-anmodet, "kan bogholderen ønske sig mere" / flag-tabel-
    // konsolidering): $fMap var en hardkodet, ufuldstændig sprog->flag-tabel
    // (kun 8 af 13 sprog dækket) med en reel fejl - svensk hedder 'sv' i
    // languages.json, men $fMap havde kun 'se'=>'se' (ikke en gyldig
    // sprogkode her), så svensk faldt igennem til at bruge 'sv' direkte som
    // ISO-landekode, som reelt er El Salvador, ikke Sverige. To andre,
    // selvstændigt driftede kopier af samme tabel fandtes desuden i
    // about.php og som en CSS-liste i inc/htm_page.lib.php. Alle tre
    // erstattet af et 'flag'-felt direkte på hvert sprog i languages.json -
    // indlæses nu ÉN gang her og genbruges af begge dropdown-render-
    // blokke nedenfor (fjerner samtidig to redundante gen-indlæsninger af
    // samme fil).
    $lang_file    = 'json-data/languages.json';
    $lang_data    = file_exists($lang_file) ? json_decode(file_get_contents($lang_file), true) : null;
    $flagMap      = [];
    if (!empty($lang_data['language'])) {
        foreach ($lang_data['language'] as $l) { $flagMap[$l['code']] = $l['flag'] ?? $l['code']; }
    }
    $fCode        = $flagMap[$current_l] ?? $current_l;
    $uLevI        = (int)$uLev;
    $userName     = htmlspecialchars($_SESSION['user_name'] ?? 'User');
    // RETTET (bruger-anmodet terminologiskift): "Beginner/Experienced/Developer"
    // beskrev niveauerne som brugerens erfaring - matcher samme skift i
    // menu_visibility.php's kolonneoverskrifter (se dér for begrundelsen).
    $levNames     = [1 => lang('@Minimal View'), 2 => lang('@Custom View'), 3 => lang('@Maximum View')];
    $currentName  = $levNames[$uLevI] ?? lang('@Unknown');
    $thHint       = ($currentTheme === 'dark')
        ? lang('@Dark theme: Use Ctrl+A if there is no contrast.')
        : lang('@Change color theme (Light ➔ Custom ➔ Dark)');
    $fsHint = lang('@Toggle full screen. Use F11 (Browser-controlled) to avoid resetting on page change');

    // ═════════════════════════════════════════════════════════════════════════
    // CSS
    // ═════════════════════════════════════════════════════════════════════════
    echo '<style>
    /* ── Topnav ── */
    .submenu { display:none; position:absolute; top:100%; left:0; background:var(--bg-submenu);
        min-width:240px; box-shadow:0 8px 16px rgba(0,0,0,0.4); z-index:9100; border-radius:4px;
        border:1px solid var(--border-subtle); pointer-events:auto; }
    .has-sub:hover > .sub-submenu { display:block; }
    .sub-submenu { display:none; position:absolute; left:100%; top:0; background:var(--bg-submenu);
        min-width:220px; border:1px solid var(--border-subtle); border-radius:4px;
        box-shadow:8px 0 16px rgba(0,0,0,0.4); }
    .dropdown-item { display:block; padding:12px 15px; color:white; text-decoration:none; font-size:14px; cursor:pointer; }
    .dropdown-item:hover { background:var(--bg-nav-hover); }
    .nav-main-link { display:flex; flex-direction:column; align-items:center; text-decoration:none;
        color:white; padding:8px 12px; min-width:80px; cursor:pointer; }
    .lang-button { background:rgba(0,0,0,0.2); padding:8px 12px; border-radius:20px;
        border:1px solid rgba(255,255,255,0.1); cursor:pointer; font-size:13px;
        display:flex; align-items:center; gap:8px; }
    .lang-dropdown-box { display:none; position:absolute; top:110%; right:0; background:var(--bg-submenu);
        min-width:160px; box-shadow:0 8px 16px rgba(0,0,0,0.4); z-index:9500; border-radius:4px;
        border:1px solid var(--border-subtle); }

    /* ── Layout toggle-knap i topnav ── */
    #menu-layout-toggle { background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.2);
        color:white; cursor:pointer; font-size:18px; line-height:1; padding:5px 8px;
        border-radius:5px; flex-shrink:0; margin-right:10px; }
    #menu-layout-toggle:hover { background:rgba(0,0,0,0.45); }

    /* ── Sidebar ── */
    body.sidebar-mode { margin-left:214px !important; margin-right:20px !important; margin-top:4px !important; }
    body.sidebar-mode #top-nav { display:none !important; }

    /* z-index hævet fra 9200 til 10001 (bruger-rapporteret): sidebaren spænder
       height:100vh med .sb-utils/.sb-logout nederst (margin-top:auto), som
       dermed lå i samme skærmområde som den faste .floating-action-bar
       (z-index:10000) og blev dækket af den - samme mønster som tidligere
       fundet på notepad-knappen og tip-boksen i backup.php, se
       floating-bar-overlap i hukommelsen. */
    #tc-sidebar { display:none; position:fixed; top:0; left:0; width:215px; height:100vh;
        box-sizing:border-box; background:var(--bg-nav); z-index:10001; overflow-y:auto; overflow-x:hidden;
        flex-direction:column; box-shadow:4px 0 12px rgba(0,0,0,0.35); font-family:sans-serif; color:white; }
    body.sidebar-mode #tc-sidebar { display:flex; }

    /* RETTET (bruger-rapporteret: "menuens indikator for undermenu skjules
       bag warning") - sb-item-elementets højrestillede pil (margin-left:auto) endte
       lige inde under #sb-backup-warning-strimlen, som optager de yderste
       22px af sidepanelets bredde. box-sizing:border-box ovenfor sikrer at
       denne padding-right IKKE gør selve sidepanelet bredere (215px i alt,
       stadig) - den presser i stedet det almindelige indhold (menu-punkter,
       pile, firmanavn m.m.) 22px længere ind, så det aldrig overlapper
       strimlen. Klassen sættes kun i PHP når advarslen rent faktisk vises. */
    #tc-sidebar.has-backup-warning { padding-right:22px; }

    /* Backup-advarsel i sidepanel-visning (bruger-anmodet: "90-grader
       roteret langs menuens højre kant") - position:absolute ankrer til
       #tc-sidebar selv (position:fixed er allerede en positioneringskontekst),
       så strimlen ligger INDENFOR sidepanelets egen 215px bredde, ikke ud
       over kanten ind over sideindholdet. writing-mode roterer teksten (og
       ikonet) 90 grader i stedet for en transform:rotate()-krykke - det
       giver naturlig topb-til-bund tekstflow uden manuel bredde-/højde-
       ombytning. $backup_alert_short (kun første sætning) bruges her, da
       den fulde advarselstekst ikke kan stå læsbart i en så smal bane - den
       fulde tekst er stadig tilgængelig som data-hint (tooltip). */
    /* RETTET (bruger-rapporteret: "hvid forgrund på gul bg er vanskelig at
       læse på varselsbanner") - var(--color-warning) er gul/orange i alle 3
       temaer (#f1c40f/#f39c12), hvid tekst derpå giver alt for lav kontrast.
       Sort tekst giver solid kontrast (samme "sort på gul"-princip som
       almindelig advarselsskiltning) uafhængigt af hvilket tema der er
       aktivt. */
    /* RETTET (bruger-anmodet): cursor:default -> pointer - stribens tekst
       ("Backup er X dage gammel") var ren tekst uden noget link, selvom
       den er altid synlig lige så længe backuppen faktisk er gammel. Et
       klik fører nu direkte til backup.php i stedet for at kræve at
       brugeren selv finder derhen gennem System-menuen. */
    #sb-backup-warning { position:absolute; top:0; right:0; width:22px; height:100%;
        box-sizing:border-box; background:var(--color-warning); color:#000; z-index:2;
        writing-mode:sideways-lr; text-orientation:mixed;
        display:flex; align-items:center; justify-content:center; text-align:center;
        font-size:12px; font-weight:bold; padding:10px 0; overflow:hidden;
        box-shadow:-2px 0 6px rgba(0,0,0,0.25); cursor:pointer; text-decoration:none; }

    #tc-sidebar .sb-logo { padding:14px 12px 10px; border-bottom:1px solid rgba(255,255,255,0.1);
        display:flex; flex-direction:row; align-items:center; gap:10px; }
    #tc-sidebar .sb-toggle { background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.2);
        color:white; cursor:pointer; font-size:16px; padding:5px 8px; border-radius:4px; flex-shrink:0; line-height:1; }
    #tc-sidebar .sb-toggle:hover { background:rgba(0,0,0,0.5); }
    #tc-sidebar .sb-logo-block { display:flex; flex-direction:column; gap:2px; min-width:0; }
    #tc-sidebar .sb-logo-text { font-size:1.3em; font-weight:bold; line-height:1.1; }
    #tc-sidebar .sb-logo-text span { color:var(--color-primary); }
    #tc-sidebar .sb-company { font-size:10px; color:var(--text-light); opacity:0.85;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px; }

    #tc-sidebar .sb-item { display:flex; align-items:center; gap:9px; padding:10px 14px;
        color:white; text-decoration:none; font-size:13.5px; cursor:pointer;
        border-left:3px solid transparent; }
    #tc-sidebar .sb-item:hover { background:var(--bg-nav-hover); }
    #tc-sidebar .sb-item.active { border-left-color:var(--color-primary); background:rgba(0,0,0,0.15); }
    #tc-sidebar .sb-item .sb-arrow { margin-left:auto; font-size:11px; opacity:0.6; transition:transform 0.2s; }
    #tc-sidebar .sb-item.open .sb-arrow { transform:rotate(90deg); }

    #tc-sidebar .sb-sub { display:none; background:rgba(0,0,0,0.25);
        border-left:3px solid var(--color-primary); margin-left:10px; }
    #tc-sidebar .sb-sub.open { display:block; }
    #tc-sidebar .sb-sub a { display:block; padding:9px 12px 9px 14px;
        color:rgba(255,255,255,0.9); text-decoration:none; font-size:13px;
        border-bottom:1px solid rgba(255,255,255,0.05); }
    #tc-sidebar .sb-sub a:hover { background:var(--bg-nav-hover); color:white; padding-left:18px; transition:padding 0.1s; }
    #tc-sidebar .sb-sub a.active { color:white; font-weight:600; background:rgba(52,152,219,0.15); }
    #tc-sidebar .sb-sub hr { border:0; border-top:1px solid rgba(255,255,255,0.15); margin:4px 0; }
    #tc-sidebar .sb-subsub-toggle { cursor:pointer; display:flex; align-items:center;
        padding:9px 12px 9px 14px; color:rgba(255,255,255,0.9); font-size:13px; gap:6px;
        border-bottom:1px solid rgba(255,255,255,0.05); }
    #tc-sidebar .sb-subsub-toggle:hover { background:var(--bg-nav-hover); }
    #tc-sidebar .sb-subsub { display:none; background:rgba(0,0,0,0.2); }
    #tc-sidebar .sb-subsub.open { display:block; }
    #tc-sidebar .sb-subsub a { padding-left:24px; font-size:12px; }

    #tc-sidebar .sb-utils { margin-top:auto; border-top:1px solid rgba(255,255,255,0.1);
        padding:10px 10px 14px; display:flex; flex-direction:column; gap:8px; }
    #tc-sidebar .sb-util-row { display:flex; align-items:center; gap:8px; font-size:12px; }
    #tc-sidebar .sb-util-row button { background:rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.15);
        color:white; border-radius:4px; cursor:pointer; font-size:13px; padding:3px 7px; }
    #tc-sidebar .sb-util-row button:hover { background:rgba(0,0,0,0.4); }
    #tc-sidebar .sb-logout { display:block; text-align:center; background:var(--color-danger);
        color:white; text-decoration:none; padding:7px; border-radius:4px; font-size:13px; font-weight:bold; }
    </style>';

    // ═════════════════════════════════════════════════════════════════════════
    // JavaScript
    // ═════════════════════════════════════════════════════════════════════════
    echo '<script>
    function adjustZoom(d) { document.body.style.zoom = (parseFloat(document.body.style.zoom || 1) + d); }
    function toggleSub(el) {
        var sub = el.nextElementSibling;
        document.querySelectorAll(".submenu").forEach(s => { if (s !== sub) s.style.display = "none"; });
        if (sub) sub.style.display = (sub.style.display === "block") ? "none" : "block";
    }
    function toggleTestLevel(currentLevel) {
        var next = currentLevel + 1; if (next > 3) next = 1;
        // RETTET: byggede FØR URL\x27en som href.split("?")[0] + "?set_level=" - men et "#" i adressen (fx efter et
        // klik paa et href="#"-undermenu-/hjaelpe-link) blev haengende, saa "?set_level=" endte INDE i fragmentet
        // ("side#?set_level=2") og aldrig naaede serveren: niveauet skiftede ikke. URL-API\x27et fjerner fragmentet
        // og saetter parameteren korrekt, uanset hvad der ellers staar i adressen.
        try {
            var u = new URL(window.location.href);
            u.hash = "";
            u.searchParams.set("set_level", next);
            window.location.href = u.toString();
        } catch (e) {
            window.location.href = window.location.pathname + "?set_level=" + next;
        }
    }
    function toggleLangMenu(e) {
        e.stopPropagation();
        var m = document.getElementById("lang-options");
        document.querySelectorAll(".submenu").forEach(s => s.style.display = "none");
        m.style.display = (m.style.display === "block") ? "none" : "block";
    }
    function toggleTheme() {
        var themes = ["light","custom","dark"];
        var current = document.documentElement.getAttribute("data-theme") || "light";
        if (!themes.includes(current)) current = "light";
        var next = themes[(themes.indexOf(current) + 1) % themes.length];
        document.documentElement.setAttribute("data-theme", next);
        var d = new Date(); d.setTime(d.getTime() + 365*24*60*60*1000);
        document.cookie = "theme=" + next + ";expires=" + d.toUTCString() + ";path=/;SameSite=Strict";
        var icons = {light:"☀️", custom:"✨", dark:"🌙"};
        ["theme-toggle-btn","sb-theme-btn"].forEach(id => {
            var btn = document.getElementById(id);
            if (btn) btn.innerText = icons[next];
        });
        fetch(window.location.href.split("?")[0] + "?set_theme_session=" + next + "&t=" + Date.now(),
            {method:"GET", headers:{"X-Requested-With":"XMLHttpRequest"}}).catch(() => {});
    }
    function toggleFullscreen() {
        if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(() => {});
        else document.exitFullscreen();
    }
    function sbToggleSub(el) {
        el.classList.toggle("open");
        var sub = el.nextElementSibling;
        if (sub) sub.classList.toggle("open");
    }
    function sbToggleSubSub(el) {
        var sub = el.nextElementSibling;
        if (sub) sub.classList.toggle("open");
    }
    function toggleMenuLayout() {
        var next = (localStorage.getItem("tc_menu_layout") === "sidebar") ? "top" : "sidebar";
        localStorage.setItem("tc_menu_layout", next);
        document.body.classList.toggle("sidebar-mode", next === "sidebar");
    }
    // Anvend gemt layout straks (undgår flash)
    (function() {
        if (localStorage.getItem("tc_menu_layout") === "sidebar")
            document.body.classList.add("sidebar-mode");
    })();
    window.onclick = function(e) {
        if (!e.target.closest(".dropdown") && !e.target.closest(".custom-lang-dropdown"))
            document.querySelectorAll(".submenu, #lang-options, #sb-lang-options").forEach(m => m.style.display = "none");
    };
    function toggleSbLang(e) {
        e.stopPropagation();
        var m = document.getElementById("sb-lang-options");
        if (m) m.style.display = (m.style.display === "block") ? "none" : "block";
    }
    </script>';

    // ═════════════════════════════════════════════════════════════════════════
    // RENDER-HJÆLPER: sub-menu items (bruges af BEGGE layouts)
    // ═════════════════════════════════════════════════════════════════════════
    // Renderes direkte — kaldes inline nedenfor
    // RETTET (bruger-anmodet: Regnskab -> Rapporter -> Revision, en ny
    // undermenu ÉT niveau dybere end Rapporter selv): denne funktion var FØR
    // hårdkodet til præcis ét niveau af "flyout i flyout" - når den stødte på
    // et punkt med sin egen 'submenu', rendrede den dets børn via en separat,
    // ikke-rekursiv inline foreach, som antog børnene altid var almindelige
    // links. Det virkede fint for Rapporter (kun ét sådant niveau nogen
    // steder i træet indtil nu), men ville rendre en 4.-niveau-undermenu
    // (Revision, som selv indeholder revisions-værktøjerne) som ét stort,
    // ugyldigt link i stedet for endnu en flyout. Gjort reelt rekursiv - kald
    // sig selv i stedet for at duplikere løkken - så ethvert nestings-dybde
    // virker uden videre ændringer. Bonus: sub-sub-punkter (fx Revision-
    // værktøjerne) får nu samme aktiv-markering og sidebar-hint-understøttelse
    // som almindelige punkter, i stedet for slet ingen (den gamle inline-
    // udgave manglede begge dele for netop dét niveau).
    $renderSubItems = function($submenu, $mode) use (&$renderSubItems, $isSubAllowed, $current_page, $ctxAttrs) {
        // $mode: 'top' eller 'sidebar'
        foreach ($submenu as $subUrl => $subVal) {
            if (!$isSubAllowed($subUrl)) continue;

            // Separator
            if (strpos($subUrl, '---') !== false) {
                if ($mode === 'top')
                    echo '<hr style="border:0; border-top:1px solid rgba(255,255,255,0.2); margin:5px 0;">';
                else
                    echo '<hr>';
                continue;
            }

            // Endnu en flyout-undermenu (Sub-sub, Sub-sub-sub, osv.) - de
            // enkelte punkter heri tjekkes stadig individuelt for niveau-
            // synlighed ($isSubAllowed), samme princip uanset nestings-dybde.
            if (is_array($subVal) && isset($subVal['submenu'])) {
                // RETTET (bruger-rapporteret dobbelt pil, "Revision ▶ ▶"):
                // top-tilstands-labels bærer selv en indlejret "▶" (i et
                // <span style="float:right">, til visning i selve dropdown-
                // linjen) - strip_tags() fjerner kun SELVE span-taggen, ikke
                // pil-TEGNET som stod inde i den (det er tekstindhold, ikke
                // opmærkning). Koden nedenfor tilføjede derefter SIN EGEN
                // afsluttende " ▶" oveni, uanset - deraf dobbeltpilen i
                // sidepanel-tilstand. Fjerner nu også selve tegnet, så kun ét
                // pil-symbol (det tilføjede) er tilbage, uanset om en
                // fremtidig label selv indeholder et eller ej.
                $ssLabel = trim(str_replace('▶', '', strip_tags($subVal['label'] ?? '')));
                $ssKeyAttr = $ctxAttrs($subUrl, true);
                if ($mode === 'top') {
                    echo '<div class="has-sub" style="position:relative;">';
                    echo '<a href="#" class="dropdown-item"'.$ssKeyAttr.' style="background:rgba(0,0,0,0.1);">'.($subVal['label'] ?? '').'</a>';
                    echo '<div class="sub-submenu">';
                    $renderSubItems($subVal['submenu'], 'top');
                    echo '</div></div>';
                } else {
                    echo '<div class="sb-subsub-toggle"'.$ssKeyAttr.' onclick="sbToggleSubSub(this)">'.$ssLabel.' ▶</div>';
                    echo '<div class="sb-subsub">';
                    $renderSubItems($subVal['submenu'], 'sidebar');
                    echo '</div>';
                }
                continue;
            }

            // Normal sub-item
            $subLabel = is_array($subVal) ? ($subVal['label'] ?? '') : $subVal;
            $subHint  = (is_array($subVal) && !empty($subVal['hint'])) ? ' data-hint="'.htmlspecialchars($subVal['hint']).'"' : '';
            $isActive = (strpos($subUrl, $current_page) !== false);
            $subKeyAttr = $ctxAttrs($subUrl);

            if ($mode === 'top') {
                echo '<a href="'.htmlspecialchars($subUrl).'" class="dropdown-item"'.$subKeyAttr.$subHint.'>'.$subLabel.'</a>';
            } else {
                echo '<a href="'.htmlspecialchars($subUrl).'"'.$subKeyAttr.($isActive ? ' class="active"' : '').'>'.$subLabel.'</a>';
            }
        }
    };

    // ═════════════════════════════════════════════════════════════════════════
    // A) SIDEBAR
    // ═════════════════════════════════════════════════════════════════════════
    echo '<div id="tc-sidebar"'.($show_backup_warning ? ' class="has-backup-warning"' : '').'>';

    // Backup-advarsel som en lodret, 90-grader-roteret strimmel langs
    // sidepanelets højre kant (bruger-anmodet placering) - $show_backup_
    // warning/$backup_alert_short/$backup_alert_msg er alle beregnet én
    // gang tidligt i showMenu(), se dens kommentar. position:absolute her
    // ankrer til #tc-sidebar selv (som er position:fixed, altså allerede en
    // positioneringskontekst) - strimlen ligger dermed INDENFOR sidepanelets
    // egen bredde, ikke ud over kanten ind over selve sideindholdet.
    if ($show_backup_warning) {
        echo '<a id="sb-backup-warning" href="backup.php" data-hint="'.htmlspecialchars($backup_alert_msg, ENT_QUOTES).'">'
            . '<i class="fa-solid fa-triangle-exclamation"></i> <nbs>&nbsp;</nbs>' . htmlspecialchars($backup_alert_short)
            . '</a>';
    }

    // Logo
    echo '<div class="sb-logo">';
    echo '<button class="sb-toggle" onclick="toggleMenuLayout()" title="'.lang('@Switch to top navigation').'"><i class="ti ti-layout-navbar"></i></button>';
    echo '<div class="sb-logo-block">';
    echo '<div class="sb-logo-text"><a href="about.php" style="color:white;text-decoration:none;"><img src="favicon.svg" alt="" style="width:28px; height:28px; top: 5px; position: relative;" /><span>Tiny</span>Cash</a></div>';
    // RETTET (samme sweep som backup-advarslen ovenfor): manglede en else-
    // gren her - #top-nav viser en "@Develop version w. errors"-note under
    // logoet når intet firmanavn er sat op endnu (company_settings.php),
    // men sidepanelets logo-blok havde kun grenen for det UDFYLDTE tilfælde.
    if ($company_name !== '')
        echo '<div class="sb-company" style="text-align: center;" title="'.htmlspecialchars($company_name).'">'.htmlspecialchars($company_name).'</div>';
    else
        echo '<div class="sb-company" style="text-align: center; color:var(--color-warning);">'.lang('@Develop version w. errors').'</div>';
    echo '</div></div>';

    // Menu-items
    echo '<div>';
    foreach ($menu as $url => $config) {
        if ($url === 'logout.php') continue;
        if (!$isAllowed($url)) continue;

        $isDropdown = isset($config['submenu']);
        $fullText   = is_array($config) ? ($config['label'] ?? '') : $config;
        $parts      = explode(' ', $fullText, 2);
        $icon       = $parts[0] ?? '';
        $label      = $parts[1] ?? $fullText;
        $active     = $isActiveItem($url, $config);

        if ($isDropdown) {
            $openClass = $active ? ' open' : '';
            echo '<div class="sb-item'.$openClass.'"'.$ctxAttrs($url, true).' onclick="sbToggleSub(this)">';
            echo '<span>'.$icon.'</span><span>'.$label.'</span><span class="sb-arrow">▶</span>';
            echo '</div>';
            echo '<div class="sb-sub'.$openClass.'">';
            $renderSubItems($config['submenu'], 'sidebar');
            echo '</div>';
        } else {
            echo '<a class="sb-item'.($active ? ' active' : '').'"'.$ctxAttrs($url).' href="'.htmlspecialchars($url).'">';
            echo '<span>'.$icon.'</span><span>'.$label.'</span>';
            echo '</a>';
        }
    }
    echo '</div>';

    // Utility-sektion
    $lang_file = 'json-data/languages.json';
    $levelHint = htmlspecialchars($currentName . ' - ' . lang('@Click to change'));
    echo '<div class="sb-utils">';

    // Visning: zoom, fullscreen, tema, niveau
    echo '<div class="sb-util-row">';
    echo '<button onclick="adjustZoom(0.02)" title="'.lang('@Zoom in').'">+</button>';
    echo '<button onclick="adjustZoom(-0.02)" title="'.lang('@Zoom out').'">−</button>';
    echo '<button onclick="toggleFullscreen()" title="'.htmlspecialchars($fsHint).'">⛶</button>';
    echo '<button id="sb-theme-btn" onclick="toggleTheme()" title="'.htmlspecialchars($thHint).'">'.$currentIcon.'</button>';
    echo "<button onclick=\"toggleTestLevel($uLevI);\" title=\"$levelHint\"
        style=\"font-family:monospace; font-weight:bold;\">L$uLevI</button>";
    echo '</div>';

    // Sprog: kun aktuelt flag + navn, klik åbner topnav-stil dropdown
    echo '<div class="custom-lang-dropdown" style="position:relative;">';
    echo '<div onclick="toggleSbLang(event)" style="display:flex; align-items:center; gap:8px; cursor:pointer;
        background:rgba(0,0,0,0.2); padding:5px 8px; border-radius:4px; border:1px solid rgba(255,255,255,0.15);">';
    echo '<span class="fi fi-'.$fCode.'" style="width:18px; height:13px;"></span>';
    echo '<span style="font-size:12px;">'.strtoupper($current_l).'</span>';
    echo '<span style="font-size:10px; opacity:0.6; margin-left:auto;">▼</span>';
    echo '</div>';
    echo '<div id="sb-lang-options" style="display:none; position:absolute; bottom:100%; left:0;
        background:var(--bg-submenu); min-width:160px; border:1px solid var(--border-subtle);
        border-radius:4px; box-shadow:0 -8px 16px rgba(0,0,0,0.4); z-index:9500; margin-bottom:4px;">';
    if (!empty($lang_data['language'])) {
        foreach ($lang_data['language'] as $l) {
                $optCode   = $flagMap[$l['code']] ?? $l['code'];
                $isCurrent = ($l['code'] == $current_l);
                $bgStyle   = $isCurrent ? 'background:rgba(52,152,219,0.2); font-weight:600;' : '';
                echo '<a href="?l='.htmlspecialchars($l['code']).'"
                    style="display:flex; align-items:center; gap:8px; padding:8px 10px; color:white;
                    text-decoration:none; font-size:12px; '.$bgStyle.'">';
                echo '<span class="fi fi-'.$optCode.'" style="width:18px; height:13px; flex-shrink:0;"></span>';
                echo '<span>'.htmlspecialchars($l['native']).'</span>';
                echo '</a>';
        }
    }
    echo '</div>';
    echo '</div>'; // custom-lang-dropdown

    echo '<a href="logout.php" class="sb-logout">🚪 '.$userName.' — '.lang('@Logout').'</a>';
    echo '</div>'; // sb-utils
    echo '</div>'; // #tc-sidebar

    // ═════════════════════════════════════════════════════════════════════════
    // B) TOP-NAVIGATION
    // ═════════════════════════════════════════════════════════════════════════
    // RETTET (bruger-rapporteret: "Information: Databasen er tom..."-
    // banneret er adskilt fra/fejljusteret i forhold til Mine opgaver
    // nedenunder): backup-advarslen herunder er position:absolute;top:100%
    // - taget helt ud af normal flow, så den ALDRIG skubber det næste
    // element (denne "Databasen er tom"-info-boks) nedad for at give
    // plads. nav's faste margin-bottom:20px var uændret uanset om
    // advarslen reelt vises, men selve advarselsbjælken (padding 6px top/
    // bund + tekstlinje) fylder markant mere end 20px - resultatet var et
    // reelt visuelt OVERLAP mellem varselsbjælkens nederste kant og toppen
    // af det næste indholdselement, hvilket så ud som en skæv/afbrudt
    // sammenhæng mellem de to. Øger margin-bottom til 56px netop når
    // advarslen faktisk vises, så der er plads nok - $show_backup_warning
    // beregnes allerede tidligere i showMenu(), se dens egen kommentar.
    $topnav_margin_bottom = $show_backup_warning ? '56px' : '20px';
    echo '<nav id="top-nav" style="background:var(--bg-nav); padding:1px 5px; margin-bottom:'.$topnav_margin_bottom.';
        display:flex; align-items:center; min-height:65px; font-family:sans-serif; color:white;
        position:relative; z-index:9000; overflow:visible !important;">';

    // Toggle-knap
    echo '<button id="menu-layout-toggle" onclick="toggleMenuLayout()"
        title="'.lang('@Switch to sidebar menu').'" data-hint="'.lang('@Switch menu between horizontal top bar and vertical side menu').'">
        <i class="ti ti-layout-sidebar"></i></button>';

    // Logo
    echo '<div style="margin-right:10px; font-size:1.7em; font-weight:bold; flex-shrink:0;">';
    echo '<a href="about.php" style="color:white;text-decoration:none;">
        <img src="favicon.svg" alt="" style="width:28px; height:28px; top: 5px; position: relative;" /><span style="color:var(--color-primary);">Tiny</span>Cash</a>';
    if ($company_name !== '')
        echo '<div style="font-size:10px; font-weight:600; color:var(--text-light); opacity:0.85; margin-top:2px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px; text-align: center;"
              data-hint="'.htmlspecialchars($company_name).'">'.htmlspecialchars($company_name).'</div>';
    else
        echo '<div style="font-size:10px; font-weight:200; color:var(--color-warning); margin-top:2px;">'.lang('@Develop version w. errors').'</div>';
    echo '</div>';

    // Menu-items
    foreach ($menu as $url => $config) {
        if ($url === 'logout.php') continue;
        if (!$isAllowed($url)) continue;

        $isDropdown = isset($config['submenu']);
        $fullText   = is_array($config) ? ($config['label'] ?? '') : $config;
        $hintText   = is_array($config) ? ($config['hint'] ?? '') : '';
        $parts      = explode(' ', $fullText, 2);
        $icon       = $parts[0] ?? '';
        $text       = $parts[1] ?? $fullText;
        $active     = $isActiveItem($url, $config);

        $activeStyle = $active ? 'border-bottom:3px solid var(--color-primary);' : '';
        $onClick     = $isDropdown
            ? 'onclick="toggleSub(this)"'
            : 'onclick="window.location.href=\''.htmlspecialchars($url).'\'"';
        $h_attr = $hintText ? ' data-hint="'.htmlspecialchars($hintText).'"' : '';

        echo '<div class="dropdown" style="position:relative; cursor:pointer;">';
        echo '<div class="nav-main-link"'.$ctxAttrs($url, $isDropdown).' style="'.$activeStyle.'" '.$onClick.$h_attr.'>';
        echo '<span style="font-size:1.5em; pointer-events:none;">'.$icon.'</span>';
        echo '<span style="font-size:0.85em; white-space:nowrap; pointer-events:none;">'.$text.($isDropdown ? ' ▼' : '').'</span>';
        echo '</div>';
        if ($isDropdown) {
            echo '<div class="submenu">';
            $renderSubItems($config['submenu'], 'top');
            echo '</div>';
        }
        echo '</div>';
    }

    // Højre side: zoom/fs/tema + sprog + bruger
    echo '<div style="margin-left:auto; display:flex; align-items:center; gap:10px;">';
    echo '<div style="display:flex; flex-direction:column; align-items:center;">';
    echo '<div style="display:flex; background:rgba(0,0,0,0.2); padding:2px; border-radius:6px; border:1px solid rgba(255,255,255,0.1); align-items:center; gap:2px;">';
    echo '<button onclick="adjustZoom(0.02)"  style="background:none;border:none;color:white;cursor:pointer;font-size:14px;padding:0 4px;">+</button>';
    echo '<button onclick="adjustZoom(-0.02)" style="background:none;border:none;color:white;cursor:pointer;font-size:14px;padding:0;">-</button>';
    echo '<button onclick="toggleFullscreen()" data-hint="'.htmlspecialchars($fsHint).'" style="background:none;border:none;color:white;cursor:pointer;font-size:16px;border-left:1px solid rgba(255,255,255,0.2);padding-left:8px;">⛶</button>';
    echo '<button id="theme-toggle-btn" onclick="toggleTheme()" data-hint="'.htmlspecialchars($thHint).'" style="background:none;border:none;color:white;cursor:pointer;font-size:14px;border-left:1px solid rgba(255,255,255,0.2);padding: 0;">'.$currentIcon.'</button>';
    // Niveau-knappen flyttet hertil fra logud-knappen (bruger-anmodet) - hører
    // sammen med de øvrige visnings-indstillinger, ikke med log ud-handlingen.
    echo '<button onclick="toggleTestLevel('.$uLevI.')" data-hint="'.htmlspecialchars($levelHint).'" style="background:none;border:none;color:white;cursor:pointer;font-size:12px;font-family:monospace;font-weight:bold;max-width: 20px;">L'.$uLevI.'</button>';
    echo '</div>';
    echo '<div style="font-size:10px;color:white;text-transform:uppercase;opacity:0.8;margin-top:2px;"
          data-hint="'.lang('@Here you can zoom in and out, <br>Switch to/from full screen, <br>or change color theme').'">'.lang('@VIEW').'</div>';
    echo '</div>';

    echo '<div class="custom-lang-dropdown" style="position:relative;">';
    echo '<div onclick="toggleLangMenu(event)" class="lang-button"><span class="fi fi-'.$fCode.'"></span> <b>'.strtoupper($current_l).'</b> ▼</div>';
    echo '<div id="lang-options" class="lang-dropdown-box">';
    if (!empty($lang_data['language'])) {
        foreach ($lang_data['language'] as $l) {
            $optCode  = $flagMap[$l['code']] ?? $l['code'];
            $bgStyle  = ($l['code'] == $current_l) ? 'background:rgba(52,152,219,0.15);' : '';
            echo '<a href="?l='.$l['code'].'" style="display:flex;align-items:center;gap:10px;padding:10px;color:white;text-decoration:none;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px;'.$bgStyle.'">';
            echo '<span class="fi fi-'.$optCode.'" style="width:20px;height:15px;"></span><span>'.$l['native'].'</span></a>';
        }
    }
    echo '</div>';
    echo '<div style="font-size:11px;color:white;text-align:center;text-transform:uppercase;opacity:0.9;">'.lang('@LANGUAGE').'</div>';
    echo '</div>';

    // Forenklet og gjort smallere (bruger-anmodet) - niveau-knappen der før sad
    // indlejret her er flyttet op i visnings-gruppen ovenfor. min-width fjernet,
    // så knappen nu bare fylder hvad indholdet kræver.
    echo '<a href="logout.php" style="background:var(--color-danger);color:white;padding:5px 10px;border-radius:4px;text-decoration:none;font-size:13px;text-align:center;display:inline-block;"
          data-hint="'.htmlspecialchars($currentName).'">
        <small style="opacity:0.9;display:block;margin-bottom:1px;">"'.$userName.'"</small>
        <b>👤 '.lang('@Logout').'</b>
    </a>';
    echo '</div>'; // højre side

    // Backup-advarsel - $show_backup_warning/$backup_alert_msg beregnet én
    // gang, tidligt i showMenu() (se dens kommentar), så #tc-sidebar-
    // visningen nedenfor kan bruge nøjagtig samme værdier.
    if ($show_backup_warning) {
        // RETTET (bruger-rapporteret: "hvid forgrund på gul bg er vanskelig
        // at læse på varselsbanner") - se samme rettelse på #sb-backup-warning
        // ovenfor: sort tekst på den gule/orange var(--color-warning) i
        // stedet for hvid.
        // RETTET (bruger-anmodet): samme klikbarheds-forbedring som
        // #sb-backup-warning ovenfor - <a> i stedet for <div>, direkte til
        // backup.php.
        echo '<a href="backup.php" data-hint="' . lang('@Go to Backup Management and take a backup') . '" style="position:absolute;top:100%;left:0;right:0;background:var(--color-warning);color:#000;text-align:center;padding:6px 20px;font-size:13px;font-weight:bold;box-shadow:0 4px 6px rgba(0,0,0,0.1);z-index:8900;text-decoration:none;cursor:pointer;display:block;">
            <i class="fa-solid fa-triangle-exclamation" style="margin-right:8px;"></i>'.$backup_alert_msg.'</a>';
    }

    echo '</div></nav>';

    // NY FUNKTION (bruger-anmodet): højreklik på et menupunkt -> mulighed for
    // at skjule det for L1/L2 direkte fra selve menuen, uden at skulle inde
    // på den separate menu_visibility.php-administrationsside. Kun indlejret
    // for admins (samme rolle-tjek som menu_hide_action.php selv håndhæver
    // server-side - klient-siden her er bekvemmelighed, ikke selve
    // spærren). Event-delegation på document (ét lyttepunkt, ikke ét pr.
    // menu-link) - fanger BÅDE sidepanel- og topnav-tilstand, da begge
    // deler samme [data-menukey]-mærkning tilføjet ovenfor i denne funktion.
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        ?>
        <style>
        /* RETTET (bruger-rapporteret: "ses ikke på sort baggrund"): manglede
           en EKSPLICIT lys tekstfarve - arvede i stedet body's --text-main,
           som i lys-temaet er MØRK tekst (#333) på selve --bg-submenu, der
           bevidst ER en mørk baggrundsfarve i alle temaer (samme farve som
           .dropdown-item/.nav-main-link allerede bruger --text-light til,
           se disse klassers egen definition). */
        #tc-menu-ctx { position:fixed; z-index:99999; background:var(--bg-submenu); color:var(--text-light);
            border:1px solid var(--border-subtle); border-radius:6px; box-shadow:0 8px 24px rgba(0,0,0,0.4);
            min-width:270px; padding:6px; display:none; font-size:13px; }
        #tc-menu-ctx label { display:flex; align-items:center; gap:8px; padding:8px 10px; border-radius:4px;
            cursor:pointer; color:var(--text-light); }
        #tc-menu-ctx label:hover { background:rgba(255,255,255,0.1); }
        #tc-menu-ctx input[type="checkbox"] { width:16px; height:16px; cursor:pointer; }
        #tc-menu-ctx button { display:block; width:100%; text-align:left; background:none; border:none; color:var(--text-light);
            padding:8px 10px; border-radius:4px; cursor:pointer; font-size:inherit; margin-top:4px; }
        #tc-menu-ctx button:hover { background:rgba(255,255,255,0.1); }
        #tc-menu-ctx hr { border:0; border-top:1px solid rgba(255,255,255,0.15); margin:6px 0; }
        #tc-menu-ctx .tc-menu-ctx-label { padding:6px 10px 4px; font-size:11px; color:var(--text-light); opacity:0.7;
            text-transform:uppercase; font-weight:bold; }
        /* NYT (bruger-anmodet): markerer et højreklik-skjulbart FELT (ikke et
           menupunkt) - samme kontekstmenu genbruges, se JS'en nedenfor. Kun
           synlig for admins (hele denne blok er allerede admin-only).
           RETTET (bruger-rapporteret, to runder):
           1) "jeg ser ikke stiplet blå ramme" - brugte FØR var(--color-info),
              som er en mørk gråblå (#34495e) i lys-temaet og en lysegrå
              (#c4c6c8) i dark-temaet til badge-/tekstbrug, hverken tydeligt
              "blå" eller kraftig nok. Erstattet med en FAST, tema-uafhængig
              blå (#2196f3, altid blå uanset tema).
           2) "det var ikke meningen at tilføje en ramme men at den
              eksisterende blot ændrede udseende" - et almindeligt htm_Field()
              har allerede sin egen <fieldset>-kant; at pakke DEN ind i endnu
              en boks med sin egen kant gav en dobbelt/indlejret ramme-effekt.
              ".tc-hideable-field fieldset" omfarver nu den EKSISTERENDE
              fieldset-kant i stedet for at tilføje en ny udenom. Kun felter
              der slet IKKE har nogen boks i forvejen (klassen "tc-no-
              existing-box", se inc/field_visibility.inc.php's $has_own_box)
              får en helt ny ramme tilføjet. */
        .tc-hideable-field { display:inline-block; vertical-align:top; position:relative; max-width:100%; }
        /* RETTET (bruger-anmodet, runde 28-opfølgning): fjernet den blålige
           baggrundstoning på tillægsfelter - kun den stiplede blå kant er nu
           tilbage som markering. */
        .tc-hideable-field fieldset { border:2px dashed #2196f3 !important; }
        .tc-hideable-field.tc-no-existing-box { border:2px dashed #2196f3; border-radius:6px; padding:6px 8px;
            margin:3px; }
        </style>
        <div id="tc-menu-ctx">
            <div class="tc-menu-ctx-label"></div>
            <label><input type="checkbox" id="tc-menu-ctx-l1"> <span id="tc-menu-ctx-l1-txt"></span></label>
            <label><input type="checkbox" id="tc-menu-ctx-l2"> <span id="tc-menu-ctx-l2-txt"></span></label>
            <button type="button" id="tc-menu-ctx-save"></button>
            <hr>
            <button type="button" id="tc-menu-ctx-restore"></button>
        </div>
        <script>
        (function() {
            // NYT (bruger-anmodet: "Det skal være valgbart L1 / L2 alene
            // eller begge"): L1 og L2 kan nu skjules/vises helt uafhængigt
            // af hinanden via to separate afkrydsningsfelter, i stedet for
            // den tidligere ene "skjul begge på én gang"-knap. Nuværende
            // tilstand kommer fra data-hidden-l1/data-hidden-l2 (udskrevet
            // server-side ud fra det allerede indlæste $overrides, se
            // $ctxAttrs() ovenfor i denne fil) - intet AJAX-kald nødvendigt
            // bare for at ÅBNE menuen og se hvad der allerede er skjult.
            var ctxKey = null, ctxHasChildren = false, ctxL1Initial = false, ctxL2Initial = false, ctxTarget = 'menu';
            var menu = document.getElementById('tc-menu-ctx');
            var labelEl = menu.querySelector('.tc-menu-ctx-label');
            var l1Box = document.getElementById('tc-menu-ctx-l1');
            var l2Box = document.getElementById('tc-menu-ctx-l2');
            var l1Txt = document.getElementById('tc-menu-ctx-l1-txt');
            var l2Txt = document.getElementById('tc-menu-ctx-l2-txt');
            var saveBtn = document.getElementById('tc-menu-ctx-save');
            var restoreBtn = document.getElementById('tc-menu-ctx-restore');
            var TXT = {
                hideL1: <?php echo json_encode(lang('@Hidden for L1 (Minimal View)')); ?>,
                hideL2: <?php echo json_encode(lang('@Hidden for L2 (Custom View)')); ?>,
                save: <?php echo json_encode(lang('@Save')); ?>,
                restore: <?php echo json_encode(lang('@Restore to default visibility')); ?>,
                warnChildren: <?php echo json_encode(lang('@This item has sub-items - hiding it will also hide ALL of them for that level. Continue?')); ?>,
                doneMenu: <?php echo json_encode(lang('@Menu visibility updated. Only an administrator can change it back (System -> Menu Visibility).')); ?>,
                doneField: <?php echo json_encode(lang('@Field visibility updated. Only an administrator can change it back (right-click the field again).')); ?>,
                restored: <?php echo json_encode(lang('@Visibility restored to default.')); ?>,
                error: <?php echo json_encode(lang('@Could not save this change.')); ?>
            };
            l1Txt.textContent = TXT.hideL1;
            l2Txt.textContent = TXT.hideL2;
            saveBtn.textContent = TXT.save;
            restoreBtn.textContent = TXT.restore;
            document.addEventListener('contextmenu', function(e) {
                // NYT (bruger-anmodet): samme kontekstmenu genbruges nu til to
                // FORSKELLIGE ting - et menupunkt ([data-menukey], mod
                // menu_hide_action.php) eller et enkelt, markeret formularfelt
                // ([data-fieldkey], mod field_hide_action.php, se inc/field_
                // visibility.inc.php). closest() prøver menupunktet FØRST, da
                // et felt aldrig kan ligge inden i et menupunkt, men et
                // menupunkt (i sjældne tilfælde) teoretisk kunne indeholde et
                // markeret felt - menupunktet skal derfor vinde ved en reel
                // konflikt, ikke omvendt.
                var el = e.target.closest('[data-menukey]');
                var isField = false;
                if (!el) {
                    el = e.target.closest('[data-fieldkey]');
                    isField = !!el;
                }
                if (!el) return;
                e.preventDefault();
                ctxTarget = isField ? 'field' : 'menu';
                ctxKey = el.getAttribute(isField ? 'data-fieldkey' : 'data-menukey');
                // Felter har ingen undermenupunkter - kaskade-advarslen er
                // udelukkende relevant for menupunkter.
                ctxHasChildren = !isField && el.getAttribute('data-haschildren') === '1';
                labelEl.textContent = ctxKey;
                l1Box.checked = el.getAttribute('data-hidden-l1') === '1';
                l2Box.checked = el.getAttribute('data-hidden-l2') === '1';
                ctxL1Initial = l1Box.checked;
                ctxL2Initial = l2Box.checked;
                menu.style.display = 'block';
                var x = e.clientX, y = e.clientY;
                var mw = 280, mh = 160;
                if (x + mw > window.innerWidth) x = window.innerWidth - mw - 10;
                if (y + mh > window.innerHeight) y = window.innerHeight - mh - 10;
                menu.style.left = x + 'px';
                menu.style.top = y + 'px';
            });
            document.addEventListener('click', function(e) {
                if (!menu.contains(e.target)) menu.style.display = 'none';
            });
            function callAction(action, extra) {
                var fd = new FormData();
                fd.append('key', ctxKey);
                fd.append('action', action);
                if (extra) { for (var k in extra) fd.append(k, extra[k]); }
                fd.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
                var endpoint = (ctxTarget === 'field') ? 'field_hide_action.php' : 'menu_hide_action.php';
                fetch(endpoint, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        menu.style.display = 'none';
                        if (data.success) {
                            alert(action === 'set_levels' ? (ctxTarget === 'field' ? TXT.doneField : TXT.doneMenu) : TXT.restored);
                            window.location.reload();
                        } else {
                            alert(TXT.error + (data.error ? ' (' + data.error + ')' : ''));
                        }
                    })
                    .catch(function() { menu.style.display = 'none'; alert(TXT.error); });
            }
            saveBtn.addEventListener('click', function() {
                // RETTET (selv-fanget, "find fem fejl"): sendte FØR altid
                // BEGGE niveauers tilstand, uanset om brugeren reelt havde
                // rørt ved dem - baseret på et snapshot taget da menuen blev
                // åbnet. To administratorer der samtidig højreklikkede samme
                // punkt og hver især kun ændrede ÉT niveau kunne dermed
                // tabe hinandens ændring (den anden admins snapshot af det
                // niveau VEDKOMMENDE ikke rørte var forældet og overskrev
                // stille den første admins reelle ændring). Sender nu KUN
                // det/de niveauer der faktisk blev ændret siden menuen blev
                // åbnet - serveren beholder den aktuelle, friskt-læste værdi
                // for et niveau der slet ikke indgår i requesten (se
                // menu_hide_action.php's tilsvarende rettelse).
                var l1Changed = l1Box.checked !== ctxL1Initial;
                var l2Changed = l2Box.checked !== ctxL2Initial;
                if (!l1Changed && !l2Changed) { menu.style.display = 'none'; return; }
                // Advar kun om kaskaden når et niveau reelt SKIFTER fra synlig
                // til skjult her og nu - ikke blot fordi det (evt. allerede
                // fra før) står som skjult i det snapshot menuen blev åbnet med.
                var newlyHidingChildren = ctxHasChildren &&
                    ((l1Changed && l1Box.checked) || (l2Changed && l2Box.checked));
                if (newlyHidingChildren && !confirm(TXT.warnChildren)) { menu.style.display = 'none'; return; }
                var extra = {};
                if (l1Changed) extra.hide_l1 = l1Box.checked ? '1' : '0';
                if (l2Changed) extra.hide_l2 = l2Box.checked ? '1' : '0';
                callAction('set_levels', extra);
            });
            restoreBtn.addEventListener('click', function() { callAction('restore'); });
        })();
        </script>
        <?php
    }
}
?>
