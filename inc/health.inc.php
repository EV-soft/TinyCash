<?php # /inc/health.inc.php v:1.3.1 d:2026-09-15 i:evs
// Systemsundhed: de kontroller der ellers ligger spredt på hver sin side, samlet til forsidens boks for administratoren -
// så et halvt bilag, en afstemningsdifference, en manglende migration eller friske fejl i loggen ses med det samme i
// stedet for først når nogen tilfældigvis åbner den rigtige side. Hver kontrol bruger samme beregning som sin egen side.
require_once __DIR__ . '/migrations.inc.php';

// Debitor-/kreditorafstemningen (samme tal som subledger_reconciliation.php, som også bruger denne funktion)
function subledger_figures($conn): array {
    $acc_deb = (int)setting('conf_acc_debitor', 8100);
    $acc_cre = (int)setting('conf_acc_creditor', 4000);
    // Debitor: alle bogførte fakturaers restbeløb MED fortegn (invoice_open_sql()) - en krediteret faktura kan skylde et
    // rykkergebyr, og en delbetalt der krediteres fuldt, er et tilgodehavende; begge står på debitorkontoen
    $r = DB::query($conn, "SELECT COALESCE(SUM(" . invoice_open_sql($conn) . "), 0) FROM invoices i WHERE " . invoice_open_where());
    $deb_sub = round((float)(($r ? DB::fetch_row($r) : null)[0] ?? 0), 2);
    $deb_gl  = round((float)(DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(amount), 0) FROM ledger WHERE acc_id = $acc_deb"))[0] ?? 0), 2);
    // Kreditor: bogførte, ubetalte udgifter med forfaldsdato (leverandørmodulet); kreditorkontoen er kredit-normal
    $res_e = @DB::query($conn, "SELECT COALESCE(SUM(e.amount), 0) FROM expenses e
        WHERE e.is_cancelled = 0 AND e.voucher_no IS NOT NULL AND (e.exp_type IS NULL OR e.exp_type != 'income') AND e.due_date IS NOT NULL AND e.paid_date IS NULL");
    $cre_ok  = ($res_e !== false);
    $cre_sub = $cre_ok ? round((float)(DB::fetch_row($res_e)[0] ?? 0), 2) : 0.0;
    $cre_gl  = $cre_ok ? -round((float)(DB::fetch_row(DB::query($conn, "SELECT COALESCE(SUM(amount), 0) FROM ledger WHERE acc_id = $acc_cre"))[0] ?? 0), 2) : 0.0;
    return ['acc_deb' => $acc_deb, 'deb_sub' => $deb_sub, 'deb_gl' => $deb_gl, 'deb_diff' => round($deb_sub - $deb_gl, 2),
            'acc_cre' => $acc_cre, 'cre_ok' => $cre_ok, 'cre_sub' => $cre_sub, 'cre_gl' => $cre_gl, 'cre_diff' => round($cre_sub - $cre_gl, 2)];
}

// Friske fejl: linjer i system_errors.log fra de sidste $hours timer (kun halen af filen læses)
function health_recent_errors(int $hours = 24): int {
    $f = ini_get('error_log');
    if (!$f || !is_readable($f)) return 0;
    $size = filesize($f);  $fh = @fopen($f, 'r');
    if (!$fh) return 0;
    if ($size > 65536) fseek($fh, -65536, SEEK_END);
    $tail = stream_get_contents($fh);  fclose($fh);
    $since = time() - $hours * 3600;  $n = 0;
    foreach (explode("\n", $tail) as $line) {
        if (preg_match('/^\[([^\]]+)\]/', $line, $m) && ($ts = strtotime($m[1])) !== false && $ts >= $since) $n++;
    }
    return $n;
}

// Alle kontroller: [['status' => ok|warn|error, 'text' => ..., 'link' => side], ...]. Gemmes 10 minutter i sessionen
// (forsiden skal ikke regne hele debitorsaldoen ved hver visning); $refresh regner forfra.
function tc_health($conn, bool $refresh = false): array {
    $key = 'tc_health_' . ($_SESSION['account_id'] ?? '');
    if (!$refresh && isset($_SESSION[$key]) && $_SESSION[$key]['at'] > time() - 600) return $_SESSION[$key]['items'];
    $items = [];
    $add = function (string $status, string $text, string $link) use (&$items) { $items[] = compact('status', 'text', 'link'); };
    $cur = setting('currency', 'DKK');
    $q1 = fn($sql) => (DB::fetch_row(DB::query($conn, $sql)) ?: [0])[0];

    // 1. Bilag der ikke balancerer, og journalposter helt uden posteringer (et halvt bilag)
    $unbal = (int)$q1("SELECT COUNT(*) FROM (SELECT jou_id FROM ledger GROUP BY jou_id HAVING ABS(SUM(amount)) > 0.005) x");
    $empty = (int)$q1("SELECT COUNT(*) FROM journal j WHERE NOT EXISTS (SELECT 1 FROM ledger l WHERE l.jou_id = j.jou_id)");
    if ($unbal || $empty) $add('error', sprintf(lang('@%d voucher(s) do not balance and %d journal entr(y/ies) have no postings.'), $unbal, $empty), 'entry_balance_check.php');
    else $add('ok', lang('@Every voucher balances.'), 'entry_balance_check.php');

    // 2. Hovedbogen som helhed (debet = kredit)
    $total = round((float)$q1("SELECT COALESCE(SUM(amount), 0) FROM ledger"), 2);
    if (abs($total) >= 0.005) $add('error', sprintf(lang('@The general ledger does not sum to zero (difference %s).'), fmt_dk($total) . ' ' . $cur), 'trial_balance.php');
    else $add('ok', lang('@The general ledger sums to zero (debit = credit).'), 'trial_balance.php');

    // 3. Debitor- og kreditorafstemning
    $s = subledger_figures($conn);
    if (abs($s['deb_diff']) >= 0.005) $add('error', sprintf(lang('@Debtors: the open invoices and account %d differ by %s.'), $s['acc_deb'], fmt_dk($s['deb_diff']) . ' ' . $cur), 'subledger_reconciliation.php');
    elseif ($s['cre_ok'] && abs($s['cre_diff']) >= 0.005) $add('warn', sprintf(lang('@Creditors: the unpaid expenses and account %d differ by %s.'), $s['acc_cre'], fmt_dk($s['cre_diff']) . ' ' . $cur), 'subledger_reconciliation.php');
    else $add('ok', lang('@Debtors and creditors match the general ledger.'), 'subledger_reconciliation.php');

    // 4. Migrationer der ikke er kørt
    $pend = migrations_pending($conn);
    if ($pend) $add('warn', sprintf(lang('@%d database migration(s) not yet applied: %s'), count($pend), implode(', ', array_map(fn($m) => $m['label'], array_slice($pend, 0, 3))) . (count($pend) > 3 ? ' ...' : '')), 'run_migrate.php');
    else $add('ok', lang('@All database migrations are applied.'), 'run_migrate.php');

    // 5. Friske fejl i fejlloggen
    $errs = health_recent_errors(24);
    if ($errs) $add('warn', sprintf(lang('@%d new line(s) in the error log in the last 24 hours.'), $errs), 'error_log.php');
    else $add('ok', lang('@No new errors in the error log in the last 24 hours.'), 'error_log.php');

    $_SESSION[$key] = ['at' => time(), 'items' => $items];
    return $items;
}

// Forsidens boks (kun administrator): én grøn linje når alt er i orden, ellers punkterne med links
function health_box($conn): void {
    if (($_SESSION['user_role'] ?? '') !== 'admin') return;
    $refresh = isset($_GET['health']) && $_GET['health'] === 'refresh';
    try { $items = tc_health($conn, $refresh); } catch (\Throwable $e) { error_log('[health] ' . $e->getMessage());  return; }
    $bad = array_values(array_filter($items, fn($i) => $i['status'] !== 'ok'));
    $icon = ['ok' => ['fa-circle-check', 'var(--color-success)'], 'warn' => ['fa-triangle-exclamation', 'var(--color-warning)'], 'error' => ['fa-circle-xmark', 'var(--color-danger)']];
    $again = ' <a href="index.php?health=refresh" style="font-size:0.85em;" data-hint="' . htmlspecialchars(lang('@Run the checks again now (they are otherwise reused for 10 minutes)')) . '">' . lang('@Check again') . '</a>';
    htm_Card_(capt: '@System Health', wdth: 1000);
    if (!$bad) {
        echo '<div style="display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-circle-check" style="color:var(--color-success);"></i> '
           . sprintf(lang('@Everything is in order (%d checks).'), count($items)) . $again . '</div>';
    } else {
        echo '<ul style="list-style:none; margin:0; padding:0;">';
        foreach ($bad as $i) {
            [$ic, $col] = $icon[$i['status']];
            echo '<li style="display:flex; align-items:baseline; gap:8px; padding:4px 0;"><i class="fa-solid ' . $ic . '" style="color:' . $col . ';"></i> <span>'
               . htmlspecialchars($i['text']) . ' <a href="' . htmlspecialchars($i['link']) . '">' . lang('@Open page') . '</a></span></li>';
        }
        echo '</ul><div style="margin-top:6px; font-size:0.9em; color:var(--text-muted);">' . sprintf(lang('@%d of %d checks are in order.'), count($items) - count($bad), count($items)) . $again . '</div>';
    }
    htm_Card_end();
}
