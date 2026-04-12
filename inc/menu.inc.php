<?php # inc/menu.inc.php v:0.8.0 d:2026-04-12 i:Gemini m:2

function showMenu() { 
    global $conn;

    $adminExists = false;
    $res = mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE user_role = 'admin'");
    if ($res) {
        $row = mysqli_fetch_row($res);
        $adminExists = ($row[0] > 0);
    }

    $menu = [
        'index.page.php' => '🏠 ' . lang('@Overview'), 
        
        'sales' => [
            'label' => '📄 ' . lang('@Sales & Customers') . ' ▼',
            'submenu' => [                               
                'invoices.page.php'        => '📋 ' . lang('@Invoice List'),
                'invoice_create.page.php'  => '➕ ' . lang('@Create New Invoice'),
                '---'                      => '---',   
                'customer_list.page.php'   => '👥 ' . lang('@Customer List'),
                'customer_create.page.php' => '✨ ' . lang('@Create New Customer')
            ]
        ],
        
        'bank' => [
            'label' => '🏦 ' . lang('@Bank') . ' ▼',
            'submenu' => [
                'bank_import_step1.page.php' => '📥 ' . lang('@Import Bank File'),
                'reconcile_list.page.php'    => '⚖️ ' . lang('@Bank Reconciliation'),
                '---'                        => '---',
                'settings_fees.page.php'     => '⚙️ ' . lang('@Fee Rules')
            ]
        ],
        
        'accounting' => [
            'label' => '💰 ' . lang('@Finance') . ' ▼',
            'submenu' => [
                'expense_create.page.php'    => '📥 ' . lang('@Register Expense'), 
                'post_manual.page.php'       => '✍️ ' .  lang('@New Manual Journal'),
                'report_income.page.php'     => '📊 ' . lang('@Profit & Loss Report'),
                'chart_of_accounts.page.php' => '📑 ' . lang('@Chart of Accounts')
            ]
        ],

        'inventory' => [
            'label' => '📦 ' . lang('@Inventory') . ' ▼',
            'submenu' => [
                'inventory_status.page.php'  => '📉 ' . lang('@Stock Status'), 
                'product_new.page.php'       => '➕ ' . lang('@Create New Product'),
            ]
        ],

        'system' => [
            'label' => '⚙️ ' . lang('@System') . ' ▼',
            'submenu' => [
                'company_settings.page.php'  => '🏢 ' . lang('@Company Settings'), 
                'vat_codes.page.php'         => '🧾 ' . lang('@VAT Codes & Rates'),
                'user_list.page.php'         => '🔑 ' . lang('@User Management'),
                '---'                        => '---',
                'backup.page.php'            => '📥 ' . lang('@Backup Management'), 
                'backup_restore.page.php'    => '🔄 ' . lang('@Restore System'),
                'error_log.page.php'         => '⚠️ ' .  lang('@Error Log'),
                'ai_help.page.php'           => 'ℹ️ ' . lang('@AI support'),
                'about.page.php'             => 'ℹ️ ' . lang('@About TinyCash')
            ]
        ], 
        'logout.php' => '🚪 ' . lang('@Logout')
    ];
    
    if (!$adminExists) {
        $newSub = ['user_create_admin.php' => '🆕 ' . lang('@Create First Admin')];
        $menu['system']['submenu'] = array_merge($newSub, $menu['system']['submenu']);
    }

    echo '<nav style="background:#2c3e50; padding:10px 20px; margin-bottom:20px; display:flex; flex-wrap:wrap; align-items:center; font-family:sans-serif; color:white; min-height: 50px;">';
    echo '<div style="margin-right:25px; font-size:1.4em; font-weight:bold; letter-spacing:1px;">';
    echo '<a href="about.page.php" style="color:white; text-decoration:none; display:flex; align-items:center; gap:5px;">';
    echo '<span style="color:#3498db;">Tiny</span>Cash</a>';
    echo '</div>';
    
    $current_page = basename($_SERVER['PHP_SELF']);
    foreach($menu as $url => $label) {
        $isActive = ($current_page == $url);
        $activeStyle = $isActive ? 'border-bottom: 3px solid #3498db; padding-bottom: 5px;' : '';
        
        if (is_array($label)) {
            $subActive = isset($label['submenu'][$current_page]);
            $subStyle = $subActive ? 'border-bottom: 3px solid #3498db; padding-bottom: 5px;' : '';
            echo '<div class="dropdown" style="position:relative; margin-right:15px;">';
            echo '<a href="#" style="color:white; text-decoration:none; cursor:pointer; padding:8px 12px; display:flex; align-items:center; gap:8px; '.$subStyle.'" onclick="toggleSub(event)">' . $label['label'] . '</a>';
            echo '<div class="submenu" style="display:none; position:absolute; background:#34495e; min-width:230px; box-shadow:0 8px 16px rgba(0,0,0,0.4); z-index:100; border-radius:4px; margin-top:5px; border:1px solid #455a64;">';
            foreach($label['submenu'] as $subUrl => $subLabel) {
                if ($subLabel === '---') {
                    echo '<hr style="border:0; border-top:1px solid #2c3e50; margin:5px 0;">';
                } else {
                    echo '<a href="'.$subUrl.'" class="dropdown-item">' . $subLabel . '</a>';
                }
            }
            echo '</div></div>';
         } else {
            $isLogout = ($url == 'logout.php');

            if ($isLogout) {
                $lang_file = 'json-data/languages.json';
                $current_l = $_SESSION['lang'] ?? 'en';
                $fMap = ['da'=>'dk','sv'=>'se','en'=>'gb','no'=>'no','nb'=>'no','de'=>'de','pl'=>'pl','fr'=>'fr','es'=>'es','it'=>'it','fi'=>'fi','kl'=>'gl'];
                $fCode = $fMap[$current_l] ?? $current_l;

                echo '<div style="margin-left: auto; display: flex; align-items: center; margin-right: 15px;">';
                echo '<div class="custom-lang-dropdown" style="position:relative;">';
                echo '<div id="lang-trigger" onclick="toggleLangMenu(event)" style="background:#34495e; color:white; padding:5px 10px; border-radius:4px; cursor:pointer; display:flex; align-items:center; gap:8px; border:1px solid #455a64; font-size: 12px; font-weight: bold;">';
                echo '<span class="fi fi-'.$fCode.'" style="width:18px; height:13px; display:inline-block;"></span>';
                echo '<span>' . strtoupper($current_l) . ' ▼</span>';
                echo '</div>';
                echo '<div id="lang-options" style="display:none; position:absolute; right:0; top:35px; background:#2c3e50; border:1px solid #455a64; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.5); z-index:1000; min-width:140px;">';
                
                if (file_exists($lang_file)) {
                    $lang_data = json_decode(file_get_contents($lang_file), true);
                    if (!empty($lang_data['language'])) {
                        foreach ($lang_data['language'] as $l) {
                            $optCode = $fMap[$l['code']] ?? $l['code'];
                            $isCurrent = ($l['code'] == $current_l);
                            $bgStyle = $isCurrent ? 'background:rgba(52,152,219,0.15);' : '';
                            echo '<a href="set_lang.php?l='.$l['code'].'" style="display:flex; align-items:center; gap:10px; padding:10px; color:white; text-decoration:none; border-bottom:1px solid rgba(255,255,255,0.05); font-size:13px; '.$bgStyle.'">';
                            echo '<span class="fi fi-'.$optCode.'" style="width:20px; height:15px;"></span>';
                            echo '<span>'.$l['native'].'</span>';
                            echo '</a>';
                        }
                    }
                }
                echo '</div></div></div>';
            }

            $style = $isLogout 
                ? 'background:#e74c3c; color:white; padding:8px 15px; border-radius:4px; text-decoration:none; font-weight:bold;' 
                : 'color:white; text-decoration:none; padding:8px 12px; margin-right:10px; display:flex; align-items:center; gap:8px; ' . $activeStyle;
            echo '<a href="'.$url.'" style="'.$style.'">' . $label . '</a>';
        }
    }
    echo '</nav>';
    ?>
    <script>
    function toggleSub(e) {
        e.preventDefault();
        var sub = e.currentTarget.nextElementSibling;
        var allSubs = document.getElementsByClassName("submenu");
        for (var i = 0; i < allSubs.length; i++) {
            if (allSubs[i] !== sub) allSubs[i].style.display = "none";
        }
        sub.style.display = (sub.style.display === 'block') ? 'none' : 'block';
    }
    function toggleLangMenu(e) {
        e.stopPropagation();
        var menu = document.getElementById("lang-options");
        menu.style.display = (menu.style.display === "block") ? "none" : "block";
    }
    window.onclick = function(event) {
        if (!event.target.closest('.custom-lang-dropdown')) {
            var langMenu = document.getElementById("lang-options");
            if (langMenu) langMenu.style.display = "none";
        }
        if (!event.target.closest('.dropdown')) {
            var dropdowns = document.getElementsByClassName("submenu");
            for (var i = 0; i < dropdowns.length; i++) { dropdowns[i].style.display = "none"; }
        }
    }
    </script>
    <?php
} // SLUT PÅ FUNKTIONEN