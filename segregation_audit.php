<?php # /segregation_audit.php v:1.3.1 d:2026-09-15 i:evs
# Adskillelse af funktioner (segregation of duties) - REVISIONSVÆRKTØJ, ikke
# adgangskontrol. Viser for hver bogført udgift/faktura hvem der OPRETTEDE
# den og hvem der BOGFØRTE den, og markerer tydeligt hvor det er samme
# bruger. Ændrer/forhindrer intet i selve posteringsflowsne - kun synlighed,
# bevidst valgt fremfor et reelt to-personers-godkendelseskø (bruger-anmodet
# scope: "kun et revisionsværktøj").
#
# Datakilder pr. flow (heterogene, da skemaet ikke ensartet sporer dette):
#  - Udgifter: expenses.created_by (direkte kolonne) mod audit_log's
#    POST_EXPENSE-post (log_action() fanger altid $_SESSION['user_id']).
#  - Fakturaer: invoices har INGEN created_by-kolonne overhovedet - bruger i
#    stedet audit_log's CREATE_DRAFT_INVOICE/POST_INVOICE-par (begge findes,
#    verificeret ved kodegennemgang af invoice_edit.php/invoice_post_action.php).
#  - Diverse Postering (journal_entry.php): ét-trins handling i sin
#    nuværende udformning - opretter og bogfører altid i samme kald, samme
#    bruger pr. definition. Vises separat som et rent oplysende antal, ikke
#    som en "overtrædelses"-liste, da der intet at adskille er i dette
#    flows nuværende design.
#  - Kreditnotaer, bankafstemning og årsafslutning er samme ét-trins-mønster
#    som Diverse Postering (bekræftet ved kodegennemgang af invoice_credit.php/
#    reconcile_action.php/year_end_close.php - hver logger opret+bogfør i én
#    transaktion) og er bevidst udeladt herunder for samme grund.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

// Henter, for hver row_id i $table, brugeren bag den FØRSTE audit_log-post af
// $action_type - bruges til både "hvem oprettede" og "hvem bogførte", alt
// efter hvilken action_type der forespørges på.
function sod_build_actor_map($conn, $table, $action_type) {
    $map = [];
    $res = DB::query($conn, "
        SELECT row_id, user_id FROM audit_log a
        WHERE table_name = '" . DB::escape($conn, $table) . "' AND action_type = '" . DB::escape($conn, $action_type) . "'
        AND log_id = (SELECT MIN(log_id) FROM audit_log b WHERE b.table_name = a.table_name AND b.action_type = a.action_type AND b.row_id = a.row_id)");
    if ($res) { while ($r = DB::fetch_assoc($res)) { $map[(int)$r['row_id']] = (int)$r['user_id']; } }
    return $map;
}

// Kontonavne-opslag til visning
$users = [];
$u_res = DB::query($conn, "SELECT user_id, username FROM users");
if ($u_res) { while ($u = DB::fetch_assoc($u_res)) { $users[(int)$u['user_id']] = $u['username']; } }
$user_count = count($users);

function sod_user_label(array $users, ?int $uid): string {
    if ($uid === null || $uid <= 0) return '<span style="color:var(--text-muted);">' . lang('@Unknown') . '</span>';
    return htmlspecialchars($users[$uid] ?? ('#' . $uid));
}
function sod_status_badge(?int $creator, ?int $poster): string {
    if ($creator === null || $poster === null || $creator <= 0 || $poster <= 0) {
        return '<span style="color:var(--text-muted);">' . lang('@Unknown') . '</span>';
    }
    // RETTET: rød / grøn tekst og ikon direkte på kortets baggrund var næsten ulæselig i mørkt tema (rød på
    // grå-brun). Nu et mærke med fast baggrund og hvid tekst - samme kontrast i begge temaer.
    $pill = 'display:inline-block; white-space:nowrap; padding:2px 9px; border-radius:10px; color:#fff; font-weight:bold; font-size:0.85em;';
    if ($creator === $poster) {
        return '<span style="' . $pill . ' background:#c0392b;"><i class="fa fa-triangle-exclamation"></i> ' . lang('@Same user') . '</span>';
    }
    return '<span style="' . $pill . ' background:#1e8449;"><i class="fa fa-check"></i> ' . lang('@Different users') . '</span>';
}

// --- UDGIFTER ---
$exp_poster_map = sod_build_actor_map($conn, 'expenses', 'POST_EXPENSE');
$exp_res = DB::query($conn, "SELECT exp_id, exp_date, description, voucher_no, created_by FROM expenses WHERE voucher_no IS NOT NULL ORDER BY exp_date DESC, exp_id DESC");
$exp_rows = [];
$exp_same = 0; $exp_total = 0;
if ($exp_res) {
    while ($r = DB::fetch_assoc($exp_res)) {
        $creator = !empty($r['created_by']) ? (int)$r['created_by'] : null;
        $poster  = $exp_poster_map[(int)$r['exp_id']] ?? null;
        $exp_rows[] = ['date' => $r['exp_date'], 'desc' => $r['description'], 'voucher' => $r['voucher_no'], 'creator' => $creator, 'poster' => $poster];
        $exp_total++;
        if ($creator !== null && $poster !== null && $creator === $poster) $exp_same++;
    }
}

// --- FAKTURAER ---
$inv_creator_map = sod_build_actor_map($conn, 'invoices', 'CREATE_DRAFT_INVOICE');
$inv_poster_map  = sod_build_actor_map($conn, 'invoices', 'POST_INVOICE');
$inv_res = DB::query($conn, "SELECT i.inv_id, i.invoice_no, i.inv_date, c.cust_name
    FROM invoices i JOIN customers c ON i.cust_id = c.cust_id
    WHERE i.invoice_no IS NOT NULL AND i.invoice_no != '' ORDER BY i.inv_date DESC, i.inv_id DESC");
$inv_rows = [];
$inv_same = 0; $inv_total = 0;
if ($inv_res) {
    while ($r = DB::fetch_assoc($inv_res)) {
        $creator = $inv_creator_map[(int)$r['inv_id']] ?? null;
        $poster  = $inv_poster_map[(int)$r['inv_id']] ?? null;
        $inv_rows[] = ['date' => $r['inv_date'], 'label' => $r['invoice_no'] . ' - ' . $r['cust_name'], 'creator' => $creator, 'poster' => $poster];
        $inv_total++;
        if ($creator !== null && $poster !== null && $creator === $poster) $inv_same++;
    }
}

// --- DIVERSE POSTERING (rent oplysende, ikke en overtrædelsesliste) ---
$manual_count_row = DB::fetch_assoc(DB::query($conn, "SELECT COUNT(*) AS c FROM journal WHERE trans_type = 'manual'"));
$manual_count = (int)($manual_count_row['c'] ?? 0);

htm_Header('@Segregation of Duties Audit');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

echo '<p style="color:var(--text-muted); margin-bottom:10px;">'
    . lang('@Shows, for each posted expense/invoice, who created it and who posted it, and flags cases where the same person did both - a classic segregation-of-duties (maker-checker) audit. This is READ-ONLY: it does not prevent or require anything, it only reports on what already happened. Credit notes, bank reconciliation, and year-end closing are single-step actions in the current design (always the same user by construction) and are not included below.')
    . '</p>';

if ($user_count <= 1) {
    htm_Alert(lang('@Only one user account exists in the system - segregation of duties is not possible until a second user is created.'), 'warning', 900, true);
}

// --- UDGIFTER ---
htm_Card_(lang('@Expenses'), 900);
if ($exp_total > 0) {
    echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:0;">' . sprintf(lang('@%d of %d expenses were posted by the same person who created them.'), $exp_same, $exp_total) . '</p>';
}
if (empty($exp_rows)) {
    echo '<p style="text-align:center; color:var(--text-muted); padding:20px;">' . lang('@No posted expenses found.') . '</p>';
} else {
    $headers = ['@Date', '@Description', '@Voucher No.', '@Created By', '@Posted By', '@Status'];
    $data = [];
    foreach ($exp_rows as $r) {
        $data[] = [
            date(CONF_DATE_FORMAT, strtotime($r['date'])),
            htmlspecialchars($r['desc']),
            $r['voucher'],
            sod_user_label($users, $r['creator']),
            sod_user_label($users, $r['poster']),
            sod_status_badge($r['creator'], $r['poster']),
        ];
    }
    htm_Table($headers, $data, 'sodExpTbl', 200, '', true, [], '400px');
}
htm_Card_end();

// --- FAKTURAER ---
htm_Card_(lang('@Invoices'), 900);
if ($inv_total > 0) {
    echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:0;">' . sprintf(lang('@%d of %d invoices were posted by the same person who created them.'), $inv_same, $inv_total) . '</p>';
}
if (empty($inv_rows)) {
    echo '<p style="text-align:center; color:var(--text-muted); padding:20px;">' . lang('@No posted invoices found.') . '</p>';
} else {
    $headers = ['@Date', '@Invoice', '@Created By', '@Posted By', '@Status'];
    $data = [];
    foreach ($inv_rows as $r) {
        $data[] = [
            date(CONF_DATE_FORMAT, strtotime($r['date'])),
            htmlspecialchars($r['label']),
            sod_user_label($users, $r['creator']),
            sod_user_label($users, $r['poster']),
            sod_status_badge($r['creator'], $r['poster']),
        ];
    }
    htm_Table($headers, $data, 'sodInvTbl', 200, '', true, [], '400px');
}
htm_Card_end();

// --- DIVERSE POSTERING (oplysende) ---
htm_Card_(lang('@Manual Journal Entries'), 900);
echo '<p style="color:var(--text-muted);">' . sprintf(lang('@Manual journal entries (Diverse Postering) are a single-step action in the current design - the creator and poster are always the same person. %d entries found.'), $manual_count) . '</p>';
htm_Card_end();

echo "</div>";
htm_Footer();
