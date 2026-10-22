<?php
// I18n: central Indonesian UI strings (lang/id.json). UI text must never be hard-coded elsewhere.
declare(strict_types=1);

namespace VC;

final class I18n
{
    private static ?array $strings = null;
    private static string $locale = 'id';

    public static function load(): void
    {
        if (self::$strings !== null) {
            return;
        }
        $path = VC_PRIVATE . '/lang/' . self::$locale . '.json';
        $raw = is_file($path) ? (string)file_get_contents($path) : '{}';
        $decoded = json_decode($raw, true);
        self::$strings = is_array($decoded) ? $decoded : [];
    }

    /**
     * Translate a dotted key, e.g. t('home.title').
     * Falls back to the key itself so a missing string is visible (never blank).
     */
    public static function t(string $key, array $replace = []): string
    {
        self::load();
        $node = self::$strings;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $key;
            }
            $node = $node[$part];
        }
        $text = is_scalar($node) ? (string)$node : $key;
        foreach ($replace as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
        return $text;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** JSON safe for embedding in a <script> block (escapes tags / quotes). */
    public static function jsonForJs(): string
    {
        self::load();
        return (string)json_encode(
            self::$strings,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
