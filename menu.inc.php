<?php # menu.inc.php

function showMenu() { 
    global $conn;

    // Tjek om der findes en admin i databasen
    $adminExists = false;
    $res = mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE user_role = 'admin'");
    if ($res) {
        $row = mysqli_fetch_row($res);
        $adminExists = ($row[0] > 0);
    }

    $menu = [
        'index.page.php'         => '🏠 ' . lang('@Overview'), 
        'fakturaer.page.php'     => '📄 ' . lang('@Invoices'),
        'faktura_opret.page.php' => '<span style="color: #2eff8c;">➕</span> ' . lang('@New Invoice'),
        
        // REGNSKAB
        'regnskab' => [
            'label' => '💰 ' . lang('@Accounting') . ' ▼',
            'submenu' => [
                'postering.page.php'        => '✍️ ' .  lang('@New Manual Posting'),
                'rapport_resultat.page.php' => '📊 ' . lang('@Income Statement'),
                'kontoplan.page.php'        => '📑 ' . lang('@Chart of Accounts')
            ]
        ],

        'kunder' => [
            'label' => '👥 ' . lang('@Customers') . ' ▼',
            'submenu' => [
                'kunder.page.php'        => lang('@Customer list'),
                'kunde_opret.page.php'   => '✨ ' . lang('@Create new customer')
            ]
        ],
        'lager' => [
            'label' => '📦 ' . lang('@Inventory') . ' ▼',
            'submenu' => [
                'lager_liste.page.php'   => lang('@Product overview'),
                'lager_opret.page.php'   => '➕ ' . lang('@Add new product'),
                'lager_indhold.page.php' => '📉 ' . lang('@Stock status & Prices')
            ]
        ],
        'system' => [
            'label' => '⚙️ ' . lang('@System') . ' ▼',
            'submenu' => [
                'firma_indstillinger.page.php' => '🏢 ' . lang('@Company details'), 
                'bruger_liste.page.php'  => '🔑 ' . lang('@User management'),
                '---'                    => '---',
                'backup.page.php'        => '📥 ' . lang('@SQL + JSON Backup'),
                'full_project_backup.php'=> '📦 ' . lang('@Full ZIP-Backup'),
                'backup_gendan.page.php' => '🔄 ' . lang('@Restore system'),
                'log.page.php'           => '⚠️ '  .  lang('@Error log'),
                'about.page.php'         => 'ℹ️ ' . lang('@About TinyCash')
            ]
        ], 
        'logout.php'                     => '🚪 ' . lang('@Log out')
    ];
    
    // Indsæt "Opret admin" hvis databasen er tom
    if (!$adminExists) {
        $newSub = ['bruger_opret_admin.php' => '🆕 ' . lang('@Create first admin')];
        $menu['system']['submenu'] = array_merge($newSub, $menu['system']['submenu']);
    }

    echo '<nav style="background:#2c3e50; padding:10px 20px; margin-bottom:20px; display:flex; flex-wrap:wrap; align-items:center; font-family:sans-serif; color:white; min-height: 50px;">';

    // Logo
    echo '<div style="margin-right:5px; font-size:1.4em; font-weight:bold; letter-spacing:1px;">';
    echo '<a href="index.page.php" style="color:white; text-decoration:none; margin-right:10px; font-size:1.4em; font-weight:bold;">';
    echo '<span style="color:#3498db;">Tiny</span>Cash</a>';
    echo '</div>';
    
    $current_page = basename($_SERVER['PHP_SELF']);
foreach($menu as $url => $label) {
        $isActive = ($current_page == $url);    // Tjek om dette punkt er det aktive
        $activeStyle = $isActive ? 'border-bottom: 3px solid #3498db; padding-bottom: 5px;' : '';
        if (is_array($label)) {                 // For dropdowns tjekker vi om en af undersiderne er aktive
            $subActive = isset($label['submenu'][$current_page]);
            $subStyle = $subActive ? 'border-bottom: 3px solid #3498db; padding-bottom: 5px;' : '';
            echo '<div class="dropdown" style="position:relative; margin-right:10px;">';
            echo '<a href="#" style="color:white; text-decoration:none; cursor:pointer; padding:8px 12px; display:flex; align-items:center; gap:8px; '.$subStyle.'" onclick="toggleSub(event)">' . $label['label'] . '</a>';
            echo '<div class="submenu" style="display:none; position:absolute; background:#34495e; min-width:230px; box-shadow:0 8px 16px rgba(0,0,0,0.4); z-index:100; border-radius:4px; margin-top:5px; border:1px solid #455a64;">';
            foreach($label['submenu'] as $subUrl => $subLabel) {
                if ($subLabel === '---') {
                    echo '<hr style="border:0; border-top:1px solid #2c3e50; margin:5px 0;">';
                } else {
                    echo '<a href="'.$subUrl.'" style="color:white; padding:10px 16px; text-decoration:none; display:block; border-bottom:1px solid #2c3e50; font-size:14px;">' . $subLabel . '</a>';
                }
            }
            echo '</div></div>';
         } else {
            $isLogout = ($url == 'logout.php');
            if ($isLogout) {       // SPROG-FLAG LIGE FØR LOGOUT
                echo '<div style="margin-left: auto; display: flex; align-items: center; gap: 15px; margin-right: 20px;">';
                // Dansk Flag (SVG Billede)
                echo '<a href="set_lang.php?l=da" title="Dansk" style="display: flex; align-items: center;">';
                echo '<img src="https://flagcdn.com/w40/dk.png" alt="DK" style="width: 28px; height: auto; border-radius: 2px; border: 1px solid rgba(255,255,255,0.2); transition: transform 0.2s;" onmouseover="this.style.transform=\'scale(1.2)\'" onmouseout="this.style.transform=\'scale(1)\'">';
                echo '</a>';
                // Engelsk Flag (SVG Billede)
                echo '<a href="set_lang.php?l=en" title="English" style="display: flex; align-items: center;">';
                echo '<img src="https://flagcdn.com/w40/gb.png" alt="EN" style="width: 28px; height: auto; border-radius: 2px; border: 1px solid rgba(255,255,255,0.2); transition: transform 0.2s;" onmouseover="this.style.transform=\'scale(1.2)\'" onmouseout="this.style.transform=\'scale(1)\'">';
                echo '</a>';
                echo '</div>';
            }
            $style = $isLogout 
                ? 'background:#e74c3c; color:white; padding:8px 15px; border-radius:4px; text-decoration:none; font-weight:bold;' 
                : 'color:white; text-decoration:none; padding:8px 12px; margin-right:10px; display:flex; align-items:center; gap:8px; ' . $activeStyle;
            echo '<a href="'.$url.'" style="'.$style.'">' . $label . '</a>';
        }
    }

    // Din eksisterende Log ud-knap vil nu følge efter denne div pga. margin-left: auto
    echo '</nav>'; // Denne linje findes allerede i din fil    echo '</nav>';
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
    window.onclick = function(event) {
        if (!event.target.closest('.dropdown')) {
            var dropdowns = document.getElementsByClassName("submenu");
            for (var i = 0; i < dropdowns.length; i++) { dropdowns[i].style.display = "none"; }
        }
    }
    </script>
    <?php
}
?>