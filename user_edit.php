<?php # /user_edit.php v:1.3.0 d:2026-08-30 i:evs
# KRITISK: rolleskift til Administrator opdaterede ALDRIG det egentlige adgangsniveau - bruger-rapporteret
# v1.2.0: gem-logikken opdaterede kun user_role, aldrig user_level - men ALT
# $rLev-tjek i inc/auth.inc.php bruger user_level, ikke user_role. En bruger
# forfremmet til "Administrator" her forblev derfor fastlåst på sit
# oprindelige niveau (typisk 1) på hver eneste niveau-3-spærret side i
# appen, selvom rolle-feltet viste "Administrator". Rettet med
# role_to_level() (inc/db_connect.inc.php). Se [[user-role-level-sync-fix]].
# v1.0.0: KRITISK - denne side havde INTET adgangstjek overhovedet. Enhver
# logget-ind bruger, uanset niveau, kunne redigere en HVILKEN SOM HELST
# brugers rolle og adgangskode via ?id=X - fuld kontoovertagelse, inkl. den
# rigtige administrators. Tilføjet samme admin-rolletjek som user_list.php
# allerede havde. Fundet ved en adgangskontrol-gennemgang.
ob_start();
require_once 'inc/php2htm.lib.php';
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/audit.inc.php';

$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Sikkerhed: kun admins må redigere brugere (samme tjek som user_list.php).
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
$msg = ""; $err = "";

// 1. Gem-logik
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_user'])) {
    $username = DB::escape($conn, $_POST['username']);
    $role     = DB::escape($conn, $_POST['user_role']);
    $level    = role_to_level($_POST['user_role']);
    $pw1      = $_POST['new_password'];
    $pw2      = $_POST['confirm_password'];

    // Hent nuværende rolle/brugernavn FØR opdatering, til revisionssporet -
    // en rolleændring (fx forfremmelse til admin) er en sikkerhedsrelevant
    // handling, der før slet ikke blev logget. Bruger-anmodet.
    $before_res = DB::query($conn, "SELECT username, user_role, user_level FROM users WHERE user_id = $edit_id");
    $before_row = $before_res ? DB::fetch_assoc($before_res) : null;

    // RETTET: manglede at opdatere user_level i takt med user_role -
    // rollen ("Administrator" osv.) blev gemt, men det egentlige numeriske
    // adgangsniveau, som alt $rLev-tjek i appen reelt bruger, forblev
    // urørt (typisk fastlåst på 1 siden brugeren blev oprettet). Se
    // role_to_level() i inc/db_connect.inc.php for baggrunden.
    $sql = "UPDATE users SET username='$username', user_role='$role', user_level=$level WHERE user_id=$edit_id";

    if (DB::query($conn, $sql)) {
        $msg = lang('@User updated successfully');
        $password_changed = false;
        if (!empty($pw1)) {
            if ($pw1 !== $pw2) { $err = lang('@Passwords do not match'); }
            // RETTET (§bugs-batch-20-review): se user_create.php for samme fund
            // - denne side havde INTET mindstekrav til en ny adgangskode ved
            // skift af en eksisterende brugers kodeord.
            elseif (strlen($pw1) < 8) { $err = lang('@Password must be at least 8 characters long'); }
            else {
                $hash = password_hash($pw1, PASSWORD_DEFAULT);
                DB::query($conn, "UPDATE users SET password_hash='$hash' WHERE user_id=$edit_id");
                $msg .= " + " . lang('@Password changed');
                $password_changed = true;
            }
        }
        // Log kun hvis noget reelt ændrede sig (rolle/brugernavn/kodeord) -
        // ikke ved et "gem" der reelt ikke rørte noget. Selve kodeordet
        // logges aldrig, kun AT det blev ændret.
        if ($before_row && ($before_row['username'] !== $_POST['username'] || $before_row['user_role'] !== $_POST['user_role'] || (int)$before_row['user_level'] !== $level || $password_changed)) {
            log_action($conn, 'UPDATE_USER', 'users', $edit_id,
                ['username' => $before_row['username'], 'user_role' => $before_row['user_role'], 'user_level' => (int)$before_row['user_level']],
                ['username' => $_POST['username'], 'user_role' => $_POST['user_role'], 'user_level' => $level, 'password_changed' => $password_changed]);
        }
    } else { $err = DB::error($conn); }
}

// 2. Hent data
$res = DB::query($conn, "SELECT * FROM users WHERE user_id = $edit_id");
$u = DB::fetch_assoc($res);
if (!$u) { die(lang("@User not found.")); }

htm_Header(capt: '@Edit User');
showMenu();

if($msg) htm_Alert(text: $msg, type: 'success');
if($err) htm_Alert(text: $err, type: 'error');

// 3. Udnyt htm_Card_ med indbygget form
htm_Card_(
    capt: '@User Settings', 
    wdth: 450, 
    form: 'post'
);

htm_Field(icon: 'fa-user', labl: '@Username', name: 'username', valu: $u['username']);
    
    // Definer alle tilgængelige roller i systemet én gang
    $role_options = [
        'admin'      => 'Administrator',    // Program admin
        'user'       => 'User',             // Alm. bruger
        'accountant' => 'Accountant'        // Revisor
    ];

    // Vis dropdown-menuen én enkelt gang
    htm_Field(
        icon: 'fa-user-tie', 
        labl: '@Role', 
        name: 'user_role', 
        valu: $u['user_role'], 
        type: 'sele', 
        opti: $role_options
    ); 

    echo "<hr style='margin:25px 0; border:none; border-top:1px dashed #ddd;'>";    echo "<h4 style='color:#7f8c8d; margin-bottom:10px;'>" . lang('@Change Password') . "</h4>";
    
    htm_Field(icon: 'fa-lock', labl: '@New Password', name: 'new_password', valu: '', type: 'password', plho: '******');
    htm_Field(icon: 'fa-check-double', labl: '@Confirm Password', name: 'confirm_password', valu: '', type: 'password', plho: '******');

    // 4. Knapper via cont
    // Vi samler Gem og Annuller i samme div via cont-variablen
    htm_Button(
        icon: 'fa-save',
        labl: '@Save User',
        type: 'success',
        attr: 'name="save_user" data-hint="'.lang('@Save changes to this user account').'"',
        styl: 'flex:2; padding:12px; font-weight:bold;',
        cont: '<div style="margin-top:25px; display:flex; gap:10px; border-top:1px solid #eee; padding-top:20px;">' .
              htm_Button(icon: 'fa-times', labl: '@Cancel', type: 'secondary', link: 'user_list.php', styl: 'flex:1; padding:12px;', attr: 'data-hint="'.lang('@Discard changes and return to the user list').'"') .
              '</div>'
    );

htm_Card_end(); 

htm_Footer(); 
ob_end_flush();
?>