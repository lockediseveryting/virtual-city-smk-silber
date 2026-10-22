<?php
// Pure PHP RFC 6238 TOTP (Time-based One-Time Password) implementation.
// Zero external dependencies. Base32 encoder/decoder, HMAC-SHA1 6-digit codes.
declare(strict_types=1);

namespace VC\Security;

final class Totp
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically secure Base32 secret key.
     * Default length: 16 characters (80 bits) or 32 characters (160 bits).
     */
    public static function generateSecret(int $length = 16): string
    {
        $secret = '';
        $max = strlen(self::BASE32_CHARS) - 1;
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_CHARS[random_int(0, $max)];
        }
        return $secret;
    }

    /**
     * Generate 6-digit TOTP code for a given timestamp (default: current time).
     */
    public static function getCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $timeSlice = (int)floor($timestamp / 30);

        // Pack 64-bit integer big-endian (RFC 4226)
        $timeBytes = pack('N*', 0) . pack('N*', $timeSlice);
        $secretBytes = self::base32Decode($secret);

        $hash = hash_hmac('sha1', $timeBytes, $secretBytes, true);

        // Dynamic truncation
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $otp = $binary % 1000000;
        return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a 6-digit TOTP code with time drift window (default ±1 period = ±30s).
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $currentTime = time();
        for ($i = -$window; $i <= $window; $i++) {
            $calcCode = self::getCode($secret, $currentTime + ($i * 30));
            if (hash_equals($calcCode, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get standard otpauth URI for QR codes or authenticator apps.
     */
    public static function getProvisioningUri(string $secret, string $label, string $issuer = 'SMK Silber'): string
    {
        $account = $issuer . ':' . $label;
        return 'otpauth://totp/' . rawurlencode($account) . '?' . http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => 6,
            'period'    => 30,
        ]);
    }

    /**
     * Decode a Base32 string into binary bytes.
     */
    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, "=\r\n\t "));
        if ($b32 === '') {
            return '';
        }

        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $char = $b32[$i];
            $val = strpos(self::BASE32_CHARS, $char);
            if ($val === false) {
                continue; // ignore padding or invalid characters
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $result;
    }

    /**
     * Encode binary bytes into Base32 string.
     */
    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $result .= self::BASE32_CHARS[($buffer >> $bitsLeft) & 0x1f];
            }
        }

        if ($bitsLeft > 0) {
            $buffer <<= (5 - $bitsLeft);
            $result .= self::BASE32_CHARS[$buffer & 0x1f];
        }

        return $result;
    }
}
