<?php # /setup_chart.php v:1.0.1 d:2026-06-14 i:evs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

// Tjek om tabellen accounts allerede indeholder data
$coaInUse = false;
$resCheck = mysqli_query($conn, "SELECT COUNT(*) FROM accounts");
if ($resCheck) {
    $row = mysqli_fetch_row($resCheck);
    $coaInUse = ($row[0] > 0);
}

// 1. HÅNDTER GEM-PROCES (Kun hvis der ikke allerede er spærret)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['template']) && !$coaInUse) {
    $template = $_POST['template']; 
    $json_file = __DIR__ . "/json-data/coa_{$template}.json";

    if (file_exists($json_file)) {
        $accounts = json_decode(file_get_contents($json_file), true);
      
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");  // 1. Slå foreign key checks fra midlertidigt
        mysqli_query($conn, "TRUNCATE TABLE accounts");     // 2. Dine nuværende TRUNCATE / slette-kommandoer (f.eks. linje 27)
        // mysqli_query($conn, "TRUNCATE TABLE ledger");    // Tømmer også ledger, hvis det er meningen
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");  // 3. Slå foreign key checks til igen (Meget vigtigt!)

        $stmt = mysqli_prepare($conn, "INSERT INTO accounts (acc_id, acc_name, acc_type, vat_code, vat_rate) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($accounts as $acc) {
            $acc_id   = (int)$acc['account_no'];
            $acc_name = $acc['name'];
            $acc_type = $acc['type'];
            $vat_code = $acc['vat_code'] ?? null;
            $vat_rate = $acc['vat_rate'] ?? 0.00;

            mysqli_stmt_bind_param($stmt, "isssd", $acc_id, $acc_name, $acc_type, $vat_code, $vat_rate);
            mysqli_stmt_execute($stmt);
        }
        
        mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('setup_complete', '1') ON DUPLICATE KEY UPDATE setting_value='1'");

        header("Location: sales_hub.php");
        exit;
    }
}

// 2. INDLÆS DATA TIL FORHÅNDSVISNING I JAVASCRIPT
$preview_data = [];
foreach (['simple', 'standard', 'extended'] as $tpl) {
    $file = __DIR__ . "/json-data/coa_{$tpl}.json";
    if (file_exists($file)) {
        $preview_data[$tpl] = json_decode(file_get_contents($file), true);
    } else {
        $preview_data[$tpl] = [];
    }
}

// Standard TinyCash header integration
htm_Header("@Vælg Kontoplan");
showMenu();

// Omslut alt i standard max-width container for ensartet layout
echo "<div style='max-width: 1000px; margin: 0 auto;'>";
    
    htm_Card_('@Konfiguration af Kontoplan',900);
    ?>
    <div style="max-width: 900px; margin: 0 auto; padding: 10px; font-family: sans-serif;">
        <div style="text-align: center; margin-bottom: 30px;">
            <p style="color: #7f8c8d; font-size: 1.1em;"><?php echo lang('@Vælg og vurder strukturen nedenfor, før du låser dit valg.'); ?></p>
        </div>

        <form method="post" id="coa-form">
            <div style="display: flex; gap: 15px; margin-bottom: 25px;">
        
                <div style="flex: 1; border: 2px solid #ddd; border-radius: 8px; padding: 15px; background: #fff; cursor: pointer; transition: all 0.2s;" id="box-simple" onclick="selectTemplate('simple')">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="radio" name="template" value="simple" id="radio-simple" checked style="transform: scale(1.2);">
                        <strong style="font-size: 1rem; color: #2c3e50;"><?php echo lang('@simple'); ?></strong>
                    </div>
                    <p style="font-size: 0.8rem; color: #7f8c8d; margin-top: 8px; line-height: 1.4;">
                        <?php echo lang('@Kun de mest nødvendige konti til salg og simple driftsudgifter. Ideel til freelancere uden varelager.'); ?>
                    </p>
                </div>

                <div style="flex: 1; border: 2px solid #ddd; border-radius: 8px; padding: 15px; background: #fff; cursor: pointer; transition: all 0.2s;" id="box-standard" onclick="selectTemplate('standard')">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="radio" name="template" value="standard" id="radio-standard" style="transform: scale(1.2);">
                        <strong style="font-size: 1rem; color: #2c3e50;"><?php echo lang('@Standard'); ?></strong>
                    </div>
                    <p style="font-size: 0.8rem; color: #7f8c8d; margin-top: 8px; line-height: 1.4;">
                        <?php echo lang('@Traditionel kontoplan med opdelt salg, mindre varelager, bildrift, administration og præcis momshåndtering.'); ?>
                    </p>
                </div>

                <div style="flex: 1; border: 2px solid #ddd; border-radius: 8px; padding: 15px; background: #fff; cursor: pointer; transition: all 0.2s;" id="box-extended" onclick="selectTemplate('extended')">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="radio" name="template" value="extended" id="radio-extended" style="transform: scale(1.2);">
                        <strong style="font-size: 1rem; color: #2c3e50;"><?php echo lang('@Extended'); ?></strong>
                    </div>
                    <p style="font-size: 0.8rem; color: #7f8c8d; margin-top: 8px; line-height: 1.4;">
                        <?php echo lang('@Fuldt udbygget erhvervskontoplan. God til ApS/A/S med ansatte, afskrivninger, finansielle poster og ejendomme.'); ?>
                    </p>
                </div>

            </div>

            <div style="background: #ebf8ff; border-left: 4px solid #3182ce; color: #2b6cb0; padding: 15px; border-radius: 4px; margin-bottom: 20px; font-size: 0.9rem; line-height: 1.5;">
                <strong style="color: #2c5282;"><i class="fa fa-info-circle"></i> <?php echo lang('@Bemærk angående fleksibilitet:'); ?></strong><br>
                <?php echo lang('@Dit valg herover er blot et udgangspunkt. Når installationen er fuldført, kan du altid frit oprette nye konti under dine indstillinger. Du kan også slette de standardkonti, du ikke har brugt i din bogføring endnu.'); ?>
            </div>

            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                <h4 style="margin: 0 0 15px 0; color: #34495e; display: flex; justify-content: space-between; align-items: center;">
                    <span><i class="fa fa-eye"></i> <?php echo lang('@Forhåndsvisning af den valgte kontoplan'); ?></span>
                    <span id="account-count" style="font-size: 0.8rem; background: #edf2f7; padding: 4px 10px; border-radius: 20px; color: #4a5568;">0 konti</span>
                </h4>
                
                <div style="max-height: 300px; overflow-y: auto; border: 1px solid #edf2f7; border-radius: 4px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left;">
                        <thead style="background: #f7fafc; position: sticky; top: 0; z-index: 2;">
                            <tr>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; width: 100px;"><?php echo lang('@Konto'); ?></th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0;"><?php echo lang('@Kontonavn'); ?></th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; width: 120px;"><?php echo lang('@Type'); ?></th>
                                <th style="padding: 10px; border-bottom: 2px solid #e2e8f0; width: 140px;"><?php echo lang('@Moms %'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="preview-table-body">
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="text-align: right;">
                <?php if ($coaInUse): ?>
                    <button type="button" disabled style="padding: 12px 30px; color: #7f8c8d; border: 1px solid #bdc3c7; border-radius: 6px; font-weight: bold; font-size: 1rem; cursor: not-allowed; background-color: #e2e8f0;" title="<?php echo lang('@Systemet indeholder allerede data. Installation er låst.'); ?>">
                        ❌ <?php echo lang('@Kontoplan er allerede i brug'); ?>
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn bg-edit" style="padding: 12px 30px; color: #fff; border: none; border-radius: 6px; font-weight: bold; font-size: 1rem; cursor: pointer; background-color: #2b6cb0;">
                        <i class="fa fa-check-circle"></i> <?php echo lang('@Installer valgt kontoplan og start'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php
    htm_Card_end();

echo "</div>";

?>
<script>
const coaTemplates = <?php echo json_encode($preview_data); ?>;

function selectTemplate(templateName) {
    document.getElementById('radio-' + templateName).checked = true;
    const templates = ['simple', 'standard', 'extended'];
    
    templates.forEach(tpl => {
        const box = document.getElementById('box-' + tpl);
        if (tpl === templateName) {
            box.style.borderColor = '#2b6cb0';
            box.style.background = '#f7fafc';
        } else {
            box.style.borderColor = '#ddd';
            box.style.background = '#fff';
        }
    });
    
    const accounts = coaTemplates[templateName] || [];
    const tbody = document.getElementById('preview-table-body');
    document.getElementById('account-count').innerText = accounts.length + ' konti';
    
    tbody.innerHTML = ''; 
    
    accounts.forEach(acc => {
        const row = document.createElement('tr');
        row.style.borderBottom = '1px solid #edf2f7';
        
        let typeColor = '#2c3e50';
        if (acc.type === 'revenue') typeColor = '#2f855a';
        if (acc.type === 'expense') typeColor = '#c53030';
        
        const accountNo = acc.account_no !== undefined ? acc.account_no : '';
        const name = acc.name !== undefined ? acc.name : '';
        const type = acc.type !== undefined ? acc.type : '';
        
        const vatCode = acc.vat_code !== undefined && acc.vat_code !== null ? acc.vat_code : '';
        const vatRate = acc.vat_rate !== undefined && acc.vat_rate !== null ? acc.vat_rate + '%' : '0%';
        
        const vatDisplay = vatCode ? `${vatCode} (${vatRate})` : vatRate;
        
        row.innerHTML = `
            <td style="padding: 8px 10px; font-family: monospace; font-weight: bold;">${accountNo}</td>
            <td style="padding: 8px 10px;">${name}</td>
            <td style="padding: 8px 10px; color: ${typeColor}; font-size: 0.8rem; text-transform: uppercase;">${type}</td>
            <td style="padding: 8px 10px; font-family: monospace;">${vatDisplay}</td>
        `;
        tbody.appendChild(row);
    });
}

// Starter automatisk visningen op med den valgte skabelon
selectTemplate('simple');
</script>

<?php
htm_Footer();
?>