<?php # /saft_import.php v:1.3.1 d:2026-09-15 i:evs
# NYT (BEK 811/2026-gennemgang, Bilag 3 "dataportabilitet", se
# [[digital-standard-bookkeeping-registration-goal]]): export_saft.php
# dækkede allerede EKSPORT-retningen (SAF-T-DK-fil ud af TinyCash), men
# bekendtgørelsen kræver også, at kunden ikke bliver "låst inde" - et
# standard bogføringssystem skal kunne MODTAGE/indlæse data fra et andet
# system, så dataene forbliver tilgængelige for kunden.
#
# Bevidst IKKE en fuld "importér som levende bogføring"-funktion: at mappe
# et fremmed systems kontoplan/bilagsnumre ind i TinyCashs egen journal/
# ledger ville kræve kontomapping, kollisionshåndtering af bilagsnumre og
# fuld dobbelt-posterings-validering - en helt anden, langt større (og
# langt mere fejlbehæftet) opgave end hvad bestemmelsens formål reelt
# kræver. I stedet: upload en SAF-T-fil (egen eksport ELLER et andet
# systems), den arkiveres permanent i storage/saft_import/ (browsbar via
# storage_browser.php, samme beskyttede mappe-mønster som storage/saf-t/),
# og dens indhold vises i en skrivebeskyttet oversigt (stamdata/kontoplan/
# kunder/leverandører/posteringer) - kundens historiske data fra det gamle
# system er dermed bevaret og tilgængeligt, uden at røre TinyCashs egen,
# urørlige bogføring.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

$import_dir = __DIR__ . '/storage/saft_import/';
if (!is_dir($import_dir)) @mkdir($import_dir, 0755, true);

// --- UPLOAD (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_saft'])) {
    if (!csrf_verify()) {
        header('Location: saft_import.php?msg=csrf'); exit;
    }
    if (empty($_FILES['saft_file']['name']) || $_FILES['saft_file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: saft_import.php?msg=upload_error'); exit;
    }
    $orig_ext = strtolower(pathinfo($_FILES['saft_file']['name'], PATHINFO_EXTENSION));
    if ($orig_ext !== 'xml') {
        header('Location: saft_import.php?msg=bad_type'); exit;
    }
    // Hurtig gyldighedstjek FØR filen gemmes permanent - afvis tydeligt ikke-
    // XML/ikke-SAF-T-indhold i stedet for at arkivere støj.
    $raw = file_get_contents($_FILES['saft_file']['tmp_name']);
    libxml_use_internal_errors(true);
    $probe = simplexml_load_string($raw);
    if ($probe === false || stripos($raw, 'StandardAuditFile') === false) {
        header('Location: saft_import.php?msg=not_saft'); exit;
    }

    $safe_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($_FILES['saft_file']['name'], PATHINFO_FILENAME));
    $stored_name = date('Ymd_His') . '_' . $safe_name . '.xml';
    if (!move_uploaded_file($_FILES['saft_file']['tmp_name'], $import_dir . $stored_name)) {
        header('Location: saft_import.php?msg=upload_error'); exit;
    }

    log_action($conn, 'IMPORT_SAFT_FILE', 'storage', 0, null, ['filename' => $stored_name, 'size' => filesize($import_dir . $stored_name)]);
    header('Location: saft_import.php?view=' . urlencode($stored_name));
    exit;
}

// --- SLET (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_saft'])) {
    if (!csrf_verify()) { header('Location: saft_import.php?msg=csrf'); exit; }
    $target = basename($_POST['delete_saft']);
    $path = $import_dir . $target;
    if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'xml') {
        @unlink($path);
        log_action($conn, 'DELETE_SAFT_IMPORT_FILE', 'storage', 0, ['filename' => $target], null);
    }
    header('Location: saft_import.php?msg=deleted');
    exit;
}

// --- PARSER: en uploadet/arkiveret SAF-T-DK-fil til et skrivebeskyttet overblik ---
// Returnerer ['error' => string] ELLER et fuldt resultat-array.
function saft_import_parse(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === false) return ['error' => lang('@Could not read the file.')];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if ($xml === false) return ['error' => lang('@This file is not valid XML.')];

    // RETTET (find-fem-fejl-gennemgang): den oprindelige antagelse ("SimpleXML
    // følger det automatisk, så længe der ikke blandes præfikser") holdt kun
    // for filer UDEN namespace-præfiks (som TinyCashs egen eksport). En fil
    // fra et andet system, der (helt gyldigt) bruger et præfiks, fx
    // <n1:AuditFile xmlns:n1="...">, gav FØR en helt tom, fejlmeldings-løs
    // oversigt - bekræftet direkte: $xml->Header blev null, selvom filen
    // bestod upload-tjekket. $xml->children($ns) virker korrekt i BEGGE
    // tilfælde (testet mod begge varianter) og bruges nu konsekvent i stedet
    // for almindelig ->felt-adgang på selve roden.
    $ns = 'urn:StandardAuditFile-Taxation-Financial:DK';
    $root = $xml->children($ns);
    $header = $root->Header ?? null;
    $result = [
        'header' => [
            'company_name' => (string)($header->Company->Name ?? ''),
            'cvr'          => (string)($header->Company->RegistrationNumber ?? ''),
            'currency'     => (string)($header->DefaultCurrencyCode ?? ''),
            'period_start' => (string)($header->SelectionCriteria->PeriodStart ?? ''),
            'period_end'   => (string)($header->SelectionCriteria->PeriodEnd ?? ''),
            'software'     => (string)($header->SoftwareCompanyName ?? ''),
            'created'      => (string)($header->AuditFileDateCreated ?? ''),
        ],
        'accounts'     => [],
        'customers'    => [],
        'suppliers'    => [],
        'transactions' => [],
        'n_transactions' => 0,
        'n_lines'        => 0,
    ];

    $master = $root->MasterFiles ?? null;
    if ($master) {
        foreach (($master->GeneralLedgerAccounts->Account ?? []) as $a) {
            $result['accounts'][] = [
                'id'   => (string)$a->AccountID,
                'name' => (string)$a->AccountDescription,
                'type' => (string)$a->AccountType,
                'std'  => (string)($a->StandardAccountID ?? ''),
            ];
        }
        foreach (($master->Customers->Customer ?? []) as $c) {
            $result['customers'][] = [
                'id'   => (string)$c->CustomerID,
                'name' => (string)$c->CompanyName,
                'cvr'  => (string)($c->CustomerTaxID ?? ''),
            ];
        }
        foreach (($master->Suppliers->Supplier ?? []) as $s) {
            $result['suppliers'][] = [
                'id'   => (string)$s->SupplierID,
                'name' => (string)$s->CompanyName,
                'cvr'  => (string)($s->SupplierTaxID ?? ''),
            ];
        }
    }

    $gle = $root->GeneralLedgerEntries ?? null;
    if ($gle) {
        foreach (($gle->Journal->Transaction ?? []) as $t) {
            $lines = [];
            foreach (($t->Line ?? []) as $l) {
                $lines[] = [
                    'account' => (string)$l->AccountID,
                    'debit'   => (string)($l->DebitAmount ?? ''),
                    'credit'  => (string)($l->CreditAmount ?? ''),
                ];
            }
            $result['transactions'][] = [
                'id'          => (string)$t->TransactionID,
                'date'        => (string)$t->TransactionDate,
                'description' => (string)$t->Description,
                'source'      => (string)($t->SourceID ?? ''),
                'lines'       => $lines,
            ];
            $result['n_lines'] += count($lines);
        }
        $result['n_transactions'] = count($result['transactions']);
    }

    return $result;
}

htm_Header(capt: '@Import SAF-T', mwidth: 1000);
showMenu();

if (isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if ($m === 'bad_type') htm_Alert(lang('@Error: Please upload an .xml file.'), 'error');
    elseif ($m === 'not_saft') htm_Alert(lang('@Error: This does not look like a SAF-T file.'), 'error');
    elseif ($m === 'upload_error') htm_Alert(lang('@Error: Could not save the uploaded file.'), 'error');
    elseif ($m === 'csrf') htm_Alert(lang('@Security check failed (CSRF). Please try again.'), 'error');
    elseif ($m === 'deleted') htm_Alert(lang('@File deleted.'), 'success');
}

// --- VISNING AF ÉN IMPORTERET FIL ---
if (isset($_GET['view'])) {
    $target = basename($_GET['view']);
    $path = $import_dir . $target;
    if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'xml') {
        htm_Alert(lang('@File not found.'), 'error');
        htm_Footer();
        exit;
    }
    $parsed = saft_import_parse($path);

    htm_Card_(capt: '@Import SAF-T', wdth: 1000);
    echo '<p><a href="saft_import.php" data-hint="' . lang('@Return to the list of imported SAF-T files') . '"><i class="fa-solid fa-arrow-left"></i> ' . lang('@Back to list') . '</a></p>';

    if (isset($parsed['error'])) {
        htm_Alert($parsed['error'], 'error');
    } else {
        $h = $parsed['header'];
        echo '<h3>' . lang('@Company') . '</h3>';
        echo '<p><strong>' . htmlspecialchars($h['company_name']) . '</strong> (CVR: ' . htmlspecialchars($h['cvr']) . ')<br>';
        echo lang('@Period') . ': ' . htmlspecialchars($h['period_start']) . ' – ' . htmlspecialchars($h['period_end']) . '<br>';
        echo lang('@Currency') . ': ' . htmlspecialchars($h['currency']) . ' · ' . lang('@Created with') . ': ' . htmlspecialchars($h['software']) . ' (' . htmlspecialchars($h['created']) . ')</p>';

        echo '<h3>' . lang('@Chart of Accounts') . ' (' . count($parsed['accounts']) . ')</h3>';
        if ($parsed['accounts']) {
            $rows = [];
            foreach ($parsed['accounts'] as $a) {
                $rows[] = [htmlspecialchars($a['id']), htmlspecialchars($a['name']), htmlspecialchars($a['type']), htmlspecialchars($a['std'])];
            }
            htm_Table(['@Account', '@Description', '@Type', '@Standard Account ID'], $rows, 'saftAccTbl', 25, vhgh: '300px');
        }

        echo '<h3>' . lang('@Customers') . ' (' . count($parsed['customers']) . ') / ' . lang('@Suppliers') . ' (' . count($parsed['suppliers']) . ')</h3>';
        $rows = [];
        foreach ($parsed['customers'] as $c) $rows[] = [lang('@Customer'), htmlspecialchars($c['id']), htmlspecialchars($c['name']), htmlspecialchars($c['cvr'])];
        foreach ($parsed['suppliers'] as $s) $rows[] = [lang('@Supplier'), htmlspecialchars($s['id']), htmlspecialchars($s['name']), htmlspecialchars($s['cvr'])];
        if ($rows) htm_Table(['@Type', '@ID', '@Name', '@CVR'], $rows, 'saftPartyTbl', 25, vhgh: '300px');

        echo '<h3>' . lang('@General Ledger') . ' (' . $parsed['n_transactions'] . ' ' . lang('@entries') . ', ' . $parsed['n_lines'] . ' ' . lang('@lines') . ')</h3>';
        $rows = [];
        foreach ($parsed['transactions'] as $t) {
            $line_text = [];
            foreach ($t['lines'] as $l) {
                $line_text[] = htmlspecialchars($l['account']) . ': ' . ($l['debit'] !== '' ? '+' . htmlspecialchars($l['debit']) : '-' . htmlspecialchars($l['credit']));
            }
            $rows[] = [
                htmlspecialchars($t['date']),
                htmlspecialchars($t['id']),
                htmlspecialchars($t['source']),
                htmlspecialchars($t['description']),
                implode('<br>', $line_text),
            ];
        }
        if ($rows) htm_Table(['@Date', '@Transaction ID', '@Voucher No.', '@Description', '@Lines'], $rows, 'saftGleTbl', 50, vhgh: '500px');
    }

    htm_Card_end();
    htm_Footer();
    exit;
}

// --- FORSIDE: UPLOAD + LISTE OVER TIDLIGERE IMPORTEREDE FILER ---
htm_Card_(capt: '@Import SAF-T', wdth: 1000);

echo '<p style="color:var(--text-muted); font-size:0.9em;">' .
    lang('@Upload a SAF-T audit file (your own export, or one from another bookkeeping system) to keep its data permanently accessible here - as a read-only overview, without affecting TinyCash\'s own bookkeeping.') .
    '</p>';

echo '<form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:20px;">';
csrf_field();
echo '<div><label style="display:block; font-size:0.85em; color:var(--text-muted); margin-bottom:4px;">' . lang('@SAF-T File (.xml)') . '</label>';
echo '<input type="file" name="saft_file" accept=".xml" required></div>';
htm_Button(icon: 'fa-upload', labl: '@Import', type: 'success', attr: 'type="submit" name="upload_saft" value="1"');
echo '</form>';

$files = glob($import_dir . '*.xml');
usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

if (empty($files)) {
    echo '<p style="color:var(--text-muted);">' . lang('@No files imported yet.') . '</p>';
} else {
    $rows = [];
    foreach ($files as $f) {
        $name = basename($f);
        $rows[] = [
            htmlspecialchars($name),
            date(CONF_DATE_FORMAT . ' H:i', filemtime($f)),
            number_format(filesize($f) / 1024, 1) . ' KB',
            '<a href="saft_import.php?view=' . urlencode($name) . '" data-hint="' . lang('@Show the content of this SAF-T file') . '" class="tc-info-fill" style="padding:5px 10px; border-radius:4px; background:var(--color-info); color:var(--text-light); text-decoration:none;"><i class="fa-solid fa-eye"></i> ' . lang('@View') . '</a> '
            . '<form method="post" style="display:inline;" onsubmit="return confirm(' . htmlspecialchars(json_encode(lang('@Delete this imported file? This only removes the archived copy, not any data elsewhere.')), ENT_QUOTES) . ');">'
            . csrf_field(false) . '<input type="hidden" name="delete_saft" value="' . htmlspecialchars($name) . '">'
            . '<button type="submit" style="padding:5px 10px; border-radius:4px; background:var(--color-danger); color:var(--text-light); border:none; cursor:pointer;"><i class="fa-solid fa-trash"></i></button></form>',
        ];
    }
    htm_Table(['@Filename', '@Imported', '@Size', ''], $rows, 'saftImportListTbl', 50);
}

htm_Card_end();
htm_Footer();
