<?php # /user_edit.page.php v:0.8.5 d:2026-04-05 i:evs m:1
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = ""; $err = "";

// 1. Gem-logik
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_user'])) {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $role     = mysqli_real_escape_string($conn, $_POST['user_role']);
    $pw1      = $_POST['new_password'];
    $pw2      = $_POST['confirm_password'];

    $sql = "UPDATE users SET username='$username', user_role='$role' WHERE user_id=$edit_id";
    
    if (mysqli_query($conn, $sql)) {
        $msg = lang('@User updated successfully');
        if (!empty($pw1)) {
            if ($pw1 !== $pw2) { $err = lang('@Passwords do not match'); }
            else {
                $hash = password_hash($pw1, PASSWORD_DEFAULT);
                mysqli_query($conn, "UPDATE users SET password_hash='$hash' WHERE user_id=$edit_id");
                $msg .= " + " . lang('@Password changed');
            }
        }
    } else { $err = mysqli_error($conn); }
}

// 2. Hent data
$res = mysqli_query($conn, "SELECT * FROM users WHERE user_id = $edit_id");
$u = mysqli_fetch_assoc($res);
if (!$u) { die("Bruger findes ikke."); }

htm_Header(lang('@Edit User'));
showMenu();

htm_Card_(lang('@User Settings'), '400');
?>

<form method="post">
    <?php
    // Bemærk: htm_InputGroup('', $label, $name, $val, $type, $opt)
    echo htm_InputGroup('', lang('@Username'), 'username', $u['username'], 'text');
    
    $role_options = ['admin' => 'Administrator', 'user' => 'User'];
    echo htm_InputGroup('', lang('@Role'), 'user_role', $u['user_role'], 'select', $role_options);

    echo "<hr style='margin:20px 0; border:none; border-top:1px dashed #ddd;'>";
    
    echo htm_InputGroup('', lang('@New Password'), 'new_password', '', 'password');
    echo htm_InputGroup('', lang('@Confirm Password'), 'confirm_password', '', 'password');
    ?>

    <div style="margin-top:20px; display:flex; gap:10px;">
        <button type="submit" name="save_user" class="btn-success" style="flex:2; padding:12px; background:#2ecc71; color:white; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">💾 Gem</button>
        <a href="user_list.page.php" style="flex:1; background:#95a5a6; color:white; text-decoration:none; padding:12px; border-radius:4px; text-align:center; font-weight:bold;">Annuller</a>
    </div>
</form>

<?php 
    htm_Card_end(); 
    htm_Footer(); 
    ob_end_flush(); 
?>