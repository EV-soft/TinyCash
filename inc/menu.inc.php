<?php # inc/menu.inc.php v:0.8.4 d:2026-04-13 i:Gemini m:2

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
            'label' => '📄 ' . lang('@Sales & Customers'),
            'submenu' => [                                
                'invoices.page.php'        => '📋 ' . lang('@Invoice List'),
                'invoice_create.page.php'  => '➕ ' . lang('@Create New Invoice'),
                '---'                      => '---',   
                'customer_list.page.php'   => '👥 ' . lang('@Customer List'),
                'customer_create.page.php' => '✨ ' . lang('@Create New Customer')
            ]
        ],
        'bank' => [
            'label' => '🏦 ' . lang('@Bank'),
            'submenu' => [
                'bank_import_step1.page.php' => '📥 ' . lang('@Import Bank File'),
                'reconcile_list.page.php'    => '⚖️ ' . lang('@Bank Reconciliation'),
                '---'                        => '---',
                'settings_fees.page.php'     => '⚙️ ' . lang('@Fee Rules')
            ]
        ],
        'accounting' => [
            'label' => '💰 ' . lang('@Finance'),
            'submenu' => [
                'expense_create.page.php'    => '📥 ' . lang('@Register Expense'), 
                'post_manual.page.php'       => '✍️ ' .  lang('@New Manual Journal'),
                'report_income.page.php'     => '📊 ' . lang('@Profit & Loss Report'),
                'chart_of_accounts.page.php' => '📑 ' . lang('@Chart of Accounts')
            ]
        ],
        'inventory' => [
            'label' => '📦 ' . lang('@Inventory'),
            'submenu' => [
                'inventory_status.page.php'  => '📉 ' . lang('@Stock Status'), 
                'product_new.page.php'       => '➕ ' . lang('@Create New Product'),
            ]
        ],
        'system' => [
            'label' => '⚙️ ' . lang('@System'),
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

    echo '<nav style="background:#2c3e50; padding:5px 20px; margin-bottom:20px; display:flex; align-items:center; min-height:80px; font-family:sans-serif; color:white; position:relative; z-index:1000;">';
    
    echo '<div style="margin-right:25px; font-size:2em; font-weight:bold; flex-shrink:0;">';
    echo '<a href="about.page.php" style="color:white; text-decoration:none;"><span style="color:#3498db;">Tiny</span>Cash</a>';
    echo '</div>';
    
    $current_page = basename($_SERVER['PHP_SELF']);

    foreach($menu as $url => $label) {
        if ($url == 'logout.php') continue;

        $isDropdown = is_array($label);
        $fullText = $isDropdown ? $label['label'] : $label;
        $parts = explode(' ', $fullText, 2);
        $icon = $parts[0] ?? '';
        $text = $parts[1] ?? $fullText;
        
        $isActive = ($current_page == $url || (isset($label['submenu']) && isset($label['submenu'][$current_page])));
        $activeStyle = $isActive ? 'border-bottom: 3px solid #3498db;' : '';

        $itemStyle = 'display:flex; flex-direction:column; align-items:center; text-decoration:none; color:white; padding:8px 12px; min-width:90px; cursor:pointer; ' . $activeStyle;

        if ($isDropdown) {
            echo '<div class="dropdown" style="position:relative;">';
            echo '<div style="'.$itemStyle.'" onclick="toggleSub(this)">';
            echo '<span style="font-size:1.5em; pointer-events:none;">' . $icon . '</span>';
            echo '<span style="font-size:0.85em; white-space:nowrap; pointer-events:none;">' . $text . ' ▼</span></div>';
            
            echo '<div class="submenu" style="display:none; position:absolute; top:100%; left:0; background:#34495e; min-width:230px; box-shadow:0 8px 16px rgba(0,0,0,0.4); z-index:1100; border-radius:4px; border:1px solid #455a64;">';
            foreach($label['submenu'] as $subUrl => $subLabel) {
                if ($subLabel === '---') {
                    echo '<hr style="border:0; border-top:1px solid #2c3e50; margin:5px 0;">';
                } else {
                    echo '<a href="'.$subUrl.'" class="dropdown-item" style="display:block; padding:12px 15px; color:white; text-decoration:none; font-size:14px;">' . $subLabel . '</a>';
                }
            }
            echo '</div></div>';
        } else {
            echo '<a href="'.$url.'" style="'.$itemStyle.'">';
            echo '<span style="font-size:1.5em;">' . $icon . '</span>';
            echo '<span style="font-size:0.85em;">' . $text . '</span></a>';
        }
    }

    echo '<div style="margin-left:auto; display:flex; align-items:center; gap:15px;">';
    
    // Sprogvælger
    $current_l = $_SESSION['lang'] ?? 'en';
    $fMap = ['da'=>'dk','en'=>'gb','kl'=>'gl','no'=>'no','nb'=>'no'];
    $fCode = $fMap[$current_l] ?? $current_l;

    echo '<div class="custom-lang-dropdown" style="position:relative;">';
    echo '<div onclick="toggleLangMenu(event)" style="background:#34495e; color:white; padding:8px 12px; border-radius:4px; cursor:pointer; display:flex; align-items:center; gap:8px; border:1px solid #455a64; font-size:12px;">';
    echo '<span class="fi fi-'.$fCode.'"></span><span>' . strtoupper($current_l) . ' ▼</span></div>';
    echo '<div id="lang-options" style="display:none; position:absolute; right:0; top:45px; background:#2c3e50; border:1px solid #455a64; border-radius:4px; min-width:150px; z-index:1200;">';
    
    $lang_file = 'json-data/languages.json';
    if (file_exists($lang_file)) {
        $lang_data = json_decode(file_get_contents($lang_file), true);
        foreach ($lang_data['language'] as $l) {
            $optCode = $fMap[$l['code']] ?? $l['code'];
            echo '<a href="set_lang.php?l='.$l['code'].'" style="display:flex; align-items:center; gap:10px; padding:12px; color:white; text-decoration:none; font-size:13px; border-bottom:1px solid rgba(255,255,255,0.05);">';
            echo '<span class="fi fi-'.$optCode.'"></span><span>'.$l['native'].'</span></a>';
        }
    }
    echo '</div></div>';

    echo '<a href="logout.php" style="background:#e74c3c; color:white; padding:10px 20px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:14px;">🚪 ' . lang('@Logout') . '</a>';
    echo '</div>';

    echo '</nav>';
    ?>
    <script>
    function toggleSub(el) {
        var sub = el.nextElementSibling;
        // Skjul alle andre undermenuer
        document.querySelectorAll(".submenu").forEach(s => { 
            if(s !== sub) s.style.display = "none"; 
        });
        // Toggle den aktuelle
        sub.style.display = (sub.style.display === 'block') ? 'none' : 'block';
    }

    function toggleLangMenu(e) {
        e.stopPropagation();
        var m = document.getElementById("lang-options");
        // Skjul hoved-undermenuer hvis sprog åbnes
        document.querySelectorAll(".submenu").forEach(s => s.style.display = "none");
        m.style.display = (m.style.display === "block") ? "none" : "block";
    }

    window.onclick = function(e) {
        // Hvis man klikker uden for en dropdown, skjul alt
        if (!e.target.closest('.dropdown') && !e.target.closest('.custom-lang-dropdown')) {
            document.querySelectorAll(".submenu, #lang-options").forEach(m => m.style.display = "none");
        }
    }
    </script>
    <?php
}