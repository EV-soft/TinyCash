<?php # /inc/accounts.inc.php v:1.3.1 d:2026-09-15 i:evs
# Kontoplanens synlighed (bruger-anmodet: "alle mulige konti - tænd for dem man bruger") og Erhvervsstyrelsens
# standardkontoplan.
#
# accounts.acc_status:
#   'active' - vises normalt i valglisterne
#   'dimmed' - vises nederst i valglisterne, mærket "sjældent brugt" - kan stadig vælges
#   'off'    - vises ikke i valglisterne (men i rapporter, årsrapport og SAF-T som altid)
# En konto der allerede er valgt på en post, vises ALTID i den posts valgliste (acc_visible_sql($keep)) -
# ellers ville en gemt udgift stille skifte konto ved næste gem. Konti som indstillingerne / en webbutik
# bogfører på automatisk, kan ikke slukkes, kun dæmpes (acc_protected_ids()).
#
# acc_import_standard(): Erhvervsstyrelsens standardkontoplan (json-data/standardkontoplan_2026.json, hentet
# fra git.erst.dk/standard-filformater) - kontiene oprettes SLUKKET med standardnummer og std_ref_id = deres
# eget nummer; et nummer der allerede bruges af en af dine konti springes over. std_accounts (SAF-T-
# mappingens mål) udskiftes med den officielle liste.

const ACC_STATUSES = ['active', 'dimmed', 'off'];
const ACC_STD_CHART_FILE = __DIR__ . '/../json-data/standardkontoplan_2026.json';

function acc_status_ready($conn): bool {
    static $ready = null;
    if ($ready === null) {
        $ready = false;
        if (DB::is_sqlite()) {
            $r = DB::query($conn, "PRAGMA table_info(accounts)");
            if ($r) while ($c = DB::fetch_assoc($r)) if ($c['name'] === 'acc_status') { $ready = true;  break; }
        } else {
            $r = DB::query($conn, "SHOW COLUMNS FROM accounts LIKE 'acc_status'");
            $ready = $r && DB::num_rows($r) > 0;
        }
    }
    return $ready;
}

// Kolonnen (idempotent) - kaldt af db-setup/migrate_account_status.php og create_all_tables_for()
function acc_ensure_schema($conn, bool $is_sqlite): array {
    $has = false;
    if ($is_sqlite) {
        $r = DB::query($conn, "PRAGMA table_info(accounts)");
        if ($r) while ($c = DB::fetch_assoc($r)) if ($c['name'] === 'acc_status') { $has = true;  break; }
    } else {
        $r = DB::query($conn, "SHOW COLUMNS FROM accounts LIKE 'acc_status'");
        $has = $r && DB::num_rows($r) > 0;
    }
    if ($has) return ["[SPRUNGET OVER] Kolonnen 'acc_status' findes allerede på accounts."];
    $ok = DB::query($conn, "ALTER TABLE accounts ADD COLUMN acc_status " . ($is_sqlite ? "TEXT DEFAULT 'active'" : "VARCHAR(10) NOT NULL DEFAULT 'active'"));
    if (!$ok) return ["[FEJL] accounts.acc_status: " . DB::error($conn)];
    DB::query($conn, "UPDATE accounts SET acc_status = 'active' WHERE acc_status IS NULL");
    return ["[OK] Kolonnen 'acc_status' tilføjet til accounts - alle nuværende konti er aktive."];
}

// SQL: kontoen må vises i en valgliste (ikke slukket) - eller er en af $keep (allerede valgt på posten)
function acc_visible_sql($conn, string $alias = '', array $keep = []): string {
    if (!acc_status_ready($conn)) return '1=1';
    $keep = array_values(array_filter(array_map('intval', $keep), fn($k) => $k > 0));
    return "(COALESCE({$alias}acc_status, 'active') <> 'off'" . ($keep ? " OR {$alias}acc_id IN (" . implode(',', $keep) . ")" : '') . ")";
}
// SQL: dæmpede konti nederst (sættes foran den eksisterende ORDER BY)
function acc_order_sql($conn, string $alias = ''): string {
    return acc_status_ready($conn) ? "CASE WHEN COALESCE({$alias}acc_status, 'active') = 'dimmed' THEN 1 ELSE 0 END, " : '';
}
// SQL: kolonnen med i SELECT (til acc_label())
function acc_status_col($conn, string $alias = ''): string {
    return acc_status_ready($conn) ? ", {$alias}acc_status" : '';
}
// Teksten i valglisten: navnet - en dæmpet konto mærkes
function acc_label(string $name, ?string $status): string {
    if ($status === 'dimmed') return $name . ' · ' . lang('@rarely used');
    if ($status === 'off')    return $name . ' · ' . lang('@switched off');
    return $name;
}

// Konti som TinyCash bogfører på automatisk: [acc_id => hvorfor] - de kan ikke slukkes
function acc_protected_ids($conn): array {
    $out = [];
    $res = DB::query($conn, "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'conf_acc_%'");
    if ($res) while ($r = DB::fetch_assoc($res)) if ((int)$r['setting_value'] > 0) $out[(int)$r['setting_value']] = lang('@used by the settings') . ' (' . $r['setting_key'] . ')';
    if (function_exists('shop_ready') || is_file(__DIR__ . '/shop.inc.php')) {
        require_once __DIR__ . '/shop.inc.php';
        if (shop_ready($conn)) {
            $res = DB::query($conn, "SELECT name, clearing_acc, sales_acc, shipping_acc FROM shop_connections WHERE active = 1");
            if ($res) while ($r = DB::fetch_assoc($res)) foreach (['clearing_acc', 'sales_acc', 'shipping_acc'] as $k) {
                if ((int)$r[$k] > 0) $out[(int)$r[$k]] = lang('@used by the web shop') . ' ' . $r['name'];
            }
        }
    }
    return $out;
}

function acc_balance($conn, int $acc_id): float {
    $r = DB::fetch_assoc(DB::query($conn, "SELECT COALESCE(SUM(amount), 0) AS b FROM ledger WHERE acc_id = $acc_id"));
    return round((float)($r['b'] ?? 0), 2);
}

// Skift status. ['ok' => bool, 'msg' => tekst, 'confirm' => true når en saldo kræver et "alligevel"]
function acc_set_status($conn, int $acc_id, string $status, bool $force = false): array {
    if (!acc_status_ready($conn)) return ['ok' => false, 'msg' => lang('@The account visibility needs a database update (Database migration).')];
    if (!in_array($status, ACC_STATUSES, true)) return ['ok' => false, 'msg' => 'Ukendt status.'];
    $acc = DB::fetch_assoc(DB::query($conn, "SELECT acc_id, acc_name, acc_type, acc_status FROM accounts WHERE acc_id = $acc_id"));
    if (!$acc) return ['ok' => false, 'msg' => lang('@Account not found')];
    if ($status === 'off') {
        $prot = acc_protected_ids($conn);
        if (isset($prot[$acc_id])) return ['ok' => false, 'msg' => sprintf(lang('@The account %d cannot be switched off - it is %s. It can be dimmed.'), $acc_id, $prot[$acc_id])];
        // RETTET: kun balancekonti - en indtægts- / udgiftskonto har altid en saldo (den samler årene), så
        // advarslen kom hver gang og betød ingenting
        $bal = in_array($acc['acc_type'], ['income', 'expense'], true) ? 0.0 : acc_balance($conn, $acc_id);
        if (abs($bal) >= 0.005 && !$force) {
            return ['ok' => false, 'confirm' => true, 'msg' => sprintf(lang('@The account %d has a balance of %s. Normally a balance is cleared first - switch it off anyway?'), $acc_id, number_format($bal, 2, ',', '.'))];
        }
    }
    DB::query($conn, "UPDATE accounts SET acc_status = '$status' WHERE acc_id = $acc_id");
    if (function_exists('log_action')) log_action($conn, 'ACCOUNT_STATUS', 'accounts', $acc_id, ['acc_status' => $acc['acc_status']], ['acc_status' => $status]);
    return ['ok' => true, 'msg' => ''];
}

// std_accounts (SAF-T: StandardAccountID) = den officielle liste. Kaldes inde i en transaktion. Returnerer antallet
function acc_fill_std_table($conn, array $doc): int {
    DB::query($conn, "DELETE FROM std_accounts");
    $n = 0;
    foreach ($doc['accounts'] as $a) {
        if (!DB::insert($conn, 'std_accounts', ['std_id' => (int)$a['no'], 'std_name' => $a['name'], 'std_type' => $a['std_type']])) throw new Exception(DB::error($conn));
        $n++;
    }
    return $n;
}

// Erhvervsstyrelsens standardkontoplan: ['added' => n, 'skipped' => [nr => dit kontonavn], 'std' => n, 'reset' => n]
function acc_import_standard($conn): array {
    $doc = json_decode((string)@file_get_contents(ACC_STD_CHART_FILE), true);
    if (!is_array($doc) || empty($doc['accounts'])) throw new Exception('Standardkontoplanen (json-data/standardkontoplan_2026.json) kan ikke læses.');
    $mine = [];
    $is_std = [];
    $res = DB::query($conn, "SELECT acc_id, acc_name, std_ref_id FROM accounts");
    while ($r = DB::fetch_assoc($res)) { $mine[(int)$r['acc_id']] = $r['acc_name'];  $is_std[(int)$r['acc_id']] = (int)($r['std_ref_id'] ?? 0) === (int)$r['acc_id']; }
    $vat_ok = [];
    $res = DB::query($conn, "SELECT vat_id FROM vat_codes");
    if ($res) while ($r = DB::fetch_assoc($res)) $vat_ok[(string)$r['vat_id']] = true;
    $out = ['added' => 0, 'skipped' => [], 'std' => 0, 'reset' => 0];
    DB::begin_transaction($conn);
    try {
        // std_accounts (SAF-T: StandardAccountID) = den officielle liste
        $ids = implode(',', array_map(fn($a) => (int)$a['no'], $doc['accounts']));
        $dang = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM accounts WHERE std_ref_id IS NOT NULL AND std_ref_id NOT IN ($ids)"));
        $out['reset'] = (int)($dang[0] ?? 0);
        DB::query($conn, "UPDATE accounts SET std_ref_id = NULL WHERE std_ref_id IS NOT NULL AND std_ref_id NOT IN ($ids)");
        $out['std'] = acc_fill_std_table($conn, $doc);
        // Kontiene - slukket, med standardnummer og -reference
        foreach ($doc['accounts'] as $a) {
            $no = (int)$a['no'];
            if (isset($mine[$no])) { if (empty($is_std[$no])) $out['skipped'][$no] = $mine[$no];  continue; }      // allerede standardkontoen (fx fra en skabelon): ikke "sprunget over"
            $row = ['acc_id' => $no, 'acc_name' => $a['name'], 'acc_type' => $a['type'], 'std_ref_id' => $no, 'acc_status' => 'off'];
            if (isset($a['vat_rate'])) $row['vat_rate'] = (float)$a['vat_rate'];
            if (isset($a['vat_code']) && isset($vat_ok[$a['vat_code']])) $row['vat_code'] = $a['vat_code'];
            if (!DB::insert($conn, 'accounts', $row)) throw new Exception(DB::error($conn));
            $out['added']++;
        }
        if (function_exists('log_action')) log_action($conn, 'IMPORT_STANDARD_CHART', 'accounts', 0, null,
            ['valid_from' => $doc['valid_from'] ?? '', 'added' => $out['added'], 'skipped' => count($out['skipped']), 'std_accounts' => $out['std'], 'reset_mappings' => $out['reset']]);
        DB::commit($conn);
    } catch (Throwable $e) {
        DB::rollback($conn);
        throw $e;
    }
    return $out;
}
