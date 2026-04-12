<?php # chart_of_accounts.page.php v:0.8.0 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php'; // Inkluderer nu automatisk php2htm.lib.php
require_once 'inc/menu.inc.php';

htm_Header(lang('@Chart of Accounts'));
showMenu();

echo "<div style='max-width:1000px; margin:0 auto;'>";

    // 1. Header and "Add New" button
    echo "<div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;'>";
        echo "<h2 style='margin:0; color:#2c3e50;'>📑 " . lang('@Chart of Accounts') . "</h2>";
        echo "<a href='account_edit.page.php?id=0' style='background:#2ecc71; color:white; text-decoration:none; padding:10px 20px; border-radius:4px; font-weight:bold;'>➕ " . lang('@Add New Account') . "</a>";
    echo "</div>";

    // Display status messages if any
    if (isset($_GET['msg']) && $_GET['msg'] == 'updated') {
        echo "<div style='background:#d4edda; color:#155724; padding:15px; border-radius:4px; margin-bottom:20px;'>✅ " . lang('@Account updated successfully') . "</div>";
    }

    htm_Card_(lang('@All Accounts'), '100%');

    echo "<table style='width:100%; border-collapse:collapse; font-family:sans-serif;'>";
        echo "<tr style='background:#f8f9fa; border-bottom:2px solid #eee; color:#7f8c8d; font-size:0.85em;'>";
            echo "<th style='padding:12px; text-align:left; width:80px;'>" . lang('@No.') . "</th>";
            echo "<th style='padding:12px; text-align:left;'>" . lang('@Account Name') . "</th>";
            echo "<th style='padding:12px; text-align:left;'>" . lang('@VAT Code') . "</th>";
            echo "<th style='padding:12px; text-align:center;'>" . lang('@Rate') . "</th>";
            echo "<th style='padding:12px; text-align:right;'>" . lang('@Actions') . "</th>";
        echo "</tr>";

        // Join with vat_codes to get name and rate
        $sql = "SELECT a.*, v.vat_name, v.vat_rate 
                FROM accounts a 
                LEFT JOIN vat_codes v ON a.vat_code = v.vat_id 
                ORDER BY a.acc_id ASC";
        
        $res = mysqli_query($conn, $sql);
        
        while ($row = mysqli_fetch_assoc($res)) {
            $vat_display = $row['vat_name'] ?? "<span style='color:#bdc3c7;'><i>" . lang('@None') . "</i></span>";
            $rate_display = isset($row['vat_rate']) ? $row['vat_rate'] . "%" : "-";
            
            // Row highlighting based on account type (Assets/Equity/Expenses)
            $row_bg = ($row['acc_id'] >= 2000) ? 'rgba(52, 152, 219, 0.03)' : 'transparent';

            echo "<tr style='border-bottom:1px solid #eee; background:$row_bg;'>";
                echo "<td style='padding:12px; font-weight:bold; color:#2c3e50;'>{$row['acc_id']}</td>";
                echo "<td style='padding:12px;'>{$row['acc_name']}</td>";
                echo "<td style='padding:12px; font-size:0.9em; color:#34495e;'>{$vat_display}</td>";
                echo "<td style='padding:12px; text-align:center; font-size:0.9em;'>$rate_display</td>";
                echo "<td style='padding:12px; text-align:right;'>";
                    echo "<a href='account_edit.page.php?id={$row['acc_id']}' style='text-decoration:none; margin-right:15px;' title='".lang('@Edit')."'>✏️</a>";
                    echo "<a href='account_delete.php?id={$row['acc_id']}' style='text-decoration:none;' title='".lang('@Delete')."' onclick='return confirm(\"".lang('@Are you sure?')."\")'>🗑️</a>";
                echo "</td>";
            echo "</tr>";
        }
    echo "</table>";

    htm_Card_end();
echo "</div>";

htm_Footer();
ob_end_flush();