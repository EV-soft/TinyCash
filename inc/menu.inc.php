<?php # inc/menu.inc.php v:0.9.1 d:2026-05-08 i:evs
function showMenu() {
    global $conn;

// 1. Tjek admin status
    $adminExists = false;
    $res = mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE user_role = 'admin'");
    if ($res) {
        $row = mysqli_fetch_row($res);
        $adminExists = ($row[0] > 0);
    }
    $current_page = basename($_SERVER['PHP_SELF']); # Definition af den nuværende side
// 2. Menu-struktur
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
                'vat_report.php'             => '🧾 ' . lang('@VAT Report'), // Tilføjet her
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
             // 'product_list.php'           => '🏷️ ' . lang('@Product Catalog'), // Tilføjet her
                'product_edit.php?id=0'      => '➕ ' . lang('@Create New Product'),
            ]
        ],
        'system' => [
            'label'   => '⚙️ ' . lang('@System'),
            'hint'    => lang('@Settings and user management'),
            'submenu' => [
                'company_settings.php'       => '🏢 ' . lang('@Company Settings'), 
                'vat_codes.php'              => '🧾 ' . lang('@VAT Codes & Rates'),
                'user_list.php'              => '🔑 ' . lang('@User Management'),
                '---_1'                      => '---',
                'control_panel' => [
                    'label'     => '🛠️ ' . lang('@Control Panel') . ' <span style="float:right; margin-left:10px;">▶</span>',
                    'submenu'   => [
                        'backup.php'         => '📥 ' . lang('@Backup Management'), 
                        'backup_restore.php' => '🔄 ' . lang('@Restore System'),
                        'error_log.php'      => '⚠️ ' . lang('@Error Log'),
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

// 3. Styling
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

// 4. HTML Navigation
    echo '<nav id="top-nav" style="background:#2c3e50; padding:1px 20px; margin-bottom:20px; display:flex; align-items:center; min-height:65px; font-family:sans-serif; color:white; position:relative; z-index:9000; overflow:visible !important;">';
    echo '<div style="margin-right:25px; font-size:2em; font-weight:bold; flex-shrink:0;">';
    echo '<a href="about.php" style="color:white; text-decoration:none;"><span style="color:#3498db;">Tiny</span>Cash</a>';
    echo '<div style="font-size:12px; font-weight:200; color: #aeff00; margin-top:2px;">Develop version w. errors</div>'; 
    echo '</div>';
    
    $current_page = basename($_SERVER['PHP_SELF']);

    foreach($menu as $url => $config) {
    if ($url == 'logout.php') continue;
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
            echo '<div class="submenu">'; // Ingen data-hint her
            foreach($config['submenu'] as $subUrl => $subVal) {
                if (is_array($subVal) && isset($subVal['submenu'])) {
                    // Kontrolpanel logik...
                    echo '<div class="has-sub" style="position:relative;">';
                    echo '<a href="#" class="dropdown-item" style="background:rgba(0,0,0,0.1);">' . ($subVal['label'] ?? '') . '</a>';
                    echo '<div class="sub-submenu">';
                    foreach($subVal['submenu'] as $ssUrl => $ssLabel) {
                        echo '<a href="'.$ssUrl.'" class="dropdown-item">' . $ssLabel . '</a>';
                    }
                    echo '</div></div>';
                } elseif (strpos($subUrl, '---') !== false) {
                    echo '<hr style="border:0; border-top:1px solid rgba(255,255,255,0.1); margin:5px 0;">';
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
echo '<div style="margin-left:auto; display:flex; align-items:center; gap:20px;">';
    
    // Visning Kontrol knapper
    echo '<div style="display:flex; flex-direction:column; align-items:center; gap:4px;">';
        echo '<div style="display:flex; background:rgba(0,0,0,0.2); padding:3px 12px; border-radius:20px; 
                gap:10px; border:1px solid rgba(255,255,255,0.1); align-items:center;">';
            echo '<button onclick="adjustZoom(-0.02)" style="background:none; border:none; color:white; 
                    cursor:pointer; font-size:18px;">-</button>';
            echo '<button onclick="toggleFullscreen()" style="background:none; border:none; color:white; 
                    cursor:pointer; font-size:16px; border-left:1px solid rgba(255,255,255,0.2); 
                    border-right:1px solid rgba(255,255,255,0.2); padding:0 10px;">⛶</button>';
            echo '<button onclick="adjustZoom(0.02)" style="background:none; border:none; color:white; 
                    cursor:pointer; font-size:18px;">+</button>';
        echo '</div>';
        echo '<div style="font-size:11px; color:white; text-transform:uppercase; opacity:0.9;">'.lang('@VIEW').'</div>';
    echo '</div>';

    // SPROGVÆLGER
    $current_l = $_SESSION['lang'] ?? 'da';
    $fMap = ['da'=>'dk','en'=>'gb','kl'=>'gl','no'=>'no'];
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
    echo '</div>';

    // Logout
    echo '<a href="logout.php" style="background:#e74c3c; color:white; padding:5px 15px; border-radius:4px; 
          text-decoration:none; font-size:14px; text-align: center; min-width: 100px;">';
    echo '<small>"'.htmlspecialchars($_SESSION['user_name'] ?? 'User').'"</small><br><b>👤 '.lang('@Logout').'</b></a>';
echo '</div>';
echo '</div></nav>';
    ?>

    <script>
    function adjustZoom(d) { document.body.style.zoom = (parseFloat(document.body.style.zoom || 1) + d); }
    function toggleSub(el) {
        var sub = el.nextElementSibling;
        document.querySelectorAll(".submenu").forEach(s => { if(s !== sub) s.style.display = "none"; });
        if(sub) sub.style.display = (sub.style.display === 'block') ? 'none' : 'block';
    }
    function toggleLangMenu(e) {
        e.stopPropagation();
        var m = document.getElementById("lang-options");
        document.querySelectorAll(".submenu").forEach(s => s.style.display = "none");
        m.style.display = (m.style.display === "block") ? "none" : "block";
    }
    window.onclick = function(e) {
        if (!e.target.closest('.dropdown') && !e.target.closest('.custom-lang-dropdown')) {
            document.querySelectorAll(".submenu, #lang-options").forEach(m => m.style.display = "none");
        }
    }
    </script>
    <?php
}