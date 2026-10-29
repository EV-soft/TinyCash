<?php # /expense_edit.php v:1.3.1 d:2026-09-15 i:evs
# Opret/redigér en udgift. En allerede bogført udgift (voucher_no sat) har
# beløb/konto/dato/type/bilag låst mod redigering (bogføringslov, 5-års
# opbevaring) - rettelser sker via Annullér (expense_actions.php) + ny
# postering, aldrig ved at overskrive en eksisterende. depot_file_path (til
# "vælg fra depot") kræver at stien (realpath) reelt ligger inden for
# storage/voucher_depot/, og upload af nyt bilag kræver nu en hvidliste over
# tilladte filendelser - se selve tjekkene for baggrund.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/depot_worker.inc.php';
require_once 'inc/help.lib.php';

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
// NYT (runde 30, expense_lines): standard tom liste - kun en EKSISTERENDE
// udgift ($id > 0) kan have gemte linjer, se fetchet nedenfor. Uden denne
// unconditional default var variablen slet ikke defineret ved en NY
// udgift (id=0), hvilket gav en rå TypeError i array_map() ved rendering
// af split-formularen (selv-fanget live under test).
$existing_expense_lines = [];
$msg = ""; $err = "";

// RETTET (§currency-setting-is-cosmetic-label): firmaets faktisk
// konfigurerede bogføringsvaluta, ikke en hardkodet 'DKK' - se samme rettelse
// i invoice_edit.php.
$base_currency = strtoupper(preg_replace('/[^A-Z]/', '', $global_settings['currency'] ?? 'DKK')) ?: 'DKK';

$date         = date('Y-m-d');
$supp         = '';
$desc         = '';
$acc          = '';
$amount       = '';
$vouch        = '';
$proj         = 0;
$exp_type     = 'expense';
$currency     = $base_currency;
$orig_currency = null;
$orig_amount   = null;
$exch_rate     = null;
$current_attachment = "";
$no_attachment_reason = "";
// NYT (leverandørmodul, se db-setup/migrate_suppliers.php): valgfri kobling
// til en leverandørstamdata-post + betalingsstatus. Kun relevant/redigerbart
// ved NY oprettelse ($id === 0) - se selve formularen nedenfor for hvorfor.
$supplier_id = null;
$due_date    = '';
$paid_date   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_ai_action']) && $_POST['toggle_ai_action'] === '1') {
    $_SESSION['use_ai_scan'] = isset($_POST['use_ai_scan_toggle']) ? 1 : 0;
    header("Location: expense_edit.php?id=" . $id);
    exit;
}
$use_ai_scan = isset($_SESSION['use_ai_scan']) ? (int)$_SESSION['use_ai_scan'] : 1;

if ($id === 0 && isset($_GET['type']) && $_GET['type'] === 'income') {
    $exp_type = 'income';
}

// RETTET (se [[project-bugs-review]]): en ny udgift/indtægt oprettet via
// "Registrér udgift/indtægt" fra selve projektsiden bar ikke projektet med
// sig - samme mønster som ?type=income lige ovenfor, bare for proj_id.
if ($id === 0 && isset($_GET['proj_id'])) {
    $proj = (int)$_GET['proj_id'];
}

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): "Dupliker
// udgift" fra expense_list.php - samme "$id===0 && isset($_GET[...])"-
// mønster som ?type=income/?proj_id lige ovenfor. Forudfylder KUN
// beskrivende/beløbsmæssige felter fra kilde-udgiften - IKKE bilaget (en ny
// udgift har sit eget, andet bilag), IKKE voucher_no (den nye er endnu ikke
// bogført), og IKKE forfalds-/betalingsdato (en frisk, ubetalt post). Selve
// oprettelsen sker først når brugeren rent faktisk trykker Gem - i
// modsætning til invoice_duplicate.php er der bevidst INGEN silent
// database-INSERT her, da en gemt udgift her ALTID får sit voucher_no med
// det samme (ingen ufarlig "kladde"-tilstand at oprette i baggrunden som
// hos fakturaer - se filens egen header-kommentar).
if ($id === 0 && isset($_GET['dup'])) {
    $dup_id = (int)$_GET['dup'];
    // RETTET (samme fund som expense_list.php's Rediger-knap): "Dupliker" må
    // gerne bruge en ANNULLERET udgift som datakilde - den kopierer kun felt-
    // værdier ind i en helt NY, ubogført kladde og rører aldrig selve
    // kilde-posten, så uforanderligheden af den annullerede original brydes
    // ikke. Uden dette filtreret fra fandt SELECT'et intet, og knappen endte
    // med at åbne en helt tom formular uden nogen advarsel om hvorfor.
    $dup_res = DB::query($conn, "SELECT * FROM expenses WHERE exp_id = $dup_id");
    if ($dup_exp = DB::fetch_assoc($dup_res)) {
        $supp          = $dup_exp['supplier'];
        $desc          = $dup_exp['description'];
        $acc           = $dup_exp['account_id'];
        $amount        = $dup_exp['amount'];
        $proj          = (int)($dup_exp['proj_id'] ?? 0);
        $exp_type      = $dup_exp['exp_type'] ?? 'expense';
        $currency      = $dup_exp['currency'] ?? $base_currency;
        $orig_currency = $dup_exp['orig_currency'] ?? null;
        $orig_amount   = $dup_exp['orig_amount']   ?? null;
        $exch_rate     = $dup_exp['exch_rate']     ?? null;
        $msg = lang('@Fields pre-filled from a previous entry - review the amount and attach a new receipt before saving.');
    }
}

if ($id > 0) {
    $res = DB::query($conn, "SELECT * FROM expenses WHERE exp_id = $id AND is_cancelled = 0");
    if ($exp = DB::fetch_assoc($res)) {
        $date          = $exp['exp_date'];
        $supp          = $exp['supplier'];
        $desc          = $exp['description'];
        $acc           = $exp['account_id'];
        $amount        = $exp['amount'];
        $vouch         = $exp['voucher_no'];
        $proj          = (int)($exp['proj_id'] ?? 0);
        $exp_type      = $exp['exp_type'] ?? 'expense';
        $currency      = $exp['currency'] ?? $base_currency;
        $orig_currency = $exp['orig_currency'] ?? null;
        $orig_amount   = $exp['orig_amount']   ?? null;
        $exch_rate     = $exp['exch_rate']     ?? null;
        $current_attachment = $exp['attachment'];
        $no_attachment_reason = $exp['no_attachment_reason'] ?? '';
        $supplier_id = !empty($exp['supplier_id']) ? (int)$exp['supplier_id'] : null;
        $due_date    = $exp['due_date']  ?? '';
        $paid_date   = $exp['paid_date'] ?? '';
    } else {
        header("Location: expense_list.php?err=not_found");
        exit;
    }

    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 30):
    // hent eksisterende kontoopdeling (hvis nogen) til forudfyldning af
    // split-formularen. Tom liste = ikke splittet, samme "syntetisk enkelt
    // linje"-fald tilbage som expense_get_lines() selv bruger ved bogføring.
    $existing_expense_lines = [];
    if (expense_lines_has_table($conn)) {
        $el_res = DB::query($conn, "SELECT account_id, amount, description FROM expense_lines WHERE exp_id = $id ORDER BY line_id ASC");
        if ($el_res) { while ($elr = DB::fetch_assoc($el_res)) { $existing_expense_lines[] = $elr; } }
    }
}

// Bogføringslov (5-års opbevaringspligt): en gang bogført (voucher_no sat),
// er det ORIGINALE bilag en del af det permanente regnskab og kan ikke
// længere fjernes eller udskiftes via UI'en - kun tilføjes hvis det manglede.
// Dette overopfylder kravet (permanent > 5 år) frem for at spore en præcis
// udløbsdato, hvilket er enklere og mere robust. $vouch er indlæst FØR denne
// POST, så den afspejler status inden det aktuelle gem-forsøg.
$expense_already_posted = ($id > 0 && !empty($vouch));

// De FINANSIELLE feltværdier som de var FØR dette gem-forsøg - bruges til at
// opdage om nogen af dem forsøges ændret på en allerede bogført udgift
// (uforanderlighed, samme princip som C1/C3). Navngivet "posted_*" for ikke
// at kollidere med $orig_currency/$orig_amount, som allerede betyder noget
// andet (fremmed valuta-beløb før omregning).
$posted_amount     = $exp['amount']     ?? null;
$posted_account_id = $exp['account_id'] ?? null;
$posted_exp_type   = $exp['exp_type']   ?? null;
$posted_exp_date   = $exp['exp_date']   ?? null;

$s = get_settings($conn);
$currency_module = !empty($s['module_currency']) && $s['module_currency'] == '1';

// NYT (leverandørmodul): valgfri dropdown til at koble en leverandørstamdata-
// post til udgiften - fritekstfeltet 'supplier' forbliver den reelle,
// gemte værdi (autofyldes af valget via JS, se formularen), så AI-scan og
// alle eksisterende rapporter der læser den rå tekst er upåvirkede.
$supplier_options = ['' => lang('@-- Select existing supplier (optional) --')];
$supplier_days_by_id = [];
// @ - degraderer stille til "ingen leverandører endnu" hvis migrate_suppliers.php
// ikke er kørt endnu (tabellen findes ikke), i stedet for at fejle hele siden.
$sup_res = @DB::query($conn, "SELECT supplier_id, supplier_name, payment_days FROM suppliers WHERE is_active = 1 AND " . suppliers_active_filter($conn) . " ORDER BY supplier_name ASC");
if ($sup_res) {
    while ($sr = DB::fetch_assoc($sup_res)) {
        $supplier_options[(int)$sr['supplier_id']] = htmlspecialchars($sr['supplier_name']);
        $supplier_days_by_id[(int)$sr['supplier_id']] = (int)($sr['payment_days'] ?? 8);
    }
}

// RETTET (bagudkompatibilitet): expenses.supplier_id/due_date/paid_date
// findes kun EFTER db-setup/migrate_suppliers.php er kørt. Uden dette tjek
// ville et forsøg på at bruge "Ikke betalt endnu" på en ikke-migreret
// installation lade som om leverandørgælden blev sporet, mens den faktiske
// due_date/paid_date-opdatering nedenfor (samme "stille fejl"-mønster som
// no_attachment_reason) reelt aldrig gemte noget. Skjuler derfor selve
// leverandør-/betalingssektionen i formularen, indtil migrationen er kørt,
// i stedet for at tilbyde en funktion der ser ud til at virke, men ikke gør.
function expense_edit_col_exists($conn, $col) {
    if (DB::is_sqlite()) {
        $res = DB::query($conn, "PRAGMA table_info(expenses)");
        if ($res) { while ($row = DB::fetch_assoc($res)) { if (strtolower($row['name']) === strtolower($col)) return true; } }
        return false;
    }
    $res = DB::query($conn, "SHOW COLUMNS FROM expenses LIKE '$col'");
    return ($res && DB::num_rows($res) > 0);
}
$supplier_module_ready = expense_edit_col_exists($conn, 'supplier_id')
    && expense_edit_col_exists($conn, 'due_date')
    && expense_edit_col_exists($conn, 'paid_date');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attachment_sq      = "";
    $new_filename       = null;
    $file_to_delete     = null;
    $depot_file_to_remove = null;
    $file_to_scan       = null;

    if (isset($_POST['delete_attachment']) && $_POST['delete_attachment'] == '1' && $id > 0 && !empty($current_attachment)) {
        $file_to_delete     = "uploads/" . $current_attachment;
        $attachment_sq      = ", attachment = ''";
        $current_attachment = "";
    }

    $target_dir = "uploads/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

    // RETTET (§bugs-batch-20-review): KRITISK - filendelsen kom direkte fra
    // klientens oplyste filnavn (eller depotfilens egen endelse) med INTET
    // tjek overhovedet, og blev sat direkte ind i det nye filnavn i uploads/
    // - en offentligt tilgængelig mappe uden login. En bruger kunne uploade
    // en fil ved navn "shell.php" som "udgiftsbilag" og få den gemt som en
    // ægte, direkte kørbar PHP-fil på serveren (fjernkørsel af kode). Kræver
    // nu en hvidliste over reelle bilagstyper - samme sæt som
    // previewDepotFile() i forvejen forudsætter klientside
    // (pdf/jpg/jpeg/png/webp), udvidet med et par almindelige dokumentformater.
    // Delt mellem begge upload-veje nedenfor (direkte upload og depot-kopi).
    // uploads/.htaccess blokerer nu også farlige endelser direkte på
    // Apache-niveau som endnu et lag.
    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx'];

    if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES["attachment"]["name"], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) {
            die(lang('@Error: This file type is not allowed as an attachment.'));
        }
        // RETTET (§bugs-batch-18-review): uniqid() er UDEN "more_entropy" blot
        // en hex-kodning af serverens præcise ur-tidspunkt (sekund+mikrosekund)
        // på kaldetidspunktet - PHP's egen dokumentation advarer eksplicit mod
        // at bruge den til sikkerhedsformål. Da uploads/ er bevidst offentligt
        // web-tilgængeligt uden login (bilag vises direkte via <a
        // href="uploads/...">), og filnavnet i praksis lækkes til enhver der
        // ser siden, kunne datoen+dette forudsigelige mønster i teorien
        // indsnævre et gæt på ET ANDET bilags filnavn markant, hvis blot ét
        // upload-tidspunkt fra samme session/dag er kendt. Erstattet med en
        // reel kryptografisk tilfældig værdi (random_bytes), uafhængig af
        // serverens ur.
        $new_filename = "EXP_" . date('Ymd') . "_" . bin2hex(random_bytes(8)) . "." . $ext;
        if (move_uploaded_file($_FILES["attachment"]["tmp_name"], $target_dir . $new_filename)) {
            $file_to_scan = $target_dir . $new_filename;
            if (!empty($current_attachment)) $file_to_delete = "uploads/" . $current_attachment;
        }
    } elseif (!empty($_POST['depot_file_path'])) {
        // KRITISK: depot_file_path kom FØR helt ukontrolleret fra POST og
        // blev brugt direkte som filsti - "depot_file_path=inc/env.ini"
        // kunne kopiere hele hemmeligheds-filen ind som et "udgiftsbilag"
        // (og sende den videre til OpenAI, hvis AI-scanning er slået til).
        // Kræver nu at den opløste, kanoniske sti (realpath) reelt ligger
        // INDEN FOR storage/voucher_depot/ - intet andet accepteres.
        // Fundet ved en scanner-/OCR-gennemgang.
        $depot_source_path   = $_POST['depot_file_path'];
        $depot_base          = realpath(__DIR__ . '/storage/voucher_depot/' . rtrim(tc_account_storage_subdir(), '/'));      // (kun dette regnskabs depot)
        $candidate           = realpath(__DIR__ . '/' . $depot_source_path);
        $absolute_depot_path = ($depot_base && $candidate && strpos($candidate, $depot_base . DIRECTORY_SEPARATOR) === 0)
            ? $candidate : null;
        if ($absolute_depot_path && file_exists($absolute_depot_path)) {
            $ext = strtolower(pathinfo($absolute_depot_path, PATHINFO_EXTENSION));
            // RETTET (§bugs-batch-20-review): samme hvidliste som ved direkte
            // upload ovenfor - kopieres en fil fra depotet ind i uploads/ (den
            // offentligt tilgængelige mappe), skal endelsen være en reel
            // bilagstype, uanset hvad der måtte være havnet i voucher_depot/.
            if (!in_array($ext, $allowed_ext, true)) {
                die(lang('@Error: This file type is not allowed as an attachment.'));
            }
            // RETTET (§bugs-batch-18-review): uniqid() er UDEN "more_entropy" blot
        // en hex-kodning af serverens præcise ur-tidspunkt (sekund+mikrosekund)
        // på kaldetidspunktet - PHP's egen dokumentation advarer eksplicit mod
        // at bruge den til sikkerhedsformål. Da uploads/ er bevidst offentligt
        // web-tilgængeligt uden login (bilag vises direkte via <a
        // href="uploads/...">), og filnavnet i praksis lækkes til enhver der
        // ser siden, kunne datoen+dette forudsigelige mønster i teorien
        // indsnævre et gæt på ET ANDET bilags filnavn markant, hvis blot ét
        // upload-tidspunkt fra samme session/dag er kendt. Erstattet med en
        // reel kryptografisk tilfældig værdi (random_bytes), uafhængig af
        // serverens ur.
        $new_filename = "EXP_" . date('Ymd') . "_" . bin2hex(random_bytes(8)) . "." . $ext;
            if (copy($absolute_depot_path, $target_dir . $new_filename)) {
                $file_to_scan         = $target_dir . $new_filename;
                $depot_file_to_remove = $absolute_depot_path;
                if (!empty($current_attachment)) $file_to_delete = "uploads/" . $current_attachment;
            }
        }
    }

    // Bilagslås: hvis udgiften allerede er bogført, og forsøget ovenfor ville
    // fjerne eller udskifte det EKSISTERENDE bilag, neutraliseres det - det
    // oprindelige bilag forbliver urørt. At tilføje et bilag hvor der IKKE
    // var et før (fx eftervedhæftning) er stadig tilladt.
    $attachment_lock_err = '';
    if ($expense_already_posted && $file_to_delete !== null) {
        $attachment_lock_err = lang('@This expense is already posted. The original attachment cannot be removed or replaced — use Reverse and create a new entry if the attachment was wrong.');
        $file_to_delete       = null;
        $depot_file_to_remove = null;
        $new_filename         = null;
        $current_attachment   = $exp['attachment']; // gendan uændret
    }

    $date     = htmlspecialchars($_POST['exp_date']    ?? $date);
    $amount   = htmlspecialchars($_POST['amount']      ?? $amount);
    $supp     = htmlspecialchars($_POST['supplier']    ?? $supp);
    $desc     = htmlspecialchars($_POST['description'] ?? $desc);
    $acc      = htmlspecialchars($_POST['account_id']  ?? $acc);
    $proj     = (int)($_POST['proj_id']   ?? 0);
    $exp_type = in_array($_POST['exp_type'] ?? '', ['expense','income']) ? $_POST['exp_type'] : 'expense';

    // NYT (leverandørmodul): valgfri leverandør-kobling + betalingsstatus.
    // Kun relevant ved NY oprettelse ($id === 0) - se formularen for hvorfor
    // en allerede bogført udgift ikke kan skifte betalingsmetode her.
    $post_supplier_id = ($supplier_module_ready && isset($_POST['supplier_id']) && (int)$_POST['supplier_id'] > 0) ? (int)$_POST['supplier_id'] : null;
    if ($post_supplier_id && trim($supp) === '') {
        // JS udfylder normalt fritekstfeltet automatisk ved valg - denne
        // server-side fallback dækker kun det usandsynlige tilfælde af en
        // klient uden JavaScript.
        $sup_row = DB::fetch_assoc(DB::query($conn, "SELECT supplier_name FROM suppliers WHERE supplier_id = $post_supplier_id"));
        if ($sup_row) $supp = htmlspecialchars($sup_row['supplier_name']);
    }
    // eksisterende udgifter: uændret, styres kun via "Betal nu"; ikke-migreret
    // installation: altid "betalt med det samme", som hidtil.
    $pay_now = ($supplier_module_ready && $id === 0) ? (isset($_POST['pay_now']) ? 1 : 0) : 1;
    $post_due_date = trim($_POST['due_date'] ?? '');

    // Valuta-felter fra POST
    $post_currency      = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['currency']      ?? $base_currency));
    $post_orig_currency = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['orig_currency'] ?? ''));
    $post_orig_amount   = parse_dk_number($_POST['orig_amount'] ?? '');
    $post_exch_rate     = parse_dk_number($_POST['exch_rate']   ?? '');

    // Hvis fremmed valuta er valgt: beregn/gem kursdata
    // RETTET (§currency-setting-is-cosmetic-label): "fremmed" afgøres nu mod
    // $base_currency, og selve bogføringen sker i $base_currency - ikke en
    // hardkodet 'DKK', som gjorde en SEK-baseret virksomheds egen SEK-udgift
    // til en tilsyneladende "fremmed" DKK-postering.
    if ($post_orig_currency !== '' && $post_orig_currency !== $base_currency && $post_exch_rate > 0) {
        $orig_currency = $post_orig_currency;
        $orig_amount   = $post_orig_amount > 0 ? $post_orig_amount : null;
        $exch_rate     = $post_exch_rate;
        $currency      = $base_currency; // bogføres altid i firmaets bogføringsvaluta
    } else {
        $orig_currency = null;
        $orig_amount   = null;
        $exch_rate     = null;
        $currency      = $post_currency ?: $base_currency;
    }

    if ($use_ai_scan === 1 && $file_to_scan && file_exists($file_to_scan)) {
        $ai_data = scanBilagMedOpenAI($file_to_scan);
        if (isset($ai_data['error'])) { echo "<pre>OpenAI Error: "; print_r($ai_data['error']); echo "</pre>"; die(); }
        if ($ai_data) {
            $is_scan_action = isset($_POST['action_scan_only']);
            if ($is_scan_action || empty($_POST['exp_date']) || $_POST['exp_date'] === date('Y-m-d')) {
                if (!empty($ai_data['dato'])) { $date = $ai_data['dato']; $_POST['exp_date'] = $date; }
            }
            if ($is_scan_action || empty($_POST['amount']) || $_POST['amount'] === '0,00' || $_POST['amount'] === '0') {
                if (isset($ai_data['total'])) { $amount = (string)$ai_data['total']; $_POST['amount'] = $amount; }
            }
            if ($is_scan_action || empty($_POST['supplier'])) {
                if (!empty($ai_data['leverandor'])) { $supp = $ai_data['leverandor']; $_POST['supplier'] = $supp; }
            }
            if ($new_filename) { $current_attachment = $new_filename; $new_filename = null; }
        }
    }

    if (isset($_POST['action_save'])) {
        $db_supp         = DB::real_escape_string($conn, $supp);
        $db_date         = DB::real_escape_string($conn, $date);
        $db_desc         = DB::real_escape_string($conn, $desc);
        $db_amount       = parse_dk_number($amount);
        $db_acc          = DB::real_escape_string($conn, $acc);
        $db_proj         = ($proj > 0) ? $proj : 'NULL';
        $db_currency     = DB::real_escape_string($conn, $currency);
        $db_orig_cur     = $orig_currency ? "'".DB::real_escape_string($conn, $orig_currency)."'" : 'NULL';
        $db_orig_amount  = $orig_amount   ? $orig_amount  : 'NULL';
        $db_exch_rate    = $exch_rate     ? $exch_rate    : 'NULL';
        $current_user_id = (int)($_SESSION['user_id'] ?? 1);

        $final_file    = $new_filename ?? $current_attachment;
        $attachment_sq = ", attachment = '$final_file'";

        // NYT (leverandørmodul): leverandørgæld-beregning. Kun relevant for
        // en NY udgift (id===0, se $pay_now ovenfor) af typen 'expense' -
        // en indtægt eller en allerede eksisterende postering bruger altid
        // den gamle, uændrede "betalt med det samme"-opførsel.
        $db_supplier_id_sql = $post_supplier_id ? $post_supplier_id : 'NULL';
        if ($id === 0 && $exp_type === 'expense' && $pay_now == 0) {
            $days = $post_supplier_id ? ($supplier_days_by_id[$post_supplier_id] ?? 8) : 8;
            $computed_due = $post_due_date !== ''
                ? $post_due_date
                : date('Y-m-d', strtotime($date . " +$days days"));
            $db_due_date_sql  = "'" . DB::escape($conn, $computed_due) . "'";
            $db_paid_date_sql = 'NULL';
        } else {
            $db_due_date_sql  = 'NULL';
            $db_paid_date_sql = "'$db_date'"; // betalt med det samme, som hidtil
        }

        // --- Bilagsregel (bogføringslov) ---
        // Bilag PÅKRÆVET ved beløb >= grænse. Under grænsen tilladt uden bilag,
        // men en begrundelse skal angives (gemmes i no_attachment_reason).
        $attachment_limit = (float)($s['conf_attachment_limit'] ?? 500);
        $reason_raw       = trim($_POST['no_attachment_reason'] ?? '');
        $has_attachment   = ($final_file !== '' && $final_file !== null);
        $bilag_err        = '';
        if (!$has_attachment && $db_amount >= $attachment_limit) {
            $bilag_err = sprintf(lang('@An attachment is required for amounts of %s kr or more.'), number_format($attachment_limit, 0, ',', '.'));
        } elseif (!$has_attachment && $reason_raw === '') {
            $bilag_err = lang('@Please state a reason for the missing attachment (the amount is under the limit).');
        }

        // Periodespærring: en NY postering (eller ny bogføring af en
        // redigeret kladde) må ikke kunne lande i en allerede låst periode -
        // fandtes allerede for kreditnotaer/ledger-sletning, men manglede her
        // (fundet ved en systematisk gennemgang, §bogforingslov-compliance).
        $date_lock_err = is_date_locked($conn, $date) ? lang('@The expense date is in a locked accounting period and cannot be saved.') : '';

        // Finansiel lås: en allerede bogført udgift må ikke kunne rettes "on
        // the spot" i beløb/konto/dato/type - det er den samme uforanderlig-
        // heds-svaghed som blev lukket for sletning (C1) og bilag (C3), blot
        // via redigering i stedet. Beskrivende felter (leverandør, tekst,
        // projekt) er stadig frit redigerbare. Rettelse af en bogført udgift
        // skal ske via Annullér (modpostering) + en ny postering.
        $financial_lock_err = '';
        if ($expense_already_posted) {
            $changed = (round((float)$posted_amount, 2) !== round((float)$db_amount, 2))
                || ((string)$posted_account_id !== (string)$db_acc)
                || ((string)$posted_exp_type !== (string)$exp_type)
                || ((string)$posted_exp_date !== (string)$date);
            if ($changed) {
                $financial_lock_err = lang('@This expense is already posted. Amount, account, date and expense/income type cannot be changed — reverse the entry and create a new one instead.');
            }
        }

        // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 30):
        // valgfri opdeling på flere konti. Kun relevant/gemmes hvis udgiften
        // IKKE allerede er bogført (samme uforanderligheds-vindue som amount/
        // account_id ovenfor - $financial_lock_err ville allerede have
        // blokeret et forsøg på at ÆNDRE amount/konto på en bogført udgift,
        // men linjerne skal også eksplicit IGNORERES her, ellers kunne den
        // underliggende kontofordeling stille ændres uden at ledger nogensinde
        // blev genberegnet). Linjebeløbene SKAL summe til nøjagtigt det
        // samme som selve amount-feltet - expenses.amount forbliver den
        // autoritative "total brutto", vist alle andre steder i systemet
        // (expense_list.php m.fl.), så de to må aldrig kunne afvige.
        $lines_err = '';
        $submitted_lines = [];
        $use_split = !$expense_already_posted && isset($_POST['line_account']) && is_array($_POST['line_account']);
        if ($use_split) {
            $line_sum = 0.0;
            foreach ($_POST['line_account'] as $i => $line_acc_raw) {
                $line_acc_id = (int)$line_acc_raw;
                $line_amt_raw = trim($_POST['line_amount'][$i] ?? '');
                if ($line_acc_id <= 0 || $line_amt_raw === '') continue; // spring tomme linjer over
                $line_amt = parse_dk_number($line_amt_raw);
                if ($line_amt <= 0) { $lines_err = lang('@Each line must have a positive amount.'); break; }
                $line_sum += $line_amt;
                $submitted_lines[] = ['account_id' => $line_acc_id, 'amount' => $line_amt, 'description' => trim($_POST['line_desc'][$i] ?? '')];
            }
            if ($lines_err === '') {
                if (count($submitted_lines) < 2) {
                    // Kun 0 eller 1 reel linje indtastet - der er reelt intet at
                    // splitte, gem som en almindelig ét-konto-udgift i stedet
                    // (ingen expense_lines-rækker) - undgår at gemme en
                    // meningsløs "opdeling" på kun én linje.
                    $use_split = false;
                } elseif (round($line_sum, 2) !== round($db_amount, 2)) {
                    $lines_err = sprintf(lang('@The line amounts (%s) must add up to exactly the total amount (%s).'), number_format($line_sum, 2, ',', '.'), number_format($db_amount, 2, ',', '.'));
                }
            }
        }

        if ($bilag_err !== '') {
            $err = $bilag_err;
        } elseif ($attachment_lock_err !== '') {
            $err = $attachment_lock_err;
        } elseif ($date_lock_err !== '') {
            $err = $date_lock_err;
        } elseif ($financial_lock_err !== '') {
            $err = $financial_lock_err;
        } elseif ($lines_err !== '') {
            $err = $lines_err;
        } elseif (!$expense_already_posted && ($ee_missing = ledger_accounts_missing($conn, array_merge(
                      $use_split ? array_column($submitted_lines, 'account_id') : [(int)$acc],
                      [($exp_type === 'income') ? (int)($s['conf_acc_vat'] ?? 6900) : (int)($s['conf_acc_purchase_vat'] ?? 6910),
                       ($supplier_module_ready && $exp_type !== 'income' && $pay_now == 0) ? (int)($s['conf_acc_creditor'] ?? 4000) : (int)($s['conf_acc_bank'] ?? 5000)])))) {
            // Bogføringen ville ramme en konto der ikke findes (ledger_post() afviser den) - afvist før noget gemmes
            $err = sprintf(lang('@The account %d does not exist in the chart of accounts - check Company Settings (posting accounts) or the chart of accounts. Nothing was posted.'), $ee_missing[0]);
        } else {
            DB::begin_transaction($conn);

        if ($id > 0) {
            $db_exp_type = ($exp_type === 'income') ? 'income' : 'expense';
            $sql = "UPDATE expenses SET
                        exp_date      = '$db_date',
                        supplier      = '$db_supp',
                        description   = '$db_desc',
                        account_id    = '$db_acc',
                        amount        = '$db_amount',
                        proj_id       = $db_proj,
                        exp_type      = '$db_exp_type',
                        currency      = '$db_currency',
                        orig_currency = $db_orig_cur,
                        orig_amount   = $db_orig_amount,
                        exch_rate     = $db_exch_rate
                        $attachment_sq
                    WHERE exp_id = $id AND is_cancelled = 0";
            $final_voucher = $vouch;
        } else {
            $final_voucher = next_voucher_no($conn);
            $db_exp_type   = ($exp_type === 'income') ? 'income' : 'expense';
            $sql = "INSERT INTO expenses
                        (exp_date, supplier, description, account_id, amount, attachment, voucher_no,
                         created_by, proj_id, exp_type, currency, orig_currency, orig_amount, exch_rate)
                    VALUES
                        ('$db_date', '$db_supp', '$db_desc', '$db_acc', '$db_amount', '$final_file',
                         $final_voucher, $current_user_id, $db_proj, '$db_exp_type',
                         '$db_currency', $db_orig_cur, $db_orig_amount, $db_exch_rate)";
        }

        if (DB::query($conn, $sql)) {
            $expense_id   = ($id > 0) ? $id : DB::insert_id($conn);
            // Gem begrundelse for manglende bilag (tom hvis bilag findes). DB::query
            // sluger fejlen stille, hvis no_attachment_reason-kolonnen ikke er migreret.
            $store_reason = $has_attachment ? '' : DB::real_escape_string($conn, $reason_raw);
            DB::query($conn, "UPDATE expenses SET no_attachment_reason = '$store_reason' WHERE exp_id = $expense_id");

            // NYT (leverandørmodul): samme "stille fejl hvis endnu ikke
            // migreret"-mønster som no_attachment_reason ovenfor - kører kun
            // hvis migrate_suppliers.php er kørt ($supplier_module_ready),
            // så formularen aldrig kan have tilbudt "Ikke betalt endnu" uden
            // at det reelt kunne gemmes (se $supplier_module_ready-tjekket).
            // supplier_id må gerne opdateres ved BÅDE opret og redigering
            // (beskrivende metadata) - due_date/paid_date sættes kun ved
            // selve oprettelsen ($id===0), aldrig ved en efterfølgende
            // redigering (de ændres derefter udelukkende via en rigtig
            // betaling, expense_actions.php?action=mark_paid).
            if ($supplier_module_ready) {
                DB::query($conn, "UPDATE expenses SET supplier_id = $db_supplier_id_sql WHERE exp_id = $expense_id");
                if ($id === 0) {
                    DB::query($conn, "UPDATE expenses SET due_date = $db_due_date_sql, paid_date = $db_paid_date_sql WHERE exp_id = $expense_id");
                }
            }

            // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde
            // 30): gem den valgfri kontoopdeling FØR selve ledger-posteringen
            // nedenfor (expense_get_lines() læser fra denne tabel). Kun for
            // en IKKE allerede bogført udgift - se $use_split's egen
            // beregning ovenfor for hvorfor.
            if (!$expense_already_posted && expense_lines_has_table($conn)) {
                DB::query($conn, "DELETE FROM expense_lines WHERE exp_id = $expense_id");
                if ($use_split) {
                    foreach ($submitted_lines as $line) {
                        $line_desc_sql = $line['description'] !== '' ? "'" . DB::escape($conn, $line['description']) . "'" : 'NULL';
                        DB::query($conn, "INSERT INTO expense_lines (exp_id, account_id, amount, description) VALUES ($expense_id, {$line['account_id']}, {$line['amount']}, $line_desc_sql)");
                    }
                }
            }

            // Sikr et voucher_no (bilagsnummer) — legacy-udgifter kan mangle det,
            // og den stabile journal-kobling afhænger af det.
            if ($final_voucher === '' || $final_voucher === null || (int)$final_voucher === 0) {
                $final_voucher = next_voucher_no($conn);
                DB::query($conn, "UPDATE expenses SET voucher_no = $final_voucher WHERE exp_id = $expense_id");
            }

            $journal_text = "Udgift: " . $db_supp . ($db_desc ? " - " . $db_desc : "");

            // --- Journal (header) — kobles STABILT via voucher_no (ikke den gamle
            //     buggy 'LIKE Udgift ID %', der matchede en vilkårlig udgift) ---
            $jou_row = DB::fetch_assoc(DB::query($conn, "SELECT jou_id FROM journal WHERE voucher_no = '$final_voucher' AND is_cancelled = 0 LIMIT 1"));
            if ($jou_row) {
                $jou_id = (int)$jou_row['jou_id'];
                DB::query($conn, "UPDATE journal SET jou_date = '$db_date', jou_text = 'Udgift ID $expense_id: $journal_text', proj_id = $db_proj WHERE jou_id = $jou_id");
            } else {
                // RETTET (§currency-setting-is-cosmetic-label): journal.currency
                // blev aldrig sat (faldt tilbage til skemaets DEFAULT 'DKK').
                // NYT (BEK 811/2026-gennemgang, [[digital-standard-bookkeeping-registration-goal]]).
                $cb = journal_created_by_sql($conn);
                DB::query($conn, "INSERT INTO journal (jou_date, voucher_no, jou_text, proj_id, trans_type, currency{$cb['col']}) VALUES ('$db_date', '$final_voucher', 'Udgift ID $expense_id: $journal_text', $db_proj, 'expense', '$db_currency'{$cb['val']})");
                $jou_id = DB::insert_id($conn);
            }

            // --- Ledger: kun ryd og genopret hvis dette IKKE var en allerede
            //     bogført udgift - de finansielle felter kan ikke være ændret
            //     her (blokeret ovenfor), så der er intet at genberegne. Undgår
            //     desuden at overskrive de oprindelige linjers created_at
            //     (registreringsdato) med et nyt tidsstempel ved en ren tekst-
            //     redigering (leverandør/beskrivelse/projekt). ---
            if (!$expense_already_posted) {
                DB::query($conn, "DELETE FROM ledger WHERE jou_id = $jou_id"); // ryd evt. rester før første postering

                // RIGTIG dobbeltpostering. Beløbet er brutto (inkl. moms);
                // momssatsen tages fra udgiften/linjen, ellers fra kontoen. Køb:
                //   DEBET udgiftskonto (netto) + DEBET købsmoms + KREDIT bank (brutto).
                // Indtægt vender fortegnene og bruger udgående moms. Balancerer til 0.
                // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde
                // 30): expense_get_lines() er nu den ENESTE autoritative kilde
                // til beløb/konto(er) - returnerer ÉN syntetisk linje (denne
                // udgifts egen account_id/amount/vat_rate) hvis udgiften ikke
                // er splittet, så koden herunder er UÆNDRET i det almindelige
                // (ikke-splittede) tilfælde, blot udtrukket til en løkke.
                $exp_lines = expense_get_lines($conn, $expense_id);
                $gross     = 0.0;
                $vat_total = 0.0;

                $acc_bank  = (int)($s['conf_acc_bank'] ?? 5000);
                $is_income = ($db_exp_type === 'income');
                $vat_acc   = $is_income ? (int)($s['conf_acc_vat'] ?? 6900) : (int)($s['conf_acc_purchase_vat'] ?? 6910);
                $sign      = $is_income ? -1 : 1;   // udgift: DEBET konto; indtægt: KREDIT konto

                // NYT (leverandørmodul): en udgift registreret som "Ikke betalt
                // endnu" krediterer den konfigurerede kreditor-/gældskonto i
                // stedet for banken - selve betalingen bogføres først senere,
                // som en separat postering, når regningen reelt betales (se
                // expense_actions.php?action=mark_paid). Gælder aldrig en
                // indtægt - der findes intet "leverandørgæld"-begreb der.
                $bank_or_creditor = ($supplier_module_ready && !$is_income && $pay_now == 0)
                    ? (int)($s['conf_acc_creditor'] ?? 4000)
                    : $acc_bank;

                foreach ($exp_lines as $line) {
                    ledger_post($conn, $jou_id, $line['account_id'], $sign * $line['net']); // udgifts-/indtægtskonto (netto) pr. linje
                    $gross     += $line['gross'];
                    $vat_total += $line['vat'];
                }
                if ($vat_total != 0) ledger_post($conn, $jou_id, $vat_acc, $sign * round($vat_total, 2)); // moms (køb 6910 / salg 6900), samlet
                ledger_post($conn, $jou_id, $bank_or_creditor, -$sign * round($gross, 2));    // bank ELLER kreditorkonto (brutto, modpost)

                // REVISIONSSPOR: modstykket til invoice_post_action.php's
                // POST_INVOICE - selve bogføringen af en ny udgift/indtægt
                // logges nu, ikke kun annullering (CANCEL_EXPENSE i
                // expense_actions.php). Højere volumen end de øvrige
                // log_action()-kald, da dette er den hyppigste finansielle
                // handling i systemet, men parallel til det der allerede
                // logges for fakturaer. Bruger-anmodet.
                log_action($conn, 'POST_EXPENSE', 'expenses', $expense_id, null,
                    ['voucher_no' => $final_voucher, 'amount' => $gross, 'exp_type' => $db_exp_type]);
            }
            DB::commit($conn);
            if ($file_to_delete && file_exists($file_to_delete)) @unlink($file_to_delete);
            if ($depot_file_to_remove && file_exists($depot_file_to_remove)) @unlink($depot_file_to_remove);
            header("Location: expense_list.php?msg=saved");
            exit;
        } else {
            DB::rollback($conn);
            $err = "SQL Error: " . DB::error($conn);
        }
        } // luk bilagsregel-else (gemning sprunget over ved valideringsfejl)
    }
}

$page_title = $id > 0 ? lang('@Edit Document / Voucher') : lang('@Register Document / Voucher');

htm_Header($page_title);
showMenu();
htm_HelpSystem();
if ($err) htm_Alert($err, 'error');
if ($msg) htm_Alert($msg, 'info');

echo '<form id="global_exp_form" method="POST" action="expense_edit.php?id='.$id.'" enctype="multipart/form-data" target="_self">';
csrf_field();
echo '<div id="ai_toggle_container" style="display:none;"><input type="hidden" id="hidden_ai_action" name="toggle_ai_action" value="0"><input type="checkbox" id="hidden_ai_checkbox" name="use_ai_scan_toggle" value="1" '.($use_ai_scan === 1 ? 'checked' : '').'></div>';
echo '<div style="display:flex; gap:10px; max-width:1400px; margin:0 auto; padding:0 10px; align-items:flex-start;">';

    // VENSTRE SIDE (depot — uændret)
    echo '<div style="flex:6; min-width:300px; position:sticky; top:20px;">';
        htm_Card_('🗄️ ' . lang('@Depot & Dropzone'), '100%', '', false);
          echo '<div id="MainDropzone" style="margin-bottom:15px; border:2px dashed #34495e; border-radius:4px; height:calc(100vh - 510px); min-height:350px; background:#f1f2f6; position:relative; overflow:hidden !important; user-select:none;">';
                echo '<div id="previewPlaceholder" style="color:#7f8c8d; text-align:center; padding:20px; position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); z-index:1; width:80%;">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:3em; color:#718096; margin-bottom:10px;"></i><br>
                    <strong>' . lang('@Drag voucher here') . '</strong><br>
                    <span style="font-size:12px; color:#95a5a6;">' . lang('@Or click a document below') . '</span>
                    <div style="margin-top:20px; padding:8px; border-top:1px solid #e2e8f0; font-size:11px; color:#7f8c8d; line-height:1.5;">
                        <i class="fa-solid fa-circle-info"></i> <strong>' . lang('@Navigation:') . '</strong> ' . lang('@Scroll wheel to zoom') . ' • ' . lang('@Click & drag to pan') . ' • ' . lang('@Double click to reset') . '
                    </div>
                </div>';
                echo '<div class="zoom-help-tooltip" style="position:absolute; top:10px; right:10px; z-index:20; background:rgba(52,73,94,0.8); color:white; width:22px; height:22px; border-radius:50%; text-align:center; line-height:22px; font-weight:bold; cursor:help; font-size:12px;">?
                    <span class="zoom-help-text" style="visibility:hidden; width:220px; background-color:#2c3e50; color:#fff; text-align:left; border-radius:6px; padding:10px; position:absolute; z-index:21; top:30px; right:0; opacity:0; transition:opacity 0.2s; font-weight:normal; font-size:11px; line-height:1.6; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
                        <strong>🔍 ' . lang('@Zoom & Navigation:') . '</strong><br>
                        • <strong>' . lang('@Scroll wheel:') . '</strong> ' . lang('@Zoom in/out on mouse position.') . '<br>
                        • <strong>' . lang('@Click & Drag:') . '</strong> ' . lang('@Move around the document.') . '<br>
                        • <strong>' . lang('@Double click:') . '</strong> ' . lang('@Reset to 100% view.') . '
                    </span>
                </div>';
        echo '<div id="zoomWrapper" style="width:100%; display:none; transform-origin:0px 0px; transform:scale(1) translate(0px,0px); cursor:grab; position:absolute; top:0; left:0; margin:0; padding:0; overflow:hidden !important;">';
            echo '<iframe id="pdfPreview" style="width:104%; height:100%; border:none; display:block; pointer-events:none; margin:0; padding:0; overflow:hidden !important;" scrolling="no"></iframe>';
            echo '<img id="imgPreview" style="width:100%; height:auto; display:none; margin:0; padding:0; object-fit:contain;" src="">';
        echo '</div>';
                echo '<div id="selectedBadge" style="display:none; position:absolute; bottom:0; left:0; right:0; background:rgba(46,204,113,0.95); color:white; padding:8px; text-align:center; font-weight:bold; font-size:12px; z-index:10;"></div>';
            echo '</div>';

            $depot = getDepotFiles();
            echo '<div class="depot-tabs" style="display:flex; background:#e2e8f0; border-radius:4px 4px 0 0; padding:4px 4px 0 4px; gap:2px;">';
            $active_tab = "mail";
            foreach (['mail' => lang('@Mail'), 'scanner' => lang('@Scanner'), 'photo' => lang('@Photo'), 'download' => lang('@Download')] as $key => $lbl) {
                $count = isset($depot[$key]) ? count($depot[$key]) : 0;
                echo '<button type="button" class="tab-lnk '.($key === $active_tab ? 'active':'').'" onclick="skiftDepotTab(\''.$key.'\')" id="tab_btn_'.$key.'" style="flex:1; padding:6px; border:none; border-radius:4px 4px 0 0; font-size:11px; font-weight:bold; cursor:pointer; background:#cbd5e0;">'.$lbl.' ('.$count.')</button>';
            }
            echo '</div>';
            echo '<div style="max-height:140px; overflow-y:auto; border:1px solid #cbd5e0; border-top:none; padding:10px; border-radius:0 0 4px 4px; background:#fafafa; font-size:13px; margin-bottom:10px;">';
            foreach (['mail', 'scanner', 'photo', 'download'] as $source) {
                $files = $depot[$source] ?? [];
                echo '<div class="tab-content-panel" id="panel_'.$source.'" style="display:'.($source === $active_tab ? 'block':'none').';">';
                if ($source === 'mail') {
                    echo '<div style="margin-bottom:8px; padding:6px 10px; background:#edf2f7; border-radius:4px; font-size:11px; color:#4a5568; display:flex; align-items:center; justify-content:space-between; gap:10px;">';
                    echo '<span><i class="fa-solid fa-circle-info" style="color:#3182ce;"></i> '.lang('@Open the document inbox to register a voucher directly from an email.').'</span>';
                    echo '<a href="mail_inbox.php?box=voucher" data-hint="' . lang('@Open the voucher inbox to pick an attachment from an email') . '" style="color:#2b6cb0; font-weight:bold; text-decoration:none; white-space:nowrap;"><i class="fa-solid fa-envelope"></i> '.lang('@Open Inbox').'</a>';
                    echo '</div>';
                }
                if (empty($files)) {
                    echo '<div style="color:#999; padding:15px; text-align:center; font-style:italic;">'.lang('@No files found').'</div>';
                } else {
                    foreach ($files as $file) {
                        echo '<div class="depot-item" id="item_'.md5($file['rel_path']).'" style="padding:6px 10px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed #eee; cursor:pointer;" onclick="previewDepotFile(\''.$file['rel_path'].'\')">';
                        echo '<span style="color:#2980b9; word-break:break-all;">📄 '.htmlspecialchars($file['filename']).'</span>';
                        echo '<button type="button" onclick="selectDepotFile(event, \''.$file['rel_path'].'\', \''.htmlspecialchars($file['filename']).'\')" style="background:#e67e22; color:white; border:none; padding:3px 8px; border-radius:3px; font-size:11px; cursor:pointer; font-weight:bold;">'.lang('@Select').'</button>';
                        echo '</div>';
                    }
                }
                echo '</div>';
            }
            echo '</div>';
            echo '<div style="display:flex; gap:8px;">';
            echo '<label style="flex:2; display:block; background:#e2e8f0; color:#4a5568; text-align:center; padding:10px; border-radius:4px; cursor:pointer; font-weight:bold; font-size:12px; border:1px solid #cbd5e0; margin:0;">📁 '.lang('@Choose local file').'<input type="file" name="attachment" style="display:none;" onchange="handleLocalFileSelect(this);"></label>';
            echo '<button type="submit" name="action_scan_only" value="1" id="AiScanButton" formnovalidate style="flex:1; display:none; background:#e67e22; color:white; border:none; padding:10px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:12px;"><i class="fa-solid fa-robot"></i> '.lang('@Fetch data with AI').'</button>';
            echo '</div>';
        htm_Card_end();
    echo '</div>';

    // HØJRE SIDE
    echo '<div style="flex:6; min-width:320px;">';
        htm_Card_('📝 ' . $page_title, '100%');

            htm_Field(icon:'fa-tag', labl:'@Type', name:'exp_type', valu:$exp_type, type:'sele',
                opti:['expense' => '📤 '.lang('@Expense'), 'income' => '📥 '.lang('@Income')], wdth:'30%');
            htm_ProjektCodeField($conn, $proj ?: null, '30%');

            $header_switch = '<br><label style="font-weight:bold; cursor:help; margin:15px; align-items:center; gap:6px; font-size:14px; user-select:none; color:#7f8c8d;">'
                . '<input type="checkbox" id="visible_ai_trigger" '.($use_ai_scan === 1 ? 'checked' : '').' onchange="triggerAiToggle(this.checked);" style="width:14px; height:14px; cursor:pointer; margin:0; vertical-align:middle;">🤖 '.lang('@AI Scan').': ';
            $header_switch .= $use_ai_scan === 1
                ? '<span style="color:#2ecc71;">'.lang('@Active').'<span style="color:var(--text-muted); font-weight:normal;"> -- '.lang('@Orange fields can be filled by AI Scan.').'</span></span>'
                : '<span style="color:#e67e22;">'.lang('@Off').'</span>';
            $header_switch .= '</label><br><br>';
            echo $header_switch;

        echo '<div style="margin-bottom:15px; font-size:12px; color:#4a5568; line-height:1.4;">';
            htm_Field('fa-hashtag', '@Voucher',     'vouch',    ($id > 0 ? $vouch : lang('@Generated')), 'text', null, 'readonly class="text-center" leg:left', '30%');
            htm_Field('fa-calendar', '@Date',       'exp_date', $date,   'date', null, 'required onchange="checkExpenseDuplicate()"', '40%');
            htm_Field('fa-coins',    sprintf(lang('@Amount %s'), $base_currency), 'amount',   number_format((float)$amount, 2, ',', ''), 'text', null, 'required onblur="checkExpenseDuplicate()" style="text-align:right;"', '30%');

            // NYT (leverandørmodul): skrivebeskyttet betalingsstatus for en
            // allerede oprettet udgift - betalingsmetoden vælges kun ved
            // selve oprettelsen (se payment-section nedenfor), og ændres
            // derefter udelukkende via en rigtig betaling (mark_paid),
            // aldrig ved almindelig redigering.
            if ($supplier_module_ready && $id > 0 && !empty($due_date)) {
                $is_paid_row = !empty($paid_date);
                $status_bg   = $is_paid_row ? 'var(--bg-alert-success)' : 'var(--bg-alert-warning)';
                $status_fg   = $is_paid_row ? 'var(--text-alert-success)' : 'var(--text-alert-warning)';
                echo '<div style="margin:5px 5px 15px; padding:10px 14px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; gap:10px; background:'.$status_bg.'; color:'.$status_fg.';">';
                echo '<span>';
                if ($is_paid_row) {
                    echo '<i class="fa-solid fa-circle-check"></i> ' . sprintf(lang('@Paid on %s'), date(CONF_DATE_FORMAT, strtotime($paid_date)));
                } else {
                    echo '<i class="fa-solid fa-clock"></i> ' . sprintf(lang('@Owed to supplier - due %s'), date(CONF_DATE_FORMAT, strtotime($due_date)));
                }
                echo '</span>';
                if (!$is_paid_row) {
                    $pay_confirm = addslashes(lang('@Register that this expense has now been paid from the bank account?'));
                    echo '<a href="expense_actions.php?action=mark_paid&id='.$id.'" data-hint="' . lang('@Register the expense as paid from the bank account (you are asked first)') . '" onclick="return confirm(\''.$pay_confirm.'\');"
                        style="background:var(--color-success); color:#fff; padding:5px 12px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.85em; white-space:nowrap;">
                        <i class="fa-solid fa-money-bill-wave"></i> ' . lang('@Pay Now') . '</a>';
                }
                echo '</div>';
            }

            // ── Valuta-sektion (kun når valuta-modulet er aktivt) ────────────
            if ($currency_module) {
            echo '<div id="currency-section" style="margin:10px 5px; padding:10px; background:var(--bg-panel); border-radius:8px; border:2px dashed blue;">';
            echo '<div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">';
            echo '<label style="font-weight:bold; font-size:13px; color:var(--text-main); white-space:nowrap;"><i class="fa fa-exchange" style="margin-right:5px; color:#7f8c8d;"></i>'.lang('@Foreign currency').'</label>';
            echo '<label style="font-size:12px; cursor:pointer; display:flex; align-items:center; gap:5px; color:var(--text-muted);">';
            $fc_checked = ($orig_currency && $orig_currency !== $base_currency) ? 'checked' : '';
            echo '<input type="checkbox" id="fc-toggle" '.$fc_checked.' onchange="toggleForeignCurrency(this.checked)"> '.lang('@Receipt is in foreign currency');
            echo '</label></div>';

            echo '<div id="fc-fields" style="display:'.($fc_checked ? 'grid' : 'none').'; grid-template-columns:1fr 1fr 1fr; gap:8px; align-items:end;">';

            // Originalvaluta
            echo '<div><label style="font-size:11px; font-weight:bold; color:var(--text-muted);">'.lang('@Currency').'</label>';
            echo '<select name="orig_currency" id="fc-currency" onchange="fetchRate()" style="width:100%; padding:6px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main); font-size:13px;">';
            // RETTET (§currency-setting-is-cosmetic-label): firmaets egen
            // bogføringsvaluta filtreres nu fra listen (uanset hvilken den
            // er), i stedet for altid at udelukke 'DKK' specifikt.
            $currencies = array_values(array_diff(['EUR','USD','GBP','SEK','NOK','CHF','JPY','CAD','AUD','PLN','CZK','HUF','RON','ISK','DKK'], [$base_currency]));
            foreach ($currencies as $c) {
                $sel = ($orig_currency === $c) ? ' selected' : '';
                echo '<option value="'.$c.'"'.$sel.'>'.$c.'</option>';
            }
            echo '</select></div>';

            // Beløb i originalvaluta
            echo '<div><label style="font-size:11px; font-weight:bold; color:var(--text-muted);">'.lang('@Amount in currency').'</label>';
            echo '<input type="text" name="orig_amount" id="fc-orig-amount" value="'.($orig_amount ? number_format($orig_amount, 2, ',', '') : '').'"
                oninput="calcDkk()" placeholder="0,00"
                style="width:100%; padding:6px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main); font-size:13px; text-align:right; box-sizing:border-box;"></div>';

            // Kurs
            echo '<div><label style="font-size:11px; font-weight:bold; color:var(--text-muted);">'.lang('@Exchange rate').' <span id="fc-rate-date" style="font-weight:normal; color:var(--text-muted); font-size:10px;"></span></label>';
            echo '<div style="display:flex; gap:4px;">';
            echo '<input type="text" name="exch_rate" id="fc-rate" value="'.($exch_rate ? number_format($exch_rate, 4, ',', '') : '').'"
                oninput="calcDkk()" placeholder="0,0000"
                style="flex:1; padding:6px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main); font-size:13px; text-align:right;">';
            echo '<button type="button" onclick="fetchRate()" title="'.lang('@Fetch current rate').'"
                style="padding:6px 8px; background:var(--color-primary); color:white; border:none; border-radius:4px; cursor:pointer; font-size:13px;">↻</button>';
            echo '</div></div>';

            echo '</div>'; // fc-fields

            // Info-linje
            echo '<div id="fc-info" style="display:'.($fc_checked ? 'block' : 'none').'; margin-top:8px; font-size:11px; color:var(--text-muted); background:var(--bg-card); padding:6px 10px; border-radius:4px; border-left:3px solid var(--color-primary);">';
            echo '<i class="fa fa-info-circle"></i> '.sprintf(lang('@%s amount is calculated automatically. Exchange rate is saved with the voucher per Danish bookkeeping law.'), htmlspecialchars($base_currency));
            echo '</div>';
            echo '</div>'; // currency-section
            } // if currency_module
            // ── Slut valuta-sektion ─────────────────────────────────────────

            // NYT (leverandørmodul): valgfri dropdown til at koble en
            // leverandørstamdata-post - udfylder fritekstfeltet 'supplier'
            // automatisk via JS (fillSupplierName()), fritekstfeltet
            // forbliver den reelt gemte værdi.
            if ($supplier_module_ready) {
                htm_Field(icon:'fa-industry', labl:'@Link to Supplier', name:'supplier_id', valu:$supplier_id ?? '',
                    type:'sele', opti:$supplier_options, extr:'onchange="fillSupplierName(this)" leg:left', wdth:'100%');
            }
            htm_Field('fa-truck',       '@Supplier',    'supplier',    $supp, 'text', null, 'required onblur="checkExpenseDuplicate()" leg:left', '100%');
            htm_Field('fa-info-circle', '@Description', 'description', $desc, 'text', null, 'leg:left',          '100%');
            // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): advarer
            // - blokerer IKKE - hvis en udgift med samme leverandør/beløb/dato
            // allerede findes, FØR gem. Klassisk fejlkilde: en kvittering der
            // ved en fejl uploades/registreres to gange. Kun relevant ved
            // OPRETTELSE - en eksisterende udgift der redigeres har naturligvis
            // altid "sig selv" som match, ekskluderes via exclude_id.
            echo '<div id="exp_duplicate_warning" style="display:none; margin:10px 0; padding:10px 14px; background:var(--bg-panel); border-left:4px solid var(--color-warning); border-radius:4px; font-size:0.9em;"></div>';

            // NYT (leverandørmodul): betalingsmetode - kun redigerbar ved
            // selve oprettelsen ($id===0), se filens header-kommentar.
            if ($supplier_module_ready && $id === 0) {
                $default_due = date('Y-m-d', strtotime('+8 days'));
                echo '<div id="payment-section" style="margin:10px 5px 15px; padding:10px; background:var(--bg-panel); border-radius:8px; border:2px dashed #16a085;">';
                echo '<label style="font-weight:bold; font-size:13px; display:flex; align-items:center; gap:8px; cursor:pointer; color:var(--text-main);">';
                echo '<input type="checkbox" id="pay_now" name="pay_now" value="1" checked onchange="togglePaymentFields(this.checked)" style="width:14px; height:14px; cursor:pointer;">';
                echo lang('@Pay immediately (from bank account)');
                echo '</label>';
                echo '<div id="due-date-wrap" style="display:none; margin-top:8px;">';
                htm_Field(icon:'fa-calendar-day', labl:'@Due Date', name:'due_date', valu:$default_due, type:'date', extr:'leg:left', wdth:'50%');
                echo '</div>';
                echo '<div style="font-size:11px; color:var(--text-muted); margin-top:6px;">';
                echo '<i class="fa fa-info-circle"></i> ' . lang('@Only applies to expenses (not income). Uncheck to record this as owed to the supplier instead - it will appear on the Aging Report until marked paid.');
                echo '</div>';
                echo '</div>';
            }

            echo '<div style="margin-bottom:15px; padding:0 5px; margin-top:15px;">';
            echo '<label style="display:block; font-weight:bold; margin-bottom:8px; font-size:13px; color:#2c3e50; text-align:left;"><i class="fa fa-list" style="margin-right:5px; color:#7f8c8d;"></i>'.lang('@Select Finance Account');
            echo '<span id="AccountPanelHint" style="font-size:11px; color:#e67e22; font-style:italic; font-weight:normal; margin-left:10px;">(<i class="fa-solid fa-lock"></i> '.lang('@Please select or upload a voucher first to unlock account selection.').')</span>';
            echo '</label>';
            echo '<div id="QuickCategoryPanel" style="display:flex; flex-wrap:wrap; gap:8px; opacity:0.3; pointer-events:none; transition:opacity 0.3s;">';
            require_once __DIR__ . '/inc/accounts.inc.php';     // slukkede konti udeladt (den valgte beholdes), dæmpede nederst og nedtonet
            $a_res = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE acc_type = 'expense' AND " . acc_visible_sql($conn, '', [(int)$acc]) . " ORDER BY " . acc_order_sql($conn) . "acc_id");
            while ($account = DB::fetch_assoc($a_res)) {
                $acc_id_val = $account['acc_id']; $full_name = $acc_id_val." - ".acc_label($account['acc_name'], $account['acc_status'] ?? null);
                echo '<label class="radio-card-btn '.($acc == $acc_id_val ? 'active-radio-btn' : '').'" title="'.htmlspecialchars($full_name).'"><input type="radio" name="account_id" value="'.$acc_id_val.'" '.($acc == $acc_id_val ? 'checked' : '').' onchange="highlightSelectedRadio(this)" required><span>'.$full_name.'</span></label>';
            }
            echo '</div></div>';

            // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde
            // 30): valgfri opdeling af DENNE udgift på flere konti - fx en
            // kvittering med både kontorartikler og bilekstra. Kun tilbudt
            // hvis migrationen er kørt OG udgiften ikke allerede er bogført
            // (samme uforanderligheds-vindue som selve kontofeltet ovenfor).
            // "Bevar totalbeløbet" håndhæves server-side (se $lines_err) -
            // klientens løbende sum er kun til øjeblikkelig visuel feedback.
            if (expense_lines_has_table($conn) && !$expense_already_posted) {
                $split_acc_opts = [];
                $sa_res = DB::query($conn, "SELECT acc_id, acc_name" . acc_status_col($conn) . " FROM accounts WHERE acc_type = 'expense' AND " . acc_visible_sql($conn, '', array_column($existing_expense_lines, 'account_id')) . " ORDER BY " . acc_order_sql($conn) . "acc_id");
                while ($sa = DB::fetch_assoc($sa_res)) { $split_acc_opts[(int)$sa['acc_id']] = $sa['acc_id'] . ' - ' . acc_label($sa['acc_name'], $sa['acc_status'] ?? null); }

                echo '<div style="margin:10px 5px 15px;">';
                echo '<label style="display:flex; align-items:center; gap:8px; font-weight:bold; font-size:13px; cursor:pointer; margin-bottom:8px;">';
                echo '<input type="checkbox" id="split_toggle" onchange="toggleExpenseSplit(this.checked)" ' . (!empty($existing_expense_lines) ? 'checked' : '') . ' style="width:14px; height:14px;">';
                echo lang('@Split this expense across multiple accounts');
                echo '</label>';
                echo '<div id="split_panel" style="display:' . (!empty($existing_expense_lines) ? 'block' : 'none') . '; padding:10px; background:var(--bg-panel); border-radius:8px; border:2px dashed #2196f3;">';
                echo '<table id="split_lines_tbl" style="width:100%; border-collapse:collapse; font-size:0.9em;">';
                echo '<thead><tr><th style="text-align:left; padding:4px;">' . lang('@Account') . '</th><th style="text-align:left; padding:4px;">' . lang('@Description') . '</th><th style="text-align:right; padding:4px; width:120px;">' . lang('@Amount') . '</th><th></th></tr></thead>';
                echo '<tbody></tbody></table>';
                echo '<button type="button" onclick="addSplitLine()" style="margin-top:6px; padding:4px 10px; font-size:0.85em; cursor:pointer;"><i class="fa fa-plus"></i> ' . lang('@Add Line') . '</button>';
                echo '<div style="margin-top:8px; font-size:0.85em;">' . lang('@Line total:') . ' <span id="split_sum">0,00</span> / <span id="split_target">0,00</span></div>';
                echo '</div></div>';
                ?>
                <script>
                var SPLIT_ACCOUNTS = <?php echo json_encode($split_acc_opts); ?>;
                var SPLIT_EXISTING = <?php echo json_encode(array_map(fn($l) => ['account_id' => (int)$l['account_id'], 'amount' => number_format((float)$l['amount'], 2, ',', ''), 'description' => $l['description'] ?? ''], $existing_expense_lines)); ?>;
                var splitLineIdx = 0;
                function splitAccountOptionsHtml(selected) {
                    var h = '';
                    for (var accId in SPLIT_ACCOUNTS) {
                        h += '<option value="' + accId + '"' + (String(accId) === String(selected) ? ' selected' : '') + '>' + SPLIT_ACCOUNTS[accId] + '</option>';
                    }
                    return h;
                }
                function addSplitLine(accId, desc, amt) {
                    var tbody = document.querySelector('#split_lines_tbl tbody');
                    var i = splitLineIdx++;
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td style="padding:2px;"><select name="line_account[' + i + ']" style="width:100%;" onchange="updateSplitSum()">' + splitAccountOptionsHtml(accId || '') + '</select></td>'
                        + '<td style="padding:2px;"><input type="text" name="line_desc[' + i + ']" value="' + (desc ? desc.replace(/"/g, '&quot;') : '') + '" style="width:100%;"></td>'
                        + '<td style="padding:2px;"><input type="text" name="line_amount[' + i + ']" value="' + (amt || '') + '" style="width:100%; text-align:right;" oninput="updateSplitSum()"></td>'
                        + '<td style="padding:2px;"><button type="button" onclick="this.closest(\'tr\').remove(); updateSplitSum();" style="cursor:pointer;">&times;</button></td>';
                    tbody.appendChild(tr);
                    updateSplitSum();
                }
                function updateSplitSum() {
                    var sum = 0;
                    document.querySelectorAll('#split_lines_tbl input[name^="line_amount"]').forEach(function(inp) {
                        sum += parseFloat((inp.value || '0').replace(/\./g, '').replace(',', '.')) || 0;
                    });
                    document.getElementById('split_sum').textContent = sum.toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2});
                    var target = parseFloat((document.querySelector('input[name="amount"]').value || '0').replace(/\./g, '').replace(',', '.')) || 0;
                    document.getElementById('split_target').textContent = target.toLocaleString('da-DK', {minimumFractionDigits:2, maximumFractionDigits:2});
                    document.getElementById('split_sum').style.color = (Math.abs(sum - target) < 0.01) ? 'var(--color-success)' : 'var(--color-danger)';
                }
                function toggleExpenseSplit(on) {
                    document.getElementById('split_panel').style.display = on ? 'block' : 'none';
                    if (on && document.querySelectorAll('#split_lines_tbl tbody tr').length === 0) {
                        addSplitLine(); addSplitLine();
                    }
                    if (!on) {
                        // Fjern alle linje-input helt, når opdeling slås fra, så
                        // et evt. gemt (men usynligt) linjesæt ikke ved et uheld
                        // sendes med alligevel.
                        document.querySelector('#split_lines_tbl tbody').innerHTML = '';
                    }
                }
                document.addEventListener('DOMContentLoaded', function() {
                    if (SPLIT_EXISTING.length > 0) {
                        SPLIT_EXISTING.forEach(function(l) { addSplitLine(l.account_id, l.description, l.amount); });
                    }
                    var amtField = document.querySelector('input[name="amount"]');
                    if (amtField) amtField.addEventListener('input', updateSplitSum);
                });
                </script>
                <?php
            }

            if (!empty($current_attachment)) {
                echo '<div style="margin-bottom:15px; padding:10px; background:#f8f9fa; border-left:4px solid #e67e22; display:flex; justify-content:space-between; align-items:center; border-radius:4px;">
                    <div>'.htm_GetDocIcon($current_attachment, 'expense').' <a href="uploads/'.$current_attachment.'" target="_blank" style="font-weight:bold; text-decoration:none;">'.lang('@View current attachment').'</a><br>
                    <span style="font-size:11px; color:#7f8c8d;">'.lang('@Filename').': '.$current_attachment.'</span></div>
                    <label style="color:#c0392b; font-size:13px; cursor:pointer;"><input type="checkbox" name="delete_attachment" value="1" style="margin-right:5px; vertical-align:middle;"> '.lang('@Remove attachment').'</label></div>';
            }

            // Begrundelse for manglende bilag — kræves (server-side) når beløbet er
            // under grænsen og der ikke er vedhæftet et bilag. Ved beløb >= grænse
            // blokeres gemning helt uden bilag.
            $__att_limit = (float)($s['conf_attachment_limit'] ?? 500);
            echo '<div id="no-att-reason-wrap" style="margin-bottom:15px;">';
            echo '<label style="display:block; font-size:12px; font-weight:bold; color:#4a5568; margin-bottom:4px;">'
               . lang('@Reason for missing attachment') . ' <span style="font-weight:normal; color:#7f8c8d;">('
               . sprintf(lang('@only for amounts under %s kr'), number_format($__att_limit, 0, ',', '.')) . ')</span></label>';
            echo '<input type="text" name="no_attachment_reason" value="'.htmlspecialchars($no_attachment_reason).'" '
               . 'placeholder="'.lang('@E.g. small cash purchase, receipt lost').'" '
               . 'style="width:100%; box-sizing:border-box; padding:8px; border:1px solid #cbd5e0; border-radius:4px; font-size:13px;">';
            echo '</div>';
            // Vis kun begrundelsesfeltet når beløbet er under grænsen (hvor det er
            // relevant). Ved beløb >= grænse SKAL der vedhæftes bilag i stedet.
            echo '<script>(function(){var limit='.json_encode($__att_limit).';'
               . 'var amt=document.querySelector("[name=amount]"),wrap=document.getElementById("no-att-reason-wrap");'
               . 'function t(){if(!amt||!wrap)return;var v=parseFloat(String(amt.value).replace(",","."))||0;wrap.style.display=(v<limit)?"block":"none";}'
               . 'if(amt){amt.addEventListener("input",t);amt.addEventListener("change",t);}t();})();</script>';

            echo '<input type="hidden" id="depot_file_path" name="depot_file_path" value="'.(isset($_POST['depot_file_path']) ? htmlspecialchars($_POST['depot_file_path']) : '').'">';
            echo '<input type="hidden" name="currency" value="'.htmlspecialchars($base_currency).'">';
            if (!$currency_module && $orig_currency && $orig_currency !== $base_currency) {
                // Modul deaktiveret: bevar eksisterende valuta-værdier, så de ikke tabes ved gem
                echo '<input type="hidden" name="orig_currency" value="'.htmlspecialchars($orig_currency).'">';
                echo '<input type="hidden" name="orig_amount" value="'.($orig_amount ? number_format($orig_amount, 2, ',', '') : '').'">';
                echo '<input type="hidden" name="exch_rate" value="'.($exch_rate ? number_format($exch_rate, 4, ',', '') : '').'">';
            }

            $save_label = ($exp_type === 'income') ? lang('@Save Income') : lang('@Save Expense');
            echo '<div style="display:flex; gap:10px; margin-top:20px; border-top:1px solid #eee; padding-top:20px;">
                <button type="submit" name="action_save" value="1" style="flex:2; background:#2ecc71; color:white; border:none; padding:10px; border-radius:4px; font-weight:bold; cursor:pointer;"><i class="fa fa-save"></i> '.$save_label.'</button>';
            htm_Button('fa-times', lang('@Cancel'), 'secondary', 'expense_list.php', 'flex:1;', 'data-hint="'.lang('@Discard changes and return to the expense list').'"');
            // NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde
            // 28): direkte link til netop DENNE udgifts historik.
            if ($id > 0 && ($_SESSION['user_role'] ?? '') === 'admin') {
                htm_Button('fa-clock-rotate-left', lang('@View History'), 'secondary', 'audit_log.php?table_name=expenses&row_id='.$id, 'flex:1;', 'data-hint="'.lang('@See who created, edited or posted this expense').'"');
            }
            echo '</div>';
        htm_Card_end();
    echo '</div>';
echo '</div>';
htm_Footer();
echo '</form>';
?>

<script>
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): kalder
// expense_duplicate_check.php ved skift i leverandør-/beløbs-/dato-
// felterne - advarer, blokerer ikke, se dens egen header for baggrunden.
function checkExpenseDuplicate() {
    var box = document.getElementById('exp_duplicate_warning');
    if (!box) return;
    var supplier = document.querySelector('[name="supplier"]');
    var amount   = document.querySelector('[name="amount"]');
    var expDate  = document.querySelector('[name="exp_date"]');
    if (!supplier || !amount || !expDate) return;
    var s = (supplier.value || '').trim();
    var a = (amount.value || '').toString().replace(',', '.');
    var d = expDate.value || '';
    if (s === '' || !a || parseFloat(a) <= 0 || !d) { box.style.display = 'none'; return; }
    var params = new URLSearchParams({ supplier: s, amount: a, exp_date: d, exclude_id: <?php echo (int)$id; ?> });
    fetch('expense_duplicate_check.php?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.duplicate) {
                box.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:var(--color-warning);"></i> '
                    + <?php echo json_encode(lang('@A similar expense (same supplier, amount and date) already exists')); ?>
                    + (data.description ? ': "' + data.description.replace(/</g, '&lt;') + '"' : '') + '. '
                    + <?php echo json_encode(lang('@If this is intentional, you can safely continue and save.')); ?>;
                box.style.display = 'block';
            } else {
                box.style.display = 'none';
            }
        })
        .catch(function() { box.style.display = 'none'; });
}
<?php if ($currency_module): ?>
// ── Valuta-widget ─────────────────────────────────────────────────────────────
// RETTET (§currency-setting-is-cosmetic-label): kursen blev altid regnet til
// hardkodet 'DKK' - se samme rettelse i invoice_edit.php.
var _fcRates = {};
var _fcBase  = 'EUR';
var _fcBaseCcy = <?php echo json_encode($base_currency); ?>;

function toggleForeignCurrency(on) {
    document.getElementById('fc-fields').style.display = on ? 'grid' : 'none';
    document.getElementById('fc-info').style.display   = on ? 'block' : 'none';
    if (on && Object.keys(_fcRates).length === 0) fetchRate();
}

function fetchRate() {
    var currency = document.getElementById('fc-currency').value;
    if (!currency || currency === _fcBaseCcy) return;
    fetch('currency_proxy.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            _fcRates = data.rates;
            _fcBase  = data.base;
            document.getElementById('fc-rate-date').textContent = '(' + data.date + ')';
            setRate(currency);
        })
        .catch(function() {
            document.getElementById('fc-rate').placeholder = '<?php echo lang('@Could not load rates'); ?>';
        });
}

function setRate(currency) {
    var rate = 0;
    if (currency === _fcBaseCcy) {
        rate = 1;
    } else if (currency === _fcBase) {
        // ECB-pivotvalutaen (EUR) til firmaets base
        rate = _fcRates[_fcBaseCcy] || 0;
    } else {
        // Kryds: currency → EUR → firmaets base ("|| 1" dækker det tilfælde
        // hvor basen selv ER EUR, hvor den ikke findes som egen nøgle)
        var toEur = _fcRates[currency] ? (1 / _fcRates[currency]) : 0;
        rate = toEur * (_fcRates[_fcBaseCcy] || 1);
    }
    if (rate > 0) {
        document.getElementById('fc-rate').value = rate.toFixed(4).replace('.', ',');
        calcDkk();
    }
}

function calcDkk() {
    var origStr = document.getElementById('fc-orig-amount').value.replace(',', '.');
    var rateStr = document.getElementById('fc-rate').value.replace(',', '.');
    var orig    = parseFloat(origStr);
    var rate    = parseFloat(rateStr);
    if (!isNaN(orig) && !isNaN(rate) && rate > 0) {
        var dkk = orig * rate;
        var amountField = document.querySelector('input[name="amount"]');
        if (amountField) amountField.value = dkk.toFixed(2).replace('.', ',');
    }
}

// Når valuta skiftes: hent ny kurs
document.getElementById('fc-currency').addEventListener('change', function() {
    if (Object.keys(_fcRates).length > 0) {
        setRate(this.value);
    } else {
        fetchRate();
    }
});

// Initialisér hvis fremmed valuta allerede er sat (redigering)
<?php if ($orig_currency && $orig_currency !== $base_currency): ?>
document.addEventListener('DOMContentLoaded', function() {
    toggleForeignCurrency(true);
});
<?php endif; ?>
<?php endif; /* currency_module */ ?>

// ── Depot & zoom (uændret fra v1.2.0) ────────────────────────────────────────
let MidlertidigFilFraPC = null;
let currentZoom = 1, isDragging = false, startX, startY, translateX = 0, translateY = 0;

const dropzone   = document.getElementById('MainDropzone');
const panel      = document.getElementById('QuickCategoryPanel');
const placeholder= document.getElementById('previewPlaceholder');
const pdfPreview = document.getElementById('pdfPreview');
const imgPreview = document.getElementById('imgPreview');
const badge      = document.getElementById('selectedBadge');
const scanBtn    = document.getElementById('AiScanButton');
const zoomWrapper= document.getElementById('zoomWrapper');

function skiftDepotTab(t) {
    document.querySelectorAll('.tab-content-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.tab-lnk').forEach(b => { b.style.background = '#cbd5e0'; b.style.color = '#000'; });
    document.getElementById('panel_' + t).style.display = 'block';
    const a = document.getElementById('tab_btn_' + t); a.style.background = '#fafafa'; a.style.color = '#2980b9';
}
function triggerAiToggle(c) { document.getElementById('hidden_ai_checkbox').checked = c; document.getElementById('hidden_ai_action').value = "1"; document.getElementById('global_exp_form').submit(); }
// NYT (leverandørmodul): udfylder fritekst-leverandørfeltet automatisk ud fra
// dropdown-valget, i stedet for at kræve dobbelt indtastning. Rører ikke
// feltet ved "-- Vælg leverandør --" (tom værdi), så brugeren frit kan taste
// en engangs-leverandør uden en stamdata-post.
function fillSupplierName(sel) {
    if (!sel.value) return;
    var txt = sel.options[sel.selectedIndex].text;
    var field = document.querySelector('input[name="supplier"]');
    if (field) field.value = txt;
}
function togglePaymentFields(payNow) {
    var wrap = document.getElementById('due-date-wrap');
    if (wrap) wrap.style.display = payNow ? 'none' : 'block';
}
function tjekVisScanKnap() { scanBtn.style.display = (<?php echo $use_ai_scan; ?> === 1 && (MidlertidigFilFraPC || document.getElementById('depot_file_path').value !== '')) ? 'block' : 'none'; }
function nulstilVisning() { placeholder.style.display='none'; pdfPreview.style.display='none'; imgPreview.style.display='none'; zoomWrapper.style.display='block'; currentZoom=1; translateX=0; translateY=0; if(document.getElementById('AccountPanelHint')) document.getElementById('AccountPanelHint').style.display='inline'; const dzRect=dropzone.getBoundingClientRect(); zoomWrapper.style.height=(dzRect.width*1.414)+"px"; zoomWrapper.style.width=(pdfPreview.style.display==='block')?(dzRect.width*1.04)+"px":dzRect.width+"px"; opdaterTransform(); }
function previewDepotFile(p) { nulstilVisning(); const e=p.split('.').pop().toLowerCase(); if(e==='pdf'){pdfPreview.src=p+"#toolbar=0&navpanes=0";pdfPreview.style.display='block';}else if(['jpg','jpeg','png','webp'].includes(e)){imgPreview.src=p;imgPreview.style.display='block';} panel.style.opacity='1'; panel.style.pointerEvents='auto'; if(document.getElementById('AccountPanelHint')) document.getElementById('AccountPanelHint').style.display='none'; }
function selectDepotFile(e,p,f) { e.stopPropagation(); MidlertidigFilFraPC=null; document.getElementById('depot_file_path').value=p; document.getElementsByName('attachment')[0].value=""; badge.innerText='✓ '+"<?php echo lang('@Depot'); ?>"+': '+f; badge.style.background='#2ecc71'; badge.style.display='block'; previewDepotFile(p); tjekVisScanKnap(); }
function opdaterTransform() { const dzRect=dropzone.getBoundingClientRect(); const rh=parseFloat(zoomWrapper.style.height)||dzRect.height; const tw=dzRect.width*currentZoom; const th=rh*currentZoom; translateX=(tw<=dzRect.width)?0:Math.min(0,Math.max(dzRect.width-tw,translateX)); translateY=(th<=dzRect.height)?0:Math.min(0,Math.max(dzRect.height-th,translateY)); zoomWrapper.style.transform=`translate(${translateX}px,${translateY}px) scale(${currentZoom})`; }
dropzone.addEventListener('mousedown',(e)=>{ const dzRect=dropzone.getBoundingClientRect(); const rh=parseFloat(zoomWrapper.style.height)||dzRect.height; if(currentZoom===1&&(rh<=dzRect.height))return; isDragging=true; zoomWrapper.style.cursor='grabbing'; startX=e.clientX-translateX; startY=e.clientY-translateY; });
window.addEventListener('mousemove',(e)=>{ if(!isDragging)return; translateX=e.clientX-startX; translateY=e.clientY-startY; opdaterTransform(); });
window.addEventListener('mouseup',()=>{ isDragging=false; zoomWrapper.style.cursor='grab'; });
dropzone.addEventListener('wheel',(e)=>{ e.preventDefault(); const zi=0.15; const delta=e.deltaY<0?1:-1; const nz=Math.min(Math.max(1,currentZoom+delta*zi),5); if(nz===currentZoom)return; const dzRect=dropzone.getBoundingClientRect(); const mx=e.clientX-dzRect.left; const my=e.clientY-dzRect.top; const dx=(mx-translateX)/currentZoom; const dy=(my-translateY)/currentZoom; currentZoom=nz; translateX=mx-dx*currentZoom; translateY=my-dy*currentZoom; opdaterTransform(); });
dropzone.addEventListener('dblclick',()=>{ nulstilVisning(); });
document.addEventListener("DOMContentLoaded",()=>{ skiftDepotTab('mail'); const f='<?php echo $current_attachment; ?>'; if(f!==''){previewDepotFile('uploads/'+f);badge.innerText='✓ '+"<?php echo lang('@Voucher ready'); ?>"+': '+f;badge.style.background='#2ecc71';badge.style.display='block';} if(document.getElementById('depot_file_path').value!==''||f!==''||MidlertidigFilFraPC!==null){panel.style.opacity='1';panel.style.pointerEvents='auto';if(document.getElementById('AccountPanelHint'))document.getElementById('AccountPanelHint').style.display='none';scanBtn.style.display='block';}else{panel.style.opacity='0.3';panel.style.pointerEvents='none';} });
function highlightSelectedRadio(r) { document.querySelectorAll('.radio-card-btn').forEach(l=>l.classList.remove('active-radio-btn')); if(r.checked){r.closest('.radio-card-btn').classList.add('active-radio-btn');if(MidlertidigFilFraPC||document.getElementById('depot_file_path').value!==''||'<?php echo $current_attachment; ?>'!==''){badge.innerText='📂 '+"<?php echo lang('@Voucher to account'); ?>"+'#'+r.value;badge.style.background='#2ecc71';}}}
function handleLocalFileSelect(i) { if(i.files&&i.files[0]){MidlertidigFilFraPC=i.files[0];document.getElementById('depot_file_path').value=''; nulstilVisning(); const u=URL.createObjectURL(MidlertidigFilFraPC); if(MidlertidigFilFraPC.type==="application/pdf"){pdfPreview.src=u;pdfPreview.style.display='block';}else if(MidlertidigFilFraPC.type.startsWith("image/")){imgPreview.src=u;imgPreview.style.display='block';} badge.innerText='📂 '+"<?php echo lang('@Local file'); ?>"+': '+MidlertidigFilFraPC.name;badge.style.background='#34495e';badge.style.display='block';panel.style.opacity='1';panel.style.pointerEvents='auto';if(document.getElementById('AccountPanelHint'))document.getElementById('AccountPanelHint').style.display='none';tjekVisScanKnap();}}
dropzone.addEventListener('dragover',(e)=>{e.preventDefault();dropzone.style.background='#e1e8ed';dropzone.style.borderColor='#2980b9';});
dropzone.addEventListener('dragleave',()=>{dropzone.style.background='#f1f2f6';dropzone.style.borderColor='#34495e';});
dropzone.addEventListener('drop',(e)=>{e.preventDefault();dropzone.style.background='#f1f2f6';dropzone.style.borderColor='#34495e';if(e.dataTransfer.files.length>0){MidlertidigFilFraPC=e.dataTransfer.files[0];const inp=document.getElementsByName('attachment')[0],dt=new DataTransfer();dt.items.add(MidlertidigFilFraPC);inp.files=dt.files;handleLocalFileSelect(inp);}});
</script>

<style>
.depot-item:hover { background-color: #ebedf0 !important; }
.tab-lnk.active { background: #fafafa !important; color: #2980b9 !important; border-bottom: 2px solid #2980b9 !important; }
.zoom-help-tooltip:hover .zoom-help-text { visibility: visible !important; opacity: 1 !important; }
.zoom-help-text::after { content: ""; position: absolute; bottom: 100%; right: 5px; margin-left: -5px; border-width: 5px; border-style: solid; border-color: transparent transparent #2c3e50 transparent; }
</style>
