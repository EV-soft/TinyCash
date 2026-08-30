<?php # /vat_codes.php v:1.3.0 d:2026-08-30 i:evs
# logger nu sletning til revisionssporet
# KRITISK (§bugs-batch-15-review): INTET niveau-tjek - momskoder/-satser
# styrer momsberegningen på hver eneste faktura og udgift i systemet, lige
# så følsomt som chart_of_accounts.php/settings_fees.php (som begge fik
# samme rettelse i [[bugs-batch-12-review]]), men blev overset her.
$rLev = 3;
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';
require 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

// Håndter sletning
if (isset($_GET['delete'])) {
    $id = DB::real_escape_string($conn, $_GET['delete']);
    // Hent momskodens data FØR sletning, til revisionssporet.
    $old_res = DB::query($conn, "SELECT vat_id, vat_name, vat_rate, vat_account FROM vat_codes WHERE vat_id = '$id'");
    $old_row = $old_res ? DB::fetch_assoc($old_res) : null;
    if (DB::query($conn, "DELETE FROM vat_codes WHERE vat_id = '$id'") && $old_row) {
        log_action($conn, 'DELETE_VAT_CODE', 'vat_codes', 0, $old_row, null);
    }
    header("Location: vat_codes.php"); exit;
}

htm_Header('@VAT Codes');
showMenu();

echo "<div style='max-width:800px; margin:0 auto;'>";
    
    // Oversigt
    // RETTET (§bugs-batch-22-review, del b): erstattet den håndrullede
    // <table> med htm_Table() (se csrf-protection-added.md/
    // htm-alert-banner-refactor.md for baggrunden). Tilføjede samtidig
    // htmlspecialchars() på vat_id/vat_name (manglede FØR helt - lav risiko
    // her da siden er admin-only, men billigt at rette når linjerne
    // alligevel skulle omskrives).
    htm_Card_('@VAT Codes', '600');
    $vat_rows = [];
    $res = DB::query($conn, "SELECT * FROM vat_codes ORDER BY vat_id ASC");
    while ($row = DB::fetch_assoc($res)) {
        // RETTET: den faktiske kolonne hedder vat_account, ikke vat_acc_id
        // (som denne linje hidtil læste) - visningen viste derfor altid "-"
        // uanset hvad der reelt var gemt.
        $acc_id = $row['vat_account'] ?? '-';

        // Erstattet det hårdkodede, danske "Slet?" onclick-confirm (uden om
        // lang()) med htm_ConfirmLink, som bruger '@Are you sure?' og
        // escaper teksten korrekt.
        $delBtn = htm_ConfirmLink(
            icon: 'fa-trash',
            link: '?delete='.$row['vat_id'],
            mess: '@Are you sure?',
            type: 'danger',
            styl: 'padding:0; background:transparent; color:var(--color-danger); font-size:16px;',
            attr: 'data-hint="'.lang('@Delete this VAT code').'"',
            echo: false
        );

        $vat_rows[] = [
            '<b>'.htmlspecialchars($row['vat_id']).'</b>',
            htmlspecialchars($row['vat_name']),
            '<div style="text-align:center;">'.$row['vat_rate'].'%</div>',
            '<div style="text-align:right;">'.htmlspecialchars((string)$acc_id).'</div>',
            '<div style="text-align:right;">'.$delBtn.'</div>',
        ];
    }
    htm_Table(['ID', '@Name', '%', '@Account', ''], $vat_rows, 'vat_tbl', 100);
    htm_Card_end();

    // Formular til at tilføje ny
    echo "<div style='margin-top:20px;'>";
    htm_Card_('@Add New VAT Code', '600');
    ?>
    <form method="post" action="vat_save.php">
        <?php csrf_field(); ?>
        <div style="display: grid; grid-template-columns: 1fr 2fr 1fr 1fr; gap: 10px; align-items: end;">
            <?php 
                htm_Field('🔑', 'ID', 'vat_id', '', 'text', null, 'required placeholder="f.eks. S25"');
                htm_Field('📝', '@Name', 'vat_name', '', 'text', null, 'required');
                htm_Field('📊', '%', 'vat_rate', '25', 'number');
                // RETTET: feltet hed hidtil "vat_acc_id", men vat_save.php
                // læser $_POST['vat_account'] og DB-kolonnen hedder samme -
                // mismatchet betød at kontonummeret aldrig blev gemt (endte
                // altid som NULL uanset hvad brugeren indtastede).
                htm_Field('📑', '@Account', 'vat_account', '', 'number', null, 'required');
            ?>
            <button type="submit" style="background:#2ecc71; color:white; border:none; padding:12px; border-radius:4px; cursor:pointer; font-weight:bold; grid-column: span 4;">
                ➕ <?php echo lang('@Add VAT Code'); ?>
            </button>
        </div>
    </form>
    <?php
    htm_Card_end();
    echo "</div>";
echo "</div>";
htm_Footer();
?>
