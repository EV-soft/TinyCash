<?php # /inc/doc_paper.inc.php v:1.0.0 d:2026-11-04 i:evs
// Fælles for dokumenter der sendes til kunden (faktura/kreditnota, tilbud/ordrebekræftelse, rykker, mails):
// kundens sprog og afsenderens firmaoplysninger. Ét sted, så papirerne ikke glider fra hinanden igen
// (tilbuddet stod på bogholderens sprog, rykkeren på dansk til en engelsk kunde, pladsholdere på det forkerte sprog).

// Kundens dokumentsprog fra kundekortet ('' = intet valgt: bogholderens eget sprog). En kunde i et andet land uden et
// valgt sprog får engelsk - før fik fx en tysk kunde fakturaen, rykkeren og mailen på dansk. (Dansk, grønlandsk og
// færøsk side tæller som samme sprogområde.)
function doc_customer_lang($conn, int $cust_id): string {
    if ($cust_id <= 0 || !db_has_column($conn, 'customers', 'cust_language')) return '';
    $has_cc = db_has_column($conn, 'customers', 'cust_country');
    $r = DB::fetch_row(DB::query($conn, "SELECT cust_language" . ($has_cc ? ", cust_country" : ", ''") . " FROM customers WHERE cust_id = $cust_id"));
    $lang = (string)($r[0] ?? '');
    $cc = strtolower(trim((string)($r[1] ?? '')));
    if ($lang !== '' || $cc === '' || !function_exists('tc_country_pack')) return $lang;
    $own = strtolower((string)tc_country_pack('iso', 'DK'));
    $da_area = ['dk', 'gl', 'fo'];
    if ($cc === $own || (in_array($cc, $da_area, true) && in_array($own, $da_area, true))) return '';
    return 'en';
}

// Et PDF-filnavns første del på det aktive sprog: det danske navn i Danmark, ellers den oversatte titel - kun ASCII
// (filnavnene var altid danske: "rykker_...", "Kontoudtog_...", "foelgeseddel_...")
function doc_file_label(string $da_label, string $key): string {
    $l = (($_SESSION['lang'] ?? (function_exists('tc_default_lang') ? tc_default_lang() : 'da')) === 'da') ? $da_label : lang($key);
    $l = strtr($l, ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', $l), '_') ?: 'document';
}

// Skift lang() midlertidigt til kundens sprog; doc_lang_pop() gendanner (kan indlejres). '' = intet skift.
function doc_lang_push(string $lang): void {
    $GLOBALS['tc_doc_lang_stack'][] = $_SESSION['lang'] ?? null;      // (intet valg = standardsproget igen bagefter, ikke dansk)
    if ($lang !== '') $_SESSION['lang'] = $lang;
}
function doc_lang_pop(): void {
    if (!empty($GLOBALS['tc_doc_lang_stack'])) {
        $prev = array_pop($GLOBALS['tc_doc_lang_stack']);
        if ($prev === null) unset($_SESSION['lang']); else $_SESSION['lang'] = $prev;
    }
}

// Afsenderens firmaoplysninger - et manglende felt vises som en tydelig pladsholder (aldrig et opdigtet CVR-nr.),
// på det sprog der er aktivt nu (kald efter doc_lang_push() på kundens papir)
function doc_company(array $s): array {
    return [
        'name'       => ($s['company_name'] ?? '')    ?: lang('@[Company name missing - fill in under Company Settings]'),
        'addr'       => ($s['company_address'] ?? '') ?: lang('@[Address missing]'),
        'city'       => ($s['company_city'] ?? '')    ?: lang('@[City missing]'),
        'cvr'        => ($s['company_cvr'] ?? '')     ?: sprintf(lang('@[%s missing]'), tc_company_id_short()),
        'incomplete' => empty($s['company_name']) || empty($s['company_cvr']) || empty($s['company_address']) || empty($s['company_city']),
    ];
}
// En PDF af blanketten, uploadet af browseren (docPdfSend() i inc/doc_design.inc.php) til en mail-vedhæftning: kun en
// hel PDF godtages, og den midlertidige fil slettes igen, når anmodningen er færdig. null = ingen gyldig PDF.
function doc_uploaded_pdf(string $field = 'pdf_file'): ?string {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $base = tempnam(sys_get_temp_dir(), 'tcd');
    $path = $base . '.pdf';
    register_shutdown_function(function () use ($base, $path) { @unlink($base);  @unlink($path); });
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $path)) return null;
    $fh = @fopen($path, 'rb');  $head = $fh ? (string)fread($fh, 5) : '';  if ($fh) fclose($fh);
    return ($head === '%PDF-' && filesize($path) > 100) ? $path : null;
}
