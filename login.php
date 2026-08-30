<?php # /login.php v:1.3.0 d:2026-08-30 i:evs
# Login-siden. Understøtter valgbar database-type (SQLite/MySQL) via
# formularen, to-faktor-login (TOTP, trin 2 efter korrekt adgangskode, kun
# for brugere der har slået det til via my_2fa.php), session_regenerate_id()
# ved login (sessionsfiksering), og real_user_level (kan ikke selv-eskaleres
# via ?set_level=). En simpel 5-forsøg/15-minutters kontolåsning pr.
# brugernavn genbruger den eksisterende login_log-tabel til at tælle nylige
# mislykkede forsøg, før adgangskode/2FA overhovedet tjekkes.
ob_start();

// Sørg for at login bruger NØJAGTIG samme navn og parametre som auth.inc.php
// RETTET (§bugs-batch-20-review): denne kommentar LOVEDE "nøjagtig samme
// parametre som auth.inc.php", men kopierede reelt kun session_name() -
// selve session_set_cookie_params()-kaldet (httponly/secure/samesite) fra
// auth.inc.php manglede helt her. Da login.php er den FØRSTE side der
// nogensinde starter en session (før man overhovedet er logget ind), fik
// selve den indledende session-cookie derfor INGEN af de hærdede
// egenskaber - bekræftet direkte: Set-Cookie-linjen fra denne side manglede
// HttpOnly/SameSite/Secure fuldstændig, uanset at auth.inc.php sætter dem
// korrekt på alle ØVRIGE sider. Kalder nu samme opsætning som
// auth.inc.php, inklusiv den betingede 'secure'-værdi.
if (session_status() === PHP_SESSION_NONE) {
    session_name('TCC_V100_SESSION');
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    session_set_cookie_params([
        'lifetime' => 14400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $is_https
    ]);
    session_start();
}
// --- NYT: LÆS EVT. VALGT DATABASE-TYPE FRA FORMULAREN, FØR db_connect.inc.php
// KØRER. db_connect.inc.php læser $_SESSION['db_type'] og bruger den sektion
// (mysql_config/sqlite_config) i stedet for env.ini's statiske ACTIVE_DB.
// Kun 'mysql'/'sqlite' accepteres - selve forbindelsesoplysningerne kommer
// STADIG udelukkende fra env.ini, brugeren vælger blot hvilken af de to
// allerede konfigurerede sektioner der skal bruges.
if (isset($_POST['db_type']) && in_array($_POST['db_type'], ['mysql', 'sqlite'], true)) {
    $_SESSION['db_type'] = $_POST['db_type'];
}

// --- NYT: VALG AF REGNSKAB (flere regnskaber pr. installation) ---
// Læses FØR db_connect.inc.php køres, præcis som db_type ovenfor - så det
// regnskab brugeren lige har valgt bliver det, siden rent faktisk forbinder
// til på denne sidevisning. Findes inc/data/accounts.json slet ikke
// (almindelig ét-regnskabs-installation), returnerer account_list() altid
// et tomt array, og hele denne mekanisme er et no-op - se
// inc/account.lib.php og inc/db_connect.inc.php.
require_once 'inc/account.lib.php';
if (isset($_POST['account_id']) && $_POST['account_id'] !== '') {
    $_SESSION['account_id'] = (string)$_POST['account_id'];
}
// Skift regnskab: ryd valget og vis vælgeren igen fra bunden.
if (isset($_GET['change_account'])) {
    unset($_SESSION['account_id']);
    header("Location: login.php");
    exit;
}
// Præcis ét registreret regnskab -> vælges automatisk, uden ekstra klik.
// Sker FØR db_connect.inc.php køres, så selv denne (første) sidevisning
// forbinder til det rigtige regnskab, ikke env.inis statiske fallback.
if (empty($_SESSION['account_id'])) {
    $only_account = account_list();
    if (count($only_account) === 1) {
        $_SESSION['account_id'] = $only_account[0]['id'];
    }
}

// Afbryd en igangværende 2FA-udfordring (trin 2) og start forfra - fx hvis
// brugeren indtastede forkert brugernavn/kodeord og vil starte helt om.
if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['totp_pending']);
    header("Location: login.php");
    exit;
}

require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/totp.lib.php';

// --- NYT: skal regnskabsvælgeren vises? ---
// 0 eller 1 aktive regnskaber -> vælgeren springes helt over (0 bevarer
// dagens oplevelse byte-for-byte for almindelige installationer; det ene
// tilfælde af "1" er allerede valgt automatisk ovenfor, FØR forbindelsen
// blev oprettet). Kun ved 2+ aktive regnskaber, OG intet allerede valgt i
// sessionen, vises et selvstændigt trin FØR selve login-formularen. At
// oprette et nyt regnskab kræver admin-login og sker derfor bevidst IKKE
// herfra, men fra regnskabs-administration inde i selve programmet.
$accounts = account_list();
$show_account_picker = (count($accounts) >= 2 && empty($_SESSION['account_id']));

// Find env-filen (samme logik som db_connect)
// RETTET: env.ini flyttet til inc/data/env.ini - de gamle stier bevaret
// som bagudkompatibel fallback.
$env_paths = ['inc/data/env.ini', 'env.ini', '.env', 'inc/env.ini', 'inc/.env'];
$env_file = '';
foreach ($env_paths as $path) {
    if (file_exists($path)) { $env_file = $path; break; }
}

// Læs konfigurationen
$config = $env_file ? parse_ini_file($env_file, true) : [];

// Tjek om MySQL er opsat
$mysql_configured = (
    !empty($config['mysql_config']['DB_HOST']) && 
    !empty($config['mysql_config']['DB_NAME'])
);

$error_msg = "";

// 1. Tjek for eksisterende brugere (Setup-tjek)
$check_users = DB::query($conn, "SELECT COUNT(*) FROM users");
if ($check_users) {
    $user_count = DB::fetch_row($check_users)[0];
    if ($user_count == 0 && file_exists('inc/user_create_admin.php')) {
        require_once 'inc/user_create_admin.php';
        exit;
    }
}

// Fuldfører selve login-sessionen - fælles for både almindeligt login (uden
// 2FA) og trin 2 nedenfor (efter en bekræftet 2FA-kode). Uændret indhold ift.
// den oprindelige login-blok, kun udtrukket så den kan genbruges to steder.
function login_complete_session(array $row, string $chosen_db_type, ?string $chosen_account_id = null): void {
    // Sessions-fiksering: nyt sessions-ID ved login, så et ID en angriber evt.
    // har "fikseret" før login ikke kan genbruges til at kapre den nu-
    // autentificerede session bagefter.
    session_regenerate_id(true);

    $_SESSION = array();
    $_SESSION['user_id']    = (int)$row['user_id'];
    $_SESSION['user_name']  = (string)$row['username'];
    $_SESSION['user_level'] = (int)$row['user_level'];
    // Ægte niveau fra databasen - IKKE det samme som user_level ovenfor, som
    // kan sænkes midlertidigt via ?set_level= (se inc/auth.inc.php) for at
    // forhåndsvise et lavere niveau. Sat KUN her, ved reelt login -
    // ?set_level= må aldrig kunne hæve user_level over dette, ellers kan
    // enhver bruger give sig selv admin-niveau blot ved at besøge ?set_level=3.
    $_SESSION['real_user_level'] = (int)$row['user_level'];
    $_SESSION['user_role']  = (string)$row['user_role'];
    $_SESSION['lang']       = 'da';
    $_SESSION['db_type']    = $chosen_db_type;
    // NYT: bevar det valgte regnskab på tværs af session-nulstillingen
    // ovenfor, præcis som db_type - ellers ville en vellykket 2FA-runde
    // (som selv går via denne funktion) stille skifte tilbage til intet
    // regnskab valgt.
    if ($chosen_account_id !== null) {
        $_SESSION['account_id'] = $chosen_account_id;
    }
}

// RETTET (§bugs-batch-18-review): inc/auth.inc.php sætter en "redirect_to"-
// cookie to steder (session-timeout og "ikke logget ind"), tydeligt beregnet
// til at sende brugeren tilbage til den SIDE de reelt var på vej til, ikke
// bare forsiden - men INTET nogensinde LÆSTE cookien igen. Login endte derfor
// altid på index.php, uanset hvilken dybt-linket side brugeren egentlig
// prøvede at nå (fx en bestemt faktura). Samme lokal-sti-validering som
// project_actions.php's return_to (åben omdirigering forhindret): kun en
// relativ sti, aldrig et andet domæne.
function login_get_safe_redirect(): string {
    $target = $_COOKIE['redirect_to'] ?? '';
    // $_SERVER['REQUEST_URI'] (hvad cookien altid er sat fra) starter altid
    // med ÉT enkelt "/" - kræver derfor præcis ét indledende "/" efterfulgt
    // af et alfanumerisk tegn (udelukker dermed også "//evil.com", som ville
    // kræve "/" som andet tegn). ":" indgår bevidst ikke i det tilladte
    // tegnsæt, så et forsøg på at smugle et fuldt skema ind (fx
    // "/x?u=http://evil.com") ville stadig fejle regex'en i sin helhed.
    //
    // RETTET (§bugs-batch-34-review): "%" var IKKE med i det tilladte
    // tegnsæt - REQUEST_URI (som cookien altid er sat fra, se auth.inc.php)
    // er den RÅ, stadig procent-kodede forespørgselslinje, og ethvert
    // forespørgselsparameter der reelt indeholder tegn som "+"/"/"/"="
    // (fx en base64-lignende OAuth-kode, som bank_integration_callback.php's
    // ?code=...&state=... fra Enable Banking) ville komme URL-kodet som
    // %2B/%2F/%3D i selve REQUEST_URI. Uden "%" i tegnsættet fejlede HELE
    // regex'en for enhver sådan URL, og login endte tavst på index.php i
    // stedet for at fuldføre den bankforbindelse brugeren var midt i - netop
    // det scenarie denne mekanisme blev bygget til (en flertrins, ekstern
    // omdirigering, hvor sessionen kan nå at udløbe undervejs hos banken).
    // Bekræftet direkte: samme funktion kaldt med en %2B/%2F/%3D-holdig
    // sti faldt tilbage til "index.php" FØR denne rettelse. At tilføje "%"
    // genindfører IKKE åben omdirigering - stien skal stadig starte med
    // netop ét "/" efterfulgt af et alfanumerisk tegn (blokerer "//evil.com"),
    // og ":" er fortsat helt udelukket (blokerer et indlejret skema, også et
    // kodet forsøg som "%3A" ville stadig kun optræde som en harmløs, bogstavelig
    // tekststreng i selve Location-headeren, ikke noget browseren selv afkoder
    // og dermed omtolker).
    if ($target !== ''
        && preg_match('#^/[A-Za-z0-9][A-Za-z0-9_\-./?=&%]*$#', $target)
        && strpos($target, 'login.php') === false) {
        return $target;
    }
    return 'index.php';
}

// 2. Håndter Login-post, TRIN 1 (brugernavn + adgangskode)
if (isset($_POST['login'])) {
    // RETTET (fundet ved "find fem fejl"-sweep af flere-regnskaber-
    // funktionen): login.php's egen formular render'er ganske vist et
    // csrf_token-felt (htm_Card_(form: 'login.php') kalder csrf_field()
    // automatisk), men INTET her tjekkede det nogensinde - inc/auth.inc.php's
    // centrale csrf_verify()-gate gælder udtrykkeligt kun "hver POST fra en
    // logget-ind bruger" (se php2htm.lib.php's egen kommentar) og dækker
    // derfor bevidst ikke login.php, som netop kører FØR nogen er logget ind.
    // Uden dette tjek er selve login-handlingen sårbar over for "login CSRF"
    // (en angriber kan bygge en selv-afsendende formular, der logger et offer
    // ind på en session med angriberens EGNE, allerede kendte oplysninger,
    // uden offeret nogensinde selv har indtastet noget).
    if (!csrf_verify()) {
        $error_msg = "Sikkerhedstjek fejlede (CSRF) - genindlæs siden og prøv igen.";
    } else {
    $initials = trim($_POST['initials'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'];
    $ua       = $_SERVER['HTTP_USER_AGENT'];

    // RETTET (se [[bugs-batch-10-review]]): login_log har hele tiden
    // registreret hvert mislykkede loginforsøg (bruger, IP, tidspunkt), men
    // intet nogensinde LÆSTE de data igen - der var ingen øvre grænse for,
    // hvor mange adgangskoder en angriber kunne afprøve mod et kendt
    // brugernavn. Simpel, praktisk låsning: 5 mislykkede forsøg mod samme
    // brugernavn inden for 15 minutter blokerer yderligere forsøg i den
    // periode - bruger den allerede eksisterende login_log-tabel, ingen ny
    // migration nødvendig. Tjekkes FØR selve kodeordstjekket, så et låst
    // forsøg aldrig når frem til password_verify().
    // RETTET (§bugs-batch-16-review): tjekkede kun status = 'Failed' (selve
    // adgangskode-trinnet) - et mislykket 2FA-forsøg logges som
    // 'Failed - 2FA' (se trin 2 nedenfor) og talte derfor ALDRIG med. En
    // angriber der allerede havde en gyldig adgangskode kunne dermed afprøve
    // 6-cifrede TOTP-koder helt uden nogen låsning, selvom hele pointen med
    // denne tæller var at forhindre netop den slags gennemprøvning.
    $lock_check_sql = "SELECT COUNT(*) AS n FROM login_log
                        WHERE logged_username = ? AND status LIKE 'Failed%'
                          AND login_time >= " . (DB::is_sqlite() ? "datetime('now', '-15 minutes')" : "DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $lock_res = DB::prepare_and_execute($conn, $lock_check_sql, [$initials]);
    $recent_failures = $lock_res ? (int)(DB::fetch_assoc($lock_res)['n'] ?? 0) : 0;

    if ($recent_failures >= 5) {
        $error_msg = "For mange mislykkede loginforsøg. Prøv igen om 15 minutter.";
    } else {
        // VIGTIGT: hvis migrate_2fa.php ikke er kørt endnu, findes totp_*-
        // kolonnerne ikke, og forespørgslen ville ellers kaste en uhåndteret
        // PDOException - der ville låse ALLE brugere (inkl. admin) helt ude,
        // også fra at kunne nå frem til selve migrationen (som kræver login).
        // Prøv derfor den fulde forespørgsel først, og fald tilbage til den
        // gamle 5-kolonne-udgave (2FA behandlet som slået fra) hvis den fejler.
        try {
            $sql = "SELECT user_id, username, password_hash, user_role, user_level, totp_enabled, totp_secret, totp_recovery_codes FROM users WHERE username = ?";
            $res = DB::prepare_and_execute($conn, $sql, [$initials]);
        } catch (\Throwable $e) {
            $sql = "SELECT user_id, username, password_hash, user_role, user_level FROM users WHERE username = ?";
            $res = DB::prepare_and_execute($conn, $sql, [$initials]);
        }

        // Tjek om vi får et resultat tilbage
        if ($res && $row = DB::fetch_assoc($res)) {
            if (password_verify($pass, $row['password_hash'])) {
                // Bevar db_type i sessionen på tværs af $_SESSION-nulstillingen herunder,
                // så resten af sessionen efter login forbliver på samme database.
                $chosen_db_type    = $_SESSION['db_type'] ?? $db_type;
                // NYT: samme princip for det valgte regnskab.
                $chosen_account_id = $_SESSION['account_id'] ?? null;

                // To-faktor-login (§bank-integration-psd2's naboprojekt på
                // forslagslisten, §Sikkerhed): hvis slået til for denne bruger,
                // er adgangskoden kun FØRSTE faktor - login fuldføres IKKE her.
                // $_SESSION['user_id'] sættes bevidst ikke endnu - auth.inc.php
                // ville ellers betragte brugeren som fuldt logget ind før 2.
                // faktor er bekræftet. Ingen login_log-post ved dette trin -
                // kun ved et REELT fuldført (eller fejlet) 2FA-forsøg nedenfor.
                if (!empty($row['totp_enabled'])) {
                    $_SESSION['totp_pending'] = [
                        'user_id'         => (int)$row['user_id'],
                        'username'        => (string)$row['username'],
                        'user_role'       => (string)$row['user_role'],
                        'user_level'      => (int)$row['user_level'],
                        'db_type'         => $chosen_db_type,
                        'account_id'      => $chosen_account_id,
                        'totp_secret'     => $row['totp_secret'],
                        'recovery_codes'  => $row['totp_recovery_codes'],
                        'logged_username' => $initials,
                    ];
                } else {
                    $log_sql = "INSERT INTO login_log (user_id, logged_username, ip_address, status, user_agent) VALUES (?, ?, ?, 'Success', ?)";
                    DB::prepare_and_execute($conn, $log_sql, [$row['user_id'], $initials, $ip, $ua]);

                    login_complete_session($row, $chosen_db_type, $chosen_account_id);
                    $redirect = login_get_safe_redirect();
                    setcookie('redirect_to', '', time() - 3600, '/');
                    header("Location: " . $redirect);
                    exit;
                }
            } else {
                $error_msg = "Forkert adgangskode.";
                $fail_sql = "INSERT INTO login_log (logged_username, ip_address, status, user_agent) VALUES (?, ?, 'Failed', ?)";
                DB::prepare_and_execute($conn, $fail_sql, [$initials, $ip, $ua]);
            }
        } else {
            $error_msg = "Bruger findes ikke.";
            $fail_sql = "INSERT INTO login_log (logged_username, ip_address, status, user_agent) VALUES (?, ?, 'Failed', ?)";
            DB::prepare_and_execute($conn, $fail_sql, [$initials, $ip, $ua]);
        }
    }
    }
}

// 2b. Håndter Login-post, TRIN 2 (6-cifret app-kode ELLER en gendannelseskode)
if (isset($_POST['totp_login']) && !empty($_SESSION['totp_pending'])) {
    // Samme CSRF-rettelse som TRIN 1 ovenfor - se dens kommentar.
    if (!csrf_verify()) {
        $error_msg = "Sikkerhedstjek fejlede (CSRF) - genindlæs siden og prøv igen.";
    } else {
    $pending = $_SESSION['totp_pending'];
    $code    = trim($_POST['totp_code'] ?? '');
    $ip      = $_SERVER['REMOTE_ADDR'];
    $ua      = $_SERVER['HTTP_USER_AGENT'];

    // RETTET (§bugs-batch-16-review): dette trin havde INTET eget
    // låsningstjek - en angriber der allerede kender adgangskoden (og derfor
    // nåede hertil) kunne afprøve 6-cifrede TOTP-koder ubegrænset, uden
    // nogensinde at ramme trin 1's 5-forsøgs-låsning. Samme
    // 5-forsøg/15-minutters-princip, nu håndhævet her også.
    $lock_check_sql = "SELECT COUNT(*) AS n FROM login_log
                        WHERE logged_username = ? AND status LIKE 'Failed%'
                          AND login_time >= " . (DB::is_sqlite() ? "datetime('now', '-15 minutes')" : "DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $lock_res = DB::prepare_and_execute($conn, $lock_check_sql, [$pending['logged_username']]);
    $recent_failures = $lock_res ? (int)(DB::fetch_assoc($lock_res)['n'] ?? 0) : 0;

    if ($recent_failures >= 5) {
        $error_msg = "For mange mislykkede loginforsøg. Prøv igen om 15 minutter.";
    } else {
        $valid          = totp_verify($pending['totp_secret'], $code);
        $used_recovery  = false;
        $remaining_codes = null;

        // Faldt appens 6-cifrede kode ikke igennem, prøv om det i stedet er en
        // af de gemte engangs-gendannelseskoder (bcrypt-hashet, ligesom kodeord).
        if (!$valid && !empty($pending['recovery_codes'])) {
            $codes = json_decode($pending['recovery_codes'], true) ?: [];
            foreach ($codes as $i => $hash) {
                if ($code !== '' && password_verify($code, $hash)) {
                    $valid = true;
                    $used_recovery = true;
                    unset($codes[$i]);
                    $remaining_codes = array_values($codes); // engangskode - fjernes efter brug
                    break;
                }
            }
        }

        if ($valid) {
            $log_sql = "INSERT INTO login_log (user_id, logged_username, ip_address, status, user_agent) VALUES (?, ?, ?, 'Success', ?)";
            DB::prepare_and_execute($conn, $log_sql, [$pending['user_id'], $pending['logged_username'], $ip, $ua]);

            $row = [
                'user_id'    => $pending['user_id'],
                'username'   => $pending['username'],
                'user_role'  => $pending['user_role'],
                'user_level' => $pending['user_level'],
            ];
            login_complete_session($row, $pending['db_type'], $pending['account_id'] ?? null);

            if ($used_recovery && $remaining_codes !== null) {
                DB::prepare_and_execute($conn, "UPDATE users SET totp_recovery_codes = ? WHERE user_id = ?",
                    [json_encode($remaining_codes), $pending['user_id']]);
                log_action($conn, 'USE_2FA_RECOVERY_CODE', 'users', $pending['user_id'], null, ['remaining' => count($remaining_codes)]);
            }

            $redirect = login_get_safe_redirect();
            setcookie('redirect_to', '', time() - 3600, '/');
            header("Location: " . $redirect);
            exit;
        } else {
            $error_msg = lang('@Incorrect code. Please try again.');
            $fail_sql = "INSERT INTO login_log (logged_username, ip_address, status, user_agent) VALUES (?, ?, 'Failed - 2FA', ?)";
            DB::prepare_and_execute($conn, $fail_sql, [$pending['logged_username'], $ip, $ua]);
        }
    }
    }
}

// 3. Generer HTML
// Login-siden låses bevidst til light-tema: der findes ingen temavælger her
// (showMenu() vises jo ikke før login), så en tidligere gemt dark/custom-
// cookie ville ellers gøre siden svær at bruge uden mulighed for selv at
// skifte tilbage. Se htm_Header()'s $force_theme-parameter.
htm_Header(lang('@Login'), 1600, true, 'light');

echo '<div style="display: flex; justify-content: center; align-items: center; min-height: 80vh;">';

htm_Card_(
    capt: "TinyCash " . lang('@Login'), 
    wdth: '400', 
    form: 'login.php'
);

if ($error_msg) { htm_alert($error_msg, 'error', 300); }
if (($_GET['msg'] ?? '') === 'admin_created') {
    htm_alert(lang('@Administrator account created. Please log in.'), 'success', 300);
}

if ($show_account_picker) {
    // --- NYT TRIN 0: VÆLG REGNSKAB (kun vist ved 2+ registrerede regnskaber,
    // se account_list()/inc/account.lib.php). Hver knap poster sit eget
    // account_id ind i den allerede åbne formular (htm_Card_(form:
    // 'login.php') ovenfor) - fanges af opsamlings-koden øverst i filen,
    // hvorefter siden genindlæses og viser den almindelige login-formular.
    echo '<p style="text-align:center; color:var(--text-muted); font-size:0.9rem;">' . lang('@Choose which ledger to sign in to.') . '</p>';
    foreach ($accounts as $acc) {
        $label = htmlspecialchars($acc['name'] ?? $acc['id']);
        $badge = !empty($acc['is_demo'])
            ? ' <span style="font-size:0.75em; color:var(--color-warning);">(' . lang('@Demo') . ')</span>'
            : '';
        echo '<button type="submit" name="account_id" value="' . htmlspecialchars($acc['id'], ENT_QUOTES) . '" style="display:block; width:100%; margin-bottom:8px; padding:10px; border:1px solid var(--border-color); border-radius:6px; background:var(--bg-panel); color:var(--text-main); cursor:pointer; text-align:left; font-size:0.95rem;">'
            . '<i class="fa fa-building" style="margin-right:8px; color:var(--color-primary);"></i>' . $label . $badge
            . '</button>';
    }
    htm_Card_end();
    echo '</div>';
    htm_Footer();
    exit;
}

if (!empty($_SESSION['totp_pending'])) {
    // --- TRIN 2: 2FA-kode (kun nået hertil efter en korrekt adgangskode
    // for en bruger der har 2FA slået til - se my_2fa.php) ---
    echo '<p style="text-align:center; color:var(--text-muted); font-size:0.9rem;">'
        . sprintf(lang('@Enter the 6-digit code from your authenticator app for %s.'), htmlspecialchars($_SESSION['totp_pending']['username']))
        . '</p>';
    htm_Field(icon: 'fa-key', labl: '@6-digit Code', name: 'totp_code', type: 'text',
        extr: 'required autofocus maxlength="9" autocomplete="one-time-code"',
        hint: '@Or one of your recovery codes', plho: '000000');
    htm_nl(1);
    htm_Button(
        icon: 'fa-check', labl: '@Verify', type: 'primary',
        styl: 'width: 100%; padding: 12px; font-size: 1.1em;',
        attr: 'name="totp_login" data-hint="'.lang('@Verify the code and complete login').'"',
        cont: '<div style="margin-top: 20px;"></div>'
    );
    echo '<div style="text-align:center; margin-top:15px;"><a href="login.php?cancel_2fa=1" style="color:var(--text-muted); font-size:0.85em; text-decoration:none;"><i class="fa fa-arrow-left"></i> ' . lang('@Cancel and start over') . '</a></div>';
    htm_Card_end();
    echo '</div>';
    htm_Footer();
    exit;
}

// NYT: link tilbage til regnskabsvælgeren - kun vist når der reelt er noget
// at vælge imellem (2+ registrerede regnskaber) og ét allerede er valgt.
if (count($accounts) >= 2 && !empty($_SESSION['account_id'])) {
    $current_account = account_get($_SESSION['account_id']);
    $current_label   = htmlspecialchars($current_account['name'] ?? $_SESSION['account_id']);
    echo '<div style="text-align:center; margin-bottom:15px; font-size:0.85em; color:var(--text-muted);">'
        . '<i class="fa fa-building"></i> ' . $current_label
        . ' &middot; <a href="login.php?change_account=1" style="color:var(--color-primary); text-decoration:none;">' . lang('@Change ledger') . '</a>'
        . '</div>';
}

// Input felter
htm_Field(icon: 'fa-user', labl: '@Initials', name: 'initials', extr: 'required autofocus',
    hint:'@User name', plho: '@Enter initials...');
htm_nl(2);

htm_Field(icon: 'fa-lock', labl: '@Password', name: 'password', type: 'password',
    extr: 'required style="padding-right: 35px; box-sizing: border-box;"',
    hint:'@User password', plho: '••••••••');
htm_nl(2);

// --- NYT: DATABASE-TYPE VÆLGER ---
// $db_type er sat af db_connect.inc.php og afspejler den database, siden
// rent faktisk lige har forbundet til (enten fra et tidligere valg i
// sessionen, eller env.ini's ACTIVE_DB som fallback ved en frisk session).
$is_mysql  = ($db_type === 'mysql');
$is_sqlite = ($db_type === 'sqlite');

// ... (din eksisterende kode)

// Logik for MySQL-feltet
$mysql_disabled = $mysql_configured ? '' : 'disabled';
$mysql_style    = $mysql_configured ? 'cursor:pointer;' : 'cursor:not-allowed; opacity: 0.4;';
$mysql_title    = $mysql_configured ? '' : ' title="'.lang('@MySQL is not configured').'"';
if ($mysql_disabled ) $info= '<br>'.lang('@MySQL is not setup (disabled) ');
    
echo '<div style="margin-bottom: 15px; padding: 10px 12px; border: 1px solid var(--border-fieldset, #ccc); border-radius: 8px; background: var(--bg-panel, #f8f9fa);"
      data-hint="'.lang('@SQLite up to 50,000 accounting entries<br>MySQL Many many more...<br>MySQL requires advanced installation').$info.'">';
      
echo '  <div style="font-size: 0.8rem; color: var(--text-muted, #666); margin-bottom: 8px;">
            <i class="fa fa-database" style="margin-right: 5px; color: var(--color-primary);"></i>' . lang('@Database') . '
        </div>';

// SQLite
echo '  <label style="display:inline-flex; align-items:center; gap:5px; margin-right:20px; cursor:pointer; font-size:0.9rem;">
            <input type="radio" name="db_type" value="sqlite" ' . ($is_sqlite ? 'checked' : '') . '> ' . lang('@SQLite (local file)') . '
        </label>';

// MySQL (deaktiveret hvis ikke opsat)
echo '  <label style="display:inline-flex; align-items:center; gap:5px; '.$mysql_style.' font-size:0.9rem;" '.$mysql_title.'>
            <input type="radio" name="db_type" value="mysql" ' . ($is_mysql ? 'checked' : '') . ' ' . $mysql_disabled .'> ' . lang('@MySQL (server)') . '
        </label>';
echo '</div>';
/* 
echo '<div style="margin-bottom: 15px; padding: 10px 12px; border: 1px solid var(--border-fieldset, #ccc); border-radius: 8px; background: var(--bg-panel, #f8f9fa);"
      data-hint="'.lang('@SQLite up to 50,000 accounting entries<br>MySQL Many many more...<br>MySQL requires advanced installation').'">';
echo '  <div style="font-size: 0.8rem; color: var(--text-muted, #666); margin-bottom: 8px;">';
echo '      <i class="fa fa-database" style="margin-right: 5px; color: var(--color-primary);"></i>' . lang('@Database');
echo '  </div>';
echo '  <label style="display:inline-flex; align-items:center; gap:5px; margin-right:20px; cursor:pointer; font-size:0.9rem;">';
echo '      <input type="radio" name="db_type" value="sqlite" ' . ($is_sqlite ? 'checked' : '') . '>';
echo '      ' . lang('@SQLite (local file)');
echo '  </label>';
echo '  <label style="display:inline-flex; align-items:center; gap:5px; cursor:pointer; font-size:0.9rem;">';
echo '      <input type="radio" name="db_type" value="mysql" ' . ($is_mysql ? 'checked' : '') . '>';
echo '      ' . lang('@MySQL (server)');
echo '  </label>';
echo '</div>';
 */
htm_nl(1);

htm_Button(
        icon: 'fa-sign-in-alt', labl: '@Sign In', type: 'primary',
        styl: 'width: 100%; padding: 12px; font-size: 1.1em;',
        attr: 'name="login" data-hint="'.lang('@Sign in with these credentials').'"',
        cont: '<div style="margin-top: 25px;"></div>'
    );
echo '<div class="lang-switcher" style="text-align: center; margin-top: 20px; font-family: sans-serif; font-size: 0.9rem;">
    <a href="set_lang.php?l=da" style="text-decoration: none; margin: 0 10px; color: #2c3e50;">
        🇩🇰 Dansk
    </a>
    <span style="color: #ccc;">|</span>
    <a href="set_lang.php?l=en" style="text-decoration: none; margin: 0 10px; color: #2c3e50;">
        🇬🇧 English
    </a>
</div>';
/* 
echo '<div class="lang-switcher" style="text-align: center; margin-top: 20px; font-family: sans-serif; font-size: 0.9rem;">
    <a href="?l=da"><img src="dk.svg" alt="Dansk"></a>
    <a href="?l=en"><img src="gb.svg" alt="English"></a>
</div>';  */

htm_Card_end(); 
echo '</div>'; 
?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var pwdField = document.getElementById('password');
    if (pwdField) {
        var wrapper = document.createElement('div');
        wrapper.style.position = 'relative';
        wrapper.style.display = 'block';
        wrapper.style.width = '100%';
        
        pwdField.parentNode.insertBefore(wrapper, pwdField);
        wrapper.appendChild(pwdField);
        
        var eyeIcon = document.createElement('i');
        eyeIcon.className = 'fas fa-eye';
        eyeIcon.style.position = 'absolute';
        eyeIcon.style.right = '10px';
        eyeIcon.style.top = '50%';
        eyeIcon.style.transform = 'translateY(-50%)';
        eyeIcon.style.cursor = 'pointer';
        eyeIcon.style.color = '#7f8c8d';
        eyeIcon.style.zIndex = '100';
        eyeIcon.style.fontSize = '1rem';
        eyeIcon.title = 'Vis/Skjul adgangskode';
        
        wrapper.appendChild(eyeIcon);
        
        eyeIcon.addEventListener('click', function(e) {
            e.preventDefault();
            if (pwdField.type === 'password') {
                pwdField.type = 'text';
                eyeIcon.className = 'fas fa-eye-slash';
            } else {
                pwdField.type = 'password';
                eyeIcon.className = 'fas fa-eye';
            }
        });
    }
});
</script>

<?php
htm_Footer(); 
?>
