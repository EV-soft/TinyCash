<?php # /quote_duplicate.php v:1.3.1 d:2026-09-15 i:evs
# NY (bruger-anmodet, "kan bogholderen ønske sig mere"): opretter en ny
# tilbudskladde med samme kunde/linjer/projekt som et eksisterende tilbud
# (uanset dettes egen status - draft/sent/accepted/rejected/converted er
# alle gyldige kilder, en genudsendelse af et allerede accepteret eller
# udløbet tilbud er et helt normalt behov). Samme mønster som
# invoice_duplicate.php fra samme session - se dens egen kommentar for
# hvorfor der (i modsætning til expense_edit.php's "prefill i stedet for
# indsæt"-tilgang) trygt kan indsættes direkte her: et tilbud er, ligesom
# en fakturakladde, en helt inert post uden regnskabsmæssig effekt før det
# konverteres til en rigtig faktura (quote_actions.php?action=convert).
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';

$src_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($src_id <= 0) { header("Location: quote_list.php"); exit; }

$table_exists = @DB::query($conn, "SELECT 1 FROM quotes LIMIT 1") !== false;
if (!$table_exists) { header("Location: quote_list.php"); exit; }

$src = DB::fetch_assoc(DB::query($conn, "SELECT * FROM quotes WHERE quote_id = $src_id"));
if (!$src) { header("Location: quote_list.php"); exit; }

// Ny gyldighedsdato beregnes frisk (i dag + 30 dage, samme standard som
// quote_edit.php's egen nye-tilbud-forvalg) - i stedet for at kopiere
// kildens EGEN valid_until, som typisk enten allerede er overskredet
// (netop derfor man vil genudsende) eller snart udløber.
$today       = date('Y-m-d');
$valid_until = date('Y-m-d', strtotime('+30 days'));

$cust_id_sql  = (int)$src['cust_id'];
$deliv_sql    = "'" . DB::escape($conn, $src['delivery_address'] ?? '') . "'";
$note_sql     = "'" . DB::escape($conn, $src['quote_note'] ?? '') . "'";
$cust_ref_sql = "'" . DB::escape($conn, $src['cust_reference'] ?? '') . "'";
$proj_sql     = !empty($src['proj_id']) ? (int)$src['proj_id'] : 'NULL';

DB::begin_transaction($conn);

$quote_no = next_quote_no($conn);
$ok = DB::query($conn, "INSERT INTO quotes
    (quote_no, cust_id, quote_date, valid_until, status, cust_reference, quote_note,
     delivery_address, proj_id, created_by)
    VALUES ($quote_no, $cust_id_sql, '$today', '$valid_until', 'draft', $cust_ref_sql, $note_sql,
     $deliv_sql, $proj_sql, " . (int)($_SESSION['user_id'] ?? 0) . ")");

if (!$ok) {
    DB::rollback($conn);
    die(lang('@Error saving invoice: ') . DB::error($conn));
}
$new_id = DB::insert_id($conn);

$lres = DB::query($conn, "SELECT * FROM quote_lines WHERE quote_id = $src_id ORDER BY line_id ASC");
while ($l = DB::fetch_assoc($lres)) {
    DB::insert($conn, 'quote_lines', [
        'quote_id'      => $new_id,
        'line_text'     => $l['line_text'],
        'quantity'      => $l['quantity'],
        'price_each'    => $l['price_each'],
        'line_vat_rate' => $l['line_vat_rate'],
        'prod_id'       => $l['prod_id'],
        'proj_id'       => $l['proj_id'],
    ]);
}

log_action($conn, 'DUPLICATE_QUOTE', 'quotes', $new_id, ['source_quote_id' => $src_id], ['cust_id' => $cust_id_sql, 'quote_date' => $today]);

DB::commit($conn);
header("Location: quote_edit.php?id=$new_id&msg=duplicated&src=$src_id");
ob_end_flush();
exit;
?>
