<?php
// Reusable branded footer for app pages. Rendered once by the shared Shell layout
// (and by standalone pages that do not use Shell). It is NOT position:fixed, so it
// never covers the 3D game canvas or the bottom tab bar on phones.
declare(strict_types=1);

namespace VC\Security;

final class Footer
{
    public const BRAND_TEXT = 'Hacker TKJ';
    public const BRAND_URL  = 'https://locked.rudd-catla.ts.net/';

    /**
     * Compact variant for the fullscreen 3D world: pinned to the very bottom edge
     * (bottom:0, ~18px tall) so it never overlaps the joystick, action bar, minimap
     * or desktop hint, which all sit >=20px above the bottom. Never fixed over the
     * canvas content, always a real link.
     */
    public static function gameHtml(): string
    {
        return '<div class="app-footer app-footer--game" style="position:absolute;bottom:0;left:50%;'
            . 'transform:translateX(-50%);z-index:95;font-size:10px;line-height:1;padding:4px 10px;'
            . 'color:#8b98ab;background:rgba(5,10,18,0.55);border-radius:8px 8px 0 0;white-space:nowrap;'
            . 'pointer-events:auto">Made by - <a href="' . self::BRAND_URL . '" target="_blank" '
            . 'rel="noopener noreferrer" style="color:inherit;text-decoration:underline">'
            . self::BRAND_TEXT . '</a></div>';
    }

    public static function html(bool $dark = true): string
    {
        $c = $dark ? 'color:#9aa4b2;' : 'color:#64748b;';
        return '<footer class="app-footer" style="text-align:center;padding:16px 16px calc(16px + env(safe-area-inset-bottom));'
            . 'font-size:13px;line-height:1.6;' . $c . '">'
            . 'Made by - <a href="' . self::BRAND_URL . '" target="_blank" rel="noopener noreferrer" '
            . 'style="color:inherit;text-decoration:underline;word-break:break-word">' . self::BRAND_TEXT . '</a>'
            . ' · <a href="/team" style="color:inherit;text-decoration:underline">Team</a>'
            . '</footer>';
    }
}
