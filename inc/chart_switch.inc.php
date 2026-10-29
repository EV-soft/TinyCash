<?php # /inc/chart_switch.inc.php v:1.3.1 d:2026-09-15 i:evs
# Skift til Erhvervsstyrelsens standardkontoplan (bruger-anmodet: "kan man til enhver tid skifte til
# standardkontoplanen" -> "ja, byg det"). Brugt af chart_switch.php.
#
# Bogførte posteringer kan ikke flyttes (bogføringsloven / append-only-triggerne), og et kontonummer kan ikke
# ændres. Et skift er derfor et skift FREMOVER, på en dato:
#   1. hver af dine konti får en standardkonto som afløser (forslag: indstillingens rolle -> fast standardnummer,
#      ellers kontoens SAF-T-tilknytning, ellers navnet) - eller beholdes
#   2. balancekontiene (aktiver, bank, gæld, moms, egenkapital) får deres saldo pr. datoen overført til
#      afløseren med ÉN bilagsført postering (trans_type 'chart_switch') - resultatkonti overføres ikke (de
#      nulstilles af årsafslutningen; skiftes der midt i et år, står årets tal på både gamle og nye konti)
#   3. alt der peger på en gammel konto, peger fremover på afløseren: Firmaindstillingernes bogføringskonti,
#      momskoderne, produkter, webbutikker, bankregler og -forbindelser, anlæg, faste udgifter, budgetter fra
#      skifteåret, åbne regnskabsårs egenkapitalkonto og ubogførte kladder / banklinjer
#   4. afløserne tændes (og arver momssatsen, hvis de ingen har); de gamle konti slukkes og får afløseren som
#      SAF-T-standardkonto - deres historik ses stadig i rapporterne
require_once __DIR__ . '/accounts.inc.php';

// Fast afløser for en indstillings rolle (standardkontoplanen 2026)
const CS_ROLE_TARGETS = [
    'conf_acc_bank' => 6480, 'conf_acc_debitor' => 6190, 'conf_acc_creditor' => 7440, 'conf_acc_sales' => 1010,
    'conf_acc_vat' => 7680, 'conf_acc_purchase_vat' => 7740, 'conf_acc_fx' => 3610, 'conf_acc_bad_debt' => 2280,
];
const CS_BALANCE_TYPES = ['asset', 'bank', 'liability', 'vat', 'equity'];
// Programmets egne standardværdier, når en indstilling ikke er gemt (samme ?? -værdier som posteringskoden)
const CS_ROLE_DEFAULTS = ['conf_acc_bank' => 5000, 'conf_acc_debitor' => 8100, 'conf_acc_creditor' => 4000, 'conf_acc_sales' => 1000,
                          'conf_acc_vat' => 6900, 'conf_acc_purchase_vat' => 6910, 'conf_acc_fx' => 7200, 'conf_acc_bad_debt' => 7100];

// Den konto en rolle reelt bogfører på: [rolle => kontonr]
function cs_role_accounts($conn): array {
    $s = get_settings($conn);
    $out = [];
    foreach (CS_ROLE_DEFAULTS as $k => $def) $out[$k] = (int)(($s[$k] ?? '') !== '' ? $s[$k] : $def);
    return $out;
}

// Kontotypens familie - en afløser skal være i samme familie (en udgift kan ikke afløses af en bankkonto)
function cs_family(string $type): string {
    return ['asset' => 'asset', 'bank' => 'asset', 'liability' => 'liability', 'vat' => 'liability', 'equity' => 'equity',
            'income' => 'income', 'expense' => 'expense'][$type] ?? $type;
}

// Standardkonti der er importeret under et ERSTATNINGSNUMMER, fordi standardnummeret var optaget af en af dine konti
// med posteringer (konfliktløsningen 'alt'): [kontonr => standardnr]. Gemt i settings - std_ref_id alene kan ikke
// skelne dem fra en af dine egne konti der blot er knyttet til standarden.
const CS_ALT_SETTING = 'chart_std_alt';
function cs_alt_map($conn): array {
    $r = DB::fetch_assoc(DB::query($conn, "SELECT setting_value FROM settings WHERE setting_key = '" . CS_ALT_SETTING . "'"));
    $out = [];
    foreach ((array)json_decode((string)($r['setting_value'] ?? ''), true) as $k => $v) $out[(int)$k] = (int)$v;
    return $out;
}

// Standardkonti der findes i kontoplanen (importeret): [nr => række] - også dem under et erstatningsnummer ('std_no')
function cs_std_accounts($conn): array {
    $out = [];
    $alt = cs_alt_map($conn);
    // Har standardnr. N en erstatning, er kontoen PÅ nummer N din egen (også når et skift har knyttet den til N)
    $where = $alt ? '(std_ref_id = acc_id AND acc_id NOT IN (' . implode(',', array_values($alt)) . ')) OR acc_id IN (' . implode(',', array_keys($alt)) . ')' : 'std_ref_id = acc_id';
    $res = DB::query($conn, "SELECT acc_id, acc_name, acc_type, vat_rate, vat_code, std_ref_id" . acc_status_col($conn) . " FROM accounts WHERE $where ORDER BY acc_id");
    if ($res) while ($r = DB::fetch_assoc($res)) { $r['std_no'] = $alt[(int)$r['acc_id']] ?? (int)$r['acc_id'];  $out[(int)$r['acc_id']] = $r; }
    return $out;
}
// Standardkonti der KAN vælges som afløser: de importerede + dem fra filen (json-data/standardkontoplan_2026.json) hvis
// nummer er ledigt - de sidste ('virtual') oprettes først når skiftet udføres (cs_execute), så skiftet ikke kræver at hele
// standardkontoplanen importeres først. Et nummer med posteringer men ingen konto (spøgelseskonto) er ikke ledigt.
function cs_std_available($conn): array {
    $std = cs_std_accounts($conn);
    $taken = [];
    $res = DB::query($conn, "SELECT acc_id FROM accounts");
    while ($r = DB::fetch_row($res)) $taken[(int)$r[0]] = true;
    $res = DB::query($conn, "SELECT DISTINCT acc_id FROM ledger");
    if ($res) while ($r = DB::fetch_row($res)) $taken[(int)$r[0]] = true;
    $alt = array_flip(cs_alt_map($conn));
    foreach (cs_std_doc() as $no => $a) {
        if (isset($taken[$no]) || isset($alt[$no])) continue;
        $std[$no] = ['acc_id' => $no, 'acc_name' => $a['name'], 'acc_type' => $a['type'], 'vat_rate' => $a['vat_rate'] ?? null,
                     'vat_code' => $a['vat_code'] ?? null, 'std_ref_id' => $no, 'acc_status' => 'off', 'std_no' => $no, 'virtual' => true];
    }
    ksort($std);
    return $std;
}

// En standardkonto som række til accounts (slukket; momskoden kun hvis den findes)
function cs_std_row($conn, array $s, int $id, int $ref): array {
    static $vat_ok = null;
    if ($vat_ok === null) {
        $vat_ok = [];
        $res = DB::query($conn, "SELECT vat_id FROM vat_codes");
        if ($res) while ($r = DB::fetch_assoc($res)) $vat_ok[(string)$r['vat_id']] = true;
    }
    $row = ['acc_id' => $id, 'acc_name' => $s['name'], 'acc_type' => $s['type'], 'std_ref_id' => $ref];
    if (acc_status_ready($conn)) $row['acc_status'] = 'off';
    if (isset($s['vat_rate'])) $row['vat_rate'] = (float)$s['vat_rate'];
    if (isset($s['vat_code']) && isset($vat_ok[$s['vat_code']])) $row['vat_code'] = $s['vat_code'];
    return $row;
}

// std_accounts (SAF-T-mappingens mål) fyldes, hvis den ikke allerede er den officielle liste
function cs_ensure_std_table($conn): void {
    $doc = cs_std_doc();
    $n = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM std_accounts"));
    if ((int)($n[0] ?? 0) !== count($doc)) acc_fill_std_table($conn, ['accounts' => array_values($doc)]);
}

// Kontonummeret som standardkonto nr. $no har i DIN kontoplan (erstatningsnummeret, hvis den er importeret under et) - 0 = ikke importeret
function cs_std_at(array $std, int $no): int {
    if (isset($std[$no]) && (int)$std[$no]['std_no'] === $no) return $no;
    foreach ($std as $id => $r) if ((int)$r['std_no'] === $no) return (int)$id;
    return 0;
}

// Dine egne konti (ikke standardkonti): [nr => række + 'balance', 'used', 'roles']
function cs_own_accounts($conn): array {
    $roles = [];
    foreach (cs_role_accounts($conn) as $k => $v) if ($v > 0) $roles[$v][] = $k;
    $used = [];
    $res = DB::query($conn, "SELECT acc_id, COUNT(*) AS n FROM ledger GROUP BY acc_id");
    if ($res) while ($r = DB::fetch_assoc($res)) $used[(int)$r['acc_id']] = (int)$r['n'];
    $out = [];
    $res = DB::query($conn, "SELECT acc_id, acc_name, acc_type, std_ref_id, vat_rate, vat_code" . acc_status_col($conn) . " FROM accounts
                             WHERE " . (($alt = cs_alt_map($conn))
                                 ? '(std_ref_id IS NULL OR std_ref_id <> acc_id OR acc_id IN (' . implode(',', array_values($alt)) . ')) AND acc_id NOT IN (' . implode(',', array_keys($alt)) . ')'
                                 : '(std_ref_id IS NULL OR std_ref_id <> acc_id)') . " ORDER BY acc_id");
    if ($res) while ($r = DB::fetch_assoc($res)) {
        $id = (int)$r['acc_id'];
        $r['postings'] = $used[$id] ?? 0;
        $r['roles']    = $roles[$id] ?? [];
        $r['balance']  = acc_balance($conn, $id);
        $out[$id] = $r;
    }
    // En indstilling der peger på en konto der IKKE findes i kontoplanen (fx standardværdien 5000), men som der
    // bogføres på: med i listen, ellers blev indstillingen ikke skiftet og saldoen ikke overført. Typen følger rollen.
    $role_type = ['conf_acc_bank' => 'bank', 'conf_acc_debitor' => 'asset', 'conf_acc_creditor' => 'liability', 'conf_acc_sales' => 'income',
                  'conf_acc_vat' => 'vat', 'conf_acc_purchase_vat' => 'vat', 'conf_acc_fx' => 'expense', 'conf_acc_bad_debt' => 'expense'];
    foreach ($roles as $id => $rl) {
        if (isset($out[$id]) || DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = $id"))) continue;
        if (($used[$id] ?? 0) === 0) continue;
        $out[$id] = ['acc_id' => $id, 'acc_name' => '⚠ ' . lang('@not in the chart of accounts'), 'acc_type' => $role_type[$rl[0]] ?? 'asset',
                     'std_ref_id' => null, 'vat_rate' => null, 'vat_code' => null, 'acc_status' => 'active', 'postings' => $used[$id],
                     'roles' => $rl, 'balance' => acc_balance($conn, $id), 'ghost' => true];
    }
    ksort($out);
    return $out;
}

// Ord der betyder noget i et kontonavn (små bogstaver, uden fyldord og bøjningsendelse) - til navneforslaget
function cs_words(string $name): array {
    $name = mb_strtolower($name);
    $w = preg_split('/[^a-zæøå0-9]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    $stop = ['og', 'af', 'til', 'for', 'fra', 'med', 'på', 'vedr', 'the', 'and', 'konto', 'kto', 'mv', 'samt', 'andre', 'øvrige', 'diverse', 'den', 'det', 'de'];
    $out = [];
    foreach ($w as $x) {
        if (mb_strlen($x) < 2 || in_array($x, $stop, true)) continue;
        foreach (['erne', 'ene', 'er'] as $end) {
            if (mb_strlen($x) - mb_strlen($end) >= 4 && mb_substr($x, -mb_strlen($end)) === $end) { $x = mb_substr($x, 0, -mb_strlen($end));  break; }
        }
        $out[$x] = true;
    }
    return array_keys($out);
}
// Passer to ord? Ens, eller det ene er en del af det andet (sammensatte ord: "gebyr" i "bankgebyr", "bank" i "bankkonto")
function cs_word_match(string $a, string $b): bool {
    if ($a === $b) return true;
    $short = mb_strlen($a) <= mb_strlen($b) ? $a : $b;  $long = $short === $a ? $b : $a;
    return mb_strlen($short) >= 4 && (mb_strpos($long, $short) === 0 || (mb_strlen($short) >= 5 && mb_strpos($long, $short) !== false));
}

// Forslag til en afløser for én konto - 0 = intet forslag (behold)
function cs_propose(array $acc, array $std): int {
    foreach ($acc['roles'] as $role) {
        $t = cs_std_at($std, CS_ROLE_TARGETS[$role]);
        if ($t) return $t;
    }
    $fam = cs_family((string)$acc['acc_type']);
    $ref = cs_std_at($std, (int)($acc['std_ref_id'] ?? 0));
    if ($ref > 0 && cs_family((string)$std[$ref]['acc_type']) === $fam) return $ref;
    $best = 0;  $best_score = 0.0;
    foreach ($std as $no => $s) {
        if (cs_family((string)$s['acc_type']) !== $fam) continue;
        $score = cs_name_score((string)$acc['acc_name'], (string)$s['acc_name']);
        if ($score > $best_score) { $best_score = $score;  $best = $no; }
    }
    if ($best_score >= 0.2) return $best;
    // En bankkonto med et navn uden fælles ord (fx "Bank - Den Gyldne Skatkiste"): standardplanens bankkonto -
    // en kasse: likvide beholdninger
    if ((string)$acc['acc_type'] === 'bank') {
        $want = cs_std_at($std, preg_match('/kasse|kontant/iu', (string)$acc['acc_name']) ? 6470 : 6480);
        if ($want) return $want;
    }
    return 0;
}

// Hvor godt to kontonavne passer sammen, 0-1. Et helt ord tæller 1, en orddel (sammensat ord) ½ - ellers vandt
// "Salgsrabatter" over "Salg af varer"
function cs_name_score(string $a, string $b): float {
    $mine = cs_words($a);  $theirs = cs_words($b);
    if (!$mine || !$theirs) return 0.0;
    $common = 0.0;
    foreach ($mine as $m) {
        $w = 0.0;
        foreach ($theirs as $t) { if ($m === $t) { $w = 1.0;  break; }  if (cs_word_match($m, $t)) $w = 0.5; }
        $common += $w;
    }
    return $common <= 0 ? 0.0 : $common / (count($mine) + count($theirs) - $common);
}

// Kontrol + plan: ['errors' => [...], 'warnings' => [...], 'map' => [gammel => ny], 'transfers' => [[gammel, ny, saldo]]]
function cs_plan($conn, array $map_in, string $date): array {
    $own = cs_own_accounts($conn);
    $std = cs_std_available($conn);
    $out = ['errors' => [], 'warnings' => [], 'map' => [], 'transfers' => []];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) { $out['errors'][] = lang('@Please enter a valid date.');  return $out; }
    if (is_date_locked($conn, $date)) $out['errors'][] = lang('@The date is in a locked accounting period.');
    foreach ($map_in as $old => $new) {
        $old = (int)$old;  $new = (int)$new;
        if ($new <= 0 || !isset($own[$old])) continue;
        if (!isset($std[$new])) { $out['errors'][] = sprintf(lang('@%d: the standard account %d does not exist in the chart of accounts.'), $old, $new);  continue; }
        if (cs_family((string)$own[$old]['acc_type']) !== cs_family((string)$std[$new]['acc_type'])) {
            $out['errors'][] = sprintf(lang('@%d %s cannot be replaced by %d %s - the account types differ.'), $old, $own[$old]['acc_name'], $new, $std[$new]['acc_name']);
            continue;
        }
        $out['map'][$old] = $new;
    }
    if (!$out['map']) $out['errors'][] = lang('@No account is set to be replaced.');
    $d = DB::escape($conn, $date);
    foreach ($out['map'] as $old => $new) {
        if (!in_array($own[$old]['acc_type'], CS_BALANCE_TYPES, true)) continue;
        $later = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM ledger l JOIN journal j ON j.jou_id = l.jou_id WHERE l.acc_id = $old AND j.jou_date > '$d'"));
        if ($later && (int)$later[0] > 0) {
            $out['errors'][] = sprintf(lang('@%d %s has postings after %s - choose a date on or after the last posting.'), $old, $own[$old]['acc_name'], date(CONF_DATE_FORMAT, strtotime($date)));
            continue;
        }
        $bal = DB::fetch_assoc(DB::query($conn, "SELECT COALESCE(SUM(l.amount), 0) AS b FROM ledger l JOIN journal j ON j.jou_id = l.jou_id WHERE l.acc_id = $old AND j.jou_date <= '$d'"));
        $b = round((float)($bal['b'] ?? 0), 2);
        if (abs($b) >= 0.005) $out['transfers'][] = [$old, $new, $b];
    }
    $fy = @DB::query($conn, "SELECT start_date FROM fiscal_years WHERE start_date = '$d'");
    if (!($fy && DB::fetch_assoc($fy))) $out['warnings'][] = lang('@The date is not the first day of a fiscal year - this year\'s income and expenses will be shown on both the old and the new accounts.');
    return $out;
}

// Udfør skiftet (én transaktion). Returnerer ['voucher_no' => n, 'counts' => [...]]
function cs_execute($conn, array $map_in, string $date): array {
    global $global_settings;
    $plan = cs_plan($conn, $map_in, $date);
    if ($plan['errors']) throw new Exception(implode(' ', $plan['errors']));
    $map = $plan['map'];
    $own = cs_own_accounts($conn);
    $std = cs_std_available($conn);
    $counts = [];
    $voucher = null;
    DB::begin_transaction($conn);
    try {
        // 0. Afløsere der kun findes i filen, oprettes nu (resten af standardkontoplanen kan importeres når som helst)
        cs_ensure_std_table($conn);
        foreach (array_unique(array_values($map)) as $new) {
            if (empty($std[$new]['virtual'])) continue;
            if (!DB::insert($conn, 'accounts', cs_std_row($conn, cs_std_doc()[$new], $new, $new))) throw new Exception(DB::error($conn));
            $counts['accounts'] = ($counts['accounts'] ?? 0) + 1;
        }
        // 1. Saldooverførsel - én bilagsført postering
        if ($plan['transfers']) {
            $voucher = next_voucher_no($conn);
            $text = DB::escape($conn, lang('@Switch to the standard chart of accounts: balances transferred'));
            $cur  = DB::escape($conn, $global_settings['currency'] ?? 'DKK');
            $cb   = journal_created_by_sql($conn);
            if (DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']})
                                  VALUES ('" . DB::escape($conn, $date) . "', '$text', 'chart_switch', $voucher, '$cur'{$cb['val']})") === false) throw new Exception(DB::error($conn));
            $jou = (int)DB::insert_id($conn);
            foreach ($plan['transfers'] as [$old, $new, $b]) {
                ledger_post($conn, $jou, $old, -$b, true);      // (den gamle konto findes måske ikke - spøgelseskonto)
                ledger_post($conn, $jou, $new, $b);
            }
        }
        // 2. Alt der peger på en gammel konto, peger på afløseren
        $year = (int)substr($date, 0, 4);
        $refs = [
            ['vat_codes', 'vat_account', ''], ['products', 'acc_id', ''], ['bank_match_rules', 'acc_id', ''],
            ['bank_connections', 'acc_id', ''], ['recurring_expenses', 'account_id', ''],
            ['fixed_assets', 'asset_account_id', "status = 'active'"], ['fixed_assets', 'depreciation_account_id', "status = 'active'"],
            ['account_budgets', 'acc_id', "year >= $year"], ['fiscal_years', 'equity_acc_id', 'is_closed = 0'],
            ['expenses', 'account_id', 'voucher_no IS NULL'], ['invoice_lines', 'acc_id', "inv_id IN (SELECT inv_id FROM invoices WHERE LOWER(inv_status) = 'draft')"],
            ['expense_lines', 'account_id', 'exp_id IN (SELECT exp_id FROM expenses WHERE voucher_no IS NULL)'],
            ['bank_statement_temp', 'acc_id', 'is_processed = 0'],
            ['shop_connections', 'clearing_acc', ''], ['shop_connections', 'sales_acc', ''], ['shop_connections', 'shipping_acc', ''],
        ];
        foreach ($refs as [$t, $col, $where]) {
            if (!shop_tbl_exists_cs($conn, $t)) continue;
            foreach ($map as $old => $new) {
                if ($t === 'account_budgets') {      // et budget findes måske allerede på afløseren - det beholdes
                    DB::query($conn, "UPDATE account_budgets SET acc_id = $new WHERE acc_id = $old AND $where
                                      AND NOT EXISTS (SELECT 1 FROM (SELECT acc_id, year FROM account_budgets) b WHERE b.acc_id = $new AND b.year = account_budgets.year)");
                    continue;
                }
                $r = DB::query($conn, "UPDATE $t SET $col = $new WHERE $col = $old" . ($where ? " AND $where" : ''));
                if ($r) $counts[$t] = ($counts[$t] ?? 0) + DB::affected_rows($conn, $r);
            }
        }
        // Firmaindstillingernes bogføringskonti (også en rolle der kun bruger programmets standardværdi - den
        // gemmes nu, ellers bogførte den fortsat på den gamle konto)
        foreach (cs_role_accounts($conn) as $k => $v) {
            if ($v > 0 && isset($map[$v])) {
                DB::query($conn, "DELETE FROM settings WHERE setting_key = '" . DB::escape($conn, $k) . "'");
                DB::insert($conn, 'settings', ['setting_key' => $k, 'setting_value' => (string)(int)$map[$v]]);
                $counts['settings'] = ($counts['settings'] ?? 0) + 1;
            }
        }
        // 3. Afløserne tændes og arver momsen; de gamle slukkes og peger på afløseren til SAF-T
        foreach ($map as $old => $new) {
            $upd = ["acc_status = 'active'"];
            $o = $own[$old];
            if (in_array($o['acc_type'], ['income', 'expense'], true) && ($std[$new]['vat_rate'] === null || $std[$new]['vat_rate'] === '') && $o['vat_rate'] !== null && $o['vat_rate'] !== '') {
                $upd[] = 'vat_rate = ' . (float)$o['vat_rate'];
                if (!empty($o['vat_code'])) $upd[] = "vat_code = '" . DB::escape($conn, $o['vat_code']) . "'";
            }
            DB::query($conn, "UPDATE accounts SET " . implode(', ', $upd) . " WHERE acc_id = $new");
            // SAF-T-standardkontoen er standardnummeret - også når afløseren ligger under et erstatningsnummer
            DB::query($conn, "UPDATE accounts SET acc_status = 'off', std_ref_id = " . (int)($std[$new]['std_no'] ?? $new) . " WHERE acc_id = $old");
        }
        log_action($conn, 'CHART_SWITCH', 'accounts', 0, null, ['date' => $date, 'map' => $map, 'voucher_no' => $voucher, 'transfers' => $plan['transfers'], 'updated' => $counts]);
        DB::commit($conn);
    } catch (Throwable $e) {
        DB::rollback($conn);
        throw $e;
    }
    return ['voucher_no' => $voucher, 'counts' => $counts, 'map' => $map, 'transfers' => $plan['transfers']];
}

function shop_tbl_exists_cs($conn, string $t): bool {
    static $cache = [];
    if (!isset($cache[$t])) {
        $r = DB::is_sqlite() ? DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='" . DB::escape($conn, $t) . "'")
                             : DB::query($conn, "SHOW TABLES LIKE '" . DB::escape($conn, $t) . "'");
        $cache[$t] = DB::is_sqlite() ? (bool)($r && DB::fetch_assoc($r)) : ($r && DB::num_rows($r) > 0);
    }
    return $cache[$t];
}

// ---------------------------------------------------------------------------------------------------------------
// KONFLIKTER: en af dine konti optager et standardnummer med et andet indhold, så standardkontoen ikke kan importeres
// (et kontonummer findes kun én gang). Fire løsninger (bruger-anmodet "er der ikke andre løsninger" -> "ja"):
//   'move'     kontoen har INGEN posteringer: den flyttes til et ledigt nummer (alt der peger på den følger med),
//              og standardkontoen importeres på sit eget nummer
//   'takeover' samme kontofamilie: din konto BLIVER standardkontoen (standardens navn og type, knyttet til den) -
//              ingen saldooverførsel, historikken fortsætter på samme nummer (det gamle navn står i revisionssporet)
//   'alt'      standardkontoen importeres under et ledigt erstatningsnummer, knyttet til standardnummeret (SAF-T ser
//              standardkontoen rigtigt) - til konti med posteringer og et andet indhold
//   'keep'     intet - din konto afløses af en anden standardkonto i skiftet
// ---------------------------------------------------------------------------------------------------------------

// Standardkontoplanens konti fra filen: [nr => række]
function cs_std_doc(): array {
    static $doc = null;
    if ($doc === null) {
        $doc = [];
        foreach ((array)(json_decode((string)@file_get_contents(ACC_STD_CHART_FILE), true)['accounts'] ?? []) as $a) $doc[(int)$a['no']] = $a;
    }
    return $doc;
}

// Tabeller og kolonner der peger på et kontonummer (en flytning skal tage dem alle med)
const CS_ACC_REFS = [
    ['vat_codes', 'vat_account'], ['products', 'acc_id'], ['invoice_lines', 'acc_id'], ['expenses', 'account_id'], ['expense_lines', 'account_id'],
    ['recurring_expenses', 'account_id'], ['bank_match_rules', 'acc_id'], ['bank_connections', 'acc_id'], ['bank_statement_temp', 'acc_id'],
    ['bank_reconciliations', 'acc_id'], ['account_budgets', 'acc_id'], ['fiscal_years', 'equity_acc_id'], ['fixed_assets', 'asset_account_id'],
    ['fixed_assets', 'depreciation_account_id'], ['shop_connections', 'clearing_acc'], ['shop_connections', 'sales_acc'],
    ['shop_connections', 'shipping_acc'], ['transactions', 'acc_id'], ['transactions', 'offset_acc_id'],
];

function cs_col_exists($conn, string $t, string $c): bool {
    static $cache = [];
    $k = "$t.$c";
    if (!isset($cache[$k])) {
        if (!shop_tbl_exists_cs($conn, $t)) return $cache[$k] = false;
        if (DB::is_sqlite()) {
            $cache[$k] = false;
            $r = DB::query($conn, "PRAGMA table_info($t)");
            if ($r) while ($x = DB::fetch_assoc($r)) if ($x['name'] === $c) $cache[$k] = true;
        } else {
            $r = DB::query($conn, "SHOW COLUMNS FROM $t LIKE '" . DB::escape($conn, $c) . "'");
            $cache[$k] = $r && DB::num_rows($r) > 0;
        }
    }
    return $cache[$k];
}

// Et ledigt kontonummer tæt på $near: hverken i din kontoplan eller et standardnummer
function cs_free_number($conn, int $near, array $taken = []): int {
    $doc = cs_std_doc();
    $used = $taken;
    $res = DB::query($conn, "SELECT acc_id FROM accounts");
    while ($r = DB::fetch_row($res)) $used[(int)$r[0]] = true;
    // RETTET: et nummer med posteringer men uden konto (spøgelseskonto, fx en gammel standardværdi som 5000) blev
    // regnet som ledigt - en flyttet konto eller en importeret standardkonto ville arve de fremmede posteringer
    $res = DB::query($conn, "SELECT DISTINCT acc_id FROM ledger");
    if ($res) while ($r = DB::fetch_row($res)) $used[(int)$r[0]] = true;
    for ($i = 1; $i <= 999; $i++) {
        foreach ([$near + $i, $near - $i] as $n) {
            if ($n > 0 && !isset($used[$n]) && !isset($doc[$n])) return $n;
        }
    }
    return 0;
}

// Konflikterne: [standardnr => ['own' => din konto, 'std' => standardkontoen, 'postings' => n, 'options' => [...],
// 'proposal' => løsning, 'target' => foreslået ledigt nummer]]
function cs_conflicts($conn): array {
    $doc = cs_std_doc();
    $alt = array_flip(cs_alt_map($conn));      // standardnr => erstatningsnummer
    $used = [];
    $res = DB::query($conn, "SELECT acc_id, COUNT(*) AS n FROM ledger GROUP BY acc_id");
    if ($res) while ($r = DB::fetch_assoc($res)) $used[(int)$r['acc_id']] = (int)$r['n'];
    $out = [];  $taken = [];
    $res = DB::query($conn, "SELECT acc_id, acc_name, acc_type, std_ref_id" . acc_status_col($conn) . " FROM accounts ORDER BY acc_id");
    while ($a = DB::fetch_assoc($res)) {
        $no = (int)$a['acc_id'];
        if (!isset($doc[$no]) || (int)($a['std_ref_id'] ?? 0) === $no || isset($alt[$no])) continue;
        $s = $doc[$no];
        $n = $used[$no] ?? 0;
        $same = cs_family((string)$a['acc_type']) === cs_family((string)$s['type']);
        $score = cs_name_score((string)$a['acc_name'], (string)$s['name']);
        $opt = [];
        if ($n === 0) $opt[] = 'move';
        if ($same)    $opt[] = 'takeover';
        $opt[] = 'alt';
        $opt[] = 'keep';
        // Forslag: samme indhold -> overtag; ubrugt -> flyt; ellers lad nummeret ligge
        if ($same && ($score >= 0.5 || ($n > 0 && $score >= 0.2))) $prop = 'takeover';
        elseif ($n === 0) $prop = 'move';
        else $prop = 'keep';
        $target = cs_free_number($conn, $no, $taken);
        $taken[$target] = true;
        $out[$no] = ['own' => $a, 'std' => $s, 'postings' => $n, 'same_family' => $same, 'options' => $opt, 'proposal' => $prop, 'target' => $target];
    }
    return $out;
}

// Løs konflikterne. $actions: [standardnr => 'move'|'takeover'|'alt'|'keep'], $targets: [standardnr => nyt nummer]
// Returnerer ['done' => [standardnr => tekst], 'errors' => [...]] - alt eller intet (én transaktion)
function cs_resolve_conflicts($conn, array $actions, array $targets): array {
    $conf = cs_conflicts($conn);
    $doc = cs_std_doc();
    $out = ['done' => [], 'errors' => []];
    $plan = [];  $claimed = [];
    foreach ($actions as $no => $act) {
        $no = (int)$no;  $act = (string)$act;
        if ($act === 'keep' || $act === '' || !isset($conf[$no])) continue;
        $c = $conf[$no];
        if (!in_array($act, $c['options'], true)) { $out['errors'][] = sprintf(lang('@%d: this solution is not possible for the account.'), $no);  continue; }
        if (in_array($act, ['move', 'alt'], true)) {
            $t = (int)($targets[$no] ?? 0);
            $clash = $t <= 0 || isset($doc[$t]) || isset($claimed[$t]) || DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM accounts WHERE acc_id = $t"))
                  || DB::fetch_assoc(DB::query($conn, "SELECT acc_id FROM ledger WHERE acc_id = $t LIMIT 1"));      // (posteringer uden konto)
            if ($clash) { $out['errors'][] = sprintf(lang('@%1$d: the number %2$d is not free - choose a number that is neither used by you nor in the standard chart.'), $no, $t);  continue; }
            $claimed[$t] = true;
            $plan[$no] = [$act, $t];
        } else {
            $plan[$no] = [$act, 0];
        }
    }
    if ($out['errors'] || !$plan) return $out;

    $std_row = fn(int $id, array $s, int $ref) => cs_std_row($conn, $s, $id, $ref);
    $alt = cs_alt_map($conn);
    $before = [];
    DB::begin_transaction($conn);
    try {
        cs_ensure_std_table($conn);
        foreach ($plan as $no => [$act, $t]) {
            $c = $conf[$no];  $s = $doc[$no];
            $before[$no] = ['acc_name' => $c['own']['acc_name'], 'acc_type' => $c['own']['acc_type'], 'action' => $act, 'to' => $t];
            if ($act === 'move') {
                // Kontrolleres igen inde i transaktionen: en postering der er kommet til siden, stopper flytningen
                $n = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM ledger WHERE acc_id = $no"));
                if ((int)($n[0] ?? 0) > 0) throw new Exception(sprintf(lang('@%d has postings now - it cannot be moved.'), $no));
                $row = DB::fetch_assoc(DB::query($conn, "SELECT * FROM accounts WHERE acc_id = $no"));
                $row['acc_id'] = $t;
                if (!DB::insert($conn, 'accounts', $row)) throw new Exception(DB::error($conn));
                foreach (CS_ACC_REFS as [$tbl, $col]) {
                    if (!cs_col_exists($conn, $tbl, $col)) continue;
                    DB::query($conn, "UPDATE $tbl SET $col = $t WHERE $col = $no");
                    // DB::query() sluger fejl (fx en append-only-trigger) - en henvisning der blev tilbage, ville
                    // ellers stille pege på standardkontoen der nu får nummeret
                    $left = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM $tbl WHERE $col = $no"));
                    if ((int)($left[0] ?? 0) > 0) throw new Exception(sprintf(lang('@%1$d cannot be moved: %2$s still points to it.'), $no, "$tbl.$col"));
                }
                // Firmaindstillingerne - også en rolle der kun bruger programmets standardværdi (den gemmes nu)
                foreach (cs_role_accounts($conn) as $k => $v) {
                    if ($v !== $no) continue;
                    DB::query($conn, "DELETE FROM settings WHERE setting_key = '" . DB::escape($conn, $k) . "'");
                    DB::insert($conn, 'settings', ['setting_key' => $k, 'setting_value' => (string)$t]);
                }
                DB::query($conn, "DELETE FROM accounts WHERE acc_id = $no");
                if (!DB::insert($conn, 'accounts', $std_row($no, $s, $no))) throw new Exception(DB::error($conn));
                $out['done'][$no] = sprintf(lang('@%1$s moved to %2$d - the standard account %3$d %4$s is imported.'), $c['own']['acc_name'], $t, $no, $s['name']);
            } elseif ($act === 'takeover') {
                DB::query($conn, "UPDATE accounts SET acc_name = '" . DB::escape($conn, $s['name']) . "', acc_type = '" . DB::escape($conn, $s['type']) . "', std_ref_id = $no WHERE acc_id = $no");
                $out['done'][$no] = sprintf(lang('@%1$d %2$s is now the standard account %3$s.'), $no, $c['own']['acc_name'], $s['name']);
            } else {
                if (!DB::insert($conn, 'accounts', $std_row($t, $s, $no))) throw new Exception(DB::error($conn));
                $alt[$t] = $no;
                $out['done'][$no] = sprintf(lang('@The standard account %1$d %2$s is imported as %3$d.'), $no, $s['name'], $t);
            }
        }
        DB::query($conn, "DELETE FROM settings WHERE setting_key = '" . CS_ALT_SETTING . "'");
        if ($alt) DB::insert($conn, 'settings', ['setting_key' => CS_ALT_SETTING, 'setting_value' => json_encode($alt)]);
        log_action($conn, 'CHART_CONFLICTS', 'accounts', 0, $before, $out['done']);
        DB::commit($conn);
    } catch (Throwable $e) {
        DB::rollback($conn);
        $out['done'] = [];
        $out['errors'][] = $e->getMessage();
    }
    return $out;
}
