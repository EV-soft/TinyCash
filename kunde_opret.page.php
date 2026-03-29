<?php # kunde_opret.page.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

// Brug din biblioteks-funktion i stedet for header.inc.php
htm_Header(lang('@Add New Customer'));
showMenu();
?>

<div style="max-width:500px; margin:20px auto; background:white; padding:25px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); font-family: sans-serif;">
    <h3 style="color:#2c3e50; border-bottom:2px solid #2ecc71; padding-bottom:10px;">
        <?php echo lang('@Add New Customer'); ?>
    </h3>
    
    <form action="faktura_actions.php?action=gem_kunde" method="POST">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"> <?php echo lang('@Company / Name:'); ?> </label>
        <input type="text" name="cust_name" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:4px;">
        
        <label style="display:block; margin-bottom:5px; font-weight:bold;"> <?php echo lang('@Address:'); ?> </label>
        <textarea name="cust_address" style="width:100%; padding:10px; margin-bottom:10px; height:80px; border:1px solid #ddd; border-radius:4px;"></textarea>
        
        <label style="display:block; margin-bottom:5px; font-weight:bold;"> <?php echo lang('@Email:'); ?> </label>
        <input type="email" name="cust_email" style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:4px;">
        
        <label style="display:block; margin-bottom:5px; font-weight:bold;"> <?php echo lang('@Registration Number:'); ?> </label>
        <input type="text" name="cust_cvr" style="width:100%; padding:10px; margin-bottom:15px; border:1px solid #ddd; border-radius:4px;">

        <label style="display:block; margin-bottom:5px; font-weight:bold;"> <?php echo lang('@Standard payment days:'); ?> </label>
        <input type="number" name="cust_payment_days" value="8" style="width:100%; padding:10px; margin-bottom:20px; border:1px solid #ddd; border-radius:4px;">
        
        <div style="display:flex; gap:10px;">
            <button type="submit" style="flex:2; background:#2ecc71; color:white; border:none; padding:12px; border-radius:4px; cursor:pointer; font-weight:bold;">
                💾 <?php echo lang('@Save'); ?>
            </button>
            <a href="kunder.page.php" style="flex:1; text-align:center; background:#95a5a6; color:white; padding:12px; border-radius:4px; text-decoration:none;">
                <?php echo lang('@Cancel'); ?>
            </a>
        </div>
    </form>
</div>

<?php htm_Footer(); ?>