<?php # faktura_gem.php
require 'auth.inc.php';
require 'db_connect.inc.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cust_id      = (int)$_POST['cust_id'];
    $inv_date     = mysqli_real_escape_string($conn, $_POST['inv_date']);
    $inv_due_date = mysqli_real_escape_string($conn, $_POST['inv_due_date']);
    
    // 1. Find næste ledige fakturanummer
    $num_res = mysqli_query($conn, "SELECT MAX(invoice_no) AS max_no FROM invoices");
    $num_row = mysqli_fetch_assoc($num_res);
    $next_no = ($num_row['max_no'] ?? 0) + 1;

    // 2. Opret faktura-hovedet
    $sql_inv = "INSERT INTO invoices (invoice_no, cust_id, inv_date, inv_due_date, inv_status) 
                VALUES ($next_no, $cust_id, '$inv_date', '$inv_due_date', 'sent')";
    
    if (mysqli_query($conn, $sql_inv)) {
        $new_inv_id = mysqli_insert_id($conn);

        // 3. Håndter varelinjer og lager-opdatering
        if (isset($_POST['line_prod'])) {
            foreach ($_POST['line_prod'] as $key => $p_id) {
                $p_id  = (int)$p_id;
                $qty   = (float)$_POST['line_qty'][$key];
                $price = (float)str_replace(',', '.', $_POST['line_price'][$key]);
                
                if ($p_id > 0 && $qty > 0) {
                    // Hent produktnavn til line_text (valgfrit, men godt for historikken)
                    $p_info = mysqli_query($conn, "SELECT prod_name FROM products WHERE prod_id = $p_id");
                    $p_data = mysqli_fetch_assoc($p_info);
                    $p_name = mysqli_real_escape_string($conn, $p_data['prod_name']);

                    // A: Indsæt varelinjen
                    $sql_line = "INSERT INTO invoice_lines (inv_id, prod_id, line_text, quantity, price_each, vat_rate) 
                                 VALUES ($new_inv_id, $p_id, '$p_name', $qty, $price, 25.00)";
                    mysqli_query($conn, $sql_line);

                    // B: OPDATER LAGER (Nedskrivning)
                    // Her bruger vi dit felt 'prod_stock' fra tabellen 'products'
                    $sql_stock = "UPDATE products 
                                  SET prod_stock = prod_stock - $qty 
                                  WHERE prod_id = $p_id";
                    mysqli_query($conn, $sql_stock);
                }
            }
        }
        
        header("Location: fakturaer.page.php?msg=success&no=$next_no");
        exit;
    } else {
        die("Fejl ved oprettelse af faktura: " . mysqli_error($conn));
    }
}