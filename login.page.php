<?php # /login.page.php v:0.8.2 d:2026-04-11 i:evs m:1
ob_start();
session_start();

require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';

$error_msg = "";

// 1. Tjek for eksisterende brugere
$check_users = mysqli_query($conn, "SELECT COUNT(*) FROM users");
if ($check_users) {
    $user_count = mysqli_fetch_row($check_users)[0];
    if ($user_count == 0 && file_exists('user_create_admin.php')) {
        header("Location: user_create_admin.php");
        exit;
    }
}

// 2. Håndter Login-post
if (isset($_POST['login'])) {
    $initials = mysqli_real_escape_string($conn, trim($_POST['initials'] ?? ''));
    $pass     = $_POST['password'] ?? '';
    
    $ip = $_SERVER['REMOTE_ADDR'];
    $ua = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT']);
    $res = mysqli_query($conn, "SELECT * FROM users WHERE username = '$initials'");
    
    if ($res && $row = mysqli_fetch_assoc($res)) {
        if (password_verify($pass, $row['password_hash'])) {
            // Log success:
            mysqli_query($conn, "INSERT INTO login_log (user_id, logged_username, ip_address, status, user_agent) 
                                 VALUES ('".$row['user_id']."', '$initials', '$ip', 'Success', '$ua')");

            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['user_name'] = $row['username']; 
            $_SESSION['user_role'] = $row['user_role'];
            header("Location: index.page.php");
            exit;
        } else {
            mysqli_query($conn, "INSERT INTO login_log (logged_username, ip_address, status, user_agent) 
                                 VALUES ('$initials', '$ip', 'Failed', '$ua')");
            $error_msg = lang('@Invalid password');
        }
    } else {
        $error_msg = lang('@User not found');
    }
}

// 3. Generer HTML
htm_Header(lang('@Login'));
?>

<div style="display: flex; justify-content: center; align-items: center; min-height: 80vh; font-family: sans-serif;">
    <div style="width: 100%; max-width: 400px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
        <h2 style="text-align:center; margin-bottom: 25px;">
            <span style="color:#3498db;">Tiny</span>Cash <?php echo lang('@Login'); ?>
        </h2>
        <?php if ($error_msg): ?>
            <div style="background: #fee; color: #c33; padding: 10px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #fcc; font-size: 0.9em; text-align: center;">
                <i class="fa fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
            </div>
        <?php endif; ?>
        <form action="login.page.php" method="post">
        <?php 
        htm_InputGroup(
            icon:  'fa-user', 
            label: lang('@Initials'), 
            name:  'initials', 
            extra: 'required autofocus'
        ); 
        htm_InputGroup(
            icon:  'fa-lock', 
            label: lang('@Password'), 
            name:  'password', 
            type:  'password', 
            extra: 'required'
        ); 
        ?>
            <div style="margin-top: 25px;">
                <button type="submit" name="login" class="btn-primary" style="width:100%; padding:12px; font-size: 1em; letter-spacing: 0.5px; cursor:pointer;">
                    <?php echo lang('@Sign In'); ?> <i class="fa fa-sign-in-alt"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<?php htm_Footer(); ?>