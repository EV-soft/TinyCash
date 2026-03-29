<?php # bruger_opret_admin.php
require 'db_connect.inc.php'; 
require 'php2htm.lib.php';

$message = "";

// 1. Tjek om der allerede findes en admin i 'user_role' kolonnen
$check = mysqli_query($conn, "SELECT user_id FROM users WHERE user_role = 'admin' LIMIT 1");
$adminExists = (mysqli_num_rows($check) > 0);

// 2. Logik: Opret administratoren med de rigtige kolonnenavne
if (isset($_POST['create_admin']) && !$adminExists) {
    // Vi bruger 'username' til initialerne
    $initials = mysqli_real_escape_string($conn, trim($_POST['username']));
    // Vi bruger 'password_hash' til det krypterede kodeord
    $pass_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = 'admin';

    $sql = "INSERT INTO users (username, password_hash, user_role) 
            VALUES ('$initials', '$pass_hash', '$role')";

    if (mysqli_query($conn, $sql)) {
        $message = "<div style='background:#d4edda; color:#155724; padding:15px; border-radius:4px; margin-bottom:20px;'>
                    <strong>✅ " . lang('@Admin created successfully!') . "</strong><br>" . 
                    lang('@You can now log in to the system.') . "
                    <br><br><a href='login.page.php' style='color:#155724; font-weight:bold;'>" . lang('@Go to Login') . "</a>
                   </div>";
        $adminExists = true; 
    } else {
        $message = "<div style='background:#f8d7da; color:#721c24; padding:15px; border-radius:4px; margin-bottom:20px;'>
                    ❌ " . lang('@Error') . ": " . mysqli_error($conn) . "</div>";
    }
}

htm_Header(lang('@Setup Administrator'));
?>

<div style="max-width: 500px; margin: 50px auto; font-family: sans-serif;">
    <div style="background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
        
        <h2 style="margin-top: 0; color: #2c3e50;"><?php echo lang('@Create Initial Admin'); ?></h2>
        
        <?php echo $message; ?>

        <?php if (!$adminExists): ?>
            <form action="" method="post">
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Initials (Username)'); ?>:</label>
                    <input type="text" name="username" required placeholder="f.eks. evs" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 25px;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Password'); ?>:</label>
                    <input type="password" name="password" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; box-sizing: border-box;">
                </div>

                <button type="submit" name="create_admin" style="width:100%; background:#3498db; color:white; padding:12px; border:none; border-radius:4px; cursor:pointer; font-weight:bold; font-size: 1.1em;">
                    🚀 <?php echo lang('@Create Admin'); ?>
                </button>
            </form>
        <?php else: ?>
            <?php if (!$message): ?>
                <div style="text-align: center; padding: 20px; color: #e67e22; border: 1px solid #fbeed5; background: #fcf8e3; border-radius: 4px;">
                    <strong><?php echo lang('@Access Restricted'); ?></strong><br>
                    <?php echo lang('@An administrator already exists.'); ?>
                    <br><br>
                    <a href="login.page.php"><?php echo lang('@Back to Login'); ?></a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php htm_Footer(); ?>