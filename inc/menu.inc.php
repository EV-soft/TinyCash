<?php # inc/menu.inc.php v:1.0.6 d:2026-06-14 i:gemini ok
function showMenu() {
    global $conn;

    // Hent det korte uLev (User Level) – standardiseret til 1 (Begynder) hvis ikke sat
    $uLev = $_SESSION['user_level'] ?? 1;

    // 1. Tjek admin status
    $adminExists = false;
    $res = mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE user_role = 'admin'");
    if ($res) {
        $row = mysqli_fetch_row($res);
        $adminExists = ($row[0] > 0);
    }
    $current_page = basename($_SERVER['PHP_SELF']);

    // 2. Menu-struktur (Helt ren og statisk)
    $menu = [
        'index.php' => [
            'label' => '🏠 ' . lang('@Overview'),
            'hint'  => lang('@Dashboard and quick stats')
        ],
        'sales' => [
            'label'   => '📄 ' . lang('@Sales & Customers'),
            'hint'    => lang('@Manage invoices and customers'),
            'submenu' => [
                 'sales_hub.php'          => ['label' => '🚀 ' . lang('@Sales Hub Dashboard'), 'hint' => lang('@Advanced sales overview') ],
                 'invoice_edit.php?id=0'  => ['label' => '➕ ' . lang('@Create New Invoice'),  'hint' => '' ],
                 'customer_edit.php?id=0' => ['label' => '✨ ' . lang('@Create New Customer') ]
             ]
        ],
        'bank' => [
            'label'   => '🏦 ' . lang('@Bank'),
            'hint'    => lang('@Import and reconcile bank statements'),
            'submenu' => [
                'bank_import_step1.php'      => '📥 '. lang('@Import Bank File'),
                'reconcile_list.php'         => '⚖️ ' . lang('@Bank Reconciliation'),
                '---'                        => '---',
                'settings_fees.php'          => '⚙️ ' . lang('@Fee Rules')
            ]
        ],
        'accounting' => [
            'label'   => '💰 ' . lang('@Finance'),
            'hint'    => lang('@Ledger, reports and expenses'),
            'submenu' => [
                'expense_list.php'           => '📋 ' . lang('@Expense List'),
                'expense_edit.php?id=0'      => '📥 ' . lang('@Register Expense'), 
                'ledger_view.php'            => '📖 ' . lang('@General Ledger'),
                'vat_report.php'             => '🧾 ' . lang('@VAT Report'),
                '---'                        => '---',
                'report_income.php'          => '📊 ' . lang('@Profit & Loss Report'),
                'chart_of_accounts.php'      => '📑 ' . lang('@Chart of Accounts')
            ]
        ],
        'inventory' => [
            'label'   => '📦 ' . lang('@Inventory'),
            'hint'    => lang('@Stock levels and products'),
            'submenu' => [
                'inventory_status.php'       => '📉 ' . lang('@Stock Status'), 
                'product_edit.php?id=0'      => '➕ ' . lang('@Create New Product'),
            ]
        ],
        'production' => [
            'label'   => '🛠️ ' . lang('@Production'),
            'hint'    => lang('@This could be a future menu'),
            'submenu' => [
                               'xx.php'       => '📉 ' . lang('@No content'), 
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
                '---_1'                      => '---',
                'control_panel' => [
                    'label'     => '🛠️ ' . lang('@Control Panel') . ' <span style="float:right; margin-left:10px;">▶</span>',
                    'submenu'   => [
                        'backup.php'         => '📥 ' . lang('@Backup Management'), 
                        'backup_restore.php' => '🔄 ' . lang('@Restore System'),
                        'setup_chart.php'    => ['label' => '📑 ' . lang('@Kontoplan forslag'), 'hint' => lang('@Only Danish')],
                        'error_log.php'      => '⚠️ '  . lang('@Error Log'),
                    ]
                ],
                '---_2'                      => '---',
                'ai_help.php'                => 'ℹ️ ' . lang('@AI support'),
                'about.php'                  => 'ℹ️ ' . lang('@About TinyCash')
            ]
        ], 
        'logout.php' => '🚪 ' . lang('@Logout')
    ];

    if (!$adminExists) {
        $newSub = ['user_edit.php?id=0' => '🆕 ' . lang('@Create First Admin')];
        $menu['system']['submenu'] = array_merge($newSub, $menu['system']['submenu']);
    }

    // 3. Styling og JavaScript
    echo '<style>
        .submenu { display:none; position:absolute; top:100%; left:0; background:#34495e; 
            min-width:240px; box-shadow:0 8px 16px rgba(0,0,0,0.4); z-index:9100; border-radius:4px; 
            border:1px solid #455a64; pointer-events: auto;}
        .has-sub:hover > .sub-submenu { display:block; }
        .sub-submenu { display:none; position:absolute; left:100%; top:0; background:#2c3e50; min-width:220px; border:1px solid #455a64; border-radius:4px; box-shadow:8px 0 16px rgba(0,0,0,0.4); }
        .dropdown-item { display:block; padding:12px 15px; color:white; text-decoration:none; font-size:14px; cursor: pointer; }
        .dropdown-item:hover { background: rgba(255,255,255,0.1); }
        .nav-main-link { display:flex; flex-direction:column; align-items:center; text-decoration:none; color:white; padding:8px 12px; min-width:90px; cursor:pointer; }
        .lang-button { background:rgba(0,0,0,0.2); padding:8px 12px; border-radius:20px; border:1px solid rgba(255,255,255,0.1); cursor:pointer; font-size:13px; display:flex; align-items:center; gap:8px; }
        .lang-dropdown-box { display:none; position:absolute; top:110%; right:0; background:#34495e; min-width:160px; box-shadow:0 8px 16px rgba(0,0,0,0.4); z-index:9500; border-radius:4px; border:1px solid #455a64; }
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
        var currentUrl = window.location.href.split(\'?\')[0];
        window.location.href = currentUrl + \'?set_level=\' + nextLevel;
    }
    function toggleLangMenu(e) {
        e.stopPropagation();
        var m = document.getElementById("lang-options");
        document.querySelectorAll(".submenu").forEach(s => s.style.display = "none");
        m.style.display = (m.style.display === "block") ? "none" : "block";
    }
    window.onclick = function(e) {
        if (!e.target.closest(".dropdown") && !e.target.closest(".custom-lang-dropdown")) {
            document.querySelectorAll(".submenu, #lang-options").forEach(m => m.style.display = "none");
        }
    }
    </script>';

    // 4. HTML Navigation
    echo '<nav id="top-nav" style="background:#2c3e50; padding:1px 20px; margin-bottom:20px; display:flex; align-items:center; min-height:65px; font-family:sans-serif; color:white; position:relative; z-index:9000; overflow:visible !important;">';
    echo '<div style="margin-right:25px; font-size:2em; font-weight:bold; flex-shrink:0;">';
    echo '<a href="about.php" style="color:white; text-decoration:none;"><span style="color:#3498db;">Tiny</span>Cash</a>';
    echo '<div style="font-size:12px; font-weight:200; color: #aeff00; margin-top:2px;">Develop version w. errors</div>'; 
    echo '</div>';
    
    foreach($menu as $url => $config) {
        if ($url == 'logout.php') continue;

        // FILTER FOR HOVEDMENUPUNKTER (NIVEAU 2 OG NIVEAU 3)
        if ($uLev < 2 && ($url == 'bank' || $url == 'accounting' || $url == 'system')) {
            continue;
        }
        if ($uLev < 3 && $url == 'production') {
            continue;
        }

        $isDropdown = (isset($config['submenu']));
        $fullText   = is_array($config) ? $config['label'] : $config;
        $hintText   = (is_array($config) && isset($config['hint'])) ? $config['hint'] : '';
        
        $parts = explode(' ', $fullText, 2);
        $icon = $parts[0] ?? '';
        $text = $parts[1] ?? $fullText;
        $isActive = ($current_page == $url || ($isDropdown && array_key_exists($current_page, $config['submenu'])));
        $activeStyle = $isActive ? 'border-bottom: 3px solid #3498db;' : '';
        $onClick = $isDropdown ? 'onclick="toggleSub(this)"' : 'onclick="window.location.href=\''.$url.'\'"';
        $h_attr = ($hintText > '') ? ' data-hint="'.htmlspecialchars($hintText).'"' : '';

        echo '<div class="dropdown" style="position:relative; cursor:pointer;">';
        echo '<div class="nav-main-link" style="'.$activeStyle.'" '.$onClick.' '.$h_attr.'>';
        echo '<span style="font-size:1.5em; pointer-events:none;">' . $icon . '</span>';
        echo '<span style="font-size:0.85em; white-space:nowrap; pointer-events:none;">' . $text . ($isDropdown ? ' ▼' : '') . '</span></div>';
        
        if ($isDropdown) {
            echo '<div class="submenu">';
            foreach($config['submenu'] as $subUrl => $subVal) {

                // BRUGER-NIVEAU FILTER (TRIN 2)
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
                    echo '<hr style="border:0; border-top:1px solid rgba(255,255,255,0.7); margin:5px 0;">';
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

    // 5. Højre side
    echo '<div style="margin-left:auto; display:flex; align-items:center; gap:10px;">';
    
    // Zoom-knapper
    $fsHint = lang('@Toggle full screen (resets on page change). <br>Tip: Use F11 on the keyboard for permanent browser-controlled full screen.');
    
    echo '<div style="display:flex; flex-direction:column; align-items:center; gap:4px;">';
        echo '<div style="display:flex; background:rgba(0,0,0,0.2); padding:3px 12px; border-radius:20px; 
                gap:10px; border:1px solid rgba(255,255,255,0.1); align-items:center;">';
            echo '<button onclick="adjustZoom(-0.02)" style="background:none; border:none; color:white; 
                    cursor:pointer; font-size:18px;">-</button>';
            echo '<button onclick="toggleFullscreen()" data-hint="'.htmlspecialchars($fsHint).'" style="background:none; border:none; color:white; 
                    cursor:pointer; font-size:16px; border-left:1px solid rgba(255,255,255,0.2); 
                    border-right:1px solid rgba(255,255,255,0.2); padding:0 10px;">⛶</button>';
            echo '<button onclick="adjustZoom(0.02)" style="background:none; border:none; color:white; 
                    cursor:pointer; font-size:18px;">+</button>';
        echo '</div>';
        echo '<div style="font-size:11px; color:white; text-transform:uppercase; opacity:0.9;">'.lang('@VIEW').'</div>';
    echo '</div>';

    // SPROGVÆLGER
    $current_l = $_SESSION['lang'] ?? 'da';
    $fMap = [
        'da' => 'dk', 
        'en' => 'gb', 
        'kl' => 'gl', 
        'se' => 'se', 
        'no' => 'no'  
    ];
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
                    echo '<a href="set_lang.php?l='.$l['code'].'" style="display:flex; 
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

    echo '<a href="logout.php" style="background:#e74c3c; color:white; padding:5px 5px; border-radius:4px; 
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

    // --- AUTOMATISK BACKUP-KONTROL OG ADVARSELSVISNING VED LOGIN ---
    $b_res = mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'last_backup_time'");
    $last_backup_timestamp = 0;
    if ($b_res && $b_row = mysqli_fetch_assoc($b_res)) {
        $last_backup_timestamp = (int)$b_row['setting_value'];
    }

    $days_old = ($last_backup_timestamp > 0) ? (int)floor((time() - $last_backup_timestamp) / (24 * 60 * 60)) : 999;

    // Hvis backup er ældre end 14 dage, forsøges automatisk gen-generering med det samme
    if ($days_old >= 14) {
        // Opdater timestamp med det samme for at undgå uendelig løkke, hvis scriptet fejler
        $now_time = time();
        mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('last_backup_time', '$now_time') ON DUPLICATE KEY UPDATE setting_value = '$now_time'");
        
        // Trigger selve backup-genereringen lydløst i baggrunden, hvis filen eksisterer
        if (file_exists('backup.php')) {
            @include_once 'backup.php'; 
            $days_old = 0; // Nulstil tæller til visningen nedenfor
        } else { $alert_msg = lang('@Warning: Backup failed!'); }
    }

    // Vis den pædagogiske advarsel i navigationsbaren, hvis backup halter bagefter
    if ($days_old > 7) {
        // sprintf indsætter $days_old på pladsen, hvor der står %d i den oversatte streng
        $alert_msg = sprintf(lang('@Warning: Backup is %d days old. Remember that you can lose everything you have created since then.'), $days_old);
        echo '<div style="position: absolute; top: 100%; left: 0; right: 0; background: #f39c12; color: #fff; text-align: center; padding: 6px 20px; font-size: 13px; font-weight: bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 8900;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:8px;"></i>' . $alert_msg . '</div>';
    }
    echo '</div></nav>';
}
?>