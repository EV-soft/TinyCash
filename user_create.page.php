<?php # /user_create.page.php v:0.8.0 d:2026-04-11 i:evs m:1
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$msg = ""; $err = "";

// --- 1. HÅNDTER OPRETTELSE (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $role     = mysqli_real_escape_string($conn, $_POST['user_role']);
    $pw1      = $_POST['password'];
    $pw2      = $_POST['confirm_password'];

    if (empty($username) || empty($pw1)) {
        $err = lang('@All fields are required');
    } elseif ($pw1 !== $pw2) {
        $err = lang('@Passwords do not match');
    } else {
        $check = mysqli_query($conn, "SELECT user_id FROM users WHERE username = '$username'");
        if (mysqli_num_rows($check) > 0) {
            $err = lang('@Username already exists');
        } else {
            $hash = password_hash($pw1, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (username, password_hash, user_role) 
                    VALUES ('$username', '$hash', '$role')";
            
            if (mysqli_query($conn, $sql)) {
                header("Location: user_list.page.php?msg=user_created");
                exit;
            } else {
                $err = "DB Error: " . mysqli_error($conn);
            }
        }
    }
}

htm_Header(lang('@Create New User'));
showMenu();

// --- 2. BRUG DIN EKSISTERENDE ALERT FUNKTION ---
if (!empty($err)) {
    // Vi kalder din centrale alert funktion fra biblioteket
    htm_Alert($err); 
}

htm_Card_(lang('@New User Account'), '400');
?>

<form method="post">
    <?php
    echo htm_InputGroup('', lang('@Username'), 'username', '', 'text');
    $role_options = ['user' => lang('@User'), 'admin' => lang('@Administrator')];
    echo htm_InputGroup('', lang('@Role'), 'user_role', 'user', 'select', $role_options);
    echo "<hr style='margin:25px 0; border:0; border-top:1px dashed #ccc;'>";
    echo htm_InputGroup('', lang('@Password'), 'password', '', 'password');
    echo htm_InputGroup('', lang('@Confirm Password'), 'confirm_password', '', 'password');
    ?>

    <div style="margin-top:30px; display:flex; gap:10px;">
        <button type="submit" name="create_user" style="flex:2; padding:12px; font-weight:bold; cursor:pointer; border:none; border-radius:4px; background:#2ecc71; color:white;">
            👤 <?php echo lang('@Create User'); ?>
        </button>
        <a href="user_list.page.php" style="flex:1; background:#95a5a6; color:white; text-decoration:none; padding:12px; border-radius:4px; text-align:center; font-weight:bold; line-height:1.2;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php 
htm_Card_end();
htm_Footer(); 
ob_end_flush();
?>