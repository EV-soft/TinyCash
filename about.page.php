<?php # about.page.php v:0.8 d:2026-04-10 i:EVS m:2
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';
require 'inc/menu.inc.php';

htm_Header(lang('@About TinyCash'));
showMenu();

htm_Card_(lang('@TinyCash Billing System'), '450');
?>
<div style="text-align: center; font-family: sans-serif; line-height: 1.6;">
    <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 10px;">
        <span style="color:#3498db;">Tiny</span>Cash
    </div>
    <p><strong><?php echo lang('@Version'); ?>:</strong> <?php echo APP_VERSION; ?></p>
    <p><strong><?php echo lang('@Last Updated'); ?>:</strong> <?php echo APP_DATE; ?></p>
    <hr>
    <p><?php echo lang('@© 2026 - Developed with a focus on simplicity and speed.</p>'); ?>
    <p style="text-align: left; background: #f9f9f9; padding: 15px; border-radius: 5px; border-left: 4px solid #3498db;">
        <strong>TinyCash </strong> <?php echo lang('@is a lightweight accounting system designed for small/smaller businesses,
                                                    that want full control over their own data.'). '<br>'.
                                              lang('@Currently as a single-currency system');
                                   ?>
    </p>
    <div style="margin: 25px 0; text-align: left;">
        <h4 style="margin-bottom: 5px;"><?php echo lang('@Developed by:'); ?></h4>
        <p style="margin-top: 0;"><?php echo lang('@EV-soft & Gemini / For your Business'); ?></p>
        <h4 style="margin-bottom: 5px;"><?php echo lang('@System status:'); ?></h4>
        <ul style="list-style: none; padding: 0;">
            <li>✅ <?php echo lang('@PHP Version:'); echo ' '.phpversion(); ?></li>
            <li>✅ <?php echo lang('@Database: MySQLi Connected'); ?></li>
            <li>✅ <?php echo lang('@Language:'); echo ($_SESSION['lang'] == 'da' ? ' Dansk 🇩🇰' : ' English 🇬🇧'); ?></li>
        </ul>
    </div>
    <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
    <small style="color: #bdc3c7;">&copy; <?php echo date('Y'); ?> 𝘓𝘐𝘊𝘌𝘕𝘚𝘌 & 𝘊𝘰𝘱𝘺𝘳𝘪𝘨𝘩𝘵 © EV-soft. All rights reserved.</small>
</div>

<?php htm_Card_end();