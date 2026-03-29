<?php # faktura_actions.php - OPTIMERET UDGAVE
include('db_connect.inc.php');
include('auth.inc.php');

$action = $_GET['action'] ?? '';

// Hjælpefunktion til at rense input (Sikkerhed)
function clean($conn, $data) {
    return mysqli_real_escape_string($conn, trim($data));
}

// Ny hjælpefunktion til at gøre beløb regne-klare (håndterer komma)
function clean_amount($data) {
    $value = str_replace(',', '.', $data); // Skift komma ud med punktum
    return (float)$value;                  // Returnér som et rent tal
}

switch ($action) {

    // --- KUNDE HANDLINGER ---
    case 'gem_kunde':
        $name    = clean($conn, $_POST['cust_name']);
        $addr    = clean($conn, $_POST['cust_address']);
        $cvr     = clean($conn, $_POST['cust_cvr'] ?? '');
        $email   = clean($conn, $_POST['cust_email'] ?? '');
        $paydays = (int)($_POST['cust_payment_days'] ?? 8);
        
        $sql = "INSERT INTO customers (cust_name, cust_address, cust_cvr, cust_email, cust_payment_days) 
                VALUES ('$name', '$addr', '$cvr', '$email', $paydays)";
        
        if (mysqli_query($conn, $sql)) {
            header("Location: kunder.page.php?msg=oprettet");
        } else {
            die("Fejl ved oprettelse af kunde: " . mysqli_error($conn));
        }
        break;

    case 'opdater_kunde':
        $id      = (int)$_POST['cust_id'];
        $name    = clean($conn, $_POST['cust_name']);
        $addr    = clean($conn, $_POST['cust_address']);
        $cvr     = clean($conn, $_POST['cust_cvr'] ?? '');
        $email   = clean($conn, $_POST['cust_email'] ?? '');
        $paydays = (int)($_POST['cust_payment_days'] ?? 8);

        $sql = "UPDATE customers SET 
                cust_name = '$name', 
                cust_address = '$addr', 
                cust_cvr = '$cvr', 
                cust_email = '$email',
                cust_payment_days = $paydays 
                WHERE cust_id = $id";
        
        mysqli_query($conn, $sql);
        header("Location: kunder.page.php?msg=opdateret");
        break;

    // --- TRANSAKTIONER (BOGFØRING) ---
    case 'slet_transaktion':
        $id = (int)$_GET['id'];

        // 1. Hent data for at tjekke alder og bilag
        $res = mysqli_query($conn, "SELECT trans_date, attachment_path FROM transactions WHERE trans_id = $id");
        $trans = mysqli_fetch_assoc($res);

        if ($trans) {
            // 2. 30-dages reglen (Sikkerhed mod ulovlig sletning)
            $dato_postering = new DateTime($trans['trans_date']);
            $nu = new DateTime();
            $forskel = $nu->diff($dato_postering);

            if ($forskel->days > 30) {
                header("Location: transaktioner.page.php?msg=locked");
                exit;
            }

            // 3. Slet fysisk bilag
            if (!empty($trans['attachment_path']) && file_exists($trans['attachment_path'])) {
                unlink($trans['attachment_path']);
            }

            // 4. Slet i DB
            mysqli_query($conn, "DELETE FROM transactions WHERE trans_id = $id");
            header("Location: transaktioner.page.php?msg=slettet");
        } else {
            header("Location: transaktioner.page.php");
        }
        break;

    // --- SYSTEM & BRUGERE ---
    case 'opdater_bruger':
        $user_id = (int)$_POST['user_id'];
        $role    = clean($conn, $_POST['user_role']);
        $pass    = $_POST['password'] ?? '';

        if (!empty($pass)) {
            // Hvis der er skrevet en ny kode, skal den hashes
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET user_role = '$role', password_hash = '$hash' WHERE user_id = $user_id";
        } else {
            // Ellers opdaterer vi kun rollen
            $sql = "UPDATE users SET user_role = '$role' WHERE user_id = $user_id";
        }

        mysqli_query($conn, $sql);
        header("Location: bruger_liste.page.php?msg=opdateret");
        break;

    case 'slet_bruger':
        $id = (int)$_GET['id'];
        
        // Sikkerhed: Man må ikke slette sig selv!
        session_start();
        $res = mysqli_query($conn, "SELECT username FROM users WHERE user_id = $id");
        $u = mysqli_fetch_assoc($res);
        
        if ($u['username'] == $_SESSION['username']) {
            header("Location: bruger_liste.page.php?msg=selv_slet_fejl");
        } else {
            mysqli_query($conn, "DELETE FROM users WHERE user_id = $id");
            header("Location: bruger_liste.page.php?msg=slettet");
        }
        break;

        case 'opdater_vare':
                $id    = (int)$_POST['prod_id'];
                $name  = clean($conn, $_POST['prod_name']);
                $stock = (int)$_POST['prod_stock'];
                $price = (float)str_replace(',', '.', $_POST['prod_price']);

                $sql = "UPDATE products SET 
                        prod_name = '$name', 
                        prod_stock = $stock, 
                        prod_price = $price 
                        WHERE prod_id = $id";
                
                mysqli_query($conn, $sql);
                header("Location: lager_liste.page.php?msg=opdateret");
                break;


    default:
        header("Location: index.page.php");
        break;
}