<?php # /inc/auth.inc.php v:1.3.0 d:2026-08-30 i:evs
# v1.2.0: KRITISK - ?set_level= tillod enhver logget-ind bruger at give sig
# selv admin-niveau uden noget tjek. Kræver nu real_user_level fra login.php
# og tillader kun at sænke, aldrig hæve. Fundet ved en adgangskontrol-
# gennemgang. Se også login.php + user_edit.php/user_create.php (samme fund).
require_once 'inc/php2htm.lib.php';

if (session_status() === PHP_SESSION_NONE) {
    $session_time = 14400; // 4 timer
    ini_set('session.gc_maxlifetime', $session_time);
    
    // Tving et unikt sessionsnavn specifikt til dit PHP 8-miljø
    // Dette klipper ALT samarbejde med de gamle, defekte sessionsfiler på serveren!
    session_name('TCC_V100_SESSION'); 

    // RETTET (§bugs-batch-20-review): 'secure' manglede helt - session-
    // cookien (som er den ENESTE ting der beviser man er logget ind) kunne
    // derfor altid sendes over almindelig, ukrypteret HTTP, selv på en
    // installation der ellers udelukkende køres over HTTPS. Sættes nu
    // betinget ud fra om forespørgslen faktisk kom ind over HTTPS - hårdkodet
    // 'true' ville have låst lokal HTTP-udvikling (php -S, ingen HTTPS) helt
    // ude af at kunne logge ind overhovedet.
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443;

    session_set_cookie_params([
        'lifetime' => $session_time,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $is_https
    ]);

    session_start();
    echo "";
}

// 1. --- HÅNDTER NIVEAUSKIFT FRA L-INDIKATOR (Kører ALTID) ---
// KRITISK RETTET 2026-08-19: dette tjekkede FØR intet som helst - enhver
// logget-ind bruger (også niveau 1) kunne besøge ?set_level=3 og give sig
// selv fuldt admin-niveau i resten af sessionen, og dermed omgå enhver side
// der bruger $rLev (CLAUDE.md's egen anbefalede metode til at afspærre nye
// sider). Nu tillades KUN at sænke til en midlertidig forhåndsvisning af et
// LAVERE niveau end brugerens ægte, database-hentede niveau
// ($_SESSION['real_user_level'], sat udelukkende i login.php ved reelt
// login) - aldrig at hæve over det. Findes intet ægte niveau i sessionen
// (fx en session fra før denne rettelse), afvises ændringen helt.
if (isset($_GET['set_level'])) {
    $requested_level = (int)$_GET['set_level'];
    $real_level = $_SESSION['real_user_level'] ?? null;
    if ($real_level !== null && $requested_level >= 1 && $requested_level <= $real_level) {
        $_SESSION['user_level'] = $requested_level;
    }
}

// 2. --- SPROG-HÅNDTERING (Kører ALTID) ---
if (isset($_GET['l'])) {
    $requested_lang = strtolower($_GET['l']);
    if (preg_match('/^[a-z]{2}$/', $requested_lang)) {
        $_SESSION['lang'] = $requested_lang;
        
        // Opdater proglang med det samme, så biblioteket forstår det
        if ($requested_lang === 'en') {
            $_SESSION['proglang'] = 'en : English';
        } else {
            $_SESSION['proglang'] = 'da : Dansk';
        }
    }
}

// Fallback hvis sessionen ikke er sat endnu
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'da'; 
}
if (!isset($_SESSION['proglang'])) {
    $_SESSION['proglang'] = 'da : Dansk';
}

// Hent den aktuelle sti med det samme
$current_url = $_SERVER['REQUEST_URI'];

// 3. --- AKTIV HARD TIMEOUT KONTROL (Kører kun hvis logget ind og ikke på login.php) ---
$max_inactivity = 43200; // 12 timers inaktivitet tilladt inden hårdt udsmid
if (isset($_SESSION['user_id']) && strpos($current_url, 'login.php') === false) {
    if (isset($_SESSION['last_activity'])) {
        $inactive_time = time() - $_SESSION['last_activity'];
        
        if ($inactive_time > $max_inactivity) {
            // Tving udsmidning
            $_SESSION = array();
            session_destroy();
            
            // Gem arbejdssiden i cookien inden vi dør
            if (!empty($current_url)) {
                $cookie_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
                setcookie('redirect_to', $current_url, time() + 3600, $cookie_path);
            }
            header("Location: login.php");
            exit;
        }
    }
    $_SESSION['last_activity'] = time(); // Opdater aktivitet ved gyldigt klik
}

// 4. --- LOGIN-KONTROL ---
if (strpos($current_url, 'login.php') !== false) {
    return; 
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] <= 0) {
    if (!empty($current_url)) {
        $cookie_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
        setcookie('redirect_to', $current_url, time() + 3600, $cookie_path);
    }
    header("Location: login.php");
    exit;
}

// 4b. --- CSRF-BESKYTTELSE (kun POST, kun for logget-ind brugere) ---
// RETTET (§bugs-batch-22-review): fandtes slet ikke noget sted i appen -
// bekræftet ved en fuld projekt-gennemsøgning. En ondsindet side kunne
// derfor få en indlogget brugers browser til automatisk at indsende en
// formular til en hvilken som helst POST-side i TinyCash (ændre firma-
// indstillinger, oprette en admin-bruger, slette en konto...) uden
// brugerens vidende - SameSite=Lax (se [[bugs-batch-20-review]]) beskytter
// kun mod passive tredjeparts-anmodninger (img/iframe/fetch), ikke mod at
// offeret narres til at klikke et link eller en auto-indsendt formular på
// en ekstern side. csrf_field()/csrf_token() (inc/php2htm.lib.php) er nu
// indlejret i hver formular i appen der bygger en <form method="post">
// (både htm_Card_()'s egen formularbygger og hver hånd-bygget formular -
// se de enkelte filers egne rettelser), inkl. de par fetch()-baserede
// AJAX-endepunkter der sender $_POST (save_layout.php, storage_browser.php,
// send_invoice_action.php). login.php er BEVIDST undtaget (den returnerer
// allerede ovenfor, før dette punkt) - login-CSRF er en separat, mindre
// alvorlig problemstilling (kan i værste fald tvinge et offer til at logge
// ind på en angribers konto, ikke udføre handlinger på offerets egne data)
// og efterlades bevidst til en senere runde, for ikke at risikere selve
// login-flowet uden dedikeret testtid.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die(lang('@Security check failed (CSRF). Please reload the page and try again.'));
}

// 5. --- BRUGER-NIVEAU KONTROL ---
// RETTET (§bugs-batch-16-review): fallback-værdien var 2, ikke 1 - en session
// hvor user_level af en eller anden grund aldrig blev sat (fx et login-flow
// der endnu ikke er nået frem til at kopiere det fra databasen, eller en
// fremtidig tilsvarende write-side-fejl som de flere gange fundne
// user_level-udeladelser i user_create.php/user_edit.php/
// inc/user_create_admin.php) ville stiltiende få niveau 2 - som allerede har
// adgang til hele "Regnskab"/"System"-menugruppen (se
// get_menu_visibility_defaults() i inc/menu.inc.php), IKKE kun den sikreste,
// mest restriktive standard. Fail-safe skal altid være det LAVESTE niveau,
// samme standardværdi som users.user_level-kolonnen selv bruger.
$uLev = $_SESSION['user_level'] ?? 1;

if (isset($rLev) && $uLev < $rLev) {
    deny_access_gracefully();
}

// Central funktion til at afvise adgang med bevaret menu og design
function deny_access_gracefully() {
    if (ob_get_length()) ob_clean();
    
    if (!function_exists('showMenu')) {
        require_once 'inc/menu.inc.php';
    }
    
    htm_Header(lang('Access Denied'));
    showMenu();
    
    htm_Card_(lang('Access Denied'), 600, '', 'access_error', false);
    echo '<div style="text-align:center; padding:20px;">';
    echo '<i class="fa fa-exclamation-triangle" style="font-size:48px; color:#e74c3c; margin-bottom:15px;"></i>';
    echo '<p style="font-size:1.2em; color:#555; margin-bottom:25px;">' . lang('Your user level is not high enough to view this page.') . '</p>';
    htm_Button('fa-arrow-left', 'Back to Dashboard', 'primary', 'sales_hub.php', '', 'data-hint="'.lang('@Return to the main dashboard').'"', '', true);
    echo '</div>';
    htm_Card_end();
    
    htm_Footer();
    exit;
}

// NYT (§currency-setting-is-cosmetic-label, Fase 2): fælles afvisnings-
// funktion til sider, der er specifikt bundet til DANSK lovgivning/SKAT
// (SAF-T-eksport, OIOUBL e-faktura, den officielle momsrapport med dens
// TastSelv-afrundingslogik) og derfor ikke giver mening for en ikke-dansk
// virksomhed, der bruger firmaets faktisk konfigurerede bogføringsvaluta
// (settings.currency) til noget andet end DKK. Kaldes af den enkelte side
// selv (efter db_connect.inc.php er indlæst), samme mønster som $rLev
// ovenfor - men datadrevet (valutaindstilling) i stedet for et statisk
// niveau-tal.
function require_dkk_base_currency($conn) {
    $base = strtoupper(get_settings($conn)['currency'] ?? 'DKK');
    if ($base === 'DKK') return;

    if (ob_get_length()) ob_clean();
    if (!function_exists('showMenu')) {
        require_once 'inc/menu.inc.php';
    }

    htm_Header(lang('@Access Restricted'));
    showMenu();

    htm_Card_(lang('@Access Restricted'), 600, '', 'access_error', false);
    echo '<div style="text-align:center; padding:20px;">';
    echo '<i class="fa fa-flag" style="font-size:48px; color:#e74c3c; margin-bottom:15px;"></i>';
    echo '<p style="font-size:1.2em; color:#555; margin-bottom:10px;">' . lang('@This feature follows Danish-specific tax and bookkeeping rules, and is only available when your company\'s base currency is DKK.') . '</p>';
    echo '<p style="font-size:0.95em; color:#888; margin-bottom:25px;">' . sprintf(lang('@Your company is currently configured with %s as its base currency (Company Settings -> Currency).'), htmlspecialchars($base)) . '</p>';
    htm_Button('fa-arrow-left', '@Back to Dashboard', 'primary', 'sales_hub.php', '', 'data-hint="'.lang('@Return to the main dashboard').'"', '', true);
    echo '</div>';
    htm_Card_end();

    htm_Footer();
    exit;
}
