<?php # /customer_data_export.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 27):
# ingen side kunne samle ALT om én kunde til ét dokument - relevant ved en
# indsigtsanmodning (GDPR art. 15). KUN adgang/eksport - INGEN sletning eller
# anonymisering: det ville reelt støde sammen med bogføringslovens 5-års
# opbevaringspligt for bogførte bilag (fakturaer/journal/ledger), så den del
# er bevidst IKKE bygget her. Samler stamdata + fakturaer + tilbud + faste
# fakturaer + mail-log-poster der matcher kundens mailadresse.
// RETTET: ingen adgangskontrol - enhver bruger (også niveau 1) kunne hente ALLE personoplysninger om en hvilken
// som helst kunde, inkl. mailloggen. Menuen markerer siden som administratorens (🔒); nu håndhævet.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

$cust_id = isset($_GET['cust_id']) ? (int)$_GET['cust_id'] : 0;

$cust_res = DB::query($conn, "SELECT cust_id, cust_name FROM customers ORDER BY cust_name ASC");
$cust_options = ['' => lang('@Select a customer') . '...'];
while ($cr = DB::fetch_assoc($cust_res)) { $cust_options[$cr['cust_id']] = $cr['cust_name']; }

function gdpr_collect_customer_data($conn, int $cust_id): ?array {
    $cust = DB::fetch_assoc(DB::query($conn, "SELECT * FROM customers WHERE cust_id = $cust_id"));
    if (!$cust) return null;

    $data = ['customer' => $cust, 'invoices' => [], 'quotes' => [], 'recurring_invoices' => [], 'sent_mail' => []];

    $disc = invoice_discount_sql($conn);
    $inv_res = DB::query($conn, "SELECT invoice_no, inv_date, inv_due_date, inv_status, currency,
            (SELECT COALESCE(SUM(quantity * price_each $disc * (100 + line_vat_rate) / 100.0), 0)
             FROM invoice_lines WHERE inv_id = i.inv_id) * COALESCE(NULLIF(i.exch_rate, 0), 1) AS total
        FROM invoices i WHERE cust_id = $cust_id ORDER BY inv_date DESC");
    while ($r = DB::fetch_assoc($inv_res)) { $data['invoices'][] = $r; }

    $q_res = @DB::query($conn, "SELECT quote_no, quote_date, valid_until, status FROM quotes WHERE cust_id = $cust_id ORDER BY quote_date DESC");
    if ($q_res) { while ($r = DB::fetch_assoc($q_res)) { $data['quotes'][] = $r; } }

    $ri_res = @DB::query($conn, "SELECT interval_type, next_run_date, is_active FROM recurring_invoices WHERE cust_id = $cust_id");
    if ($ri_res) { while ($r = DB::fetch_assoc($ri_res)) { $data['recurring_invoices'][] = $r; } }

    if (!empty($cust['cust_email'])) {
        $m_res = @DB::query($conn, "SELECT sent_at, subject, success FROM mail_log WHERE to_email = '" . DB::escape($conn, $cust['cust_email']) . "' ORDER BY sent_at DESC");
        if ($m_res) { while ($r = DB::fetch_assoc($m_res)) { $data['sent_mail'][] = $r; } }
    }

    // RETTET: kundens KONTAKTPERSONER (navne, mails, telefoner) og WEBBUTIKSORDRER (med kundens adresse, mail og
    // telefon i ordredata) manglede - en indsigtsanmodning (GDPR art. 15) blev besvaret ufuldstændigt
    $data['contacts'] = [];
    $c_res = @DB::query($conn, "SELECT * FROM customer_contacts WHERE cust_id = $cust_id");
    if ($c_res) { while ($r = DB::fetch_assoc($c_res)) { unset($r['cust_id']);  $data['contacts'][] = $r; } }
    $data['web_shop_orders'] = [];
    $o_res = @DB::query($conn, "SELECT o.kind, o.external_id, o.order_date, o.customer_name, o.gross, o.status, o.payload
                                FROM shop_orders o LEFT JOIN invoices i ON i.inv_id = o.inv_id
                                WHERE o.cust_id = $cust_id OR i.cust_id = $cust_id ORDER BY o.order_date DESC");
    if ($o_res) { while ($r = DB::fetch_assoc($o_res)) { $r['payload'] = json_decode((string)$r['payload'], true);  $data['web_shop_orders'][] = $r; } }

    return $data;
}

if (isset($_GET['download']) && $cust_id > 0) {
    $data = gdpr_collect_customer_data($conn, $cust_id);
    if ($data === null) { die(lang('@Customer not found')); }

    if (ob_get_level()) ob_end_clean();
    $safe_name = preg_replace('/[^A-Za-z0-9_-]/', '_', $data['customer']['cust_name']);
    $filename = 'GDPR_' . $safe_name . '_' . date('Y-m-d') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    // JSON_INVALID_UTF8_SUBSTITUTE: uden dette flag fejler json_encode() TAVST
    // (returnerer false, som blot ville blive echo'et som en tom 0-byte-fil
    // med HTTP 200 - selv-fanget live under test af cust_id=2, "Den Grimme
    // Ælling A/S", som viste sig at have en rå Latin-1-byte i navnet, se
    // §bookkeeper-wishlist-round26) i stedet for reelt ugyldig UTF-8-data.
    // Erstatter den ugyldige byte med Unicode-erstatningstegnet i stedet for
    // at fejle - eksporten skal virke for ENHVER kunde, uanset om deres
    // stamdata tilfældigvis er korrupt fra før.
    echo json_encode(['generated_at' => date('c'), 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

htm_Header('@Customer Data Export (GDPR)');
showMenu();

echo "<div style='max-width:900px; margin:0 auto; padding:10px;'>";
htm_Card_('@Customer Data Export (GDPR)', 900);
echo '<p style="color:var(--text-muted); font-size:0.9em; margin-top:-5px;">'
    . lang('@Collects everything the system holds about one customer - master data, invoices, quotes, recurring invoice templates, and sent emails - into a single document, for responding to a data access request (GDPR Art. 15). This is an EXPORT tool only - it does not delete or anonymize anything, since bookkeeping-posted invoices must be retained for 5 years under Danish bookkeeping law regardless.')
    . '</p>';

echo '<form method="get" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:20px;">';
echo '<div style="flex:1;"><label style="font-size:0.85em; font-weight:bold; display:block;">' . lang('@Customer') . '</label>';
htm_Select('cust_id', $cust_options, (string)$cust_id, 'width:100%; padding:6px;');
echo '</div>';
htm_Button(labl: '@Show', type: 'primary', attr: 'data-hint="'.lang('@Show all data held for this customer').'"');
echo '</form>';

if ($cust_id > 0) {
    $data = gdpr_collect_customer_data($conn, $cust_id);
    if ($data === null) {
        htm_Alert(lang('@Customer not found'), 'error');
    } else {
        echo '<div style="margin-bottom:15px;">';
        htm_Button(icon: 'fa-download', labl: '@Download as JSON', type: 'success', link: 'customer_data_export.php?cust_id=' . $cust_id . '&download=1',
            attr: 'data-hint="'.lang('@Download this data as a single JSON file').'"');
        echo '</div>';

        echo '<h4>' . lang('@Master Data') . '</h4>';
        $master_rows = [];
        foreach ($data['customer'] as $k => $v) { $master_rows[] = [htmlspecialchars($k), htmlspecialchars((string)($v ?? ''))]; }
        htm_Table(['@Field', '@Value'], $master_rows, 'gdprMasterTbl', 20);

        echo '<h4>' . lang('@Invoices') . ' (' . count($data['invoices']) . ')</h4>';
        if (empty($data['invoices'])) {
            echo '<p style="color:var(--text-muted); font-style:italic;">' . lang('@None found.') . '</p>';
        } else {
            $rows = [];
            foreach ($data['invoices'] as $r) { $rows[] = [htmlspecialchars($r['invoice_no']), $r['inv_date'], htmlspecialchars($r['inv_status']), number_format((float)$r['total'], 2, ',', '.') . ' ' . htmlspecialchars($r['currency'])]; }
            htm_Table(['@Invoice No', '@Date', '@Status', '@Total'], $rows, 'gdprInvTbl', 50);
        }

        echo '<h4>' . lang('@Quotes') . ' (' . count($data['quotes']) . ')</h4>';
        if (empty($data['quotes'])) {
            echo '<p style="color:var(--text-muted); font-style:italic;">' . lang('@None found.') . '</p>';
        } else {
            $rows = [];
            foreach ($data['quotes'] as $r) { $rows[] = [htmlspecialchars($r['quote_no']), $r['quote_date'], htmlspecialchars($r['status'])]; }
            htm_Table(['@Quote No', '@Date', '@Status'], $rows, 'gdprQuoteTbl', 50);
        }

        echo '<h4>' . lang('@Sent Mail Log') . ' (' . count($data['sent_mail']) . ')</h4>';
        if (empty($data['sent_mail'])) {
            echo '<p style="color:var(--text-muted); font-style:italic;">' . lang('@None found.') . '</p>';
        } else {
            $rows = [];
            foreach ($data['sent_mail'] as $r) { $rows[] = [db_ts_local($r['sent_at']), htmlspecialchars($r['subject']), $r['success'] ? lang('@Yes') : lang('@No')]; }
            htm_Table(['@Date', '@Subject', '@Success'], $rows, 'gdprMailTbl', 50);
        }
    }
}

htm_Card_end();
echo "</div>";
htm_Footer();
