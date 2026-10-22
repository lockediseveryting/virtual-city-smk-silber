<?php
// PWA Helper: manifest, design system assets, PWA meta, and runtime scripts.
// Every page includes this once, so the UI stays consistent across the app.
declare(strict_types=1);

namespace VC\Security;

final class Pwa
{
    public static function headTags(): string
    {
        $nonce = Headers::nonce();
        // Token CSRF selalu tersedia di SEMUA halaman (baca oleh fetch/X-CSRF di JS).
        $csrf = Csrf::token();
        return <<<HTML
<meta name="csrf" content="{$csrf}">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#090d16">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="SMK Silber">
<meta name="mobile-web-app-capable" content="yes">
<link rel="apple-touch-icon" href="/assets/img/logo-silber.jpg">
<link rel="icon" type="image/jpeg" sizes="600x600" href="/assets/img/logo-silber.jpg">
<link rel="icon" type="image/svg+xml" href="/assets/icons/icon.svg">
<link rel="stylesheet" href="/assets/css/app.css?v=7.5.0">
<script src="/assets/js/audio.js" nonce="{$nonce}" defer></script>
<script src="/assets/js/ui.js" nonce="{$nonce}" defer></script>
<script src="/assets/js/pwa.js" nonce="{$nonce}" defer></script>
HTML;
    }
}
