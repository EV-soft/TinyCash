<?php # /inc/totp.lib.php v:1.3.0 d:2026-08-30 i:evs
# NY FUNKTION: To-faktor-login, TOTP/RFC 6238
# Selvstændig TOTP-implementation (RFC 4226 HOTP + RFC 6238 TOTP), ingen
# eksterne biblioteker/pakkehåndtering - matcher projektets "intet build-trin,
# ingen package manager"-princip. Kompatibel med alle almindelige
# authenticator-apps (Google Authenticator, Authy, 1Password, Bitwarden osv.),
# som alle implementerer nøjagtig samme standard.
#
# BEVIDST INGEN QR-kode-generering: en QR-encoder (Reed-Solomon-fejlkorrektion
# m.m.) er kompleks nok at en forkert egen implementation kunne generere en
# QR-kode der SER rigtig ud, men ikke reelt kan scannes - og det kan ikke
# verificeres uden en fysisk telefon/kamera. Manuel indtastning af
# hemmeligheden er fuldt understøttet af alle authenticator-apps og er den
# sikre, verificerbare løsning her. At bruge en ekstern QR-genererings-
# tjeneste er BEVIDST UNDGÅET - det ville sende selve 2FA-hemmeligheden til
# en tredjepart.

function totp_base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function totp_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) continue; // ufuldstændig sidste byte - padding, ikke data
        $output .= chr(bindec($byte));
    }
    return $output;
}

// Genererer en ny, tilfældig 160-bit hemmelighed (RFC 4226's anbefalede
// nøglelængde for SHA1-baseret HOTP/TOTP), base32-kodet til visning/indtastning.
function totp_generate_secret(): string {
    return totp_base32_encode(random_bytes(20));
}

// RFC 4226 HOTP - selve kerneberegningen TOTP bygger ovenpå. Verificeret
// direkte mod RFC 4226's egne officielle test-vektorer (Appendix D, hemmelighed
// "12345678901234567890" ASCII, counter 0-9) - se tools/ eller kommentar i
// migrate_2fa.php-relaterede memory-noter for verifikationskørslen.
function totp_hotp(string $secret_base32, int $counter, int $digits = 6): string {
    $key = totp_base32_decode($secret_base32);
    $bin_counter = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter
    $hash = hash_hmac('sha1', $bin_counter, $key, true);

    $offset = ord($hash[19]) & 0xf;
    $part1 = (ord($hash[$offset])     & 0x7f) << 24;
    $part2 = (ord($hash[$offset + 1]) & 0xff) << 16;
    $part3 = (ord($hash[$offset + 2]) & 0xff) << 8;
    $part4 = (ord($hash[$offset + 3]) & 0xff);
    $code  = ($part1 | $part2 | $part3 | $part4) % (10 ** $digits);

    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

// RFC 6238 TOTP - HOTP med en tidsbaseret tæller (30-sekunders vinduer, standard
// hos alle almindelige authenticator-apps).
function totp_code(string $secret_base32, ?int $timestamp = null, int $period = 30, int $digits = 6): string {
    $timestamp = $timestamp ?? time();
    return totp_hotp($secret_base32, intdiv($timestamp, $period), $digits);
}

// Verificerer en indtastet kode mod nuværende tidsvindue +/- $window vinduer
// (tolerance for urforskel mellem server og telefon - 1 vindue til hver side
// er almindelig praksis, dvs. +/- 30 sekunder).
function totp_verify(string $secret_base32, string $code, int $window = 1, int $period = 30): bool {
    $code = trim($code);
    if ($code === '' || !ctype_digit($code)) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret_base32, $now + ($i * $period), $period), $code)) {
            return true;
        }
    }
    return false;
}

// otpauth://-URI'en - de fleste authenticator-apps kan tilføje en konto via
// denne som tekst, ikke kun ved at scanne en QR-kode.
function totp_provisioning_uri(string $secret_base32, string $account_name, string $issuer = 'TinyCash'): string {
    $label = rawurlencode($issuer) . ':' . rawurlencode($account_name);
    $params = http_build_query([
        'secret' => $secret_base32,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ]);
    return 'otpauth://totp/' . $label . '?' . $params;
}

// Genererer engangs-gendannelseskoder (bruges hvis authenticator-appen
// mistes) - format "XXXX-XXXX", store bogstaver+tal, undgår forvekslelige
// tegn (0/O, 1/I/L).
function totp_generate_recovery_codes(int $count = 8): array {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $code = '';
        for ($j = 0; $j < 8; $j++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            if ($j === 3) $code .= '-';
        }
        $codes[] = $code;
    }
    return $codes;
}
?>
