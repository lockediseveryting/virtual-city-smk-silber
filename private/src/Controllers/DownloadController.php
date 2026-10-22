<?php
// DownloadController: Dedicated Download Center & Standalone Launcher Generator.
declare(strict_types=1);

namespace VC\Controllers;

use VC\I18n;
use VC\Security\Auth;
use VC\Security\Headers;
use VC\View\Shell;

final class DownloadController
{
    public static function render(): void
    {
        $user = Auth::user();
        $nonce = Headers::nonce();

        Shell::start([
            'title'  => 'Unduh & Pasang Aplikasi — ' . I18n::t('app.name'),
            'active' => 'download',
            'user'   => $user,
        ]);

        // Satu tombol: pasang langsung (PWA) tanpa file launcher. Panduan hanya untuk perangkat ini.
        echo '<div class="dl-wrap">';
        echo '<div class="px-panel dl-card">';
        echo '<img src="/assets/img/logo-silber.jpg" width="84" height="84" alt="Logo SMK Silber" class="dl-logo">';
        echo '<h1 class="dl-title">Pasang Virtual City</h1>';
        echo '<p class="dl-sub">Langsung terpasang di HP atau komputer — tanpa file launcher, tanpa Play Store. Ikon muncul di layar utama / desktop dan terbuka layar penuh.</p>';
        echo '<button type="button" class="px-btn px-btn--gold dl-btn" id="dlInstall">📲 PASANG SEKARANG</button>';
        echo '<p class="dl-status" id="dlStatus" aria-live="polite"></p>';
        echo '<div class="dl-guide" id="dlGuide" hidden></div>';
        echo '<p class="dl-foot"><a href="/">← Kembali ke dashboard</a></p>';
        echo '</div></div>';
        echo '<style nonce="' . $nonce . '">
.dl-wrap{min-height:calc(100dvh - 40px);display:flex;align-items:center;justify-content:center;padding:16px}
.dl-card{width:min(560px,100%);text-align:center;padding:24px 20px!important}
.dl-logo{box-shadow:0 0 0 4px #20130a;image-rendering:auto}
.dl-title{margin:12px 0 6px;font-size:clamp(24px,5vw,32px)}
.dl-sub{margin:0 0 18px;font-size:16px;line-height:1.5}
.dl-btn{font-size:20px!important;padding:14px 26px!important;min-height:58px}
.dl-status{min-height:1.4em;margin:14px 0 0;font-size:15px}
.dl-guide{margin-top:14px;text-align:left;font-size:15px;line-height:1.6;padding:12px 14px;background:#170f1e;box-shadow:inset 0 0 0 2px #3a2a4a}
.dl-guide ol{margin:6px 0 0;padding-left:22px}
.dl-foot{margin:18px 0 0;font-size:14px}
</style>';
        echo '<script nonce="' . $nonce . '">
(function () {
  var ua = navigator.userAgent.toLowerCase();
  var ios = /iphone|ipad|ipod/.test(ua), android = /android/.test(ua);
  var st = document.getElementById("dlStatus"), guide = document.getElementById("dlGuide"), btn = document.getElementById("dlInstall");
  var standalone = (window.matchMedia && window.matchMedia("(display-mode: standalone)").matches) || navigator.standalone === true;
  function steps() {
    if (ios) return "<b>iPhone / iPad (Safari):</b><ol><li>Ketuk ikon <b>Bagikan</b> ⎋ di bawah.</li><li>Pilih <b>Tambah ke Layar Utama</b>.</li><li>Ketuk <b>Tambah</b>.</li></ol>";
    if (android) return "<b>Android (Chrome):</b><ol><li>Ketuk menu <b>⋮</b> di kanan atas.</li><li>Pilih <b>Instal aplikasi</b> / <b>Tambahkan ke layar utama</b>.</li><li>Ketuk <b>Instal</b> / <b>Tambah</b>.</li></ol>";
    return "<b>Komputer (Chrome / Edge):</b><ol><li>Klik ikon <b>⊕ Instal</b> di ujung kanan kolom alamat, atau menu <b>⋮</b> → <b>Simpan dan bagikan → Instal halaman sebagai aplikasi</b>.</li><li>Klik <b>Instal</b>.</li></ol>";
  }
  function showGuide(note) { guide.innerHTML = (note ? "<p style=\"margin:0 0 8px\">" + note + "</p>" : "") + steps(); guide.hidden = false; }
  if (standalone) { st.textContent = "✅ Aplikasi sudah terpasang di perangkat ini."; btn.textContent = "▶ BUKA DASHBOARD"; btn.onclick = function () { location.href = "/"; }; return; }
  function ready() { return window.pwa && window.pwa.deferredPrompt; }
  function refresh() {
    if (ready()) st.textContent = "Siap dipasang — tekan tombol di atas.";
    else if (!window.isSecureContext) st.textContent = "";
  }
  window.addEventListener("beforeinstallprompt", function () { setTimeout(refresh, 50); });
  window.addEventListener("appinstalled", function () { st.textContent = "🎉 Terpasang! Buka dari ikon Virtual City di layar utama."; guide.hidden = true; });
  setTimeout(refresh, 600);
  btn.addEventListener("click", function () {
    if (ready()) { window.pwa.promptInstall(); return; }
    // Pemasangan 1-klik butuh HTTPS + browser yang mendukung; selain itu tampilkan langkah singkat perangkat ini.
    showGuide(window.isSecureContext ? "Browser ini tidak menampilkan tombol instal otomatis. Pasang dengan 3 langkah:" : "Pasang dengan 3 langkah singkat:");
  });
})();
</script>';

        Shell::end([
            'user'   => $user,
            'active' => 'download',
        ]);
    }

    public static function handleDownload(): void
    {
        $type = trim((string)($_GET['type'] ?? 'launcher'));
        $siteUrl = rtrim((string)\vc_env('SITE_URL', 'http://xox.smksilber.cloud'), '/');

        if ($type === 'shortcut') {
            header('Content-Type: application/internet-shortcut');
            header('Content-Disposition: attachment; filename="VirtualCity_SMKSilber.url"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo "[InternetShortcut]\r\nURL={$siteUrl}/\r\nIconIndex=0\r\n";
            exit;
        }

        // Default: Standalone Web Launcher HTML file
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="VirtualCity_SMKSilber.html"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes">';
        echo '<meta name="mobile-web-app-capable" content="yes">';
        echo '<meta name="theme-color" content="#090d16">';
        echo '<title>Virtual City SMK Silber — Launcher</title>';
        echo '<style>
body { margin: 0; background: #070b14; color: #fff; font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; text-align: center; }
.card { background: #0f172a; border: 1px solid rgba(56,189,248,0.35); border-radius: 16px; padding: 28px 24px; max-width: 420px; box-shadow: 0 16px 40px rgba(0,0,0,0.8); }
.btn { display: inline-block; background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; text-decoration: none; font-weight: 700; padding: 12px 24px; border-radius: 10px; margin-top: 16px; font-size: 1rem; }
.spinner { width: 36px; height: 36px; border: 3px solid rgba(56,189,248,0.2); border-top-color: #38bdf8; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 16px; }
@keyframes spin { to { transform: rotate(360deg); } }
</style></head><body>';
        echo '<div class="card"><div class="spinner"></div>';
        echo '<h2 style="margin:0 0 8px">Virtual City SMK Silber</h2>';
        echo '<p style="color:#94a3b8;font-size:0.88rem;margin:0">Membuka portal sekolah dan dunia virtual 3D...</p>';
        echo '<a class="btn" href="' . htmlspecialchars($siteUrl, ENT_QUOTES) . '/">Buka Sekarang →</a>';
        echo '</div>';
        echo '<script>setTimeout(function(){ window.location.href = ' . json_encode($siteUrl . '/') . '; }, 500);</script>';
        echo '</body></html>';
        exit;
    }
}
