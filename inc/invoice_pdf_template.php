<?php
# invoice_pdf_template.php
// Hvis filen kaldes direkte i bufferen, sikrer vi os adgang til variablerne
if (!isset($pdf_inv)) {
    $pdf_inv = $GLOBALS['pdf_inv'] ?? [];
    $pdf_lines = $GLOBALS['pdf_lines'] ?? [];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 14px; color: #333; }
        .header { font-size: 24px; font-weight: bold; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .info-box { margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f5f5f5; font-weight: bold; }
        .text-right { text-align: right; }
    </style>
</head>
<body>

    <div class="header">
        FAKTURA #<?php echo htmlspecialchars($pdf_inv['invoice_no'] ?? 'Uden nummer'); ?>
    </div>
    
    <div class="info-box">
        <strong>Dato:</strong> <?php echo htmlspecialchars($pdf_inv['inv_date'] ?? '-'); ?><br>
        <strong>Forfaldsdato:</strong> <?php echo htmlspecialchars($pdf_inv['inv_due_date'] ?? '-'); ?><br>
        <strong>Status:</strong> <?php echo htmlspecialchars($pdf_inv['inv_status'] ?? '-'); ?><br>
        <strong>Valuta:</strong> <?php echo htmlspecialchars($pdf_inv['currency'] ?? 'DKK'); ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Beskrivelse</th>
                <th class="text-right">Antal</th>
                <th class="text-right">Stk. pris</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $total_all = 0;
            if (!empty($pdf_lines)): 
                foreach ($pdf_lines as $l): 
                    $qty = (float)($l['quantity'] ?? 0);
                    $price = (float)($l['price_each'] ?? 0);
                    $line_total = $qty * $price;
                    $total_all += $line_total;
            ?>
            <tr>
                <td><?php echo htmlspecialchars($l['line_text'] ?? ''); ?></td>
                <td class="text-right"><?php echo number_format($qty, 2, ',', '.'); ?></td>
                <td class="text-right"><?php echo number_format($price, 2, ',', '.'); ?></td>
                <td class="text-right"><?php echo number_format($line_total, 2, ',', '.'); ?></td>
            </tr>
            <?php 
                endforeach; 
            else:
            ?>
            <tr>
                <td colspan="4" style="text-align: center;">Ingen linjer fundet på denne faktura.</td>
            </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="text-right"><strong>Total ekskl. moms:</strong></td>
                <td class="text-right"><strong><?php echo number_format($total_all, 2, ',', '.'); ?></strong></td>
            </tr>
        </tfoot>
    </table>

</body>
</html>