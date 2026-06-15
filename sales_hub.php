<?php # sales_hub.php v:1.0.0 d:2026-06-15 i:evs
require_once 'inc/db_connect.inc.php';
require_once 'inc/auth.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

// Hent den valgte status fra URL'en
$current_status = isset($_GET['status']) ? $_GET['status'] : 'all';

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
function postInv(id) {
    if (!confirm("' . lang('@Are you sure you want to post this invoice? This will assign an official invoice number and lock the draft.') . '")) return;

    fetch("invoice_post_action.php?id=" + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                sysAlert("' . lang('@Invoice successfully posted with number: ') . '" + data.invoice_no);
                location.reload(); 
            } else {
                sysAlert("' . lang('@Posting failed: ') . '" + data.error);
            }
        })
        .catch(err => {
            sysAlert("' . lang('@System error: Could not connect to server.') . '");
        });
}

function delInv(id){
    if(confirm("' . lang('@Delete Invoice?') . '")) window.location="invoice_edit.php?id="+id+"&del=1";
}

function setFilter(status) {
    window.location.href = "sales_hub.php?status=" + status;
}

function sendMail(id) {
    const btn = document.getElementById("mail_btn_" + id);
    const originalHTML = btn.innerHTML;
    
    if (!confirm("' . lang('@Send Invoice via Email?') . '")) return;

    btn.disabled = true;
    btn.innerHTML = \'<i class="fa fa-spinner fa-spin"></i>\';
    btn.style.opacity = "0.6";

    fetch("send_invoice_action.php?id=" + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                sysAlert("' . lang('@Email sent successfully!') . '");
                location.reload(); 
            } else {
                sysAlert("' . lang('@Error: ') . '" + data.error);
                btn.disabled = false;
                btn.innerHTML = originalHTML;
                btn.style.opacity = "1";
            }
        })
        .catch(err => {
            sysAlert("' . lang('@System error: Could not connect to mail system.: ') . '");
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            btn.style.opacity = "1";
        });
}
</script>';
echo '<div style="display:flex; gap:10px; align-items: flex-start; padding:10px;">';

# KOLONNE 1: KUNDER
echo '<div style="flex: 2; min-width: 400px;">';
    $c_tools = htm_Button('fa-file-csv', '', 'info', 'export.php?type=customers', 'padding:4px 8px; margin-right:5px;', 'target="_blank" data-hint="'.lang('@Export All Data').'"', '', false);
    $c_tools .= htm_Button('fa-user-plus', '', 'success', 'customer_edit.php?id=0', 'padding:4px 8px;', 'data-hint="'.lang('@Create New').'"', '', false);
    
    htm_Card_('@Customers', "100%", "", "c_card", true, $c_tools);

    $data_c = [];
    $resc = mysqli_query($conn, "SELECT cust_id, cust_name, cust_email FROM customers ORDER BY cust_name LIMIT 100");
    if ($resc) {
        while($rowc = mysqli_fetch_assoc($resc)) {
            $btn = '<a href="customer_edit.php?id='.$rowc['cust_id'].'" class="btn-icon bg-edit" data-hint="'.lang('@Edit').'"><i class="fa fa-pencil"></i></a>';
            $data_c[] = [$rowc['cust_id'], $rowc['cust_name'], $rowc['cust_email'], $btn];
        }
    }
    $cust_cols = [
        'width:50px;',
        'width:140px;',
        'width:180px;',
        'width:100px; text-align:center;'
    ];

    htm_Table(['@ID', '@Name', '@Email', '@Action'], $data_c, 'hub_cust', 20, '', true, $cust_cols);
    htm_Card_end();
echo '</div>';

# KOLONNE 2: FAKTURAER
echo '<div style="flex: 3;">';
    $i_tools = htm_Button('fa-file-csv', '', 'info', 'export.php?type=invoices', 'padding:4px 8px; margin-right:5px;', 'target="_blank" data-hint="'.lang('@Export All Data').'"', '', false);
    $i_tools .= htm_Button('fa-plus', '', 'success', 'invoice_edit.php?id=0', 'padding:4px 8px;', 'data-hint="'.lang('@Create New').'"', '', false);
    
    htm_Card_("@Invoice List", "100%", "", "i_card", true, $i_tools);

    // Filter-knapper med label foran
    $i_filter = '<div style="display:flex; gap:3px; margin-bottom:10px; align-items:center;" data-hint="'.lang('@Filter: Hide all data rows that do not meet criteria').'">';
    $i_filter .= '<span style="font-size:0.8rem; font-weight:bold; margin-right:5px; font-family:sans-serif; color:#555;">' . lang('@Filter:') . '</span>'; // NY LABEL FORAN KNAPPER
    foreach(['all', 'draft', 'sent', 'paid'] as $f) {
        $l = ($f == 'all') ? lang('@All') : lang('@'.$f);
        $isActive = (strtolower($current_status) == $f);
        $bg = $isActive ? '#3498db' : '#fff';
        $color = $isActive ? '#fff' : '#333';
        $i_filter .= '<button type="button" onclick="setFilter(\''.$f.'\')" style="padding:5px 12px; border:1px solid #ddd; border-radius:15px; cursor:pointer; font-size:0.75rem; background:'.$bg.'; color:'.$color.'; font-weight:'.($isActive?'bold':'normal').';" >'.$l.'</button>';
    }
    $i_filter .= '</div>';

    // SQL WHERE-klausul baseret på filteret
    $where_clause = "";
    if ($current_status !== 'all') {
        $safe_status = mysqli_real_escape_string($conn, strtolower($current_status));
        $where_clause = "WHERE i.inv_status = '$safe_status'";
    }

    $data_i = [];
    $query = "SELECT i.inv_id, i.invoice_no, i.inv_date, c.cust_name, i.inv_status, SUM(l.quantity * l.price_each) as total_amount 
              FROM invoices i 
              LEFT JOIN customers c ON i.cust_id = c.cust_id 
              LEFT JOIN invoice_lines l ON i.inv_id = l.inv_id 
              $where_clause 
              GROUP BY i.inv_id 
              ORDER BY i.inv_id DESC LIMIT 100";
              
    $resi = mysqli_query($conn, $query);
    if ($resi) {
        while($rowi = mysqli_fetch_assoc($resi)) {
            $amou = number_format($rowi['total_amount'] ?? 0, 2, ',', '.');
            
            $btns = '<a href="invoice_view.php?id='.$rowi['inv_id'].'" class="btn-icon bg-view" data-hint="'.lang('@View').'"><i class="fa fa-eye"></i></a>';
            
            if ($rowi['inv_status'] == 'draft') {
                $btns .= '<a href="invoice_edit.php?id='.$rowi['inv_id'].'" class="btn-icon bg-edit" data-hint="'.lang('@Edit').'"><i class="fa fa-pencil"></i></a>';
                $btns .= '<button type="button" onclick="postInv('.$rowi['inv_id'].')" class="btn-icon" style="background:#27ae60;" data-hint="'.lang('@Post Invoice (Assign No.)').'"><i class="fa fa-gavel"></i></button>';
            } else {
                // NYT: Hvis fakturaen ER låst/sendt/betalt, tillader vi automatisk kreditering via URL-parameter
                $btns .= '<a href="invoice_edit.php?id=0&credit_ref='.$rowi['inv_id'].'" class="btn-icon" style="background:#e67e22;" data-hint="'.lang('@Credit this invoice (Auto-create)').'"><i class="fa fa-undo"></i></a>';
            }
            
            $btns .= '<button type="button" onclick="delInv('.$rowi['inv_id'].')" class="btn-icon bg-del" data-hint="'.lang('@Delete').'"><i class="fa fa-trash"></i></button>';
            
            $invoice_display_no = ($rowi['inv_status'] == 'draft') ? lang('@Draft') : ($rowi['invoice_no'] ?: '---');

            $data_i[] = [
                $invoice_display_no,
                $rowi['inv_date'], 
                $rowi['cust_name'] ?: '---', 
                $amou, 
                lang('@'.$rowi['inv_status']), 
                $btns
            ];
        }
    }

    $inv_cols = [
        'width:90px;',
        'width:120px;',
        'width:200px;',
        'width:100px; text-align:right;',
        'width:90px; text-align:center;',
        'width:140px; text-align:left;'
    ];

    htm_Table(['@Inv. No', '@Date', '@Customer', '@Amount', '@Status', '@Action'], $data_i, 'hub_inv', 20, $i_filter, true, $inv_cols);
    htm_Card_end();
echo '</div>';

echo '</div>';

// NYT: Injicer data-hint på søgefelterne dynamisk efter tabelgenerering
echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    // Find søgefeltet tilhørende kundetabellen (hub_cust)
    const custSearch = document.querySelector("#hub_cust_search, [id$=\'_hub_cust_search\'], #hub_cust input[type=\'text\']");
    if (custSearch) {
        custSearch.setAttribute("data-hint", "' . lang('@Search for customer name, ID or email...') . '");
    }

    // Find søgefeltet tilhørende fakturatabellen (hub_inv)
    const invSearch = document.querySelector("#hub_inv_search, [id$=\'_hub_inv_search\'], #hub_inv input[type=\'text\']");
    if (invSearch) {
        invSearch.setAttribute("data-hint", "' . lang('@Search for invoice number, customer or date...') . '");
    }
});
</script>';

htm_Footer();
?>