<?php # /invoice_actions.php v:0.9.1 d:2026-05-07 i:evs
# Called from: product_edit.page.php 
require 'inc/db_connect.inc.php';
require 'inc/auth.inc.php';

// Vi bruger både GET og POST afhængigt af handlingen
$action = $_REQUEST['action'] ?? '';

// Hjælpefunktioner
function clean($conn, $data) {
    return mysqli_real_escape_string($conn, trim($data));
}

function clean_amount($data) {
    $value = str_replace(',', '.', $data); 
    return (float)$value;
}

switch ($action) {
    // --- PRODUKT / LAGER HANDLINGER ---
    case 'create_product':
        $name  = clean($conn, $_POST['prod_name']);
        $stock = (int)$_POST['prod_stock'];
        $min   = (int)($_POST['prod_min_stock'] ?? 5); // Hent min_stock (default 5)
        $price = clean_amount($_POST['prod_price']);
        $sql = "INSERT INTO products (prod_name, prod_stock, prod_min_stock, prod_price) 
                VALUES ('$name', $stock, $min, $price)";
        if (mysqli_query($conn, $sql)) {
            header("Location: inventory_status.php?msg=created");
        } else {
            die(lang("@Error creating product: ") . mysqli_error($conn));
        }
        break;

    case 'update_product':
        $id    = (int)$_POST['prod_id'];
        $name  = clean($conn, $_POST['prod_name']);
        $stock = (int)$_POST['prod_stock'];
        $min   = (int)($_POST['prod_min_stock'] ?? 5); // Hent min_stock fra formen
        $price = clean_amount($_POST['prod_price']);
        $sql = "UPDATE products SET 
                prod_name = '$name', 
                prod_stock = $stock, 
                prod_min_stock = $min, 
                prod_price = $price 
                WHERE prod_id = $id";
        if (mysqli_query($conn, $sql)) {
            header("Location: inventory_status.php?msg=updated");
        } else {
            die("Error updating product: " . mysqli_error($conn));
        }
        break;

    case 'delete_product':
        $id = (int)$_GET['id'];
        // Tjek om varen er brugt på fakturalinjer
        $check = mysqli_query($conn, "SELECT line_id FROM invoice_lines WHERE prod_id = $id LIMIT 1");
        if (mysqli_num_rows($check) > 0) {
            header("Location: inventory_status.php?msg=error_in_use");
        } else {
            mysqli_query($conn, "DELETE FROM products WHERE prod_id = $id");
            header("Location: inventory_status.php?msg=deleted");
        }
        break;

    // --- KUNDE HANDLINGER ---
    case 'save_customer':
        $name    = clean($conn, $_POST['cust_name']);
        $addr    = clean($conn, $_POST['cust_address']);
        $cvr     = clean($conn, $_POST['cust_cvr'] ?? '');
        $email   = clean($conn, $_POST['cust_email'] ?? '');
        $paydays = (int)($_POST['cust_payment_days'] ?? 8);
        $sql = "INSERT INTO customers (cust_name, cust_address, cust_cvr, cust_email, cust_payment_days) 
                VALUES ('$name', '$addr', '$cvr', '$email', $paydays)";
        mysqli_query($conn, $sql);
        header("Location: customer_list.php?msg=created");
        break;

    // --- FAKTURA HANDLINGER ---
    case 'slet_faktura':
        $inv_id = (int)$_GET['id'];
        // 1. Før lageret tilbage
        $res_lines = mysqli_query($conn, "SELECT prod_id, quantity FROM invoice_lines WHERE inv_id = $inv_id");
        if ($res_lines) {
            while ($line = mysqli_fetch_assoc($res_lines)) {
                $p_id = (int)$line['prod_id'];
                $qty  = (float)$line['quantity'];
                if ($p_id > 0) {
                    mysqli_query($conn, "UPDATE products SET prod_stock = prod_stock + $qty WHERE prod_id = $p_id");
                }
            }
        }
        // 2. Slet data (linjer først pga. relationer)
        mysqli_query($conn, "DELETE FROM invoice_lines WHERE inv_id = $inv_id");
        if (mysqli_query($conn, "DELETE FROM invoices WHERE inv_id = $inv_id")) {
            header("Location: invoices.php?msg=slettet");
        }
        break;

    case 'update_status':
        $id     = (int)$_GET['id'];
        $status = clean($conn, $_GET['status']); 
        mysqli_query($conn, "UPDATE invoices SET inv_status = '$status' WHERE inv_id = $id");
        header("Location: invoices.php?msg=status_opdateret");
        break;

    default:
        header("Location: index.php");
        break;
}