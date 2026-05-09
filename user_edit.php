<?php # /user_edit.php v:0.9.1 d:2026-05-07 i:evs
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

    htm_InputGroup(icon: 'fa-user', labl: '@Username', name: 'username', valu: $u['username']);
    $role_options = [
    'admin'      => 'Administrator', 
    'user'       => 'User',
    'accountant' => 'Accountant'
    ];

    htm_InputGroup(
        icon: 'fa-user-tie', 
        labl: '@Role', 
        name: 'user_role', 
        valu: $u['user_role'], 
        type: 'sele', 
        opti: $role_options
    );

    $role_options = ['admin' => 'Administrator', 'user' => 'User'];
    htm_InputGroup(icon: 'fa-shield-halved', labl: '@Role', name: 'user_role', valu: $u['user_role'], type: 'sele', opti: $role_options);

    echo "<hr style='margin:25px 0; border:none; border-top:1px dashed #ddd;'>";
    echo "<h4 style='color:#7f8c8d; margin-bottom:10px;'>" . lang('@Change Password') . "</h4>";
    
    htm_InputGroup(icon: 'fa-lock', labl: '@New Password', name: 'new_password', valu: '', type: 'password', plho: '******');
    htm_InputGroup(icon: 'fa-check-double', labl: '@Confirm Password', name: 'confirm_password', valu: '', type: 'password', plho: '******');

    // 4. Knapper via cont
    // Vi samler Gem og Annuller i samme div via cont-variablen
    htm_Button(
        icon: 'fa-save',
        labl: '@Save User',
        type: 'success',
        attr: 'name="save_user"',
        styl: 'flex:2; padding:12px; font-weight:bold;',
        cont: '<div style="margin-top:25px; display:flex; gap:10px; border-top:1px solid #eee; padding-top:20px;">' . 
              htm_Button(icon: 'fa-times', labl: '@Cancel', type: 'secondary', link: 'user_list.page.php', styl: 'flex:1; padding:12px;') . 
              '</div>'
    );

htm_Card_end(); 

htm_Footer(); 
ob_end_flush();
?>