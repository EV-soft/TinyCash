<?php # inc/menu.inc.php v:1.2.0 d:2026-08-11 i:evs
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

    $company_name    = '';
    $module_projects = false;
    if ($conn) {
        $company_settings = get_settings($conn);
        $company_name     = trim($company_settings['company_name'] ?? '');
        $module_projects  = !empty($company_settings['module_projects']) && $company_settings['module_projects'] == '1';
    }

    $current_page = explode('?', basename($_SERVER['SCRIPT_NAME']))[0];
    $current_box  = isset($_GET['box']) ? $_GET['box'] : '';

    // ── Hjælpefunktion: er dette menu-punkt aktivt? ──────────────────────────
    $isActiveItem = function($url, $config) use ($current_page, $current_box) {
        if ($current_page === $url) return true;
        if (!isset($config['submenu'])) return false;
        foreach (array_keys($config['submenu']) as $subKey) {
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

    // ── Hjælpefunktion: må dette punkt vises for $uLev? ──────────────────────
    $isAllowed = function($url) use ($uLev) {
        if ($uLev < 2 && ($url === 'accounting' || $url === 'system')) return false;
        if ($uLev < 3 && $url === 'production') return false;
        return true;
    };

    $isSubAllowed = function($subUrl) use ($uLev) {
        if ($uLev < 3 && in_array($subUrl, ['settings_fees.php','chart_of_accounts.php','user_list.php','storage_browser.php','control_panel'])) return false;
        return true;
    };

    // ── Menu-array ────────────────────────────────────────────────────────────
    $menu = [
        'index.php'  => ['label' => '🏠 ' . lang('@Overview'),   'hint' => lang('@Dashboard and quick stats')],
        'sales'      => ['label' => '📄 ' . lang('@Sales'),      'hint' => lang('@Manage invoices and customers'),
            'submenu' => [
                'sales_hub.php'              => '🚀 ' . lang('@Sales Hub Dashboard'),
                'invoice_edit.php?id=0'      => '➕ ' . lang('@Create New Invoice'),
                'mail_inbox.php?box=invoice' => '🗂️ ' . lang('@Invoice Copies Inbox'),
            ]],
        'expenses'   => ['label' => '🛒 ' . lang('@Purchases'),   'hint' => lang('@Manage expenses, vouchers and supplier invoices'),
            'submenu' => [
                'expense_list.php'           => '📋 ' . lang('@Expense List'),
                'expense_edit.php?id=0'      => '📥 ' . lang('@Register Expense'),
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
                'bank_import_step1.php'      => '📥 ' . lang('@Import Bank File'),
                'reconcile_list.php'         => '⚖️ ' . lang('@Bank Reconciliation'),
                'settings_fees.php'          => '⚙️ ' . lang('@Fee Rules'),
                '---'                        => '---',
                'ledger_view.php'            => '📖 ' . lang('@General Ledger'),
                'vat_report.php'             => '🧾 ' . lang('@VAT Report'),
                '---_1'                      => '---',
                'report_income.php'          => '📊 ' . lang('@Profit & Loss Report'),
                'chart_of_accounts.php'      => '📑 ' . lang('@Chart of Accounts'),
            ]],
        /* 'production' => ['label' => '🛠️ ' . lang('@Production'),  'hint' => lang('@Production lines and management'),
            'submenu' => [
                'xx.php'                     => '📉 ' . lang('@No content'),
            ]], */ 
        'system'     => ['label' => '⚙️ ' . lang('@System'),      'hint' => lang('@Settings and user management'),
            'submenu' => [
                'company_settings.php'       => '🏢 ' . lang('@Settings'),
                'vat_codes.php'              => '🧾 ' . lang('@VAT Codes & Rates'),
                'user_list.php'              => '🔑 ' . lang('@User Management'),
                'storage_browser.php'        => '📁 ' . lang('@Storage Browser'),
                '---_2'                      => '---',
                'control_panel'              => ['label' => '🛠️ ' . lang('@Control Panel') . ' <span style="float:right">▶</span>',
                    'submenu' => [
                        'backup.php'              => '📥 ' . lang('@Backup Management'),
                        'backup_restore.php'      => '🔄 ' . lang('@Restore System'),
                        'setup_chart.php'         => ['label' => '📑 ' . lang('@Chart of Accounts Proposal'), 'hint' => lang('@Only Danish layout')],
                        'run_migrate.php'         => ['label' => '📦 ' . lang('@Database migration'),         'hint' => lang('@Update database structure')],
                        'translation_manager.php' => '🌐 ' . lang('@Language Editor'),
                        'error_log.php'           => '⚠️ ' . lang('@Error Log'),
                    ]],
                '---_3'                      => '---',
                'ai_help.php'                => 'ℹ️ ' . lang('@AI support'),
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
                    ]];
            }
        }
        $menu = $new_menu;
    }

    if (!$adminExists) {
        $menu['system']['submenu'] = array_merge(
            ['user_edit.php?id=0' => '🆕 ' . lang('@Create First Admin')],
            $menu['system']['submenu']
        );
    }

    // ── Fælles kontekst til utility-widgets ──────────────────────────────────
    $currentTheme = $_COOKIE['theme'] ?? 'light';
    $themeIcons   = ['light' => '☀️', 'custom' => '✨', 'dark' => '🌙'];
    $currentIcon  = $themeIcons[$currentTheme] ?? '☀️';
    $current_l    = $_SESSION['lang'] ?? 'da';
    $fMap         = ['da'=>'dk','en'=>'gb','kl'=>'gl','se'=>'se','nb'=>'no','nn'=>'no','pt'=>'pt','es'=>'es'];
    $fCode        = $fMap[$current_l] ?? $current_l;
    $uLevI        = (int)$uLev;
    $userName     = htmlspecialchars($_SESSION['user_name'] ?? 'User');
    $levNames     = [1 => lang('@Beginner'), 2 => lang('@Experienced'), 3 => lang('@Developer')];
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

    #tc-sidebar { display:none; position:fixed; top:0; left:0; width:210px; height:100vh;
        background:var(--bg-nav); z-index:9200; overflow-y:auto; overflow-x:hidden;
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

            // Sub-sub (Control Panel)
            if (is_array($subVal) && isset($subVal['submenu'])) {
                $ssLabel = strip_tags($subVal['label'] ?? '');
                if ($mode === 'top') {
                    echo '<div class="has-sub" style="position:relative;">';
                    echo '<a href="#" class="dropdown-item" style="background:rgba(0,0,0,0.1);">'.($subVal['label'] ?? '').'</a>';
                    echo '<div class="sub-submenu">';
                    foreach ($subVal['submenu'] as $ssUrl => $ssVal2) {
                        $ssLabel2 = is_array($ssVal2) ? ($ssVal2['label'] ?? '') : $ssVal2;
                        $ssHint   = (is_array($ssVal2) && !empty($ssVal2['hint'])) ? ' data-hint="'.htmlspecialchars($ssVal2['hint']).'"' : '';
                        echo '<a href="'.htmlspecialchars($ssUrl).'" class="dropdown-item"'.$ssHint.'>'.$ssLabel2.'</a>';
                    }
                    echo '</div></div>';
                } else {
                    echo '<div class="sb-subsub-toggle" onclick="sbToggleSubSub(this)">'.$ssLabel.' ▶</div>';
                    echo '<div class="sb-subsub">';
                    foreach ($subVal['submenu'] as $ssUrl => $ssVal2) {
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
    echo '<div class="sb-logo-text"><a href="about.php" style="color:white;text-decoration:none;"><span>Tiny</span>Cash</a></div>';
    if ($company_name !== '')
        echo '<div class="sb-company" title="'.htmlspecialchars($company_name).'">'.htmlspecialchars($company_name).'</div>';
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
    echo '<nav id="top-nav" style="background:var(--bg-nav); padding:1px 20px; margin-bottom:20px;
        display:flex; align-items:center; min-height:65px; font-family:sans-serif; color:white;
        position:relative; z-index:9000; overflow:visible !important;">';

    // Toggle-knap
    echo '<button id="menu-layout-toggle" onclick="toggleMenuLayout()"
        title="'.lang('@Switch to sidebar menu').'" data-hint="'.lang('@Switch menu between horizontal top bar and vertical side menu').'">
        <i class="ti ti-layout-sidebar"></i></button>';

    // Logo
    echo '<div style="margin-right:25px; font-size:1.7em; font-weight:bold; flex-shrink:0;">';
    echo '<a href="about.php" style="color:white;text-decoration:none;"><span style="color:var(--color-primary);">Tiny</span>Cash</a>';
    if ($company_name !== '')
        echo '<div style="font-size:10px; font-weight:600; color:var(--text-light); opacity:0.85; margin-top:2px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px;"
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
    echo '<div style="display:flex; background:rgba(0,0,0,0.2); padding:2px; border-radius:6px; border:1px solid rgba(255,255,255,0.1); align-items:center; gap:8px;">';
    echo '<button onclick="adjustZoom(0.02)"  style="background:none;border:none;color:white;cursor:pointer;font-size:14px;padding:0;">+</button>';
    echo '<button onclick="adjustZoom(-0.02)" style="background:none;border:none;color:white;cursor:pointer;font-size:14px;padding:0;">-</button>';
    echo '<button onclick="toggleFullscreen()" data-hint="'.htmlspecialchars($fsHint).'" style="background:none;border:none;color:white;cursor:pointer;font-size:16px;border-left:1px solid rgba(255,255,255,0.2);padding-left:8px;">⛶</button>';
    echo '<button id="theme-toggle-btn" onclick="toggleTheme()" data-hint="'.htmlspecialchars($thHint).'" style="background:none;border:none;color:white;cursor:pointer;font-size:14px;border-left:1px solid rgba(255,255,255,0.2);padding-left:2px;">'.$currentIcon.'</button>';
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

    $titleText = $currentName . ' - ' . lang('@Click to change');
    echo '<a href="logout.php" style="background:var(--color-danger);color:white;padding:5px;border-radius:4px;text-decoration:none;font-size:14px;text-align:center;min-width:110px;display:inline-block;"
          data-hint="'.lang('@Here you will see your username and a button where you can <br>change user level: L1-L2-L3.<br>which controls the display of menu items.').'">
        <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:2px;">
            <small style="opacity:0.9;">"'.$userName.'"</small>
            <span onclick="event.preventDefault();event.stopPropagation();toggleTestLevel('.$uLevI.');"
                  data-hint="'.htmlspecialchars($titleText).'"
                  style="font-size:11px;color:#fff;background:rgba(0,0,0,0.4);padding:1px 4px;border-radius:2px;cursor:pointer;font-family:monospace;font-weight:bold;border:1px solid rgba(255,255,255,0.8);line-height:1;">L'.$uLevI.'</span>
        </div>
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
