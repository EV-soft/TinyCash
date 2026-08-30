<?php # /inc/menu.inc.php v:1.3.0 d:2026-08-30 i:evs
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
                'recurring_invoices.php'     => '🔁 ' . lang('@Recurring Invoices'),
                'mail_inbox.php?box=invoice' => '🗂️ ' . lang('@Invoice Copies Inbox'),
            ]],
        'expenses'   => ['label' => '🛒 ' . lang('@Purchases'),   'hint' => lang('@Manage expenses, vouchers and supplier invoices'),
            'submenu' => [
                'expense_list.php'           => '📋 ' . lang('@Expense List'),
                'expense_edit.php?id=0'      => '📥 ' . lang('@Register Expense'),
                'supplier_list.php'          => '🏭 ' . lang('@Suppliers'),
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
                        ['ledger_view.php'        => '📖 ' . lang('@General Ledger')],
                        $is_dkk_base ? ['vat_report.php' => '🧾 ' . lang('@VAT Report')] : [],
                        [
                            'aging_report.php'       => '📆 ' . lang('@Aging Report'),
                            'report_income.php'      => '📊 ' . lang('@Profit & Loss Report'),
                            'balance_sheet.php'      => '⚖️ ' . lang('@Balance Sheet'),
                            'annual_report.php'      => '📜 ' . lang('@Annual Report'),
                        ]
                    )],
                '---_1'                      => '---',
                'bank_import_step1.php'      => '📥 ' . lang('@Import Bank File'),
                'bank_integration.php'       => '🏦 ' . lang('@Bank Integration (PSD2)'),
                'reconcile_list.php'         => '⚖️ ' . lang('@Bank Reconciliation'),
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
                        'setup_chart.php'         => ['label' => '📑 ' . lang('@Chart of Accounts Proposal'), 'hint' => lang('@Only Danish layout')],
                        'run_migrate.php'         => ['label' => '📦 ' . lang('@Database migration'),         'hint' => lang('@Update database structure')],
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
    if (in_array($url, ['settings_fees.php','chart_of_accounts.php','user_list.php','storage_browser.php','maintenance'], true)) return [1 => false, 2 => false, 3 => true];
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

    // ── Fælles kontekst til utility-widgets ──────────────────────────────────
    $currentTheme = $_COOKIE['theme'] ?? 'light';
    $themeIcons   = ['light' => '☀️', 'custom' => '✨', 'dark' => '🌙'];
    $currentIcon  = $themeIcons[$currentTheme] ?? '☀️';
    $current_l    = $_SESSION['lang'] ?? 'da';
    $fMap         = ['da'=>'dk','en'=>'gb','kl'=>'gl','se'=>'se','nb'=>'no','nn'=>'no','pt'=>'pt','es'=>'es'];
    $fCode        = $fMap[$current_l] ?? $current_l;
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
        background:var(--bg-nav); z-index:10001; overflow-y:auto; overflow-x:hidden;
        flex-direction:column; box-shadow:4px 0 12px rgba(0,0,0,0.35); font-family:sans-serif; color:white; }
    body.sidebar-mode #tc-sidebar { display:flex; }

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
        window.location.href = window.location.href.split("?")[0] + "?set_level=" + next;
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
    $renderSubItems = function($submenu, $mode) use ($isSubAllowed, $current_page) {
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

            // Sub-sub (Control Panel) - RETTET: de enkelte punkter herunder
            // (fx backup.php, run_migrate.php) blev FØR aldrig niveau-
            // tjekket individuelt, kun selve "Maintenance"-punktet ovenfor -
            // en flad tabel med niveau pr. enkelt menu-punkt giver ikke
            // mening, hvis punkter tre niveauer nede reelt ikke kan skjules.
            if (is_array($subVal) && isset($subVal['submenu'])) {
                $ssLabel = strip_tags($subVal['label'] ?? '');
                if ($mode === 'top') {
                    echo '<div class="has-sub" style="position:relative;">';
                    echo '<a href="#" class="dropdown-item" style="background:rgba(0,0,0,0.1);">'.($subVal['label'] ?? '').'</a>';
                    echo '<div class="sub-submenu">';
                    foreach ($subVal['submenu'] as $ssUrl => $ssVal2) {
                        if (!$isSubAllowed($ssUrl)) continue;
                        $ssLabel2 = is_array($ssVal2) ? ($ssVal2['label'] ?? '') : $ssVal2;
                        $ssHint   = (is_array($ssVal2) && !empty($ssVal2['hint'])) ? ' data-hint="'.htmlspecialchars($ssVal2['hint']).'"' : '';
                        echo '<a href="'.htmlspecialchars($ssUrl).'" class="dropdown-item"'.$ssHint.'>'.$ssLabel2.'</a>';
                    }
                    echo '</div></div>';
                } else {
                    echo '<div class="sb-subsub-toggle" onclick="sbToggleSubSub(this)">'.$ssLabel.' ▶</div>';
                    echo '<div class="sb-subsub">';
                    foreach ($subVal['submenu'] as $ssUrl => $ssVal2) {
                        if (!$isSubAllowed($ssUrl)) continue;
                        $ssLabel2 = is_array($ssVal2) ? ($ssVal2['label'] ?? '') : $ssVal2;
                        echo '<a href="'.htmlspecialchars($ssUrl).'">'.$ssLabel2.'</a>';
                    }
                    echo '</div>';
                }
                continue;
            }

            // Normal sub-item
            $subLabel = is_array($subVal) ? ($subVal['label'] ?? '') : $subVal;
            $subHint  = (is_array($subVal) && !empty($subVal['hint'])) ? ' data-hint="'.htmlspecialchars($subVal['hint']).'"' : '';
            $isActive = (strpos($subUrl, $current_page) !== false);

            if ($mode === 'top') {
                echo '<a href="'.htmlspecialchars($subUrl).'" class="dropdown-item"'.$subHint.'>'.$subLabel.'</a>';
            } else {
                echo '<a href="'.htmlspecialchars($subUrl).'"'.($isActive ? ' class="active"' : '').'>'.$subLabel.'</a>';
            }
        }
    };

    // ═════════════════════════════════════════════════════════════════════════
    // A) SIDEBAR
    // ═════════════════════════════════════════════════════════════════════════
    echo '<div id="tc-sidebar">';

    // Logo
    echo '<div class="sb-logo">';
    echo '<button class="sb-toggle" onclick="toggleMenuLayout()" title="'.lang('@Switch to top navigation').'"><i class="ti ti-layout-navbar"></i></button>';
    echo '<div class="sb-logo-block">';
    echo '<div class="sb-logo-text"><a href="about.php" style="color:white;text-decoration:none;"><img src="favicon.svg" alt="" style="width:28px; height:28px; top: 5px; position: relative;" /><span>Tiny</span>Cash</a></div>';
    if ($company_name !== '')
        echo '<div class="sb-company" style="text-align: center;" title="'.htmlspecialchars($company_name).'">'.htmlspecialchars($company_name).'</div>';
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
            echo '<div class="sb-item'.$openClass.'" onclick="sbToggleSub(this)">';
            echo '<span>'.$icon.'</span><span>'.$label.'</span><span class="sb-arrow">▶</span>';
            echo '</div>';
            echo '<div class="sb-sub'.$openClass.'">';
            $renderSubItems($config['submenu'], 'sidebar');
            echo '</div>';
        } else {
            echo '<a class="sb-item'.($active ? ' active' : '').'" href="'.htmlspecialchars($url).'">';
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
    if (file_exists($lang_file)) {
        $lang_data = json_decode(file_get_contents($lang_file), true);
        if (!empty($lang_data['language'])) {
            foreach ($lang_data['language'] as $l) {
                $optCode   = $fMap[$l['code']] ?? $l['code'];
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
    }
    echo '</div>';
    echo '</div>'; // custom-lang-dropdown

    echo '<a href="logout.php" class="sb-logout">🚪 '.$userName.' — '.lang('@Logout').'</a>';
    echo '</div>'; // sb-utils
    echo '</div>'; // #tc-sidebar

    // ═════════════════════════════════════════════════════════════════════════
    // B) TOP-NAVIGATION
    // ═════════════════════════════════════════════════════════════════════════
    echo '<nav id="top-nav" style="background:var(--bg-nav); padding:1px 5px; margin-bottom:20px;
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
        echo '<div class="nav-main-link" style="'.$activeStyle.'" '.$onClick.$h_attr.'>';
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

    $lang_file = 'json-data/languages.json';
    echo '<div class="custom-lang-dropdown" style="position:relative;">';
    echo '<div onclick="toggleLangMenu(event)" class="lang-button"><span class="fi fi-'.$fCode.'"></span> <b>'.strtoupper($current_l).'</b> ▼</div>';
    echo '<div id="lang-options" class="lang-dropdown-box">';
    if (file_exists($lang_file)) {
        $lang_data = json_decode(file_get_contents($lang_file), true);
        if (!empty($lang_data['language'])) {
            foreach ($lang_data['language'] as $l) {
                $optCode  = $fMap[$l['code']] ?? $l['code'];
                $bgStyle  = ($l['code'] == $current_l) ? 'background:rgba(52,152,219,0.15);' : '';
                echo '<a href="?l='.$l['code'].'" style="display:flex;align-items:center;gap:10px;padding:10px;color:white;text-decoration:none;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px;'.$bgStyle.'">';
                echo '<span class="fi fi-'.$optCode.'" style="width:20px;height:15px;"></span><span>'.$l['native'].'</span></a>';
            }
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

    // Backup-advarsel
    $days_old = 0;
    if ($conn) {
        $b_res = @DB::query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'last_backup_time'");
        if ($b_res && $b_row = DB::fetch_assoc($b_res)) {
            $days_old = (int)floor((time() - (int)$b_row['setting_value']) / 86400);
        } else {
            $days_old = 999;
        }
    }
    if ($days_old > 10) {
        $alert_msg = sprintf(lang('@Warning: Backup is').' %d '.lang('@days old. Remember that you can lose everything you have created since then.'), $days_old);
        echo '<div style="position:absolute;top:100%;left:0;right:0;background:var(--color-warning);color:#fff;text-align:center;padding:6px 20px;font-size:13px;font-weight:bold;box-shadow:0 4px 6px rgba(0,0,0,0.1);z-index:8900;">
            <i class="fa-solid fa-triangle-exclamation" style="margin-right:8px;"></i>'.$alert_msg.'</div>';
    }

    echo '</div></nav>';
}
?>
