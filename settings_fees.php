<?php # /settings_fees.php v:1.3.0 d:2026-08-30 i:evs
# Gebyrregler for automatisk postering af bankgebyrer ved afstemning
# (reconcile_list.php). rate/fixed parses med parse_dk_number(); source-
# feltets hint oversætter korrekt. menu_visibility.php's egen standard-
# opsætning kategoriserer denne side som niveau-3-kun (samme liste som
# chart_of_accounts.php/storage_browser.php/user_list.php), men det var
# aldrig håndhævet server-side her - kun skjult i menuen. En niveau-1-
# bruger kunne tilgå siden direkte via URL'en og ændre/slette reglerne der
# styrer automatisk bogføring af bankgebyrer. $rLev tilføjet.
$rLev = 3;
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php'; // Sikr at denne er med

$s   = get_settings($conn);
$cur = $s['currency'] ?? 'DKK';

$msg = "";

// --- GEM/SLET FUNKTIONER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_rule'])) {
    $source = strtolower(trim((string)($_POST['source'] ?? '')));
    if ($source === '') redirect('settings_fees.php', '@Please enter the name of the source.', 'error');      // (en regel uden navn blev gemt som "fee_rule_")
    $key    = 'fee_rule_' . $source;
    // RETTET: (float) alene mangler samme komma->punktum-normalisering som en lang række andre beløbsfelter denne
    // session - erstattet med parse_dk_number.
    // RETTET: model blev gemt ufiltreret (og vist uescapet nedenfor) - nu kun de tre kendte modeller
    $model  = in_array($_POST['model'] ?? '', ['fixed', 'relative', 'mixed'], true) ? $_POST['model'] : 'fixed';
    // RETTET: modellen hedder "Procent", men feltet forventede en brøk (0,014) - "1,4" (for 1,4 %) blev gemt som 1,4, og
    // gebyrformlen beløb / (1 - sats) gav et negativt gebyr (ved 1: division med nul). Feltet er nu i procent (0-99),
    // og der gemmes stadig en brøk, så eksisterende regler er uændrede.
    $rate_pct = tc_num($_POST['rate'] ?? '0', 0, 99.99);
    $fixed    = tc_num($_POST['fixed'] ?? '0', 0);
    if ($rate_pct === null || $fixed === null) redirect('settings_fees.php', '@Please enter a percentage between 0 and 99 and a fixed amount of 0 or more.', 'error');
    $data   = ['model' => $model, 'rate' => round($rate_pct / 100, 6), 'fixed' => $fixed];
    $value  = json_encode($data);

    // RETTET: brugte en hjemmelavet str_replace("'", "''",...)-escaping i stedet for den fælles DB::escape - i
    // strid med projektets egen faste regel ("gå aldrig uden om DB-klassen"). Praktisk risiko var lav her (utf8mb4
    // er ikke sårbar over for det klassiske multi-byte-trick denne slags hjemmelavet escaping ellers kan åbne
    // for), men rettet for konsistens og for ikke at lade mønstret sprede sig videre.
    DB::query($conn, "DELETE FROM settings WHERE setting_key = '" . DB::escape($conn, $key) . "'");
    DB::query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('" . DB::escape($conn, $key) . "', '" . DB::escape($conn, $value) . "')");

    $msg = lang('@Rule saved successfully');
}

// RETTET: ?delete= slettede ENHVER indstilling (fx firmanavn, kontonumre), ikke kun gebyrregler - nu kun fee_rule_*
if (isset($_GET['delete']) && strpos((string)$_GET['delete'], 'fee_rule_') === 0) {
    if (!array_key_exists((string)$_GET['delete'], get_settings($conn))) redirect('settings_fees.php', '@Not found - it may already have been deleted.', 'error');      // RETTET: meldte succes, selv om intet fandtes (dobbeltklik, en anden fane)
    $key = DB::escape($conn, $_GET['delete']);
    DB::query($conn, "DELETE FROM settings WHERE setting_key = '$key'");
    redirect('settings_fees.php', '@Rule deleted successfully');
}

htm_Header('@Fee Rules');
showMenu();

if ($msg) htm_Alert($msg, 'success');

htm_Card_('@Configure Fee Rules', '800');
?>

<form method="post">
    <?php csrf_field(); ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: start; margin-bottom: 20px;">
        <?php 
        // RETTET: hint-nøglen var selv skrevet på dansk ("Kildens navn") i stedet for engelsk, i strid med
        // projektets konvention om at nøgler altid er den engelske kildetekst - oversatte derfor aldrig for andre
        // sprog end dansk (faldt tilbage til den danske tekst).
        htm_Field(icon: 'fa-tag', labl: '@Source', name: 'source', extr: 'required placeholder="'.lang('@Name').'" data-hint="'.lang('@The name of the source').'"');

        $models = ['fixed' => lang('@Fixed amount'), 'relative' => lang('@Percentage'), 'mixed' => lang('@Mixed')];
        htm_Field(icon: 'fa-calculator', labl: '@Model', name: 'model', valu: 'fixed', type: 'sele', opti: $models); 

        $dual_input = '
            <div style="display: flex; gap: 5px; align-items: center;">
                <input type="number" name="rate" step="0.01" min="0" max="99.99" placeholder="1.4 %" title="%" style="width: 100%; border: none; outline: none; background: transparent;">
                <span style="color:var(--text-muted);">|</span>
                <input type="number" name="fixed" step="0.01" value="1.00" style="width: 100%; border: none; outline: none; background: transparent;">
            </div>';
        htm_Field(icon: 'fa-percent', labl: '@Rate / Fixed', name: 'combined', valu: $dual_input, type: 'raw'); 
        ?>
    </div>

    <?php
    // BRUG HTM_BUTTON TIL GEM
    htm_Button(
        icon: 'fa-save',
        labl: '@Save Rule',
        type: 'success',
        styl: 'padding: 12px 40px;',
        attr: 'name="save_rule" data-hint="'.lang('@Save this fee rule').'"',
        cont: '<div style="text-align: right; margin-bottom: 30px;"></div>'
    );
    ?>
</form>

<fieldset style="padding: 15px; border: 2px solid #ddd; border-radius: 8px;">
    <legend style="color: var(--text-muted); font-size: 0.9em; padding: 0 10px; text-align: center;';">
                <?php echo lang('@Active Rules'); ?>
    </legend>
    <table style="width:100%; border-collapse:collapse;">
        <tbody>
            <?php
            $res = DB::query($conn, "SELECT * FROM settings WHERE setting_key LIKE 'fee_rule_%'");
            if (DB::num_rows($res) == 0) {
                echo '<tr><td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">'.
                    lang('@No fee rules found').'</td></tr>';
            } else {
                while ($r = DB::fetch_assoc($res)) {
                    $source = str_replace('fee_rule_', '', $r['setting_key']);
                    $val = json_decode($r['setting_value'], true);
                    
                    // Sikkerhedstjek: Hvis json_decode fejlede, giv den en standardværdi
                    if (!is_array($val)) $val = ['model' => 'fixed', 'rate' => 0, 'fixed' => 0];
                    if (!in_array($val['model'] ?? '', ['fixed', 'relative', 'mixed'], true)) $val['model'] = 'fixed';
                    
                    // Formater satser pænt til visning
                    $rate_display = ($val['model'] === 'relative' || $val['model'] === 'mixed') ? fmt_dk($val['rate'] * 100, 2) . ' %' : '-';
                    $fixed_display = ($val['model'] === 'fixed' || $val['model'] === 'mixed') ? fmt_dk($val['fixed']) . ' ' . $cur : '-';
                    if ($val['model'] === 'mixed') {
                        $rate_display = fmt_dk($val['rate'] * 100, 2) . ' % + ' . fmt_dk($val['fixed']) . ' ' . $cur;
                        $fixed_display = ''; // Samlet i rate_display for overskuelighed
                    } elseif ($val['model'] === 'relative') {
                        $rate_display = fmt_dk($val['rate'] * 100, 2) . ' %';
                    } elseif ($val['model'] === 'fixed') {
                        $rate_display = fmt_dk($val['fixed']) . ' ' . $cur;
                    }

                    // RETTET: lang('@'.ucfirst(model)) gav engelsk "Fixed"/"Relative" (tomme oversættelser) - samme tekster som valglisten
                    $model_lbl = ['fixed' => lang('@Fixed amount'), 'relative' => lang('@Percentage'), 'mixed' => lang('@Mixed')][$val['model']] ?? htmlspecialchars((string)$val['model']);
                    echo '<tr style="border-bottom:1px solid #f9f9f9;">
                            <td style="padding:12px; font-weight:bold;">' . htmlspecialchars(ucfirst($source)) . '</td>
                            <td style="padding:12px;">'. $model_lbl . '</td>
                            <td style="padding:12px; color: var(--text-muted);">' . $rate_display . '</td>
                            <td style="padding:12px; text-align:right;">';
                    
                    htm_ConfirmLink(
                        icon: 'fa-trash-can',
                        // RETTET: kildenavnet blev hverken URL-kodet her eller HTML-escapet ovenfor - en kilde med fx
                        // "&" eller "#" i navnet kunne ikke slettes (linket ramte en anden nøgle), og navnet kunne
                        // indeholde HTML/script der kørte hos den næste administrator der åbnede siden.
                        link: "?delete=" . urlencode($r['setting_key']),
                        mess: '@Are you sure?',
                        type: 'danger',
                        styl: 'padding: 4px 8px; font-size: 12px;',
                        attr: 'data-hint="'.lang('@Delete this fee rule').'"'
                    );
                    echo '</td></tr>';
                }
            }
            ?>
        </tbody>
    </table>
</fieldset>

<?php
htm_Card_end();
htm_Footer();
ob_end_flush();
?>