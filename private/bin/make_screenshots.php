<?php
// Generate placeholder PWA screenshots as raw PNGs (no GD extension required).
// These are clearly-marked placeholders until real screenshots are captured.
declare(strict_types=1);

function png_chunk(string $type, string $data): string
{
    $body = $type . $data;
    return pack('N', strlen($data)) . $body . pack('N', crc32($body) & 0xffffffff);
}

function write_png(string $path, int $w, int $h, callable $colorAt): void
{
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\x00"; // filter: none
        for ($x = 0; $x < $w; $x++) {
            [$r, $g, $b] = $colorAt($x, $y, $w, $h);
            $raw .= chr($r) . chr($g) . chr($b);
        }
    }
    $ihdr = pack('N', $w) . pack('N', $h) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
    $png = "\x89PNG\r\n\x1a\n"
        . png_chunk('IHDR', $ihdr)
        . png_chunk('IDAT', gzcompress($raw, 9))
        . png_chunk('IEND', '');
    file_put_contents($path, $png);
}

$dir = dirname(__DIR__, 2) . '/public/assets/screenshots';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

// Deep teal/indigo gradient with a bright accent band, matching the game theme.
$colorAt = function (int $x, int $y, int $w, int $h): array {
    $t = $y / max(1, $h - 1);
    $r = (int)(9 + $t * (19 - 9));
    $g = (int)(13 + $t * (40 - 13));
    $b = (int)(22 + $t * (70 - 22));
    // Accent band near the top (primary color).
    if ($y > (int)($h * 0.18) && $y < (int)($h * 0.18) + (int)($h * 0.05)) {
        $r = 20; $g = 184; $b = 166;
    }
    // Subtle vignette so text overlays stay readable.
    $dx = ($x - $w / 2) / ($w / 2);
    $dy = ($y - $h / 2) / ($h / 2);
    $v = 1 - 0.25 * min(1, sqrt($dx * $dx + $dy * $dy));
    return [(int)($r * $v), (int)($g * $v), (int)($b * $v)];
};

write_png($dir . '/home-mobile.png', 540, 960, $colorAt);
write_png($dir . '/home-desktop.png', 1280, 720, $colorAt);

foreach (['home-mobile.png', 'home-desktop.png'] as $f) {
    $p = $dir . '/' . $f;
    echo (is_file($p) ? 'OK  ' : 'ERR ') . $f . ' — ' . (is_file($p) ? filesize($p) : 0) . " bytes\n";
}
