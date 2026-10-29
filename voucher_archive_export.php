<?php # /voucher_archive_export.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 26):
# kun fulde systembackups fandtes - ingen måde at hente "alle bilag for år X"
# som en ren pakke til aflevering til revisor, eller til at opfylde
# bogføringslovens 5-års opbevaringspligt uden at grave enkeltvis. Bundler
# de FAKTISKE bilagsfiler (kun udgifter har en fysisk vedhæftet fil -
# invoices har ingen attachment-kolonne, selve fakturaposten/PDF'en er dens
# eget bilag og kan printes/downloades enkeltvist fra invoice_view.php,
# nævnes derfor kun i CSV-oversigten, ikke som en fabrikeret fil i ZIP'en -
# se §fabricated-cvr-bank-details-bug for hvorfor vi aldrig lader som om
# data findes, når det ikke gør). Admin-only, samme niveau som backup.php,
# da eksporten samler følsomme regnskabsdata + bilag i én downloadbar fil.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

function csv_safe($v) {
    if (!is_string($v) || $v === '') return $v;
    return (strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if (isset($_GET['download']) && $_GET['download'] == '1') {
    if (!class_exists('ZipArchive')) {
        die(lang('@PHP ZipArchive extension is not enabled on this server.'));
    }

    $ys = "$year-01-01";
    $ye = "$year-12-31";
    $backupDir = 'backups/';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755);
    $zipName = "Bilagsarkiv_{$year}_" . date('Y-m-d_Hi') . '.zip';
    $zipPath = $backupDir . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        die(lang('@ZIP creation failed'));
    }

    $csv_rows = [];
    $csv_rows[] = [lang('@Type'), lang('@Voucher No'), lang('@Date'), lang('@Counterpart'), lang('@Amount'), lang('@Attachment')];

    // --- Udgifter: de eneste poster der reelt kan have en fysisk vedhæftet fil ---
    $res_e = DB::query($conn, "SELECT exp_id, exp_date, supplier, amount, voucher_no, attachment, no_attachment_reason
        FROM expenses WHERE is_cancelled = 0 AND voucher_no IS NOT NULL AND exp_date BETWEEN '$ys' AND '$ye'
        ORDER BY exp_date ASC");      // RETTET: ubogførte kladder er ingen bilag og hørte ikke med i bilagsarkivet
    $attached_count = 0;
    if ($res_e) {
        while ($e = DB::fetch_assoc($res_e)) {
            $has_file = false;
            if (!empty($e['attachment'])) {
                $local_path = get_tc_doc($e['attachment']);
                if ($local_path && file_exists($local_path)) {
                    $safe_name = 'bilag_' . $e['exp_id'] . '_' . basename($e['attachment']);
                    $zip->addFile($local_path, 'udgifter/' . $safe_name);
                    $has_file = true;
                    $attached_count++;
                }
            }
            $csv_rows[] = [
                lang('@Expense'),
                csv_safe($e['voucher_no'] ?? ''),
                $e['exp_date'],
                csv_safe($e['supplier'] ?? ''),
                number_format((float)$e['amount'], 2, ',', ''),
                $has_file ? lang('@Included in ZIP') : ($e['no_attachment_reason'] ? lang('@Exempt') . ': ' . csv_safe($e['no_attachment_reason']) : lang('@Missing')),
            ];
        }
    }

    // --- Fakturaer: ingen fysisk fil at bundle - kun med i selve oversigten,
    // så revisoren ved de findes og kan tilgås/printes fra systemet ---
    // RETTET: fakturaerne og kreditnotaerne HAR nu en fil - den arkiverede PDF (inc/invoice_pdf_archive.inc.php,
    // BEK 811/2026: opbevaring af bilag) - men den kom ikke med i årets bilagsarkiv. Den lægges nu i ZIP'en.
    require_once __DIR__ . '/inc/invoice_pdf_archive.inc.php';
    $res_i = DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.credit_ref, i.credit_note_no, i.inv_date, c.cust_name,
            (SELECT COALESCE(SUM(quantity * price_each " . invoice_discount_sql($conn) . " * (100 + line_vat_rate) / 100.0), 0)
             FROM invoice_lines WHERE inv_id = i.inv_id) AS total
        FROM invoices i JOIN customers c ON i.cust_id = c.cust_id
        WHERE LOWER(i.inv_status) != 'draft' AND i.inv_date BETWEEN '$ys' AND '$ye'
        ORDER BY i.inv_date ASC");
    if ($res_i) {
        while ($i = DB::fetch_assoc($res_i)) {
            $pdf = invoice_archive_pdf_path($conn, (int)$i['inv_id']);
            $pdf_ok = $pdf && file_exists($pdf) && filesize($pdf) > 0;
            if ($pdf_ok) { $zip->addFile($pdf, 'fakturaer/' . basename($pdf));  $attached_count++; }
            $is_cn = !empty($i['credit_ref']);
            $csv_rows[] = [
                lang($is_cn ? '@Credit Note' : '@Invoice'),
                csv_safe($is_cn ? (!empty($i['credit_note_no']) ? 'KN-' . $i['credit_note_no'] : '') : ($i['invoice_no'] ?? '')),
                $i['inv_date'],
                csv_safe($i['cust_name'] ?? ''),
                number_format((float)$i['total'], 2, ',', ''),
                $pdf_ok ? lang('@Included in ZIP') : lang('@View/print from Invoice View in the system'),
            ];
        }
    }

    $csv_out = chr(0xEF) . chr(0xBB) . chr(0xBF);
    foreach ($csv_rows as $row) {
        $csv_out .= implode(';', array_map(fn($c) => '"' . str_replace('"', '""', (string)$c) . '"', $row)) . "\r\n";
    }
    $zip->addFromString('bilagsoversigt.csv', $csv_out);
    $zip->addFromString('LÆS MIG.txt',
        sprintf(lang('@Voucher archive for year %d, generated %s. Contains the attached files of the posted expenses, the archived PDF of each invoice and credit note, and a CSV index of every voucher of the year (including those without a file).'), $year, date('Y-m-d H:i'))
    );

    $zip->close();

    if (file_exists($zipPath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($zipPath);
        unlink($zipPath);
        exit;
    } else {
        die(lang('@Backup file not found'));
    }
}

htm_Header('@Voucher Archive Export');
showMenu();

htm_Card_('@Voucher Archive Export', 700);
echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Download a ZIP bundle of all expense attachments and a full CSV index of every voucher (expenses and non-draft invoices) for a chosen year - useful for handing off to an accountant/auditor, or for the bookkeeping law\'s 5-year retention requirement.') .
    '</p>';
echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end;">';
echo '<input type="hidden" name="download" value="1">';
echo '<div><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Year') . '</label>';
echo '<input type="number" name="year" value="' . $year . '" style="width:110px; padding:6px;"></div>';
htm_Button(icon: 'fa-file-zipper', labl: '@Download ZIP', type: 'success', attr: 'data-hint="'.lang('@Download the voucher archive for the selected year').'"');
echo '</form>';
htm_Card_end();

htm_Footer();
