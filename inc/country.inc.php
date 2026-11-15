<?php # /inc/country.inc.php v:1.3.1 d:2026-09-15 i:evs
// Landepakker: alt der afhænger af landet (talformat, momssatser og -koder, firmaregister, rykkerregler, e-faktura
// m.m.) beskrives i json-data/countries/<kode>.json. Indstillingen 'country' vælger pakken; standard er 'dk', så en
// installation uden indstillingen opfører sig som altid. Se tools/landepakke_opgoerelse.md.

const TC_COUNTRY_DEFAULT = 'dk';
const TC_VAT_TYPES = ['sales', 'purchase', 'eu_goods_sale', 'eu_goods_sale_nonlisted', 'eu_services_sale', 'eu_goods_purchase', 'eu_services_purchase',
                      'noneu_goods_purchase', 'noneu_services_purchase', 'export', 'exempt', 'reverse_domestic'];
// Køb med omvendt betalingspligt: leverandørens faktura er uden moms; køberen beregner momsen og bogfører den både som
// skyldig (momskodens konto, fx 7700 "Moms af varekøb udland") og som fradrag (købsmoms) - se tc_vat_rc_info()
const TC_VAT_RC_TYPES = ['eu_goods_purchase', 'eu_services_purchase', 'noneu_goods_purchase', 'noneu_services_purchase'];

function tc_country_dir(): string {
    // (testpakken kan pege på en mappe med egne testpakker - kun fra kommandolinjen)
    if (PHP_SAPI === 'cli' && !empty($GLOBALS['tc_country_dir_test'])) return $GLOBALS['tc_country_dir_test'];
    return __DIR__ . '/../json-data/countries/';
}

// De landepakker der findes: [kode => navn]
function tc_country_list(): array {
    $out = [];
    foreach (glob(tc_country_dir() . '*.json') ?: [] as $f) {
        $p = json_decode((string)file_get_contents($f), true);
        if (is_array($p) && ($p['code'] ?? '') === basename($f, '.json')) $out[$p['code']] = (string)($p['name'] ?? $p['code']);
    }
    ksort($out);
    return $out;
}

// Den valgte pakkes kode. Ukendt eller ugyldig kode = standardpakken (en fejltastet indstilling må ikke vælte siden).
function tc_country(): string {
    $c = strtolower(trim((string)(function_exists('setting') ? setting('country', TC_COUNTRY_DEFAULT) : TC_COUNTRY_DEFAULT)));
    return (preg_match('/^[a-z]{2}$/', $c) && is_file(tc_country_dir() . $c . '.json')) ? $c : TC_COUNTRY_DEFAULT;
}

// Hele pakken, eller én værdi ved en sti med punktummer: tc_country_pack('vat.standard_rate', 25).
// Pakken læses én gang pr. forespørgsel og pr. land (indstillingen kan skifte undervejs, fx når den gemmes).
function tc_country_pack(?string $path = null, $default = null) {
    static $cache = [];
    $code = tc_country();
    if (!isset($cache[$code])) {
        $p = json_decode((string)@file_get_contents(tc_country_dir() . $code . '.json'), true);
        $cache[$code] = is_array($p) ? $p : [];
    }
    if ($path === null) return $cache[$code];
    $v = $cache[$code];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

// Talformatets decimal- og tusindtalstegn: [',', '.'] i Danmark
function tc_num_sep(): array {
    static $memo = [];
    $c = tc_country();
    if (!isset($memo[$c])) {
        $dec  = (string)tc_country_pack('number.dec', ',');
        $thou = (string)tc_country_pack('number.thou', '.');
        $memo[$c] = [$dec === '' ? ',' : $dec, $thou];
    }
    return $memo[$c];
}

// Sprog-/landekode til JavaScripts toLocaleString() og Intl
function tc_locale(): string {
    return (string)tc_country_pack('locale', 'da-DK');
}

// Landets almindelige momssats - standardværdien når en vare/linje ikke har sin egen
function tc_vat_std_rate(): float {
    return (float)tc_country_pack('vat.standard_rate', 25);
}

// Moms indeholdt i et beløb inkl. moms ved landets almindelige sats: 125 -> 25 i Danmark
function tc_vat_of_gross(float $gross, ?float $rate = null): float {
    $r = $rate ?? tc_vat_std_rate();
    return $r > 0 ? round($gross * $r / (100 + $r), 2) : 0.0;
}

// Valutaens visningsnavn efter et beløb: "kr" for landets egen valuta, ellers valutakoden ("EUR")
function tc_cur_label(?string $cur = null): string {
    $cur = strtoupper((string)($cur ?? setting('currency', (string)tc_country_pack('currency', 'DKK'))));
    return $cur === strtoupper((string)tc_country_pack('currency', '')) ? (string)tc_country_pack('currency_symbol', $cur) : $cur;
}

// Rykkergebyr efter landets regler. $no = rykkerens nummer, $days_since_prev = dage siden forrige rykker.
function tc_reminder_fee_allowed(float $fee, int $no, int $days_since_prev, string $cur): float {
    $r = (array)tc_country_pack('reminders', []);
    if ($no < (int)($r['fee_from_reminder'] ?? 1)) return 0.0;
    if (!empty($r['fee_until_reminder']) && $no > (int)$r['fee_until_reminder']) return 0.0;
    if ($days_since_prev < (int)($r['min_days_between'] ?? 0)) return 0.0;
    if (isset($r['max_fee']) && strtoupper($cur) === strtoupper((string)($r['max_fee_currency'] ?? ''))) $fee = min($fee, (float)$r['max_fee']);
    return $fee;
}

// Firmaregisteret for landets firma-id (CVR i Danmark); null = intet opslag i denne pakke
function tc_registry(): ?string {
    $r = tc_country_pack('company_id.registry');
    return is_string($r) && $r !== '' ? $r : null;
}

// Har landepakken en landespecifik funktion? Momsangivelse, OIOUBL og SAF-T kræver desuden, at bogføringsvalutaen er
// landets egen (indberetningen sker i landets valuta). Ukendt funktion = nej.
function tc_pack_feature(string $f): bool {
    $own_cur = strtoupper((string)(function_exists('setting') ? setting('currency', (string)tc_country_pack('currency', 'DKK')) : 'DKK'))
               === strtoupper((string)tc_country_pack('currency', 'DKK'));
    switch ($f) {
        case 'vat_report':    return $own_cur && (array)tc_country_pack('vat_return', []) !== [];
        case 'oioubl':        return $own_cur && in_array('oioubl21', (array)tc_country_pack('einvoice', []), true);
        case 'peppol':        return in_array('peppol_bis3', (array)tc_country_pack('einvoice', []), true);
        case 'saft_dk':       return $own_cur && tc_country_pack('saft') === 'dk';
        case 'saft_no':       return $own_cur && tc_country_pack('saft') === 'no';
        case 'annual_report': return (string)tc_country_pack('legal.annual_report', '') !== '';
        case 'cvr_lock':      return (bool)tc_country_pack('legal.cvr_lock', false);
        case 'oss':           return (bool)tc_country_pack('legal.oss', false);
        case 'ioss':          return (bool)tc_country_pack('legal.ioss', false);
        case 'eu_sales_list': return $own_cur && (bool)tc_country_pack('legal.eu_sales_list', false);
    }
    return false;
}

// Virksomhedens momsnummer med landekode: indstillingen company_vat_id, ellers prefiks + firma-id + suffiks, når det
// passer til landets form (DK12345678, SE556677889901, NO123456789MVA, FI12345678). Island: VSK-númerið er ikke
// kennitalaen og skal indtastes. '' = ukendt.
function tc_company_vat_id(): string {
    $re  = (string)tc_country_pack('vat_id.regex', '');
    $own = (string)(tc_vat_id_clean(setting('company_vat_id', '')) ?? '');
    // (kun når det passer til landepakken - efter et skift af land hørte et gammelt momsnummer til det forrige land)
    if ($own !== '' && ($re === '' || preg_match('/' . str_replace('/', '\/', $re) . '/', $own))) return $own;
    $id  = preg_replace('/\D/', '', (string)setting('company_cvr', ''));
    // (Litauen: momsnummeret er et andet nummer end virksomhedskoden - "LT" + koden passede til mønstret, men var forkert)
    if ($id === '' || tc_country_pack('vat_id.derive', true) === false) return '';
    $v = (string)tc_country_pack('vat_id.prefix', (string)tc_country_pack('iso', 'DK')) . $id . (string)tc_country_pack('vat_id.suffix', '');
    return ($re === '' || preg_match('/' . str_replace('/', '\/', $re) . '/', $v)) ? $v : '';
}

// Kort etiket for firma-id'et på blanketter ("CVR", "Org.nr", "Y-tunnus")
function tc_company_id_short(): string {
    return htmlspecialchars((string)tc_country_pack('company_id.short', 'CVR'));
}

// Tekst loven kræver på salgsdokumenter, fx Norges "Foretaksregisteret" (foretaksregisterloven § 10-2); '' = ingen
function tc_register_text(): string {
    return (string)tc_country_pack('legal.register_text', '');
}

// Etiketten for firma-id'et ("CVR-nummer" / "Org.nr." ...), allerede oversat
function tc_company_id_label(): string {
    return lang((string)tc_country_pack('company_id.label', '@CVR Number'));
}

// Momskodetyperne med oversatte navne, til vælgere og oversigter
function tc_vat_type_labels(): array {
    return [
        'sales'                => lang('@Sales VAT (domestic)'),
        'purchase'             => lang('@Purchase VAT (domestic)'),
        'eu_goods_sale'        => lang('@EU sale of goods (no VAT)'),
        'eu_goods_sale_nonlisted' => lang('@EU sale of goods, not for the EU sales list (no VAT)'),
        'eu_services_sale'     => lang('@EU sale of services (no VAT)'),
        'eu_goods_purchase'    => lang('@EU purchase of goods (reverse charge)'),
        'eu_services_purchase' => lang('@EU purchase of services (reverse charge)'),
        'noneu_goods_purchase' => lang('@Purchase of goods from outside the EU (VAT in the VAT return)'),
        'noneu_services_purchase' => lang('@Purchase of services from outside the EU (reverse charge)'),
        'export'               => lang('@Export outside the EU (no VAT)'),
        'exempt'               => lang('@VAT exempt'),
        'reverse_domestic'     => lang('@Domestic sale with reverse charge'),
    ];
}

// Slår et firma-id op i landets firmaregister. Samme svarformat som cvr_lookup_by_number():
// ['success' => true, 'cvr', 'name', 'street', 'zipcode', 'city', ...] eller ['success' => false, 'error' => ...]
function tc_registry_lookup($conn, string $id): array {
    switch (tc_registry()) {
        case 'dk_cvr':
            require_once __DIR__ . '/cvr_lookup.inc.php';
            return cvr_lookup_by_number($conn, $id);
        case 'no_brreg':
            require_once __DIR__ . '/registry_nordic.inc.php';
            return tc_registry_no_brreg($id);
        case 'fi_prh':
            require_once __DIR__ . '/registry_nordic.inc.php';
            return tc_registry_fi_prh($id);
        default:
            return ['success' => false, 'error' => lang('@There is no company register lookup for the selected country - please fill in the details manually.')];
    }
}

// Lande til kunde/leverandør (EU/EØS, Norden og de hyppigste handelspartnere): [ISO-kode => engelsk navn som lang-nøgle]
function tc_country_codes(): array {
    return [
        'DK' => '@Denmark', 'SE' => '@Sweden', 'NO' => '@Norway', 'FI' => '@Finland', 'IS' => '@Iceland', 'GL' => '@Greenland', 'FO' => '@Faroe Islands',
        'DE' => '@Germany', 'NL' => '@Netherlands', 'BE' => '@Belgium', 'LU' => '@Luxembourg', 'FR' => '@France', 'IE' => '@Ireland',
        'AT' => '@Austria', 'IT' => '@Italy', 'ES' => '@Spain', 'PT' => '@Portugal', 'PL' => '@Poland', 'CZ' => '@Czechia',
        'SK' => '@Slovakia', 'HU' => '@Hungary', 'SI' => '@Slovenia', 'HR' => '@Croatia', 'RO' => '@Romania', 'BG' => '@Bulgaria',
        'GR' => '@Greece', 'CY' => '@Cyprus', 'MT' => '@Malta', 'EE' => '@Estonia', 'LV' => '@Latvia', 'LT' => '@Lithuania',
        'CH' => '@Switzerland', 'GB' => '@United Kingdom', 'US' => '@United States', 'CN' => '@China',
    ];
}
// Antal cifre i landets firma-id (talt i pakkens regex): 8 for CVR, 9 for norsk org.nr. - til opslagsknapperne
function tc_company_id_digits(): int {
    $re = (string)tc_country_pack('company_id.regex', '^[0-9]{8}$');
    // (enhver cifferklasse tæller - Belgiens [01] og Letlands [4-9] blev før sprunget over: 9 og 10 i stedet for 10 og 11)
    preg_match_all('/\[[0-9-]+\](?:\{(\d+)\})?/', $re, $m);
    $n = 0;
    foreach ($m[1] as $q) $n += $q === '' ? 1 : (int)$q;
    return $n ?: 8;
}

// Postnummerets mønster (uden ^$) for et land: landepakkens, ellers en kendt form; null = ukendt land
function tc_zip_pattern(string $cc): ?string {
    $cc = strtoupper($cc);
    $f = tc_country_dir() . strtolower($cc) . '.json';
    if (preg_match('/^[A-Z]{2}$/', $cc) && is_file($f)) {
        $re = (string)(json_decode((string)file_get_contents($f), true)['zip']['regex'] ?? '');
        if ($re !== '') return trim($re, '^$');
    }
    $known = ['DE' => '[0-9]{5}', 'FR' => '[0-9]{5}', 'IT' => '[0-9]{5}', 'ES' => '[0-9]{5}', 'EE' => '[0-9]{5}', 'GR' => '[0-9]{3} ?[0-9]{2}',
              'CZ' => '[0-9]{3} ?[0-9]{2}', 'SK' => '[0-9]{3} ?[0-9]{2}', 'NL' => '[0-9]{4} ?[A-Z]{2}', 'PL' => '[0-9]{2}-[0-9]{3}',
              'PT' => '[0-9]{4}-[0-9]{3}', 'BE' => '[0-9]{4}', 'AT' => '[0-9]{4}', 'CH' => '[0-9]{4}', 'LU' => '[0-9]{4}', 'HU' => '[0-9]{4}',
              'SI' => '[0-9]{4}', 'BG' => '[0-9]{4}', 'CY' => '[0-9]{4}', 'GL' => '39[0-9]{2}', 'FO' => '[0-9]{3}', 'HR' => '[0-9]{5}',
              'RO' => '[0-9]{6}', 'LT' => '[0-9]{5}', 'LV' => '[0-9]{4}', 'US' => '[0-9]{5}(?:-[0-9]{4})?',
              'GB' => '[A-Z]{1,2}[0-9][0-9A-Z]? ?[0-9][A-Z]{2}', 'IE' => '[A-Z][0-9][0-9W] ?[0-9A-Z]{4}'];
    return $known[$cc] ?? null;
}

// Fritekstadresse -> ['street', 'zip', 'city']. Adresserne er fritekst ("Gade 1, 2. th, 2000 Frederiksberg",
// "Storgata 5\n0155 Oslo", "Main St 1, 10115 Berlin, Germany"): sidste linje/komma-led er "postnr. by" efter
// landets postnummerform, og et afsluttende landenavn/-kode fjernes. $zip/$city er særskilte felter, hvis de findes.
function tc_split_address(string $txt, string $zip = '', string $city = '', string $cc = ''): array {
    $pat = tc_zip_pattern($cc ?: (string)tc_country_pack('iso', 'DK')) ?? '[0-9][0-9A-Z]{1,4}(?: [0-9]{2,3})?';
    $line = '/^(?:[A-Z]{1,2}-)?(' . $pat . ')\s+(\D.*)$/u';
    $parts = array_values(array_filter(array_map('trim', preg_split('/[,\n]/', trim($txt))), 'strlen'));
    // (et afsluttende land - "Germany", "Tyskland", "DE" - er ikke en del af byen)
    if (count($parts) > 1) {
        $last = mb_strtolower(end($parts));
        foreach (tc_country_codes() as $iso => $key) {
            if ($last === strtolower($iso) || $last === mb_strtolower(ltrim($key, '@')) || $last === mb_strtolower(lang($key))) { array_pop($parts);  break; }
        }
    }
    // (Storbritannien/Irland: byen står før postnummeret - "London SW1A 1AA" eller postnummeret på sin egen linje)
    $uk = ['GB' => '[A-Z]{1,2}[0-9][0-9A-Z]? ?[0-9][A-Z]{2}', 'IE' => '[A-Z][0-9][0-9W] ?[0-9A-Z]{4}'][strtoupper($cc)] ?? null;
    if ($uk && count($parts) > 1 && preg_match('/^(?:(\D.*?)\s+)?(' . $uk . ')$/iu', end($parts), $m)) {
        array_pop($parts);  $zip = strtoupper($m[2]);
        $city = $m[1] !== '' ? $m[1] : (count($parts) > 1 ? array_pop($parts) : '');
    }
    elseif (count($parts) > 1 && preg_match($line, end($parts), $m)) { array_pop($parts);  $zip = $m[1];  $city = $m[2]; }
    elseif (trim($zip) === '' && preg_match($line, trim($city), $m)) { $zip = $m[1];  $city = $m[2]; }     // byfeltet "1051 København K"
    return ['street' => implode(', ', $parts), 'zip' => trim($zip), 'city' => trim($city)];
}

// Passer et postnummer til landets form? Ukendt land eller tomt felt = ja (kun det, vi kender, kan afvises)
function tc_zip_ok(string $zip, string $cc = ''): bool {
    $pat = tc_zip_pattern($cc ?: (string)tc_country_pack('iso', 'DK'));
    return $pat === null || trim($zip) === '' || preg_match('/^(?:[A-Z]{1,2}-)?(?:' . $pat . ')$/u', strtoupper(trim($zip)));
}

// EU-medlemslandene - til omvendt betalingspligt/EU-salg (Grønland og Færøerne er ikke med i EU)
// Salgstyper uden moms - et salg med moms hører aldrig på en konto med en af dem (invoice_sales_split)
const TC_VAT_ZERO_SALE_TYPES = ['eu_goods_sale', 'eu_goods_sale_nonlisted', 'eu_services_sale', 'export', 'reverse_domestic', 'exempt'];

const TC_EU_COUNTRIES = ['AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK'];

// Et momsnummer (VAT-id) med landekode, fx "SE556677889901": store bogstaver, uden mellemrum/punktum/bindestreg.
// '' = tomt felt, null = ugyldigt. Grækenland bruger EL i momsnumre.
function tc_vat_id_clean($raw): ?string {
    $s = strtoupper(preg_replace('/[\s.\-]/', '', (string)$raw));
    if ($s === '') return '';
    return preg_match('/^[A-Z]{2}[A-Z0-9+*]{2,13}$/', $s) ? $s : null;
}

// Omvendt betalingspligt for en konto: kontoens momskode har en købstype med omvendt betalingspligt.
// null = almindelig konto. Ellers ['code', 'type', 'rate', 'acc' => kontoen den skyldige moms bogføres på (kan være 0
// = ikke sat op)]. Huskes pr. forespørgsel.
function tc_vat_rc_info($conn, int $acc_id): ?array {
    $memo = &$GLOBALS['tc_vat_rc_memo'];      // (tc_vat_rc_forget() rydder den, fx efter en ændret momskode)
    if (!is_array($memo)) $memo = [];
    if (array_key_exists($acc_id, $memo)) return $memo[$acc_id];
    $memo[$acc_id] = null;
    if (!db_has_column($conn, 'vat_codes', 'vat_type')) return null;
    $r = DB::fetch_assoc(DB::query($conn, "SELECT v.vat_id, v.vat_type, v.vat_rate, v.vat_account FROM accounts a
                                          JOIN vat_codes v ON v.vat_id = a.vat_code WHERE a.acc_id = $acc_id"));
    if ($r && in_array($r['vat_type'], TC_VAT_RC_TYPES, true) && (float)$r['vat_rate'] > 0) {
        $memo[$acc_id] = ['code' => (string)$r['vat_id'], 'type' => (string)$r['vat_type'], 'rate' => (float)$r['vat_rate'], 'acc' => (int)$r['vat_account']];
    }
    return $memo[$acc_id];
}

// Tjek før en udgift/indtægt bogføres på konti med omvendt betalingspligt. '' = i orden, ellers en fejltekst.
function expense_rc_check($conn, array $acc_ids, bool $is_income): string {
    foreach (array_unique(array_map('intval', $acc_ids)) as $a) {
        $rc = tc_vat_rc_info($conn, $a);
        if (!$rc) continue;
        if ($is_income) return sprintf(lang('@Account %d has a reverse charge VAT code (%s), which only applies to purchases.'), $a, $rc['code']);
        if ($rc['acc'] <= 0 || !tc_account_ok($conn, $rc['acc'])) return sprintf(lang('@The VAT code %s (reverse charge) has no valid account for the VAT owed - set it on the VAT Codes page. Nothing was posted.'), $rc['code']);
    }
    return '';
}

// Opretter landepakkens momskoder der mangler (EU-salg, eksport, omvendt betalingspligt) og kobler standardkontoplanens
// udlandskonti til dem. Rører aldrig en eksisterende momskode, og en konto får kun en kode hvis dens nummer OG navn
// er standardkontoplanens og den ikke allerede har en EU-/eksportkode. Returnerer en liste af det der blev gjort.
function tc_apply_pack_vat_setup($conn): array {
    $log = [];
    if (!db_has_column($conn, 'vat_codes', 'vat_type')) return ['vat_codes.vat_type mangler - kør migrate_vat_code_types.php først'];
    foreach ((array)tc_country_pack('vat.codes', []) as $vc) {
        if (!is_array($vc) || !preg_match('/^[A-Za-z0-9_]{1,10}$/', (string)($vc['id'] ?? '')) || !in_array($vc['type'] ?? '', TC_VAT_TYPES, true)) continue;
        if (DB::fetch_assoc(DB::prepare_and_execute($conn, "SELECT vat_id FROM vat_codes WHERE vat_id = ?", [$vc['id']]))) continue;
        $acc = !empty($vc['rc_account']) && tc_account_ok($conn, (int)$vc['rc_account']) ? (int)$vc['rc_account'] : null;
        DB::insert($conn, 'vat_codes', ['vat_id' => $vc['id'], 'vat_name' => (string)($vc['name'] ?? $vc['id']), 'vat_rate' => (float)($vc['rate'] ?? 0),
                                        'vat_account' => $acc, 'vat_type' => $vc['type']]);
        $log[] = "momskode {$vc['id']} oprettet" . ($acc ? " (konto $acc)" : (!empty($vc['rc_account']) ? " - konto {$vc['rc_account']} findes ikke, sæt kontoen på Momskoder" : ''));
    }
    $keep = ['eu_goods_sale', 'eu_goods_sale_nonlisted', 'eu_services_sale', 'export', 'reverse_domestic', 'eu_goods_purchase', 'eu_services_purchase', 'noneu_goods_purchase', 'noneu_services_purchase'];
    foreach ((array)tc_country_pack('vat.std_account_codes', []) as $acc_id => $m) {
        [$name, $code] = $m;
        $a = DB::fetch_assoc(DB::prepare_and_execute($conn, "SELECT a.acc_id, a.vat_code, v.vat_type FROM accounts a LEFT JOIN vat_codes v ON v.vat_id = a.vat_code
                                                            WHERE a.acc_id = ? AND a.acc_name = ?", [(int)$acc_id, $name]));
        if (!$a || in_array($a['vat_type'], $keep, true)) continue;
        DB::prepare_and_execute($conn, "UPDATE accounts SET vat_code = ?, vat_rate = 0 WHERE acc_id = ?", [$code, (int)$acc_id]);
        $log[] = "konto $acc_id ($name): momskode " . ($a['vat_code'] ?: '-') . " -> $code";
    }
    return $log;
}

// Hjemlandets salgssatser: 0 %, standardsatsen, pakkens salgskoder og regnskabets egne salgsmomskoder
function tc_home_sales_rates($conn): array {
    $home = [0.0, tc_vat_std_rate()];
    foreach ((array)tc_country_pack('vat.codes', []) as $vc) if (($vc['type'] ?? '') === 'sales') $home[] = (float)($vc['rate'] ?? 0);
    // Pakken kender landets satser: kun de gælder. Momskoderne og kontiene i databasen kan stamme fra et tidligere land - efter
    // et skift fra Danmark til Tyskland blev 25 % stadig godtaget, og salget faldt uden for momsangivelsens felter
    if (is_array(tc_country_pack('vat.codes'))) return array_values(array_unique($home, SORT_REGULAR));
    $res = DB::query($conn, "SELECT DISTINCT vat_rate FROM vat_codes" . (db_has_column($conn, 'vat_codes', 'vat_type') ? " WHERE vat_type = 'sales'" : ''));
    while ($res && ($r = DB::fetch_row($res))) $home[] = (float)$r[0];
    // (og indtægtskontiene - en vare får sin sats fra sin konto)
    $res = DB::query($conn, "SELECT DISTINCT vat_rate FROM accounts WHERE acc_type = 'income' AND vat_rate IS NOT NULL");
    while ($res && ($r = DB::fetch_row($res))) $home[] = (float)$r[0];
    return array_values(array_unique($home, SORT_REGULAR));
}

// Er satsen en af hjemlandets salgssatser?
function tc_home_rate_ok($conn, float $rate): bool {
    foreach (tc_home_sales_rates($conn) as $h) if (abs($h - $rate) < 0.001) return true;
    return false;
}

function tc_vat_rc_forget(): void {
    $GLOBALS['tc_vat_rc_memo'] = [];
}
require_once __DIR__ . '/payment_ref.inc.php';      // betalingsreference (FIK/KID/OCR/viitenumero/RF) fra landepakken
require_once __DIR__ . '/oss.inc.php';      // EU-OSS (salg til private i andre EU-lande)

// Hver fakturalinjes momskodetype: [line_id => vat_type|null]. Kontoen findes som ved bogføringen
// (invoice_sales_split): varens indtægtskonto, ellers standard-salgskontoen.
function tc_invoice_line_vat_types($conn, int $inv_id): array {
    if (!db_has_column($conn, 'vat_codes', 'vat_type')) return [];
    $acc_sales = (int)setting('conf_acc_sales', 1000);
    $out = [];
    $res = DB::query($conn, "SELECT il.line_id, COALESCE(il.line_vat_rate, 0) AS rate, CASE WHEN a.acc_type = 'income' THEN a.acc_id ELSE $acc_sales END AS acc
                             FROM invoice_lines il LEFT JOIN products p ON p.prod_id = il.prod_id AND il.prod_id > 0
                             LEFT JOIN accounts a ON a.acc_id = p.acc_id WHERE il.inv_id = $inv_id");
    $types = [];
    while ($res && ($r = DB::fetch_assoc($res))) {
        $acc = (int)$r['acc'];
        if (!array_key_exists($acc, $types)) {
            $t = DB::fetch_row(DB::query($conn, "SELECT v.vat_type FROM accounts a JOIN vat_codes v ON v.vat_id = a.vat_code WHERE a.acc_id = $acc"));
            $types[$acc] = $t[0] ?? null;
        }
        $t = $types[$acc];
        // (med moms: bogført på standard-salgskontoen - se invoice_sales_split)
        if (abs((float)$r['rate']) >= 0.001 && in_array($t, TC_VAT_ZERO_SALE_TYPES, true)) {
            if (!array_key_exists($acc_sales, $types)) $types[$acc_sales] = DB::fetch_row(DB::query($conn, "SELECT v.vat_type FROM accounts a JOIN vat_codes v ON v.vat_id = a.vat_code WHERE a.acc_id = $acc_sales"))[0] ?? null;
            $t = $types[$acc_sales];
        }
        $out[(int)$r['line_id']] = $t;
    }
    return $out;
}
