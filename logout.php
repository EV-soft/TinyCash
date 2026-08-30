<?php # /logout.php v:1.3.0 d:2026-08-30 i:evs
# Rettet: manglende session_name() gjorde at logout ikke rensede den rigtige session

// RETTET: session_start() blev kaldt uden session_name('TCC_V100_SESSION')
// først - præcis samme fejl som blev fundet og rettet i set_lang.php. Uden
// det rigtige sessionsnavn startede/ryddede PHP en helt ANDEN, ukendt
// session (under standardnavnet), mens den faktiske session - TCC_V100_SESSION,
// som indeholder $_SESSION['user_id'] osv. - aldrig blev rørt. Resultatet var,
// at brugeren reelt ALDRIG blev logget ud på sessionsniveau: når login.php
// bagefter korrekt genoptog TCC_V100_SESSION, var user_id stadig sat, hvilket
// fik htm_Footer()'s "kun-når-logget-ind"-blok (floating action bar,
// hjælpesystem, notesblok) til stadig at blive vist på login-siden.
session_name('TCC_V100_SESSION');
session_start();

$_SESSION = array();

// Hvis der bruges session-cookies, så slet dem også
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header("Location: login.php");
exit;
?>
