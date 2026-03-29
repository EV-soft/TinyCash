<?php # about.page.php
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php';
require 'menu.inc.php';

htm_Header(lang('@About TinyCash'));
showMenu();

htm_Card_(lang('@About TinyCash'), '450');
?>
<div style="text-align: center; font-family: sans-serif; line-height: 1.6;">
    <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 10px;">
        <span style="color:#3498db;">Tiny</span>Cash
    </div>
    <div style="color: #7f8c8d; margin-bottom: 20px;">Version 1.2.0 </div>

    <p style="text-align: left; background: #f9f9f9; padding: 15px; border-radius: 5px; border-left: 4px solid #3498db;">
        <strong>TinyCash </strong> <?php echo lang('@er et letvægts regnskabssystem designet til små/mindre virksomheder, 
                                                    der ønsker fuld kontrol over egne data.'); ?>
    </p>

    <div style="margin: 25px 0; text-align: left;">
        <h4 style="margin-bottom: 5px;"><?php echo lang('@Udviklet af:'); ?></h4>
        <p style="margin-top: 0;"><?php echo lang('@EV-soft & Gemini / Til din Virksomhed'); ?></p>
        
        <h4 style="margin-bottom: 5px;"><?php echo lang('@Systemstatus:'); ?></h4>
        <ul style="list-style: none; padding: 0;">
            <li>✅ <?php echo lang('@PHP Version:'); echo ' '.phpversion(); ?></li>
            <li>✅ <?php echo lang('@Database: MySQLi Connected'); ?></li>
            <li>✅ <?php echo lang('@Sprog:'); echo ($_SESSION['lang'] == 'da' ? ' Dansk 🇩🇰' : ' English 🇬🇧'); ?></li>
        </ul>
    </div>

    <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
    
    <small style="color: #bdc3c7;">&copy; <?php echo date('Y'); ?> EV-soft. All rights reserved.</small>
</div>
<?php htm_Card_end(); ?>