<?php # /inc/oss.inc.php v:1.3.1 d:2026-09-15 i:evs
// EU-OSS (One Stop Shop, EU-ordningen): salg af varer og ydelser til PRIVATE i andre EU-lande pålægges modtagerlandets
// moms og indberettes kvartalsvis på én samlet OSS-angivelse - ikke på den hjemlige momsangivelse. En faktura er OSS,
// når virksomheden er OSS-registreret (Firmaindstillinger), kunden har et andet EU-land og intet momsnummer (B2C).
// Salg og moms bogføres da på egne OSS-konti, så den udenlandske moms holdes ude af den hjemlige angivelse.

const OSS_THRESHOLD_EUR = 10000;      // samlet EU-fjernsalg til private pr. kalenderår, før OSS/udenlandsk moms bliver pligtig

function oss_ready($conn): bool {
    return db_has_column($conn, 'invoices', 'inv_oss');
}

// invoices.inv_oss_off: 1 = salget til en privat kunde i et andet EU-land er IKKE OSS - en almindelig ydelse (fx
// konsulenttimer, reparation), der beskattes i sælgerens eget land (momssystemdirektivet art. 45). Satsen kan ikke afgøre
// det (Sverige har også 25 %), så det vælges pr. faktura. Varer og elektroniske ydelser til private er OSS.
function oss_off_ready($conn): bool {
    return db_has_column($conn, 'invoices', 'inv_oss_off');
}

// Er kunden en privatkunde i et andet EU-land - uanset om virksomheden er OSS-registreret? (til OSS-tærsklen)
function oss_is_eu_b2c_customer($conn, int $cust_id): bool {
    if (!db_has_column($conn, 'customers', 'cust_country')) return false;
    $c = DB::fetch_assoc(DB::query($conn, "SELECT cust_country, cust_vat_id FROM customers WHERE cust_id = " . (int)$cust_id));
    $cc = strtoupper(trim((string)($c['cust_country'] ?? '')));
    return $c && $cc !== '' && $cc !== strtoupper((string)tc_country_pack('iso', 'DK')) && in_array($cc, TC_EU_COUNTRIES, true) && trim((string)($c['cust_vat_id'] ?? '')) === '';
}

// Landepakken (kun EU-lande) har OSS
function oss_available(): bool {
    return (bool)tc_country_pack('legal.oss', false);
}

// OSS-registreret på $date (standard: i dag)? Før registreringsdatoen (oss_from, typisk første dag i kvartalet efter
// tilmeldingen) er et salg til en privatkunde i et andet EU-land et almindeligt salg med hjemlandets moms (under tærsklen)
function oss_registered(?string $date = null): bool {
    if (!oss_available() || setting('oss_registered', '0') !== '1') return false;
    $from = (string)setting('oss_from', '');
    return !tc_is_date($from) || substr($date ?? date('Y-m-d'), 0, 10) >= $from;
}

// EU-landenes satser: ['DE' => ['standard' => 19, 'reduced' => [7]], ...]
function oss_rates(): array {
    static $r = null;
    if ($r === null) {
        $j = json_decode((string)@file_get_contents(__DIR__ . '/../json-data/eu_vat_rates.json'), true);
        $r = is_array($j['rates'] ?? null) ? $j['rates'] : [];
    }
    return $r;
}

function oss_std_rate(string $cc): ?float {
    $r = oss_rates()[strtoupper($cc)]['standard'] ?? null;
    return $r === null ? null : (float)$r;
}

// Er satsen en af landets satser (0 % tilladt - fx momsfrie ydelser)?
function oss_rate_valid(string $cc, float $rate): bool {
    if (abs($rate) < 0.001) return true;
    $r = oss_rates()[strtoupper($cc)] ?? null;
    if (!$r) return false;
    foreach (array_merge([$r['standard']], (array)($r['reduced'] ?? [])) as $x) if (abs((float)$x - $rate) < 0.001) return true;
    return false;
}

// Modtagerlandet hvis et salg til en kunde i landet $cc med momsnummeret $vat_id er OSS, ellers null
function oss_dest_for(string $cc, string $vat_id, ?string $date = null): ?string {
    if (!oss_registered($date)) return null;
    $cc   = strtoupper(trim($cc));
    $home = strtoupper((string)tc_country_pack('iso', 'DK'));
    if ($cc === '' || $cc === $home || !in_array($cc, TC_EU_COUNTRIES, true)) return null;
    if (trim($vat_id) !== '') return null;      // (erhvervskunde: omvendt betalingspligt, ikke OSS)
    return $cc;
}

// Modtagerlandet hvis et salg til kunden på $date (standard: i dag) er OSS, ellers null
function oss_dest_for_customer($conn, int $cust_id, ?string $date = null): ?string {
    if (!oss_registered($date) || !db_has_column($conn, 'customers', 'cust_country')) return null;
    $c = DB::fetch_assoc(DB::query($conn, "SELECT cust_country, cust_vat_id FROM customers WHERE cust_id = " . (int)$cust_id));
    return $c ? oss_dest_for((string)($c['cust_country'] ?? ''), (string)($c['cust_vat_id'] ?? ''), $date) : null;
}

// Kunderne et salg til er OSS: [kunde => [land, standardsats]] - til faktura- og tilbudssidens JavaScript (assets/tc-oss.js)
function oss_customer_map($conn): array {
    $out = [];
    if (!oss_registered() || !oss_ready($conn) || !db_has_column($conn, 'customers', 'cust_country')) return $out;
    $res = DB::query($conn, "SELECT cust_id, cust_country, cust_vat_id FROM customers WHERE cust_country IS NOT NULL AND cust_country <> ''");
    while ($res && ($c = DB::fetch_assoc($res))) {
        $cc = oss_dest_for((string)$c['cust_country'], (string)($c['cust_vat_id'] ?? ''));
        if ($cc !== null && oss_std_rate($cc) !== null) $out[(int)$c['cust_id']] = [$cc, oss_std_rate($cc)];
    }
    return $out;
}

// En linjes sats til et OSS-salg: den hjemlige standardsats bliver modtagerlandets; alt andet er uændret
function oss_map_rate(string $cc, float $rate): float {
    $std = oss_std_rate($cc);
    return ($std !== null && abs($rate - tc_vat_std_rate()) < 0.001) ? $std : $rate;
}

// OSS-konti fra Firmaindstillinger: [salg, moms]. Kaster en Exception hvis de ikke er sat eller ikke findes.
function oss_accounts($conn): array {
    $sales = (int)setting('conf_acc_oss_sales', 0);
    $vat   = (int)setting('conf_acc_oss_vat', 0);
    if ($sales <= 0 || $vat <= 0 || !tc_account_ok($conn, $sales) || !tc_account_ok($conn, $vat)) {
        throw new Exception(lang('@The invoice is an EU-OSS sale, but the OSS sales and OSS VAT accounts are not set - choose them under Company Settings -> Automatic posting.'));
    }
    return [$sales, $vat];
}

// Afviser en OSS-/IOSS-faktura med en momssats, modtagerlandet ikke har (typisk den hjemlige sats ved en fejl)
function oss_check_lines($conn, int $inv_id, string $cc, bool $ioss = false): void {
    $res = DB::query($conn, "SELECT DISTINCT line_vat_rate FROM invoice_lines WHERE inv_id = " . (int)$inv_id);
    while ($res && ($r = DB::fetch_row($res))) {
        if (!oss_rate_valid($cc, (float)$r[0])) {
            throw new Exception(sprintf($ioss ? lang('@The invoice is an IOSS sale to a consumer in %s, but a line has %s %% VAT. Use the VAT rate of %s - the standard rate is %s %%.')
                                              : lang('@The customer is a private customer in %s (EU-OSS), but a line has %s %% VAT. Use the VAT rate of %s - the standard rate is %s %%. Is it a service taxed in your own country (e.g. hours)? Then choose "Own country" under EU-OSS on the invoice.'),
                $cc, fmt_pct((float)$r[0]), $cc, fmt_pct((float)oss_std_rate($cc))));
        }
    }
}

// OSS-angivelsen (med $col = 'inv_ioss' IOSS-angivelsen) for perioden: pr. land og sats nettobeløb og moms i regnskabets
// valuta (fakturaer minus kreditnotaer, efter fakturadatoen). [['country', 'rate', 'net', 'vat', 'count'], ...]
// Med $split_corrections: en kreditnota til en faktura fra en TIDLIGERE periode er ikke periodens salg, men en
// rettelse af den periode (forordning 904/2010 art. 61, 282/2011 art. 61) - den står så i oss_report_corrections().
function oss_report_rows($conn, string $ps, string $pe, string $col = 'inv_oss', bool $split_corrections = false): array {
    return oss_report_collect($conn, $ps, $pe, $col)[$split_corrections ? 'rows' : 'all'];
}

// Rettelser af tidligere perioder: [['country', 'orig_date' (fakturaens dato), 'vat' (negativ), 'net'], ...] pr. land og
// oprindelig fakturadato - siden grupperer dem pr. periode
function oss_report_corrections($conn, string $ps, string $pe, string $col = 'inv_oss'): array {
    return oss_report_collect($conn, $ps, $pe, $col)['corrections'];
}

function oss_report_collect($conn, string $ps, string $pe, string $col): array {
    $out = ['all' => [], 'rows' => [], 'corrections' => []];
    if (!in_array($col, ['inv_oss', 'inv_ioss'], true) || !db_has_column($conn, 'invoices', $col)) return $out;
    $disc = invoice_discount_sql($conn, 'il.');
    $res = DB::prepare_and_execute($conn, "SELECT i.inv_id, i.$col AS cc, il.line_vat_rate AS rate,
                    SUM(il.quantity * il.price_each $disc * COALESCE(NULLIF(i.exch_rate, 0), 1)) AS net,
                    (SELECT o.inv_date FROM invoices o WHERE o.inv_id = i.credit_ref) AS orig_date
             FROM invoices i JOIN invoice_lines il ON il.inv_id = i.inv_id
             WHERE i.$col IS NOT NULL AND i.$col <> '' AND LOWER(i.inv_status) <> 'draft' AND i.inv_date BETWEEN ? AND ?
             GROUP BY i.inv_id, i.$col, il.line_vat_rate, i.credit_ref", [$ps, $pe]);
    $add = function (array &$list, string $k, array $init, float $net, float $rate) {
        if (!isset($list[$k])) $list[$k] = $init + ['net' => 0.0, 'vat' => 0.0, 'count' => 0];
        $list[$k]['net'] = round($list[$k]['net'] + $net, 2);
        $list[$k]['vat'] = round($list[$k]['vat'] + round($net * $rate / 100, 2), 2);
        $list[$k]['count']++;
    };
    while ($res && ($r = DB::fetch_assoc($res))) {
        $net  = round((float)$r['net'], 2);
        $rate = (float)$r['rate'];
        $k    = $r['cc'] . '|' . $rate;
        $add($out['all'], $k, ['country' => $r['cc'], 'rate' => $rate], $net, $rate);
        $od = substr((string)($r['orig_date'] ?? ''), 0, 10);
        if ($od !== '' && $od < $ps) $add($out['corrections'], $r['cc'] . '|' . $od . '|' . $rate, ['country' => $r['cc'], 'orig_date' => $od, 'rate' => $rate], $net, $rate);
        else $add($out['rows'], $k, ['country' => $r['cc'], 'rate' => $rate], $net, $rate);
    }
    foreach ($out as &$l) { ksort($l);  $l = array_values($l); }
    unset($l);
    return $out;
}
// Rettelserne grupperet pr. land og oprindelig periode ($period_of: dato -> [nøgle, etiket, periodens sidste dag]),
// med EUR efter kursen på den oprindelige periodes sidste dag. [['country', 'period', 'net', 'vat', 'vat_eur'], ...]
function oss_corrections_by_period(array $corr, callable $period_of, string $cur): array {
    $out = [];
    foreach ($corr as $c) {
        [$key, $label, $end] = $period_of($c['orig_date']);
        $k = $c['country'] . '|' . $key;
        $out[$k] ??= ['country' => $c['country'], 'period' => $label, 'end' => $end, 'net' => 0.0, 'vat' => 0.0];
        $out[$k]['net'] = round($out[$k]['net'] + $c['net'], 2);
        $out[$k]['vat'] = round($out[$k]['vat'] + $c['vat'], 2);
    }
    ksort($out);
    foreach ($out as &$o) { $r = oss_ecb_rate($o['end'], $cur);  $o['vat_eur'] = $r ? round($o['vat'] / $r, 2) : null; }
    unset($o);
    return array_values($out);
}

// Tabellen med rettelser (oss_report.php / ioss_report.php)
function oss_corrections_html(array $rows, string $cur, array $cc_names): string {
    if (!$rows) return '';
    $th = 'style="padding:8px; text-align:right; border-bottom:2px solid var(--border-color);"';
    $tl = 'style="padding:8px; text-align:left; border-bottom:2px solid var(--border-color);"';
    $td = 'style="padding:6px 8px; text-align:right; border-bottom:1px solid var(--border-color); white-space:nowrap;"';
    $tdl = 'style="padding:6px 8px; border-bottom:1px solid var(--border-color);"';
    $h = '<h4 style="margin:18px 0 6px;">' . lang('@Corrections of earlier periods') . '</h4>'
       . '<p style="font-size:0.9em; color:var(--text-muted); margin:0 0 8px;">' . lang('@Credit notes for invoices from an earlier period are not part of the sales of this period. Enter them in the corrections section of the return, per country and original period.') . '</p>'
       . "<table style=\"width:100%; border-collapse:collapse;\"><tr><th $tl>" . lang('@Country') . "</th><th $tl>" . lang('@Period') . "</th><th $th>" . lang('@VAT') . " $cur</th>"
       . ($cur !== 'EUR' ? "<th $th>" . lang('@VAT') . ' EUR</th>' : '') . '</tr>';
    foreach ($rows as $r) {
        $h .= "<tr><td $tdl>" . htmlspecialchars($r['country']) . ' - ' . htmlspecialchars(lang($cc_names[$r['country']] ?? '@' . $r['country'])) . "</td><td $tdl>" . htmlspecialchars($r['period']) . '</td>'
            . "<td $td>" . fmt_dk($r['vat']) . '</td>' . ($cur !== 'EUR' ? "<td $td>" . ($r['vat_eur'] !== null ? fmt_dk($r['vat_eur']) : '-') . '</td>' : '') . '</tr>';
    }
    return $h . '</table>';
}

// Salg til private i andre EU-lande i et kalenderår (regnskabets valuta) - til tærsklen på 10.000 EUR.
// Medregner også salg før registreringen (kunde i andet EU-land uden momsnummer).
function oss_b2c_eu_sales($conn, int $year): float {
    if (!db_has_column($conn, 'customers', 'cust_country')) return 0.0;
    $home = strtoupper((string)tc_country_pack('iso', 'DK'));
    $eu   = implode(',', array_map(fn($c) => "'$c'", array_diff(TC_EU_COUNTRIES, [$home])));
    $disc = invoice_discount_sql($conn, 'il.');
    $r = DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(il.quantity * il.price_each $disc * COALESCE(NULLIF(i.exch_rate, 0), 1)), 0)
             FROM invoices i JOIN invoice_lines il ON il.inv_id = i.inv_id JOIN customers c ON c.cust_id = i.cust_id
             WHERE LOWER(i.inv_status) <> 'draft' AND i.inv_date BETWEEN '$year-01-01' AND '$year-12-31'
               AND UPPER(c.cust_country) IN ($eu) AND (c.cust_vat_id IS NULL OR c.cust_vat_id = '')"
             . (db_has_column($conn, 'invoices', 'inv_ioss') ? " AND (i.inv_ioss IS NULL OR i.inv_ioss = '')" : '')      // (IOSS-salg sendes fra et land uden for EU - tæller ikke med)
             . (oss_off_ready($conn) ? " AND COALESCE(i.inv_oss_off, 0) = 0" : '')));                                   // (heller ikke ydelser beskattet i eget land)
    return round((float)($r[0] ?? 0), 2);
}

// ECB's kurs for 1 EUR i $cur på datoen (OSS-/IOSS-angivelsen omregnes med kursen på periodens sidste dag). Udgiver
// ECB ingen kurs den dag (weekend/helligdag), gælder den NÆSTE udgivelse (momssystemdirektivet art. 366) - ikke den
// forrige. Henter fra Frankfurter (ECB's referencekurser); en dato uden senere udgivelse endnu (i dag) får den seneste.
// null = kunne ikke hentes.
function oss_ecb_rate(string $date, string $cur): ?float {
    $cur = strtoupper($cur);
    if ($cur === 'EUR') return 1.0;
    if (!tc_is_date($date) || !preg_match('/^[A-Z]{3}$/', $cur)) return null;
    if (getenv('TINYCASH_TEST_DB') !== false) return null;      // (testkørsler kalder ikke nettet - kursen indtastes)
    static $memo = [];
    if (array_key_exists("$date|$cur", $memo)) return $memo["$date|$cur"];
    $memo["$date|$cur"] = null;
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $to  = date('Y-m-d', strtotime($date . ' +7 days'));
    $raw = @file_get_contents("https://api.frankfurter.dev/v1/$date..$to?from=EUR&to=$cur", false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    $v = null;
    foreach ((array)($j['rates'] ?? []) as $d => $r) {
        if ((string)$d >= $date && is_numeric($r[$cur] ?? null)) { $v = $r[$cur];  break; }
    }
    if ($v === null) {      // (ingen udgivelse på eller efter datoen endnu: den seneste)
        $raw = @file_get_contents("https://api.frankfurter.dev/v1/$date?from=EUR&to=$cur", false, $ctx);
        $j = $raw ? json_decode($raw, true) : null;
        $v = $j['rates'][$cur] ?? null;
    }
    return $memo["$date|$cur"] = is_numeric($v) && (float)$v > 0 ? (float)$v : null;
}

// --- IOSS (importordningen) ------------------------------------------------------------------------------------------
// Fjernsalg af varer, der sendes fra et land UDEN for EU til en forbruger i EU, i forsendelser på højst 150 EUR: sælgeren
// opkræver modtagerlandets moms ved salget, og indførslen er momsfri ved grænsen. Afsendelsesstedet - ikke kunden - afgør
// det, så fakturaen markeres (kladde: inv_ioss = 'Y'; bogført: modtagerlandet). Angivelsen er månedlig (ioss_report.php).
// IOSS-nummeret (IM...) gives til fragtfirmaet; det står ikke på fakturaen.

const IOSS_LIMIT_EUR = 150;      // forsendelsens egenværdi: varerne uden moms

function ioss_ready($conn): bool {
    return db_has_column($conn, 'invoices', 'inv_ioss');
}

function ioss_available(): bool {
    return (bool)tc_country_pack('legal.ioss', false);
}

// IM + 3 cifre for registreringslandet + 7 cifre, fx IM2080000123
function ioss_number_ok(string $n): bool {
    return (bool)preg_match('/^IM\d{10}$/', $n);
}

function ioss_registered(): bool {
    return ioss_available() && ioss_number_ok((string)setting('ioss_number', ''));
}

// Modtagerlandet hvis et salg til kunden kan være IOSS: en forbruger (intet momsnummer) i et EU-land - også hjemlandet,
// når det er i EU (tomt land = hjemlandet). Ellers null.
function ioss_dest_for(string $cc, string $vat_id): ?string {
    $cc = strtoupper(trim($cc));
    if ($cc === '') $cc = strtoupper((string)tc_country_pack('iso', 'DK'));
    if (!in_array($cc, TC_EU_COUNTRIES, true) || trim($vat_id) !== '') return null;
    return $cc;
}

function ioss_dest_for_customer($conn, int $cust_id): ?string {
    if (!db_has_column($conn, 'customers', 'cust_country')) return ioss_dest_for('', '');
    $c = DB::fetch_assoc(DB::query($conn, "SELECT cust_country, cust_vat_id FROM customers WHERE cust_id = " . (int)$cust_id));
    return $c ? ioss_dest_for((string)($c['cust_country'] ?? ''), (string)($c['cust_vat_id'] ?? '')) : null;
}

// Kunder i et andet EU-land, hvis sats skifter når fakturaen er IOSS: [kunde => [land, standardsats]] (assets/tc-oss.js)
function ioss_customer_map($conn): array {
    $out = [];
    if (!ioss_registered() || !ioss_ready($conn) || !db_has_column($conn, 'customers', 'cust_country')) return $out;
    $home = strtoupper((string)tc_country_pack('iso', 'DK'));
    $res = DB::query($conn, "SELECT cust_id, cust_country, cust_vat_id FROM customers WHERE cust_country IS NOT NULL AND cust_country <> ''");
    while ($res && ($c = DB::fetch_assoc($res))) {
        $cc = ioss_dest_for((string)$c['cust_country'], (string)($c['cust_vat_id'] ?? ''));
        if ($cc !== null && $cc !== $home && oss_std_rate($cc) !== null) $out[(int)$c['cust_id']] = [$cc, oss_std_rate($cc)];
    }
    return $out;
}

// IOSS-konti fra Firmaindstillinger: [salg, moms]. Kaster en Exception hvis de ikke er sat eller ikke findes.
function ioss_accounts($conn): array {
    $sales = (int)setting('conf_acc_ioss_sales', 0);
    $vat   = (int)setting('conf_acc_ioss_vat', 0);
    if ($sales <= 0 || $vat <= 0 || !tc_account_ok($conn, $sales) || !tc_account_ok($conn, $vat)) {
        throw new Exception(lang('@The invoice is an IOSS sale, but the IOSS sales and IOSS VAT accounts are not set - choose them under Company Settings -> Automatic posting.'));
    }
    return [$sales, $vat];
}

// En fragtlinje: ingen vare og et fragtord i teksten (webbutikkernes fragtlinjer, "Fragt GLS", "Shipping", "Frakt" ...)
function ioss_is_shipping_line(array $l): bool {
    return (int)($l['prod_id'] ?? 0) <= 0
        && (bool)preg_match('/fragt|forsendelse|levering|porto|shipping|delivery|postage|frakt|leverans|toimitus|rahti|postikulu|versand|sending/iu', (string)($l['line_text'] ?? ''));
}

// Forsendelsens egenværdi i EUR - varerne uden moms, uden fragt der står for sig (den hører ikke til egenværdien) - til
// grænsen på 150 EUR. null = kursen kunne ikke hentes (grænsen tjekkes da ikke).
function ioss_value_eur($conn, int $inv_id): ?float {
    $inv = DB::fetch_assoc(DB::query($conn, "SELECT inv_date, currency, orig_currency, exch_rate FROM invoices WHERE inv_id = " . (int)$inv_id));
    if (!$inv) return null;
    $disc = invoice_discount_sql($conn, '');
    $own = 0.0;                                                              // (i fakturaens valuta)
    $res = DB::query($conn, "SELECT prod_id, line_text, quantity * price_each $disc AS net FROM invoice_lines WHERE inv_id = " . (int)$inv_id);
    while ($res && ($l = DB::fetch_assoc($res))) if (!ioss_is_shipping_line($l)) $own += (float)$l['net'];
    if (strtoupper((string)($inv['orig_currency'] ?: $inv['currency'])) === 'EUR') return round($own, 2);
    $book = $own * ((float)$inv['exch_rate'] > 0 ? (float)$inv['exch_rate'] : 1);
    $base = strtoupper((string)setting('currency', 'DKK'));
    if ($base === 'EUR') return round($book, 2);
    $rate = oss_ecb_rate(substr((string)$inv['inv_date'], 0, 10), $base);
    return $rate ? round($book / $rate, 2) : null;
}

// Tjekker en kladde markeret som IOSS og giver modtagerlandet - ellers en Exception, der siger hvad der er galt
function ioss_check_invoice($conn, int $inv_id, int $cust_id): string {
    if (!ioss_registered()) throw new Exception(lang('@The invoice is marked as an IOSS sale, but the company has no IOSS number - enter it under Company Settings, or remove the IOSS mark.'));
    $cc = ioss_dest_for_customer($conn, $cust_id);
    if ($cc === null) throw new Exception(lang('@IOSS only covers sales to consumers in the EU - the customer has a VAT number or is outside the EU. Remove the IOSS mark.'));
    $eur = ioss_value_eur($conn, $inv_id);
    if ($eur !== null && $eur > IOSS_LIMIT_EUR + 0.004) {
        throw new Exception(sprintf(lang('@The goods on the invoice are worth %s EUR without VAT. IOSS only covers consignments up to 150 EUR - above that, the VAT is paid at import. Remove the IOSS mark.'), fmt_dk($eur)));
    }
    oss_check_lines($conn, $inv_id, $cc, true);
    return $cc;
}
