<?php # /bank_import_process.php v:1.3.1 d:2026-09-15 i:evs
# Trin 2 af CSV-bankimport (kaldes fra bank_import_step1.php's formular):
# læser den uploadede CSV-fil linje for linje efter den valgte kolonne-
# mapping og indsætter rækkerne i bank_statement_temp, klar til afstemning
# i reconcile_list.php. file_path skal (efter realpath) ligge inden for
# uploads/, hvor step 1 altid gemmer sine egne uploads - forhindrer at det
# skjulte POST-felt bruges til at læse/slette vilkårlige filer på serveren.
# En dato der ikke kan parses falder ikke stille tilbage til dagens dato -
# den markeres tydeligt i tekstfeltet og tælles som advarsel (msg=imported&
# date_warnings=N).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // KRITISK: file_path kom ukontrolleret fra et skjult POST-felt (sat af
    // bank_import_step1.php, men intet forhindrer klienten i selv at ændre
    // værdien før step 2 sendes) og blev brugt direkte i fopen()/unlink() -
    // "file_path=inc/env.ini" ville læse hele hemmeligheds-filen linje for
    // linje ind i bank_statement_temp (synlig bagefter via reconcile_list.php)
    // OG SÅ SLETTE inc/env.ini via @unlink() nedenfor. Samme sårbarhedsklasse
    // som depot_file_path i expense_edit.php (scanner-/OCR-gennemgangen) -
    // kræver nu at den kanoniske sti (realpath) reelt ligger inden for
    // uploads/, hvor bank_import_step1.php altid gemmer sine egne uploads.
    $upload_base = realpath(__DIR__ . '/uploads');
    $candidate   = realpath(__DIR__ . '/' . ($_POST['file_path'] ?? ''));
    $file_path   = ($upload_base && $candidate && strpos($candidate, $upload_base . DIRECTORY_SEPARATOR) === 0)
        ? $candidate : null;
    $delimiter = $_POST['delimiter'] ?? ',';
    // RETTET (§bugs-batch-24-review): intet isset/array-tjek her - manglede
    // map[] helt (se bank_import_step1.php's rettelse), gav en PHP-advarsel
    // og en effektivt tom mapping i stedet for en tydelig fejl. Falder nu
    // eksplicit tilbage til et tomt array (ingen kolonner mappes - alle
    // rækker importeres med tomme/0-værdier, men uden en runtime-advarsel).
    $mapping   = is_array($_POST['map'] ?? null) ? $_POST['map'] : [];
    $source    = DB::real_escape_string($conn, $_POST['import_source'] ?? 'bank');
    $d_sep     = $_POST['decimal_sep']  ?? ',';
    $t_sep     = ($d_sep == ',') ? '.' : ','; // Automatic thousands separator

    if (!$file_path || !file_exists($file_path)) {
        die(lang('@Error: File not found'));
    }

    $handle = fopen($file_path, "r");
    $importCount = 0;
    $dateWarnCount = 0;
    // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): dublet-tjekket
    // nedenfor fandtes allerede (samme dato+tekst+beløb importeres ikke to
    // gange), men var STUMT - genimporterer man ved en fejl den samme
    // periode, ser man bare et lavere antal importerede rækker end forventet,
    // uden nogen forklaring på hvorfor, hvilket let ligner en fejl i stedet
    // for at være den beskyttelse der reelt virkede. Tælles nu og vises i
    // succesbeskeden på reconcile_list.php.
    $duplicateCount = 0;
    $seen_in_file = [];       // dato|tekst|beløb => antal gange set i DENNE fil (se dublet-tjekket nedenfor)

    // RETTET (§bugs-batch-24-review): sprang altid PRÆCIS én linje over,
    // uanset hvad brugeren valgte i bank_import_step1.php's "Spring linjer
    // over"-felt (skip_lines) - det felt blev aldrig læst her overhovedet.
    // De fleste bankeksporter har kun én header-linje, så det sjældent blev
    // bemærket, men en fil med fx en indledende "Kontoudtog for konto
    // XXXX"-linje FØR selve kolonneoverskrifterne ville få den rigtige
    // header-linje fejlagtigt tolket som en transaktionsrække.
    $skip_lines = max(0, (int)($_POST['skip_lines'] ?? 1));
    for ($i = 0; $i < $skip_lines; $i++) {
        if (fgetcsv($handle, 1000, $delimiter) === false) break;
    }

    // RETTET: tegnsættet blev ikke håndteret - mange danske bankers CSV er i Windows-1252/ISO-8859-1. Tekster med
    // æøå blev gemt som ugyldig UTF-8 (htmlspecialchars() gør dem TOMME i afstemningen), og et UTF-8-BOM foran første
    // felt fik den første dato til at fejle. Nu: BOM fjernes, og et felt der ikke er gyldig UTF-8, omkodes.
    $bom_done = false;
    while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
        if (!$bom_done && isset($data[0])) { $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$data[0]);  $bom_done = true; }
        foreach ($data as $di => $dv) {
            if ($dv !== null && !mb_check_encoding((string)$dv, 'UTF-8')) $data[$di] = mb_convert_encoding((string)$dv, 'UTF-8', 'Windows-1252');
        }
        $raw = ['date' => date('Y-m-d'), 'text' => '', 'net' => 0.0, 'fee' => 0.0, 'gross' => 0.0, 'date_ok' => true];
        $has_net = false;
        $has_gross = false;

        foreach ($mapping as $index => $db_field) {
            if ($db_field == 'ignore' || !isset($data[$index])) continue;
            $val = trim($data[$index]);

            if (in_array($db_field, ['amount', 'net_amount', 'fee_amount'])) {
                // --- Robust numeric cleaning ---
                // RETTET: kun et FORANSTILLET almindeligt minus blev forstået - et efterstillet minus ("1.234,50-"),
                // et typografisk minus ("−1.234,50", U+2212) eller regnskabsparenteser ("(1.234,50)") blev renset
                // væk, og en UDBETALING blev importeret som en indbetaling.
                $neg = (bool)preg_match('/^\s*\(.*\)\s*$|-\s*$|^\s*[−–]|^\s*-/u', $val);
                $val = str_replace(['−', '–', '(', ')'], '', $val);
                $val = ltrim(rtrim($val, " -\t"), " -\t");
                $val = str_replace(' ', '', $val); // Remove spaces
                $val = str_replace($t_sep, '', $val); // Remove thousands sep
                $val = str_replace($d_sep, '.', $val); // Convert decimal sep to dot
                $val = preg_replace('/[^-0-9.]/', '', $val); // Keep only minus, numbers and dot
                $num = $neg ? -abs((float)$val) : (float)$val;
                if ($db_field == 'amount')     { $raw['gross'] = $num; $has_gross = true; }
                if ($db_field == 'net_amount') { $raw['net'] = $num; $has_net = true; }
                if ($db_field == 'fee_amount') { $raw['fee'] = $num; }
            } 
            elseif ($db_field == 'trans_date') {
                $val_orig = $val;
                $val = str_replace(['/', '.'], '-', trim($val));
                // RETTET: et tocifret årstal ("05.03.26" - almindeligt i danske bankudtræk) blev af strtotime()
                // læst som år-måned-dag: 2005-03-26 - stille en helt forkert dato, uden advarsel. Dansk
                // dag-måned-år læses nu eksplicit (også med firecifret år); ISO "2026-03-05" som før.
                if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2}|\d{4})$/', $val, $dm)) {
                    $yy = strlen($dm[3]) === 2 ? 2000 + (int)$dm[3] : (int)$dm[3];
                    $date_ts = checkdate((int)$dm[2], (int)$dm[1], $yy) ? mktime(0, 0, 0, (int)$dm[2], (int)$dm[1], $yy) : false;
                } else {
                    $date_ts = strtotime($val);
                }
                if ($date_ts) {
                    $raw['date'] = date('Y-m-d', $date_ts);
                } else {
                    // Faldt tidligere STILLE tilbage til dagens dato - en
                    // banktransaktion kunne havne i en helt forkert periode
                    // uden nogen advarsel. Nu markeres den tydeligt i tekst-
                    // feltet i stedet, så den er synlig til manuel rettelse
                    // i reconcile_list.php.
                    $raw['date']    = date('Y-m-d');
                    $raw['date_ok'] = false;
                    $raw['date_raw'] = $val_orig;
                }
            }
            elseif ($db_field == 'text_val') {
                $raw['text'] = DB::real_escape_string($conn, $val);
            }
        }

        // LOGIC: Calculate missing values (Net, Fee, Gross)
        // If we have Net and Fee, but no Gross: Gross = Net + Fee
        if ($has_net && !$has_gross) {
            $raw['gross'] = $raw['net'] + $raw['fee'];
        }
        // If we have both Gross and Net: Fee = Gross - Net
        elseif ($has_gross && $has_net && $raw['fee'] == 0) {
            $raw['fee'] = $raw['gross'] - $raw['net'];
        }

        // Dato kunne ikke læses - marker det synligt i teksten (i stedet for
        // at lade den forkerte dagens-dato-værdi passere ubemærket) og tæl
        // den med, så importresultatet på reconcile_list.php viser en
        // advarsel.
        if (!$raw['date_ok']) {
            $dateWarnCount++;
            $warn_prefix = DB::real_escape_string($conn, '[DATO IKKE GENKENDT: "' . $raw['date_raw'] . '"] ');
            $raw['text'] = $warn_prefix . $raw['text'];
        }

        $d = $raw['date'];
        $t = $raw['text'];
        $a = $raw['gross']; // Gross amount is used for bank matching
        $f = $raw['fee'];

        // DUPLICATE CHECK & INSERT
        // RETTET: to ens transaktioner i SAMME fil (samme dag, tekst og beløb - fx to parkeringer à 50 kr)
        // blev til én: den anden blev afvist som "dublet" af den første, der lige var indsat. Nu tælles
        // forekomsterne: den n'te ens linje i filen er kun en dublet, hvis der allerede findes n af dem.
        $dup_key = "$d|$t|$a";
        $nth = ($seen_in_file[$dup_key]['seen'] ?? 0) + 1;                       // den n'te ens linje i denne fil
        $ins = $seen_in_file[$dup_key]['inserted'] ?? 0;                          // heraf allerede indsat fra denne fil
        $seen_in_file[$dup_key] = ['seen' => $nth, 'inserted' => $ins];
        $check = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM bank_statement_temp WHERE trans_date='$d' AND text_val='$t' AND amount=$a"));
        $before = (int)($check[0] ?? 0) - $ins;                                    // fra tidligere importer

        if ($before < $nth) {
            $seen_in_file[$dup_key]['inserted']++;
            $sql = "INSERT INTO bank_statement_temp (trans_date, text_val, amount, fee_amount, import_source)
                    VALUES ('$d', '$t', $a, $f, '$source')";
            if (DB::query($conn, $sql)) $importCount++;
        } else {
            $duplicateCount++;
        }
    }
    fclose($handle);
    @unlink($file_path);

    header("Location: reconcile_list.php?msg=imported&count=$importCount&date_warnings=$dateWarnCount&duplicates=$duplicateCount");
    exit;
}