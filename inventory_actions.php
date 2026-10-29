<?php # /inventory_actions.php v:1.3.1 d:2026-09-15 i:evs
# fund 2+3 fra en produkt-/lagergennemgang - se [[inventory-bugs-review]]
# v1.3.0: (2) tilføjet en rigtig delete_product-handling - der var før slet
# ingen måde at slette et produkt på nogen steder i UI'et, den eneste
# eksisterende logik (invoice_actions.php) er helt ukoblet/ureferet. Kaldes
# via et GET-link (samme mønster som account_delete.php/project_actions.php),
# så den generelle POST-spærre gælder nu kun create/update.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/field_visibility.inc.php';

$action = $_GET['action'] ?? '';

if ($action === 'delete_product') {
    $del_id = (int)($_GET['id'] ?? 0);
    if ($del_id > 0) {
        // NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): denne fil
        // (og product_edit.php, som kun er visningen) kaldte FØR ALDRIG
        // log_action() nogen steder - hverken opret, redigér eller slet - i
        // modsætning til customer_edit.php/account_edit.php/vat_codes.php,
        // som alle logger ændringer af stamdata. En prisændring, kostpris-
        // ændring (påvirker avance-beregningen, [[bookkeeper-wishlist-round17]])
        // eller en direkte lagerkorrektion var dermed helt usporbar. Henter
        // "før"-billedet FØR selve sletningen, samme mønster som
        // customer_edit.php/supplier_list.php's egne sletnings-logninger.
        $del_old_row = DB::fetch_assoc(DB::query($conn, "SELECT * FROM products WHERE prod_id = $del_id"));
        // Bevar samme sikkerhedstjek som den tidligere ukoblede logik i
        // invoice_actions.php havde: bloker sletning hvis produktet er brugt
        // på en fakturalinje, i stedet for at efterlade en løs fremmednøgle.
        //
        // NYT (§bugs-batch-31-review): samme fejlklasse som batch 30 - dette
        // tjek dækkede kun invoice_lines. recurring_invoice_lines har ALTID
        // haft sin egen prod_id-kolonne (aldrig tjekket her), og quote_lines
        // (nyere, se [[quotes-feature]]) har den samme. Et slettet produkt
        // kunne derfor stå tilbage som en løs reference i en gentagen
        // fakturaskabelon eller et tilbud - og da BÅDE quote_actions.php's
        // "Konvertér til faktura" OG recurring_invoices.inc.php's
        // generator kopierer prod_id videre uden selv at tjekke det stadig
        // findes, ville det løse ID stille forplante sig ind i en helt ny
        // faktura-linje. @-hæmmet for quote_lines, da tabellen kun findes
        // efter db-setup/migrate_quotes.php er kørt.
        $in_use = DB::num_rows(DB::query($conn, "SELECT line_id FROM invoice_lines WHERE prod_id = $del_id LIMIT 1")) > 0;
        if (!$in_use) {
            $in_use = DB::num_rows(DB::query($conn, "SELECT rline_id FROM recurring_invoice_lines WHERE prod_id = $del_id LIMIT 1")) > 0;
        }
        if (!$in_use) {
            $chk = @DB::query($conn, "SELECT line_id FROM quote_lines WHERE prod_id = $del_id LIMIT 1");
            $in_use = ($chk && DB::num_rows($chk) > 0);
        }
        if (!$in_use) {      // RETTET: en linjeskabelon med varen - indsat bagefter pegede linjen på en vare der ikke fandtes og gik tabt
            $chk = @DB::query($conn, "SELECT item_id FROM invoice_line_template_items WHERE prod_id = $del_id LIMIT 1");
            $in_use = ($chk && DB::fetch_assoc($chk));
        }
        if ($in_use) {
            header("Location: inventory_status.php?msg=error_in_use"); exit;
        }
        if (DB::query($conn, "DELETE FROM products WHERE prod_id = $del_id") && $del_old_row) {
            log_action($conn, 'DELETE_PRODUCT', 'products', $del_id, $del_old_row, null);
        }
    }
    header("Location: inventory_status.php?msg=deleted"); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: inventory_status.php");
    exit;
}

$prod_id = (int)($_POST['prod_id'] ?? 0);

// Forbered data fra formularen (uden prod_vat_rate, da momsen styres via kontoplanen)
// RETTET (§bugs-batch-23-review, se [[migrate_product_sku_unique]]): tomt SKU
// gemmes nu som NULL, ikke som en tom streng - forudsætning for at det
// rigtige UNIQUE-indeks (tilføjet i samme runde) kan tillade et vilkårligt
// antal produkter uden SKU side om side (NULL <> NULL i et unikt indeks),
// i stedet for at de alle ville kollidere med hinanden på den samme ''.
$prod_sku_raw   = trim($_POST['prod_sku'] ?? '');
$prod_sku_sql   = $prod_sku_raw !== '' ? "'" . DB::escape($conn, $prod_sku_raw) . "'" : 'NULL';
$prod_name      = DB::escape($conn, $_POST['prod_name'] ?? '');
$prod_stock     = parse_dk_number($_POST['prod_stock'] ?? 0);      // RETTET: (int) kappede en brøkdel af (fx 7,5 kg -> 7)
$prod_min_stock = (int)($_POST['prod_min_stock'] ?? 5);
$prod_price     = parse_dk_number($_POST['prod_price'] ?? 0);
$acc_id         = isset($_POST['acc_id']) ? (int)$_POST['acc_id'] : "NULL";

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): valgfri kostpris,
// samme "stille degradering hvis ikke migreret endnu"-mønster som andre
// nyere kolonner - prod_cost_price findes kun efter db-setup/migrate_
// product_cost_price.php er kørt. Tomt felt gemmes som NULL (ukendt), ikke
// som en falsk 0-kr-kostpris.
$has_cost_col = false;
if (DB::is_sqlite()) {
    $cc_check = DB::query($conn, "PRAGMA table_info(products)");
    if ($cc_check) { while ($ccr = DB::fetch_assoc($cc_check)) { if ($ccr['name'] === 'prod_cost_price') { $has_cost_col = true; break; } } }
} else {
    $cc_check = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_cost_price'");
    $has_cost_col = ($cc_check && DB::num_rows($cc_check) > 0);
}
$has_cost_col = $has_cost_col && field_visible_to_user($conn, 'product_edit.prod_cost_price');     // Skjult felt (ikke i formularen): den gemte kostpris bevares
$prod_cost_raw = trim($_POST['prod_cost_price'] ?? '');
$prod_cost_sql = $prod_cost_raw !== '' ? parse_dk_number($prod_cost_raw) : 'NULL';

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 27): valgfrit
// kategori-/gruppefelt, samme "stille degradering hvis ikke migreret endnu"-
// mønster som kostprisen ovenfor - prod_category findes kun efter db-setup/
// migrate_product_category.php er kørt.
$has_category_col = false;
if (DB::is_sqlite()) {
    $catc = DB::query($conn, "PRAGMA table_info(products)");
    if ($catc) { while ($catr = DB::fetch_assoc($catc)) { if ($catr['name'] === 'prod_category') { $has_category_col = true; break; } } }
} else {
    $catc = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_category'");
    $has_category_col = ($catc && DB::num_rows($catc) > 0);
}
$prod_category_raw = trim($_POST['prod_category'] ?? '');
$prod_category_sql = $prod_category_raw !== '' ? "'" . DB::escape($conn, $prod_category_raw) . "'" : 'NULL';

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 31): valgfrit
// stregkode-/EAN-felt, samme "stille degradering hvis ikke migreret endnu"-
// mønster som kategorien ovenfor.
$has_ean_col = false;
if (DB::is_sqlite()) {
    $eanc = DB::query($conn, "PRAGMA table_info(products)");
    if ($eanc) { while ($eanr = DB::fetch_assoc($eanc)) { if ($eanr['name'] === 'prod_ean') { $has_ean_col = true; break; } } }
} else {
    $eanc = DB::query($conn, "SHOW COLUMNS FROM products LIKE 'prod_ean'");
    $has_ean_col = ($eanc && DB::num_rows($eanc) > 0);
}
$prod_ean_raw = trim($_POST['prod_ean'] ?? '');
$prod_ean_sql = $prod_ean_raw !== '' ? "'" . DB::escape($conn, $prod_ean_raw) . "'" : 'NULL';

// RETTET (§bugs-batch-22-review): intet tjekkede nogensinde om prod_sku
// allerede var i brug af et ANDET produkt ved selve gemningen - kun "Opret
// variant"-knappens FORESLÅEDE SKU var beskyttet mod kollision (se
// [[inventory-bugs-review]] fund 3, product_edit.php), men intet forhindrede
// brugeren i at ændre det foreslåede SKU tilbage til noget der allerede var
// i brug, eller blot indtaste et eksisterende SKU manuelt på et helt nyt
// produkt. To produkter med samme SKU er reelt umuligt at skelne ved
// opslag/scanning. Tomt SKU er stadig tilladt (nogle produkter har ingen
// endnu) - kun et REELT, ikke-tomt duplikat afvises.
//
// RETTET (§bugs-batch-23-review): dette SELECT-før-INSERT-tjek er i sig selv
// et kapløb - to næsten-samtidige indsendelser med samme SKU kunne begge
// bestå SELECT'et FØR nogen af dem nåede at gemme, og ende med to produkter
// med samme SKU alligevel (præcis den situation tjekket skulle forhindre).
// Det er derfor bevidst suppleret, ikke erstattet, af et rigtigt UNIQUE-
// indeks i selve databasen (db-setup/migrate_product_sku_unique.php) - det
// tidlige tjek her giver stadig en pæn fejlbesked i det normale tilfælde,
// mens indekset er den reelle garanti hvis to indsendelser rammer samtidigt
// (fanget nedenfor som et databasefejl-fald, ikke en tavs succes).
if ($prod_sku_raw !== '') {
    $sku_check_sql = "SELECT prod_id FROM products WHERE prod_sku = '" . DB::escape($conn, $prod_sku_raw) . "'";
    if ($prod_id > 0) $sku_check_sql .= " AND prod_id != $prod_id";
    $sku_in_use = DB::num_rows(DB::query($conn, $sku_check_sql)) > 0;
    if ($sku_in_use) {
        die(lang('@Error: This SKU is already used by another product.'));
    }
}

$success = false;
$after_row = [
    'prod_sku' => $prod_sku_raw !== '' ? $prod_sku_raw : null, 'prod_name' => $_POST['prod_name'] ?? '',
    'prod_stock' => $prod_stock, 'prod_min_stock' => $prod_min_stock, 'prod_price' => $prod_price,
    'acc_id' => $acc_id === 'NULL' ? null : $acc_id,
];
if ($has_cost_col) $after_row['prod_cost_price'] = $prod_cost_sql === 'NULL' ? null : $prod_cost_sql;
if ($has_category_col) $after_row['prod_category'] = $prod_category_raw !== '' ? $prod_category_raw : null;
if ($has_ean_col) $after_row['prod_ean'] = $prod_ean_raw !== '' ? $prod_ean_raw : null;

if ($action === 'create_product') {
    $cols = "prod_sku, prod_name, prod_stock, prod_min_stock, prod_price, acc_id";
    $vals = "$prod_sku_sql, '$prod_name', $prod_stock, $prod_min_stock, $prod_price, $acc_id";
    if ($has_cost_col) { $cols .= ", prod_cost_price"; $vals .= ", $prod_cost_sql"; }
    if ($has_category_col) { $cols .= ", prod_category"; $vals .= ", $prod_category_sql"; }
    if ($has_ean_col) { $cols .= ", prod_ean"; $vals .= ", $prod_ean_sql"; }
    $sql = "INSERT INTO products ($cols) VALUES ($vals)";
    $success = DB::query($conn, $sql);
    if ($success) {
        log_action($conn, 'CREATE_PRODUCT', 'products', DB::insert_id($conn), null, $after_row);
    }
}
elseif ($action === 'update_product' && $prod_id > 0) {
    $before_row = DB::fetch_assoc(DB::query($conn, "SELECT * FROM products WHERE prod_id = $prod_id"));
    // RETTET: beholdningen fra formularen blev skrevet som et FAST tal - blev der bogført en faktura (eller kom en
    // webordre) mens formularen stod åben, forsvandt det salg fra lageret, når fx prisen blev gemt. Kun en
    // ændring af feltet skrives nu - som en regulering (+/-) af den aktuelle beholdning.
    if (!isset($_POST['prod_stock'])) {
        $stock_sql  = 'prod_stock = prod_stock';      // feltet ikke sendt: beholdningen røres ikke (ellers -hele beholdningen)
        $prod_stock = (float)($before_row['prod_stock'] ?? 0);
        $after_row['prod_stock'] = $prod_stock;
    } elseif (isset($_POST['prod_stock_orig']) && trim((string)$_POST['prod_stock_orig']) !== '') {
        $stock_delta = round($prod_stock - parse_dk_number($_POST['prod_stock_orig']), 4);
        $stock_sql   = "prod_stock = prod_stock + ($stock_delta)";
        $prod_stock  = round((float)($before_row['prod_stock'] ?? 0) + $stock_delta, 4);
        $after_row['prod_stock'] = $prod_stock;
    } else {
        $stock_sql = "prod_stock = $prod_stock";
    }
    $sql = "UPDATE products SET
                prod_sku = $prod_sku_sql,
                prod_name = '$prod_name',
                $stock_sql,
                prod_min_stock = $prod_min_stock,
                prod_price = $prod_price,
                acc_id = $acc_id"
        . ($has_cost_col ? ", prod_cost_price = $prod_cost_sql" : "")
        . ($has_category_col ? ", prod_category = $prod_category_sql" : "")
        . ($has_ean_col ? ", prod_ean = $prod_ean_sql" : "") . "
            WHERE prod_id = $prod_id";
    $success = DB::query($conn, $sql);
    if ($success) {
        log_action($conn, 'UPDATE_PRODUCT', 'products', $prod_id, $before_row, $after_row);
    }
}

if (!$success) {
    // Databasens UNIQUE-indeks (se ovenfor) kan i sjældne tilfælde afvise et
    // reelt kapløbstab her, selvom SELECT-tjekket ovenfor lige var bestået -
    // vis samme brugervenlige besked som det almindelige tjek i stedet for
    // en rå SQL-fejl, hvis det ligner en dubletkollision.
    $db_err = DB::error($conn);
    if ($prod_sku_raw !== '' && (stripos($db_err, 'unique') !== false || stripos($db_err, 'duplicate') !== false)) {
        die(lang('@Error: This SKU is already used by another product.'));
    }
    die("SQL Error: " . $db_err);
}

header("Location: inventory_status.php");
exit;
?>