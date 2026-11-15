<?php # /inc/payment_ref.inc.php v:1.3.1 d:2026-09-15 i:evs
// Betalingsreference på fakturaen, så banken kan sende den med indbetalingen og afstemningen kan finde fakturaen:
// FIK +71 (DK), KID (NO), OCR (SE), viitenumero (FI), gestructureerde mededeling (BE) eller RF (ISO 11649, hele EU). Referencen dannes af fakturanummeret,
// så den altid kan regnes ud igen og ikke skal gemmes. Ordningen vælges i Firmaindstillinger, ellers fra landepakken.

const TC_PAYMENT_REF_SCHEMES = ['none', 'fik71', 'kid', 'ocr', 'fi_ref', 'ee_ref', 'be_ogm', 'rf'];

// Luhn (modulus 10, vægt 2-1 fra højre) - FIK 71, KID og OCR
function tc_luhn_digit(string $digits): int {
    $sum = 0;  $w = 2;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $p = (int)$digits[$i] * $w;
        $sum += $p > 9 ? $p - 9 : $p;
        $w = $w === 2 ? 1 : 2;
    }
    return (10 - $sum % 10) % 10;
}

// Finsk viitenumero: vægt 7-3-1 fra højre
function tc_fi_ref_digit(string $digits): int {
    $sum = 0;  $w = [7, 3, 1];
    for ($i = strlen($digits) - 1, $k = 0; $i >= 0; $i--, $k++) $sum += (int)$digits[$i] * $w[$k % 3];
    return (10 - $sum % 10) % 10;
}

// ISO 11649: RF + 2 kontrolcifre + referencen (A-Z/0-9); modulus 97 som IBAN
function tc_rf_ref(string $base): string {
    $num = '';
    foreach (str_split(strtoupper($base) . 'RF00') as $ch) $num .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
    $mod = 0;
    foreach (str_split($num, 7) as $part) $mod = (int)(($mod . $part)) % 97;
    return 'RF' . str_pad((string)(98 - $mod), 2, '0', STR_PAD_LEFT) . strtoupper($base);
}

// Er en RF-reference (fx fra en bankfil) gyldig?
// IBAN: landekode, to kontrolcifre og op til 30 tegn, modulus 97 = 1 (ISO 13616) - en tastefejl stod ellers på hver e-faktura
function tc_iban_ok(string $iban): bool {
    $iban = strtoupper(preg_replace('/\s+/', '', $iban));
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[0-9A-Z]{10,30}$/', $iban)) return false;
    $num = '';
    foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $ch) $num .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
    $mod = 0;
    foreach (str_split($num, 7) as $part) $mod = (int)($mod . $part) % 97;
    return $mod === 1;
}

function tc_rf_valid(string $ref): bool {
    $ref = strtoupper(preg_replace('/\s+/', '', $ref));
    if (!preg_match('/^RF[0-9]{2}[0-9A-Z]{1,21}$/', $ref)) return false;
    return tc_rf_ref(substr($ref, 4)) === $ref;
}

// Den valgte ordning: indstillingen 'payment_ref' ('auto' = landepakken). FIK kræver et kreditornummer.
function tc_payment_ref_scheme(): string {
    $s = (string)(function_exists('setting') ? setting('payment_ref', 'auto') : 'auto');
    if ($s === '' || $s === 'auto') $s = (string)tc_country_pack('payment_ref', 'none');
    if (!in_array($s, TC_PAYMENT_REF_SCHEMES, true)) return 'none';
    if ($s === 'fik71' && tc_fik_creditor() === '') return 'none';
    return $s;
}

function tc_fik_creditor(): string {
    $c = preg_replace('/\D/', '', (string)(function_exists('setting') ? setting('fik_creditor', '') : ''));
    return strlen($c) === 8 ? $c : '';
}

function tc_payment_ref_labels(): array {
    return [
        'auto'   => lang('@From the country pack'),
        'none'   => lang('@None'),
        'fik71'  => lang('@FIK +71 (Denmark, payment slip)'),
        'kid'    => lang('@KID (Norway)'),
        'ocr'    => lang('@OCR (Sweden, Bankgiro/Plusgiro)'),
        'fi_ref' => lang('@Reference number (Finland)'),
        'ee_ref' => lang('@Reference number (Estonia, viitenumber)'),
        'be_ogm' => lang('@Structured communication (Belgium, +++000/0000/00000+++)'),
        'rf'     => lang('@RF creditor reference (ISO 11649, all of the EU)'),
    ];
}

// Fakturaens reference: ['scheme', 'ref' (maskinformat), 'display' (som på papiret)] - null uden ordning/fakturanummer.
// Kreditnotaer får ingen reference (der skal ikke betales).
function tc_payment_ref($inv_no, ?string $scheme = null): ?array {
    $scheme = $scheme ?? tc_payment_ref_scheme();
    if ($scheme === 'none') return null;
    $digits = ltrim(preg_replace('/\D/', '', (string)$inv_no), '0');
    if ($scheme === 'rf') {
        $base = preg_replace('/[^0-9A-Z]/', '', strtoupper((string)$inv_no));
        if ($base === '' || strlen($base) > 21) return null;
        $ref = tc_rf_ref($base);
        return ['scheme' => $scheme, 'ref' => $ref, 'display' => trim(chunk_split($ref, 4, ' '))];
    }
    if ($digits === '') return null;
    switch ($scheme) {
        case 'fik71':
            if (strlen($digits) > 14 || ($cred = tc_fik_creditor()) === '') return null;
            $base = str_pad($digits, 14, '0', STR_PAD_LEFT);
            $ref  = $base . tc_luhn_digit($base);
            return ['scheme' => $scheme, 'ref' => $ref, 'display' => '+71<' . $ref . '+' . $cred . '<', 'creditor' => $cred];
        case 'kid':
            if (strlen($digits) > 24) return null;
            $ref = $digits . tc_luhn_digit($digits);
            return ['scheme' => $scheme, 'ref' => $ref, 'display' => $ref];
        case 'ocr':
            // længdeciffer + kontrolciffer (Bankgirots "hård kontrol"); gyldigt Luhn-tal også ved kun kontrolciffer
            if (strlen($digits) > 23) return null;
            $base = $digits . ((strlen($digits) + 2) % 10);
            $ref  = $base . tc_luhn_digit($base);
            return ['scheme' => $scheme, 'ref' => $ref, 'display' => $ref];
        case 'be_ogm':
            // Belgisk gestructureerde mededeling: 10 cifre + 2 kontrolcifre (mod 97, 0 = 97), vist +++123/4567/89012+++
            if (strlen($digits) > 10) return null;
            $base = str_pad($digits, 10, '0', STR_PAD_LEFT);
            $chk  = (int)$base % 97 ?: 97;
            $ref  = $base . str_pad((string)$chk, 2, '0', STR_PAD_LEFT);
            return ['scheme' => $scheme, 'ref' => $ref, 'display' => '+++' . substr($ref, 0, 3) . '/' . substr($ref, 3, 4) . '/' . substr($ref, 7, 5) . '+++'];
        case 'ee_ref':
            // Estisk viitenumber: samme 7-3-1-kontrolciffer som det finske, 2-20 cifre, vist uden mellemrum
            if (strlen($digits) > 19) return null;
            $ref = $digits . tc_fi_ref_digit($digits);
            return ['scheme' => $scheme, 'ref' => $ref, 'display' => $ref];
        case 'fi_ref':
            // 4-20 cifre inkl. kontrolciffer; et kort nummer får et foranstillet 1 (bankerne fjerner foranstillede nuller)
            if (strlen($digits) < 3) $digits = '1' . str_pad($digits, 3, '0', STR_PAD_LEFT);
            if (strlen($digits) > 19) return null;
            $ref = $digits . tc_fi_ref_digit($digits);
            return ['scheme' => $scheme, 'ref' => $ref, 'display' => strrev(trim(chunk_split(strrev($ref), 5, ' ')))];
    }
    return null;
}

// Står fakturaens reference i en banktekst? Sammenligner kun cifre/bogstaver, så mellemrum og "+71<" ikke tæller.
function tc_payment_ref_in_text(?array $ref, string $text): bool {
    if (!$ref) return false;
    $needle = preg_replace('/[^0-9A-Z]/', '', strtoupper($ref['ref']));
    if (strlen($needle) < 4) return false;
    // En kort reference (finsk/estisk for faktura 1001 = "10013") blev aldrig fundet. Den skal nu stå som et helt ord i
    // teksten, så den ikke rammer et beløb eller en dato; en lang må gerne være delt af mellemrum (+71<...>, OCR-grupper)
    if (strlen($needle) < 6) return (bool)preg_match('/(?<![0-9A-Z])(?<![0-9][.,])' . $needle . '(?![0-9A-Z])(?![.,][0-9])/', strtoupper($text));
    return str_contains(preg_replace('/[^0-9A-Z]/', '', strtoupper($text)), $needle);
}
