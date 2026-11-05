<?php # /inc/doc_paper.inc.php v:1.0.0 d:2026-11-04 i:evs
// Fælles for dokumenter der sendes til kunden (faktura/kreditnota, tilbud/ordrebekræftelse, rykker, mails):
// kundens sprog og afsenderens firmaoplysninger. Ét sted, så papirerne ikke glider fra hinanden igen
// (tilbuddet stod på bogholderens sprog, rykkeren på dansk til en engelsk kunde, pladsholdere på det forkerte sprog).

// Kundens dokumentsprog fra kundekortet ('' = intet valgt: bogholderens eget sprog)
function doc_customer_lang($conn, int $cust_id): string {
    if ($cust_id <= 0 || !db_has_column($conn, 'customers', 'cust_language')) return '';
    $r = DB::fetch_row(DB::query($conn, "SELECT cust_language FROM customers WHERE cust_id = $cust_id"));
    return (string)($r[0] ?? '');
}

// Skift lang() midlertidigt til kundens sprog; doc_lang_pop() gendanner (kan indlejres). '' = intet skift.
function doc_lang_push(string $lang): void {
    $GLOBALS['tc_doc_lang_stack'][] = $_SESSION['lang'] ?? 'da';
    if ($lang !== '') $_SESSION['lang'] = $lang;
}
function doc_lang_pop(): void {
    if (!empty($GLOBALS['tc_doc_lang_stack'])) $_SESSION['lang'] = array_pop($GLOBALS['tc_doc_lang_stack']);
}

// Afsenderens firmaoplysninger - et manglende felt vises som en tydelig pladsholder (aldrig et opdigtet CVR-nr.),
// på det sprog der er aktivt nu (kald efter doc_lang_push() på kundens papir)
function doc_company(array $s): array {
    return [
        'name'       => ($s['company_name'] ?? '')    ?: lang('@[Company name missing - fill in under Company Settings]'),
        'addr'       => ($s['company_address'] ?? '') ?: lang('@[Address missing]'),
        'city'       => ($s['company_city'] ?? '')    ?: lang('@[City missing]'),
        'cvr'        => ($s['company_cvr'] ?? '')     ?: lang('@[CVR missing]'),
        'incomplete' => empty($s['company_name']) || empty($s['company_cvr']) || empty($s['company_address']) || empty($s['company_city']),
    ];
}
