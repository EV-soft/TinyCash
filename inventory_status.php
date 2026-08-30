<?php # /inventory_status.php v:1.3.0 d:2026-08-30 i:evs
# 5 fund ved en produkt-/lagergennemgang - se [[inventory-bugs-review]]
# v1.3.0: (1) KRITISK - produktnavn blev skrevet råt/uescaped ind i tabellen
# (lagret XSS, bekræftet live med <script>alert(1)</script>) - product_list.php
# escaper allerede samme felt korrekt, kun denne søsterside manglede det.
# (2) Der var slet ingen måde at slette et produkt på nogen steder i UI'et -
# den eneste sletningslogik (invoice_actions.php?action=delete_product) er
# helt ukoblet/ureferet. Tilføjet en rigtig Slet-knap her (bekræftelse +
# tjek for brug på fakturalinjer, samme mønster som customer_edit.php).
# (4) "Opret variant"-knappens hint var hårdkodet dansk (manglede
# @-præfikset), oversatte derfor aldrig - rettet til en rigtig lang()-nøgle.
# (5) CSV-eksporten hed altid bogstaveligt "test.csv" - en glemt
# placeholder-værdi, rettet til et rigtigt filnavn.
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

$s = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

htm_Header("@Inventory Status", 800);
showMenu();

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted')       htm_Alert(text: lang('@Product deleted'), type: 'success');
    if ($_GET['msg'] === 'error_in_use')  htm_Alert(text: lang('@Cannot delete product: it is used on one or more invoice lines.'), type: 'error');
}

// Knapper til top-højre
$tools = htm_Button('fa-plus', ' '.lang('@New Product'), 'success', 'product_edit.php', 'padding:4px 8px; margin-right:5px;', 'data-hint="'.lang('@Create a new product').'"', '', false);
$tools .= htm_Button('fa-file-csv', ' CSV', 'info', 'inventory_export.php', 'padding:4px 8px;', 'target="_blank" data-hint="'.lang('@Export the inventory list as CSV').'"', '', false);

htm_Card_("@Inventory Status", "100%", "", "inv_card", true, $tools);

$data = [];
$res = DB::query($conn, "SELECT prod_id, prod_sku, prod_name, prod_stock, prod_min_stock, prod_price FROM products ORDER BY prod_name ASC");

if ($res && DB::num_rows($res) > 0) {
    while($r = DB::fetch_assoc($res)) {
        $stk = (int)($r['prod_stock'] ?? 0);
        $min = (int)($r['prod_min_stock'] ?? 5);
        
        $sku = !empty($r['prod_sku']) ? $r['prod_sku'] : $r['prod_id'];
        // RETTET (fund 1, lagret XSS): produktnavnet blev før skrevet direkte
        // ind i markup'en - htm_Table() escaper IKKE cellernes indhold selv
        // (den understøtter bevidst rå HTML i celler, fx knapper), så det er
        // hvert kaldested der selv skal escape ren tekst. product_list.php
        // gjorde det allerede korrekt for samme felt.
        $name = htmlspecialchars($r['prod_name']);

        // Pris formateres med valuta
        $prc = number_format($r['prod_price'], 2, ',', '.') . " $cur";

        // Lagerstatus
        $display_stock = $stk;

        // Action knapper inkl. ny variant-knap
        $btns = htm_ActionButtons([
            ['icon' => 'fa-pencil', 'link' => 'product_edit.php?id='.$r['prod_id'], 'hint' => '@Edit', 'type' => 'primary'],
            // RETTET (fund 4): hint manglede @-præfikset og var derfor
            // hårdkodet dansk uanset valgt sprog - se lang()'s konvention.
            ['icon' => 'fa-copy', 'link' => 'product_edit.php?copy_id='.$r['prod_id'], 'hint' => '@Create variant', 'type' => 'success'],
            // NYT (fund 2): der var ingen måde at slette et produkt på
            // nogen steder i UI'et - den eneste eksisterende sletningslogik
            // (invoice_actions.php?action=delete_product) er helt ukoblet.
            // Genbruger samme tjek (blokerer sletning hvis produktet er
            // brugt på en fakturalinje) i den aktivt vedligeholdte
            // inventory_actions.php i stedet.
            ['icon' => 'fa-trash', 'link' => 'inventory_actions.php?action=delete_product&id='.$r['prod_id'], 'hint' => '@Delete', 'type' => 'danger', 'confirm' => '@Are you sure you want to delete this product? This cannot be undone.'],
        ], false);

        $data[] = [$sku,
            '<span class="truncate" onclick="toggleCell(this.parentElement)" title="'.lang('@Click to toggle view').'">'.$name.'</span>',
            $prc,
            $display_stock,
            $btns];
    }
}

// Simple overskrifter (Biblioteket oversætter dem selv og tilføjer sortering)
$headers = [
    '@SKU', 
    '@Product', 
    '@Price', 
    '@Stock', 
    '@Action'
];

// Generer tabellen (Kun ét kald her)
// RETTET (fund 5): eksportfilnavnet var en glemt placeholder ("test.csv").
htm_Table($headers, $data, 'inv_table', 50, vhgh:'350px', expo:'lagerstatus.csv');

htm_Card_end();
htm_Footer();
?>