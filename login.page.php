<?php # login.page.php
ob_start();
session_start();
require 'db_connect.inc.php';
require 'php2htm.lib.php';

$error_msg = "";

if (isset($_POST['login'])) {
    $initials = mysqli_real_escape_string($conn, trim($_POST['initials']));
    $pass     = $_POST['password'];

    // 1. Vi søger i 'username' kolonnen
    $res = mysqli_query($conn, "SELECT * FROM users WHERE username = '$initials'");
    
    if ($row = mysqli_fetch_assoc($res)) {
        /* 
         echo "Indtastet: " . $pass . "<br>";
         echo "I databasen: " . $row['password_hash'] . "<br>";
         if (password_verify($pass, $row['password_hash'])) { echo "MATCH!"; } else { echo "FEJL!"; }
         exit; // Stop her så vi kan se teksten
         */
        // 2. RETTELSE: Vi bruger 'password_hash' i stedet for 'user_password'
        if (password_verify($pass, $row['password_hash'])) {
            
            $_SESSION['user_id'] = $row['user_id'];
            // 3. RETTELSE: Vi bruger 'username' i stedet for 'user_name'
            $_SESSION['user_name'] = $row['username']; 
            $_SESSION['user_role'] = $row['user_role'];
            header("Location: index.page.php");
            exit;
            // 4. RETTELSE: Vi fjerner UPDATE af 'last_login', da kolonnen ikke findes
            // (Hvis du vil have den, skal den oprettes i databasen først)
            
            header("Location: index.page.php");
            exit;
        } else {
            $error_msg = lang('@Invalid password');
        }
    } else {
        $error_msg = lang('@User not found');
    }
}

htm_Header(lang('@Login'));
?>

<div style="display: flex; justify-content: center; align-items: center; min-height: 80vh; font-family: sans-serif;">
    <div style="width: 100%; max-width: 400px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
        <h2 style="text-align:center;"><?php echo '<span style="color:#3498db;">Tiny</span>Cash '.lang('@Login'); ?></h2>
        <?php if ($error_msg) echo "<p style='color:red;'>$error_msg</p>"; ?>
        <form action="" method="post">
            <input type="text" name="initials" placeholder="<?php echo lang('@Initials'); ?>" required style="width:100%; padding:10px; margin-bottom:10px;">
            <input type="password" name="password" placeholder="<?php echo lang('@Password'); ?>" required style="width:100%; padding:10px; margin-bottom:10px;">
            <button type="submit" name="login" style="width:100%; padding:10px; background:#3498db; color:white; border:none;"><?php echo lang('@Sign In'); ?></button>
        </form>
    </div>
</div>

<?php htm_Footer(); ?>