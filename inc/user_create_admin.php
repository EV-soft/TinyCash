<?php # /inc/user_create_admin.php v:1.3.0 d:2026-08-30 i:evs
// Da denne fil nu bliver "required" fra login.php, behøver vi ikke session_start eller db_connect igen.
require_once 'inc/db_connect.inc.php'; 
require_once 'inc/php2htm.lib.php';
$message = "";

// 1. Tjek om der allerede findes en admin
$check = DB::query($conn, "SELECT user_id FROM users WHERE user_role = 'admin' LIMIT 1");
$adminExists = ($check && DB::num_rows($check) > 0);

// 2. Logik: Opret administratoren
// RETTET (§bugs-batch-22-review): kaldes fra login.php (som IKKE inkluderer
// inc/auth.inc.php, der ellers håndhæver CSRF-tjekket globalt for alle
// andre logget-ind sider) - tjekkes derfor eksplicit her.
if (isset($_POST['create_admin']) && !$adminExists && !csrf_verify()) {
    http_response_code(403);
    die(lang('@Security check failed (CSRF). Please reload the page and try again.'));
}
if (isset($_POST['create_admin']) && !$adminExists) {
    $initials = DB::real_escape_string($conn, trim($_POST['username']));
    $pw = $_POST['password'] ?? '';

    // RETTET (§bugs-batch-20-review): denne side - selve den ALLERFØRSTE
    // adgangskode i systemet, på den ALLERFØRSTE admin-konto - havde INTET
    // server-side tjek overhovedet, hverken for tomt felt eller mindstelængde
    // (kun formularens "required"-attribut, som et håndlavet POST frit kan
    // omgå). "password=" (tom streng) ville stille hashe og gemme en gyldig,
    // tom adgangskode for den nyoprettede admin. Samme 8-tegns mindstekrav
    // som user_create.php/user_edit.php (se dem for baggrund).
    if (trim($initials) === '' || strlen($pw) < 8) {
        $message = "<div style='background:#f8d7da; color:#721c24; padding:15px; border-radius:4px; margin-bottom:20px;'>
                    ❌ " . lang('@Password must be at least 8 characters long') . "</div>";
        // $adminExists forbliver false her, så formularen vises igen.
    } else {
        $pass_hash = password_hash($pw, PASSWORD_DEFAULT);
        $role = 'admin';
        // RETTET (§bugs-batch-15-review): samme fund som [[user-role-level-sync-fix]]
        // (user_create.php/user_edit.php), men i den EGENTLIGE første-admin-
        // bootstrap-side, som blev overset dengang. user_level manglede helt her -
        // kolonnens standardværdi (1) betød, at selve den allerførste admin på en
        // helt frisk installation blev oprettet med user_role='admin' men
        // user_level=1, og dermed reelt afvist af ethvert $rLev-baseret adgangstjek
        // i resten af appen (kontoplan, filbrowser, backup, indstillinger...),
        // selvom brugerlisten (som tjekker user_role direkte) ville vise dem som
        // admin. Kun init_demo_data.php's hårdkodede demo-admin havde begge felter
        // korrekte - denne, den RIGTIGE første-gangs-opsætningsside, gjorde ikke.
        $level = role_to_level($role);
        $sql = "INSERT INTO users (username, password_hash, user_role, user_level)
                VALUES ('$initials', '$pass_hash', '$role', $level)";

        if (DB::query($conn, $sql)) {
            $message = "<div style='background:#d4edda; color:#155724; padding:15px; border-radius:4px; margin-bottom:20px;'>
                        <strong>✅ " . lang('@Admin created successfully!') . "</strong><br>" .
                        lang('@You can now log in to the system.') . "
                        <br><br><a href='login.php' style='color:#155724; font-weight:bold;'>" . lang('@Go to Login') . "</a>
                       </div>";
            $adminExists = true;
        } else {
            $message = "<div style='background:#f8d7da; color:#721c24; padding:15px; border-radius:4px; margin-bottom:20px;'>
                        ❌ " . lang('@Error') . ": " . DB::error($conn) . "</div>";
        }
    }
}

htm_Header(lang('@Setup Administrator'));
?>

<div style="max-width: 500px; margin: 50px auto; font-family: sans-serif;">
    <div style="background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
        <h2 style="margin-top: 0; color: #2c3e50;"><?php echo lang('@Create Initial Admin'); ?></h2>
        <?php echo $message; ?>
        <?php if (!$adminExists): ?>
            <form action="login.php" method="post">
                <?php csrf_field(); ?>
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;"><?php echo lang('@Initials (Username)'); ?>:</label>
                    <input type="text" name="username" required placeholder="f.eks. tcAdmin" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; box-sizing: border-box;">
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
                    <a href="login.php"><?php echo lang('@Back to Login'); ?></a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php htm_Footer(); ?>
