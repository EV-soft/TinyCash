<?php # /inc/vat_return.inc.php v:1.3.1 d:2026-09-15 i:evs
// Momsangivelsens felter for perioden, efter landepakkens "vat_return" (json-data/countries/<land>.json).
// Regler: setting_acc = saldoen på en momskonto fra Firmaindstillinger (+ dens forgængere efter et kontoplanskift);
// rc_vat = saldoen på de konti, som momskoder med omvendt betalingspligt bogfører den skyldige moms på;
// net = nettobeløbet på konti hvis momskode har en af typerne (rubrik A/B/C), evt. kun visse satser ("rates");
// vat_of_net = samme nettobeløb x kodens sats. "payable" = fortegn i momstilsvaret.

function vat_return_fields($conn, string $ps, string $pe): array {
    $ps = DB::escape($conn, $ps);  $pe = DB::escape($conn, $pe);
    $has_type = db_has_column($conn, 'vat_codes', 'vat_type');
    $sum_accs = function (array $accs, int $sign, bool $pl) use ($conn, $ps, $pe): float {
        $accs = array_values(array_unique(array_filter(array_map('intval', $accs))));
        if (!$accs) return 0.0;
        $r = DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(l.amount), 0) FROM ledger l JOIN journal j ON l.jou_id = j.jou_id
             WHERE l.acc_id IN (" . implode(',', $accs) . ") AND j.jou_date BETWEEN '$ps' AND '$pe'"
             . ($pl ? " AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')" : '')));
        return round($sign * (float)($r[0] ?? 0), 2);
    };
    $types_in = fn(array $types) => implode(',', array_map(fn($t) => "'" . DB::escape($conn, (string)$t) . "'", $types));

    $out = [];
    $payable = 0.0;
    foreach ((array)tc_country_pack('vat_return', []) as $f) {
        if (!is_array($f) || empty($f['box'])) continue;
        $sign  = (int)($f['sign'] ?? 1);
        $types = (array)($f['types'] ?? []);
        $value = 0.0;
        switch ($f['rule'] ?? '') {
            case 'setting_acc':
                $acc = (int)setting((string)$f['setting'], 0);
                $value = $acc > 0 ? $sum_accs(array_map('intval', explode(',', tc_vat_acc_list($conn, $acc))), $sign, false) : 0.0;
                break;
            case 'rc_vat':
                if ($has_type && $types) {
                    $accs = [];
                    $res = DB::query($conn, "SELECT DISTINCT vat_account FROM vat_codes WHERE vat_type IN (" . $types_in($types) . ") AND vat_account IS NOT NULL");
                    while ($res && ($r = DB::fetch_row($res))) $accs[] = (int)$r[0];
                    // Deler kontoen moms med koder fra andre felter (fx Finlands 2939 for EU-varer, EU-ydelser og import),
                    // ville saldoen tælle i hvert felt - så regnes feltet af grundlaget × kodens sats i stedet
                    $others = [];
                    if ($accs) {
                        $res = DB::query($conn, "SELECT COUNT(*) FROM vat_codes WHERE vat_account IN (" . implode(',', array_map('intval', $accs)) . ")
                                                 AND vat_type IN (" . $types_in(TC_VAT_RC_TYPES) . ") AND vat_type NOT IN (" . $types_in($types) . ")");
                        $others = $res ? DB::fetch_row($res) : [0];
                    }
                    if ((int)($others[0] ?? 0) === 0) { $value = $sum_accs($accs, $sign, false);  break; }
                    $res = DB::query($conn, "SELECT a.acc_id, v.vat_rate FROM accounts a JOIN vat_codes v ON v.vat_id = a.vat_code WHERE v.vat_type IN (" . $types_in($types) . ")");
                    $by_rate = [];
                    while ($res && ($r = DB::fetch_row($res))) $by_rate[(string)(float)$r[1]][] = (int)$r[0];
                    foreach ($by_rate as $rate => $base_accs) $value += round($sum_accs($base_accs, 1, true) * (float)$rate / 100, 2);
                    $value = round($value, 2);
                }
                break;
            case 'net':
            case 'vat_of_net':
                // "rates": kun koder med disse satser (fx Sveriges rutor 05/10/11/12 pr. sats).
                // vat_of_net = nettobeløbet x kodens sats - til lande hvor flere satser deler én momskonto.
                if ($has_type && $types) {
                    $rates = array_map('floatval', (array)($f['rates'] ?? []));
                    $by_rate = [];
                    $res = DB::query($conn, "SELECT a.acc_id, v.vat_rate FROM accounts a JOIN vat_codes v ON v.vat_id = a.vat_code WHERE v.vat_type IN (" . $types_in($types) . ")");
                    while ($res && ($r = DB::fetch_row($res))) {
                        if ($rates && !in_array((float)$r[1], $rates, true)) continue;
                        $by_rate[(string)(float)$r[1]][] = (int)$r[0];
                    }
                    foreach ($by_rate as $rate => $accs) {
                        $net = $sum_accs($accs, $sign, true);
                        $value += $f['rule'] === 'vat_of_net' ? round($net * (float)$rate / 100, 2) : $net;
                    }
                    $value = round($value, 2);
                }
                break;
        }
        if (isset($f['payable'])) $payable += (int)$f['payable'] * $value;
        $out[] = ['box' => (string)$f['box'], 'label' => (string)($f['label'] ?? $f['box']), 'value' => $value, 'payable' => isset($f['payable'])];
    }
    return ['fields' => $out, 'payable' => round($payable, 2), 'unplaced' => vat_return_unplaced_sales($conn, $ps, $pe)];
}

// Salg uden moms til kunder i et andet land, bogført på en konto uden EU-/eksport-momskode - det kommer ikke med i
// rubrik B/C. Returnerer [['invoice_no', 'cust_name', 'country', 'net'], ...] (beløb i regnskabets valuta).
function vat_return_unplaced_sales($conn, string $ps, string $pe): array {
    if (!db_has_column($conn, 'customers', 'cust_country') || !db_has_column($conn, 'vat_codes', 'vat_type')) return [];
    $home = strtoupper((string)tc_country_pack('iso', 'DK'));
    $acc_sales = (int)setting('conf_acc_sales', 1000);
    $placed = ['eu_goods_sale', 'eu_goods_sale_nonlisted', 'eu_services_sale', 'export', 'reverse_domestic'];
    $disc = invoice_discount_sql($conn, 'il.');
    $res = DB::query($conn, "SELECT i.invoice_no, c.cust_name, c.cust_country, il.quantity * il.price_each $disc * COALESCE(NULLIF(i.exch_rate, 0), 1) AS net,
                                    CASE WHEN a.acc_type = 'income' THEN a.acc_id ELSE $acc_sales END AS acc
                             FROM invoices i JOIN customers c ON c.cust_id = i.cust_id JOIN invoice_lines il ON il.inv_id = i.inv_id
                             LEFT JOIN products p ON p.prod_id = il.prod_id AND il.prod_id > 0 LEFT JOIN accounts a ON a.acc_id = p.acc_id
                             WHERE i.inv_date BETWEEN '$ps' AND '$pe' AND LOWER(i.inv_status) != 'draft'
                               AND c.cust_country IS NOT NULL AND c.cust_country <> '' AND UPPER(c.cust_country) <> '$home'
                               AND COALESCE(il.line_vat_rate, 0) = 0"
                             . (db_has_column($conn, 'invoices', 'inv_oss') ? " AND (i.inv_oss IS NULL OR i.inv_oss = '')" : '')      // (OSS-salg står på OSS-angivelsen)
                             . (db_has_column($conn, 'invoices', 'inv_ioss') ? " AND (i.inv_ioss IS NULL OR i.inv_ioss = '')" : ''));  // (og IOSS-salg på IOSS-angivelsen)
    $types = [];
    $out = [];
    while ($res && ($r = DB::fetch_assoc($res))) {
        $acc = (int)$r['acc'];
        if (!array_key_exists($acc, $types)) {
            $t = DB::fetch_row(DB::query($conn, "SELECT v.vat_type FROM accounts a JOIN vat_codes v ON v.vat_id = a.vat_code WHERE a.acc_id = $acc"));
            $types[$acc] = $t[0] ?? null;
        }
        if (in_array($types[$acc], $placed, true)) continue;
        $k = $r['invoice_no'];
        if (!isset($out[$k])) $out[$k] = ['invoice_no' => $r['invoice_no'], 'cust_name' => $r['cust_name'], 'country' => strtoupper((string)$r['cust_country']), 'net' => 0.0, 'acc' => $acc];
        $out[$k]['net'] = round($out[$k]['net'] + (float)$r['net'], 2);
    }
    return array_values(array_filter($out, fn($x) => abs($x['net']) >= 0.005));
}

// Listeindberetning ("EU-salg uden moms"): salg til virksomheder i andre EU-lande pr. købers momsnummer, delt i varer
// og ydelser - de samme fakturalinjer som rubrik B (konti med momskodetypen eu_goods_sale / eu_services_sale; varer
// med eu_goods_sale_nonlisted er i rubrik B, men ikke på listen). Kontoen findes som ved bogføringen
// (invoice_sales_split): varens indtægtskonto, ellers standard-salgskontoen. Kreditnotaer trækker fra.
// ['rows' => [['country', 'vat_id', 'cust_name', 'goods', 'services', 'invoices']], 'missing' => [fakturaer uden
//  momsnummer], 'goods' => sum, 'services' => sum]. Beløb i regnskabets valuta.
function vat_eu_sales_list($conn, string $ps, string $pe): array {
    $out = ['rows' => [], 'missing' => [], 'goods' => 0.0, 'services' => 0.0];
    if (!db_has_column($conn, 'vat_codes', 'vat_type') || !db_has_column($conn, 'customers', 'cust_vat_id')) return $out;
    $ps = DB::escape($conn, $ps);  $pe = DB::escape($conn, $pe);
    $acc_sales = (int)setting('conf_acc_sales', 1000);
    $types = [];
    $res = DB::query($conn, "SELECT a.acc_id, v.vat_type FROM accounts a JOIN vat_codes v ON v.vat_id = a.vat_code
                             WHERE v.vat_type IN ('eu_goods_sale', 'eu_services_sale')");
    while ($res && ($r = DB::fetch_row($res))) $types[(int)$r[0]] = $r[1] === 'eu_goods_sale' ? 'goods' : 'services';
    if (!$types) return $out;
    $disc = invoice_discount_sql($conn, 'il.');
    $res = DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.credit_note_no, i.credit_ref, c.cust_id, c.cust_name, c.cust_country, c.cust_vat_id,
                                    il.quantity * il.price_each $disc * COALESCE(NULLIF(i.exch_rate, 0), 1) AS net,
                                    CASE WHEN a.acc_type = 'income' THEN a.acc_id ELSE $acc_sales END AS acc
                             FROM invoices i JOIN customers c ON c.cust_id = i.cust_id JOIN invoice_lines il ON il.inv_id = i.inv_id
                             LEFT JOIN products p ON p.prod_id = il.prod_id AND il.prod_id > 0 LEFT JOIN accounts a ON a.acc_id = p.acc_id
                             WHERE i.inv_date BETWEEN '$ps' AND '$pe' AND LOWER(i.inv_status) != 'draft' AND COALESCE(il.line_vat_rate, 0) = 0");
    $home = strtoupper((string)tc_country_pack('iso', 'DK'));
    while ($res && ($r = DB::fetch_assoc($res))) {
        // (kun salg uden moms til en køber i et andet land - en linje med moms bogføres på standard-salgskontoen, og en
        // indenlandsk køber med momsnummer hører ikke på listen)
        if (strtoupper(trim((string)($r['cust_country'] ?? ''))) === '' || strtoupper((string)$r['cust_country']) === $home) continue;
        $kind = $types[(int)$r['acc']] ?? null;
        if ($kind === null) continue;
        $net = round((float)$r['net'], 2);
        $vat = (string)(tc_vat_id_clean($r['cust_vat_id'] ?? '') ?? '');
        if ($vat === '') {
            $doc = !empty($r['credit_ref']) ? 'KN-' . $r['credit_note_no'] : (string)$r['invoice_no'];
            $out['missing'][$doc] = ['doc' => $doc, 'cust_name' => (string)$r['cust_name'], 'cust_id' => (int)$r['cust_id'], 'net' => round(($out['missing'][$doc]['net'] ?? 0) + $net, 2)];
            continue;
        }
        // (momsnummeret uden landekode, som på listen; landet er momsnummerets - Grækenland hedder EL der)
        $cc = substr($vat, 0, 2);
        $row = &$out['rows'][$vat];
        $row ??= ['country' => $cc, 'vat_id' => substr($vat, 2), 'cust_name' => (string)$r['cust_name'], 'goods' => 0.0, 'services' => 0.0, 'docs' => []];
        $row[$kind] = round($row[$kind] + $net, 2);
        $row['docs'][(int)$r['inv_id']] = true;
        $out[$kind] = round($out[$kind] + $net, 2);
        unset($row);
    }
    foreach ($out['rows'] as &$row) { $row['invoices'] = count($row['docs']);  unset($row['docs']); }
    unset($row);
    $out['rows'] = array_filter($out['rows'], fn($r) => abs($r['goods']) >= 0.005 || abs($r['services']) >= 0.005);
    ksort($out['rows']);
    $out['rows'] = array_values($out['rows']);
    $out['missing'] = array_values($out['missing']);
    return $out;
}

// Momsperiodens typer. "bimonth" = to måneder (Norges mva-termin og Islands VSK-tímabil: jan-feb, mar-apr ...)
const VAT_PERIOD_TYPES = ['year' => ['@Full Year', 1, 12], 'half' => ['@Half-year', 2, 6], 'quarter' => ['@Quarter', 4, 3],
                          'bimonth' => ['@2-month period', 6, 2], 'month' => ['@Month', 12, 1]];

// Landets almindelige momsperiode (landepakkens vat.period), ellers hele året som hidtil
function vat_period_default(): string {
    $p = (string)tc_country_pack('vat.period', 'year');
    return isset(VAT_PERIOD_TYPES[$p]) ? $p : 'year';
}

// Den senest afsluttede periode af typen - den der skal angives: [år, nummer]
function vat_period_last_closed(string $type, ?string $today = null): array {
    if (!isset(VAT_PERIOD_TYPES[$type])) $type = vat_period_default();
    $t = strtotime($today ?? date('Y-m-d'));
    if ($type === 'year') return [(int)date('Y', $t), 1];      // (hele året er en oversigt - indeværende år som hidtil)
    $months = VAT_PERIOD_TYPES[$type][2];
    $y = (int)date('Y', $t);  $cur = intdiv((int)date('n', $t) - 1, $months) + 1;      // (den igangværende periode)
    return $cur > 1 ? [$y, $cur - 1] : [$y - 1, VAT_PERIOD_TYPES[$type][1]];
}

// Periodens grænser: [start, slut, etiket, type, nummer]. Ukendt type = landets standard; nummeret holdes inden for året.
function vat_period_bounds(int $year, string $type, int $num): array {
    if (!isset(VAT_PERIOD_TYPES[$type])) $type = vat_period_default();
    [$lbl, $count, $months] = VAT_PERIOD_TYPES[$type];
    $num = max(1, min($count, $num));
    $m1 = ($num - 1) * $months + 1;
    $ps = sprintf('%04d-%02d-01', $year, $m1);
    $pe = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $m1 + $months - 1)));
    switch ($type) {
        case 'year':    $label = (string)$year;  break;
        case 'month':   $label = tc_month_name($num) . ' ' . $year;  break;
        case 'bimonth': $label = lang($lbl) . ' ' . $num . ' (' . tc_month_name($m1) . ' - ' . tc_month_name($m1 + 1) . ') ' . $year;  break;
        default:        $label = lang($lbl) . ' ' . $num . ' ' . $year;
    }
    return [$ps, $pe, $label, $type, $num];
}
