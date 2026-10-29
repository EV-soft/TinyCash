<?php # /inc/invoice_pdf_archive.inc.php v:1.3.1 d:2026-09-15 i:evs
# NYT (bruger-anmodet lukning af BEK 811/2026-gennemgangens fund 2, se
# [[digital-standard-bookkeeping-registration-goal]]): "Opbevaring af bilag"
# (Bilag 1, punkt 4b) kræver at en fakturas PDF ENTEN genereres automatisk og
# opbevares digitalt, ELLER kan indlæses/opbevares digitalt. Ved selvsyn i
# send_invoice_action.php (linje 81-98+150, FØR denne rettelse) blev PDF'en
# kun gemt MIDLERTIDIGT som arbejdsfil til selve mail-vedhæftningen, og
# SLETTET IGEN efter en vellykket afsendelse - kun en faktura, hvis
# mailafsendelse reelt FEJLEDE, beholdt ved et tilfælde sin PDF-kopi (stik
# modsat af hensigten). En faktura der aldrig blev mailet (kun printet eller
# markeret betalt manuelt) fik ALDRIG nogen gemt PDF-kopi overhovedet.
#
# Permanent arkivsti: storage/invoices/invoice_{invoice_no}.pdf - samme mappe
# (allerede et kendt, beskyttet "folder_key" i storage_browser.php, blot
# hidtil ubrugt af noget som helst i kodebasen) og samme "ikke-nulpaddet
# invoice_no i filnavnet"-navngivning som export_oioubl.phps OIOUBL-arkiv
# allerede bruger (OIOUBL_Invoice_{no}.xml, se dens egen $doc_id) -
# storage_browser.phps eksisterende slette-spærring
# (storage_file_is_locked_attachment()) genkender derfor denne nye fil-type
# UDEN ændringer dér: den udtrækker allerede det LÆNGSTE tal i et vilkårligt
# filnavn under folder_key 'invoices'/'einvoices' og slår det op mod
# invoices.invoice_no.

// Den permanente arkivsti for en fakturas PDF-kopi. Returnerer null hvis
// fakturaen (endnu) ikke er bogført - den har da intet invoice_no at
// navngive filen efter, og skal (endnu) ikke arkiveres.
// RETTET: en kreditnota har intet fakturanummer (kun sit eget kreditnotanummer, credit_note_no) - stien blev
// null, så kreditnotaen kunne ikke mailes ("ikke bogført endnu") og blev ALDRIG arkiveret, selvom den er et
// bilag på linje med fakturaen. Den arkiveres nu som creditnote_<nr>.pdf (uden nummerserie: creditnote_id<id>).
function invoice_archive_pdf_path($conn, int $inv_id): ?string {
    $row = DB::fetch_assoc(DB::query($conn, "SELECT * FROM invoices WHERE inv_id = $inv_id"));
    if (!$row) return null;
    $dir = __DIR__ . '/../storage/invoices/' . tc_account_storage_subdir();      // (eget regnskab - se tc_account_storage_subdir())
    if (!empty($row['invoice_no'])) return $dir . 'invoice_' . (int)$row['invoice_no'] . '.pdf';
    if (!empty($row['credit_ref']) && strtolower((string)$row['inv_status']) !== 'draft') {
        return $dir . (!empty($row['credit_note_no']) ? 'creditnote_' . (int)$row['credit_note_no'] : 'creditnote_id' . $inv_id) . '.pdf';
    }
    return null;
}

// Findes der allerede en arkiveret PDF-kopi for denne faktura?
function invoice_has_archived_pdf($conn, int $inv_id): bool {
    $path = invoice_archive_pdf_path($conn, $inv_id);
    return $path !== null && file_exists($path) && filesize($path) > 0;
}

// Gemmer en klient-uploadet PDF (fra html2pdf.js, se invoice_view.php) til
// den permanente arkivsti. Delt mellem send_invoice_action.php (mail-
// afsendelse) og det nye invoice_archive_pdf.php (stille arkivering uden
// afsendelse), så de to ALDRIG kan ende med at gemme PDF'en to forskellige
// steder eller på to forskellige måder.
// Returnerer ['success'=>bool, 'error'=>?string, 'path'=>?string].
function invoice_store_uploaded_pdf($conn, int $inv_id): array {
    $path = invoice_archive_pdf_path($conn, $inv_id);
    if ($path === null) {
        return ['success' => false, 'error' => 'Fakturaen er ikke bogført endnu - intet fakturanummer at arkivere under.', 'path' => null];
    }

    // RETTET (find-fem-fejl-gennemgang): send_invoice_action.php kaldte denne
    // funktion UBETINGET ved hver (gen)afsendelse, og skrev dermed altid et
    // FRISKT genereret PDF ind over det allerede arkiverede - uden selv at
    // tjekke invoice_has_archived_pdf() først (i modsætning til invoice_view.
    // phps egen "selv-helbred"-kald, som korrekt kun kører når arkivet
    // mangler). Ændres fx firmaets logo/indstillinger mellem to afsendelser af
    // SAMME faktura, ville det oprindelige, reelt udsendte dokument stille gå
    // tabt - stik imod selve formålet med en permanent arkivering. Findes der
    // allerede et arkiveret PDF, bruges det UÆNDRET igen (både som arkiv OG
    // som selve mail-vedhæftningen) i stedet for at overskrive det.
    if (invoice_has_archived_pdf($conn, $inv_id)) {
        return ['success' => true, 'error' => null, 'path' => $path];
    }

    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $path)) {
            return ['success' => false, 'error' => 'Kunne ikke flytte den uploadede fil.', 'path' => null];
        }
    } elseif (isset($_POST['pdf_data'])) {
        $data = $_POST['pdf_data'];
        if (strpos($data, ',') !== false) $data = explode(',', $data)[1];
        if (file_put_contents($path, base64_decode($data)) === false) {
            return ['success' => false, 'error' => 'Kunne ikke skrive PDF-filen til disk.', 'path' => null];
        }
    } else {
        return ['success' => false, 'error' => 'Ingen PDF-data modtaget fra browseren.', 'path' => null];
    }

    if (!file_exists($path) || filesize($path) === 0) {
        return ['success' => false, 'error' => 'PDF-filen kunne ikke gemmes eller var tom.', 'path' => null];
    }
    // RETTET: hvad som helst blev gemt som fakturaens permanente PDF - en afbrudt/forkert upload (html2pdf
    // fejlede, en HTML-fejlside, en anden fil) blev liggende, og invoice_has_archived_pdf() (kun størrelse > 0)
    // forhindrede, at den nogensinde blev lavet igen. Kun en fil der er en PDF, beholdes.
    $fh = @fopen($path, 'rb');  $head = $fh ? fread($fh, 5) : '';  if ($fh) { fseek($fh, max(0, filesize($path) - 1024));  $tail = fread($fh, 1024);  fclose($fh); }
    if ($head !== '%PDF-' || strpos($tail ?? '', '%%EOF') === false) {
        @unlink($path);
        return ['success' => false, 'error' => 'Den modtagne fil er ikke en hel PDF - den er ikke gemt (forsøges igen næste gang fakturaen vises).', 'path' => null];
    }
    return ['success' => true, 'error' => null, 'path' => $path];
}
