<?php # bruger_rediger.page.php
ob_start(); 
require 'auth.inc.php';
require 'db_connect.inc.php';
require 'php2htm.lib.php'; 
require 'menu.inc.php';

// Kun admins må rette brugere
if ($_SESSION['user_role'] !== 'admin') {
    die(lang('@Access denied'));
}

// 1. Logik: Håndter opdatering
if (isset($_POST['update_user'])) {
    $id    = (int)$_POST['user_id'];
    $name  = mysqli_real_escape_string($conn, $_POST['user_name']);
    $email = mysqli_real_escape_string($conn, $_POST['user_email']);
    $role  = mysqli_real_escape_string($conn, $_POST['user_role']);
    
    // Opdater basis-info
    $sql = "UPDATE users SET user_name = '$name', user_email = '$email', user_role = '$role' WHERE user_id = $id";
    mysqli_query($conn, $sql);

    // Hvis der er skrevet en ny adgangskode
    if (!empty($_POST['new_password'])) {
        $pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE users SET user_password = '$pass' WHERE user_id = $id");
    }

    header("Location: bruger_liste.page.php?msg=user_updated");
    exit;
}

// 2. Data: Hent brugeren
$id = (int)$_GET['id'];
$res = mysqli_query($conn, "SELECT * FROM users WHERE user_id = $id");
$u = mysqli_fetch_assoc($res);

if (!$u) die(lang('@User not found'));

htm_Header(lang('@Edit User'));
showMenu();

htm_Card_(lang('@User Account Details') . ": " . htmlspecialchars($u['user_name']));
?>

<form action="" method="post" style="font-family: sans-serif; max-width: 500px;">
    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Username'); ?>:</label>
        <input type="text" name="user_name" value="<?php echo htmlspecialchars($u['user_name']); ?>" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Email Address'); ?>:</label>
        <input type="email" name="user_email" value="<?php echo htmlspecialchars($u['user_email']); ?>" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
    </div>

    <div style="margin-bottom: 15px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@User Role'); ?>:</label>
        <select name="user_role" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            <option value="user" <?php if($u['user_role'] == 'user') echo 'selected'; ?>><?php echo lang('@Standard User'); ?></option>
            <option value="admin" <?php if($u['user_role'] == 'admin') echo 'selected'; ?>><?php echo lang('@Administrator'); ?></option>
        </select>
    </div>

    <div style="margin-bottom: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
        <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@New Password'); ?>:</label>
        <input type="password" name="new_password" placeholder="<?php echo lang('@Leave blank to keep current'); ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        <small style="color: #856404;"><?php echo lang('@Only fill this out if you want to change the password'); ?></small>
    </div>

    <div style="display: flex; gap: 10px;">
        <button type="submit" name="update_user" style="background:#3498db; color:white; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">
            💾 <?php echo lang('@Save Changes'); ?>
        </button>
        <a href="bruger_liste.page.php" style="background:#95a5a6; color:white; padding:10px 20px; text-decoration:none; border-radius:4px; text-align:center;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush(); 
?>