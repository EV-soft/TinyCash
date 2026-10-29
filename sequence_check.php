<?php # /sequence_check.php v:1.3.1 d:2026-09-15 i:evs
# Bilagsnummer-/fakturanummer-kontrol: verificerer at journal.voucher_no og
# invoices.invoice_no hver danner en SAMMENHÆNGENDE talrække uden huller -
# en af de første ting en revisor tjekker ("er noget blevet slettet uden om
# systemet"). Rent læse-værktøj, ingen skrivning/rettelse - viser blot hvad
# der reelt findes.
#
# De to sekvenser er bevidst adskilte (samme skel som resten af systemet):
# voucher_no tildeles ved selve bogføringen af ALLE posteringstyper
# (next_voucher_no(), fælles tæller), invoice_no er specifikt kundevendte
# fakturanumre (next_invoice_no(), egen tæller) - en kreditnota får fx et
# voucher_no, men intet invoice_no. Begge tælleres tildeling sker atomisk
# inde i den kaldende transaktion (se posting-accounts/bogforingslov-
# compliance i hukommelsen), så en "sund" installation forventes at have
# INGEN huller i nogen af sekvenserne - et fund her er et reelt signal, ikke
# støj.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';

// Komprimerer en liste af tal til letlæste intervaller, fx [5,6,7,9] -> "5–7, 9".
function seq_compress_ranges(array $numbers): string {
    if (empty($numbers)) return '';
    sort($numbers);
    $out = [];
    $start = $prev = null;
    foreach ($numbers as $n) {
        if ($start === null) { $start = $prev = $n; continue; }
        if ($n == $prev + 1) { $prev = $n; continue; }
        $out[] = ($start == $prev) ? (string)$start : "{$start}–{$prev}";
        $start = $prev = $n;
    }
    $out[] = ($start == $prev) ? (string)$start : "{$start}–{$prev}";
    return implode(', ', $out);
}

// RETTET: array_unique() fjernede dubletterne FØR optællingen - et bilags- eller fakturanummer der var brugt TO
// gange, blev aldrig rapporteret, og det er netop det en revisor leder efter (to bilag med samme nummer).
function seq_find_gaps(array $numbers): array {
    $all     = array_map('intval', $numbers);
    $dupes   = array_keys(array_filter(array_count_values($all), fn($c) => $c > 1));
    sort($dupes);
    $numbers = array_values(array_unique($all));
    if (empty($numbers)) {
        return ['min' => null, 'max' => null, 'count' => 0, 'expected' => 0, 'missing' => [], 'dupes' => []];
    }
    sort($numbers);
    $min = $numbers[0];
    $max = end($numbers);
    $expected = $max - $min + 1;
    $missing = array_values(array_diff(range($min, $max), $numbers));
    return ['min' => $min, 'max' => $max, 'count' => count($numbers), 'expected' => $expected, 'missing' => $missing, 'dupes' => $dupes];
}

function seq_render_section($capt, array $gaps) {
    htm_Card_($capt, 900);
    if ($gaps['count'] === 0) {
        echo '<p style="color:var(--text-muted); text-align:center; padding:20px;">' . lang('@No numbered entries found yet.') . '</p>';
        htm_Card_end();
        return;
    }

    echo '<div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:15px; margin-bottom:15px;">';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Lowest') . '</div><div style="font-weight:bold; font-size:1.2em;">' . $gaps['min'] . '</div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Highest') . '</div><div style="font-weight:bold; font-size:1.2em;">' . $gaps['max'] . '</div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Total Found') . '</div><div style="font-weight:bold; font-size:1.2em;">' . $gaps['count'] . '</div></div>';
    echo '<div><div style="color:var(--text-muted); font-size:0.85em;">' . lang('@Expected (if no gaps)') . '</div><div style="font-weight:bold; font-size:1.2em;">' . $gaps['expected'] . '</div></div>';
    echo '</div>';

    if (!empty($gaps['dupes'])) {
        htm_Alert(sprintf(lang('@%d number(s) are used more than once:'), count($gaps['dupes'])), 'error', 900, true);
        echo '<div style="padding:12px; margin-bottom:10px; background:var(--bg-panel); border-radius:6px; font-family:monospace; font-weight:bold; color:var(--color-danger);">'
            . htmlspecialchars(seq_compress_ranges($gaps['dupes'])) . '</div>';
    }
    if (empty($gaps['missing'])) {
        htm_Alert(lang('@No gaps found - the sequence is complete.'), 'success', 900, true);
    } else {
        $count = count($gaps['missing']);
        htm_Alert(sprintf(lang('@%d missing number(s) found in the sequence:'), $count), 'error', 900, true);
        echo '<div style="padding:12px; background:var(--bg-panel); border-radius:6px; font-family:monospace; font-weight:bold; color:var(--color-danger);">'
            . htmlspecialchars(seq_compress_ranges($gaps['missing'])) . '</div>';
    }
    htm_Card_end();
}

// Bilagsnumre (journal.voucher_no) - alle posteringstyper under ét.
$v_res = DB::query($conn, "SELECT voucher_no FROM journal WHERE voucher_no IS NOT NULL");
$v_nums = [];
if ($v_res) { while ($r = DB::fetch_assoc($v_res)) $v_nums[] = $r['voucher_no']; }
$voucher_gaps = seq_find_gaps($v_nums);

// Fakturanumre (invoices.invoice_no) - kun tildelte (bogførte) fakturaer;
// kladder og kreditnotaer får aldrig et invoice_no og indgår derfor korrekt
// ikke i denne sekvens.
$i_res = DB::query($conn, "SELECT invoice_no FROM invoices WHERE invoice_no IS NOT NULL AND invoice_no != ''");
$i_nums = [];
if ($i_res) { while ($r = DB::fetch_assoc($i_res)) $i_nums[] = $r['invoice_no']; }
$invoice_gaps = seq_find_gaps($i_nums);

htm_Header('@Sequence Number Check');
showMenu();

echo "<div style='max-width:1000px; margin:0 auto; padding:10px;'>";

echo '<p style="color:var(--text-muted); margin-bottom:20px;">'
    . lang('@This checks that voucher numbers (journal.voucher_no) and invoice numbers (invoices.invoice_no) each form an unbroken sequence, with no gaps - one of the first things an auditor checks, since a gap could mean something was deleted outside the normal flows.')
    . '</p>';

seq_render_section(lang('@Voucher Number Sequence'), $voucher_gaps);
seq_render_section(lang('@Invoice Number Sequence'), $invoice_gaps);
// RETTET: kreditnotaernes egen nummerserie (credit_note_no, se next_credit_note_no()) blev ikke kontrolleret
$cn_res = @DB::query($conn, "SELECT credit_note_no FROM invoices WHERE credit_note_no IS NOT NULL");
if ($cn_res) {
    $cn_nums = [];
    while ($r = DB::fetch_assoc($cn_res)) $cn_nums[] = $r['credit_note_no'];
    seq_render_section(lang('@Credit Note Number Sequence'), seq_find_gaps($cn_nums));
}

echo "</div>";
htm_Footer();
