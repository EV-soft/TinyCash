<?php # /customer_statements.php v:1.0.0 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere"): mulighed
# for at sende kontoudtog til ALLE kunder med en åben saldo i én omgang.
# customer_statement_send.php kunne FØR kun sende ét kontoudtog ad gangen,
# manuelt fra hver enkelt kundes egen customer_statement.php - samme
# "no bulk action"-hul som reminders.php/quote_followup.php allerede lukkede
# for rykkere/tilbudsopfølgning. Genbruger den fælles
# get_customer_statement_data() (inc/customer_statement.inc.php) til
# saldoberegningen, og send_single_statement() (customer_statement_send.php)
# til selve afsendelsen - ingen ny forretningslogik, kun listevisningen her.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/customer_statement.inc.php';

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

htm_Header('@Account Statements');
showMenu();

if (isset($_GET['msg']) && $_GET['msg'] === 'bulk') {
    $b_sent   = (int)($_GET['sent'] ?? 0);
    $b_failed = (int)($_GET['failed'] ?? 0);
    if ($b_failed > 0) {
        htm_Alert(sprintf(lang('@%d statement(s) sent. %d could not be sent (no email address, or a mail error) - please check them individually.'), $b_sent, $b_failed), 'warning');
    } else {
        htm_Alert(sprintf(lang('@%d statement(s) sent successfully.'), $b_sent), 'success');
    }
}

htm_Card_(capt: '@Account Statements', wdth: 1100);

echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">' .
    lang('@Customers with an open balance. Send an account statement directly from here, one at a time or in bulk.') .
    '</p>';

// Kandidater: kunder med mindst én bogført faktura og en mailadresse - resten
// filtreres fra nedenfor (saldo <= 0), samme "hent kandidater, filtrér i PHP"-
// tilgang som reminders.php's egen "reelt allerede betalt"-filtrering.
$res = DB::query($conn, "SELECT DISTINCT c.cust_id, c.cust_name, c.cust_email
    FROM customers c JOIN invoices i ON i.cust_id = c.cust_id
    WHERE LOWER(i.inv_status) != 'draft'
    ORDER BY c.cust_name ASC");

$headers = ['&nbsp;<input type="checkbox" id="bulkSelectAll" onchange="toggleAllStatements(this.checked)" data-hint="' . htmlspecialchars(lang('@Select all')) . '">',
    '@Customer', '@Email', '@Outstanding Balance', '@Overdue Invoices', '@Actions'];
$data = [];

if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $stmt = get_customer_statement_data($conn, (int)$row['cust_id']);
        if (!$stmt || $stmt['total_outstanding'] <= 0.01) continue; // ingen åben saldo - intet at rykke/sende for

        $cid = (int)$row['cust_id'];
        $has_email = !empty($row['cust_email']);
        $bal_color = $stmt['overdue_invoices'] > 0 ? 'var(--color-danger)' : 'var(--color-warning)';

        $send_btn = $has_email
            ? '<a href="customer_statement_send.php?id=' . $cid . '" data-hint="' . lang('@Email the account statement to the customer (you are asked first)') . '" '
              . 'onclick="return confirm(\'' . addslashes(sprintf(lang('@Send an account statement to %s?'), $row['cust_email'])) . '\')" '
              . 'style="background:var(--color-primary); color:#fff; padding:6px 14px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:0.85em;">'
              . '<i class="fa-solid fa-envelope"></i> ' . lang('@Send Statement') . '</a>'
            : '<span style="color:var(--text-muted); font-size:0.85em;">' . lang('@No email address') . '</span>';

        $data[] = [
            $has_email
                ? '<input type="checkbox" class="bulk-statement-checkbox" value="' . $cid . '" onchange="updateStatementSelectedCount()">'
                : '',
            '<a href="customer_statement.php?id=' . $cid . '">' . htmlspecialchars($row['cust_name']) . '</a>',
            $has_email ? htmlspecialchars($row['cust_email']) : '<span style="color:var(--color-danger);">' . lang('@Missing') . '</span>',
            '<span style="color:'.$bal_color.'; font-weight:bold;">' . number_format($stmt['total_outstanding'], 2, ',', '.') . ' ' . $cur . '</span>',
            $stmt['overdue_invoices'] > 0
                ? '<span style="color:var(--color-danger); font-weight:bold;">' . $stmt['overdue_invoices'] . '</span>'
                : '0',
            $send_btn,
        ];
    }
}

if (empty($data)) {
    echo "<p style='padding:30px; text-align:center; color:var(--text-muted);'>" . lang('@No customers with an open balance.') . "</p>";
} else {
    $bulk_html = '<button type="button" id="bulkStatementBtn" onclick="submitBulkStatements()" disabled '
        . 'style="background:var(--color-primary); color:#fff; border:none; padding:6px 14px; '
        . 'border-radius:4px; font-weight:bold; cursor:pointer; font-size:13px; opacity:0.5;">'
        . '<i class="fa-solid fa-envelope-circle-check"></i> ' . lang('@Send Selected Statements')
        . ' (<span id="bulkStatementCount">0</span>)</button>';
    htm_Table($headers, $data, 'statementsTbl', 100, $bulk_html, true, [], '600px');
    ?>
    <form id="bulkStatementForm" method="post" action="customer_statement_send.php" style="display:none;"><?php csrf_field(); ?></form>
    <script>
    function updateStatementSelectedCount() {
        var n = document.querySelectorAll('.bulk-statement-checkbox:checked').length;
        document.getElementById('bulkStatementCount').innerText = n;
        var btn = document.getElementById('bulkStatementBtn');
        btn.disabled = (n === 0);
        btn.style.opacity = (n === 0) ? '0.5' : '1';
    }
    function toggleAllStatements(checked) {
        document.querySelectorAll('.bulk-statement-checkbox').forEach(function(cb) { cb.checked = checked; });
        updateStatementSelectedCount();
    }
    function submitBulkStatements() {
        var checked = document.querySelectorAll('.bulk-statement-checkbox:checked');
        if (checked.length === 0) return;
        var confirmMsg = <?php echo json_encode(lang('@Send an account statement for %d selected customer(s)?')); ?>.replace('%d', checked.length);
        if (!confirm(confirmMsg)) return;
        var form = document.getElementById('bulkStatementForm');
        checked.forEach(function(cb) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = cb.value;
            form.appendChild(inp);
        });
        form.submit();
    }
    </script>
    <?php
}

htm_Card_end();
htm_Footer();
ob_end_flush();
?>
