<?php # /inventory_actions.php v:1.3.0 d:2026-08-30 i:evs
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

$action = $_GET['action'] ?? '';

if ($action === 'delete_product') {
    $del_id = (int)($_GET['id'] ?? 0);
    if ($del_id > 0) {
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
        if ($in_use) {
            header("Location: inventory_status.php?msg=error_in_use"); exit;
        }
        DB::query($conn, "DELETE FROM products WHERE prod_id = $del_id");
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
$prod_stock     = (int)($_POST['prod_stock'] ?? 0);
$prod_min_stock = (int)($_POST['prod_min_stock'] ?? 5);
$prod_price     = parse_dk_number($_POST['prod_price'] ?? 0);
$acc_id         = isset($_POST['acc_id']) ? (int)$_POST['acc_id'] : "NULL";

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

if ($action === 'create_product') {
    $sql = "INSERT INTO products (prod_sku, prod_name, prod_stock, prod_min_stock, prod_price, acc_id)
            VALUES ($prod_sku_sql, '$prod_name', $prod_stock, $prod_min_stock, $prod_price, $acc_id)";
    $success = DB::query($conn, $sql);
}
elseif ($action === 'update_product' && $prod_id > 0) {
    $sql = "UPDATE products SET
                prod_sku = $prod_sku_sql,
                prod_name = '$prod_name',
                prod_stock = $prod_stock,
                prod_min_stock = $prod_min_stock,
                prod_price = $prod_price,
                acc_id = $acc_id
            WHERE prod_id = $prod_id";
    $success = DB::query($conn, $sql);
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