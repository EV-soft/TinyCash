<?php # /invoice_view.php v:0.9.2 d:2026-05-08 i:evs
require_once 'inc/db_connect.inc.php'; 
require_once 'inc/auth.inc.php'; 
require_once 'inc/php2htm.lib.php';

$inv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Hent faktura-, kunde- og firma-indstillinger (antager du har en settings tabel eller lign.)
$sql = "SELECT i.*, c.cust_name, c.cust_email, c.cust_address, c.cust_cvr 
        FROM invoices i 
        JOIN customers c ON i.cust_id = c.cust_id 
        WHERE i.inv_id = $inv_id";
$res = mysqli_query($conn, $sql);
$inv = mysqli_fetch_assoc($res); 

if (!$inv) die(lang("@Invoice not found.")); 

// Hent systemindstillinger
$settings = [];
$set_res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
while ($s = mysqli_fetch_assoc($set_res)) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

// Fallback værdier hvis settings er tomme
$coName = $settings['company_name'] ?? 'Dit Firma ApS';
$coAddr = $settings['company_address'] ?? 'Vejnavn 1';
$coCity = $settings['company_city'] ?? '8000 Aarhus';
$coCVR  = $settings['company_cvr'] ?? '12345678';

// Valuta og formatering
$cur = $inv['currency'] ?? 'DKK';
// Hvis inv_due_date er tom, beregn den ud fra kundens standard betalingsbetingelser
if (!$inv['inv_due_date'] && $inv['cust_payment_days']) {
    $inv['inv_due_date'] = date('Y-m-d', strtotime($inv['inv_date'] . ' + ' . $inv['cust_payment_days'] . ' days'));
}

echo '<!DOCTYPE html><html lang="da"><head><meta charset="UTF-8"><title>'.lang('@Invoice').' #'.$inv['invoice_no'].'</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    body { font-family: "Segoe UI", Arial, sans-serif; color: #000; line-height: 1.3; background: #f0f0f0; margin: 0; padding: 20px 0; font-weight: 450; }
    .paper { background: #fff; width: 210mm; min-height: 297mm; padding: 15mm; margin: 0 auto; box-shadow: 0 0 10px rgba(0,0,0,0.1); position: relative; box-sizing: border-box; }
    .status-stamp { position: absolute; top: 30px; right: 30px; border: 4px solid; padding: 5px 15px; font-weight: bold; font-size: 20px; text-transform: uppercase; transform: rotate(-15deg); opacity: 0.15; z-index: 10; }
    .line-table { width: 100%; border-collapse: collapse; margin-top: 10mm; }
    .line-table th { background: #f8f9fa; border-bottom: 2px solid #333; padding: 8px; text-align: left; font-size: 13px; }
    .line-table td { padding: 10px 8px; border-bottom: 1px solid #eee; font-size: 15px; vertical-align: top; }
    .totals-table { width: 45%; margin-left: 55%; margin-top: 10mm; border-collapse: collapse; }
    .totals-table td { padding: 5px; font-size: 15px; }
    .no-print { width: 210mm; margin: 0 auto 10px auto; text-align: right; }
    @media print { body { background: #fff; padding: 0; } .paper { box-shadow: none; margin: 0; width: 100%; padding: 15mm; } .no-print { display: none; } }
</style></head><body>';

echo '<div class="no-print">';
    htm_Button(icon: 'fa-print', labl: '@Print', type: 'primary', attr: 'onclick="window.print()"');
    htm_Button(icon: 'fa-edit',  labl: '@Edit',  type: 'secondary', link: 'invoice_edit.php?id='.$inv_id, styl: 'margin-left:5px;');
echo '</div>';

echo '<div class="paper">'; 
    
    // Status stempel (Bruger dine sprog-nøgler)
    $st_col = ['paid'=>'#2ecc71','sent'=>'#3498db','draft'=>'#95a5a6','void'=>'#e74c3c'][$inv['inv_status']] ?? '#95a5a6';
    echo '<div class="status-stamp" style="color:'.$st_col.';border-color:'.$st_col.';">'.lang('@'.$inv['inv_status']).'</div>';
    
    // 1. HEADER (Logo og dynamisk afsender info)
    echo '<div style="height: 35mm; width: 100%; position: relative;">';
        echo '<div style="float: left; width: 50%;">';
            if (file_exists('images/logo.png')) echo '<img src="images/logo.png" style="max-height:60px;margin-bottom:10px;display:block;">';
            echo '<h1 style="margin:0;font-size:32px;color:#2c3e50;">'.lang('@INVOICE').'</h1>';
        echo '</div>';
        echo '<div style="float: right; width: 50%; text-align: right; font-size: 14px; color: #444;">';
            echo '<strong>' . htmlspecialchars($coName) . '</strong><br>';
            echo htmlspecialchars($coAddr) . '<br>';
            echo htmlspecialchars($coCity) . '<br>';
            echo lang('@CVR') . ': ' . htmlspecialchars($coCVR);
           // echo '<strong>'.lang('@Company Name').'</strong><br>'.lang('@Company Address').'<br>'.lang('@Company City').'<br>'.lang('@CVR').': '.lang('@Company CVR');
        echo '</div>';
        echo '<div style="clear: both;"></div>';
    echo '</div>';

    // 2. MODTAGER OG FAKTURA DETALJER
    echo '<div style="height: 50mm; width: 100%; position: relative; margin-top: 5mm;">';
        echo '<div style="position: absolute; top: 5mm; left: 0; width: 90mm; font-size: 14px; line-height: 1.4;">';
            echo '<small style="color:#666; font-size: 11px;">'.lang('@Recipient').':</small><br>';
            echo '<strong>'.$inv['cust_name'].'</strong><br>'.nl2br(htmlspecialchars($inv['cust_address'] ?? '')); 
            
            if(!empty($inv['delivery_address'])) {
                echo '<br><br><small style="font-size: 10px;"><strong>'.lang('@Delivery Address').':</strong><br>'.nl2br(htmlspecialchars($inv['delivery_address'])).'</small>';
            }
        echo '</div>';

        echo '<div style="position: absolute; top: 5mm; right: 0; width: 65mm;">';
            echo '<table style="width: 100%; border-collapse: collapse; font-size: 13px;">'; 
                $f_no = $inv['invoice_no'] ? '#'.str_pad($inv['invoice_no'], 6, "0", STR_PAD_LEFT) : lang('@DRAFT');
                echo '<tr><td style="padding: 2px 0;"><strong>'.lang('@Invoice No').':</strong></td><td style="text-align: right;">'.$f_no.'</td></tr>';
                echo '<tr><td style="padding: 2px 0;"><strong>'.lang('@Date').':</strong></td><td style="text-align: right;">'.date('d.m.Y', strtotime($inv['inv_date'])).'</td></tr>';
                echo '<tr><td style="padding: 2px 0;"><strong>'.lang('@Due Date').':</strong></td><td style="text-align: right;">'.date('d.m.Y', strtotime($inv['inv_due_date'])).'</td></tr>';
                if($inv['cust_cvr']) echo '<tr><td style="padding: 2px 0;"><strong>'.lang('@CVR').':</strong></td><td style="text-align: right;">'.$inv['cust_cvr'].'</td></tr>';
            echo '</table>';
        echo '</div>';
    echo '</div>';

    // 3. VARELINJER
    echo '<table class="line-table">
        <thead>
            <tr>
                <th>'.lang('@Description').'</th>
                <th style="text-align:right;">'.lang('@Qty').'</th>
                <th style="text-align:right;">'.lang('@Price').'</th>
                <th style="text-align:right;">'.lang('@VAT%').'</th>
                <th style="text-align:right;">'.lang('@Total').'</th>
            </tr>
        </thead>
        <tbody>';
        
        $res_l = mysqli_query($conn, "SELECT * FROM invoice_lines WHERE inv_id = $inv_id ORDER BY line_id ASC"); 
        $sub = 0; $vat = 0;
        
        while ($l = mysqli_fetch_assoc($res_l)) { 
            $lt = $l['quantity'] * $l['price_each']; 
            $lv = $lt * ($l['line_vat_rate'] / 100); 
            $sub += $lt; $vat += $lv;
            echo '<tr>
                <td>'.htmlspecialchars($l['line_text']).'</td>
                <td style="text-align:right;">'.number_format($l['quantity'],2,',','.').'</td>
                <td style="text-align:right;">'.number_format($l['price_each'],2,',','.').'</td>
                <td style="text-align:right;">'.(int)$l['line_vat_rate'].'%</td>
                <td style="text-align:right;font-weight:bold;">'.number_format($lt,2,',','.').'</td>
            </tr>';
        } 
    echo '</tbody></table>';

    // 4. TOTALER
    echo '<table class="totals-table">
        <tr><td>'.lang('@Subtotal').':</td><td style="text-align:right;">'.number_format($sub,2,',','.').'</td></tr>
        <tr><td>'.lang('@VAT').':</td><td style="text-align:right;">'.number_format($vat,2,',','.').'</td></tr>
        <tr style="font-size:1.2em;font-weight:bold;border-top:2px solid #333;">
            <td>'.lang('@Total').':</td>
            <td style="text-align:right;">'.number_format($sub+$vat,2,',','.').' '.$cur.'</td>
        </tr>
    </table>';
    
    if($inv['inv_note']) {
        echo '<div style="margin-top:20mm;padding-top:10px;border-top:1px solid #eee;">
            <small style="color:#666;">'.lang('@Notes').':</small><br>
            '.nl2br(htmlspecialchars($inv['inv_note'])).'
        </div>';
    }
    
    // 5. FOOTER (Oversatte betalingslabels)
    echo '<div style="position:absolute;bottom:10mm;left:15mm;right:15mm;border-top:1px solid #eee;padding-top:10px;">
        <table style="width:100%;table-layout:fixed;font-size:10px;color:#666;border-collapse:collapse;">
            <tr>
                <td style="width:25%;vertical-align:top;text-align:left;">
                    <strong>'.lang('@Payment').'</strong><br>
                    ' . ($settings['bank_name'] ?? 'Nordea') . '<br>
                    ' . lang('@Reg') . ': ' . ($settings['bank_reg'] ?? '0000') . ' ' . lang('@Acc') . ': ' . ($settings['bank_acc'] ?? '00000000') . '
                </td>
                <td style="width:25%;vertical-align:top;text-align:center;">
                    <strong>'.lang('@Contact').'</strong><br>
                    ' . ($settings['company_phone'] ?? '') . '<br>
                    ' . ($settings['company_email'] ?? '') . '
                </td>
                <td style="width:25%;vertical-align:top;text-align:center;">
                    <strong>'.lang('@Online').'</strong><br>
                    '.lang('@MobilePay').': ' . ($settings['company_mobilepay'] ?? '00000') . '<br>
                    ' . ($settings['company_web'] ?? 'www.firma.dk') . '
                </td>
                <td style="width:25%;vertical-align:top;text-align:right;">
                    <strong>' . ($settings['company_name'] ?? 'Firma Navn') . '</strong><br>
                    ' . lang('@CVR') . ': ' . ($settings['company_cvr'] ?? '00000000') . '<br>
                    '.lang('@Thank you for your business!').'
                </td>
            </tr>
        </table>
    </div>';

echo '</div>';

if(function_exists('htm_V2_Nav')) htm_V2_Nav($inv_id);
htm_Footer();
?>