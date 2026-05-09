<?php # sales_hub.php v:0.9.1 d:2026-05-07 i:evs
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

htm_Header("@Sales & Customers", 1600);
showMenu();

echo '<style>
    .hub-total { font-family: "Courier New", monospace; font-size: 1.1rem; font-weight: 600; text-align: right; } 
    .btn-icon { padding:5px 9px; border:none; border-radius:4px; cursor:pointer; color:#fff; font-size:0.9rem; margin-right:3px; display:inline-block; text-decoration:none; } 
    .bg-view { background: #5bc0de; } .bg-edit { background: #337ab7; } .bg-del { background: #d9534f; }
    th.sortable { cursor: pointer; position: relative; }
    th.sortable:after { content: "\f0dc"; font-family: FontAwesome; padding-left: 5px; color: #ccc; }
</style>';

echo '<script>
function delInv(id){
    if(confirm("'.lang('@Delete Invoice?').'")) window.location="invoice_edit.php?id="+id+"&del=1";
}
</script>';

echo '<div style="display:flex; gap:10px; align-items: flex-start; padding:10px;">';

# KOLONNE 1: KUNDER
echo '<div style="flex: 1; min-width: 400px;">';
    $c_tools = htm_Button('fa-file-csv', '', 'info', 'export.php?type=customers', 'padding:4px 8px; margin-right:5px;', 'target="_blank" data-hint="'.lang('@Export All Data').'"', '', false);
    $c_tools .= htm_Button('fa-user-plus', '', 'success', 'customer_edit.php?id=0', 'padding:4px 8px;', 'data-hint="'.lang('@Create New').'"', '', false);
    
    htm_Card_('@Customers', "100%", "", "c_card", true, $c_tools);

    $data_c = [];
    $resc = mysqli_query($conn, "SELECT cust_id, cust_name, cust_email FROM customers ORDER BY cust_name LIMIT 100");
    if ($resc) {
        while($rowc = mysqli_fetch_assoc($resc)) {
            $btn = '<a href="customer_edit.php?id='.$rowc['cust_id'].'" class="btn-icon bg-edit"><i class="fa fa-pencil"></i></a>';
            $data_c[] = [$rowc['cust_id'], $rowc['cust_name'], $rowc['cust_email'], $btn];
        }
    }
    
    // Vi bruger rene labels her. Sortering bør aktiveres af tabel-ID 'hub_cust'
    htm_Table(['@ID', '@Name', '@Email', '@Action'], $data_c, 'hub_cust', 20);
    htm_Card_end();
echo '</div>';

# KOLONNE 2: FAKTURAER
echo '<div style="flex: 2;">';
    $i_tools = htm_Button('fa-file-csv', '', 'info', 'export.php?type=invoices', 'padding:4px 8px; margin-right:5px;', 'target="_blank" data-hint="'.lang('@Export All Data').'"', '', false);
    $i_tools .= htm_Button('fa-plus', '', 'success', 'invoice_edit.php?id=0', 'padding:4px 8px;', 'data-hint="'.lang('@Create New').'"', '', false);
    
    htm_Card_("@Invoice List", "100%", "", "i_card", true, $i_tools);

    $i_filter = '<div style="display:flex; gap:3px;">';
    foreach(['all', 'draft', 'sent', 'paid'] as $f) {
        $l = ($f == 'all') ? lang('@All') : lang('@'.$f);
        $i_filter .= '<button onclick="setFilter(\'hub_inv\', \''.(($f=='all')?'':$l).'\')" style="padding:5px 12px; border:1px solid #ddd; border-radius:15px; cursor:pointer; font-size:0.75rem; background:#fff;">'.$l.'</button>';
    }
    $i_filter .= '</div>';

    $data_i = [];
    $resi = mysqli_query($conn, "SELECT i.inv_id, i.invoice_no, i.inv_date, c.cust_name, i.inv_status, SUM(l.quantity * l.price_each) as total_amount 
                                 FROM invoices i 
                                 LEFT JOIN customers c ON i.cust_id = c.cust_id 
                                 LEFT JOIN invoice_lines l ON i.inv_id = l.inv_id 
                                 GROUP BY i.inv_id 
                                 ORDER BY i.inv_id DESC LIMIT 100");
    if ($resi) {
        while($rowi = mysqli_fetch_assoc($resi)) {
            $amou = number_format($rowi['total_amount'] ?? 0, 2, ',', '.');
            $btns = '<a href="invoice_view.php?id='.$rowi['inv_id'].'" class="btn-icon bg-view"><i class="fa fa-eye"></i></a>';
            $btns .= '<a href="invoice_edit.php?id='.$rowi['inv_id'].'" class="btn-icon bg-edit"><i class="fa fa-pencil"></i></a>';
            $btns .= '<button onclick="delInv('.$rowi['inv_id'].')" class="btn-icon bg-del"><i class="fa fa-trash"></i></button>';
            
            $data_i[] = [
                $rowi['invoice_no'] ?: 'Draft', 
                $rowi['inv_date'], 
                $rowi['cust_name'] ?: '---', 
                $amou, 
                lang('@'.$rowi['inv_status']), 
                $btns
            ];
        }
    }

    htm_Table(['@Inv. No', '@Date', '@Customer', '@Amount', '@Status', '@Action'], $data_i, 'hub_inv', 20, $i_filter);
    htm_Card_end();
echo '</div>';

echo '</div>';
htm_Footer();
?>