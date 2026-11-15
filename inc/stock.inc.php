<?php # /inc/stock.inc.php v:1.0.0 d:2026-11-05 i:evs
// Lager: hver ændring af beholdningen går gennem stock_move() og logges i stock_movements - varemodtagelse (stock_receipt.php),
// optælling (stock_count.php), rettelse på varekortet, salg (bogført faktura), kreditnota og webbutikkens samlede dagssalg.
// Så kan varekortet forklare beholdningen, og beholdningen kan regnes tilbage til en dato (stock_qty_at()) - fx en
// optælling dateret 31/12 efter at der er solgt i januar. Lagerværdien (beholdning x kostpris) kan bogføres pr. en dato:
// varelagerkontoen (balance) sættes til værdien mod reguleringskontoen - så lageret står rigtigt i balancen/årsrapporten.
const STOCK_KINDS = ['receipt', 'count', 'manual', 'sale', 'credit', 'shop'];
function stock_kind_label(string $kind): string {
    return lang(['receipt' => '@Goods Receipt', 'count' => '@Stock Count', 'manual' => '@Changed on the product card', 'sale' => '@Sale',
                 'credit' => '@Credit Note', 'shop' => '@Web shop sale'][$kind] ?? '@Stock Count');
}

function stock_acc_inventory($conn): int { return (int)setting('conf_acc_inventory', 8150); }
function stock_acc_adjust($conn): int    { return (int)setting('conf_acc_stock_adjust', 2100); }
function stock_ready($conn): bool        { return db_has_table($conn, 'stock_movements'); }

// Tabel + varelagerkonto (idempotent). Returnerer linjer til kalderens udskrift.
function stock_ensure_schema($conn, bool $is_sqlite): array {
    $out = [];
    if (!db_has_table($conn, 'stock_movements')) {
        $ok = DB::query($conn, $is_sqlite
            ? "CREATE TABLE stock_movements (move_id INTEGER PRIMARY KEY AUTOINCREMENT, prod_id INTEGER NOT NULL, move_date TEXT NOT NULL,
                   qty REAL NOT NULL, kind TEXT NOT NULL, unit_cost REAL, supplier_id INTEGER, reference TEXT, note TEXT,
                   created_by INTEGER, created_at TEXT)"
            : "CREATE TABLE stock_movements (move_id INT AUTO_INCREMENT PRIMARY KEY, prod_id INT NOT NULL, move_date DATE NOT NULL,
                   qty DECIMAL(14,3) NOT NULL, kind VARCHAR(10) NOT NULL, unit_cost DECIMAL(14,2), supplier_id INT, reference VARCHAR(100), note VARCHAR(255),
                   created_by INT, created_at DATETIME, INDEX (prod_id)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out[] = $ok ? "[OK] Tabellen 'stock_movements' oprettet - varemodtagelse og lageroptælling logges." : "[FEJL] stock_movements: " . DB::error($conn);
        if ($ok) stock_log_from($conn);
    } else {
        $out[] = "[SPRUNGET OVER] Tabellen 'stock_movements' findes allerede.";
    }
    $acc = stock_acc_inventory($conn);
    if (tc_account_ok($conn, $acc)) {
        $out[] = "[SPRUNGET OVER] Varelagerkontoen $acc findes allerede.";
    } elseif (DB::insert($conn, 'accounts', ['acc_id' => $acc, 'acc_name' => 'Varelager', 'acc_type' => 'asset', 'vat_rate' => 0])) {
        $out[] = "[OK] Konto $acc 'Varelager' (aktiv) oprettet.";
    } else {
        $out[] = "[FEJL] Konto $acc: " . DB::error($conn);
    }
    return $out;
}

// Ændrer beholdningen og logger bevægelsen. $qty med fortegn.
function stock_move($conn, int $prod_id, float $qty, string $kind, string $date, array $extra = []): void {
    if (abs($qty) < 0.0005) return;
    if (!DB::query($conn, "UPDATE products SET prod_stock = COALESCE(prod_stock, 0) + " . round($qty, 3) . " WHERE prod_id = $prod_id")) throw new Exception(DB::error($conn));
    stock_log_move($conn, $prod_id, $qty, $kind, $date, $extra);
}

// Logger en bevægelse, hvor beholdningen allerede er ændret af kalderen (fx varekortet) - uden tabellen: intet
function stock_log_move($conn, int $prod_id, float $qty, string $kind, string $date, array $extra = []): void {
    if (abs($qty) < 0.0005 || !stock_ready($conn)) return;
    stock_log_from($conn);
    DB::insert($conn, 'stock_movements', ['prod_id' => $prod_id, 'move_date' => $date, 'qty' => round($qty, 3), 'kind' => $kind,
        'unit_cost' => $extra['unit_cost'] ?? null, 'supplier_id' => $extra['supplier_id'] ?? null, 'reference' => $extra['reference'] ?? null,
        'note' => $extra['note'] ?? null, 'created_by' => (int)($_SESSION['user_id'] ?? 0), 'created_at' => date('Y-m-d H:i:s')]);
}

// Fra denne dato er ALLE bevægelser logget (sat første gang der logges efter omlægningen; før loggede salg og kreditnotaer
// ikke). En beholdning kan kun regnes tilbage til en dato fra og med den - ellers mangler der bevægelser.
function stock_log_from($conn): string {
    $d = (string)setting('stock_log_from', '');
    if (tc_is_date($d)) return $d;
    $d = date('Y-m-d');
    DB::insert($conn, 'settings', ['setting_key' => 'stock_log_from', 'setting_value' => $d]);
    return $d;
}

// Beholdningen pr. vare ved udgangen af $date (null = nu): den aktuelle minus bevægelserne dateret efter. [prod_id => antal]
function stock_qty_at($conn, ?string $date = null): array {
    $out = [];
    $res = DB::query($conn, "SELECT prod_id, COALESCE(prod_stock, 0) AS s FROM products");
    while ($res && ($r = DB::fetch_assoc($res))) $out[(int)$r['prod_id']] = (float)$r['s'];
    if ($date !== null && $date < date('Y-m-d') && stock_ready($conn)) {
        $res = DB::query($conn, "SELECT prod_id, SUM(qty) AS q FROM stock_movements WHERE move_date > '" . DB::escape($conn, $date) . "' GROUP BY prod_id");
        while ($res && ($r = DB::fetch_assoc($res))) if (isset($out[(int)$r['prod_id']])) $out[(int)$r['prod_id']] = round($out[(int)$r['prod_id']] - (float)$r['q'], 3);
    }
    return $out;
}

// Lagerets værdi til kostpris (nu, eller ved udgangen af $date): [værdi, antal varer uden kostpris (tæller ikke med)].
// Kostprisen er den aktuelle - der føres ingen historik over kostpriser.
function stock_value($conn, ?string $date = null): array {
    if (!db_has_column($conn, 'products', 'prod_cost_price')) return [0.0, 0];
    $cost = [];
    $res = DB::query($conn, "SELECT prod_id, prod_cost_price FROM products");
    while ($res && ($r = DB::fetch_assoc($res))) $cost[(int)$r['prod_id']] = $r['prod_cost_price'];
    $v = 0.0;  $n = 0;
    foreach (stock_qty_at($conn, $date) as $pid => $q) {
        if ($q <= 0) continue;
        if ($cost[$pid] === null) { $n++; continue; }
        $v += $q * (float)$cost[$pid];
    }
    return [round($v, 2), $n];
}

// Bogfører lagerværdien pr. $date: varelagerkontoen sættes til $value (differencen mod dens saldo til og med datoen
// bogføres mod reguleringskontoen). Returnerer [bilagsnr., bogført difference]; 0-difference bogføres ikke.
// Atomisk: hele bilaget eller intet (indlejret i kalderens transaktion bliver det et savepoint) - se DB::transaction()
function stock_post_value($conn, float $value, string $date): array {
    return DB::transaction($conn, fn() => stock_post_value_steps($conn, $value, $date));
}
function stock_post_value_steps($conn, float $value, string $date): array {
    $inv = stock_acc_inventory($conn);  $adj = stock_acc_adjust($conn);
    if (!tc_account_ok($conn, $inv) || !tc_account_ok($conn, $adj)) throw new Exception(lang('@The inventory accounts are missing - check Company Settings (posting accounts) and run the database migration.'));
    if (is_date_locked($conn, $date)) throw new Exception(lang('@The date is in a locked accounting period.'));
    $d   = DB::escape($conn, $date);
    $bal = (float)(DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(l.amount), 0) FROM ledger l JOIN journal j ON j.jou_id = l.jou_id WHERE l.acc_id = $inv AND j.jou_date <= '$d'"))[0] ?? 0);
    $diff = round($value - $bal, 2);
    if (abs($diff) < 0.005) return [0, 0.0];
    $voucher = next_voucher_no($conn);
    $cb  = journal_created_by_sql($conn);
    $txt = DB::escape($conn, sprintf(lang('@Inventory value per %s'), date(CONF_DATE_FORMAT, strtotime($date))));
    DB::query($conn, "INSERT INTO journal (jou_date, jou_text, trans_type, voucher_no, currency{$cb['col']}) VALUES ('$d', '$txt', 'stock_value', $voucher, '" . DB::escape($conn, setting('currency', 'DKK')) . "'{$cb['val']})");
    $jou = (int)DB::insert_id($conn);
    ledger_post($conn, $jou, $inv, $diff);
    ledger_post($conn, $jou, $adj, -$diff);
    return [$voucher, $diff];
}
