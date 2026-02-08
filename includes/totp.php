<?php
/**
 * TOTP (Time-based One-Time Password) Implementation
 * RFC 6238 / RFC 4226 compliant
 *
 * Pure PHP implementation — no external dependencies.
 * Uses HMAC-SHA1 with 30-second time steps and 6-digit codes.
 */

define('TOTP_PERIOD', 30);      // Time step in seconds
define('TOTP_DIGITS', 6);       // Code length
define('TOTP_ALGORITHM', 'sha1');
define('TOTP_SECRET_BYTES', 20); // 160-bit secret

// RFC 4648 Base32 alphabet
define('BASE32_ALPHABET', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567');

/**
 * Generate a random TOTP secret (Base32 encoded)
 * @return string 32-character Base32 string
 */
function generateTotpSecret() {
    return base32Encode(random_bytes(TOTP_SECRET_BYTES));
}

/**
 * Get the current TOTP code for a given secret
 * @param string $base32Secret Base32-encoded secret
 * @param int|null $timeSlice Time slice (null = current)
 * @return string 6-digit zero-padded code
 */
function getTotpCode($base32Secret, $timeSlice = null) {
    if ($timeSlice === null) {
        $timeSlice = floor(time() / TOTP_PERIOD);
    }

    $secretBytes = base32Decode($base32Secret);

    // Pack time as 8-byte big-endian
    $time = pack('N*', 0, $timeSlice);

    // HMAC-SHA1
    $hash = hash_hmac(TOTP_ALGORITHM, $time, $secretBytes, true);

    // Dynamic truncation (RFC 4226 section 5.4)
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $binary =
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF);

    $otp = $binary % pow(10, TOTP_DIGITS);

    return str_pad($otp, TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Verify a TOTP code against a secret
 * @param string $base32Secret Base32-encoded secret
 * @param string $code 6-digit code to verify
 * @param int $window Number of time slices to check either side (default 1 = ±30s)
 * @return bool Whether the code is valid
 */
function verifyTotpCode($base32Secret, $code, $window = 1) {
    $code = str_pad(trim($code), TOTP_DIGITS, '0', STR_PAD_LEFT);
    $currentSlice = floor(time() / TOTP_PERIOD);

    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(getTotpCode($base32Secret, $currentSlice + $i), $code)) {
            return true;
        }
    }

    return false;
}

/**
 * Build the otpauth:// URI for QR code generation
 * @param string $base32Secret Base32-encoded secret
 * @param string $accountName User identifier (e.g. username or email)
 * @param string $issuer Application name
 * @return string otpauth URI
 */
function getTotpUri($base32Secret, $accountName, $issuer = 'FreeITSM') {
    $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
    $params = http_build_query([
        'secret' => $base32Secret,
        'issuer' => $issuer,
        'algorithm' => strtoupper(TOTP_ALGORITHM),
        'digits' => TOTP_DIGITS,
        'period' => TOTP_PERIOD,
    ]);

    return 'otpauth://totp/' . $label . '?' . $params;
}

/**
 * Encode binary data to Base32 (RFC 4648)
 * @param string $data Raw binary data
 * @return string Base32-encoded string
 */
function base32Encode($data) {
    $alphabet = BASE32_ALPHABET;
    $encoded = '';
    $buffer = 0;
    $bitsLeft = 0;

    for ($i = 0; $i < strlen($data); $i++) {
        $buffer = ($buffer << 8) | ord($data[$i]);
        $bitsLeft += 8;

        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $encoded .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
        }
    }

    if ($bitsLeft > 0) {
        $encoded .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
    }

    return $encoded;
}

/**
 * Decode Base32-encoded string to binary (RFC 4648)
 * @param string $encoded Base32-encoded string
 * @return string Raw binary data
 */
function base32Decode($encoded) {
    $alphabet = BASE32_ALPHABET;
    $encoded = strtoupper(rtrim($encoded, '='));
    $decoded = '';
    $buffer = 0;
    $bitsLeft = 0;

    for ($i = 0; $i < strlen($encoded); $i++) {
        $val = strpos($alphabet, $encoded[$i]);
        if ($val === false) continue; // Skip invalid chars

        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;

        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $decoded .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $decoded;
}
?>
