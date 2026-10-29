<?php # /reconcile_action.php v:1.3.1 d:2026-09-15 i:evs
# fee_amount manglede parse_dk_number() - se [[settings-fees-reconcile-bugs-review]]
# v1.7.1: fee_amount castedes direkte via (float), samme sårbarhed som andre
# beløbsfelter denne session (komma-formateret input kunne blive stille
# afkortet) - gebyret bogføres direkte på hovedbogen, så det er en reel
# regnskabsrisiko, ikke kun kosmetisk. Erstattet med parse_dk_number().
# v1.7.0: betalingsposteringen krediterede aldrig debitorkontoen, balancerede derfor aldrig til 0; rettet + tilføjet delvis betaling via ny invoice_payments-tabel, se selve posteringen for fuld forklaring.
# (Tilføjet proj_id på journal + auto-expense ved scenarie B)
# v1.3.0: bankkonto fra conf_acc_bank (var hardkodet forkert til 1000/"Salg");
# journalpost får nu voucher_no fra den fælles next_voucher_no()
# v1.4.0: tilføjet periodespærring (is_date_locked) - manglede helt
# v1.5.0: KRITISK - en kladde-faktura kunne markeres 'paid' direkte her, helt
# uden om bogføringen. Tilføjet statustjek. Faktura-/fakturaflow-gennemgang.
#
# RETTET (bruger-anmodet: "bekræft alle foreslåede match" på
# reconcile_list.php): selve posterings-logikken herunder er UÆNDRET, kun
# flyttet ind i en funktion (reconcile_one_bank_line()) der returnerer et
# resultat i stedet for at die()/redirecte direkte - så den kan genbruges BÅDE
# for et enkelt POST (uændret ekstern opførsel, se bunden af filen) OG i et
# loop for en bulk-bekræftelse af flere banklinjer på én gang, uden at
# duplikere den finansielt kritiske dobbelt-bogholderi-/FX-/gebyr-logik to
# steder (som uundgåeligt ville glide fra hinanden over tid). Hver linje i et
# bulk-kald får stadig sin EGEN transaktion (begin/commit/rollback inde i
# funktionen) - én fejlende linje kan derfor aldrig rulle de andre tilbage.
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/audit.inc.php';
// NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): reconcile_one_
// bank_line() bor nu i inc/reconcile.inc.php, se dens egen header for
// hvorfor - denne fils bundlogik (nedenfor) er uændret.
require_once 'inc/reconcile.inc.php';

// --- BULK: bekræft flere foreslåede match på én gang (bruger-anmodet) ---
// Bevidst begrænset til scenarie A (fakturamatch) - kun der findes et
// entydigt, allerede validerede forslag (reconcile_list.php's
// $match_candidates-tjek: PRÆCIS én kandidat). Scenarie B (fri kontering)
// har ingen tilsvarende pålidelig auto-gæt at bulk-bekræfte - at gætte forkert
// dér ville poste et beløb på en vilkårlig konto uden reelt grundlag. Ingen
// gebyr-/FX-justering i bulk-tilstand (samme neutrale standardværdier som et
// enkelt-match uden ekstra input fra brugeren).
if (isset($_POST['bulk_pairs']) && is_array($_POST['bulk_pairs'])) {
    $sent = 0; $failed = 0;
    foreach ($_POST['bulk_pairs'] as $pair) {
        $p_tmp    = isset($pair['tmp_id'])    ? (int)$pair['tmp_id']    : 0;
        $p_target = isset($pair['target_id']) ? (int)$pair['target_id'] : 0;
        if ($p_tmp <= 0 || $p_target <= 0) { $failed++; continue; }
        $result = reconcile_one_bank_line($conn, $global_settings, $p_tmp, $p_target, 0, 0.0, 2320, 0, false);
        if ($result['ok']) { $sent++; } else { $failed++; }
    }
    header("Location: reconcile_list.php?msg=bulk&sent=$sent&failed=$failed");
    ob_end_flush();
    exit;
}

// --- ENKELT-TILSTAND (uændret ekstern opførsel ift. tidligere version) ---
$tmp_id     = isset($_POST['tmp_id'])     ? (int)$_POST['tmp_id']     : 0;
// NYT (webbutik): "Udbetaling fra webbutik X" (target_id = "shop:<id>") - mod butikkens mellemregningskonto, gebyret med
if (preg_match('/^shop:(\d+)$/', (string)($_POST['target_id'] ?? ''), $sm)) {
    $p_res = reconcile_shop_payout($conn, $global_settings, $tmp_id, (int)$sm[1],
        isset($_POST['fee_amount']) ? parse_dk_number($_POST['fee_amount']) : 0.0, (int)($_POST['fee_acc_id'] ?? 0));
    if (!empty($p_res['ok'])) { header("Location: reconcile_list.php?msg=success"); }
    elseif (!empty($p_res['already_processed'])) { header("Location: reconcile_list.php?msg=already_processed"); }
    else { $_SESSION['flash_error'] = $p_res['message'] ?? lang('@Error:'); header("Location: reconcile_list.php"); }
    ob_end_flush();
    exit;
}
$target_id  = isset($_POST['target_id'])  ? (int)$_POST['target_id']  : 0;
$acc_id     = isset($_POST['acc_id'])     ? (int)$_POST['acc_id']     : 0;
$fee_amount = isset($_POST['fee_amount']) ? parse_dk_number($_POST['fee_amount']) : 0;
$fee_acc_id = isset($_POST['fee_acc_id']) ? (int)$_POST['fee_acc_id'] : 2320;
$proj_id    = isset($_POST['proj_id'])    ? (int)$_POST['proj_id']    : 0;
$close_fx_invoice = isset($_POST['close_fx_invoice']) && $_POST['close_fx_invoice'] === '1';

$result = reconcile_one_bank_line($conn, $global_settings, $tmp_id, $target_id, $acc_id, $fee_amount, $fee_acc_id, $proj_id, $close_fx_invoice);

// NYT (bruger-anmodet, "kan bogholderen ønske sig mere", runde 33): "Husk
// denne regel"-afkrydsningsfeltet på reconcile_list.php - gemmer et
// "beskrivelse indeholder X -> konto Y"-mønster, som fremover foreslås
// automatisk for tilsvarende banklinjer. Kun relevant for udgiftslinjer
// (target_id=0, direkte kontering) - en fakturamatch har intet konto-valg
// at gemme en regel for. Stille degradering hvis migrationen ikke er kørt.
if ($result['ok'] && $target_id === 0 && $acc_id > 0 && isset($_POST['save_rule']) && $_POST['save_rule'] === '1') {
    $rule_pattern = trim($_POST['rule_pattern'] ?? '');
    if ($rule_pattern !== '') {
        $has_rules_table = false;
        if (DB::is_sqlite()) {
            $brt = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='bank_match_rules'");
            $has_rules_table = ($brt && DB::fetch_assoc($brt));
        } else {
            $brt = DB::query($conn, "SHOW TABLES LIKE 'bank_match_rules'");
            $has_rules_table = ($brt && DB::num_rows($brt) > 0);
        }
        if ($has_rules_table) {
            $dup = DB::fetch_assoc(DB::query($conn, "SELECT rule_id FROM bank_match_rules WHERE pattern = '" . DB::escape($conn, $rule_pattern) . "' AND acc_id = $acc_id"));
            if (!$dup) {
                DB::insert($conn, 'bank_match_rules', [
                    'pattern'    => $rule_pattern,
                    'acc_id'     => $acc_id,
                    'created_by' => (int)($_SESSION['user_id'] ?? 0),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}

if (!empty($result['already_processed'])) {
    header("Location: reconcile_list.php?msg=already_processed");
} elseif ($result['ok']) {
    header("Location: reconcile_list.php?msg=success");
} else {
    die($result['message']);
}
ob_end_flush();
?>
