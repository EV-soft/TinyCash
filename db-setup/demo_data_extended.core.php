<?php # /db-setup/demo_data_extended.core.php v:1.0.0 d:2026-11-05 i:evs
# Udvidede demodata (bruger-anmodet: "data der muliggør aftestning af alt"): et samlet eventyr-scenarie, så hver side
# og hvert flow har noget at vise - betalte, delvist betalte, forfaldne og rykkede fakturaer, EUR-faktura, rabat,
# kreditnota, afskrivning, tilbud i alle statusser, leverandører med betalingsfil, udgifter (betalt, skyldig, splittet),
# faste fakturaer/udgifter, timer, budgetter, banklinjer til afstemning, bankregler, linjeskabelon, kontaktpersoner.
# Bogfører gennem appens egne funktioner (invoice_post, invoice_register_payment, reconcile_one_bank_line,
# invoice_create_credit, ledger_post), så tallene hænger sammen som i virkeligheden. Datoer er relative til i dag,
# så "forfalden" og "udløber snart" passer, når scriptet køres. Kører kun én gang pr. regnskab (settings-markør);
# et scenarie hvis tabel/kolonne mangler (migration ikke kørt), springes over.
require_once __DIR__ . '/../inc/php2htm.lib.php';
require_once __DIR__ . '/../inc/audit.inc.php';
require_once __DIR__ . '/../inc/invoice_post.inc.php';
require_once __DIR__ . '/../inc/reconcile.inc.php';
require_once __DIR__ . '/../inc/customer_statement.inc.php';

const DX_MARKER = 'demo_extended_version';
class DxSkip extends Exception {}
function dx_need_table($conn, string $table): void {
    if (!db_has_table($conn, $table)) throw new DxSkip("tabellen $table findes ikke - kør migrationen (System -> Vedligeholdelse -> Databasemigration) og derefter dette script igen");
}

function dx_date(int $days): string { return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days')); }

// En række, hvis tabellen findes - kun de kolonner, der findes (ældre skemaer)
function dx_insert($conn, string $table, array $row): int {
    if (!db_has_table($conn, $table)) return 0;
    $row = array_filter($row, fn($k) => db_has_column($conn, $table, $k), ARRAY_FILTER_USE_KEY);
    return DB::insert($conn, $table, $row) ? (int)DB::insert_id($conn) : 0;
}

// En fakturakladde med linjer: [tekst, antal, pris, moms %, vare-id, rabat %]
function dx_invoice($conn, int $cust_id, string $date, string $due, array $lines, array $extra = []): int {
    $inv_id = dx_insert($conn, 'invoices', $extra + ['cust_id' => $cust_id, 'inv_date' => $date, 'inv_due_date' => $due,
        'inv_status' => 'draft', 'currency' => setting('currency', 'DKK')]);
    foreach ($lines as $l) {
        dx_insert($conn, 'invoice_lines', ['inv_id' => $inv_id, 'line_text' => $l[0], 'quantity' => $l[1], 'price_each' => $l[2],
            'line_vat_rate' => $l[3] ?? 25, 'prod_id' => $l[4] ?? 0, 'discount_pct' => $l[5] ?? 0, 'proj_id' => $extra['proj_id'] ?? null]);
    }
    return $inv_id;
}

// En banklinje (til afstemning) - afstemt mod en faktura, hvis $inv_id er givet
function dx_bank_line($conn, string $date, string $text, float $amount, int $inv_id = 0): int {
    $tmp = dx_insert($conn, 'bank_statement_temp', ['trans_date' => $date, 'text_val' => $text, 'amount' => $amount,
        'fee_amount' => 0, 'import_source' => 'demo', 'is_processed' => 0]);
    if ($inv_id > 0 && $tmp > 0) {
        $r = reconcile_one_bank_line($conn, get_settings($conn), $tmp, $inv_id, 0, 0.0, 0, 0, false);
        if (empty($r['ok'])) throw new Exception('Afstemning: ' . ($r['message'] ?? '?'));
    }
    return $tmp;
}

// En bogført udgift (samme posteringer som expense_edit.php): udgiftskonto netto, købsmoms, bank (betalt) eller
// kreditor (skyldig, med forfald). $e: date, supplier, supplier_id, account_id, amount (brutto), vat, desc, paid, due, type
function dx_expense($conn, array $e): int {
    $s       = get_settings($conn);
    $income  = ($e['type'] ?? 'expense') === 'income';
    $gross   = round((float)$e['amount'], 2);
    $vat     = round($gross * (float)($e['vat'] ?? 25) / (100 + (float)($e['vat'] ?? 25)), 2);
    $voucher = next_voucher_no($conn);
    $paid    = !empty($e['paid']);
    $exp_id  = dx_insert($conn, 'expenses', [
        'exp_date' => $e['date'], 'supplier' => $e['supplier'], 'supplier_id' => $e['supplier_id'] ?? null,
        'account_id' => $e['account_id'], 'amount' => $gross, 'vat_rate' => $e['vat'] ?? 25, 'description' => $e['desc'] ?? '',
        'voucher_no' => $voucher, 'currency' => setting('currency', 'DKK'), 'exp_type' => $income ? 'income' : 'expense',
        'proj_id' => $e['proj_id'] ?? null, 'no_attachment_reason' => 'Demodata - intet bilag',
        'due_date' => $paid ? null : ($e['due'] ?? null), 'paid_date' => $paid ? $e['date'] : null,
        'created_by' => 1, 'is_cancelled' => 0,
    ]);
    foreach ($e['lines'] ?? [] as $l) dx_insert($conn, 'expense_lines', ['exp_id' => $exp_id, 'account_id' => $l[0], 'amount' => $l[1], 'line_vat_rate' => $e['vat'] ?? 25, 'description' => $l[2] ?? null]);
    $cb = journal_created_by_sql($conn);
    $txt = DB::escape($conn, "Udgift ID $exp_id: " . ($income ? 'Indtægt' : 'Udgift') . ': ' . $e['supplier'] . (!empty($e['desc']) ? ' - ' . $e['desc'] : ''));
    DB::query($conn, "INSERT INTO journal (jou_date, voucher_no, jou_text, trans_type, currency{$cb['col']}) VALUES ('{$e['date']}', $voucher, '$txt', 'expense', '" . DB::escape($conn, setting('currency', 'DKK')) . "'{$cb['val']})");
    $jou_id = (int)DB::insert_id($conn);
    $sign = $income ? -1 : 1;
    $vat_acc  = $income ? (int)($s['conf_acc_vat'] ?? 6900) : (int)($s['conf_acc_purchase_vat'] ?? 6910);
    $counter  = $paid ? (int)($s['conf_acc_bank'] ?? 5000) : (int)($s['conf_acc_creditor'] ?? 4000);
    if (!empty($e['lines'])) {
        foreach ($e['lines'] as $l) ledger_post($conn, $jou_id, (int)$l[0], $sign * round((float)$l[1] / (1 + (float)($e['vat'] ?? 25) / 100), 2));
        $net_sum = array_sum(array_map(fn($l) => round((float)$l[1] / (1 + (float)($e['vat'] ?? 25) / 100), 2), $e['lines']));
        $vat = round($gross - $net_sum, 2);
    } else {
        ledger_post($conn, $jou_id, (int)$e['account_id'], $sign * round($gross - $vat, 2));
    }
    if ($vat != 0) ledger_post($conn, $jou_id, $vat_acc, $sign * $vat);
    ledger_post($conn, $jou_id, $counter, -$sign * $gross);
    return $exp_id;
}

// $log(tekst) skriver en linje i rapporten. Returnerer [ok, fejl].
function seed_demo_data_extended($conn, ?callable $log = null): array {
    $log = $log ?? function (string $t) { echo $t . "\n"; };
    if (setting(DX_MARKER, '') !== '') { $log('Udvidede demodata findes allerede (version ' . setting(DX_MARKER) . ') - intet gjort.'); return [0, 0]; }
    if (is_date_locked($conn, dx_date(-120))) { $log('STOP: der er en låsedato inden for de sidste 120 dage - demodata bogføres i den periode.'); return [0, 1]; }
    $ok = 0;  $err = 0;
    // Hvert scenarie i sin egen transaktion - undtagen dem med bankafstemning ($tx = false): reconcile_one_bank_line()
    // styrer selv sin transaktion, og SQLite kan ikke indlejre dem
    // En manglende tabel (migration ikke kørt) er ikke en fejl - scenariet springes over (dx_skip())
    // Hvert gennemført scenarie huskes (settings 'demo_extended_done'), så en ny kørsel efter migrationen kun tager resten
    $skip = 0;
    $done = json_decode((string)setting('demo_extended_done', '[]'), true) ?: [];
    $step = function (string $name, callable $fn, bool $tx = true) use ($conn, $log, &$ok, &$err, &$skip, &$done) {
        if (in_array($name, $done, true)) { $log("FINDES $name"); return; }
        if ($tx) DB::begin_transaction($conn);
        try {
            $fn();
            $done[] = $name;
            $kq = 'demo_extended_done';  $vq = DB::escape($conn, json_encode($done, JSON_UNESCAPED_UNICODE));
            if (DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM settings WHERE setting_key = '$kq'"))[0] > 0) DB::query($conn, "UPDATE settings SET setting_value = '$vq' WHERE setting_key = '$kq'");
            else DB::query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('$kq', '$vq')");
            if ($tx) DB::commit($conn);  $ok++;  $log("OK    $name");
        }
        catch (DxSkip $e) { if ($tx) DB::rollback($conn);  $skip++;  $log("SPRING OVER  $name: " . $e->getMessage()); }
        catch (\Throwable $e) { if ($tx) DB::rollback($conn);  $err++;  $log("FEJL  $name: " . $e->getMessage()); }
    };
    $set = function (string $k, $v, bool $only_if_empty = true) use ($conn) {
        if ($only_if_empty && setting($k, '') !== '') return;
        $kq = DB::escape($conn, $k);  $vq = DB::escape($conn, (string)$v);
        if (DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM settings WHERE setting_key = '$kq'"))[0] > 0) DB::query($conn, "UPDATE settings SET setting_value = '$vq' WHERE setting_key = '$kq'");
        else DB::query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('$kq', '$vq')");
    };
    $acc_exists = fn(int $a) => tc_account_ok($conn, $a);
    $ids = [];      // navngivne id'er til de senere scenarier

    // 1. Firmaprofil og bogføringskonti - ellers viser blanketterne pladsholdere, og bankposteringer afvises
    $step('Firmaprofil og bogføringskonti', function () use ($conn, $set, $acc_exists) {
        foreach (['company_address' => 'Nyhavn 18', 'company_city' => '1051 København K', 'company_cvr' => '12345678',
                  'bank_name' => 'Den Gyldne Skatkiste Bank', 'bank_reg' => '1234', 'bank_acc' => '0012345678',
                  'company_mobilepay' => '12345', 'company_web' => 'www.tinycash-eventyr.dk', 'reminder_fee_amount' => '100'] as $k => $v) $set($k, $v);
        if (!$acc_exists((int)setting('conf_acc_bank', 5000)) && $acc_exists(5800)) $set('conf_acc_bank', 5800, false);
        // Tab på debitorer findes kun efter en migration - afskrivningsscenariet skal kunne bogføres
        if (!$acc_exists(7100)) dx_insert($conn, 'accounts', ['acc_id' => 7100, 'acc_name' => 'Tab på debitorer', 'acc_type' => 'expense', 'vat_rate' => 0]);
        foreach (['conf_acc_creditor' => 4000, 'conf_acc_vat' => 6900, 'conf_acc_purchase_vat' => 6910, 'conf_acc_bad_debt' => 7100, 'conf_acc_fx' => 7200] as $k => $a) {
            if ($acc_exists($a)) $set($k, $a);
        }
        $GLOBALS['global_settings'] = get_settings($conn);
    });

    // 2. Kunder med særtilfælde + kontaktpersoner
    $step('Kunder (engelsk, uden rykkere, kreditgrænse) og kontaktpersoner', function () use ($conn, &$ids) {
        $ids['mermaid'] = dx_insert($conn, 'customers', ['cust_name' => 'The Little Mermaid Ltd', 'cust_email' => 'ariel@mermaid.example',
            'cust_address' => "Langelinie 1\n2100 København Ø", 'cust_cvr' => 'GB123456789', 'cust_payment_days' => 14, 'cust_language' => 'en']);
        $ids['snow'] = dx_insert($conn, 'customers', ['cust_name' => 'Snedronningens Isslot', 'cust_email' => 'is@snedronningen.example',
            'cust_address' => "Isvej 1\n9990 Skagen", 'cust_payment_days' => 8, 'cust_no_reminders' => 1, 'cust_notes' => 'Vil ikke have rykkere - ring i stedet.']);
        $ids['tin'] = dx_insert($conn, 'customers', ['cust_name' => 'Den Standhaftige Tinsoldat', 'cust_email' => 'tin@soldat.example',
            'cust_address' => "Legetøjsgade 7\n5000 Odense C", 'cust_payment_days' => 14, 'credit_limit' => 5000]);
        $ids['nomail'] = dx_insert($conn, 'customers', ['cust_name' => 'Klods-Hans (uden e-mail)', 'cust_address' => "Mudderhullet 3\n4000 Roskilde", 'cust_payment_days' => 8]);
        $c = (int)(DB::fetch_row(DB::query($conn, "SELECT MIN(cust_id) FROM customers WHERE cust_email IS NOT NULL AND cust_email <> ''"))[0] ?? 0);
        dx_insert($conn, 'customer_contacts', ['cust_id' => $c, 'contact_name' => 'Bogholderiet', 'contact_email' => 'faktura@eventyr.example', 'contact_note' => 'Fakturamodtager', 'created_at' => date('Y-m-d H:i:s')]);
        dx_insert($conn, 'customer_contacts', ['cust_id' => $c, 'contact_name' => 'Indkøber Ole Lukøje', 'contact_phone' => '+45 20 30 40 50', 'created_at' => date('Y-m-d H:i:s')]);
    });

    // 3. Leverandører - med og uden bankoplysninger (betalingsfilen)
    $step('Leverandører', function () use ($conn, &$ids) {
        $ids['sup_bell']  = dx_insert($conn, 'suppliers', ['supplier_name' => 'Klokkestøberiet ApS', 'cvr' => '87654321', 'email' => 'regning@klokke.example', 'payment_days' => 14, 'supplier_reg_no' => '9876', 'supplier_account_no' => '0001234567', 'is_active' => 1, 'address' => "Støberivej 2\n8000 Aarhus C"]);
        $ids['sup_troll'] = dx_insert($conn, 'suppliers', ['supplier_name' => 'Trolden Transport', 'cvr' => '11223344', 'email' => 'bogholderi@trold.example', 'payment_days' => 30, 'supplier_reg_no' => '5555', 'supplier_account_no' => '0009988776', 'is_active' => 1]);
        $ids['sup_witch'] = dx_insert($conn, 'suppliers', ['supplier_name' => 'Heksens Urtehandel', 'email' => 'heks@urter.example', 'payment_days' => 8, 'is_active' => 1, 'notes' => 'Ingen bankoplysninger - kan ikke komme med i betalingsfilen']);
    });

    // 4. Varer: kostpris, kategori, EAN, en vare under minimumslager
    $step('Varer (kostpris, kategori, lavt lager)', function () use ($conn) {
        $res = DB::query($conn, "SELECT prod_id, prod_price FROM products ORDER BY prod_id");
        $i = 0;
        while ($r = DB::fetch_assoc($res)) {
            $upd = [];
            if (db_has_column($conn, 'products', 'prod_cost_price') && $i % 4 !== 3) $upd[] = 'prod_cost_price = ' . round((float)$r['prod_price'] * 0.55, 2);      // (hver 4. uden kostpris)
            if (db_has_column($conn, 'products', 'prod_category')) $upd[] = "prod_category = '" . ($i % 2 ? 'Magi' : 'Eventyrvarer') . "'";
            if (db_has_column($conn, 'products', 'prod_ean')) $upd[] = "prod_ean = '57" . str_pad((string)(1000000000 + (int)$r['prod_id']), 11, '0', STR_PAD_LEFT) . "'";
            if ($upd) DB::query($conn, 'UPDATE products SET ' . implode(', ', $upd) . ' WHERE prod_id = ' . (int)$r['prod_id']);
            $i++;
        }
        DB::query($conn, "UPDATE products SET prod_stock = 1, prod_min_stock = 5 WHERE prod_id = (SELECT MAX(prod_id) FROM products)");
    });

    $cust  = fn(int $n) => (int)(DB::fetch_row(DB::query($conn, "SELECT cust_id FROM customers WHERE cust_email IS NOT NULL AND cust_email <> '' AND " . customers_active_filter($conn) . " ORDER BY cust_id LIMIT 1 OFFSET $n"))[0] ?? 0);
    $prod  = fn(int $n) => DB::fetch_assoc(DB::query($conn, "SELECT prod_id, prod_name, prod_price FROM products ORDER BY prod_id LIMIT 1 OFFSET $n")) ?: ['prod_id' => 0, 'prod_name' => 'Ydelse', 'prod_price' => 500];
    $pline = fn(int $n, float $qty, float $disc = 0) => [$prod($n)['prod_name'], $qty, (float)$prod($n)['prod_price'], 25, (int)$prod($n)['prod_id'], $disc];

    // 5. Fakturaer i alle tilstande
    $step('Faktura betalt via bankafstemning', function () use ($conn, $cust, $pline) {
        $id = dx_invoice($conn, $cust(0), dx_date(-60), dx_date(-52), [$pline(0, 2), $pline(1, 3)], ['cust_reference' => 'Ordre 4711']);
        invoice_post($conn, $id);
        dx_bank_line($conn, dx_date(-50), 'Indbetaling faktura ' . DB::fetch_row(DB::query($conn, "SELECT invoice_no FROM invoices WHERE inv_id = $id"))[0], invoice_open_dkk($conn, $id), $id);
    }, false);
    $step('Faktura delvist betalt', function () use ($conn, $cust, $pline, &$ids) {
        $id = dx_invoice($conn, $cust(1), dx_date(-40), dx_date(-26), [$pline(2, 4)]);
        invoice_post($conn, $id);
        dx_bank_line($conn, dx_date(-30), 'Aconto betaling', round(invoice_open_dkk($conn, $id) / 2, 2), $id);
        $ids['partial'] = $id;
    }, false);
    $step('Forfalden faktura uden rykker (rykkerlisten)', function () use ($conn, $cust, $pline) {
        invoice_post($conn, dx_invoice($conn, $cust(2), dx_date(-45), dx_date(-37), [$pline(3, 1), ['Fragt med flyvende tæppe', 1, 150, 25, 0, 0]]));
    });
    $step('Forfalden faktura med 1. rykker sendt for 12 dage siden (2. rykker med gebyr)', function () use ($conn, $cust, $pline) {
        $id = dx_invoice($conn, $cust(3), dx_date(-50), dx_date(-36), [$pline(4, 2)]);
        invoice_post($conn, $id);
        $sent = dx_date(-12) . ' 10:00:00';
        DB::query($conn, "UPDATE invoices SET reminder_count = 1, reminder_sent_at = '$sent' WHERE inv_id = $id");
        dx_insert($conn, 'audit_log', ['user_id' => 1, 'action_type' => 'SEND_REMINDER', 'table_name' => 'invoices', 'row_id' => $id,
            'old_values' => json_encode(['reminder_count' => 0]), 'new_values' => json_encode(['reminder_count' => 1, 'to' => 'demo', 'fee' => 0]), 'log_date' => $sent]);
    });
    $step('Forfalden faktura til kunde uden rykkere', function () use ($conn, $pline, &$ids) {
        if (empty($ids['snow'])) throw new Exception('kunden mangler');
        invoice_post($conn, dx_invoice($conn, $ids['snow'], dx_date(-30), dx_date(-22), [$pline(5, 1)]));
    });
    $step('EUR-faktura til engelsk kunde (kundens sprog på papiret)', function () use ($conn, $pline, &$ids) {
        if (empty($ids['mermaid'])) throw new Exception('kunden mangler');
        $id = dx_invoice($conn, $ids['mermaid'], dx_date(-20), dx_date(-6), [['Mermaid tail polish', 3, 40, 0, 0, 0], ['Shipping', 1, 25, 0, 0, 0]],
            ['currency' => 'EUR', 'orig_currency' => 'EUR', 'exch_rate' => 7.46, 'cust_reference' => 'PO-2026-17']);
        invoice_post($conn, $id);
    });
    $step('Faktura med linjerabat og projekt (ikke forfalden)', function () use ($conn, $cust, $pline) {
        $proj = (int)(DB::fetch_row(DB::query($conn, "SELECT MIN(proj_id) FROM projects"))[0] ?? 0);
        invoice_post($conn, dx_invoice($conn, $cust(4), dx_date(-5), dx_date(9), [$pline(0, 10, 10), $pline(6, 1, 25)], $proj ? ['proj_id' => $proj] : []));
    });
    $step('Faktura krediteret delvist (kreditnota)', function () use ($conn, $cust, $pline) {
        $id = dx_invoice($conn, $cust(5), dx_date(-35), dx_date(-27), [$pline(1, 5), $pline(2, 2)]);
        invoice_post($conn, $id);
        $first = (int)DB::fetch_row(DB::query($conn, "SELECT MIN(line_id) FROM invoice_lines WHERE inv_id = $id"))[0];
        $last  = (int)DB::fetch_row(DB::query($conn, "SELECT MAX(line_id) FROM invoice_lines WHERE inv_id = $id"))[0];
        invoice_create_credit($conn, $id, [$first => 2, $last => 0], dx_date(-25), 'Demo: 2 stk. returneret');
    });
    $step('Faktura afskrevet som tab på debitor', function () use ($conn, $cust, $pline) {
        $id = dx_invoice($conn, $cust(6), dx_date(-110), dx_date(-102), [$pline(7, 1)]);
        invoice_post($conn, $id);
        invoice_register_payment($conn, $id, dx_date(-10), invoice_open_dkk($conn, $id), (int)setting('conf_acc_bad_debt', 7100), lang('@Written off as bad debt') . ' - Konkurs (demo)');
    });
    $step('Faktura betalt kontant (manuel betaling)', function () use ($conn, $cust, $pline) {
        $id = dx_invoice($conn, $cust(7), dx_date(-15), dx_date(-7), [$pline(8, 6)]);
        invoice_post($conn, $id);
        dx_bank_line($conn, dx_date(-14), lang('@Manual payment') . ' - kontant i butikken', invoice_open_dkk($conn, $id), $id);
    }, false);
    $step('Fakturakladder (en med kunde over kreditgrænsen)', function () use ($conn, $cust, $pline, &$ids) {
        dx_invoice($conn, $cust(0), dx_date(0), dx_date(8), [$pline(3, 2)], ['inv_note' => 'Kladde - ikke bogført endnu']);
        if (!empty($ids['tin'])) {
            $id = dx_invoice($conn, $ids['tin'], dx_date(-25), dx_date(-11), [$pline(6, 3)]);
            invoice_post($conn, $id);      // (forfalden saldo - advarsel om kreditgrænse ved næste faktura)
            dx_invoice($conn, $ids['tin'], dx_date(0), dx_date(14), [$pline(6, 2)], ['inv_note' => 'Kunden er over sin kreditgrænse']);
        }
    });

    // 6. Banklinjer der venter på afstemning + bankregler
    $step('Uafstemte banklinjer og bankregler', function () use ($conn, &$ids) {
        if (!empty($ids['partial'])) dx_bank_line($conn, dx_date(-2), 'Restbetaling ' . DB::fetch_row(DB::query($conn, "SELECT invoice_no FROM invoices WHERE inv_id = {$ids['partial']}"))[0], invoice_open_dkk($conn, $ids['partial']));
        dx_bank_line($conn, dx_date(-3), 'NETFLIX.COM abonnement', -129);
        dx_bank_line($conn, dx_date(-3), 'Gebyr kontoførelse', -45);
        dx_bank_line($conn, dx_date(-1), 'MobilePay salg dagsopgørelse', 1875);
        dx_bank_line($conn, dx_date(-1), 'Overførsel ukendt afsender', 500);
        foreach ([['NETFLIX', 2300], ['Gebyr', 2320], ['MobilePay', 1000]] as [$p, $a]) {
            if (tc_account_ok($conn, $a)) dx_insert($conn, 'bank_match_rules', ['pattern' => $p, 'acc_id' => $a, 'created_by' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }, false);

    // 7. Tilbud i alle statusser
    $step('Tilbud (kladde, sendt, udløber snart, udløbet, accepteret, afvist, konverteret)', function () use ($conn, $cust, $pline) {
        dx_need_table($conn, 'quotes');
        $q = function (int $c, string $status, string $date, ?string $valid, array $lines, array $extra = []) use ($conn) {
            $id = dx_insert($conn, 'quotes', $extra + ['quote_no' => next_quote_no($conn), 'cust_id' => $c, 'quote_date' => $date, 'valid_until' => $valid,
                'status' => $status, 'created_by' => 1, 'created_at' => date('Y-m-d H:i:s')]);
            foreach ($lines as $l) dx_insert($conn, 'quote_lines', ['quote_id' => $id, 'line_text' => $l[0], 'quantity' => $l[1], 'price_each' => $l[2], 'line_vat_rate' => $l[3], 'prod_id' => $l[4]]);
            return $id;
        };
        $q($cust(0), 'draft', dx_date(0), dx_date(30), [$pline(2, 1)], ['quote_note' => 'Kladde - kan redigeres']);
        $q($cust(1), 'sent', dx_date(-27), dx_date(3), [$pline(3, 2)]);                                 // udløber om 3 dage
        $q($cust(2), 'sent', dx_date(-40), dx_date(-10), [$pline(4, 1), $pline(5, 2)]);                 // udløbet - opfølgning
        $q($cust(3), 'accepted', dx_date(-12), dx_date(18), [$pline(6, 1)], ['accepted_at' => dx_date(-8) . ' 12:00:00']);
        $q($cust(4), 'rejected', dx_date(-20), dx_date(10), [$pline(7, 3)], ['rejected_at' => dx_date(-15) . ' 09:00:00']);
        $inv = dx_invoice($conn, $cust(5), dx_date(-3), dx_date(5), [$pline(8, 4)]);
        $q($cust(5), 'converted', dx_date(-9), dx_date(21), [$pline(8, 4)], ['converted_invoice_id' => $inv, 'accepted_at' => dx_date(-4) . ' 10:00:00']);
    });

    // 8. Udgifter: betalt, skyldig (betalingsfil), forfalden, splittet, indtægt, kladde
    $step('Udgifter (betalt, skyldig, forfalden, splittet, indtægt, kladde)', function () use ($conn, &$ids) {
        $exp = fn(int $a) => tc_account_ok($conn, $a) ? $a : (int)(DB::fetch_row(DB::query($conn, "SELECT MIN(acc_id) FROM accounts WHERE acc_type = 'expense'"))[0]);
        dx_expense($conn, ['date' => dx_date(-55), 'supplier' => 'Klokkestøberiet ApS', 'supplier_id' => $ids['sup_bell'] ?? null, 'account_id' => $exp(2200), 'amount' => 12500, 'desc' => 'Husleje værksted', 'paid' => true]);
        dx_expense($conn, ['date' => dx_date(-10), 'supplier' => 'Klokkestøberiet ApS', 'supplier_id' => $ids['sup_bell'] ?? null, 'account_id' => $exp(2220), 'amount' => 3750, 'desc' => 'Reparation af vindebro', 'due' => dx_date(4)]);
        dx_expense($conn, ['date' => dx_date(-40), 'supplier' => 'Trolden Transport', 'supplier_id' => $ids['sup_troll'] ?? null, 'account_id' => $exp(2400), 'amount' => 2200, 'vat' => 0, 'desc' => 'Fragt - forfalden', 'due' => dx_date(-10)]);
        dx_expense($conn, ['date' => dx_date(-8), 'supplier' => 'Heksens Urtehandel', 'supplier_id' => $ids['sup_witch'] ?? null, 'account_id' => $exp(2100), 'amount' => 980, 'desc' => 'Urter (ingen bankoplysninger)', 'due' => dx_date(0)]);
        dx_expense($conn, ['date' => dx_date(-20), 'supplier' => 'Kongerigets Kontorforsyning', 'account_id' => $exp(2300), 'amount' => 1500, 'desc' => 'Kontor og markedsføring (splittet)', 'paid' => true,
                           'lines' => [[$exp(2300), 1000, 'Papir og blæk'], [$exp(2500), 500, 'Plakater']]]);
        dx_expense($conn, ['date' => dx_date(-6), 'supplier' => 'Kongens Tilskudskontor', 'account_id' => (int)setting('conf_acc_sales', 1000), 'amount' => 4000, 'vat' => 0, 'desc' => 'Anden indtægt', 'paid' => true, 'type' => 'income']);
        dx_insert($conn, 'expenses', ['exp_date' => dx_date(-1), 'supplier' => 'Heksens Urtehandel', 'supplier_id' => $ids['sup_witch'] ?? null, 'account_id' => $exp(2100),
            'amount' => 450, 'vat_rate' => 25, 'description' => 'Kladde - mangler bilag', 'currency' => setting('currency', 'DKK'), 'exp_type' => 'expense', 'created_by' => 1, 'is_cancelled' => 0]);
    });

    // 9. Faste fakturaer og udgifter (næste kørsel ude i fremtiden - dannes ikke med det samme)
    $step('Faste fakturaer og faste udgifter', function () use ($conn, $cust, $prod, &$ids) {
        dx_need_table($conn, 'recurring_invoices');
        $r = dx_insert($conn, 'recurring_invoices', ['cust_id' => $cust(1), 'interval_type' => 'monthly', 'next_run_date' => dx_date(10), 'is_active' => 1,
            'inv_due_days' => 14, 'inv_note' => 'Månedligt abonnement på eventyrkasser', 'created_by' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $p = $prod(0);
        dx_insert($conn, 'recurring_invoice_lines', ['recur_id' => $r, 'line_text' => $p['prod_name'], 'quantity' => 2, 'price_each' => $p['prod_price'], 'line_vat_rate' => 25, 'prod_id' => $p['prod_id']]);
        dx_insert($conn, 'recurring_invoices', ['cust_id' => $cust(2), 'interval_type' => 'quarterly', 'next_run_date' => dx_date(45), 'is_active' => 0,
            'inv_due_days' => 8, 'inv_note' => 'Sat på pause', 'created_by' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $a = tc_account_ok($conn, 2200) ? 2200 : (int)(DB::fetch_row(DB::query($conn, "SELECT MIN(acc_id) FROM accounts WHERE acc_type = 'expense'"))[0]);
        dx_insert($conn, 'recurring_expenses', ['supplier' => 'Klokkestøberiet ApS', 'supplier_id' => $ids['sup_bell'] ?? null, 'account_id' => $a, 'amount' => 12500,
            'description' => 'Husleje værksted', 'exp_type' => 'expense', 'interval_type' => 'monthly', 'next_run_date' => dx_date(20), 'is_active' => 1, 'created_by' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    });

    // 10. Projekt med timer (fakturerbare, ikke-fakturerbare, allerede faktureret)
    $step('Projekt med timeregistreringer', function () use ($conn, $cust) {
        dx_need_table($conn, 'time_entries');
        $p = dx_insert($conn, 'projects', ['proj_no' => 'DEMO-' . date('Y') . '-01', 'cust_id' => $cust(0), 'proj_start' => dx_date(-60), 'proj_stop' => dx_date(60),
            'proj_description' => 'Ombygning af slottet (demo)', 'is_active' => 1, 'default_hourly_rate' => 850]);
        $u = (int)(DB::fetch_row(DB::query($conn, "SELECT MIN(user_id) FROM users"))[0] ?? 1);
        foreach ([[-20, 'Opmåling af tårnet', 3.5, 1], [-15, 'Tegninger', 6, 1], [-12, 'Intern planlægning', 2, 0], [-6, 'Byggeledelse', 7.5, 1], [-2, 'Møde med kongen', 1.5, 1]] as [$d, $t, $h, $b]) {
            dx_insert($conn, 'time_entries', ['proj_id' => $p, 'user_id' => $u, 'entry_date' => dx_date($d), 'description' => $t, 'hours' => $h, 'hourly_rate' => 850,
                'line_vat_rate' => 25, 'is_billable' => $b, 'is_invoiced' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        }
        dx_expense($conn, ['date' => dx_date(-18), 'supplier' => 'Trolden Transport', 'account_id' => tc_account_ok($conn, 2400) ? 2400 : (int)DB::fetch_row(DB::query($conn, "SELECT MIN(acc_id) FROM accounts WHERE acc_type = 'expense'"))[0],
            'amount' => 1250, 'desc' => 'Kørsel til slottet', 'paid' => true, 'proj_id' => $p]);
    });

    // 11. Budgetter, linjeskabelon
    $step('Budgetter for i år og en linjeskabelon', function () use ($conn, $prod) {
        $y = (int)date('Y');
        foreach ([[1000, 250000], [2200, 150000], [2300, 12000], [2500, 20000]] as [$a, $b]) {
            if (tc_account_ok($conn, $a)) dx_insert($conn, 'account_budgets', ['acc_id' => $a, 'year' => $y, 'budget_amount' => $b]);
        }
        $t = dx_insert($conn, 'invoice_line_templates', ['name' => 'Eventyrpakke (standard)', 'created_by' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        foreach ([0, 1] as $n) { $p = $prod($n); dx_insert($conn, 'invoice_line_template_items', ['template_id' => $t, 'line_text' => $p['prod_name'], 'quantity' => 1, 'price_each' => $p['prod_price'], 'line_vat_rate' => 25, 'prod_id' => $p['prod_id'], 'discount_pct' => $n ? 10 : 0]); }
    });

    // Markøren sættes kun, når intet manglede - så kan scriptet køres igen efter migrationen og tage resten
    if ($skip === 0) $set(DX_MARKER, '1 (' . date('Y-m-d') . ')', false);
    $log("Færdig: $ok scenarier oprettet, $skip sprunget over, $err fejlede.");
    return [$ok, $err];
}
