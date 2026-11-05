<?php # /user_create.php v:1.3.0 d:2026-08-30 i:evs
# KRITISK: samme user_level-fund som user_edit.php - se [[user-role-level-sync-fix]]
# v1.2.0: INSERT satte kun user_role, aldrig user_level - enhver ny bruger
# blev født på niveau 1 (kolonnens standard) uanset valgt rolle. Rettet med
# role_to_level() (inc/db_connect.inc.php).
# v1.1.0: KRITISK - denne side havde INTET adgangstjek overhovedet. Enhver
# logget-ind bruger, uanset niveau, kunne oprette en helt ny bruger med
# user_role=admin direkte via formularen. Tilføjet samme admin-rolletjek som
# user_list.php allerede havde. Fundet ved en adgangskontrol-gennemgang.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

// Sikkerhed: kun admins må oprette brugere (samme tjek som user_list.php).
// RETTET: inc/menu.inc.php's "🆕 Opret første admin"- menupunkt (vist for enhver logget-ind bruger, når INGEN admin
// findes i systemet - fx hvis den eneste admin-konto er blevet slettet, mens andre brugere stadig findes) pegede
// fejlagtigt på user_edit.php?id=0, som er en ren REDIGERINGS-side (UPDATE... WHERE user_id=0 rammer ingen rækker,
// og GET-visningen dør med "User not found" FØR formularen overhovedet vises) - den kunne derfor ALDRIG bruges til
// at oprette noget, med eller uden adgang.
$admin_check = DB::query($conn, "SELECT user_id FROM users WHERE user_role = 'admin' LIMIT 1");
$no_admin_yet = !($admin_check && DB::num_rows($admin_check) > 0);
if (!$no_admin_yet && (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin')) {
    deny_access_gracefully();
}

$msg = ""; $err = "";

// --- 1. HÅNDTER OPRETTELSE (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    // RETTET: brugernavnet blev ikke trimmet (login trimmer - " admin" kunne aldrig logge ind), og dublet-tjekket var
    // versalfølsomt på SQLite: "Admin" kunne oprettes ved siden af "admin", og da låsningen efter 5 fejlforsøg tæller
    // uden hensyn til store/små bogstaver, kunne fejlforsøg på den ene låse den anden ude.
    $username = DB::escape($conn, trim((string)($_POST['username'] ?? '')));
    $role     = DB::escape($conn, $_POST['user_role']);
    $pw1      = $_POST['password'];
    $pw2      = $_POST['confirm_password'];

    if (empty($username) || empty($pw1)) {
        $err = lang('@All fields are required');
    } elseif ($pw1 !== $pw2) {
        $err = lang('@Passwords do not match');
    } elseif (strlen($pw1) < 8) {
        // RETTET: denne side (og user_edit.php samt inc/user_create_admin.php, se dem for samme fund) tjekkede kun
        // at adgangskoden ikke var TOM - en ny bruger, herunder en ny administrator, kunne sættes op med
        // adgangskoden "1" uden nogen advarsel. Kræver nu mindst 8 tegn - ingen kompleksitetsregler udover det,
        // for ikke unødigt at genindføre de forældede "skal indeholde stort+småt+tal+specialtegn"-krav NIST selv
        // har frarådet.
        $err = lang('@Password must be at least 8 characters long');
    } else {
        $check = DB::query($conn, "SELECT user_id FROM users WHERE LOWER(username) = LOWER('$username')");
        if (DB::num_rows($check) > 0) {
            $err = lang('@Username already exists');
        } else {
            $hash  = password_hash($pw1, PASSWORD_DEFAULT);
            // RETTET: manglede user_level - se role_to_level i inc/db_connect.inc.php og. Uden dette blev enhver
            // ny bruger født på user_level=1 (kolonnens standardværdi) uanset hvilken rolle der blev valgt her -
            // en ny "Administrator" havde reelt intet admin-niveau overhovedet.
            $level = role_to_level($_POST['user_role']);
            $sql = "INSERT INTO users (username, password_hash, user_role, user_level)
                    VALUES ('$username', '$hash', '$role', $level)";
            
            if (DB::query($conn, $sql)) {
                // RETTET: i selve bootstrap-tilfældet (den aktuelle bruger er ikke selv admin, men fik lov til at
                // oprette den allerførste admin ovenfor) peger dette ellers direkte på user_list.php - som
                // ØJEBLIKKELIGT ville afvise dem igen, fordi DERES session stadig er den oprindelige, ikke-admin
                // session. Send i stedet til login.php i det tilfælde, så de kan logge ind med den nye konto.
                $is_admin_session = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
                if ($is_admin_session) redirect('user_list.php', '@User created.');      // (msg=user_created blev aldrig vist)
                redirect("login.php?msg=admin_created");
            } else {
                $err = lang('@Error:') . ' ' . htmlspecialchars((string)DB::error($conn));
            }
        }
    }
}

htm_Header('@Create New User');
showMenu();

// --- 2. BRUG DIN EKSISTERENDE ALERT FUNKTION ---
if (!empty($err)) {
    // Vi kalder din centrale alert funktion fra biblioteket
    htm_Alert($err); 
}

htm_Card_('@New User Account', '400');
?>

<form method="post">
    <?php
    csrf_field();
    htm_Field('', lang('@Username'), 'username', '', 'text');
    // RETTET: 'select' er ikke et gyldigt type-nøgleord i htm_Field - kun det præcise 4-tegns 'sele' udløser en
    // rigtig <select>-rendering (se inc/php2htm.lib.php). Feltet faldt derfor hele tiden tilbage til en almindelig
    // tekstboks uden nogen dropdown overhovedet.
    $role_options = ['user' => lang('@User'), 'accountant' => lang('@Accountant'), 'admin' => lang('@Administrator')];
    htm_Field('', lang('@Role'), 'user_role', 'user', 'sele', $role_options);
    echo "<hr style='margin:25px 0; border:0; border-top:1px dashed #ccc;'>";
    htm_Field('', lang('@Password'), 'password', '', 'password');
    htm_Field('', lang('@Confirm Password'), 'confirm_password', '', 'password');
    ?>

    <div style="margin-top:30px; display:flex; gap:10px;">
        <button type="submit" name="create_user" style="flex:2; padding:12px; font-weight:bold; cursor:pointer; border:none; border-radius:4px; background:#2ecc71; color:white;">
            👤 <?php echo lang('@Create User'); ?>
        </button>
        <a href="user_list.php" data-hint="<?php echo lang('@Return to the user list without creating the user'); ?>" style="flex:1; background:#95a5a6; color:white; text-decoration:none; padding:12px; border-radius:4px; text-align:center; font-weight:bold; line-height:1.2;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php 
htm_Card_end();
htm_Footer(); 
ob_end_flush();
?>
