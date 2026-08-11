<?php # /vat_codes.php v:1.0.0 d:2026-07-07 i:claude (Opdateret til at bruge htm_ConfirmLink)
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php'; 
require 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

// Håndter sletning
if (isset($_GET['delete'])) {
    $id = DB::real_escape_string($conn, $_GET['delete']);
    DB::query($conn, "DELETE FROM vat_codes WHERE vat_id = '$id'");
    header("Location: vat_codes.php"); exit;
}

htm_Header('@VAT Codes');
showMenu();

echo "<div style='max-width:800px; margin:0 auto;'>";
    
    // Oversigt
    htm_Card_('@VAT Codes', '600');
    echo "<table style='width:100%; border-collapse:collapse;'>";
    echo "<tr style='background:#f8f9fa; border-bottom:2px solid #eee;'>
            <th style='padding:10px; text-align:left;'>ID</th>
            <th style='padding:10px; text-align:left;'>".lang('@Name')."</th>
            <th style='padding:10px; text-align:center;'>%</th>
            <th style='padding:10px; text-align:right;'>".lang('@Account')."</th>
            <th style='padding:10px; text-align:right;'></th>
          </tr>";

    $res = DB::query($conn, "SELECT * FROM vat_codes ORDER BY vat_id ASC");
    while ($row = DB::fetch_assoc($res)) {
        // Håndter manglende vat_acc_id sikkert
        $acc_id = $row['vat_acc_id'] ?? '-'; 

        // Erstattet det hårdkodede, danske "Slet?" onclick-confirm (uden om
        // lang()) med htm_ConfirmLink, som bruger '@Are you sure?' og
        // escaper teksten korrekt.
        $delBtn = htm_ConfirmLink(
            icon: 'fa-trash',
            link: '?delete='.$row['vat_id'],
            mess: '@Are you sure?',
            type: 'danger',
            styl: 'padding:0; background:transparent; color:var(--color-danger); font-size:16px;',
            echo: false
        );

        echo "<tr style='border-bottom:1px solid #eee;'>
                <td style='padding:10px;'><b>{$row['vat_id']}</b></td>
                <td style='padding:10px;'>{$row['vat_name']}</td>
                <td style='padding:10px; text-align:center;'>{$row['vat_rate']}%</td>
                <td style='padding:10px; text-align:right;'>{$acc_id}</td>
                <td style='padding:10px; text-align:right;'>"
                    . $delBtn .
                "</td>
              </tr>";
    }
    echo "</table>";
    htm_Card_end();

    // Formular til at tilføje ny
    echo "<div style='margin-top:20px;'>";
    htm_Card_('@Add New VAT Code', '600');
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
