<?php # /ai_help.php v:1.3.0 d:2026-08-30 i:evs
# Statisk vejledningsside til brugere om hvordan man bruger en AI-assistent
# (fx Claude Code) effektivt på TinyCash - kontekst-fil, fejlbeskrivelse og
# Debug=true. Filstier/eksempler holdes ajour med de faktiske filnavne i
# projektet (fandt og rettede stale referencer: inc/master_advisor.php ->
# faktisk inc/Master_Advisor.php, og et dødt link til index.page.php ->
# index.php, en efterladt navnekonvention fra før .page.php-omdøbningen).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

htm_Header('@AI Support & Advisor');
showMenu();
?>

<div style="max-width: 900px; margin: 20px auto; font-family: sans-serif;">
    <div style="text-align: center; margin-bottom: 40px;">
        <h1 style="color: #2c3e50; font-size: 2.5em;">🤖 <?php echo lang('@AI Assistant Guide'); ?></h1>
        <p style="color: #7f8c8d; font-size: 1.1em;"><?php echo lang('@How to get the best help for usage, development and debugging of TinyCash.'); ?></p>
    </div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
        <?php htm_Card_('@1. Give AI Context', '100%'); ?>
            <p><?php echo lang('@To allow the AI to fix errors in e.g. VAT or invoicing, it must know your database structure.'); ?></p>
            <ul style="line-height: 1.6;">
                <li><?php echo lang('@Run the file'); ?> <code>/inc/Master_Advisor.php</code> <?php echo lang('@in your browser.'); ?></li>
                <li><?php echo lang('@Copy all content (SQL schema + source code).'); ?></li>
                <li><?php echo lang('@Paste it into the chat as the very first thing.'); ?></li>
            </ul>
        <?php htm_Card_end(); ?>

        <?php htm_Card_('@2. Describe the error precisely', '100%'); ?>
            <p><?php echo lang('@Avoid "It does not work". Instead use:'); ?></p>
            <ul style="line-height: 1.6;">
                <li><strong><?php echo lang('@Which page?'); ?></strong> (e.g. <code>invoice_view.php</code>)</li>
                <li><strong><?php echo lang('@Which ID?'); ?></strong> (e.g. "<?php echo lang('@When I open ID 12'); ?>")</li>
                <li><strong><?php echo lang('@What is the error?'); ?></strong> (e.g. "<?php echo lang('@Error 500 or VAT is calculated incorrectly'); ?>")</li>
            </ul>
        <?php htm_Card_end(); ?>
    </div>

    <?php htm_Card_('@🛠 Smart Debugging (Debug Mode)'); ?>
        <p><?php echo lang('@If you see a white page (Error 500), you can activate a detailed error report directly in your browser without changing the code.'); ?></p>
        <div style="background: #f8f9fa; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin: 15px 0;">
            <strong><?php echo lang('@How to do it:'); ?></strong><br>
            <?php echo lang('@Add'); ?> <code style="color: #e74c3c; font-weight: bold;">&Debug=true</code> <?php echo lang('@to the end of the URL in your browser.'); ?><br><br>
            <em><?php echo lang('@Example:'); ?></em><br>
            <code style="font-size: 0.9em;">invoice_view.php?id=12<strong>&Debug=true</strong></code>
        </div>
        <p><?php echo lang('@Copy the resulting error message (e.g. "Fatal error: Uncaught Error...") and send it to the AI for an immediate fix.'); ?></p>
    <?php htm_Card_end(); ?>
    <div style="background: #e8f4fd; border-left: 5px solid #3498db; padding: 20px; margin-top: 30px; border-radius: 4px;">
        <h3 style="margin-top: 0; color: #2980b9;">💡 <?php echo lang('@AI Tips'); ?></h3>
        <p style="margin-bottom: 0;">
            <?php echo lang('@You can ask the AI to "Refactor" your code if it has become cluttered, or ask it to perform a "Security Audit" to check for SQL injections.'); ?>
        </p>
    </div>
    <div style="text-align: center; margin-top: 40px;">
        <a href="index.php" class="btn-secondary" style="padding: 10px 25px; text-decoration: none; border-radius: 4px;">
            <?php echo lang('@Back to Dashboard'); ?>
        </a>
    </div>
</div>

<?php
htm_Footer();
ob_end_flush();
?>