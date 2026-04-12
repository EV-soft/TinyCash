<?php # /vat_codes.page.php v:0.8 d:2026-04-11 i:evs m:1
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php'; 
require 'inc/menu.inc.php';

// Håndter sletning
if (isset($_GET['delete'])) {
    $id = mysqli_real_escape_string($conn, $_GET['delete']);
    mysqli_query($conn, "DELETE FROM vat_codes WHERE vat_id = '$id'");
    header("Location: vat_codes.page.php"); exit;
}

htm_Header(lang('@VAT Codes'));
showMenu();

echo "<div style='max-width:800px; margin:0 auto;'>";
    
    // Oversigt
    htm_Card_(lang('@VAT Codes'), '600');
    echo "<table style='width:100%; border-collapse:collapse;'>";
    echo "<tr style='background:#f8f9fa; border-bottom:2px solid #eee;'>
            <th style='padding:10px; text-align:left;'>ID</th>
            <th style='padding:10px; text-align:left;'>".lang('@Name')."</th>
            <th style='padding:10px; text-align:center;'>%</th>
            <th style='padding:10px; text-align:right;'>".lang('@Account')."</th>
            <th style='padding:10px; text-align:right;'></th>
          </tr>";

    $res = mysqli_query($conn, "SELECT * FROM vat_codes ORDER BY vat_id ASC");
    while ($row = mysqli_fetch_assoc($res)) {
        echo "<tr style='border-bottom:1px solid #eee;'>
                <td style='padding:10px;'><b>{$row['vat_id']}</b></td>
                <td style='padding:10px;'>{$row['vat_name']}</td>
                <td style='padding:10px; text-align:center;'>{$row['vat_rate']}%</td>
                <td style='padding:10px; text-align:right;'>{$row['vat_acc_id']}</td>
                <td style='padding:10px; text-align:right;'>
                    <a href='?delete={$row['vat_id']}' style='color:#e74c3c; text-decoration:none;' onclick='return confirm(\"Slet?\")'>🗑️</a>
                </td>
              </tr>";
    }
    echo "</table>";
    htm_Card_end();

    // Formular til at tilføje ny
    echo "<div style='margin-top:20px;'>";
    htm_Card_(lang('@Add New VAT Code'), '600');
    ?>
    <form method="post" action="vat_save.php">
        <div style="display: grid; grid-template-columns: 1fr 2fr 1fr 1fr; gap: 10px; align-items: end;">
            <?php 
                htm_InputGroup('🔑', 'ID', 'vat_id', '', 'text', null, 'required placeholder="f.eks. S25"');
                htm_InputGroup('📝', '@Name', 'vat_name', '', 'text', null, 'required');
                htm_InputGroup('📊', '%', 'vat_rate', '25', 'number');
                htm_InputGroup('📑', '@Account', 'vat_acc_id', '', 'number', null, 'required');
            ?>
            <button type="submit" style="background:#2ecc71; color:white; border:none; padding:12px; border-radius:4px; cursor:pointer; font-weight:bold; grid-column: span 4;">
                ➕ <?php echo lang('@Add VAT Code'); ?>
            </button>
        </div>
    </form>
    <?php
    htm_Card_end();
    echo "</div>";
echo "</div>";
htm_Footer();
?>