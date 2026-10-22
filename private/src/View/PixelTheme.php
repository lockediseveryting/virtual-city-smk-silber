<?php
// PixelTheme: tema pixel-game global (font pixel, tombol kayu, latar kota siang/malam)
// + skrip layar penuh HP. Disisipkan index.php tepat sebelum </head> setiap halaman,
// SESUDAH gaya bawaan halaman, sehingga tema berlaku merata tanpa menyunting tiap controller.
declare(strict_types=1);

namespace VC\View;

final class PixelTheme
{
    public static function headTags(): string
    {
        return '<link rel="preload" href="/assets/fonts/pixelify-sans.woff2" as="font" type="font/woff2" crossorigin>'
            . '<link rel="stylesheet" href="/assets/css/pixel-theme.css?v=7.6.2">'
            . '<script src="/assets/js/pixel-shell.js?v=8.0.0"></script>'
            . '<script src="/assets/js/vc-sound.js?v=7.6.1" defer></script>'
            . '<script src="/assets/js/vc-live.js?v=7.7.0" defer></script>' // broadcast langsung + bunyi di semua halaman
            . UiTheme::style(); // tema tombol global pilihan pemilik (/media)
    }

    /** Sisipkan headTags() sebelum </head> pertama dan honeypot trap sebelum </body> (dipakai sebagai callback ob_start). */
    public static function inject(string $html): string
    {
        $pos = stripos($html, '</head>');
        if ($pos !== false) {
            $html = substr_replace($html, self::headTags(), $pos, 0);
        }
        $bodyPos = strripos($html, '</body>');
        if ($bodyPos !== false) {
            $trap = '<a href="/_bot_trap_sink" style="display:none!important;position:absolute;left:-9999px;top:-9999px;width:0;height:0;opacity:0;pointer-events:none" rel="nofollow" aria-hidden="true" tabindex="-1"></a>';
            $html = substr_replace($html, $trap, $bodyPos, 0);
        }
        return $html;
    }
}
