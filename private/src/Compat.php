<?php
// Compatibility shims for hosts without the mbstring extension.
// These fallbacks are UTF-8 aware (per-character, never splitting a codepoint),
// so JSON output stays valid on servers where mbstring is not installed.
declare(strict_types=1);

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return substr($string, $start, $length);
        }
        return implode('', array_slice($chars, $start, $length));
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? strlen($string) : count($chars);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        return strtoupper($string);
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return strtolower($string);
    }
}
