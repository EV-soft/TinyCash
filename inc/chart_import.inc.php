<?php # /inc/chart_import.inc.php v:1.3.1 d:2026-09-15 i:evs
// Kontoplan fra en fil (XLSX eller CSV), som brugeren selv har hentet - fx BAS fra bas.se eller NS 4102 fra revisoren.
// TinyCash medbringer ikke selv disse planer: BAS-kontogruppen og Regnskap Norge har ophavsretten, og retten til at
// bygge dem ind i et system, der gives videre, er en betalt licens. Brugerens egen import til eget regnskab er fri.
// Kun nye konti oprettes; en eksisterende konto røres aldrig. Kontotypen udledes af landepakkens chart_classes.

const ACC_TYPE_KEYS = ['income' => 1, 'expense' => 1, 'asset' => 1, 'liability' => 1, 'equity' => 1, 'bank' => 1, 'vat' => 1];

// Filens rækker som lister af tekstceller. XLSX læses med ZipArchive (første ark, delte strenge + indlejrede tekster).
function chart_import_read(string $path, string $name): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') return chart_import_read_xlsx($path);
    if ($ext === 'csv' || $ext === 'txt') return chart_import_read_csv($path);
    throw new Exception(lang('@Only .xlsx and .csv files can be imported (save an .xls file as .xlsx first).'));
}

function chart_import_read_csv(string $path): array {
    $raw = (string)file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $first = strtok($raw, "\n");
    $sep = ';';
    foreach ([';', "\t", ','] as $s) if (substr_count((string)$first, $s) > substr_count((string)$first, $sep)) $sep = $s;
    $rows = [];
    $h = fopen('php://memory', 'r+');
    fwrite($h, $raw);  rewind($h);
    while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) $rows[] = array_map(fn($c) => trim((string)$c), $r);
    fclose($h);
    return $rows;
}

function chart_import_read_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) throw new Exception(lang('@The server cannot read .xlsx files (PHP zip is missing) - save the file as .csv instead.'));
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new Exception(lang('@The file could not be opened as an .xlsx file.'));
    $shared = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = @simplexml_load_string($ss, 'SimpleXMLElement', LIBXML_NONET);
        if ($x) foreach ($x->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            foreach ($si->r as $r) $t .= (string)$r->t;
            $shared[] = $t;
        }
    }
    // Første ark efter workbook-rækkefølgen (sheet1.xml er det næsten altid)
    $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet === false) {
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheet = $z->getFromName($n);  break; }
        }
    }
    $z->close();
    if ($sheet === false) throw new Exception(lang('@The file could not be opened as an .xlsx file.'));
    $x = @simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET | LIBXML_PARSEHUGE);
    if (!$x) throw new Exception(lang('@The file could not be opened as an .xlsx file.'));
    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $cells = [];  $next = 1;
        foreach ($row->c as $c) {
            // (kolonnebogstaverne giver placeringen - tomme celler mangler i filen; uden "r" er cellen den næste)
            $col = 0;
            foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper((string)$c['r']))) as $ch) $col = $col * 26 + (ord($ch) - 64);
            if ($col <= 0) $col = $next;
            $next = $col + 1;
            $t = (string)$c['t'];
            $v = $t === 's' ? ($shared[(int)$c->v] ?? '') : ($t === 'inlineStr' ? (string)$c->is->t : (string)$c->v);
            $cells[max(0, $col - 1)] = trim($v);
        }
        if (!$cells) continue;
        $line = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $i => $v) $line[$i] = $v;
        $rows[] = $line;
    }
    return $rows;
}

// Kontotypen af kontonummeret efter landepakkens klasser (længste præfiks); null = ukendt
function chart_import_type(string $no): ?string {
    $best = null;  $len = 0;
    foreach ((array)tc_country_pack('chart_classes', []) as $c) {
        [$p, $t] = $c;
        if (strncmp($no, (string)$p, strlen((string)$p)) === 0 && strlen((string)$p) > $len && isset(ACC_TYPE_KEYS[$t])) { $best = $t;  $len = strlen((string)$p); }
    }
    return $best;
}

// Konti i rækkerne: hver celle med et kontonummer (4-6 cifre) fulgt af en tekst er en konto - også når en fil har
// flere kontogrupper side om side (som BAS' regneark). En kolonne med en kendt kontotype bruges, hvis den findes.
// [['no', 'name', 'type', 'guessed' => bool], ...] sorteret efter nummer; dubletter (samme nummer) kun første gang.
function chart_import_accounts(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $r = array_values($r);
        for ($i = 0; $i < count($r) - 1; $i++) {
            // (Excel gemmer tal som "1930.0"; BAS markerer konti der ikke bruges ved K2 med "#": "1010#")
            $no = preg_replace(['/\.0+$/', '/\s*[#*]$/'], '', trim((string)$r[$i]));
            if (!preg_match('/^[1-9][0-9]{3,5}$/', $no) || isset($out[$no])) continue;
            $name = '';
            for ($j = $i + 1; $j < count($r) && $j <= $i + 3; $j++) {
                $c = trim((string)$r[$j]);
                if ($c !== '' && !preg_match('/^[0-9.,\s]*$/', $c) && !in_array($c, ['■', '□', '*', 'x', 'X'], true)) { $name = $c;  break; }
            }
            if ($name === '') continue;
            $type = null;
            foreach ($r as $c) if (isset(ACC_TYPE_KEYS[strtolower(trim((string)$c))])) { $type = strtolower(trim((string)$c));  break; }
            $guess = $type === null;
            $type ??= chart_import_type($no);
            $out[$no] = ['no' => (int)$no, 'name' => mb_substr($name, 0, 100), 'type' => $type ?? 'expense', 'guessed' => $guess, 'unknown' => $type === null];
        }
    }
    ksort($out, SORT_NUMERIC);
    return array_values($out);
}

// En indtægtskontos momssats - varer på kontoen faktureres med den: satsen i navnet ("Försäljning varor 12 %"), hvis
// den er en af landets; 0 for salg uden moms (eksport, EU, momsfrit); ellers null = landets standardsats.
// (Før fik alle 0 - en vare på en ny salgskonto blev faktureret helt uden moms.)
function chart_import_income_rate($conn, string $name): ?float {
    if (preg_match('/(\d{1,2}(?:[.,]\d{1,2})?)\s*%/u', $name, $m)) {
        $r = (float)str_replace(',', '.', $m[1]);
        if (tc_home_rate_ok($conn, $r)) return $r;
    }
    // ("EU" kun som eget ord og med store bogstaver - med /i ramte det "eu" midt i ord, fx tysk "steuerpflichtig",
    // så en momspligtig salgskonto blev importeret med 0 % moms)
    if (preg_match('/moms(?:fri|fritt?)|utan moms|uden moms|mva-?fri|uten mva|veroton|steuerfrei|export|eksport|utförsel|utførsel|\bunionen|omvänd|omvendt|reverse/iu', $name)
        || preg_match('/(?<![\p{L}])EU(?![\p{L}])/u', $name)) return 0.0;
    return null;
}

// Opretter de konti der ikke findes (status 'active' eller 'off'). ['added', 'existing' => [nr => [eget navn, filens navn]]]
function chart_import_apply($conn, array $accounts, string $status): array {
    $status = in_array($status, ['active', 'off'], true) ? $status : 'off';
    $mine = [];
    $res = DB::query($conn, "SELECT acc_id, acc_name FROM accounts");
    while ($res && ($r = DB::fetch_assoc($res))) $mine[(int)$r['acc_id']] = (string)$r['acc_name'];
    $has_status = db_has_column($conn, 'accounts', 'acc_status');
    $out = ['added' => 0, 'existing' => []];
    DB::transaction($conn, function () use ($conn, $accounts, $status, $mine, $has_status, &$out) {
        foreach ($accounts as $a) {
            $no = (int)$a['no'];
            if (isset($mine[$no])) { if (mb_strtolower($mine[$no]) !== mb_strtolower($a['name'])) $out['existing'][$no] = [$mine[$no], $a['name']];  continue; }
            $type = isset(ACC_TYPE_KEYS[$a['type']]) ? $a['type'] : 'expense';
            $row = ['acc_id' => $no, 'acc_name' => (string)$a['name'], 'acc_type' => $type, 'vat_rate' => $type === 'income' ? chart_import_income_rate($conn, (string)$a['name']) : 0];
            if ($has_status) $row['acc_status'] = $status;
            if (!DB::insert($conn, 'accounts', $row)) throw new Exception(DB::error($conn));
            $out['added']++;
        }
    });
    if (function_exists('log_action')) log_action($conn, 'IMPORT_CHART_FILE', 'accounts', 0, null, ['added' => $out['added'], 'existing_kept' => count($out['existing']), 'status' => $status]);
    return $out;
}
