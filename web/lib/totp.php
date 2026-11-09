<?php
// Double authentification TOTP (compatible Google Authenticator, Aegis, etc.)
declare(strict_types=1);

const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function b32_encode(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= B32[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function b32_decode(string $s): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    if ($s === '') return '';
    $bits = '';
    foreach (str_split($s) as $c) {
        $bits .= str_pad(decbin(strpos(B32, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) === 8) $out .= chr(bindec($b));
    }
    return $out;
}

function totp_code(string $secret, int $pas): string
{
    $h = hash_hmac('sha1', pack('J', $pas), b32_decode($secret), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16)
       | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Vérifie un code (tolérance ±30 s). Refuse un code déjà utilisé. */
function totp_verifier(string $secret, string $code, int $dernierPas, ?int &$pasUtilise = null): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $maintenant = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $pas = $maintenant + $d;
        if ($pas > $dernierPas && hash_equals(totp_code($secret, $pas), $code)) {
            $pasUtilise = $pas;
            return true;
        }
    }
    return false;
}
