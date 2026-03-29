<?php # transaktioner.page.php
include_once('db_connect.inc.php');
include_once('auth.inc.php');
include_once('menu.inc.php');
include_once('header.inc.php');

showMenu();

// 1. Hent alle transaktioner med kontonavn og standard-kategori mapping
$sql = "SELECT t.*, a.acc_name, s.std_id, s.std_name 
        FROM transactions t
        LEFT JOIN accounts a ON t.acc_id = a.acc_id
        LEFT JOIN std_accounts s ON a.std_ref_id = s.std_id
        ORDER BY t.trans_date DESC, t.trans_id DESC";
$result = mysqli_query($conn, $sql);
?>

<div style="max-width:1100px; margin:20px auto; font-family:sans-serif;">
    
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid #34495e; padding-bottom:15px; margin-bottom:20px;">
        <div>
            <h2 style="margin:0; color:#2c3e50;">🧾 Transaktionsliste / Hovedbog</h2>
            <p style="margin:5px 0 0; color:#7f8c8d; font-size:0.9em;">Oversigt over alle bogførte poster og momsbevægelser</p>
        </div>
        <button onclick="window.print();" style="background:#34495e; color:white; border:none; padding:10px 18px; border-radius:4px; cursor:pointer; font-weight:bold;">
            🖨️ Udskriv til PDF
        </button>
    </div>

    <?php if(isset($_GET['msg']) && $_GET['msg'] == 'slettet'): ?>
        <div style="background:#fff3cd; color:#856404; padding:15px; margin-bottom:20px; border-radius:5px; border:1px solid #ffeeba;">
            ⚠️ Posteringen er blevet slettet fra regnskabet.
        </div>
    <?php endif; ?>

    <table style="width:100%; border-collapse:collapse; background:white; box-shadow:0 2px 10px rgba(0,0,0,0.1); border-radius:8px; overflow:hidden;">
        <thead>
            <tr style="background:#34495e; color:white; text-align:left;">
                <th style="padding:15px;">Dato</th>
                <th style="padding:15px;">Bilagstekst / Ref</th>
                <th style="padding:15px;">Konto (Mapping)</th>
                <th style="padding:15px; text-align:right;">Beløb inkl. moms</th>
                <th style="padding:15px; text-align:right;">Momsbeløb</th>
                <th style="padding:15px; text-align:center;">Handling</th>
            </tr>
        </thead>
        <tbody>
            <?php if(mysqli_num_rows($result) > 0): ?>
            <?php 
            while($row = mysqli_fetch_assoc($result)): 
                // Beregn om posteringen er over 30 dage gammel
                $dato_timestamp = strtotime($row['trans_date']);
                $nu = time();
                $dage_gamle = ($nu - $dato_timestamp) / (60 * 60 * 24);
                $er_laast = ($dage_gamle > 30);
            ?>
            <tr style="border-bottom:1px solid #eee; <?php echo $er_laast ? 'opacity: 0.8;' : ''; ?>">
                
                <td style="padding:15px; text-align:center; white-space:nowrap;">
                    <?php if(!empty($row['attachment_path'])): ?>
                        <a href="uploads/<?php echo $row['attachment_path']; ?>" target="_blank" style="text-decoration:none; margin-right:12px;" title="Se bilag">
                            📎
                        </a>
                    <?php endif; ?>
                    
                    <?php if($er_laast): ?>
                        <span title="Denne postering er over 30 dage gammel og kan ikke slettes (Lovkrav)" style="cursor:help; filter:grayscale(100%); opacity:0.3; font-size:1.1em;">
                            🔒
                        </span>
                    <?php else: ?>
                        <a href="faktura_actions.php?action=slet_transaktion&id=<?php echo $row['trans_id']; ?>" 
                           style="color:#e74c3c; text-decoration:none; font-size:1.1em;" 
                           onclick="return confirm('Er du sikker? Bilaget slettes også fysisk fra serveren.');"
                           title="Slet postering (Inden for 30 dage)">
                           🗑️
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="padding:60px; text-align:center; color:#95a5a6;">
                        <div style="font-size:3em; margin-bottom:10px;">📋</div>
                        Ingen transaktioner fundet i systemet endnu.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div style="margin-top:20px; text-align:right; color:#7f8c8d; font-size:0.85em;">
        * Alle beløb vises i DKK. Momsen er beregnet ud fra kontoens momssats på bogføringstidspunktet.
    </div>
</div>

<?php include_once('footer.inc.php'); ?>