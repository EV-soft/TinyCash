<?php # /invoice_create.page.php v:0.8.0 d:2026-04-10 i:Gemini m:2
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';

// 1. HENT AUTOMATISK NÆSTE FAKTURANUMMER
$res_no = mysqli_query($conn, "SELECT MAX(invoice_no) FROM invoices");
$next_invoice_no = mysqli_fetch_column($res_no);
$next_invoice_no = ($next_invoice_no > 0) ? $next_invoice_no + 1 : 1001; // Starter på 1001 hvis ingen findes

// 2. HENT STANDARDINDSTILLINGER (Valuta)
$settings = get_settings($conn);
$default_currency = $settings['currency'] ?? 'DKK';

htm_Header(lang('@Create Invoice'));
showMenu();

htm_Card_(lang('@New Invoice'), 800);
?>

<form method="post">
    <div style="margin-bottom: 25px;">
        <?php
        $res_c = mysqli_query($conn, "SELECT cust_id, cust_name FROM customers ORDER BY cust_name");
        $cust_opt = ['' => '-- ' . lang('@Select Customer') . ' --'];
        while($c = mysqli_fetch_assoc($res_c)) { $cust_opt[$c['cust_id']] = $c['cust_name']; }
        
        // Bemærk: htm_InputGroup printer nu selv (echo), så intet 'echo' foran kaldet her
        htm_InputGroup('', lang('@Customer'), 'invoice_customer', '', 'select', $cust_opt);
        ?>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 15px; margin-bottom: 30px; background: #fcfcfc; padding: 10px; border-radius: 4px;">
        <?php
        // Fakturanummer (Hentes nu dynamisk)
        htm_InputGroup('', lang('@Invoice No.'), 'invoice_no', $next_invoice_no, 'text');
        
        // Datoer
        htm_InputGroup('', lang('@Date'), 'invoice_date', date('Y-m-d'), 'date');
        htm_InputGroup('', lang('@Due Date'), 'due_date', date('Y-m-d', strtotime('+14 days')), 'date');
        
        // Valuta (Hentes fra settings)
        htm_InputGroup('', lang('@Currency'), 'currency', $default_currency, 'text');
        ?>
    </div>

    <div id="invoice_items_container">
        <table style="width:100%; border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr style="text-align: left; color: #95a5a6; font-size: 0.75rem; text-transform: uppercase; border-bottom: 2px solid #eee;">
                    <th style="padding: 10px;"><?php echo lang('@Description'); ?></th>
                    <th style="padding: 10px; width: 80px;"><?php echo lang('@Qty'); ?></th>
                    <th style="padding: 10px; width: 120px;"><?php echo lang('@Price'); ?></th>
                    <th style="padding: 10px; width: 100px;"><?php echo lang('@VAT'); ?></th>
                    <th style="padding: 10px; width: 120px; text-align: right;"><?php echo lang('@Total'); ?></th>
                    <th style="width: 40px;"></th>
                </tr>
            </thead>
           <tbody>
                <tr style="border-bottom: 1px solid #f4f4f4;">
                    <?php 
                        $prod_res = mysqli_query($conn, "SELECT prod_id, prod_name, prod_price FROM products ORDER BY prod_name");
                        $products = [];
                        while($p = mysqli_fetch_assoc($prod_res)) { $products[] = $p; }
                    ?>
                    <td>
                        <select name="item_prod_id[]" class="prod-select" style="width:100%; border:none; padding:5px; background:#f9f9f9; margin-bottom:5px;">
                            <option value="">-- <?php echo lang('@Select Product'); ?> --</option>
                            <?php foreach($products as $pr): ?>
                                <option value="<?php echo $pr['prod_id']; ?>"><?php echo $pr['prod_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="item_desc[]" placeholder="<?php echo lang('@Description'); ?>" style="width:100%; border:none; padding:12px; background:transparent;">
                    </td>
                    
                    <td><input type="number" name="item_qty[]" value="1" style="width:100%; border:none; padding:12px; background:transparent;"></td>
                    
                    <td><input type="number" name="item_price[]" step="0.01" placeholder="0.00" style="width:100%; border:none; padding:12px; background:transparent;"></td>
                    
                    <td>
                        <select name="item_vat[]" style="width:100%; border:none; padding:12px; background:transparent;">
                            <option value="25">25%</option>
                            <option value="0">0%</option>
                        </select>
                    </td>
                    
                    <td style="text-align: right; font-weight: 600; padding: 12px;">0.00</td>
                    
                    <td style="text-align: center;"><i class="fa fa-times" style="color:#ccc; cursor:pointer;"></i></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-top: 20px;">
        <button type="button" class="btn-primary" style="width:auto; font-size:0.8em; padding:8px 15px;">+ <?php echo lang('@Add Line'); ?></button>
        
        <div style="text-align: right; min-width: 250px;">
    <div style="margin-bottom: 5px; color: #7f8c8d;">
        <?php echo lang('@Subtotal'); ?>: <span id="summary_subtotal">0,00</span>
    </div>
    <div style="margin-bottom: 15px; color: #7f8c8d;">
        <?php echo lang('@VAT'); ?>: <span id="summary_vat">0,00</span>
    </div>
    <div style="font-size: 1.4rem; font-weight: 700; color: #2c3e50; border-top: 1px solid #ddd; padding-top: 10px;">
        TOTAL: <span id="summary_total">0,00</span> <?php echo $default_currency; ?>
    </div>
</div>
    </div>

    <div style="margin-top: 40px; display: flex; gap: 15px;">
        <button type="submit" name="save_invoice" class="btn-success" style="flex: 2; padding: 15px;">
            <i class="fa fa-check"></i> <?php echo lang('@Create Invoice'); ?>
        </button>
        <a href="invoice_list.page.php" class="btn-primary" style="flex: 1; background: #95a5a6; display: flex; align-items: center; justify-content: center; text-decoration: none;">
            <?php echo lang('@Cancel'); ?>
        </a>
    </div>
</form>

<?php 
htm_Card_end();
?>
<script>
document.addEventListener('input', function (e) {
    if (e.target.name === 'item_qty[]' || e.target.name === 'item_price[]' || e.target.name === 'item_vat[]') {
        calculateInvoice();
    }
});

function calculateInvoice() {
    let subtotal = 0;
    let totalVat = 0;
    
    const rows = document.querySelectorAll('#invoice_items_container tbody tr');
    
    rows.forEach(row => {
        const qty = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
        const price = parseFloat(row.querySelector('[name="item_price[]"]').value) || 0;
        const vatRate = 25; // Her kan du hente den valgte sats fra select-feltet hvis nødvendigt
        
        const lineTotal = qty * price;
        const lineVat = lineTotal * (vatRate / 100);
        
        // Opdater "Total" for den enkelte linje i tabellen
        row.querySelector('td:nth-last-child(2)').innerText = lineTotal.toLocaleString('da-DK', { minimumFractionDigits: 2 });
        
        subtotal += lineTotal;
        totalVat += lineVat;
    });
    
    const grandTotal = subtotal + totalVat;
    
    // Opdater opsummeringen i bunden
    document.getElementById('summary_subtotal').innerText = subtotal.toLocaleString('da-DK', { minimumFractionDigits: 2 });
    document.getElementById('summary_vat').innerText = totalVat.toLocaleString('da-DK', { minimumFractionDigits: 2 });
    document.getElementById('summary_total').innerText = grandTotal.toLocaleString('da-DK', { minimumFractionDigits: 2 });
}
</script>
<?php
htm_Footer(); 
ob_end_flush();
?>