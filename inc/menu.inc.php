<?php # inc/menu.inc.php v:1.1.0 d:2026-07-02 i:evs
function showMenu() {
    global $conn;

    // Hent det korte uLev (User Level) – standardiseret til 1 (Begynder) hvis ikke sat
    $uLev = isset($_SESSION['user_level']) ? (int)$_SESSION['user_level'] : 1;

    // 1. Tjek admin status
    $adminExists = true;
    
    // Kør kun hvis der er en active forbindelse
    if ($conn) {
        $res = @DB::query($conn, "SELECT COUNT(*) FROM users WHERE user_role = 'admin'");
        if ($res) {
            $row = DB::fetch_row($res);
            $adminExists = ($row[0] > 0);
        }
    }

    // Isoler udelukkende filnavnet (f.eks. 'invoice_edit.php') for at undgå array-sammenbrud
    $current_page = explode('?', basename($_SERVER['SCRIPT_NAME']))[0];
    // Hent den aktuelle box parameter hvis den findes
    $current_box = isset($_GET['box']) ? $_GET['box'] : '';

    // 2. Menu-struktur
    $menu = [
        'index.php'   => [
            'label'   => '🏠 ' . lang('@Overview'),
            'hint'    => lang('@Dashboard and quick stats')
        ],
        'sales' => [
            'label'   => '📄 ' . lang('@Sales'),
            'hint'    => lang('@Manage invoices and customers'),
            'submenu' => [
                'sales_hub.php'              => '🚀 ' . lang('@Sales Hub Dashboard'),
                'invoice_edit.php?id=0'      => '➕ ' . lang('@Create New Invoice'),
                'mail_inbox.php?box=invoice' => '🗂️ ' . lang('@Invoice Copies Inbox'), // Unikt keyword og parameter
            ]
        ],
        'expenses' => [
            'label'   => '🛒 ' . lang('@Purchases'),
            'hint'    => lang('@Manage expenses, vouchers and supplier invoices'),
            'submenu' => [
                'expense_list.php'           => '📋 ' . lang('@Expense List'),
                'expense_edit.php?id=0'      => '📥 ' . lang('@Register Expense'),
               // 'mail_inbox.php?box=voucher' => '📬 ' . lang('@Voucher Inbox'),        // Unikt keyword og parameter
               // 'mail_inbox.php?box=vendor'  => '📦 ' . lang('@Supplier Invoice Inbox'), // Unikt keyword og parameter
                '---'                        => '---',
                'mail_inbox.php?box=voucher' => '📬 ' . lang('@Voucher Inbox'), // Én samlet indbakke til alt indkommende køb
                ]
        ],
        'inventory'   => [
            'label'   => '📦 ' . lang('@Inventory'),
            'hint'    => lang('@Stock levels and products'),
            'submenu' => [
                'inventory_status.php'       => '📉 ' . lang('@Stock Status'), 
                'product_edit.php?id=0'      => '➕ ' . lang('@Create New Product'),
            ]
        ],
        // Forslag til opdateret struktur under 'Finance'
        /* 'accounting' => [
            'label'   => '💰 ' . lang('@Accounting'), // Ændret fra Finance til Accounting
            'hint'    => lang('@Complete financial management'),
            'submenu' => [
                'ledger_view.php'      => '📖 ' . lang('@General Ledger'),
                '---'                  => '---',
                'report_income.php'    => '📊 ' . lang('@Profit & Loss'),
                'report_balance.php'   => '⚖️ ' . lang('@Balance Sheet'),
                'vat_report.php'       => '🧾 ' . lang('@VAT Report'),
                '---_1'                => '---',
                'bank_import_step1.php'=> '📥 ' . lang('@Bank Reconciliation'),
                'chart_of_accounts.php'=> '📑 ' . lang('@Chart of Accounts')
            ]
        ],
 */        
        'accounting'  => [
            'label'   => '💰 ' . lang('@Accounting'),
            'hint'    => lang('@Ledger, banking, reports and settings'),
            'submenu' => [
                'bank_import_step1.php'      => '📥 ' . lang('@Import Bank File'),
                'reconcile_list.php'         => '⚖️ ' . lang('@Bank Reconciliation'),
                'settings_fees.php'          => '⚙️ ' . lang('@Fee Rules'),
                '---'                        => '---',
                'ledger_view.php'            => '📖 ' . lang('@General Ledger'),
                'vat_report.php'             => '🧾 ' . lang('@VAT Report'),
                '---_1'                      => '---',
                'report_income.php'          => '📊 ' . lang('@Profit & Loss Report'),
                'chart_of_accounts.php'      => '📑 ' . lang('@Chart of Accounts')
            ]
        ],
        'production'  => [
            'label'   => '🛠️ ' . lang('@Production'),
            'hint'    => lang('@Production lines and management'),
            'submenu' => [
                'xx.php'                     => '📉 ' . lang('@No content'), 
            ]
        ],
        'system' => [
            'label'   => '⚙️ ' . lang('@System'),
            'hint'    => lang('@Settings and user management'),
            'submenu' => [
                'company_settings.php'       => '🏢 ' . lang('@Company Settings'), 
                'vat_codes.php'              => '🧾 ' . lang('@VAT Codes & Rates'),
                'user_list.php'              => '🔑 ' . lang('@User Management'),
                'storage_browser.php'        => '📁 ' . lang('@Storage Browser'),
                '---_2'                      => '---',
                'control_panel' => [
                    'label'     => '🛠️ ' . lang('@Control Panel') . ' <span style="float:right; margin-left:10px;">▶</span>',
                    'submenu'   => [
                        'backup.php'         => '📥 ' . lang('@Backup Management'), 
                        'backup_restore.php' => '🔄 ' . lang('@Restore System'),
                        
                        'setup_chart.php'    => ['label' => '📑 ' . lang('@Chart of Accounts Proposal'), 'hint' => lang('@Only Danish layout')],
                        'run_migrate.php'    => ['label' => '📦 ' . lang('@Database migration'), 'hint' => lang('@Update database structure')],
                        
                        'translation_manager.php' => '🌐 ' . lang('@Language Editor'), 
                        'error_log.php'      => '⚠️ ' . lang('@Error Log'),
                    ]
                ],
                '---_3'                      => '---',
                'ai_help.php'                => 'ℹ️ ' . lang('@AI support'),
                'about.php'                  => 'ℹ️ ' . lang('@About TinyCash')
            ]
        ], 
        'logout.php' => [
            'label' => '🚪 ' . lang('@Logout'),
            'hint'  => ''
        ]
    ];

    if (!$adminExists) {
        $newSub = ['user_edit.php?id=0' => '🆕 ' . lang('@Create First Admin')];
        $menu['system']['submenu'] = array_merge($newSub, $menu['system']['submenu']);
    }
      
    // 3. Styling og JavaScript tilpasset centralt tema
    echo '<style>
        .submenu { display:none; position:absolute; top:100%; left:0; background: var(--theme-nav-dropdown, #34495e); 
            min-width:240px; box-shadow:0 8px 16px var(--theme-shadow, rgba(0,0,0,0.4)); z-index:9100; border-radius:4px; 
            border:1px solid var(--theme-border-subtle, #455a64); pointer-events: auto;}
        .has-sub:hover > .sub-submenu { display:block; }
        .sub-submenu { display:none; position:absolute; left:100%; top:0; background: var(--theme-nav-subdropdown, #2c3e50); min-width:220px; border:1px solid var(--theme-border-subtle, #455a64); border-radius:4px; box-shadow:8px 0 16px var(--theme-shadow, rgba(0,0,0,0.4)); }
        .dropdown-item { display:block; padding:12px 15px; color:white; text-decoration:none; font-size:14px; cursor: pointer; }
        .dropdown-item:hover { background: var(--theme-bg-hover, rgba(255,255,255,0.1)); }
        .nav-main-link { display:flex; flex-direction:column; align-items:center; text-decoration:none; color:white; padding:8px 12px; min-width:90px; cursor:pointer; }
        .lang-button { background:rgba(0,0,0,0.2); padding:8px 12px; border-radius:20px; border:1px solid rgba(255,255,255,0.1); cursor:pointer; font-size:13px; display:flex; align-items:center; gap:8px; }
        .lang-dropdown-box { display:none; position:absolute; top:110%; right:0; background: var(--theme-nav-dropdown, #34495e); min-width:160px; box-shadow:0 8px 16px var(--theme-shadow, rgba(0,0,0,0.4)); z-index:9500; border-radius:4px; border:1px solid var(--theme-border-subtle, #455a64); }
        
        /* Skjul advarsel som standard, tving den frem KUN når data-theme="dark" er aktivt på html-niveau */
        .dark-theme-warning { display: none !important; }
        [data-theme="dark"] .dark-theme-warning { display: inline-block !important; }
    </style>';

echo '<script>
    function adjustZoom(d) { document.body.style.zoom = (parseFloat(document.body.style.zoom || 1) + d); }
    function toggleSub(el) {
        var sub = el.nextElementSibling;
        document.querySelectorAll(".submenu").forEach(s => { if(s !== sub) s.style.display = "none"; });
        if(sub) sub.style.display = (sub.style.display === "block") ? "none" : "block";
    }
    function toggleTestLevel(currentLevel) {
        var nextLevel = currentLevel + 1;
        if (nextLevel > 3) { nextLevel = 1; }
        var currentUrl = window.location.href.split("?")[0];
        window.location.href = currentUrl + "?set_level=" + nextLevel;
    }
    function toggleLangMenu(e) {
        e.stopPropagation();
        var m = document.getElementById("lang-options");
        document.querySelectorAll(".submenu").forEach(s => s.style.display = "none");
        m.style.display = (m.style.display === "block") ? "none" : "block";
    }
    
    function toggleTheme() {
        var themes = ["light", "custom", "dark"];
        var current = document.documentElement.getAttribute("data-theme") || "light";
        if (!themes.includes(current)) current = "light";
        
        var nextIndex = (themes.indexOf(current) + 1) % themes.length;
        var nextTheme = themes[nextIndex];
        
        document.documentElement.setAttribute("data-theme", nextTheme);
        
        var d = new Date();
        d.setTime(d.getTime() + (365 * 24 * 60 * 60 * 1000));
        document.cookie = "theme=" + nextTheme + ";expires=" + d.toUTCString() + ";path=/;SameSite=Strict";
        
        var icons = {"light": "☀️", "custom": "✨", "dark": "🌙"};
        var btn = document.getElementById("theme-toggle-btn");
        if (btn) {
            btn.innerText = icons[nextTheme];
            
            // Dynamisk skift af data-hint ved klik (Undgår misvisende tooltips før genindlæsning)
            if (nextTheme === "dark") {
                btn.setAttribute("data-hint", "Mørkt tema kan have mangelfulde kontraster visse steder. Tip: Brug Ctrl+A for at markere/synliggøre skjult tekst.");
            } else {
                btn.setAttribute("data-hint", "Skift farvetema (Light ➔ Custom ➔ Dark)");
            }
        }

        fetch(window.location.href.split("?")[0] + "?set_theme_session=" + nextTheme + "&t=" + Date.now(), {
            method: "GET",
            headers: { "X-Requested-With": "XMLHttpRequest" }
        }).catch(err => console.log("Session sync failed"));
    }
    
    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => {});
        } else {
            document.exitFullscreen();
        }
    }
    window.onclick = function(e) {
        if (!e.target.closest(".dropdown") && !e.target.closest(".custom-lang-dropdown")) {
            document.querySelectorAll(".submenu, #lang-options").forEach(m => m.style.display = "none");
        }
    }
    </script>';

    // 4. HTML Navigation
    echo '<nav id="top-nav" style="background: var(--theme-nav-bg, #2c3e50); padding:1px 20px; margin-bottom:20px; display:flex; align-items:center; min-height:65px; font-family:sans-serif; color:white; position:relative; z-index:9000; overflow:visible !important;">';
    echo '<div style="margin-right:25px; font-size:1.7em; font-weight:bold; flex-shrink:0;">';
    echo '<a href="about.php" style="color:white; text-decoration:none;"><span style="color: var(--theme-primary, #3498db);">Tiny</span>Cash</a>';
    echo '<div style="font-size:10px; font-weight:200; color: var(--theme-secondary, #aeff00); margin-top:2px;">Develop version w. errors</div>'; 
    echo '</div>';
    
    foreach($menu as $url => $config) {
        if ($url == 'logout.php') continue;

        if ($uLev < 2 && ($url == 'expenses' || $url == 'accounting' || $url == 'system')) continue;
        if ($uLev < 3 && $url == 'production') continue;

        $isDropdown = (isset($config['submenu']));

        if (is_array($config) && !isset($config['label'])) {
            $fullText = $config['label'] ?? 'Menu';
        } else {
            $fullText = is_array($config) ? $config['label'] : $config;
        }

        if (is_array($fullText)) {
            $fullText = $fullText['label'] ?? 'Menu';
        }

        $hintText = (is_array($config) && isset($config['hint'])) ? $config['hint'] : '';
       
        $parts = explode(' ', $fullText, 2);
        $icon = $parts[0] ?? '';
        $text = $parts[1] ?? $fullText;

        // --- FORBEDRET AKTIV-STATUS LOGIK ---
        $isActive = ($current_page == $url);
        if (!$isActive && $isDropdown && isset($config['submenu'])) {
            foreach (array_keys($config['submenu']) as $subKey) {
                if (strpos((string)$subKey, $current_page) !== false) {
                    if ($current_page === 'mail_inbox.php') {
                        $target_box = (strpos((string)$subKey, 'box=invoice') !== false) ? 'invoice' : 'voucher';
                        $actual_box = ($current_box === 'invoice') ? 'invoice' : 'voucher';
                        
                        if ($target_box === $actual_box) {
                            $isActive = true;
                            break;
                        }
                    } else {
                        $isActive = true;
                        break;
                    }
                }
            }
        }       
        $activeStyle = $isActive ? 'border-bottom: 3px solid var(--theme-primary, #3498db);' : '';
        $onClick = $isDropdown ? 'onclick="toggleSub(this)"' : 'onclick="window.location.href=\''.$url.'\'"';
        $h_attr = ($hintText > '') ? ' data-hint="'.htmlspecialchars($hintText).'"' : '';

        echo '<div class="dropdown" style="position:relative; cursor:pointer;">';
        echo '<div class="nav-main-link" style="'.$activeStyle.'" '.$onClick.' '.$h_attr.'>';
        echo '<span style="font-size:1.5em; pointer-events:none;">' . $icon . '</span>';
        echo '<span style="font-size:0.85em; white-space:nowrap; pointer-events:none;">' . $text . ($isDropdown ? ' ▼' : '') . '</span></div>';
        
        if ($isDropdown) {
            echo '<div class="submenu">';
            foreach($config['submenu'] as $subUrl => $subVal) {

                if ($uLev < 3) {
                    if ($subUrl == 'settings_fees.php' || $subUrl == 'chart_of_accounts.php' || $subUrl == 'user_list.php' || $subUrl == 'storage_browser.php' || $subUrl == 'control_panel') {
                        continue; 
                    }
                }

                if (is_array($subVal) && isset($subVal['submenu'])) {
                    echo '<div class="has-sub" style="position:relative;">';
                    echo '<a href="#" class="dropdown-item" style="background:rgba(0,0,0,0.1);">' . ($subVal['label'] ?? '') . '</a>';
                    echo '<div class="sub-submenu">';
                    foreach($subVal['submenu'] as $ssUrl => $ssVal2) {
                        $ssLabel = is_array($ssVal2) ? ($ssVal2['label'] ?? '') : $ssVal2;
                        $ssHint = (is_array($ssVal2) && !empty($ssVal2['hint'])) ? $ssVal2['hint'] : '';
                        $ssHAttr = ($ssHint !== '') ? ' data-hint="'.htmlspecialchars($ssHint).'"' : '';
                        echo '<a href="'.$ssUrl.'" class="dropdown-item"'.$ssHAttr.'>' . $ssLabel . '</a>';
                    }
                    echo '</div></div>';
                } elseif (strpos($subUrl, '---') !== false) {
                    echo '<hr style="border:0; border-top:1px solid rgba(255,255,255,0.2); margin:5px 0;">';
                } else {
                    $subLabel = is_array($subVal) ? ($subVal['label'] ?? '') : $subVal;
                    $subHint = (is_array($subVal) && !empty($subVal['hint'])) ? $subVal['hint'] : '';
                    $subHAttr = ($subHint !== '') ? ' data-hint="'.htmlspecialchars($subHint).'"' : '';
                    echo '<a href="'.$subUrl.'" class="dropdown-item"'.$subHAttr.'>' . $subLabel . '</a>';
                }
            }
            echo '</div>';
        }
        echo '</div>';
    }

// 5. Højre side (Definer variabler først)
    $currentTheme = $_COOKIE['theme'] ?? 'light';
    $themeIcons = ['light' => '☀️', 'custom' => '✨', 'dark' => '🌙'];
    $currentIcon = $themeIcons[$currentTheme] ?? '☀️';
    
    $fsHint = lang('@Toggle full screen. Use F11 (Browser-controlled) to avoid resetting on page change');
    $thHint = ($currentTheme === 'dark') 
        ? lang('@Dark theme: Use Ctrl+A if there is no contrast.')
        : lang('@Change color theme (Light ➔ Custom ➔ Dark)');

    echo '<div style="margin-left:auto; display:flex; align-items:center; gap:10px;">';

    // VIEW KONTROLLER (Stabel)
    echo '<div style="display:flex; flex-direction:column; align-items:center;">';
        // Knap-række (Øverst)
        echo '<div style="display:flex; background:rgba(0,0,0,0.2); padding:2px 2px; border-radius:6px; border:1px solid rgba(255,255,255,0.1); align-items:center; gap:8px;">';
            // Zoom (Horisontalt for at spare plads)
            echo '<button onclick="adjustZoom(0.02)" style="background:none; border:none; color:white; cursor:pointer; font-size:14px; padding:0;">+</button>';
            echo '<button onclick="adjustZoom(-0.02)" style="background:none; border:none; color:white; cursor:pointer; font-size:14px; padding:0;">-</button>';
            // Fuldskærm
            echo '<button onclick="toggleFullscreen()" data-hint="'.htmlspecialchars($fsHint).'" style="background:none; border:none; color:white; cursor:pointer; font-size:16px; border-left:1px solid rgba(255,255,255,0.2); padding-left:8px;">⛶</button>';
            // Tema-toggle
            echo '<button id="theme-toggle-btn" onclick="toggleTheme()" data-hint="'.htmlspecialchars($thHint).'" style="background:none; border:none; color:white; cursor:pointer; font-size:14px; border-left:1px solid rgba(255,255,255,0.2); padding-left:2px;">' . $currentIcon . '</button>';
        echo '</div>';
        // Tekst (Nederst)
        echo '<div style="font-size:10px; color:white; text-transform:uppercase; opacity:0.8; margin-top:2px;">'.lang('@VIEW').'</div>';
    echo '</div>';
    
    // Fjernede teksten "VIEW" for at spare de sidste 30-40px i bredden.
 /*    // 5. Højre side
    echo '<div style="margin-left:auto; display:flex; align-items:center; gap:15px;">';

    // Bestem indledende hints baseret på gemt cookie
    $currentTheme = $_COOKIE['theme'] ?? 'light';
    $themeIcons = ['light' => '☀️', 'custom' => '✨', 'dark' => '🌙'];
    $currentIcon = $themeIcons[$currentTheme] ?? '☀️';
    
    $fsHint = lang('@Toggle full screen (resets on page change). <br>Tip: Use F11 on the keyboard for permanent browser-controlled full screen.');
    
    if ($currentTheme === 'dark') {
        $thHint = "Mørkt tema kan have mangelfulde kontraster visse steder. Tip: Brug Ctrl+A for at markere/synliggøre skjult tekst.";
    } else {
        $thHint = "Skift farvetema (Light ➔ Custom ➔ Dark)";
    }
/* 
    // DYNAMISK WARNING-BOKS: Vises kun i mørkt tema via CSS-klassen herover
    echo '<span class="dark-theme-warning" style="font-size:11px; color:#f39c12; background:rgba(0,0,0,0.4); padding:6px 10px; border-radius:4px; border:1px solid rgba(243,156,18,0.3); max-width:210px; line-height:1.3; flex-shrink:0;">
            ⚠️ <b>Kontrast-info:</b><br>Brug <b>Ctrl+A</b> for at synliggøre tekst.
          </span>';
 * /
    echo '<div style="display:flex; flex-direction:column; align-items:center; gap:4px;">';
    echo '<div style="display:flex; background:rgba(0,0,0,0.2); padding:3px 12px; border-radius:20px; 
            gap:10px; border:1px solid rgba(255,255,255,0.1); align-items:center;">';
        
        // Zoom ud
        echo '<button onclick="adjustZoom(-0.02)" style="background:none; border:none; color:white; 
                cursor:pointer; font-size:18px;">-</button>';
        
        // Fuldskærm
        echo '<button onclick="toggleFullscreen()" data-hint="'.htmlspecialchars($fsHint).'" style="background:none; border:none; color:white; 
                cursor:pointer; font-size:16px; border-left:1px solid rgba(255,255,255,0.2); 
                padding:0 0 0 10px;">⛶</button>';
        
        // Tema-toggle
        echo '<button id="theme-toggle-btn" onclick="toggleTheme()" data-hint="'.htmlspecialchars($thHint).'" style="background:none; border:none; color:white; 
                cursor:pointer; font-size:14px; border-left:1px solid rgba(255,255,255,0.2); 
                border-right:1px solid rgba(255,255,255,0.2); padding:0 10px;">' . $currentIcon . '</button>';
        
        // Zoom ind
        echo '<button onclick="adjustZoom(0.02)" style="background:none; border:none; color:white; 
                cursor:pointer; font-size:18px;">+</button>';
                    
    echo '</div>';
    echo '<div style="font-size:11px; color:white; text-transform:uppercase; opacity:0.9;">'.lang('@VIEW').'</div>';
    echo '</div>';
 */
    // SPROGVÆLGER
    $current_l = $_SESSION['lang'] ?? 'da';
    $fMap = ['da' => 'dk', 'en' => 'gb', 'kl' => 'gl', 'se' => 'se', 'nb' => 'no', 'nn' => 'no', 'pt' => 'pt', 'es' => 'es'];
    $fCode = $fMap[$current_l] ?? $current_l;
    $lang_file = 'json-data/languages.json'; 
    echo '<div class="custom-lang-dropdown" style="position:relative;">';
        echo '<div onclick="toggleLangMenu(event)" class="lang-button"><span class="fi fi-'.$fCode.'">
                </span> <b>'.strtoupper($current_l).'</b> ▼</div>';
        echo '<div id="lang-options" class="lang-dropdown-box">';

        if (file_exists($lang_file)) {
            $lang_data = json_decode(file_get_contents($lang_file), true);
            if (!empty($lang_data['language'])) {
                foreach ($lang_data['language'] as $l) {
                    $optCode = $fMap[$l['code']] ?? $l['code'];
                    $isCurrent = ($l['code'] == $current_l);
                    $bgStyle = $isCurrent ? 'background:rgba(52,152,219,0.15);' : '';
                    echo '<a href="?l=' . $l['code'] . '"style="display:flex; 
                          align-items:center; gap:10px; padding:10px; color:white; text-decoration:none; 
                          border-bottom:1px solid rgba(255,255,255,0.05); font-size:13px; '.$bgStyle.'">';
                    echo '<span class="fi fi-'.$optCode.'" style="width:20px; height:15px;"></span>';
                    echo '<span>'.$l['native'].'</span>';
                    echo '</a>';
                }
            }
        }
        echo '</div>';
        echo '<div style="font-size:11px; color:white; text-align: center; text-transform:uppercase; opacity:0.9;">'.lang('@LANGUAGE').'</div>';
    echo '</div>';

    // LOGOUT-KNAP
    $levNames = [1 => lang('@Beginner'), 2 => lang('@Experienced'), 3 => lang('@Developer')];
    $currentName = isset($levNames[$uLev]) ? $levNames[$uLev] : lang('@Unknown');
    $titleText = $currentName . ' - ' . lang('@Click to change');
    $userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');

    echo '<a href="logout.php" style="background: var(--theme-danger, #e74c3c); color:white; padding:5px 5px; border-radius:4px; 
          text-decoration:none; font-size:14px; text-align: center; min-width: 110px; display:inline-block;">';
        echo '<div style="display:flex; align-items:center; justify-content:center; gap:6px; margin-bottom:2px;">';
            echo '<small style="opacity:0.9;">"' . $userName . '"</small>';
            echo '<span onclick="event.preventDefault(); event.stopPropagation(); toggleTestLevel(' . (int)$uLev . ');" 
                        data-hint="' . htmlspecialchars($titleText) . '" 
                        style="font-size:11px; color:#fff; background:rgba(0,0,0,0.4); padding:1px 4px; 
                               border-radius:2px; cursor:pointer; font-family:monospace; font-weight:bold; 
                               border:1px solid rgba(255,255,255,0.8); line-height:1;">';
            echo 'L' . (int)$uLev;
            echo '</span>';
        echo '</div>';
        echo '<b>👤 ' . lang('@Logout') . '</b>';
    echo '</a>';
    
    echo '</div>';

    // AUTOMATISK BACKUP-KONTROL
    $days_old = 0;
    if ($conn) {
        $b_res = @DB::query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'last_backup_time'");
        if ($b_res && $b_row = DB::fetch_assoc($b_res)) {
            $last_backup_timestamp = (int)$b_row['setting_value'];
            $days_old = (int)floor((time() - $last_backup_timestamp) / (24 * 60 * 60));
        } else {
            $days_old = 999;
        }
    }

    if ($days_old > 7) {
        $alert_msg = sprintf(lang('@Warning: Backup is').' %d '.lang('@days old. Remember that you can lose everything you have created since then.'), $days_old);
        echo '<div style="position: absolute; top: 100%; left: 0; right: 0; background: var(--theme-warning, #f39c12); color: #fff; text-align: center; padding: 6px 20px; font-size: 13px; font-weight: bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 8900;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:8px;"></i>' . $alert_msg . '</div>';
    }
    
    echo '</div></nav>';
}
?>