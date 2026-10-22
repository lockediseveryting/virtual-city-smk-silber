<?php
// WorldController: 3D Virtual Campus view, 2D fallback map view, and visual Map Editor for admin.
declare(strict_types=1);

namespace VC\Controllers;

use VC\Domain\Schedule;
use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Footer;
use VC\Security\Headers;
use VC\Security\Permissions;

final class WorldController
{
    /** Render full 3D interactive world page */
    public static function render3DPage(): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();
        $csrf = Csrf::token();
        $isGuest = ($user['role'] ?? '') === 'tamu';
        if ($user !== null && self::partyLocked((int)$user['id'], '3d')) {
            return;
        }
        // Nama tampil = nama akun (full_name), bukan username teknis.
        $username = $user ? htmlspecialchars((string)($user['full_name'] ?: $user['username']), ENT_QUOTES) : 'Tamu';
        $role = $user ? htmlspecialchars($user['role'], ENT_QUOTES) : 'guest';

        // Real player progress & active customized avatar for the HUD and 3D engine.
        $level = 1;
        $totalXp = 0;
        $coins = 0;
        $xpPct = 0;
        $activeAvatar = null;
        if ($user !== null) {
            try {
                $p = \VC\Domain\Gameplay::getProgress((int)$user['id']);
                $level = (int)$p['level'];
                $totalXp = (int)$p['total_xp'];
                $coins = (int)$p['coins'];
                $into = \VC\Domain\Gameplay::xpIntoLevel($totalXp, $level);
                $need = \VC\Domain\Gameplay::xpToNextLevel($totalXp, $level);
                $xpPct = ($into + $need) > 0 ? (int)round($into * 100 / ($into + $need)) : 100;
                $activeAvatar = \VC\Domain\Gameplay::getActiveAvatar((int)$user['id']);
            } catch (\Throwable $e) {
                $level = 1;
            }
        }

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">';
        echo '<meta name="csrf" content="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';
        echo '<script nonce="' . $nonce . '">window.__I18N = ' . \VC\I18n::jsonForJs() . ';</script>';
        echo \VC\Security\Pwa::headTags();
        echo '<title>Dunia 3D — Virtual City SMK Silber</title>'; ?>
<style nonce="<?= $nonce ?>">
* { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, sans-serif; }
html, body { width: 100%; height: 100%; overflow: hidden; background: #000; color: #fff; touch-action: manipulation; }
#viewport3D { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; display: block; touch-action: none; }

/* In-game HUD — built on the shared design system (app.css tokens/classes). */
.hud { position: absolute; z-index: 120 !important; pointer-events: auto !important; }
.hud--top { top: 10px; left: 10px; right: 10px; display: flex; align-items: center; justify-content: space-between; gap: 8px; z-index: 120 !important; pointer-events: auto !important; }
.hud__group { display: flex; align-items: center; gap: 6px; flex-shrink: 0; pointer-events: auto !important; }
.hud-btn {
  background: color-mix(in srgb, var(--c-surface) 88%, transparent); border: 1px solid var(--c-border-strong);
  color: var(--c-text); padding: 0 12px; min-height: 40px; border-radius: var(--r-md); cursor: pointer; font-size: 0.82rem; font-weight: 700;
  display: inline-flex; align-items: center; justify-content: center; gap: 5px; pointer-events: auto !important; touch-action: manipulation;
  user-select: none; -webkit-user-select: none; flex-shrink: 0; white-space: nowrap;
  transition: background 0.15s, border-color 0.15s, transform 0.1s;
}
.hud-btn:hover { background: color-mix(in srgb, var(--c-primary-2) 28%, transparent); border-color: var(--c-primary-2); }
.hud-btn:active { transform: scale(0.96); }
.hud-btn.is-active { background: var(--c-primary); color: #04121a; border-color: var(--c-primary); }
.hud-quick {
  display: flex; align-items: center; gap: 6px; overflow-x: auto; max-width: 58vw; scrollbar-width: none;
  pointer-events: auto !important; touch-action: pan-x; -webkit-overflow-scrolling: touch;
}
.hud-quick::-webkit-scrollbar { display: none; }
.hud-location { display: flex; flex-direction: column; line-height: 1.15; flex-shrink: 0; }
.hud-location__name { font-weight: 800; font-size: 0.82rem; color: var(--c-text); }
#objectiveCompass { position: absolute; top: 74px; left: 50%; transform: translateX(-50%); z-index: 100 !important; pointer-events: none !important; }
/* HUD atas: ringkas (1 baris) ↔ penuh (☰) ↔ tersembunyi (👁) — pilihan disimpan per perangkat. */
.hud-extra { display: contents; }
#topHud.is-compact { flex-wrap: nowrap !important; }
#topHud.is-compact .hud-quick > :not(.hud-keep), #topHud.is-compact .hud-extra { display: none !important; }
#topHud.is-compact .hud-quick { width: auto !important; order: 0 !important; flex-wrap: nowrap; }
#topHud.is-compact .hud__group:has(.hud-quick) { width: auto !important; flex-wrap: nowrap !important; }
#topHud.is-hidden { display: none !important; }
#hudShowBtn { display: none; position: absolute; top: 10px; left: 10px; z-index: 121; width: 40px; height: 40px; padding: 0; justify-content: center; opacity: .85; }
#topHud.is-hidden ~ #hudShowBtn { display: inline-flex; }
#topHud.is-hidden ~ #objectiveCompass { display: none; }
@media (max-width: 900px), (pointer: coarse) {
  .hud--top { padding: 6px 8px; flex-wrap: wrap; gap: 6px; }
  .hud-quick { max-width: 100%; width: 100%; order: 3; }
  .hud__group:has(.hud-quick) { width: 100%; flex-wrap: wrap; }
  #objectiveCompass { top: 60px; bottom: auto; left: 10px; transform: none; max-width: 46vw; padding: 4px 10px !important; }
  #objectiveCompass .hud__label { display: none; }
  /* Notifikasi chat: dulu di kiri-bawah tertutup joystick → pindah ke kiri-atas di bawah kartu tujuan. */
  #worldLiveChatHud { bottom: auto !important; top: 104px !important; left: 10px !important; flex-direction: column !important; max-width: 46vw !important; }
  #topHud.is-compact .hud-location .hud__label, #topHud.is-compact .xpbar, #topHud.is-compact .stat__xp { display: none; }
  #topHud.is-compact .hud-location__name { font-size: .78rem; max-width: 26vw; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
  .hud-name { display: none; }
}

/* Zoom kamera */
#zoomCtl { position: absolute; left: 20px; bottom: 160px; z-index: 131; display: flex; gap: 8px; pointer-events: auto; }
#zoomCtl button { width: 46px; height: 46px; border-radius: 12px; border: 2px solid rgba(56,189,248,.65); background: rgba(6,11,20,.82); color: #fff; font-size: 1.4rem; font-weight: 800; cursor: pointer; touch-action: manipulation; }
#zoomCtl button:active { transform: scale(.9); }
body.chat-open #zoomCtl { display: none; }
@media (hover: hover) and (pointer: fine) { #zoomCtl { bottom: 70px; } }

/* Mobile Touch Controls */
#touchJoystickZone {
  position: absolute; bottom: 25px; left: 20px; width: 124px; height: 124px;
  background: radial-gradient(circle, rgba(2, 132, 199, 0.22) 0%, rgba(6, 11, 20, 0.88) 75%);
  border: 2px solid rgba(56, 189, 248, 0.65);
  box-shadow: 0 0 20px rgba(2, 132, 199, 0.45), inset 0 0 16px rgba(56, 189, 248, 0.25);
  border-radius: 50%; z-index: 130 !important; display: none; touch-action: none; pointer-events: auto;
}
#touchJoystickKnob {
  position: absolute; top: 50%; left: 50%; width: 50px; height: 50px;
  background: radial-gradient(circle at 35% 35%, #ff8800, #ea580c);
  border: 2px solid #fff; border-radius: 50%;
  box-shadow: 0 0 16px rgba(255, 107, 0, 0.85);
  transform: translate(-50%, -50%); pointer-events: none;
}
.mobile-action-bar {
  position: absolute; bottom: 20px; right: 16px; z-index: 130 !important;
  display: none; grid-template-columns: repeat(2, 56px); gap: 10px; pointer-events: auto !important;
}
.btn-mobile-act {
  width: 56px; height: 56px; border-radius: 18px; border: 2px solid rgba(255,255,255,0.35);
  background: rgba(11, 20, 36, 0.94); color: #fff; font-weight: 800; font-size: 0.74rem; letter-spacing: 0.02em;
  display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; touch-action: manipulation;
  user-select: none; -webkit-user-select: none; -webkit-tap-highlight-color: transparent;
  pointer-events: auto !important; box-shadow: 0 4px 16px rgba(0,0,0,0.6); transition: transform 0.08s, background 0.15s;
}
.btn-mobile-act:active { transform: scale(0.92); }

@media (max-width: 900px), (pointer: coarse) {
  #touchJoystickZone { display: block; }
  .mobile-action-bar { display: grid; }
  .desktop-hint { display: none !important; }
  #minimapWrapper { width: 180px !important; height: 120px !important; bottom: 154px !important; right: 16px !important; }
  #minimapWrapper.is-expanded { width: calc(100vw - 32px) !important; height: 260px !important; bottom: 80px !important; }
  /* Mic di samping tombol aksi: mudah dijangkau jempol kanan, target sentuh 64px. */
  #pttBtn { top: auto !important; bottom: 20px !important; right: 150px !important; width: 64px; height: 64px; border-radius: 50% !important; padding: 0 !important; font-size: 1.5rem !important; display: flex; align-items: center; justify-content: center; }
  #pttBtn #pttText { display: none; }
}

/* Minimap — ukuran besar & responsive agar denah sekolah terbaca jelas */
#minimapWrapper {
  position: absolute; bottom: 20px; right: 20px; width: 360px; height: 240px;
  background: rgba(15, 23, 42, 0.92); border: 2px solid rgba(34, 211, 238, 0.7);
  border-radius: 14px; overflow: hidden; z-index: 110 !important; cursor: pointer; pointer-events: auto !important; touch-action: manipulation;
  box-shadow: 0 4px 24px rgba(0,0,0,0.6);
  transition: width 0.2s ease, height 0.2s ease;
}
#minimapWrapper.is-expanded {
  width: 520px !important; height: 350px !important;
}

/* Desktop Hint */
.desktop-hint {
  position: absolute; bottom: 20px; left: 20px;
  background: rgba(15, 23, 42, 0.88); border: 1px solid rgba(255,255,255,0.18);
  padding: 8px 14px; border-radius: 8px; font-size: 0.75rem; color: #cbd5e1; z-index: 110 !important;
  pointer-events: none;
}
kbd { background: #334155; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; margin: 0 2px; }

/* Responsive helpers: petunjuk kontrol menyesuaikan perangkat */
.desktop-only { display: inline-flex; }
.mobile-only { display: none; }
@media (max-width: 900px), (pointer: coarse) {
  .desktop-only { display: none !important; }
  .mobile-only { display: inline-flex !important; }
  .hud-btn { font-size: 0.75rem; min-height: 36px; padding: 0 9px; }
  .modal-card { padding: 16px; max-height: 90vh; }
  #worldLobby .modal-card, #worldLobby .card { font-size: 0.92rem; }
  #hotspotPrompt { bottom: 150px; font-size: 0.78rem; padding: 6px 14px; }
}

/* Push-to-talk button */
#pttBtn {
  /* Desktop: tepat di atas minimap (minimap 360×240 di kanan-bawah dulu MENUTUPI tombol ini → tak bisa diklik). */
  position: absolute; bottom: 272px; right: 20px; z-index: 111 !important; min-height: 44px; padding: 0 16px; font-weight: 800;
  cursor: pointer; pointer-events: auto !important; touch-action: manipulation;
  transition: all 0.2s;
}
#pttBtn.is-recording {
  background: #15803d !important; border-color: #4ade80 !important; color: #fff !important;
  box-shadow: 0 0 16px rgba(74,222,128,0.6);
}
#pttBtn.is-recording.is-speaking { box-shadow: 0 0 0 6px rgba(74,222,128,0.35), 0 0 22px rgba(74,222,128,0.9); }

/* Modal */
.modal-overlay {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.82); backdrop-filter: blur(8px); z-index: 10000 !important;
  display: none; align-items: center; justify-content: center; padding: 16px; pointer-events: auto !important; touch-action: auto;
}
.modal-card {
  background: #131a29; border: 1px solid #1f293d; border-radius: 16px;
  width: 100%; max-width: 650px; max-height: 85vh; overflow-y: auto; padding: 20px; pointer-events: auto !important; touch-action: auto;
}
/* Chat = panel dock semi-transparan (bukan modal layar penuh): dunia 3D, joystick & mic
   tetap terlihat dan bisa dipakai sambil mengobrol. */
#worldChatModal.modal-overlay { background: transparent !important; backdrop-filter: none !important; pointer-events: none !important;
  align-items: flex-end; justify-content: flex-start; padding: 0 0 64px 16px; z-index: 9000 !important; }
#worldChatModal .modal-card { pointer-events: auto !important; width: min(380px, 92vw); max-width: 380px !important; padding: 12px 14px;
  background: rgba(10,16,30,0.84); backdrop-filter: blur(6px); box-shadow: 0 12px 32px rgba(0,0,0,.45); }
#worldChatModal h2 { font-size: .95rem !important; }
#worldChatModal #worldChatBox { height: 190px !important; margin-bottom: 8px !important; padding: 8px !important; }
#worldChatModal input { font-size: 16px; } /* ≥16px: iOS tidak auto-zoom */
@media (max-width: 900px), (pointer: coarse) {
  #worldChatModal.modal-overlay { align-items: flex-start; justify-content: center; padding: 56px 0 0 0; }
  #worldChatModal .modal-card { width: min(520px, 58vw); max-height: calc(100dvh - 70px); }
  #worldChatModal .modal-card > div:first-child span[style*="c-neon"] { display: none; }
  #worldChatModal #worldChatBox { height: 26vh !important; }
}
.card-quest-item {
  background: rgba(30,41,59,0.7); border: 1px solid #334155; border-radius: 10px;
  padding: 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
input, select, textarea, button { pointer-events: auto; touch-action: manipulation; }

/* Mobile Landscape Orientation Mode */
.landscape-prompt {
  position: fixed; inset: 0; z-index: 999999 !important;
  background: rgba(7, 11, 20, 0.94); backdrop-filter: blur(12px);
  display: none; align-items: center; justify-content: center; padding: 20px; text-align: center;
}
.landscape-box {
  background: #0f172a; border: 2px solid #f97316; border-radius: 20px;
  max-width: 440px; width: 100%; padding: 26px 20px; box-shadow: 0 10px 40px rgba(249,115,22,0.4);
}
.rotate-phone-anim {
  font-size: 3.2rem; margin-bottom: 12px; display: inline-block;
  animation: phoneRotate 2.4s ease-in-out infinite;
}
@keyframes phoneRotate {
  0% { transform: rotate(0deg); }
  35% { transform: rotate(-90deg); }
  65% { transform: rotate(-90deg); }
  100% { transform: rotate(0deg); }
}

/* ===== HUD & chat versi HP (14-10-2026 sesi 3): bar atas tidak pernah meluber ===== */
@media (max-width: 900px), (pointer: coarse) {
  #topHud { flex-wrap: nowrap !important; padding: 4px 6px !important; gap: 4px !important; top: 6px !important; left: 6px !important; right: 6px !important; }
  #topHud > .hud__group:first-child { flex-shrink: 0; gap: 4px; }
  #topHud > .hud__group:first-child img { width: 26px; height: 26px; }
  #topHud .hud-name, #topHud .stat__coin, #topHud .stat__xp, #topHud .xpbar, #topHud #hudFloor { display: none !important; }
  #topHud > .hud-location { flex: 1 1 auto; min-width: 0; text-align: center; }
  #topHud .hud-location .hud__label { display: none; }
  #topHud .hud-location__name { display: block; font-size: .78rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  #topHud > .hud__group:last-child { flex-shrink: 0; gap: 4px; flex-wrap: nowrap !important; width: auto !important; }
  #topHud .hud-quick { width: auto !important; order: 0 !important; gap: 4px; }
  #topHud .hud-btn { min-height: 38px; min-width: 38px; padding: 0 8px; font-size: .9rem; justify-content: center; }
  #topHud .lbl { display: none; }
  /* Menu ☰ di HP: panel grid di bawah bar (bukan baris yang menyambung keluar layar). */
  #topHud:not(.is-compact) .hud-quick > :not(.hud-keep), #topHud:not(.is-compact) .hud-extra > * { display: none; }
  #hudMenuSheet { display: none; position: absolute; top: 52px; right: 6px; z-index: 140; width: min(330px, calc(100vw - 12px));
    background: rgba(10,16,30,.95); border: 1px solid rgba(56,189,248,.35); border-radius: 14px; padding: 8px;
    grid-template-columns: repeat(2, 1fr); gap: 6px; box-shadow: 0 12px 30px rgba(0,0,0,.5); }
  #topHud:not(.is-compact):not(.is-hidden) ~ #hudMenuSheet { display: grid; }
  #hudMenuSheet .hud-btn { width: 100%; min-height: 42px; justify-content: flex-start; font-size: .82rem; }
  #objectiveCompass { top: 50px !important; }
  #worldLiveChatHud { top: 96px !important; }
  #minimapWrapper { width: 150px !important; height: 100px !important; bottom: auto !important; top: 52px !important; right: 6px !important; }
  #minimapWrapper .minimap-title { display: none; }
  /* Chat di HP: panel samping kiri (landscape) / bottom sheet (potret); joystick disembunyikan saat chat terbuka. */
  #worldChatModal.modal-overlay { align-items: stretch !important; justify-content: flex-start !important; padding: 50px 0 6px 6px !important; }
  #worldChatModal .modal-card { width: min(440px, 50vw) !important; max-height: none !important; height: 100%; display: flex; flex-direction: column; padding: 10px !important; }
  #worldChatModal #worldChatBox { flex: 1 1 auto; height: auto !important; min-height: 60px; }
  #worldChatModal .modal-card > div:first-child { margin-bottom: 6px !important; }
  #worldChatModal .modal-card > div:first-child span[style*="font-size:1.4rem"] { display: none; }
  #worldChatModal a[href="/chat"] { display: none; }
  body.chat-open #touchJoystickZone, body.chat-open #objectiveCompass, body.chat-open #worldLiveChatHud { display: none !important; }
}
@media (max-width: 900px) and (orientation: portrait) {
  #worldChatModal.modal-overlay { align-items: flex-end !important; padding: 0 !important; }
  #worldChatModal .modal-card { width: 100vw !important; height: 55dvh; border-radius: 16px 16px 0 0; }
  #minimapWrapper { top: 50px !important; }
}

.lobby-char { display: flex; gap: 8px; align-items: center; text-align: left; padding: 8px 10px; border-radius: 12px; cursor: pointer;
  background: rgba(15,23,42,.7); border: 1.5px solid rgba(148,163,184,.25); color: #e2e8f0; font: inherit; min-height: 52px; }
.lobby-char b { display: block; font-size: .82rem; } .lobby-char small { display: block; font-size: .68rem; color: #94a3b8; line-height: 1.3; }
.lobby-char__emoji { font-size: 1.4rem; width: 34px; height: 34px; display: grid; place-items: center; border-radius: 10px; background: color-mix(in srgb, var(--cc) 25%, transparent); flex-shrink: 0; }
.lobby-char.is-on { border-color: var(--cc); box-shadow: 0 0 0 2px color-mix(in srgb, var(--cc) 35%, transparent); background: color-mix(in srgb, var(--cc) 12%, #0f172a); }
</style></head><body class="vc-game">

<canvas id="viewport3D"></canvas>

<!-- Mobile Landscape Orientation Assistant -->
<div id="landscapePromptOverlay" class="landscape-prompt">
  <div class="landscape-box">
    <div class="rotate-phone-anim">📱 ➔ 📲</div>
    <h3 style="color:#fff;font-size:1.25rem;margin-bottom:8px">Mode Layar Horizontal (Landscape)</h3>
    <p style="color:#cbd5e1;font-size:0.86rem;line-height:1.5;margin-bottom:18px">
      Demi kenyamanan kendali joystick virtual dan tampilan 3D denah gedung sekolah yang optimal, disarankan memutar posisi HP Anda secara miring (horizontal / landscape).
    </p>
    <div style="display:flex;gap:8px;flex-direction:column">
      <button type="button" class="btn" style="background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;padding:12px;border-radius:10px;font-weight:800;border:none;cursor:pointer;font-size:0.95rem" onclick="enterFullscreenLandscape()">🔄 Layar Penuh Landscape</button>
      <button type="button" class="btn" style="background:rgba(255,255,255,0.1);color:#cbd5e1;padding:10px;border-radius:10px;font-weight:600;border:1px solid rgba(255,255,255,0.2);cursor:pointer;font-size:0.85rem" onclick="dismissLandscapePrompt()">Tetap Main Vertikal (Portrait)</button>
    </div>
  </div>
</div>

<!-- In-game HUD -->
<div class="hud hud--top is-compact" id="topHud">
  <!-- Left: avatar, player, level/XP, coins -->
  <div class="hud__group">
    <img src="/assets/img/logo-silber.jpg" width="30" height="30" alt="Logo SMK Silber" style="border-radius:8px">
    <div class="hud-location hud-name"><span class="hud__label">Pemain</span>
    <span style="font-weight:800;font-size:0.85rem"><?= $username ?> <span class="badge"><?= $role ?></span></span></div>
    <div style="min-width:104px">
      <div class="row row--between" style="gap:6px"><span class="level-pill" id="hudLevel"><?= htmlspecialchars(\VC\I18n::t('hud.level'), ENT_QUOTES) ?> <?= $level ?></span>
      <span class="stat stat__xp">✨ <span id="hudXp"><?= $totalXp ?></span></span></div>
      <div class="xpbar" style="height:8px"><div class="xpbar__fill" id="hudXpBar" style="width:<?= $xpPct ?>%"></div></div>
    </div>
    <span class="stat stat__coin" data-coin-target data-stat="coins">🪙 <span id="hudCoins"><?= $coins ?></span></span>
  </div>

  <!-- Middle: current location + floor indicator -->
  <div class="hud-location">
    <span class="hud__label"><?= htmlspecialchars(\VC\I18n::t('hud.location'), ENT_QUOTES) ?></span>
    <span class="hud-location__name" id="hudLocation">Area Sekolah</span>
  </div>
  <span class="badge badge--gold" id="hudFloor"><?= htmlspecialchars(\VC\I18n::t('hud.floor'), ENT_QUOTES) ?> 1</span>

  <!-- Right: quick buttons + view controls -->
  <div class="hud__group">
    <div class="hud-quick">
      <button class="hud-btn" type="button" onclick="openWorldAiDialog()" title="Si Silber AI" style="border-color:var(--c-gold);color:var(--c-gold)">🤖 Si Silber</button>
      <?php if (!$isGuest): ?><a href="/karakter" class="hud-btn" title="Studio Kustomisasi Karakter" style="text-decoration:none;border-color:var(--c-primary-2);color:var(--c-primary-2)">🎭 Karakter</a><?php endif; ?>
      <button class="hud-btn" type="button" onclick="openCharacterSelectModal()" title="Pilih Cepat">⚡ Ganti</button>
      <button class="hud-btn hud-keep" type="button" onclick="vcNavOpen()" title="Navigasi: cari & arahkan ke ruangan">🧭<span class="lbl"> Navigasi</span></button>
      <button class="hud-btn hud-keep" type="button" onclick="openWorldMissionDialog()" title="Misi">🎯<span class="lbl"> Misi</span></button>
      <button class="hud-btn" type="button" onclick="openWorldEventDialog()" title="Event">🎉 Event</button>
      <button class="hud-btn hud-keep" type="button" onclick="openWorldChatDialog()" title="Chat">💬<span class="lbl"> Chat</span></button>
      <button class="hud-btn" type="button" onclick="toggleMapModal()" title="Peta">🗺️ Peta</button>
      <button class="hud-btn" type="button" onclick="triggerWorldEmote()" title="Emote">😀 Emote</button>
    </div>
    <span class="hud-extra">
    <button class="hud-btn" type="button" id="qualityToggleBtn" onclick="toggleWorldQuality()" title="Kualitas Grafis (Auto / High / Medium / Low)">⚙️ Grafis: Auto</button>
    <button class="hud-btn" type="button" id="dayNightBtn" onclick="toggleWorldDayNight()" title="Siang / Malam">☀️ Siang</button>
    <button class="hud-btn" type="button" onclick="toggleWorldRoof()" title="Mode Atap">🏠</button>
    <?php if ($user !== null && in_array($user['role'], ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'], true)): ?>
    <a href="/admin" class="hud-btn" style="text-decoration:none;border-color:var(--c-gold);color:var(--c-gold)" title="Panel Dashboard Admin">🛡️ Admin</a>
    <?php endif; ?>
    <?php if (!$isGuest): ?><a href="/" class="hud-btn" style="text-decoration:none" title="Kembali ke Dashboard Beranda">🏠 Dashboard</a><?php endif; ?>
    <button class="hud-btn" id="fsBtn" type="button" onclick="toggleGameFullscreen(this)" title="Layar Penuh">⛶ Layar Penuh</button>
    </span>
    <button class="hud-btn" id="hudMoreBtn" type="button" onclick="setHudMode(document.getElementById('topHud').classList.contains('is-compact') ? 'full' : 'compact')" title="Semua menu" aria-expanded="false">☰</button>
    <button class="hud-btn" type="button" onclick="setHudMode('hidden')" title="Sembunyikan HUD (layar penuh)">👁</button>
  </div>
</div>
<button class="hud-btn" id="hudShowBtn" type="button" onclick="setHudMode('compact')" title="Tampilkan HUD">☰</button>
<div id="hudMenuSheet" role="menu" aria-label="Menu permainan"></div>

<!-- Objective compass -->
<div class="hud" id="objectiveCompass" title="<?= htmlspecialchars(\VC\I18n::t('hud.objective'), ENT_QUOTES) ?>">
  <span class="compass"><span class="compass__needle" id="compassNeedle"></span>
  <span><span class="hud__label"><?= htmlspecialchars(\VC\I18n::t('hud.objective'), ENT_QUOTES) ?></span><br><span id="objectiveText" style="font-size:0.8rem">Jelajahi sekolah — cari ruang kelasmu</span></span></span>
</div>

<!-- Interactive Hotspot Prompt -->
<div id="hotspotPrompt" style="position:absolute;bottom:85px;left:50%;transform:translateX(-50%);background:rgba(9,15,27,0.95);border:2px solid #ff6b00;color:#fff;padding:8px 20px;border-radius:24px;font-size:0.85rem;font-weight:800;display:none;align-items:center;gap:10px;z-index:125;cursor:pointer;box-shadow:0 0 24px rgba(255,107,0,0.55);pointer-events:auto;user-select:none;-webkit-tap-highlight-color:transparent;" onclick="if(world)world.interactNearestHotspot()">
  <span style="background:linear-gradient(135deg,#ff6b00,#ea580c);color:#fff;padding:3px 10px;border-radius:6px;font-size:0.75rem;font-weight:800;box-shadow:0 2px 6px rgba(0,0,0,0.5)">TEKAN E / KETUK</span>
  <span id="hotspotPromptText">Buka Interaksi</span>
</div>

<!-- Player Interact Prompt (Multiplayer Live) -->
<div id="playerInteractPrompt" style="position:absolute;bottom:135px;left:50%;transform:translateX(-50%);background:rgba(9,15,27,0.95);border:2px solid #38bdf8;color:#fff;padding:8px 20px;border-radius:24px;font-size:0.85rem;font-weight:800;display:none;align-items:center;gap:10px;z-index:126;cursor:pointer;box-shadow:0 0 24px rgba(56,189,248,0.55);pointer-events:auto;user-select:none;-webkit-tap-highlight-color:transparent;" onclick="if(world)world.interactNearestPlayer()">
  <span style="background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;padding:3px 10px;border-radius:6px;font-size:0.75rem;font-weight:800;box-shadow:0 2px 6px rgba(0,0,0,0.5)">TEKAN F / KETUK</span>
  <span>Interaksi: <span id="playerInteractPromptName" style="color:#38bdf8;font-weight:800">Pemain Dekat</span> 👋</span>
</div>

<!-- Push-to-talk button -->
<button class="hud hud--bottom-right" id="pttBtn" type="button" onclick="togglePtt()" title="<?= htmlspecialchars(\VC\I18n::t('hud.push_to_talk'), ENT_QUOTES) ?>">🎙️ <span id="pttText"><?= htmlspecialchars(\VC\I18n::t('hud.push_to_talk'), ENT_QUOTES) ?></span></button>

<!-- Zoom kamera (HP: juga bisa cubit dua jari) -->
<div id="zoomCtl" aria-label="Zoom kamera"><button type="button" id="zoomInBtn" aria-label="Perbesar (zoom in)">＋</button><button type="button" id="zoomOutBtn" aria-label="Perkecil (zoom out)">－</button></div>

<!-- Touch Joystick & Mobile Buttons -->
<div id="touchJoystickZone"><div id="touchJoystickKnob"></div></div>
<div class="mobile-action-bar">
  <button class="btn-mobile-act" id="btnMobilePlayerAction" type="button" onclick="if(world)world.interactNearestPlayer()" style="display:none;background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;border-color:#38bdf8;box-shadow:0 0 16px rgba(56,189,248,0.7)">👋 Sapa</button>
  <button class="btn-mobile-act" id="btnMobileAction" type="button" onclick="if(world)world.interactNearestHotspot()" style="display:none;background:linear-gradient(135deg,#ff6b00,#ea580c);color:#fff;border-color:#ff8800;box-shadow:0 0 16px rgba(255,107,0,0.7)">✋ Aksi</button>
  <button class="btn-mobile-act" id="btnMobileJump" type="button" onclick="if(world)world.jump()" style="background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;border-color:#38bdf8;box-shadow:0 0 14px rgba(56,189,248,0.5)">⬆ Lompat</button>
  <button class="btn-mobile-act" id="btnMobileSprint" type="button" onclick="toggleMobileSprint(this)">🏃 Lari</button>
</div>

<!-- Desktop Controls Hint -->
<div class="desktop-hint">
  Gerak: <kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> • Kamera Putar: <b>Tahan Klik Kiri / Geser Layar</b> • Zoom: <b>Scroll Mouse / ＋ －</b> • Lompat: <kbd>Spasi</kbd> • Lari: <kbd>Shift</kbd> • Mic: <kbd>V</kbd>
</div>

<!-- Minimap -->
<div id="minimapWrapper" onclick="toggleMapModal()" title="Klik untuk membuka peta lengkap">
  <div class="minimap-title" style="position:absolute;top:6px;left:10px;font-size:0.7rem;font-weight:800;color:#22d3ee;pointer-events:none;text-shadow:0 1px 3px #000;z-index:2">📍 RADAR SEKOLAH</div>
  <button type="button" id="btnExpandMinimap" onclick="event.stopPropagation();toggleMinimapExpand()" style="position:absolute;top:5px;right:6px;background:rgba(15,23,42,0.8);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:6px;padding:2px 7px;font-size:0.75rem;cursor:pointer;z-index:2" title="Perbesar / Perkecil Radar">🔍</button>
  <canvas id="minimapCanvas" style="width:100%;height:100%"></canvas>
</div>

<!-- 2D Map Modal -->
<div id="mapModal" class="modal-overlay">
  <div class="modal-card" style="max-width:880px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h2 style="font-size:1.2rem;color:#fff">🗺️ Peta Sekolah SMK Silber</h2>
      <button onclick="toggleMapModal()" style="background:none;border:none;color:#94a3b8;font-size:1.4rem;cursor:pointer">✕</button>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="display:flex;gap:8px">
        <button onclick="if(map2d){map2d.setFloor(1);switchWorldFloor(1);}" class="hud-btn">Lantai 1</button>
        <button onclick="if(map2d){map2d.setFloor(2);switchWorldFloor(2);}" class="hud-btn">Lantai 2</button>
      </div>
      <div style="display:flex;gap:6px">
        <button onclick="if(map2d)map2d.zoomIn()" class="hud-btn" style="min-height:36px;padding:0 12px;font-size:1.1rem" title="Perbesar Peta">+</button>
        <button onclick="if(map2d)map2d.zoomOut()" class="hud-btn" style="min-height:36px;padding:0 12px;font-size:1.1rem" title="Perkecil Peta">-</button>
        <button onclick="if(map2d)map2d.fitToCampus()" class="hud-btn" style="min-height:36px;padding:0 12px;font-size:0.8rem" title="Pas Ukuran Layar">⛶ Pas Peta</button>
      </div>
    </div>
    <div style="width:100%;height:460px;border:1px solid #1f293d;border-radius:12px;overflow:hidden;position:relative">
      <canvas id="modalMapCanvas" style="width:100%;height:100%"></canvas>
    </div>
    <div id="roomInfo" style="margin-top:12px;font-size:0.85rem;color:#94a3b8">Klik ruangan pada denah untuk melihat informasi atau berpindah tempat. Geser mouse untuk menggeser denah, scroll untuk zoom.</div>
  </div>
</div>

<!-- In-Game Missions Modal -->
<div id="worldMissionModal" class="modal-overlay">
  <div class="modal-card" style="max-width:560px;border-color:var(--c-primary-2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:1.5rem">🎯</span>
        <div><h2 style="font-size:1.15rem;color:#fff;margin:0">Misi Harian & Quest Sekolah</h2><span style="font-size:0.75rem;color:var(--c-gold)">Selesaikan quest untuk mendapatkan XP & Koin</span></div>
      </div>
      <button onclick="toggleWorldMissionModal()" style="background:none;border:none;color:#94a3b8;font-size:1.3rem;cursor:pointer">✕</button>
    </div>
    <div id="worldMissionList" style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px;max-height:min(60vh,520px);overflow-y:auto"><span class="muted">Memuat misi…</span></div>
    <div style="display:flex;justify-content:space-between;align-items:center">
      <?php if (!$isGuest): ?><a href="/misi" class="hud-btn" style="text-decoration:none">Lihat Semua Misi di Portal Misi →</a><?php else: ?><span></span><?php endif; ?>
      <button onclick="toggleWorldMissionModal()" class="hud-btn">Tutup</button>
    </div>
  </div>
</div>

<!-- In-Game Events Modal -->
<div id="worldEventModal" class="modal-overlay">
  <div class="modal-card" style="max-width:560px;border-color:var(--c-neon)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:1.5rem">🎉</span>
        <div><h2 style="font-size:1.15rem;color:#fff;margin:0">Event & Agenda Sekolah</h2><span style="font-size:0.75rem;color:var(--c-neon)">Kegiatan sekolah yang sedang berlangsung</span></div>
      </div>
      <button onclick="toggleWorldEventModal()" style="background:none;border:none;color:#94a3b8;font-size:1.3rem;cursor:pointer">✕</button>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
      <div class="card-quest-item">
        <div>
          <div style="font-weight:700;color:#fff">🏆 Classmeeting Futsal Antar-Jurusan</div>
          <div style="font-size:0.8rem;color:#94a3b8">Lokasi: Lapangan Futsal Sekolah • Waktu: Hari Ini 14:00</div>
        </div>
        <span class="badge badge--neon">Sedang Aktif</span>
      </div>
      <div class="card-quest-item">
        <div>
          <div style="font-weight:700;color:#fff">💻 Kompetisi Web Cyber SMK Silber</div>
          <div style="font-size:0.8rem;color:#94a3b8">Lokasi: Lab Komputer 1 • Pendaftaran dibuka untuk semua siswa</div>
        </div>
        <span class="badge badge--gold">Minggu Ini</span>
      </div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center">
      <a href="/event" class="hud-btn" style="text-decoration:none">Buka Portal Event Lengkap →</a>
      <button onclick="toggleWorldEventModal()" class="hud-btn">Tutup</button>
    </div>
  </div>
</div>

<!-- Live Chat Overlay HUD -->
<div id="worldLiveChatHud" style="position:fixed;bottom:76px;left:16px;z-index:90;display:flex;flex-direction:column-reverse;gap:6px;pointer-events:none;max-width:320px"></div>

<!-- In-Game Chat Modal -->
<div id="worldChatModal" class="modal-overlay">
  <div class="modal-card" style="max-width:520px;border-color:var(--c-primary)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:1.4rem">💬</span>
        <div><h2 style="font-size:1.1rem;color:#fff;margin:0">Obrolan Sekolah 3D</h2><span style="font-size:0.75rem;color:var(--c-neon)">Kirim pesan ke teman di sekitar sekolah</span></div>
      </div>
      <button onclick="toggleWorldChatModal()" style="background:none;border:none;color:#94a3b8;font-size:1.3rem;cursor:pointer">✕</button>
    </div>
    <div id="worldChatBox" style="height:240px;overflow-y:auto;background:rgba(0,0,0,0.35);border:1px solid #1f293d;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
      <div style="background:rgba(30,41,59,0.7);padding:6px 10px;border-radius:8px;font-size:0.85rem">
        <strong style="color:var(--c-primary-2)">Si Silber (Sistem):</strong> Selamat datang di Obrolan 3D! Kamu bisa menyapa teman-temanmu di sini.
      </div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
      <button id="chatChip-global" type="button" class="hud-btn is-active" onclick="setChatChannel('global')" style="min-height:32px;padding:0 12px;font-size:.76rem">🌐 Global</button>
      <button id="chatChip-area" type="button" class="hud-btn" onclick="setChatChannel('area')" style="min-height:32px;padding:0 12px;font-size:.76rem">📍 Area</button>
      <button id="chatChip-private" type="button" class="hud-btn" onclick="setChatChannel('private')" style="min-height:32px;padding:0 12px;font-size:.76rem">✉️ Privat</button>
      <select id="worldChatTarget" onchange="chatWhisperTo = parseInt(this.value,10) || 0;" style="display:none;flex:1;min-width:150px;background:#0f172a;border:1px solid #334155;color:#fff;padding:6px 10px;border-radius:8px;font-size:.78rem;min-height:32px">
        <option value="">— pilih pemain —</option>
      </select>
    </div>
    <form onsubmit="return sendWorldChat(event)" style="display:flex;gap:8px">
      <input id="worldChatInput" placeholder="Tulis pesan obrolan..." style="flex:1;background:#0f172a;border:1px solid #334155;color:#fff;padding:8px 12px;border-radius:8px" required>
      <button type="submit" class="hud-btn" style="background:var(--c-primary);color:#04121a;font-weight:700">Kirim</button>
    </form>
    <div style="margin-top:10px;text-align:right">
      <a href="/chat" style="color:var(--c-primary-2);font-size:0.8rem;text-decoration:none">Buka Ruang Obrolan Penuh →</a>
    </div>
  </div>
</div>

<!-- Si Silber AI Companion Modal for 3D World -->
<div id="worldAiModal" class="modal-overlay">
  <div class="modal-card" style="max-width:520px;border-color:var(--c-primary-2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="display:flex;align-items:center;gap:10px">
        <img src="/assets/mascot/silber-mascot.svg" style="width:38px;height:38px;border-radius:50%;border:2px solid var(--c-gold);background:#090d16;padding:2px">
        <div><h2 style="font-size:1.1rem;color:#fff;margin:0">Si Silber (AI Companion)</h2><span style="font-size:0.75rem;color:var(--c-neon)">● Pendamping Sekolah 3D Aktif</span></div>
      </div>
      <button onclick="toggleWorldAiModal()" style="background:none;border:none;color:#94a3b8;font-size:1.2rem;cursor:pointer">✕</button>
    </div>
    <div id="worldAiHistory" style="height:260px;overflow-y:auto;background:rgba(0,0,0,0.3);border:1px solid #1f293d;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:10px;margin-bottom:12px">
      <div style="background:rgba(30,41,59,0.8);border-radius:10px;padding:8px 12px;font-size:0.85rem;color:#e2e8f0">Halo! Aku <b>Si Silber</b>. Sedang menjelajahi area mana di sekolah? Tanya aku tentang rute gedung, guru yang mengajar, jadwal, atau tips eksplorasi 3D! 🤖</div>
    </div>
    <form onsubmit="return sendWorldAi(event)" style="display:flex;gap:8px">
      <input id="worldAiInput" placeholder="Tanya Si Silber..." style="flex:1;background:#0f172a;border:1px solid #334155;color:#fff;padding:8px 12px;border-radius:8px" required>
      <button type="submit" id="btnWorldAiSend" class="hud-btn" style="background:var(--c-primary);color:#04121a;font-weight:700">Kirim</button>
    </form>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:10px">
      <button class="hud-btn" type="button" onclick="askWorldAiQuick('Apa saja yang bisa kamu bantu?')">💬 Bantuan</button>
      <button class="hud-btn" type="button" onclick="askWorldAiQuick('Beri petunjuk misi saya yang belum selesai')">🎯 Petunjuk misi</button>
      <button class="hud-btn" type="button" onclick="askWorldAiQuick('Tolong selesaikan misi saya')">✅ Selesaikan misi</button>
      <button class="hud-btn" type="button" onclick="askWorldAiQuick('Fitur apa saja yang ada di aplikasi ini?')">🧩 Fitur</button>
      <button class="hud-btn" type="button" onclick="askWorldAiQuick('Bagaimana cara main bareng teman di server ini?')">👥 Main bareng</button>
    </div>
  </div>
</div>

<!-- Character Selection Screen Modal -->
<div id="characterSelectModal" class="modal-overlay">
  <div class="modal-card" style="max-width:760px;border-color:var(--c-primary-2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #1f293d;padding-bottom:10px">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:1.6rem">🎭</span>
        <div>
          <h2 style="font-size:1.2rem;color:#fff;margin:0">Pemilihan Karakter & Avatar Sekolah</h2>
          <span style="font-size:0.75rem;color:var(--c-primary-2)">Pilih karakter identitas jurusan dan seragam SMK Silber</span>
        </div>
      </div>
      <button onclick="toggleCharacterSelectModal()" style="background:none;border:none;color:#94a3b8;font-size:1.4rem;cursor:pointer;padding:4px 8px">✕</button>
    </div>
    <div id="characterPresetsGrid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(220px, 1fr));gap:12px;max-height:55vh;overflow-y:auto;padding-right:4px">
      <div style="color:#94a3b8;font-size:0.85rem;padding:20px;text-align:center;grid-column:1/-1">Memuat katalog karakter... ⏳</div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;border-top:1px solid #1f293d;padding-top:12px">
      <span style="font-size:0.75rem;color:#94a3b8">Karakter yang dipilih otomatis tersimpan di akun Anda dan diterapkan ke dunia 3D.</span>
      <button onclick="toggleCharacterSelectModal()" class="hud-btn">Tutup</button>
    </div>
  </div>
</div>

<!-- Live Player Interaction Modal (Multiplayer) -->
<div id="playerInteractModal" class="modal-overlay">
  <div class="modal-card" style="max-width:540px;border-color:var(--c-primary-2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #1f293d;padding-bottom:10px">
      <div style="display:flex;align-items:center;gap:12px">
        <div id="piModalAvatar" style="width:48px;height:48px;border-radius:50%;background:radial-gradient(circle,#0284c7,#0f172a);border:2px solid #38bdf8;display:flex;align-items:center;justify-content:center;font-size:1.5rem">👤</div>
        <div>
          <div style="display:flex;align-items:center;gap:8px">
            <h2 id="piModalName" style="font-size:1.15rem;color:#fff;margin:0">Nama Pemain</h2>
            <span id="piModalRole" class="badge badge--neon">MURID</span>
          </div>
          <span id="piModalLoc" style="font-size:0.75rem;color:#94a3b8">Lokasi: Area Kampus</span>
        </div>
      </div>
      <button onclick="togglePlayerInteractModal()" style="background:none;border:none;color:#94a3b8;font-size:1.4rem;cursor:pointer;padding:4px 8px">✕</button>
    </div>

    <!-- Quick Interactions -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
      <button type="button" class="btn" onclick="submitPlayerInteract('wave')" style="background:linear-gradient(135deg,#0284c7,#0369a1);padding:10px;font-size:0.85rem">
        👋 Sapa Pemain
      </button>
      <button type="button" class="btn" onclick="submitPlayerInteract('invite_mission')" style="background:linear-gradient(135deg,#10b981,#059669);padding:10px;font-size:0.85rem">
        🎯 Ajak Misi Bersama
      </button>
    </div>

    <!-- Tip / Transfer Coins Card -->
    <div class="card" style="background:rgba(15,23,42,0.9);border:1px solid rgba(245,158,11,0.4);padding:14px;margin-bottom:14px">
      <div style="font-size:0.88rem;font-weight:800;color:var(--c-gold);margin-bottom:8px;display:flex;align-items:center;gap:6px">
        <span>🪙 Kirim Hadiah Koin Silber (P2P Transfer)</span>
      </div>
      <div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap">
        <button type="button" class="hud-btn" style="min-height:32px;padding:2px 10px;font-size:0.78rem" onclick="setTipAmount(5)">+5</button>
        <button type="button" class="hud-btn" style="min-height:32px;padding:2px 10px;font-size:0.78rem" onclick="setTipAmount(10)">+10</button>
        <button type="button" class="hud-btn" style="min-height:32px;padding:2px 10px;font-size:0.78rem" onclick="setTipAmount(25)">+25</button>
        <button type="button" class="hud-btn" style="min-height:32px;padding:2px 10px;font-size:0.78rem" onclick="setTipAmount(50)">+50</button>
        <button type="button" class="hud-btn" style="min-height:32px;padding:2px 10px;font-size:0.78rem" onclick="setTipAmount(100)">+100</button>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="piTipAmount" type="number" min="1" max="500" value="10" placeholder="Jumlah koin" style="width:110px;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px;font-size:0.9rem">
        <input id="piTipNote" type="text" placeholder="Pesan apresiasi (opsional)..." style="flex:1;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px;font-size:0.85rem">
        <button type="button" id="btnSendTip" class="btn btn--gold" style="padding:8px 14px;font-size:0.85rem" onclick="submitPlayerInteract('tip_coins')">Kirim 🚀</button>
      </div>
    </div>

    <!-- Quick Whisper Chat -->
    <form onsubmit="return submitPlayerWhisper(event)" style="display:flex;gap:8px">
      <input id="piWhisperInput" placeholder="Kirim pesan langsung ke pemain ini..." style="flex:1;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px 12px;font-size:0.85rem">
      <button type="submit" class="btn" style="background:#0284c7;padding:8px 14px;font-size:0.85rem">Kirim 💬</button>
    </form>
  </div>
</div>

<!-- Bank Mini SMK Silber Modal (Simulasi Transaksi & Finansial Siswa) -->
<div id="bankMiniModal" class="modal-overlay">
  <div class="modal-card" style="max-width:620px;border-color:var(--c-gold)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #1f293d;padding-bottom:10px">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;font-size:1.6rem;box-shadow:0 0 16px rgba(245,158,11,0.5)">🏦</div>
        <div>
          <h2 style="font-size:1.2rem;color:#fff;margin:0">Bank Mini SMK Silber</h2>
          <span style="font-size:0.75rem;color:var(--c-gold)">Simulasi Transaksi, Tabungan &amp; Literasi Finansial Siswa</span>
        </div>
      </div>
      <button onclick="toggleBankMiniModal()" style="background:none;border:none;color:#94a3b8;font-size:1.4rem;cursor:pointer;padding:4px 8px">✕</button>
    </div>

    <!-- Balance Banner -->
    <div class="card" style="background:linear-gradient(135deg,rgba(15,23,42,0.95),rgba(245,158,11,0.15));border:1px solid rgba(245,158,11,0.5);padding:16px;margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <span style="font-size:0.78rem;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px">Saldo Rekening Koin Silber</span>
          <div style="font-size:1.8rem;font-weight:900;color:var(--c-gold);margin-top:2px">
            🪙 <span id="bankMiniBalance">--</span> Koin
          </div>
        </div>
        <div style="text-align:right">
          <span class="badge badge--gold">Status Aktif</span>
          <div style="font-size:0.75rem;color:#cbd5e1;margin-top:4px">Ledger SQLite Terverifikasi</div>
        </div>
      </div>
    </div>

    <!-- Transfer Form -->
    <div class="card" style="background:rgba(15,23,42,0.85);border:1px solid #1f293d;padding:16px;margin-bottom:14px">
      <h3 style="font-size:0.95rem;color:#fff;margin:0 0 10px;display:flex;align-items:center;gap:6px">
        <span>💸 Transfer Koin ke Rekan Siswa</span>
      </h3>
      <form id="formBankTransfer" onsubmit="submitBankTransfer(event)">
        <div style="margin-bottom:10px">
          <label style="display:block;font-size:0.78rem;color:#94a3b8;margin-bottom:4px">Tujuan Penerima (Username atau NIS Siswa):</label>
          <input id="bankTargetUser" required placeholder="Contoh: siswa_silber atau 99001..." style="width:100%;box-sizing:border-box;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:9px 12px;font-size:0.88rem">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
          <div>
            <label style="display:block;font-size:0.78rem;color:#94a3b8;margin-bottom:4px">Nominal Transfer (1 - 500):</label>
            <input id="bankAmount" type="number" min="1" max="500" value="20" required style="width:100%;box-sizing:border-box;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:9px 12px;font-size:0.88rem">
          </div>
          <div>
            <label style="display:block;font-size:0.78rem;color:#94a3b8;margin-bottom:4px">Kategori Transaksi:</label>
            <select id="bankCategory" style="width:100%;box-sizing:border-box;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:9px 12px;font-size:0.85rem">
              <option value="Simulasi Bank Mini">Simulasi Bank Mini</option>
              <option value="Hadiah Prestasi">Hadiah Prestasi</option>
              <option value="Proyek Siswa">Proyek Siswa</option>
              <option value="Tabungan Bersama">Tabungan Bersama</option>
            </select>
          </div>
        </div>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:0.78rem;color:#94a3b8;margin-bottom:4px">Catatan Tambahan (Opsional):</label>
          <input id="bankNote" placeholder="Catatan transaksi..." style="width:100%;box-sizing:border-box;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:9px 12px;font-size:0.85rem">
        </div>
        <button type="submit" id="btnBankSubmit" class="btn btn--block btn--gold" style="padding:12px;font-size:0.95rem">
          🚀 Proses Transfer Koin Sekarang
        </button>
      </form>
    </div>

    <!-- Educational Footer -->
    <div style="display:flex;align-items:center;gap:10px;font-size:0.75rem;color:#94a3b8;background:rgba(2,132,199,0.08);border:1px solid rgba(2,132,199,0.25);border-radius:10px;padding:10px">
      <span>💡</span>
      <div><b>Misi Edukasi Finansial:</b> Melakukan simulasi transaksi di Bank Mini otomatis memicu penyelesaian misi <b>Transaksi Cerdas</b> (Reward: +100 Koin &amp; +150 XP)!</div>
    </div>
  </div>
</div>
<?php
        // ---- Dashboard lobi SEBELUM masuk permainan 3D (user & admin) ----
        $lobbyMissions = [];
        $lobbyBadges = 0;
        if ($user !== null) {
            try { $lobbyMissions = array_slice(\VC\Domain\Gameplay::getMissions((int)$user['id']), 0, 3); } catch (\Throwable $e) { $lobbyMissions = []; }
            try {
                foreach (\VC\Domain\Gameplay::getAllBadges((int)$user['id']) as $bg) {
                    if (!empty($bg['is_earned'])) { $lobbyBadges++; }
                }
            } catch (\Throwable $e) { $lobbyBadges = 0; }
        }
        $lobbyStaff = in_array($user['role'] ?? '', ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'], true);
        ?>
<div id="worldLobby" class="modal-overlay" style="display:flex;background:rgba(4,7,15,0.94);backdrop-filter:blur(6px);z-index:20000">
  <div class="modal-card" style="max-width:840px;border-color:var(--c-primary-2);padding:22px;max-height:92vh">
    <div style="display:flex;align-items:center;gap:14px;border-bottom:1px solid #1f293d;padding-bottom:14px;margin-bottom:16px;flex-wrap:wrap">
      <img src="/assets/img/logo-silber.jpg" alt="Logo SMK Silber" style="width:56px;height:56px;border-radius:14px;object-fit:cover;border:2px solid var(--c-gold)">
      <div style="flex:1;min-width:200px">
        <div class="row" style="gap:8px"><span class="badge badge--neon">🎮 Dunia 3D</span><span class="badge badge--gold">🌍 Server Live</span></div>
        <h2 style="margin:6px 0 2px;font-size:1.2rem;color:#fff">Dashboard Sebelum Bermain — SMK Silber</h2>
        <span class="muted" style="font-size:.8rem">Satu server, banyak murid: jalan bareng, chat bareng, kerja bareng 🎓</span>
      </div>
      <img src="/assets/mascot/silber-mascot.svg" alt="Si Silber" title="Si Silber — Maskot & AI Pendamping" style="width:64px;height:64px">
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px">
      <div class="card" style="padding:14px;margin:0">
        <div class="row row--between"><strong>👤 Profil Pemain</strong><span class="badge"><?= $role ?></span></div>
        <div style="font-weight:800;margin-top:6px;color:#fff"><?= $username ?></div>
        <div class="row row--between" style="margin-top:8px;font-size:.8rem"><span>⭐ Level <?= $level ?></span><span>✨ <?= $totalXp ?> XP</span></div>
        <div class="xpbar" style="height:8px;margin-top:4px"><div class="xpbar__fill" style="width:<?= $xpPct ?>%"></div></div>
        <div class="row row--between" style="margin-top:8px;font-size:.8rem"><span>🪙 <?= $coins ?> koin</span><span>🏅 <?= $lobbyBadges ?> lencana</span></div>
      </div>

      <div class="card" style="padding:14px;margin:0">
        <div class="row row--between"><strong>🌍 Pilih Server</strong><span class="faint" style="font-size:.72rem" id="srvRefreshHint">live</span></div>
        <div id="srvList" style="margin-top:8px;display:flex;flex-direction:column;gap:6px;max-height:170px;overflow-y:auto;font-size:.8rem">
          <span class="muted">memuat daftar server…</span>
        </div>
        <div class="row" style="gap:6px;margin-top:8px">
          <input id="srvCode" placeholder="Kode undangan" aria-label="Kode undangan server" style="flex:1;min-width:0;font-size:.78rem;padding:8px 10px;border-radius:8px;border:1px solid #1f293d;background:rgba(2,6,12,.5);color:#e2e8f0">
          <button class="hud-btn" type="button" onclick="srvJoinCode()" style="min-height:36px;padding:0 12px;font-size:.75rem">Gabung</button>
        </div>
        <?php if ($lobbyStaff) { ?>
        <button class="hud-btn" type="button" onclick="srvCreateForm()" style="margin-top:8px;min-height:36px;padding:0 12px;font-size:.75rem">+ Server Kelas</button>
        <div id="srvCreateBox" style="display:none;margin-top:8px"></div>
        <?php } ?>
      </div>

      <div class="card" style="padding:14px;margin:0">
        <div class="row row--between"><strong>🎯 Misi Cepat</strong><a href="/misi" style="color:var(--c-primary-2);font-size:.75rem">semua →</a></div>
        <div style="margin-top:8px;display:flex;flex-direction:column;gap:6px">
        <?php if (!$lobbyMissions) { ?>
          <span class="muted" style="font-size:.8rem">Belum ada misi aktif. Masuk lalu jelajahi sekolah dulu! 🚀</span>
        <?php } ?>
        <?php foreach ($lobbyMissions as $lm) { $st = (string)($lm['user_status'] ?? 'not_started'); ?>
          <div class="row row--between" style="font-size:.8rem;gap:8px">
            <span style="color:#e2e8f0"><?= htmlspecialchars((string)($lm['title'] ?? ''), ENT_QUOTES) ?></span>
            <span class="badge"><?= $st === 'completed' ? '✅' : ($st === 'in_progress' ? '⏳' : '🆕') ?></span>
          </div>
        <?php } ?>
        </div>
      </div>
    </div>

    <div class="card" id="lobbyCharCard" style="margin-top:12px;padding:14px;margin-bottom:0">
      <div class="row row--between" style="gap:8px;flex-wrap:wrap"><strong>🎭 Karakter Kamu</strong><span class="faint" style="font-size:.72rem">gaya sama di Dashboard, Dunia Pixel & 3D</span></div>
      <div id="lobbyMyChar" style="display:flex;align-items:center;gap:14px;margin-top:10px">
        <canvas id="lobbyMyCharCv" width="22" height="42" style="width:44px;height:84px;image-rendering:pixelated"></canvas>
        <div style="flex:1;min-width:0"><div style="font-weight:700"><?= htmlspecialchars((string)($user['full_name'] ?? ($user['username'] ?? 'Tamu')), ENT_QUOTES) ?></div>
        <div id="lobbyMyCharInfo" class="muted" style="font-size:.8rem;margin-top:2px"></div>
        <?php if (!$isGuest) { ?><a href="/" class="btn btn--sm" style="margin-top:8px;display:inline-block">✏️ Ubah gaya di Dashboard</a><?php } else { ?><div class="faint" style="font-size:.75rem;margin-top:6px">Mode tamu: gaya default sekolah.</div><?php } ?></div>
      </div>
      <div id="lobbyCharGrid" hidden></div>
      <div id="lobbyCharLore" class="muted" style="font-size:.82rem;line-height:1.55;margin-top:10px;display:none"></div>
    </div>

    <div class="card" style="margin-top:12px;padding:14px;margin-bottom:0">
      <strong>🕹️ Kontrol Bermain</strong>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;font-size:.78rem;color:#94a3b8">
        <span class="badge desktop-only">💻 Laptop: <kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> gerak • Spasi lompat • Shift lari • E interaksi • klik+geser kamera</span>
        <span class="badge mobile-only">📱 HP: joystick kiri • geser layar untuk kamera • tombol Lompat/Lari • naik lantai lewat tangga di dalam gedung</span>
      </div>
    </div>

    <?php if ($lobbyStaff) { ?>
    <div class="card" style="margin-top:12px;padding:14px;border-color:#f97316;margin-bottom:0">
      <strong>🛡️ Panel Guru / Admin</strong>
      <div class="row" style="gap:8px;flex-wrap:wrap;margin-top:8px">
        <a href="/admin" class="hud-btn" style="text-decoration:none">📊 Dashboard Admin</a>
        <a href="/admin/ai" class="hud-btn" style="text-decoration:none">🤖 AI Orchestrator</a>
        <a href="/editor-peta" class="hud-btn" style="text-decoration:none">📐 Editor Denah</a>
        <a href="/jadwal" class="hud-btn" style="text-decoration:none">📅 Kelola Jadwal</a>
        <a href="/tugas" class="hud-btn" style="text-decoration:none">📝 Kelola Tugas</a>
      </div>
    </div>
    <?php } ?>

    <div class="card" style="margin-top:12px;padding:14px;border-color:var(--c-gold);margin-bottom:0">
      <div style="display:flex;gap:12px;align-items:flex-start">
        <img src="/assets/mascot/silber-mascot.svg" alt="Si Silber" style="width:46px;height:46px;flex-shrink:0">
        <div style="flex:1">
          <strong style="color:var(--c-gold)">🤖 Aku Si Silber — siap membantu</strong>
          <div class="muted" style="font-size:.82rem;margin-top:4px">Aku bisa memberi <b>petunjuk misi</b>, membantu <b>menyelesaikan misimu</b>, menjelaskan <b>fitur</b> (denah, jadwal, tugas, karakter, chat, toko), dan mengatur rute ke ruangan.</div>
          <div id="lobbyAiBox" style="display:none;margin-top:10px;background:rgba(0,0,0,.35);border:1px solid #1f293d;border-radius:10px;padding:10px;max-height:190px;overflow-y:auto;font-size:.84rem;line-height:1.6"></div>
          <div class="row" style="gap:8px;margin-top:10px;flex-wrap:wrap">
            <button class="hud-btn" type="button" onclick="lobbyAsk('Apa saja yang bisa kamu bantu?')">💬 Apa yang bisa kamu bantu?</button>
            <button class="hud-btn" type="button" onclick="lobbyAsk('Beri petunjuk misi saya yang belum selesai')">🧭 Petunjuk misi</button>
            <button class="hud-btn" type="button" onclick="lobbyAsk('Bagaimana cara bermain bareng teman di server ini?')">👥 Main bareng</button>
          </div>
        </div>
      </div>
    </div>

    <div class="row row--between" style="gap:10px;margin-top:18px;flex-wrap:wrap">
      <div class="row" style="gap:8px;flex-wrap:wrap">
        <?php if (!$isGuest): ?>
        <a href="/peta" class="hud-btn" style="text-decoration:none">🗺️ Buka Denah 2D</a>
        <a href="/" class="hud-btn" style="text-decoration:none">🏠 Beranda</a>
        <?php endif; ?>
      </div>
      <div class="row" style="gap:8px">
        <?php if (!$isGuest): ?><a href="/karakter" class="hud-btn" style="text-decoration:none">🎨 Studio Avatar</a><?php endif; ?>
        <button class="hud-btn" id="btnEnterWorld" type="button" onclick="closeWorldLobby()" style="background:var(--c-primary);color:#04121a;font-weight:800;padding:0 24px;min-height:46px">🚀 Mulai Menjelajah</button>
<?php if (!$isGuest): ?>        <a class="hud-btn" href="/pixel" style="background:rgba(245,158,11,.2);border-color:#f59e0b;color:#fbbf24;font-weight:800;padding:0 18px;min-height:46px;text-decoration:none">🧱 Pixel World</a><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script nonce="<?= $nonce ?>">
function checkMobileOrientation() {
  var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || (window.innerWidth <= 840 && window.innerHeight > window.innerWidth);
  var overlay = document.getElementById("landscapePromptOverlay");
  if (!overlay) return;
  // Tidak lagi memblokir layar: potret tetap bisa main; cukup saran sekali.
  overlay.style.display = "none";
  if (isMobile && window.innerHeight > window.innerWidth && !window.__vcLandscapeHinted) {
    window.__vcLandscapeHinted = true;
    setTimeout(function () { if (window.UI) UI.toast("📱 Tips: miringkan HP (landscape) untuk tampilan lebih luas", "info", 3500); }, 2500);
  }
}
// iPhone (Safari) tidak punya Fullscreen API/kunci orientasi → label tombol jangan menjanjikan fullscreen.
document.addEventListener("DOMContentLoaded", function () {
  var el = document.documentElement;
  if (!(el.requestFullscreen || el.webkitRequestFullscreen)) {
    var b = document.querySelector('#landscapePromptOverlay button[onclick="enterFullscreenLandscape()"]');
    if (b) b.textContent = "▶ Lanjut (putar HP untuk landscape)";
  }
});
window.addEventListener("resize", checkMobileOrientation);
window.addEventListener("orientationchange", checkMobileOrientation);
document.addEventListener("DOMContentLoaded", checkMobileOrientation);
setTimeout(checkMobileOrientation, 600);

function enterFullscreenLandscape() {
  var el = document.documentElement;
  var rfs = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
  if (rfs) {
    try {
      rfs.call(el).then(function() {
        if (screen.orientation && screen.orientation.lock) {
          screen.orientation.lock("landscape").catch(function(e){});
        }
      }).catch(function(e){});
    } catch(e){}
  }
  dismissLandscapePrompt();
}

function dismissLandscapePrompt() {
  var overlay = document.getElementById("landscapePromptOverlay");
  if (overlay) overlay.style.display = "none";
  sessionStorage.setItem("vc_landscape_dismissed", "1");
}

var srvPendingId = null;
function srvCsrf() {
  var m = document.querySelector('meta[name="csrf"]');
  return m ? m.content : '';
}
function srvChosen() {
  var q = parseInt(new URLSearchParams(location.search).get('server') || '', 10);
  if (q > 0) return q;
  try { return parseInt(sessionStorage.getItem('vc_server_id'), 10) || 1; } catch (e) { return 1; }
}
function srvSave(id) {
  try { sessionStorage.setItem('vc_server_id', String(id)); } catch (e) {}
}
async function srvLoad() {
  var box = document.getElementById('srvList');
  if (!box) return;
  try {
    var r = await fetch('/api/servers?world=3d', { headers: { 'X-CSRF': srvCsrf() } }); // hanya server Dunia 3D
    var d = await r.json();
    var list = Array.isArray(d.servers) ? d.servers : [];
    if (!list.length) { box.innerHTML = '<span class="muted">Belum ada server aktif.</span>'; return; }
    box.innerHTML = '';
    list.forEach(function (s) {
      var isChosen = s.id === srvChosen();
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;align-items:center;gap:8px;justify-content:space-between;padding:6px 8px;border:1px solid ' + (isChosen ? 'var(--c-primary-2)' : '#1f293d') + ';border-radius:8px;background:' + (isChosen ? 'rgba(2,132,199,.12)' : 'rgba(2,6,12,.35)');
      var live = s.online > 0
        ? '<span style="color:#4ade80;font-weight:700">● ' + s.online + ' online</span>'
        : '<span style="color:#64748b">○ idle</span>';
      var cap = ' / ' + s.capacity;
      var label = document.createElement('div');
      label.innerHTML = '<div style="color:#e2e8f0;font-weight:600">' + String(s.name).replace(/</g, '&lt;') + (s.type === 'class' ? ' <span class="badge" style="font-size:.62rem">kelas</span>' : '') + '</div>'
        + '<div style="font-size:.68rem;color:#94a3b8">' + live + ' · ' + s.member_count + cap + ' anggota' + (s.class_name ? ' · ' + String(s.class_name).replace(/</g, '&lt;') : '') + '</div>';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'hud-btn';
      btn.style.cssText = 'min-height:32px;padding:0 10px;font-size:.7rem;flex-shrink:0';
      btn.textContent = isChosen ? '✓ Dipilih' : (s.needs_code ? 'Pakai kode' : 'Pilih');
      btn.onclick = function () { srvPick(s.id, s.needs_code); };
      row.appendChild(label);
      row.appendChild(btn);
      box.appendChild(row);
    });
    var hint = document.getElementById('srvRefreshHint');
    if (hint) hint.textContent = 'aktif: #' + srvChosen();
  } catch (e) {
    box.innerHTML = '<span class="muted">Gagal memuat server.</span>';
  }
}
async function srvPick(id, needsCode) {
  var code = '';
  if (needsCode) {
    srvPendingId = id;
    var inp = document.getElementById('srvCode');
    if (inp) inp.focus();
    var box = document.getElementById('srvList');
    if (box) { box.innerHTML = '<span class="muted">Masukkan kode undangan lalu tekan Gabung.</span>'; }
    return;
  }
  await srvJoin(id, '');
}
async function srvJoinCode() {
  var inp = document.getElementById('srvCode');
  var code = inp ? inp.value.trim() : '';
  if (!srvPendingId) {
    var box = document.getElementById('srvList');
    if (box) box.innerHTML = '<span class="muted">Pilih server yang pakai kode dulu.</span>';
    return;
  }
  await srvJoin(srvPendingId, code);
  srvPendingId = null;
  if (inp) inp.value = '';
}
async function srvJoin(id, code) {
  try {
    var r = await fetch('/api/servers/join', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': srvCsrf() },
      body: JSON.stringify({ server_id: id, code: code, _csrf: srvCsrf() })
    });
    var d = await r.json();
    if (d.status === 'ok' && d.world === 'pixel' && d.redirect) { location.href = d.redirect; return; } // server Dunia Pixel
    if (d.status === 'ok') {
      var changed = id !== srvChosen();
      srvSave(id);
      // Pindah room = muat ulang dengan ?server= agar koneksi WS (chat/suara) ikut pindah.
      if (changed) { location.href = '/dunia?server=' + id; return; }
      srvLoad();
    } else {
      var box = document.getElementById('srvList');
      if (box) box.innerHTML = '<span style="color:#fca5a5">' + String(d.message || 'Gagal bergabung.').replace(/</g, '&lt;') + '</span>';
    }
  } catch (e) {
    var box2 = document.getElementById('srvList');
    if (box2) box2.innerHTML = '<span class="muted">Koneksi gagal.</span>';
  }
}
<?php if ($lobbyStaff) { ?>
function srvCreateForm() {
  var box = document.getElementById('srvCreateBox');
  if (!box) return;
  if (box.style.display !== 'none') { box.style.display = 'none'; return; }
  box.style.display = 'block';
  box.innerHTML = '<div style="display:flex;flex-direction:column;gap:6px;font-size:.78rem">'
    + '<input id="srvName" placeholder="Nama server kelas" aria-label="Nama server" style="padding:8px 10px;border-radius:8px;border:1px solid #1f293d;background:rgba(2,6,12,.5);color:#e2e8f0">'
    + '<div class="row" style="gap:6px"><input id="srvCap" type="number" min="2" max="150" value="30" aria-label="Kapasitas" style="width:90px;padding:8px 10px;border-radius:8px;border:1px solid #1f293d;background:rgba(2,6,12,.5);color:#e2e8f0"><select id="srvAccess" aria-label="Akses server" style="flex:1;padding:8px 10px;border-radius:8px;border:1px solid #1f293d;background:rgba(2,6,12,.5);color:#e2e8f0"><option value="invite">Kode undangan</option><option value="open">Terbuka</option></select></div>'
    + '<button class="hud-btn" type="button" onclick="srvCreate()" style="min-height:36px">Buat Server</button>'
    + '</div>';
}
async function srvCreate() {
  var name = (document.getElementById('srvName') || {}).value || '';
  var cap = (document.getElementById('srvCap') || {}).value || 30;
  var acc = (document.getElementById('srvAccess') || {}).value || 'invite';
  var box = document.getElementById('srvCreateBox');
  try {
    var r = await fetch('/api/servers/create', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': srvCsrf() },
      body: JSON.stringify({ name: name, capacity: parseInt(cap, 10) || 30, access: acc, world: '3d', _csrf: srvCsrf() })
    });
    var d = await r.json();
    if (d.status === 'ok') {
      srvSave(d.server_id);
      srvLoad();
      if (box) box.innerHTML = '<div style="color:#4ade80;font-size:.75rem">Server dibuat! Kode undangan: <b>' + String(d.invite_code || '').replace(/</g, '&lt;') + '</b></div>';
      if (window.vcShare) vcShare.open({ title: 'Server dibuat: ' + name, url: acc === 'invite' && d.invite_code ? '/gabung?kode=' + encodeURIComponent(d.invite_code) : '/dunia?server=' + d.server_id, code: d.invite_code || '', text: 'Ayo masuk server "' + name + '" (Dunia 3D) di Virtual City SMK Silber!' });
    } else if (box) {
      box.innerHTML = '<div style="color:#fca5a5;font-size:.75rem">' + String(d.message || 'Gagal membuat server.').replace(/</g, '&lt;') + '</div>';
    }
  } catch (e) {
    if (box) box.innerHTML = '<div class="muted" style="font-size:.75rem">Koneksi gagal.</div>';
  }
}
<?php } ?>
srvLoad();

window.__vcLobbyOpen = true; // render 3D dijeda selama lobi terbuka (lihat world3d.js)
window.__vcIsGuest = <?= $isGuest ? 'true' : 'false' ?>;
// Pemilih karakter cerita di lobi (bukan di dalam dunia; tanpa tugas).
function renderLobbyCharacters() {
  // Gaya karakter disunting di dashboard (satu sumber). Lobi hanya menampilkannya.
  const myCv = document.getElementById('lobbyMyCharCv');
  if (myCv && window.PixelAvatar && window.__AVATAR) {
    PixelAvatar.paint(myCv, window.__AVATAR.config, false);
    const c = PixelAvatar.normalize(window.__AVATAR.config);
    const info = document.getElementById('lobbyMyCharInfo');
    if (info) info.textContent = (PixelAvatar.OUTFITS[c.outfit] || '') + (c.acc.length ? ' · ' + c.acc.map(function (a) { return (PixelAvatar.ACCESSORIES[a] || a).split(' ')[0]; }).join(' ') : '') + (window.__AVATAR.bio ? ' · “' + window.__AVATAR.bio + '”' : '');
    return;
  }
  const grid = document.getElementById('lobbyCharGrid');
  if (!grid || !window.CharacterStory) return;
  const esc = (t) => String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  CharacterStory.load(function () {
    const list = CharacterStory.getAll() || [];
    const sel = CharacterStory.getSelected();
    grid.innerHTML = list.map(function (c) {
      const on = c.id === sel;
      return '<button type="button" class="lobby-char' + (on ? ' is-on' : '') + '" data-id="' + esc(c.id) + '" style="--cc:' + esc(c.color || '#38bdf8') + '">' +
        '<span class="lobby-char__emoji">' + esc(c.emoji || '👤') + '</span><span><b>' + esc(c.name) + '</b><small>' + esc(c.jurusan || '') + '</small></span></button>';
    }).join('') + '<button type="button" class="lobby-char' + (sel ? '' : ' is-on') + '" data-id="" style="--cc:#64748b"><span class="lobby-char__emoji">🙂</span><span><b>Tanpa karakter</b><small>tampil sebagai diri sendiri</small></span></button>';
    const lore = document.getElementById('lobbyCharLore');
    const cur = sel ? CharacterStory.getById(sel) : null;
    if (lore) { lore.style.display = cur ? 'block' : 'none'; lore.innerHTML = cur ? '<b style="color:' + esc(cur.color) + '">' + esc(cur.emoji) + ' ' + esc(cur.name) + '</b> — ' + esc(cur.lore) : ''; }
    grid.querySelectorAll('.lobby-char').forEach(function (b) {
      b.addEventListener('click', function () {
        const id = b.getAttribute('data-id');
        CharacterStory.setSelected(id);
        if (typeof world !== 'undefined' && world && world.applyStoryCharacter) {
          if (!id) { world.avatarConfig = null; world.createPlayerAvatar(); if (window.sendWsMessage) sendWsMessage({ type: 'avatar_change', avatarConfig: {} }); }
          else world.applyStoryCharacter(id);
        }
        renderLobbyCharacters();
      });
    });
  });
}
document.addEventListener('DOMContentLoaded', renderLobbyCharacters);

function closeWorldLobby() {
  var el = document.getElementById('worldLobby');
  if (el) { el.style.display = 'none'; }
  window.__vcLobbyOpen = false;
  try { sessionStorage.setItem('vc_world_lobby_closed', '1'); } catch (e) {}
  var hint = document.querySelector('.desktop-hint');
  if (hint) hint.style.opacity = '1';
}
async function lobbyAsk(q) {
  var box = document.getElementById('lobbyAiBox');
  if (!box) return;
  box.style.display = 'block';
  box.innerHTML += '<div style="text-align:right;color:var(--c-primary-2);margin-bottom:6px"><b>Kamu:</b> ' + q.replace(/</g, '&lt;') + '</div><div style="color:#94a3b8;margin-bottom:6px">🤖 Si Silber berpikir…</div>';
  box.scrollTop = box.scrollHeight;
  try {
    var meta = document.querySelector('meta[name="csrf"]');
    var csrf = meta ? meta.content : '';
    var r = await fetch('/api/ai/ask', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
      body: JSON.stringify({ question: q, context: 'Lobi sebelum masuk Dunia 3D SMK Silber. Halaman dashboard pra-permainan.', _csrf: csrf })
    });
    var d = await r.json();
    var reply = (d && (d.reply || d.message)) || 'Maaf, aku sedang sibuk. Coba lagi ya!';
    var last = box.lastElementChild;
    if (last && last.textContent.indexOf('berpikir') > -1) last.remove();
    box.innerHTML += '<div style="color:#e2e8f0;background:rgba(30,41,59,.75);padding:8px 10px;border-radius:8px">🤖 <b>Si Silber:</b> ' + String(reply).replace(/</g, '&lt;') + '</div>';
  } catch (e) {
    var last2 = box.lastElementChild;
    if (last2 && last2.textContent.indexOf('berpikir') > -1) last2.remove();
    box.innerHTML += '<div style="color:#fca5a5">🤖 Gagal terhubung ke Si Silber. Coba lagi ya.</div>';
  }
  box.scrollTop = box.scrollHeight;
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    var el = document.getElementById('worldLobby');
    if (el && el.style.display !== 'none') closeWorldLobby();
  }
});
(async function () {
  var el = document.getElementById('lobbyOnline');
  if (!el) return;
  try {
    var meta = document.querySelector('meta[name="csrf"]');
    var csrf = meta ? meta.content : '';
    var r = await fetch('/api/multiplayer/sync', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
      body: JSON.stringify({ x: 135, y: 0, z: 12, floor: 1, _csrf: csrf })
    });
    var d = await r.json();
    if (r.ok && d && d.players) {
      el.innerHTML = '<span style="color:#4ade80">● Online</span> — ' + (d.players.length + 1) + ' pemain di server ini';
    } else {
      el.textContent = 'Masuk dengan akun untuk melihat pemain online';
    }
  } catch (e) { el.textContent = 'Server aktif'; }
})();
</script>
?>
<script src="/assets/vendor/three.min.js" nonce="<?= $nonce ?>"></script>
<?php
        // Integrasi Modul Game (Workstream B/C/E): dipasang setelah three.min.js, sebelum world3d.js.
        // CSS minigames diberi id "minigames-css" agar MiniGames.ensureCSS() tidak double-load.
        echo '<link rel="stylesheet" href="/assets/css/minigames.css" id="minigames-css">';
        echo '<script src="/assets/js/character-story.js" nonce="' . $nonce . '"></script>';
        echo '<script src="/assets/js/missions-minigame.js" nonce="' . $nonce . '"></script>';
        echo '<script src="/assets/js/live-chat.js" nonce="' . $nonce . '"></script>';
        echo '<script src="/assets/js/voice.js?v=7.7.0" nonce="' . $nonce . '"></script>';
        // Karakter kustom v2 (sama dengan dashboard & Dunia Pixel) — dibaca world3d.buildStylizedAvatar.
        $av3d = $user !== null ? \VC\Domain\AvatarStyle::forUser((int)$user['id'], (string)($user['role'] ?? 'murid')) : ['config' => \VC\Domain\AvatarStyle::defaults('murid'), 'bio' => ''];
        echo '<script nonce="' . $nonce . '">window.__AVATAR = ' . json_encode(['config' => $av3d['config'], 'bio' => $av3d['bio']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
        echo '<script src="/assets/js/pixel-avatar.js?v=8.1.2" nonce="' . $nonce . '"></script>';
?>
<script src="/assets/js/world3d.js?v=8.2.0" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/world3d-art.js?v=8.1.2" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/world3d-land.js?v=7.9.3" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/world3d-nature.js?v=7.8.1" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/world3d-arch.js?v=7.9.5" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/world3d-camera.js?v=7.9.2" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/daily.js?v=7.9.5" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/vc-ui-toggle.js?v=7.8.2" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/inventory.js?v=8.2.1" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/world-nav.js?v=7.8.0" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/anomaly.js?v=7.9.5" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/world-life.js?v=7.8.1" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/qr.js?v=7.6.1" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/share.js?v=7.6.1" nonce="<?= $nonce ?>"></script>
<script src="/assets/js/map2d.js?v=7.6.1" nonce="<?= $nonce ?>"></script>
<script nonce="<?= $nonce ?>">
let world = null;
let map2d = null;
// Agent 1 — chat in-game: kanal global/area/privat + daftar pemain WS untuk whisper.
const SELF_ID = <?= (int)($user['id'] ?? 0) ?>;
window.__wsPlayers = new Map();
window.__chatAreaId = window.__chatAreaId || '';
let chatChannel = 'global';
let chatWhisperTo = 0;
let lastGameChatId = 0;
function fillChatTargets() {
  const tsel = document.getElementById("worldChatTarget");
  if (!tsel) return;
  tsel.innerHTML = "<option value=\"\">— pilih pemain —</option>";
  (window.__wsPlayers || new Map()).forEach(function(p, id) {
    if (!p || !p.id || p.id === SELF_ID) return;
    const opt = document.createElement("option");
    opt.value = p.id;
    opt.textContent = (p.fullName || p.username || "Pemain #" + p.id) + " (#" + p.id + ")";
    tsel.appendChild(opt);
  });
}
function setChatChannel(ch) {
  chatChannel = ch;
  ["global", "area", "private"].forEach(function(c) {
    const b = document.getElementById("chatChip-" + c);
    if (b) b.classList.toggle("is-active", c === ch);
  });
  const tsel = document.getElementById("worldChatTarget");
  if (tsel) tsel.style.display = ch === "private" ? "block" : "none";
  const box = document.getElementById("worldChatBox");
  if (box) box.innerHTML = "";
  lastGameChatId = 0;
  worldChatInitialized = false;
  pollWorldChat();
}
let minimap = null;

async function startWorld() {
  try {
    world = new VirtualCampus3D("viewport3D", {
      campusDataUrl: "/api/campus",
      quality: "auto",
      avatarConfig: <?= json_encode($activeAvatar['config'] ?? null) ?>
    });
    await world.init();
    try { sessionStorage.removeItem("vc_3d_retry"); } catch (e) {}

    minimap = new CampusMap2D("minimapCanvas", {
      campusDataUrl: "/api/campus",
      interactive: false,
      trackPlayer: true,
      compactLabels: true,
      scale: 1.15
    });
    await minimap.init();

    // Link minimap marker to 3D player position + keep the HUD in sync.
    let hudTick = 0;
    setInterval(() => {
      if (world && minimap) {
        // Radar memakai denah asli campus.json; dunia 3D direnggangkan (world3d-art.js) → petakan balik.
        const mp = world.unmapPoint ? world.unmapPoint(world.playerPos.x, world.playerPos.z) : { x: world.playerPos.x, z: world.playerPos.z };
        minimap.updatePlayerPos(mp.x, mp.z, world.headingAngle);
        minimap.setFloor(world.currentFloor);
      }
      if (++hudTick % 4 === 0) updateHud();
    }, 100);

    // Real-Time WebSocket Multiplayer with resilient HTTP fallback
    let ws = null;
    let wsActive = false;
    let wsReconnectTimer = null;
    let lastSentPos = { x: 0, y: 0, z: 0, yaw: 0, floor: 1 };
    let posSendThrottle = 0;

    window.sendWsMessage = function(obj) {
      if (wsActive && ws && ws.readyState === WebSocket.OPEN) {
        try { ws.send(JSON.stringify(obj)); return true; } catch(e) {}
      }
      return false;
    };

    async function initWebSocketMultiplayer() {
      try {
        const csrfEl = document.querySelector('meta[name="csrf"]');
        const csrf = csrfEl ? csrfEl.content : "";
        const qsServer = typeof srvChosen === "function" ? srvChosen() : parseInt(new URLSearchParams(location.search).get("server") || "", 10);
        const tokenRes = await fetch("/api/ws/token?world=3d&_csrf=" + encodeURIComponent(csrf) + (qsServer > 0 ? "&server=" + qsServer : ""));
        let tokenData = null;
        try { tokenData = await tokenRes.json(); } catch (e) { tokenData = null; }
        if (!tokenRes.ok && tokenData && tokenData.status === "error") {
          // Ditolak server (bukan anggota / kode salah / server Dunia Pixel): berhenti, jangan reconnect terus.
          wsStopped = true;
          if (tokenData.code === "wrong_world" && tokenData.redirect) {
            if (window.UI) UI.toast("🧱 " + tokenData.message + " Mengalihkan…", "gold", 3500);
            setTimeout(() => { window.location.href = tokenData.redirect; }, 2200);
          } else if (window.UI) UI.toast("⚠️ " + (tokenData.message || "Gagal masuk server multiplayer."), "error", 4500);
          return;
        }
        if (!tokenData || tokenData.status !== "ok" || !tokenData.url) throw new Error("Token error");

        const targetUrl = tokenData.url;
        ws = new WebSocket(targetUrl);

        ws.onopen = () => {
          wsActive = true;
          // Integrasi Modul Game: LiveChat memakai koneksi WS yang sama (tidak membuat koneksi kedua).
          // Dipasang di sini agar wrapper LiveChat tetap merangkai handler onmessage yang sudah ada.
          if (window.LiveChat) { try { LiveChat.init(ws); } catch (e) {} }
          // Desain karakter story → pemain lain (juga setelah reconnect).
          if (world && world.avatarConfig) {
            try { ws.send(JSON.stringify({ type: 'avatar_change', avatarConfig: world.avatarConfig })); } catch (e) {}
          }
          if (window.UI) UI.toast("Terhubung ke server multiplayer real-time ⚡", "success", 1800);
        };

        ws.onmessage = (event) => {
          try {
            const data = JSON.parse(event.data);
            if (!data || !world) return;
            if (window.VoiceChat) VoiceChat.handleWs(data);
            // Anomaly Director (server): anomali yang sama untuk semua pemain di room ini.
            if (window.__vcAnomaly && window.__vcAnomaly.server) {
              if (data.type === 'init' && data.self) window.__vcAnomaly.server({ op: 'hello', me: data.self.id });
              else if (data.type === 'anomaly') window.__vcAnomaly.server(data);
            }
            switch (data.type) {
              case 'init':
                if (data.players) {
                  (data.players || []).forEach(p => { if (p && p.id) window.__wsPlayers.set(p.id, p); });
                  if (world.syncRemotePlayers) world.syncRemotePlayers(data.players);
                }
                break;
              case 'player_join':
                if (data.player) {
                  window.__wsPlayers.set(data.player.id, data.player);
                  if (world.syncRemotePlayers) world.syncRemotePlayers([data.player], false);
                  if (window.UI) UI.toast("👋 " + (data.player.fullName || data.player.username) + " bergabung ke kampus!", "info", 2000);
                }
                break;
              case 'player_interacted':
              case 'player_interaction':
                const fromName = data.fromName || data.fromUsername || 'Seseorang';
                if (data.action === 'wave') {
                  if (world.playTone) world.playTone(660, "sine", 0.12);
                  if (world.remotePlayers && world.remotePlayers.has(data.fromId)) {
                    const rec = world.remotePlayers.get(data.fromId);
                    if (rec) rec.emoteTime = 2.0;
                  }
                  if (window.UI) UI.toast("👋 " + fromName + " melambaikan tangan menyapa Anda!", "info", 3000);
                } else if (data.action === 'invite_mission') {
                  if (world.playTone) world.playTone(740, "triangle", 0.15);
                  if (window.UI) UI.toast("🎯 " + fromName + " mengajak Anda kerja sama misi sekolah!", "gold", 3500);
                } else if (data.action === 'tip_coins') {
                  if (world.playTone) world.playTone(880, "sine", 0.2);
                  if (window.UI) {
                    UI.confetti(20);
                    UI.toast("🪙 " + fromName + " mengirimi Anda hadiah koin!", "gold", 3500);
                  }
                  // Refresh player balance
                  fetch("/api/stats").then(r => r.json()).then(st => {
                    if (st && st.coins !== undefined) {
                      const cEl = document.getElementById("hudCoins");
                      if (cEl) cEl.textContent = st.coins;
                    }
                  }).catch(() => {});
                }
                break;
              case 'player_move':
                if (world.remotePlayers) {
                  const record = world.remotePlayers.get(data.id);
                  const targetY = (Number.isFinite(data.y) && data.y !== 0) ? data.y : ((data.floor === 2) ? 14 : 0);
                  if (record) {
                    record.targetPos.set(data.x, targetY, data.z);
                    record.floor = data.floor;
                    if (data.yaw !== undefined) record.targetYaw = data.yaw;
                  } else if (world.syncRemotePlayers) {
                    world.syncRemotePlayers([data], false);
                  }
                }
                break;
              case 'player_leave':
                if (world.remotePlayers && world.remotePlayers.has(data.id)) {
                  const rec = world.remotePlayers.get(data.id);
                  if (rec && rec.mesh) world.scene.remove(rec.mesh);
                  world.remotePlayers.delete(data.id);
                }
                break;
              case 'player_emote':
                if (world.remoteEmote) world.remoteEmote(data.id, data.emote);
                else if (world.remotePlayers && world.remotePlayers.has(data.id)) {
                  const rec = world.remotePlayers.get(data.id);
                  if (rec) rec.emoteTime = 1.5;
                }
                break;
              case 'player_avatar_change':
                if (world.syncRemotePlayers) {
                  world.syncRemotePlayers([data], false);
                }
                break;
              case 'chat_message': {
                // Agent 1 — pesan live WS (persistent lewat relay PHP; msgId konsisten dengan REST).
                const scope = data.scope || 'global';
                const isSelf = data.id === SELF_ID;
                if (!isSelf && scope !== 'private' && world.showPeerChat) world.showPeerChat(data.id, data.text || "");
                if (!isSelf && typeof showWorldChatBubble === 'function' && scope === 'global') {
                  showWorldChatBubble(data.username || "Pemain", data.text || "", false);
                }
                if (!isSelf && scope === 'private' && window.UI) {
                  UI.toast("✉️ Pesan privat dari " + (data.username || "Pemain") + " — cek tab Privat", "gold", 3200);
                }
                appendChatRow({ id: data.msgId, username: data.username, full_name: data.fullName, text: data.text, scope: scope }, isSelf);
                if (!isSelf && world.playTone) world.playTone(480, "sine", 0.08);
                break;
              }
              case 'server_deleted': {
                // Integrasi Modul Game: server dihapus admin → pesan + redirect ke beranda.
                try { ws.onclose = null; ws.close(); } catch (e) {}
                if (window.UI) UI.toast("⚠️ " + (data.message || "Server permainan ini telah dihapus.") + " Mengalihkan ke beranda…", "gold", 4000);
                setTimeout(() => { window.location.href = "/"; }, 2500);
                break;
              }
            }
          } catch(e) {}
        };

        ws.onclose = (ev) => {
          wsActive = false;
          // 4001 token ditolak, 4002 akun dibuka di tab/perangkat lain → jangan saling tendang tanpa henti.
          if (ev && (ev.code === 4001 || ev.code === 4002)) {
            wsStopped = true;
            if (window.UI) UI.toast(ev.code === 4002 ? "📴 Akun ini dibuka di tab/perangkat lain. Multiplayer di tab ini dihentikan." : "⚠️ Sesi multiplayer ditolak. Muat ulang halaman.", "error", 5000);
            return;
          }
          scheduleWsReconnect();
        };

        ws.onerror = () => {
          wsActive = false;
          try { ws.close(); } catch(e) {}
        };
      } catch(e) {
        wsActive = false;
        scheduleWsReconnect();
      }
    }

    let wsStopped = false;
    function scheduleWsReconnect() {
      if (wsReconnectTimer || wsStopped) return;
      wsReconnectTimer = setTimeout(() => {
        wsReconnectTimer = null;
        initWebSocketMultiplayer();
      }, 7000);
    }

    initWebSocketMultiplayer();

    // Fast network position broadcast (10-15 Hz) over WebSocket when moving
    setInterval(() => {
      if (!world || !world.playerPos) return;
      if (wsActive && ws && ws.readyState === WebSocket.OPEN) {
        const p = world.playerPos;
        const dx = Math.abs(p.x - lastSentPos.x);
        const dy = Math.abs(p.y - lastSentPos.y);
        const dz = Math.abs(p.z - lastSentPos.z);
        const dyaw = Math.abs(world.headingAngle - lastSentPos.yaw);
        const dfloor = world.currentFloor !== lastSentPos.floor;

        if (dx > 0.05 || dy > 0.05 || dz > 0.05 || dyaw > 0.05 || dfloor || (++posSendThrottle % 15 === 0)) {
          lastSentPos = { x: p.x, y: p.y, z: p.z, yaw: world.headingAngle, floor: world.currentFloor };
          ws.send(JSON.stringify({
            type: 'move',
            x: Math.round(p.x * 100) / 100,
            y: Math.round(p.y * 100) / 100,
            z: Math.round(p.z * 100) / 100,
            yaw: Math.round(world.headingAngle * 100) / 100,
            floor: world.currentFloor,
            isMoving: world.isMoving,
            isSprinting: world.isSprinting
          }));
        }
      }
    }, 80);

    // Reliable HTTP Polling Fallback (active if WS is offline or guest)
    setInterval(async () => {
      if (wsActive) return; // Skip polling when WebSocket is connected!
      if (!world || !world.playerPos) return;
      try {
        const csrfEl = document.querySelector('meta[name="csrf"]');
        const csrf = csrfEl ? csrfEl.content : "";
        const res = await fetch("/api/multiplayer/sync", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF": csrf },
          body: JSON.stringify({
            x: world.playerPos.x,
            y: world.playerPos.y,
            z: world.playerPos.z,
            floor: world.currentFloor,
            _csrf: csrf
          })
        });
        if (res.ok) {
          const d = await res.json();
          if (d && d.players && world.syncRemotePlayers) {
            world.syncRemotePlayers(d.players);
          }
        }
      } catch(e) {}
    }, 3500);
  } catch(e) {
    console.error("Gagal inisialisasi 3D:", e);
    // Tanpa alert() — dialog modal bisa membekukan UI di HP. Tampilkan pesan di lobi, lalu alihkan ke denah 2D.
    // Percobaan ulang otomatis SEKALI dengan grafis paling ringan (sering berhasil di HP lama / GPU sibuk).
    var tried = false; try { tried = sessionStorage.getItem("vc_3d_retry") === "1"; } catch (e2) {}
    if (!tried) {
      try { sessionStorage.setItem("vc_3d_retry", "1"); localStorage.setItem("vc_gfx_quality", "low"); } catch (e2) {}
      setTimeout(function () { location.reload(); }, 800);
      return;
    }
    var lobbyEl = document.getElementById('worldLobby');
    if (lobbyEl) lobbyEl.style.display = 'flex';
    var infoBox = document.getElementById('lobbyAiBox');
    var pixUrl = "/pixel?server=" + (typeof srvChosen === "function" ? srvChosen() : 1);
    if (infoBox) {
      infoBox.style.display = 'block';
      infoBox.innerHTML = '<div style="color:#fca5a5;line-height:1.6">⚠️ Grafik 3D belum bisa dibuka di browser ini (' + String((e && e.message) || 'WebGL').replace(/</g, '&lt;').slice(0, 80) + ').<br>' +
        'Coba: tutup tab lain / aplikasi berat, aktifkan "Akselerasi hardware", atau buka lewat Chrome terbaru.</div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">' +
        '<button type="button" class="hud-btn" style="background:var(--c-primary);color:#04121a;font-weight:800" onclick="try{sessionStorage.removeItem(\'vc_3d_retry\')}catch(x){};location.reload()">🔄 Coba 3D lagi</button>' +
        '<a class="hud-btn" style="text-decoration:none" href="' + pixUrl + '">🧱 Masuk kelas yang sama (Mode 2D)</a></div>';
    }
    window.__vcLobbyOpen = false;
  }
}

if (document.readyState === "loading") {
  window.addEventListener("DOMContentLoaded", startWorld);
} else {
  startWorld();
}

// Resolve the room the player currently stands in (bounds are center-based;
// rooms without bounds use a proximity radius like the 2D map does).
const ROOM_PROXIMITY_RADIUS = 16;
function roomAt(x, z, floor) {
  const rooms = (world && world.campusData && world.campusData.rooms) ? world.campusData.rooms : [];
  for (const r of rooms) {
    if ((r.floor || 1) !== floor) continue;
    if (typeof r.x === 'number' && typeof r.z === 'number') {
      if (Math.hypot(x - r.x, z - r.z) <= ROOM_PROXIMITY_RADIUS) return r;
      continue;
    }
    if (!r.bounds) continue;
    const halfW = (r.bounds.width || 0) / 2, halfD = (r.bounds.depth || 0) / 2;
    if (Math.abs(x - r.bounds.x) <= halfW && Math.abs(z - r.bounds.z) <= halfD) return r;
  }
  // Fall back to the containing building (center-based bounds) for area names.
  const buildings = (world && world.campusData && world.campusData.buildings) ? world.campusData.buildings : [];
  for (const b of buildings) {
    if (b.floor !== floor && !b.hasFloor2) continue;
    const halfW = (b.bounds.width || 0) / 2, halfD = (b.bounds.depth || 0) / 2;
    if (Math.abs(x - b.bounds.x) <= halfW && Math.abs(z - b.bounds.z) <= halfD) return b;
  }
  return null;
}

// Agent 1 — rekam kunjungan lokasi ke server (prasyarat misi location-bound).
let lastVisitLocationId = null;
function recordPlayerVisit(loc) {
  if (!loc || !loc.id || lastVisitLocationId === loc.id) return;
  lastVisitLocationId = loc.id;
  const csrfEl = document.querySelector('meta[name="csrf"]');
  fetch("/api/world/visit", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ location_id: loc.id, _csrf: csrfEl ? csrfEl.content : "" })
  }).catch(() => {});
}

function updateHud() {
  if (!world) return;
  const loc = roomAt(world.playerPos.x, world.playerPos.z, world.currentFloor);
  const locEl = document.getElementById("hudLocation");
  if (locEl) locEl.textContent = loc ? loc.name : "Area Sekolah";
  if (loc && loc.id) window.__chatAreaId = loc.id; // kanal chat area = lokasi internal saat ini
  recordPlayerVisit(loc);

  const floorEl = document.getElementById("hudFloor");
  if (floorEl) floorEl.textContent = UI.t("hud.floor") + " " + world.currentFloor;
  if (minimap && minimap.currentFloor !== Math.min(2, world.currentFloor)) minimap.setFloor(Math.min(2, world.currentFloor));

  const needle = document.getElementById("compassNeedle");
  if (needle) needle.style.transform = "rotate(" + (world.headingAngle * 180 / Math.PI) + "deg)";

  // Area-based ambience music (starts only after the first user gesture).
  // Musik chiptune santai (vc-sound.js); arpeggio area lama hanya cadangan.
  if (window.VcMusic) {
    if (!world.__musicStarted) { world.__musicStarted = true; VcMusic.play("game"); }
  } else if (loc && window.gameAudio) {
    const tp = (loc.type || "").toLowerCase();
    const area = tp.includes("lab") ? "lab" : (tp.includes("kantin") ? "kantin" : "kelas");
    gameAudio.playAreaMusic(area);
  }
}

function toggleMinimapExpand() {
  const m = document.getElementById("minimapWrapper");
  if (!m) return;
  m.classList.toggle("is-expanded");
  if (minimap) minimap.resizeCanvas();
}

function toggleMapModal() {
  const modal = document.getElementById("mapModal");
  if (!modal) return;
  modal.style.display = (modal.style.display === "flex") ? "none" : "flex";
  if (modal.style.display === "flex") {
    if (!map2d) {
      map2d = new CampusMap2D("modalMapCanvas", {
        campusDataUrl: "/api/campus",
        interactive: true,
        autoFit: true,
        onSelectRoom: (r) => {
          const tx = (r.x !== undefined) ? r.x : (r.bounds ? r.bounds.x : 0);
          const tz = (r.z !== undefined) ? r.z : (r.bounds ? r.bounds.z : 0);
          const fl = r.floor || 1;
          const bldg = r.building ? (' (' + r.building + ')') : '';
          const info = document.getElementById("roomInfo");
          if (info) {
            info.innerHTML = "<strong style=\"color:#fff\">" + (r.name || 'Lokasi Sekolah') + "</strong>" + bldg + "<br>" +
              "<button id=\"btnTeleportRoom\" style=\"margin-top:8px;padding:8px 16px;background:var(--c-primary);color:#04121a;border:none;border-radius:8px;font-weight:bold;cursor:pointer\">⚡ Teleport ke Lokasi Ini</button>";
            const tpBtn = document.getElementById("btnTeleportRoom");
            if (tpBtn) {
              tpBtn.onclick = function() {
                if (world && typeof world.teleportTo === 'function') {
                  const q = world.mapPoint ? world.mapPoint(tx, tz) : { x: tx, z: tz };
                  world.teleportTo(q.x, q.z, fl);
                  if (world.switchFloor) world.switchFloor(fl);
                }
                toggleMapModal();
                if (window.UI) UI.toast('Berpindah ke ' + (r.name || 'lokasi') + ' ⚡', 'success', 2000);
              };
            }
          }
        }
      });
      map2d.init();
    } else {
      map2d.resizeCanvas();
      map2d.fitToCampus();
    }
  }
}

// 18 emote (sama dengan Dunia Pixel & dashboard) — panel di layar; tombol G membuka cepat.
let emotePanel3d = null;
function triggerWorldEmote() {
  if (!emotePanel3d && window.VirtualCampus3D && VirtualCampus3D.emotePanel) {
    emotePanel3d = VirtualCampus3D.emotePanel(world, function (o) { if (typeof window.sendWsMessage === 'function') window.sendWsMessage(o); });
  }
  if (emotePanel3d) { emotePanel3d.toggle(); return; }
  if (world && typeof world.emote === 'function') world.emote();
  if (typeof window.sendWsMessage === 'function') window.sendWsMessage({ type: 'emote', emote: 'wave' });
}
document.addEventListener('keydown', function (e) {
  if ((e.key === 'g' || e.key === 'G') && !/INPUT|TEXTAREA|SELECT/.test((e.target && e.target.tagName) || '')) triggerWorldEmote();
});

function toggleWorldQuality() {
  if (world && typeof world.toggleQuality === 'function') {
    const q = world.toggleQuality();
    const btn = document.getElementById('qualityToggleBtn');
    if (btn) btn.textContent = '⚙️ Grafis: ' + q.toUpperCase();
    if (window.UI) UI.toast('Kualitas grafis diatur ke ' + q.toUpperCase() + ' ⚙️', 'info', 1600);
  }
}

function toggleWorldDayNight() {
  if (world && typeof world.toggleDayNight === 'function') {
    world.toggleDayNight();
  }
}

function toggleWorldRoof() {
  if (world && typeof world.toggleRoofMode === 'function') {
    world.toggleRoofMode();
    const isVisible = (world.options && world.options.roofMode !== undefined) ? world.options.roofMode : true;
    if (window.UI) UI.toast(isVisible ? 'Mode Atap: Ditampilkan 🏠' : 'Mode Atap: Transparan / Terbuka 🪟', 'info', 1600);
  }
}

function openWorldAiDialog() {
  toggleWorldAiModal();
}

// Quick chips: tanya Si Silber 1 klik (bantuan, petunjuk & penyelesaian misi, fitur).
async function askWorldAiQuick(q) {
  const input = document.getElementById("worldAiInput");
  if (input) input.value = q;
  await sendWorldAi();
}

function toggleWorldAiModal() {
  const modal = document.getElementById("worldAiModal");
  modal.style.display = (modal.style.display === "flex") ? "none" : "flex";
  if (modal.style.display === "flex") {
    setTimeout(() => {
      const inp = document.getElementById("worldAiInput");
      if (inp) inp.focus();
    }, 100);
  }
}

async function sendWorldAi(e) {
  if (e) e.preventDefault();
  const input = document.getElementById("worldAiInput");
  const btn = document.getElementById("btnWorldAiSend");
  const history = document.getElementById("worldAiHistory");
  const q = (input ? input.value : "").trim();
  if (!q) return false;

  input.value = "";
  btn.disabled = true;

  const uDiv = document.createElement("div");
  uDiv.style.cssText = "background:linear-gradient(135deg, var(--c-primary), #0284c7);color:#04121a;font-weight:600;padding:8px 12px;border-radius:10px;align-self:flex-end;max-width:85%;font-size:0.85rem";
  uDiv.textContent = q;
  history.appendChild(uDiv);

  const bDiv = document.createElement("div");
  bDiv.style.cssText = "background:rgba(30,41,59,0.85);color:#e2e8f0;padding:8px 12px;border-radius:10px;align-self:flex-start;max-width:85%;font-size:0.85rem;border:1px solid #334155";
  bDiv.innerHTML = "<em>Si Silber sedang memproses... ⚡</em>";
  history.appendChild(bDiv);
  history.scrollTop = history.scrollHeight;

  const locEl = document.getElementById("hudLocation");
  const locName = locEl ? locEl.textContent : "Area Sekolah";

  try {
    const csrf = document.querySelector('meta[name="csrf"]');
    const token = csrf ? csrf.content : "";
    const res = await fetch("/api/ai/ask", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": token },
      body: JSON.stringify({ question: q, _csrf: token, context: "3D World, Lokasi: " + locName })
    });
    const d = await res.json();
    if (d.status === "ok") {
      bDiv.innerHTML = (d.reply || "").replace(/\n/g, "<br>");
    } else {
      bDiv.innerHTML = "⚠️ " + (d.message || "Gagal menghubungi AI.");
    }
  } catch(err) {
    bDiv.innerHTML = "⚠️ Gangguan koneksi.";
  }

  btn.disabled = false;
  history.scrollTop = history.scrollHeight;
  return false;
}

// Lantai kini fisik (naik lewat tangga). Fungsi ini hanya mengganti tampilan peta 2D,
// TIDAK lagi memindahkan pemain (dulu teleport → posisi tak sinkron antar pemain).
function switchWorldFloor(floor) {
  if (map2d) map2d.setFloor(floor);
}

// HUD atas: 'compact' (1 baris, default) | 'full' (semua tombol) | 'hidden' (layar penuh).
function setHudMode(mode) {
  const hud = document.getElementById("topHud");
  if (!hud) return;
  if (!["compact", "full", "hidden"].includes(mode)) mode = "compact";
  hud.classList.toggle("is-compact", mode === "compact");
  hud.classList.toggle("is-hidden", mode === "hidden");
  const more = document.getElementById("hudMoreBtn");
  if (more) { more.setAttribute("aria-expanded", String(mode === "full")); more.textContent = mode === "full" ? "✕" : "☰"; }
  try { localStorage.setItem("vc_hud_mode", mode); } catch (e) {}
}
(function () {
  // Lembar menu ☰ (HP): salin tombol non-utama dari bar; klik meneruskan ke tombol asli lalu menutup menu.
  const sheet = document.getElementById("hudMenuSheet");
  const hud = document.getElementById("topHud");
  if (sheet && hud) {
    hud.querySelectorAll(".hud-quick > :not(.hud-keep), .hud-extra > *").forEach(function (orig) {
      const b = document.createElement("button");
      b.type = "button"; b.className = "hud-btn"; b.textContent = orig.textContent.trim();
      b.addEventListener("click", function () { orig.click(); setHudMode("compact"); });
      sheet.appendChild(b);
    });
  }
  let m = "compact"; try { m = localStorage.getItem("vc_hud_mode") || "compact"; } catch (e) {}
  // Mode penuh tidak dipulihkan di HP (menu terbuka menutupi layar saat masuk).
  if (m === "full" && matchMedia("(max-width: 900px), (pointer: coarse)").matches) m = "compact";
  setHudMode(m);
})();

// Voice chat (WebRTC, lihat /assets/js/voice.js): tombol mic = toggle, semua di room bisa dengar.
let isPttActive = false;
if (window.VoiceChat) {
  VoiceChat.init(function (obj) { return window.sendWsMessage ? window.sendWsMessage(obj) : false; });
  VoiceChat.onSpeaking = function (id, on) {
    if (id === SELF_ID) {
      const b = document.getElementById("pttBtn");
      if (b) b.classList.toggle("is-speaking", on);
    } else if (world && world.setPeerSpeaking) {
      world.setPeerSpeaking(id, on);
    }
  };
  VoiceChat.onError = function (m) { if (window.UI) UI.toast("🎙️ " + m, "gold", 4000); };
  const unlock = function () { VoiceChat.unlockAudio(); };
  document.addEventListener("pointerdown", unlock, { passive: true });
  document.addEventListener("keydown", unlock);
}
// Pintasan keyboard: V = nyalakan/matikan mikrofon (desktop).
document.addEventListener("keydown", function (e) {
  if (e.repeat || (e.key !== "v" && e.key !== "V")) return;
  const a = document.activeElement;
  if (a && (a.tagName === "INPUT" || a.tagName === "TEXTAREA" || a.isContentEditable)) return;
  togglePtt();
});
async function togglePtt() {
  const want = !isPttActive;
  isPttActive = window.VoiceChat ? await VoiceChat.setMic(want) : false;
  const btn = document.getElementById("pttBtn");
  const txt = document.getElementById("pttText");
  if (btn) btn.classList.toggle("is-recording", isPttActive);
  if (txt) txt.textContent = isPttActive ? "Mic ON" : "Mic OFF";
  if (world && world.playTone) {
    world.playTone(isPttActive ? 600 : 350, "sine", 0.12);
  }
  if (window.UI && (isPttActive || !want)) {
    UI.toast(isPttActive ? "🎙️ Mikrofon aktif — teman sekelas bisa mendengarmu." : "🎙️ Mikrofon dimatikan.", isPttActive ? "success" : "info", 2000);
  }
}

// Layar penuh ala game (button + trigger otomatis sekali di HP saat sentuh pertama)
function toggleGameFullscreen(btn) {
  try {
    var el = document.documentElement;
    var active = document.fullscreenElement || document.webkitFullscreenElement;
    if (!active) {
      var req = el.requestFullscreen || el.webkitRequestFullscreen;
      if (req) { var p = req.call(el); if (p && p.catch) p.catch(function () {}); }
      if (screen.orientation && screen.orientation.lock) {
        try { var lp = screen.orientation.lock('landscape'); if (lp && lp.catch) lp.catch(function () {}); } catch (e) {}
      }
      if (btn) btn.classList.add('is-active');
      if (window.UI) UI.toast('Mode layar penuh aktif 📱', 'success', 1600);
    } else {
      var exit = document.exitFullscreen || document.webkitExitFullscreen;
      if (exit) exit.call(document);
      if (btn) btn.classList.remove('is-active');
    }
  } catch (e) { /* browser menolak fullscreen — biarkan */ }
}

function toggleMobileSprint(btn) {
  if (!world) return;
  world.toggleSprint();
  if (btn) {
    btn.style.background = world.isSprinting ? "var(--c-primary)" : "rgba(15, 23, 42, 0.92)";
    btn.style.color = world.isSprinting ? "#04121a" : "#fff";
    btn.style.borderColor = world.isSprinting ? "#22d3ee" : "rgba(255,255,255,0.45)";
    if (window.UI) UI.toast(world.isSprinting ? "Mode Lari Aktif 🏃" : "Mode Jalan Santai 🚶", "info", 1200);
  }
}

function toggleGameAudio() {
  if (!window.gameAudio) return;
  gameAudio.setMuted(!gameAudio.muted);
  if (window.VcMusic && VcMusic.isOn() === gameAudio.muted) VcMusic.toggle();
  const btn = document.getElementById("audioBtn");
  if (btn) btn.textContent = gameAudio.muted ? "🔇" : "🔊";
  if (window.UI) UI.toast(gameAudio.muted ? "Audio dimatikan 🔇" : "Audio diaktifkan 🔊", "info", 1800);
}

function openWorldMissionDialog() { toggleWorldMissionModal(); }
function toggleWorldMissionModal() {
  const modal = document.getElementById("worldMissionModal");
  if (!modal) return;
  const opening = modal.style.display !== "flex";
  modal.style.display = opening ? "flex" : "none";
  if (opening) loadWorldMissions();
}

// Daftar misi dari database (/api/missions) — termasuk misi yang dibuat guru/admin.
// Dulu daftar ini di-hardcode (3 misi) sehingga misi buatan guru tidak pernah muncul di 3D.
let worldMissionCache = [];
setTimeout(function () { loadWorldMissions(); }, 2500); // isi cache awal (dipakai Bank Mini)
async function loadWorldMissions() {
  const box = document.getElementById("worldMissionList");
  if (!box) return;
  const esc = (t) => String(t == null ? "" : t).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/"/g, "&quot;");
  try {
    const r = await fetch("/api/missions", { credentials: "same-origin" });
    const d = await r.json();
    worldMissionCache = Array.isArray(d.missions) ? d.missions : [];
  } catch (e) {
    box.innerHTML = '<span class="muted">Gagal memuat misi. Coba lagi.</span>';
    return;
  }
  if (!worldMissionCache.length) { box.innerHTML = '<span class="muted">Belum ada misi dari guru.</span>'; return; }
  // Belum selesai di atas, selesai di bawah.
  const list = worldMissionCache.slice().sort((a, b) => (a.user_status === "completed") - (b.user_status === "completed"));
  box.innerHTML = list.map(function (m) {
    const done = m.user_status === "completed";
    const xp = parseInt(m.xp_reward, 10) || 0, coin = parseInt(m.coin_reward, 10) || 0;
    const reward = [xp ? "+" + xp + " XP" : "", coin ? "+" + coin + " 🪙" : ""].filter(Boolean).join(" · ");
    const where = (m.target_type === "location" && m.target_value) ? '<div style="font-size:0.75rem;color:#fbbf24;margin-top:3px">📍 Datangi lokasi: ' + esc(m.target_value) + '</div>' : "";
    const btn = done
      ? '<button class="hud-btn" disabled style="background:#059669;color:#fff;white-space:nowrap">✅ Selesai</button>'
      : '<button class="hud-btn" style="background:var(--c-primary);color:#04121a;white-space:nowrap" onclick="claimWorldMission(this,' + (parseInt(m.id, 10) || 0) + ',' + xp + ',' + coin + ')">Selesaikan</button>';
    return '<div class="card-quest-item"' + (done ? ' style="opacity:.6"' : '') + '><div style="min-width:0">' +
      '<div style="font-weight:700;color:#fff">' + esc(m.icon || "🎯") + " " + esc(m.title) + '</div>' +
      '<div style="font-size:0.8rem;color:#94a3b8">' + esc(m.description || "") + (reward ? ' <b style="color:var(--c-gold)">' + reward + '</b>' : "") + '</div>' + where +
      '</div>' + btn + '</div>';
  }).join("");
}

async function claimWorldMission(btn, missionId, xp, coin) {
  if (btn) btn.disabled = true;
  try {
    const csrfEl = document.querySelector('meta[name="csrf"]');
    const csrf = csrfEl ? csrfEl.content : "";
    const res = await fetch("/api/missions/complete", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": csrf },
      body: JSON.stringify({ mission_id: missionId, _csrf: csrf })
    });
    const d = await res.json();
    if (d.status === "success") {
      if (btn) {
        btn.textContent = "✅ Selesai!";
        btn.style.background = "#059669";
        btn.style.color = "#fff";
      }
      if (world && world.playTone) world.playTone(880, "sine", 0.15);
      if (window.UI) {
        UI.confetti(24);
        UI.toast("🎉 Misi selesai! Hadiah +" + (d.xp_reward || xp) + " XP & +" + (d.coin_reward || coin) + " Koin!", "gold", 2500);
      }
      const cEl = document.getElementById("hudCoins");
      if (cEl && d.coins !== undefined) cEl.textContent = d.coins;
      const xEl = document.getElementById("hudXp");
      if (xEl && d.total_xp !== undefined) xEl.textContent = d.total_xp;
      const lEl = document.getElementById("hudLevel");
      if (lEl && d.level !== undefined) lEl.textContent = "Lv " + d.level;
    } else if (d.status === "duplicate") {
      if (btn) {
        btn.textContent = "✅ Selesai";
        btn.style.background = "#334155";
        btn.style.color = "#94a3b8";
      }
      if (window.UI) UI.toast("ℹ️ Misi ini sudah diselesaikan sebelumnya.", "info", 2000);
    } else {
      if (btn) btn.disabled = false;
      if (window.UI) UI.toast("⚠️ " + (d.message || "Gagal mengklaim misi."), "danger", 2500);
    }
  } catch(e) {
    if (btn) btn.disabled = false;
    if (window.UI) UI.toast("⚠️ Gangguan koneksi ke server.", "danger", 2500);
  }
}

function openWorldEventDialog() { toggleWorldEventModal(); }
function toggleWorldEventModal() {
  const modal = document.getElementById("worldEventModal");
  if (!modal) return;
  modal.style.display = (modal.style.display === "flex") ? "none" : "flex";
}

function openWorldChatDialog() { toggleWorldChatModal(); }
function toggleWorldChatModal() {
  const modal = document.getElementById("worldChatModal");
  if (!modal) return;
  modal.style.display = (modal.style.display === "flex") ? "none" : "flex";
  document.body.classList.toggle("chat-open", modal.style.display === "flex"); // CSS HP: sembunyikan joystick
  if (modal.style.display === "flex") {
    fillChatTargets();
    var tsel = document.getElementById("worldChatTarget");
    if (tsel) tsel.style.display = chatChannel === "private" ? "block" : "none";
    pollWorldChat();
    setTimeout(() => {
      const inp = document.getElementById("worldChatInput");
      if (inp) inp.focus();
    }, 100);
  }
}

let worldChatInitialized = false;

function showWorldChatBubble(sender, text, isSelf) {
  const hud = document.getElementById("worldLiveChatHud");
  if (!hud) return;
  const bubble = document.createElement("div");
  bubble.style.cssText = "background:rgba(15,23,42,0.92);border:1px solid " + (isSelf ? "#0284c7" : "#f97316") + ";border-radius:10px;padding:6px 12px;font-size:0.8rem;color:#fff;box-shadow:0 6px 18px rgba(0,0,0,0.5);opacity:0;transform:translateY(8px);transition:all 0.25s ease-out;backdrop-filter:blur(8px);pointer-events:none;";
  bubble.innerHTML = "<span style=\"color:" + (isSelf ? "#38bdf8" : "#fb923c") + ";font-weight:700\">💬 " + sender.replace(/&/g,"&amp;").replace(/</g,"&lt;") + ":</span> " + text.replace(/&/g,"&amp;").replace(/</g,"&lt;");
  hud.appendChild(bubble);
  requestAnimationFrame(() => {
    bubble.style.opacity = "1";
    bubble.style.transform = "translateY(0)";
  });
  setTimeout(() => {
    bubble.style.opacity = "0";
    bubble.style.transform = "translateY(-6px)";
    setTimeout(() => bubble.remove(), 260);
  }, 4800);
}

function chatScopeParams() {
  let params = "scope=" + encodeURIComponent(chatChannel);
  if (chatChannel === "global" && typeof srvChosen === "function") params += "&server=" + srvChosen();
  if (chatChannel === "area" && window.__chatAreaId) {
    params += "&location_id=" + encodeURIComponent(window.__chatAreaId);
  }
  if (chatChannel === "private" && chatWhisperTo > 0) {
    params += "&with=" + chatWhisperTo;
  }
  return params;
}

function appendChatRow(m, isSelf) {
  const box = document.getElementById("worldChatBox");
  if (!box || !m) return;
  const mid = parseInt(m.msgId || m.id, 10);
  if (!mid) return;
  const rowId = "wmsg-" + mid;
  if (document.getElementById(rowId)) return; // dedupe WS vs polling (id sama dari penyimpanan sama)
  const scope = chatChannel === 'private' ? ' [PRIBADI]' : (chatChannel === 'area' ? ' [AREA]' : '');
  const senderName = isSelf ? "Anda" : (m.full_name || m.username || "Pemain");
  const color = isSelf ? "#38bdf8" : "#fb923c";
  const row = document.createElement("div");
  row.id = rowId;
  row.style.cssText = isSelf
    ? "background:rgba(2,132,199,0.25);border:1px solid #0284c7;padding:6px 10px;border-radius:8px;font-size:0.85rem;color:#f8fafc;align-self:flex-end;max-width:85%"
    : "background:rgba(30,41,59,0.75);border:1px solid #334155;padding:6px 10px;border-radius:8px;font-size:0.85rem;color:#e2e8f0;align-self:flex-start;max-width:85%";
  row.innerHTML = "<strong style=\"color:" + color + "\">" + senderName.replace(/&/g,"&amp;").replace(/</g,"&lt;") + scope + ":</strong> " + String(m.text || "").replace(/&/g,"&amp;").replace(/</g,"&lt;");
  box.appendChild(row);
  box.scrollTop = box.scrollHeight;
}

async function pollWorldChat() {
  const box = document.getElementById("worldChatBox");
  if (!box) return;
  if (chatChannel === "private" && chatWhisperTo <= 0) return;
  try {
    const url = "/api/game/chat?" + chatScopeParams() + (lastGameChatId > 0 ? "&since_id=" + lastGameChatId : "");
    const res = await fetch(url);
    if (!res.ok) return;
    const d = await res.json();
    if (d.status !== "ok" || !Array.isArray(d.messages)) return;
    d.messages.forEach(m => {
      const mid = parseInt(m.id, 10);
      if (mid > lastGameChatId) lastGameChatId = mid;
      const isSelf = parseInt(m.user_id, 10) === SELF_ID; // bukan username: dua siswa bisa bernama sama
      appendChatRow(m, isSelf); // baris modal; gelembung HUD hanya untuk pesan WS live
    });
    worldChatInitialized = true;
  } catch(e) {}
}

async function sendWorldChat(e) {
  if (e) e.preventDefault();
  const input = document.getElementById("worldChatInput");
  const msg = (input ? input.value : "").trim();
  if (!msg) return false;
  if (chatChannel === "private" && chatWhisperTo <= 0) {
    if (window.UI) UI.toast("Pilih penerima untuk pesan privat.", "error", 2500);
    return false;
  }

  input.value = "";
  if (world && world.playTone) world.playTone(520, "sine", 0.1);
  if (chatChannel === "global") {
    showWorldChatBubble("Anda", msg, true);
  }

  // `wsActive` hidup di dalam scope init WS (tidak terlihat di sini → dulu ReferenceError dan
  // pesan hilang). sendWsMessage() mengembalikan true bila terkirim lewat WS; selain itu REST.
  const payload = { type: 'chat', scope: chatChannel, text: msg };
  if (chatChannel === 'area') payload.area = window.__chatAreaId;
  if (chatChannel === 'private') payload.with = chatWhisperTo;
  const sentWs = typeof window.sendWsMessage === 'function' && window.sendWsMessage(payload);
  if (!sentWs) {
    // Fallback REST: persist + moderasi server tetap berjalan walau WS mati.
    try {
      const csrfEl = document.querySelector('meta[name="csrf"]');
      const csrf = csrfEl ? csrfEl.content : "";
      const body = { scope: chatChannel, text: msg, _csrf: csrf };
      if (chatChannel === 'global' && typeof srvChosen === 'function') body.server = srvChosen();
      if (chatChannel === 'area') body.location_id = window.__chatAreaId;
      if (chatChannel === 'private') body.with = chatWhisperTo;
      const res = await fetch("/api/game/chat/send", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF": csrf },
        body: JSON.stringify(body)
      });
      const d = await res.json();
      if (d.status === "error") {
        if (window.UI) UI.toast("⚠️ " + (d.message || "Pesan tidak terkirim."), "error", 3000);
        return false;
      }
      appendChatRow({ id: d.id, username: "<?= $username ?>", text: msg }, true);
    } catch(err) {
      if (window.UI) UI.toast("⚠️ Chat tidak terkirim (server tidak terjangkau).", "error", 2500);
    }
  }
  return false;
}

// Start live chat sync every 2.5 seconds
setTimeout(() => {
  pollWorldChat();
  setInterval(pollWorldChat, 2500);
}, 1200);

function openCharacterSelectModal() {
  toggleCharacterSelectModal();
  loadCharacterPresets();
}

function toggleCharacterSelectModal() {
  const modal = document.getElementById("characterSelectModal");
  if (!modal) return;
  modal.style.display = (modal.style.display === "flex") ? "none" : "flex";
}

async function loadCharacterPresets() {
  const grid = document.getElementById("characterPresetsGrid");
  if (!grid) return;
  grid.innerHTML = '<div style="color:#94a3b8;font-size:0.85rem;padding:20px;text-align:center;grid-column:1/-1">Memuat katalog karakter... ⏳</div>';
  try {
    const res = await fetch("/api/character/presets");
    const d = await res.json();
    if (!d || !d.characters) {
      grid.innerHTML = '<div style="color:#ef4444;padding:20px;grid-column:1/-1">Gagal memuat katalog karakter.</div>';
      return;
    }
    grid.innerHTML = "";
    d.characters.forEach(c => {
      const card = document.createElement("div");
      card.style.cssText = "background:rgba(15,23,42,0.85);border:1px solid " + (c.is_active ? "var(--c-primary-2)" : "#334155") + ";border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:8px;position:relative;" + (c.is_active ? "box-shadow:0 0 16px rgba(34,211,238,0.25);" : "");

      const mascotBadge = c.slug.includes("tkj") ? "🌐 TKJ" : (c.slug.includes("rpl") ? "💻 RPL" : (c.slug.includes("dkv") ? "🎨 DKV" : "🏫 SILBER"));
      const outfitColor = (c.config && c.config.outfit_color) ? c.config.outfit_color : "#0284c7";

      let btnHtml = "";
      if (c.is_active) {
        btnHtml = '<button class="hud-btn" disabled style="background:#059669;color:#fff;width:100%;justify-content:center;cursor:default">✅ Digunakan</button>';
      } else if (c.is_acquired) {
        btnHtml = '<button class="hud-btn" onclick="selectCharacterPreset(' + c.id + ')" style="background:var(--c-primary);color:#04121a;width:100%;justify-content:center;font-weight:700">Gunakan</button>';
      } else if (c.can_unlock) {
        btnHtml = '<button class="hud-btn" onclick="selectCharacterPreset(' + c.id + ')" style="background:var(--c-gold);color:#04121a;width:100%;justify-content:center;font-weight:700">🪙 Buka (' + c.price_coins + ' Koin)</button>';
      } else {
        btnHtml = '<button class="hud-btn" disabled style="background:#334155;color:#94a3b8;width:100%;justify-content:center;cursor:not-allowed">🔒 Butuh Level ' + c.required_level + '</button>';
      }

      card.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
          <span style="font-size:0.75rem;padding:2px 8px;border-radius:6px;background:#1e293b;color:var(--c-primary-2);font-weight:700">${mascotBadge}</span>
          <div style="width:16px;height:16px;border-radius:50%;background:${outfitColor};border:1px solid #fff" title="Warna Seragam"></div>
        </div>
        <div style="font-weight:800;color:#fff;font-size:0.95rem">${c.name}</div>
        <div style="font-size:0.75rem;color:#94a3b8;line-height:1.3;flex:1">${c.description}</div>
        <div style="margin-top:auto">${btnHtml}</div>
      `;
      grid.appendChild(card);
    });
  } catch(e) {
    grid.innerHTML = '<div style="color:#ef4444;padding:20px;grid-column:1/-1">Terjadi kesalahan koneksi.</div>';
  }
}

async function selectCharacterPreset(presetId) {
  try {
    const csrfEl = document.querySelector('meta[name="csrf"]');
    const csrf = csrfEl ? csrfEl.content : "";
    const res = await fetch("/api/character/select", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": csrf },
      body: JSON.stringify({ preset_id: presetId, _csrf: csrf })
    });
    const d = await res.json();
    if (d.status === "success") {
      if (world && d.config) {
        world.setAvatarConfig(d.config);
      }
      if (typeof window.sendWsMessage === 'function' && d.config) {
        window.sendWsMessage({ type: 'avatar_change', avatarConfig: d.config });
      }
      if (window.UI) {
        UI.confetti(16);
        UI.toast("🎭 " + d.message, "success", 2200);
      }
      loadCharacterPresets();
    } else {
      if (window.UI) UI.toast("⚠️ " + (d.message || "Gagal memilih karakter."), "danger", 2500);
    }
  } catch(e) {
    if (window.UI) UI.toast("⚠️ Gangguan koneksi ke server.", "danger", 2500);
  }
}

function openWorldAiDialog() { toggleWorldAiModal(); }
function toggleWorldAiModal() {
  const modal = document.getElementById("worldAiModal");
  if (!modal) return;
  const isOpening = (modal.style.display !== "flex");
  modal.style.display = isOpening ? "flex" : "none";
  if (isOpening) {
    setTimeout(() => {
      const inp = document.getElementById("worldAiInput");
      if (inp) inp.focus();
    }, 100);
  }
}

async function sendWorldAi(e) {
  if (e) e.preventDefault();
  const input = document.getElementById("worldAiInput");
  const history = document.getElementById("worldAiHistory");
  const btn = document.getElementById("btnWorldAiSend");
  if (!input || !history) return false;
  const q = (input.value || "").trim();
  if (!q) return false;

  input.value = "";
  if (btn) btn.disabled = true;

  const userMsg = document.createElement("div");
  userMsg.style.cssText = "align-self:flex-end;max-width:85%;background:linear-gradient(135deg,var(--c-primary),#0284c7);color:#04121a;font-weight:700;padding:8px 12px;border-radius:12px;font-size:0.85rem";
  userMsg.innerHTML = "<b>Kamu:</b> " + q.replace(/&/g,"&amp;").replace(/</g,"&lt;");
  history.appendChild(userMsg);

  const botMsg = document.createElement("div");
  botMsg.style.cssText = "align-self:flex-start;max-width:90%;background:rgba(30,41,59,0.85);color:#e2e8f0;padding:8px 12px;border-radius:12px;font-size:0.85rem;border:1px solid #334155";
  botMsg.innerHTML = "<em>Si Silber sedang berpikir... ⚡</em>";
  history.appendChild(botMsg);
  history.scrollTop = history.scrollHeight;

  try {
    const meta = document.querySelector('meta[name="csrf"]');
    const csrf = meta ? meta.content : '';
    const res = await fetch("/api/ai/ask", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": csrf },
      body: JSON.stringify({ question: q, context: "Dunia 3D Virtual City SMK Silber", _csrf: csrf })
    });
    const d = await res.json();
    const reply = (d && (d.reply || d.message)) || "Maaf, Si Silber sedang memproses tugas lain. Coba lagi ya!";
    botMsg.innerHTML = "🤖 <b>Si Silber:</b> " + String(reply).replace(/\n/g, "<br>");
  } catch(err) {
    botMsg.innerHTML = "⚠️ Terjadi gangguan koneksi jaringan.";
  }

  if (btn) btn.disabled = false;
  history.scrollTop = history.scrollHeight;
  return false;
}

function askWorldAiQuick(question) {
  const input = document.getElementById("worldAiInput");
  if (input) {
    input.value = question;
    sendWorldAi();
  }
}

// Global modal escape key and outside click handling
let currentInteractTarget = null;

function setTipAmount(amt) {
  const inp = document.getElementById("piTipAmount");
  if (inp) inp.value = amt;
}

window.openPlayerInteractModal = function(playerData) {
  currentInteractTarget = playerData;
  const modal = document.getElementById("playerInteractModal");
  if (!modal) return;
  const nameEl = document.getElementById("piModalName");
  const roleEl = document.getElementById("piModalRole");
  const locEl = document.getElementById("piModalLoc");
  const avatarEl = document.getElementById("piModalAvatar");

  if (nameEl) nameEl.textContent = playerData.fullName || playerData.username || "Pemain";
  if (roleEl) {
    roleEl.textContent = (playerData.role || "murid").toUpperCase();
    roleEl.className = "badge " + (playerData.role === "root" ? "badge--gold" : "badge--neon");
  }
  if (locEl) locEl.textContent = "Lokasi: " + (playerData.locationName || "Area Kampus");
  if (avatarEl && playerData.avatarConfig) {
    const col = playerData.avatarConfig.outfit_color || playerData.programColor || "#0284c7";
    avatarEl.style.borderColor = col;
  }

  modal.style.display = "flex";
};

function togglePlayerInteractModal() {
  const modal = document.getElementById("playerInteractModal");
  if (!modal) return;
  modal.style.display = (modal.style.display === "flex") ? "none" : "flex";
}

async function submitPlayerInteract(action) {
  if (!currentInteractTarget || !currentInteractTarget.id) {
    if (window.UI) UI.toast("Pemain tidak ditemukan.", "danger");
    return;
  }
  const targetId = currentInteractTarget.id;
  const targetName = currentInteractTarget.fullName || currentInteractTarget.username;
  const csrfEl = document.querySelector('meta[name="csrf"]');
  const csrf = csrfEl ? csrfEl.content : "";

  let payload = { action: action, target_id: targetId, _csrf: csrf };

  if (action === "wave") {
    if (world && world.triggerEmote) world.triggerEmote("wave");
    if (typeof window.sendWsMessage === 'function') {
      window.sendWsMessage({ type: 'player_interact', targetId: targetId, targetName: targetName, action: 'wave' });
    }
  } else if (action === "tip_coins") {
    const amtInp = document.getElementById("piTipAmount");
    const noteInp = document.getElementById("piTipNote");
    const amount = parseInt(amtInp ? amtInp.value : "10", 10) || 10;
    const note = (noteInp ? noteInp.value : "").trim();
    payload.amount = amount;
    payload.note = note;

    const btn = document.getElementById("btnSendTip");
    if (btn) btn.disabled = true;
  } else if (action === "invite_mission") {
    payload.mission_title = "Misi Petualangan Bersama";
  }

  try {
    const r = await fetch("/api/player/interact", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": csrf },
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.status === "ok" || d.status === "success") {
      if (action === "tip_coins") {
        if (d.sender_coins !== undefined) {
          const cEl = document.getElementById("hudCoins");
          if (cEl) cEl.textContent = d.sender_coins;
        }
        if (window.UI) {
          UI.confetti(20);
          UI.toast("🪙 Berhasil mengirim " + payload.amount + " koin ke " + targetName + "!", "gold", 3000);
        }
        if (typeof window.sendWsMessage === 'function') {
          window.sendWsMessage({ type: 'chat', text: "🪙 [Hadiah Koin] Saya baru saja mengirim " + payload.amount + " koin ke " + targetName + "!" });
        }
        togglePlayerInteractModal();
      } else {
        if (window.UI) UI.toast(d.message || "Interaksi terkirim!", "success", 2500);
      }
    } else {
      if (window.UI) UI.toast("⚠️ " + (d.message || "Gagal berinteraksi."), "danger");
    }
  } catch(e) {
    if (window.UI) UI.toast("Gangguan koneksi.", "danger");
  } finally {
    const btn = document.getElementById("btnSendTip");
    if (btn) btn.disabled = false;
  }
}

function submitPlayerWhisper(e) {
  if (e) e.preventDefault();
  const inp = document.getElementById("piWhisperInput");
  const msg = (inp ? inp.value : "").trim();
  if (!msg || !currentInteractTarget) return false;
  inp.value = "";

  const targetName = currentInteractTarget.fullName || currentInteractTarget.username;
  const fullMsg = "@" + currentInteractTarget.username + ": " + msg;

  if (typeof window.sendWsMessage === 'function') {
    window.sendWsMessage({ type: 'chat', text: fullMsg });
  }
  showWorldChatBubble("Bisik ke " + targetName, msg, true);
  if (window.UI) UI.toast("Pesan bisik terkirim ke " + targetName, "info", 2000);
  togglePlayerInteractModal();
  return false;
}

// ---- BANK MINI MODAL & P2P COIN TRANSFERS ----
window.openBankMiniModal = function() {
  const modal = document.getElementById("bankMiniModal");
  if (!modal) return;
  const coinsEl = document.getElementById("hudCoins"); const coins = (coinsEl && coinsEl.textContent) || "0";
  const balEl = document.getElementById("bankMiniBalance");
  if (balEl) balEl.textContent = coins;
  modal.style.display = "flex";
};

function toggleBankMiniModal() {
  const modal = document.getElementById("bankMiniModal");
  if (!modal) return;
  modal.style.display = (modal.style.display === "flex") ? "none" : "flex";
}

async function submitBankTransfer(e) {
  if (e) e.preventDefault();
  const tuEl = document.getElementById("bankTargetUser"); const targetUser = tuEl ? tuEl.value.trim() : "";
  const amEl = document.getElementById("bankAmount"); const amount = parseInt((amEl && amEl.value) || "0", 10);
  const bnEl = document.getElementById("bankNote"), bcEl = document.getElementById("bankCategory"); const note = (bnEl && bnEl.value.trim()) || (bcEl && bcEl.value);
  const btn = document.getElementById("btnBankSubmit");

  if (!targetUser) {
    if (window.UI) UI.toast("Masukkan username atau NIS siswa penerima.", "danger");
    return false;
  }
  if (amount <= 0 || amount > 500) {
    if (window.UI) UI.toast("Nominal transfer antara 1 - 500 koin.", "danger");
    return false;
  }

  const csrfEl = document.querySelector('meta[name="csrf"]');
  const csrf = csrfEl ? csrfEl.content : "";

  if (btn) btn.disabled = true;
  try {
    const r = await fetch("/api/player/interact", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": csrf },
      body: JSON.stringify({
        action: "tip_coins",
        target_username: targetUser,
        amount: amount,
        note: "Bank Mini: " + note,
        _csrf: csrf
      })
    });
    const d = await r.json();
    if (d.status === "ok" || d.status === "success") {
      if (d.sender_coins !== undefined) {
        const cEl = document.getElementById("hudCoins");
        if (cEl) cEl.textContent = d.sender_coins;
        const balEl = document.getElementById("bankMiniBalance");
        if (balEl) balEl.textContent = d.sender_coins;
      }
      if (window.UI) {
        UI.confetti(25);
        UI.toast("🏦 Transfer Berhasil! " + amount + " Koin terkirim ke " + (d.target_name || targetUser) + "!", "gold", 3500);
      }
      if (typeof window.sendWsMessage === 'function') {
        window.sendWsMessage({ type: 'chat', text: "🏦 [Bank Mini] Transaksi koin berhasil diproses untuk " + (d.target_name || targetUser) });
      }
      // Auto-trigger mission check for 'mission-keuangan'
      try {
        const bankMission = worldMissionCache.find(m => m.target_value === "BANK_MINI");
        if (bankMission && bankMission.user_status !== "completed") fetch("/api/missions/complete", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF": csrf },
          body: JSON.stringify({ mission_id: parseInt(bankMission.id, 10), _csrf: csrf })
        });
      } catch(ignore) {}
      setTimeout(toggleBankMiniModal, 1200);
    } else {
      if (window.UI) UI.toast("⚠️ " + (d.message || "Transfer gagal diproses."), "danger");
    }
  } catch(err) {
    if (window.UI) UI.toast("Gangguan koneksi ke sistem perbankan sekolah.", "danger");
  } finally {
    if (btn) btn.disabled = false;
  }
  return false;
}

window.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    ["mapModal", "worldMissionModal", "worldEventModal", "worldChatModal", "worldAiModal", "characterSelectModal", "playerInteractModal", "bankMiniModal"].forEach(id => {
      const m = document.getElementById(id);
      if (m) m.style.display = "none";
    });
    document.body.classList.remove("chat-open");
  }
});

window.addEventListener("click", (e) => {
  if (e.target && e.target.classList && e.target.classList.contains("modal-overlay")) {
    e.target.style.display = "none";
  }
});

// Prevent Three.js canvas touch and mouse drag from capturing UI button clicks
['mousedown', 'touchstart', 'pointerdown'].forEach(ev => {
  document.addEventListener(ev, (e) => {
    if (e.target && e.target.closest && e.target.closest('.hud, .hud-btn, .modal-card, .btn-mobile-act, .modal-overlay, .mobile-action-bar')) {
      e.stopPropagation();
    }
  }, { passive: true });
});

// Multi-touch game button bindings: immediate response on touchstart without waiting for click
// `world` dideklarasikan dengan `let` → BUKAN properti window. Dulu `window.world.jump()` selalu
// undefined → tombol Lompat / Aksi / Sapa di HP tidak berfungsi sama sekali.
function initMobileTouchControls() {
  if (window.__vcTouchBound) return; // cegah binding ganda (DOMContentLoaded + readyState)
  window.__vcTouchBound = true;
  const jumpBtn = document.getElementById('btnMobileJump');
  const sprintBtn = document.getElementById('btnMobileSprint');
  const floorBtn = document.getElementById('btnMobileFloor');
  const actionBtn = document.getElementById('btnMobileAction');
  const playerActBtn = document.getElementById('btnMobilePlayerAction');

  function bindDirectTouch(btn, actionFn) {
    if (!btn) return;
    let lastTouch = 0;
    const handler = (e) => {
      lastTouch = Date.now();
      if (e.cancelable) e.preventDefault();
      e.stopPropagation();
      actionFn(e);
    };
    btn.addEventListener('touchstart', handler, { passive: false });
    btn.addEventListener('pointerdown', (e) => {
      if (e.pointerType === 'touch') return;
      actionFn(e);
    });
    btn.addEventListener('click', (e) => {
      if (Date.now() - lastTouch < 500) {
        e.preventDefault();
        e.stopPropagation();
      }
    });
  }

  bindDirectTouch(jumpBtn, () => {
    if (world) world.jump();
  });
  bindDirectTouch(sprintBtn, () => {
    toggleMobileSprint(sprintBtn);
  });
  bindDirectTouch(actionBtn, () => {
    if (world) world.interactNearestHotspot();
  });
  bindDirectTouch(playerActBtn, () => {
    if (world) world.interactNearestPlayer();
  });
}
document.addEventListener('DOMContentLoaded', initMobileTouchControls);
if (document.readyState === 'complete' || document.readyState === 'interactive') {
  initMobileTouchControls();
}
</script>
<?php
        echo Footer::gameHtml();
        echo '</body></html>';
    }

    /** Render 2D standalone fallback map page */
    /** Mode PIXEL WORLD — 2D top-down pixel-art playable, data lokasi = locations.json (dokumen mapping) */
    /**
     * Anggota mabar menunggu kapten: tampilkan layar tunggu (bukan dunia) bila kapten belum
     * memilih tujuan atau tujuannya dunia lain. true = halaman tunggu sudah dikirim.
     */
    private static function partyLocked(int $uid, string $world): bool
    {
        try {
            $p = \VC\Domain\Party::lockFor($uid, $world);
        } catch (\Throwable $e) {
            return false;
        }
        if ($p === null) {
            return false;
        }
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES);
        $nonce = Headers::nonce();
        $dest = $p['dest_url'];
        $msg = $dest === ''
            ? 'Kapten <b>' . $e($p['captain_name']) . '</b> belum memilih tujuan. Tunggu di dashboard — kamu akan ikut berangkat otomatis.'
            : 'Kapten <b>' . $e($p['captain_name']) . '</b> memilih tujuan lain. Ikuti kapten supaya tetap bareng.';
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="csrf" content="' . $e(Csrf::token()) . '"><title>Tunggu Kapten — Virtual City</title></head><body>';
        echo '<main class="px-panel" style="max-width:460px;margin:12vh auto;padding:22px 18px;text-align:center">';
        echo '<h1 style="font-size:22px;margin:0 0 10px">⏳ Tunggu Kapten</h1>';
        echo '<p style="font-size:16px;line-height:1.5">Kamu sedang di mabar <b>' . $e($p['name']) . '</b>. ' . $msg . '</p>';
        echo '<p style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:18px">';
        if ($dest !== '') {
            echo '<a class="px-btn px-btn--gold" href="' . $e($dest) . '">🚀 Ikut kapten</a>';
        }
        echo '<a class="px-btn" href="/?mabar=1">🎮 Kembali ke Mabar</a>';
        echo '<button class="px-btn" type="button" id="ptyLeave">🚪 Keluar dari mabar</button></p></main>';
        echo '<script nonce="' . $e($nonce) . '">document.getElementById("ptyLeave").onclick=function(){var t=document.querySelector("meta[name=csrf]").content;'
            . 'fetch("/api/party/action",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-CSRF":t},body:JSON.stringify({action:"leave"})})'
            . '.then(function(){location.reload();});};</script></body></html>';
        return true;
    }

    public static function renderPixelPage(): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();
        if ($user !== null && self::partyLocked((int)$user['id'], 'pixel')) {
            return;
        }
        $username = $user !== null ? htmlspecialchars((string)($user['full_name'] ?: $user['username']), ENT_QUOTES) : 'Tamu';
        $role = $user !== null ? htmlspecialchars((string)$user['role'], ENT_QUOTES) : 'Tamu';
        $level = 1; $totalXp = 0; $coins = 0;
        if ($user !== null) {
            try { $p = \VC\Domain\Gameplay::getProgress((int)$user['id']);
                  $level = (int)$p['level']; $totalXp = (int)$p['total_xp']; $coins = (int)$p['coins'];
            } catch (\Throwable $e) { $level = 1; }
        }
        $visitedIds = [];
        try { $visitedIds = \VC\Domain\Gameplay::getVisitedLocations((int)$user['id']); } catch (\Throwable $e) { $visitedIds = []; }

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">';
        echo '<meta name="csrf" content="' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '">';
        echo \VC\Security\Pwa::headTags();
        echo '<title>Pixel World — Virtual City SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">';
        echo '*{box-sizing:border-box;margin:0;padding:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}';
        echo 'html,body{height:100%;overflow:hidden;background:#0b120c;color:#e2e8f0;touch-action:manipulation}';
        echo '#pixCanvas{position:absolute;inset:0;width:100%;height:100%;display:block;image-rendering:pixelated;touch-action:none}';
        echo '.pixhud{position:absolute;z-index:50;pointer-events:auto}';
        echo '#pixTopbar{top:0;left:0;right:0;display:flex;gap:6px;align-items:center;padding:8px 10px;background:linear-gradient(180deg,rgba(4,7,15,.9),transparent);flex-wrap:wrap}';
        echo '.pbtn{background:rgba(11,20,36,.92);border:1px solid #1f293d;color:#e2e8f0;border-radius:9px;padding:6px 10px;font-weight:700;font-size:.76 rem;min-height:36px;cursor:pointer;text-decoration:none}';
        echo '.pbtn:hover{border-color:#22d3ee}';
        echo '.pstats{display:flex;gap:10px;font-size:.72rem;color:#e9d6b0;align-items:center;background:rgba(20,13,29,.72);padding:4px 8px;flex-wrap:wrap}'; // terbaca di langit siang
        echo '#pixJoy{position:absolute;bottom:24px;left:18px;width:118px;height:118px;border-radius:50%;border:2px solid rgba(245,158,11,.7);background:radial-gradient(circle,rgba(245,158,11,.18),rgba(4,7,15,.85) 78%);z-index:60;touch-action:none;display:none}';
        echo '#pixJoyKnob{position:absolute;top:50%;left:50%;width:44px;height:44px;border-radius:50%;background:radial-gradient(circle at 35% 35%,#fbbf24,#b45309);border:2px solid #fff;transform:translate(-50%,-50%);pointer-events:none}';
        echo '#pixAction{position:absolute;bottom:30px;right:18px;display:none;z-index:60;width:64px;height:64px;border-radius:16px;border:2px solid rgba(255,255,255,.35);background:rgba(11,20,36,.95);color:#fff;font-weight:800;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.6)}';
        echo '#pixPanel{position:absolute;bottom:0;left:0;right:0;max-height:46vh;overflow-y:auto;background:rgba(7,11,20,.96);border-top:2px solid #f59e0b;padding:14px;display:none;z-index:70}';
        echo '#pixPanel h3{color:#f59e0b;font-size:.95rem;margin-bottom:6px}';
        echo '#pixPanel p{font-size:.8rem;line-height:1.6;color:#cbd5e1}';
        echo '.prow{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}';
        echo '#pixSay{position:absolute;bottom:110px;left:50%;transform:translateX(-50%);max-width:78vw;background:rgba(15,23,42,.95);border:1px solid #1f293d;border-radius:12px;padding:8px 12px;font-size:.78rem;color:#f8fafc;display:none;z-index:65}';
        echo '@media (pointer:coarse),(max-width:900px){#pixJoy,#pixAction{display:block}}';
        echo '@media (min-width:901px) and (pointer:fine){#pixJoy,#pixAction{display:none}}';
        // Kontrol sentuh side-view (pixel-side-map.js): ◀ ▶ kiri, ▲ ▼ + AKSI kanan; emote pindah ke atas agar tidak bertumpuk.
        echo '#pixAction{width:72px;height:62px;border-radius:0;font-family:"Pixelify Sans",monospace;font-size:14px;bottom:22px}';
        echo '@media (pointer:coarse),(max-width:900px){#pixEmoteBar{bottom:auto!important;top:52px;left:auto!important;right:10px!important;transform:none!important;flex-direction:row!important}#pixEmoteBar .pbtn{min-width:34px!important;min-height:34px!important;padding:2px 6px}#pixSideBtn{bottom:22px!important;right:calc(14px + 218px)!important}}';
        echo '@media (max-height:420px){#pixMiniWrap{display:none}}';
        // Potret: minimap di kanan atas (tidak menutupi karakter di tengah), emote disembunyikan agar layar lega.
        echo '@media (orientation:portrait) and (max-width:700px){#pixEmoteBar{display:none!important}#pixMiniWrap{top:150px!important;bottom:auto!important;right:6px!important;transform:scale(.8);transform-origin:top right}}';
        echo '.pix-chat-row{background:rgba(58,36,20,.85);padding:6px 9px;align-self:flex-start;max-width:92%;word-wrap:break-word}.pix-chat-row b{color:#ffcf4a}.pix-chat-row.is-mine{background:rgba(194,122,60,.45);align-self:flex-end}.pix-chat-row.is-mine b{color:#fff1d0}';
        echo '#pixSide{background:rgba(34,23,43,.97)!important;border:0!important;border-radius:0!important;box-shadow:inset 0 0 0 3px #6b4423,0 0 0 3px #20130a!important}#pixChatInput{font-size:16px!important}';
        echo '#pixLobi{position:fixed;inset:0;background:rgba(4,7,15,.94);backdrop-filter:blur(6px);z-index:100;display:flex;align-items:center;justify-content:center;padding:16px}';
        echo '.card{background:#131a29;border:1px solid #22d3ee;border-radius:16px;max-width:560px;width:100%;padding:20px}';
        echo '</style></head><body class="vc-game">';

        echo '<canvas id="pixCanvas" tabindex="0" aria-label="Dunia pixel sekolah"></canvas>';

        echo '<div id="pixTopbar" class="pixhud">';
        echo '<a href="/" class="pbtn">🏠</a><a href="/dunia" class="pbtn">🚀 3D</a><button class="pbtn" id="pixMapBtn" onclick="if(pix)pix.openMap()" title="Peta navigasi (M)">🧭 Peta</button><button class="pbtn" onclick="if(pix)pix.zoomBy(1.2)" title="Perbesar (zoom in)" aria-label="Zoom in">＋</button><button class="pbtn" onclick="if(pix)pix.zoomBy(1/1.2)" title="Perkecil (zoom out)" aria-label="Zoom out">－</button><button class="pbtn" id="pixChatBtn" onclick="pixOpenChat()" title="Chat (C)">💬 Chat</button><button class="pbtn" id="pixMicBtn" onclick="pixToggleMic()" title="Mic on/off (V)">🔇 Mic</button><a href="/misi" class="pbtn">🎯 Misi</a>';
        echo '<button class="pbtn" onclick="openTravelingModal()" title="Traveling Antar-Kota & Peta Google Maps">🚌 Traveling</button>';
        echo '<button class="pbtn" id="pixServerBtn" onclick="openServerSwitcher()" title="Pilih / Ganti Server Multiplayer">🌐 <span id="pixCurrentServerName">Silber Public Pixel</span></button>';
        echo '<button class="pbtn" id="pixAudioBtn" onclick="toggleAudio()" title="Audio SFX & Musik">🔊 Audio</button>';
        echo '<button class="pbtn" id="pixVoiceBtn" onclick="togglePixVoice()" title="Voice Signaling Push-to-Talk">🎙️ Voice</button>';
        echo '<button class="pbtn" id="pixPlayersBtn" onclick="togglePlayersModal()" title="Lihat Pemain Online">👥 <span id="pixOnlineCount">1</span></button>';
        echo '<button class="pbtn" id="pixPauseBtn">⏸️ Menu</button>';
        echo '<span class="pstats"><span>👤 ' . $username . '</span><span>⭐ Lv' . $level . '</span><span>🪙 ' . $coins . '</span><span>📍 <span id="pixWhere">Gerbang</span></span><span id="pixMpPill" style="color:#94a3b8">● MP: offline</span></span>';
        echo '</div>';

        echo '<div id="pixJoy"><div id="pixJoyKnob"></div></div>';
        echo '<button id="pixAction">AKSI</button>';
        echo '<div id="pixSay"></div>';
        echo '<div id="pixPanel"></div>';
        echo <<<'PIXHTML'
<div id="pixSide" style="position:absolute;top:60px;right:10px;width:min(340px,88vw);max-height:70vh;overflow-y:auto;background:rgba(7,11,20,.96);border:1px solid #22d3ee;border-radius:14px;padding:12px;display:none;z-index:72">
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
    <button id="pixTabChat" class="pbtn is-active" onclick="pixTab('chat')">💬 Chat</button>
    <button id="pixTabMisi" class="pbtn" onclick="pixTab('misi')">🎯 Misi</button>
    <button id="pixTabTugas" class="pbtn" onclick="pixTab('tugas')">📝 Tugas</button>
    <button class="pbtn" onclick="pixSideToggle()" style="margin-left:auto">✖</button>
  </div>
  <div id="pixChatSec">
    <div style="display:flex;gap:6px;margin-bottom:8px">
      <button id="pixChip-global" class="pbtn is-active" onclick="pixChatScope('global')" style="min-height:30px;padding:4px 10px;font-size:.72rem">🌐 Global</button>
      <button id="pixChip-area" class="pbtn" onclick="pixChatScope('area')" style="min-height:30px;padding:4px 10px;font-size:.72rem">📍 Area</button>
    </div>
    <div id="pixChatBox" style="height:200px;overflow-y:auto;background:rgba(2,6,12,.5);border-radius:8px;padding:8px;display:flex;flex-direction:column;gap:6px;font-size:.78rem;color:#e2e8f0"></div>
    <input id="pixChatInput" placeholder="Tulis pesan…" style="margin-top:8px;background:#0d121d;color:#fff;border:1px solid #1f293d;border-radius:8px;padding:8px;font-size:.78rem;width:100%;box-sizing:border-box">
    <button class="pbtn" style="width:100%;margin-top:6px;background:#f59e0b;color:#1c1204" onclick="pixChatSend()">Kirim</button>
  </div>
  <div id="pixMisiSec" style="display:none;font-size:.78rem;line-height:1.55;color:#cbd5e1"></div>
  <div id="pixTugasSec" style="display:none;font-size:.78rem;line-height:1.55;color:#cbd5e1"></div>
</div>
<button id="pixSideBtn" class="pixhud" style="bottom:34px;right:92px;display:none;width:52px;height:52px;border-radius:14px;border:2px solid rgba(255,255,255,.35);background:rgba(11,20,36,.94);color:#fff;font-weight:800;cursor:pointer;z-index:60">💬</button>
<div id="pixMiniWrap" class="pixhud" style="top:52px;right:10px;z-index:48;background:rgba(7,11,20,.85);border:1px solid #1f293d;border-radius:10px;padding:4px">
  <canvas id="pixMini" style="width:180px;height:96px;display:block;image-rendering:pixelated;cursor:pointer" aria-label="Minimap sekolah, klik untuk pergi"></canvas>
  <div style="font-size:.6rem;color:#94a3b8;text-align:center;margin-top:2px">Klik minimap untuk pergi</div>
</div>
<div id="pixEmoteBar" class="pixhud" style="top:50%;right:10px;transform:translateY(-50%);flex-direction:column;display:flex;gap:6px;z-index:60">
  <button class="pbtn" data-emote="wave" title="Lambaikan Tangan" style="min-width:40px;min-height:40px">👋</button>
  <button class="pbtn" data-emote="point" title="Tunjuk Arah" style="min-width:40px;min-height:40px">👉</button>
  <button class="pbtn" data-emote="clap" title="Tepuk Tangan" style="min-width:40px;min-height:40px">👏</button>
  <button class="pbtn" data-emote="cheer" title="Sorak Gembira" style="min-width:40px;min-height:40px">🎉</button>
  <button class="pbtn" data-emote="sit" title="Duduk Istirahat" style="min-width:40px;min-height:40px">🪑</button>
</div>
<div id="pixPlayers" style="position:absolute;top:60px;right:10px;width:min(320px,86vw);max-height:50vh;overflow-y:auto;background:rgba(7,11,20,.96);border:1px solid #22d3ee;border-radius:14px;padding:12px;display:none;z-index:72;font-size:.78rem;color:#e2e8f0">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <b style="color:#f59e0b">👥 Pemain Terhubung di Server</b>
    <button class="pbtn" onclick="document.getElementById('pixPlayers').style.display='none'" style="min-height:26px;padding:2px 8px">✖</button>
  </div>
  <div id="pixPlayersList" style="line-height:1.9"></div>
</div>
PIXHTML;

        $tasksJson = '[]';
        if ($user !== null) {
            try {
                $uid = (int)$user['id'];
                $classId = (int)($user['class_id'] ?? 0);
                if ($uid > 0) { // tugas kelas + tugas yang dikirim guru ke room/server yang diikuti
                    $tasks = \VC\Domain\Learning::assignmentsForClass($classId, $uid);
                    $subs = \VC\Domain\Learning::getStudentSubmissions($uid);
                    $subByA = [];
                    foreach ($subs as $s) { $subByA[(int)$s['assignment_id']] = $s; }
                    $out = [];
                    foreach (array_slice($tasks, 0, 16) as $t) {
                        $sub = $subByA[(int)$t['id']] ?? null;
                        $out[] = [
                            'id' => (int)$t['id'],
                            'title' => (string)$t['title'],
                            'subject' => (string)($t['subject_name'] ?? 'Pelajaran'),
                            'deadline' => (string)($t['deadline'] ?? ''),
                            'status' => $sub ? ((int)($sub['graded_at'] ?? 0) > 0 ? 'graded' : 'submitted') : 'open',
                            'score' => $sub !== null ? ($sub['score'] !== null ? (int)$sub['score'] : null) : null
                        ];
                    }
                    $tasksJson = json_encode($out, JSON_UNESCAPED_UNICODE);
                }
            } catch (\Throwable $e) { $tasksJson = '[]'; }
        }

        $servers = [];
        try {
            // Dunia Pixel hanya menampilkan server Pixel (satu server = satu dunia).
            $servers = \VC\Domain\GameServers::listFor((int)($user['id'] ?? 0), (string)($user['role'] ?? 'guest'), 'pixel');
        } catch (\Throwable $e) { $servers = []; }
        if (empty($servers)) {
            $pixPublicId = 0; // 0 → token memakai server publik Dunia Pixel
            try {
                $pixPublicId = (int)(\VC\Database::get()->query('SELECT id FROM game_servers WHERE world = "pixel" AND type = "public" AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            } catch (\Throwable $e) { $pixPublicId = 0; }
            $servers = [[
                'id' => $pixPublicId,
                'world' => 'pixel',
                'name' => 'Silber Public Pixel',
                'description' => 'Server publik terbuka untuk seluruh siswa dan warga sekolah.',
                'type' => 'public',
                'class_name' => null,
                'capacity' => 150,
                'access' => 'open',
                'needs_code' => false,
                'is_owner' => false,
                'member_count' => 0,
                'online' => 0,
                'status' => 'idle'
            ]];
        }
        $serversJson = json_encode($servers, JSON_UNESCAPED_UNICODE);

        echo '<div id="pixLobi">';
        echo '<div class="card" style="max-width:680px;max-height:88vh;overflow-y:auto">';
        echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:8px">';
        echo '<div><h2 style="color:#f59e0b;margin:0 0 4px">🧱 Pixel World — SMK Silber</h2>';
        echo '<p style="font-size:.82rem;color:#cbd5e1;line-height:1.5">Dunia sekolah pixel-art side-view sesuai denah resmi (38 lokasi ruang.txt): Gedung A–D bertingkat, pintu & interior yang bisa dijelajahi, tangga antarlantai, peta navigasi, dan multiplayer realtime.</p></div>';
        echo '<span style="background:rgba(34,211,238,0.15);color:#22d3ee;padding:3px 8px;border-radius:6px;font-size:.72rem;border:1px solid rgba(34,211,238,0.3);white-space:nowrap">Kapasitas: s/d 150 Pemain</span>';
        echo '</div>';

        // KARTU GABUNG CEPAT KELAS (SISWA: Cukup Nama & Kode Kelas - Masukan Pak Agus)
        echo '<div style="background:linear-gradient(135deg,rgba(2,132,199,0.22),rgba(15,23,42,0.92));border:1px solid #38bdf8;border-radius:12px;padding:12px 14px;margin:10px 0">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px"><b style="color:#38bdf8;font-size:.88rem">🚀 Gabung Kelas Cepat</b><span style="font-size:.7rem;color:#94a3b8">Cukup isi nama & kode kelas guru</span></div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        echo '<input id="pixQuickName" value="' . $username . '" placeholder="Nama Siswa" style="flex:1;min-width:130px;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:7px 10px;font-size:.8rem">';
        echo '<input id="pixQuickCode" placeholder="Kode Kelas Guru (cth: A1B2C3D4)..." maxlength="16" style="flex:1;min-width:130px;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:7px 10px;font-size:.8rem;text-transform:uppercase">';
        echo '<button class="pbtn" onclick="quickJoinByCode()" style="background:#10b981;color:#000;font-weight:800;padding:7px 14px">▶ Gabung</button>';
        echo '</div></div>';

        // Server Browser Grid
        echo '<div style="background:rgba(7,11,20,.85);border:1px solid #1f293d;border-radius:12px;padding:12px;margin:12px 0">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">';
        echo '<b style="color:#22d3ee;font-size:.85rem">🌐 Pilih Server Multiplayer:</b>';
        echo '<div style="display:flex;gap:4px">';
        echo '<button class="pbtn is-active" id="srvTabAll" onclick="pixFilterServers(\'all\')" style="min-height:28px;padding:2px 8px;font-size:.7rem">Semua</button>';
        echo '<button class="pbtn" id="srvTabClass" onclick="pixFilterServers(\'class\')" style="min-height:28px;padding:2px 8px;font-size:.7rem">🏫 Kelas</button>';
        echo '<button class="pbtn" id="srvTabPub" onclick="pixFilterServers(\'public\')" style="min-height:28px;padding:2px 8px;font-size:.7rem">🌐 Publik</button>';
        echo '<button class="pbtn" id="srvTabPriv" onclick="pixFilterServers(\'private\')" style="min-height:28px;padding:2px 8px;font-size:.7rem">🔒 Privat</button>';
        echo '<button class="pbtn" id="srvTabEvent" onclick="pixFilterServers(\'event\')" style="min-height:28px;padding:2px 8px;font-size:.7rem">🏆 Event</button>';
        echo '</div></div>';
        echo '<div id="pixServerCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px;max-height:200px;overflow-y:auto;padding:2px"></div>';
        echo '<div id="pixCodeWrap" style="display:none;margin-top:10px;background:#090d16;padding:8px 12px;border-radius:8px;border:1px solid #f59e0b">';
        echo '<label style="font-size:.75rem;color:#f59e0b;display:block;margin-bottom:4px">🔑 Server ini membutuhkan Kode Undangan:</label>';
        echo '<input id="pixServerCodeInp" placeholder="Masukkan 8 karakter kode undangan..." maxlength="16" style="width:100%;background:#131a29;color:#fff;border:1px solid #334155;border-radius:6px;padding:6px 8px;font-size:.78rem;text-transform:uppercase">';
        echo '</div></div>';

        echo '<p style="font-size:.76rem;color:#94a3b8;margin-bottom:10px">Kontrol: A/D atau ◀ ▶ jalan · Shift lari · W/↑ masuk pintu & naik tangga · S/↓ keluar & turun · E aksi · M peta navigasi · 📱 tombol sentuh di layar</p>';
        echo '<div class="prow" style="margin-top:0">';
        echo '<a href="/dunia" class="pbtn" style="text-decoration:none">🚀 Mode 3D</a>';
        echo '<a href="/peta" class="pbtn" style="text-decoration:none">🗺️ Denah Interaktif</a>';
        echo '<button class="pbtn" type="button" onclick="pixGoOffline(false)" title="Main sendiri tanpa internet / server">📴 Main Offline</button>';
        echo '<button class="pbtn" id="pixEnterBtn" style="background:#f59e0b;color:#1c1204;font-weight:800;margin-left:auto" onclick="enterGameServer()">▶ Masuk ke Sekolah</button>';
        echo '</div></div></div>';

        // ?v= memaksa HP yang masih memakai service worker lama mengambil versi terbaru (cache-first per URL).
        $pv = '8.2.1';
        echo '<script src="/assets/js/voice.js?v=' . $pv . '"></script>';
        echo '<script src="/assets/js/pixel-audio.js?v=' . $pv . '"></script>';
        echo '<script src="/assets/js/pixel-net.js?v=' . $pv . '"></script>';
        // Integrasi Modul Game (Workstream B/C/E): dipasang SEBELUM engine pixel side-view.
        // CSS minigames diberi id "minigames-css" agar MiniGames.ensureCSS() tidak double-load.
        echo '<link rel="stylesheet" href="/assets/css/minigames.css" id="minigames-css">';
        echo '<script src="/assets/js/character-story.js"></script>';
        echo '<script src="/assets/js/missions-minigame.js"></script>';
        echo '<script src="/assets/js/live-chat.js"></script>';
        // Engine side-view (ruang.txt): karakter = pixel-avatar.js yang sama dengan dashboard.
        echo '<script src="/assets/js/pixel-avatar.js?v=' . $pv . '"></script>';
        echo '<script src="/assets/js/pixel-side.js?v=' . $pv . '"></script>';
        echo '<script src="/assets/js/pixel-side-art.js?v=' . $pv . '"></script>';
        echo '<script src="/assets/js/pixel-side-map.js?v=' . $pv . '"></script>';
        echo '<script src="/assets/js/world-nav.js?v=' . $pv . '"></script>'; // panggilan kumpul server + navigasi
        echo '<script src="/assets/js/anomaly.js?v=' . $pv . '"></script>'; // 4 jenis anomali malam hari
        echo '<script src="/assets/js/world-life.js?v=' . $pv . '"></script>'; // kucing, sampah, misi mabar
        echo '<script src="/assets/js/daily.js?v=' . $pv . '"></script>'; // misi harian (chip 📅)
        echo '<script src="/assets/js/vc-ui-toggle.js?v=' . $pv . '"></script>'; // 👁 sembunyikan semua tombol
        echo '<script src="/assets/js/pixel-pc.js?v=' . $pv . '"></script>'; // komputer lab bisa dipakai
        echo '<script src="/assets/js/inventory.js?v=' . $pv . '"></script>'; // inventaris: senter, buku, pensil, peta, botol, kartu
        $visitedJson = json_encode(array_values($visitedIds));
        $selfId = (int)($user['id'] ?? 0);
        $pixNameJson = json_encode($user !== null ? (string)($user['full_name'] ?: $user['username']) : 'Tamu', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        // Karakter kustom v2 (sama dengan dashboard & 3D).
        $pixAv = $user !== null ? \VC\Domain\AvatarStyle::forUser((int)$user['id'], (string)($user['role'] ?? 'murid')) : ['config' => \VC\Domain\AvatarStyle::defaults('murid'), 'bio' => ''];
        $pixAvatarJson = json_encode(['config' => $pixAv['config'], 'bio' => $pixAv['bio']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo <<<PIXJS
<script nonce="{$nonce}">
window.__visitedLocs = {$visitedJson};
window.__PIX_NAME = {$pixNameJson};
window.__AVATAR = {$pixAvatarJson};
window.__SELF_ID = {$selfId};
window.__PIX_TASKS = {$tasksJson};
window.__PIX_SERVERS = {$serversJson};
let pix = null;
let currentFilter = 'all';
let selectedServerId = parseInt(new URLSearchParams(location.search).get('server') || '', 10) || (window.__PIX_SERVERS[0] ? window.__PIX_SERVERS[0].id : 1);

function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(c){ return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c]; }); }
function pixEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(ch){ return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[ch]; }); }

function pixToast(msg, ok){
  var el = document.getElementById('pixToast');
  if (!el) { el = document.createElement('div'); el.id = 'pixToast'; el.style.cssText = 'position:absolute;bottom:120px;left:50%;transform:translateX(-50%);z-index:95;background:rgba(15,23,42,.97);border:1px solid ' + (ok ? '#10b981' : '#ef4444') + ';color:#f8fafc;border-radius:10px;padding:9px 14px;font-size:.78rem;max-width:80vw'; document.body.appendChild(el); }
  el.style.borderColor = ok ? '#10b981' : '#ef4444';
  el.textContent = msg;
  clearTimeout(window.__toastT);
  window.__toastT = setTimeout(function(){ el.remove(); }, 3200);
}

// Voice chat Dunia Pixel (sama dengan 3D & Mabar): tombol 🔇/🎤 Mic di topbar + tombol V.
var pixTalking = {};
function pixTalkRender(){
  var el = document.getElementById('pixTalk');
  if (!el) { el = document.createElement('div'); el.id = 'pixTalk'; el.style.cssText = 'position:absolute;top:58px;right:10px;z-index:60;display:flex;flex-direction:column;gap:4px;pointer-events:none;font-size:.74rem'; document.body.appendChild(el); }
  var h = '';
  Object.keys(pixTalking).forEach(function(id){ h += '<span style="background:rgba(6,78,59,.92);color:#bbf7d0;border:1px solid #22c55e;border-radius:8px;padding:3px 8px">🔊 ' + pixEsc(pixTalking[id]) + '</span>'; });
  el.innerHTML = h;
}
if (window.VoiceChat) {
  VoiceChat.init(function(obj){ if (window.PixelNet) PixelNet.sendNow(obj); return true; });
  VoiceChat.onSpeaking = function(id, on){
    if (id === window.__SELF_ID) { var b = document.getElementById('pixMicBtn'); if (b) b.style.boxShadow = on ? '0 0 0 3px rgba(74,222,128,.55)' : ''; return; }
    var r = (pix && pix.remotes) ? pix.remotes.get(id) : null;
    if (on) pixTalking[id] = (r && r.name) || 'Pemain'; else delete pixTalking[id];
    pixTalkRender();
  };
  VoiceChat.onError = function(m){ pixToast('🎙️ ' + m, false); };
  var pixUnlock = function(){ VoiceChat.unlockAudio(); };
  document.addEventListener('pointerdown', pixUnlock, { passive: true });
  document.addEventListener('keydown', function(e){
    if ((e.key === 'v' || e.key === 'V') && !/INPUT|TEXTAREA|SELECT/.test((e.target && e.target.tagName) || '')) pixToggleMic();
  });
}
function pixToggleMic(){
  if (!window.VoiceChat) { pixToast('Voice chat tidak tersedia di perangkat ini.', false); return; }
  VoiceChat.unlockAudio();
  VoiceChat.setMic(!VoiceChat.micOn).then(function(on){
    var b = document.getElementById('pixMicBtn');
    if (b) { b.textContent = on ? '🎤 Mic ON' : '🔇 Mic'; b.style.background = on ? '#16a34a' : ''; }
    if (on) pixToast('🎤 Mic menyala — semua pemain di server ini bisa mendengarmu.', true);
  });
}

function closeLobi(){
  var el = document.getElementById("pixLobi");
  if (el) el.style.display = "none";
  if (pix) pix.resizeCanvas();
}

function openServerSwitcher(){
  var el = document.getElementById("pixLobi");
  if (el) {
    el.style.display = "flex";
    renderServers(currentFilter);
  }
}

function togglePlayersModal(){
  var p = document.getElementById("pixPlayers");
  if (!p) return;
  p.style.display = (p.style.display === "none" || p.style.display === "") ? "block" : "none";
}

function toggleAudio(){
  if (window.PixelAudio) {
    var muted = PixelAudio.toggleMute();
    var btn = document.getElementById("pixAudioBtn");
    if (btn) btn.textContent = muted ? "🔇 Bisu" : "🔊 Audio";
    pixToast(muted ? "Audio dinonaktifkan." : "Audio aktif.", true);
  }
}

function pixFilterServers(f){
  currentFilter = f;
  ['srvTabAll','srvTabClass','srvTabPub','srvTabPriv','srvTabEvent'].forEach(function(id){
    var b = document.getElementById(id);
    if (b) b.classList.remove('is-active');
  });
  var activeBtn = { all: 'srvTabAll', class: 'srvTabClass', public: 'srvTabPub', private: 'srvTabPriv', event: 'srvTabEvent' }[f];
  if (activeBtn && document.getElementById(activeBtn)) document.getElementById(activeBtn).classList.add('is-active');
  renderServers(f);
}

function renderServers(filter){
  var container = document.getElementById("pixServerCards");
  if (!container) return;
  container.innerHTML = "";
  var list = (window.__PIX_SERVERS || []).filter(function(s){
    if (!filter || filter === 'all') return true;
    return s.type === filter;
  });
  if (!list.length) {
    container.innerHTML = '<div style="grid-column:1/-1;padding:16px;text-align:center;color:#64748b;font-size:.78rem">Tidak ada server pada kategori ini.</div>';
    return;
  }
  list.forEach(function(s){
    var isSel = (s.id === selectedServerId);
    var div = document.createElement("div");
    div.style.cssText = 'background:' + (isSel ? 'rgba(2,132,199,.25)' : 'rgba(15,23,42,.75)') + ';border:1px solid ' + (isSel ? '#22d3ee' : '#1e293b') + ';border-radius:8px;padding:8px 10px;cursor:pointer;transition:border-color .15s';
    var typeBadge = { public: '🌐 Publik', class: '🏫 Kelas', private: '🔒 Privat', event: '🏆 Event' }[s.type] || s.type;
    var liveText = (s.online > 0) ? '<span style="color:#10b981">● ' + s.online + ' aktif</span>' : '<span style="color:#64748b">○ Siap</span>';
    var lockIcon = s.needs_code ? ' 🔑' : '';
    div.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">'
      + '<b style="color:' + (isSel ? '#38bdf8' : '#fff') + ';font-size:.8rem">' + esc(s.name) + lockIcon + '</b>'
      + '<span style="font-size:.65rem;background:#0f172a;padding:2px 5px;border-radius:4px;color:#94a3b8">' + typeBadge + '</span></div>'
      + '<div style="font-size:.72rem;color:#cbd5e1">' + esc(s.class_name ? ('Rombel: ' + s.class_name) : (s.description || 'Server sekolah')) + '</div>'
      + '<div style="display:flex;justify-content:space-between;margin-top:6px;font-size:.68rem;color:#94a3b8">'
      + '<span>👥 ' + (s.online || 0) + ' / ' + (s.capacity || 150) + '</span>'
      + '<span>' + liveText + '</span></div>';
    div.onclick = function(){ selectServer(s.id); };
    container.appendChild(div);
  });
  updateCodeWrap();
}

function selectServer(id){
  selectedServerId = id;
  renderServers(currentFilter);
  var cur = (window.__PIX_SERVERS || []).find(function(s){ return s.id === id; });
  if (cur) {
    var nameEl = document.getElementById("pixCurrentServerName");
    if (nameEl) nameEl.textContent = cur.name;
  }
}

function updateCodeWrap(){
  var cur = (window.__PIX_SERVERS || []).find(function(s){ return s.id === selectedServerId; });
  var wrap = document.getElementById("pixCodeWrap");
  if (wrap) {
    wrap.style.display = (cur && cur.needs_code) ? "block" : "none";
  }
}

async function enterGameServer(){
  var cur = (window.__PIX_SERVERS || []).find(function(s){ return s.id === selectedServerId; });
  var code = "";
  if (cur && cur.needs_code) {
    var inp = document.getElementById("pixServerCodeInp");
    code = (inp && inp.value || "").trim();
    if (!code) {
      pixToast("Masukkan kode undangan server.", false);
      return;
    }
  }
  closeLobi();
  // Integrasi Modul Game: pilih karakter + HUD story + LiveChat saat masuk dunia pixel.
  if (window.__vcPixelGameStart) { try { window.__vcPixelGameStart(); } catch (e) {} }
  if (cur) {
    var nameEl = document.getElementById("pixCurrentServerName");
    if (nameEl) nameEl.textContent = cur.name;
  }
  if (window.PixelAudio) PixelAudio.init();
  if (window.PixelNet) {
    PixelNet.switchServer(selectedServerId, code);
  }
}

async function postApi(url, body){
  var m=document.querySelector("meta[name=csrf]"); var c=m?m.content:"";
  // Mode offline: kunjungan lokasi disimpan & dikirim saat online lagi (misi tetap tercatat).
  if (window.__pixOffline || !navigator.onLine) {
    if (url === "/api/world/visit") { pixQueue(body); return { status: "ok", offline: true }; }
    return { status: "error", message: "Sedang offline." };
  }
  try {
    var r=await fetch(url,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF":c},body:JSON.stringify(Object.assign({},body,{_csrf:c}))});
    try { return await r.json(); } catch(e){ return {status:"error",message:"Respons tidak valid"}; }
  } catch (e) {
    if (url === "/api/world/visit") { pixQueue(body); return { status: "ok", offline: true }; }
    return { status: "error", message: "Koneksi terputus." };
  }
}
function pixQueue(body){
  var q = []; try { q = JSON.parse(localStorage.getItem("vc_pix_queue") || "[]"); } catch (e) { q = []; }
  if (!q.some(function(x){ return x.location_id === body.location_id; })) q.push({ location_id: body.location_id });
  try { localStorage.setItem("vc_pix_queue", JSON.stringify(q.slice(-60))); } catch (e) {}
}
function pixFlushQueue(){
  if (!navigator.onLine) return;
  var q = []; try { q = JSON.parse(localStorage.getItem("vc_pix_queue") || "[]"); } catch (e) { q = []; }
  if (!q.length) return;
  try { localStorage.removeItem("vc_pix_queue"); } catch (e) {}
  var was = window.__pixOffline; window.__pixOffline = false;
  q.forEach(function(b){ postApi("/api/world/visit", b); });
  window.__pixOffline = was;
  pixToast("📶 Online lagi — " + q.length + " kunjungan offline tersinkron.", true);
}
/** Mode offline: main sendiri tanpa server (otomatis bila internet putus). Progres kunjungan disimpan. */
function pixGoOffline(auto){
  window.__pixOffline = true;
  if (window.PixelNet) { PixelNet.closedByUser = true; try { if (PixelNet.ws) PixelNet.ws.close(); } catch (e) {} PixelNet.setStatus('offline'); }
  var pill = document.getElementById("pixMpPill"); if (pill) { pill.textContent = "📴 Mode Offline"; pill.style.color = "#fbbf24"; }
  closeLobi();
  if (window.PixelAudio) PixelAudio.init();
  pixToast(auto ? "📴 Internet terputus — lanjut main offline. Kunjungan disimpan & dikirim saat online." : "📴 Mode offline: main sendiri, tanpa server. Kunjungan tetap tersimpan.", true);
}
window.addEventListener("online", function(){
  pixFlushQueue();
  if (window.__pixOffline) pixToast("📶 Internet kembali. Muat ulang / Ganti Server untuk main bareng lagi.", true);
});
window.addEventListener("offline", function(){ if (!window.__pixOffline) pixGoOffline(true); });

function openLocPanel(loc){
  var d=document.getElementById("pixPanel"); if(!loc){ d.style.display="none"; return; }
  var internalId=(loc.campusRef||"").split(":")[1]||loc.id; var visited=window.__visitedLocs.indexOf(internalId)>=0;
  var btnVisit = visited ? '' : '<button class="pbtn" style="background:#f59e0b;color:#1c1204" data-visit="' + internalId + '">📍 Kunjungi</button>';
  d.innerHTML = '<h3>' + esc(loc.name) + '</h3><p>' + esc(loc.description||"") + '</p>'
    + '<div style="font-size:.75rem;color:#94a3b8;margin-top:6px">' + esc(loc.category||"") + ' • Lantai ' + (loc.floor||1)
    + ' • Misi: ' + ((loc.missionIds||[]).length ? esc(loc.missionIds.join(", ")) : '-') 
    + ' • ' + (visited ? '✅ kunjungan tercatat' : 'belum dikunjungi') + '</div>'
    + '<div class="prow">' + btnVisit
    + ((loc.missionIds||[]).length ? '<a href="/misi" class="pbtn" style="text-decoration:none">🎯 Buka Daftar Misi</a>' : '')
    + '<button class="pbtn" data-close="1">✖ Tutup</button></div>';
  var vb = d.querySelector('[data-visit]');
  if (vb) vb.addEventListener('click', function(){ visitLoc(vb.getAttribute('data-visit')); });
  var cb = d.querySelector('[data-close]');
  if (cb) cb.addEventListener('click', closePanel);
  d.style.display="block";
}

function openNpcDialog(n){
  var say=document.getElementById("pixSay");
  say.innerHTML='<b style="color:#f59e0b">' + esc(n.name) + ':</b> ' + esc(n.lines.join(" "));
  say.style.display="block";
  clearTimeout(window.__sayT); window.__sayT=setTimeout(function(){ say.style.display="none"; }, 5000);
}

function openFurnitureDialog(item, building){
  var d = document.getElementById("pixPanel");
  if (!d) return;
  var type = item.type || 'furn';
  var bName = building ? building.name : 'Sekolah';
  var content = '';
  // Komputer bisa dipakai sungguhan (SilberOS: terminal, kuis, subnet, notepad, paint, ular).
  if ((type === 'pcdesk' || type === 'workstation') && window.vcPC) { vcPC.open({ lab: bName, engine: pix }); return; }

  if (type === 'whiteboard') {
    content = '<h3>📋 Papan Tulis Kelas — ' + esc(bName) + '</h3>'
      + '<p>Papan instruksi materi dan tugas aktif yang disiapkan guru mata pelajaran.</p>'
      + '<div class="prow"><button class="pbtn" style="background:#0284c7;color:#fff" onclick="pixTab(\'tugas\');pixSideToggle(true)">📝 Buka Daftar Tugas Rombel</button><button class="pbtn" onclick="closePanel()">Tutup</button></div>';
  } else if (type === 'pcdesk') {
    content = '<h3>💻 Workstation Komputer & Jaringan — ' + esc(bName) + '</h3>'
      + '<p>Simulasi Kuis Teknologi: <b>Protokol apa yang digunakan untuk web terenkripsi SSL/TLS?</b></p>'
      + '<div class="prow">'
      + '<button class="pbtn" onclick="quizAnswer(false)">A. HTTP Port 80</button>'
      + '<button class="pbtn" style="border-color:#10b981" onclick="quizAnswer(true)">B. HTTPS Port 443</button>'
      + '<button class="pbtn" onclick="quizAnswer(false)">C. Telnet Port 23</button>'
      + '<button class="pbtn" onclick="closePanel()">Tutup</button></div>';
  } else if (type === 'workbench') {
    content = '<h3>🛠️ Meja Perakitan & Troubleshooting PC — Lab Rakitan</h3>'
      + '<p>Daftar perakitan hardware: 1. Pasang CPU & Pasta · 2. Pasang RAM Dual-Channel · 3. Hubungkan Kabel Power PSU 24-Pin · 4. Pasang GPU PCIe x16.</p>'
      + '<div class="prow">'
      + '<button class="pbtn" style="background:#10b981;color:#000" onclick="testPcBeep()">⚡ Tes Power & POST Beep</button>'
      + '<button class="pbtn" onclick="closePanel()">Tutup</button></div>';
  } else if (type === 'catalog') {
    content = '<h3>📚 Katalog Digital Perpustakaan SMK Silber</h3>'
      + '<p>Pencarian koleksi literasi: Temukan modul Pemrograman Web, Jaringan Komputer Cisco, Animasi 3D Blender, dan Akuntansi Perbankan.</p>'
      + '<div class="prow"><a href="/misi" class="pbtn" style="background:#f59e0b;color:#1c1204;text-decoration:none">🎯 Buka Misi Literasi</a><button class="pbtn" onclick="closePanel()">Tutup</button></div>';
  } else if (type === 'counter') {
    content = '<h3>🏦 Loket Perbankan Siswa & Minimarket Sekolah</h3>'
      + '<p>Layanan transaksi keuangan edukatif. Tabungan siswa dan pembayaran praktikum kejuruan.</p>'
      + '<div class="prow"><button class="pbtn" onclick="pixToast(\'Saldo koin Anda aman tercatat di ledger sistem.\', true)">🪙 Cek Saldo Buku Tabungan</button><button class="pbtn" onclick="closePanel()">Tutup</button></div>';
  } else if (type === 'consultdesk') {
    content = '<h3>🧑‍🏫 Meja Konseling BKK & Bimbingan Karier</h3>'
      + '<p>Pusat informasi penempatan Praktik Kerja Lapangan (PKL), sertifikasi kompetensi keahlian BNSP, serta bimbingan studi lanjut.</p>'
      + '<div class="prow"><button class="pbtn" onclick="pixToast(\'Jadwal konsultasi karier dibuka setiap hari kerja.\', true)">ℹ️ Info Magang Industri</button><button class="pbtn" onclick="closePanel()">Tutup</button></div>';
  } else if (type === 'meetingdesk') {
    content = '<h3>🤝 Meja Musyawarah Pimpinan Ranting IPM</h3>'
      + '<p>Ruang rapat pengurus Ikatan Pelajar Muhammadiyah SMK Silber. Agenda kegiatan dakwah komunitas dan ekstrakurikuler.</p>'
      + '<div class="prow"><button class="pbtn" onclick="closePanel()">Tutup</button></div>';
  } else if (type === 'workstation') {
    content = '<h3>💼 Workstation Tim Silber Industries</h3>'
      + '<p>Teaching Factory rekayasa perangkat lunak dan layanan digital berbasis pesanan industri.</p>'
      + '<div class="prow"><button class="pbtn" onclick="pixToast(\'Pipeline build aplikasi: 100% lulus QA.\', true)">🚀 Cek Pipeline CI/CD Proyek</button><button class="pbtn" onclick="closePanel()">Tutup</button></div>';
  } else {
    content = '<h3>' + esc(item.label || 'Perabotan Ruangan') + ' — ' + esc(bName) + '</h3>'
      + '<p>Fasilitas pendukung pembelajaran di ruang sekolah SMK Silber.</p>'
      + '<div class="prow"><button class="pbtn" onclick="closePanel()">Tutup</button></div>';
  }

  d.innerHTML = content;
  d.style.display = "block";
}

function quizAnswer(correct){
  if (correct) {
    if (window.PixelAudio) PixelAudio.sfx('join');
    pixToast('✅ Jawaban BENAR! HTTPS menggunakan port 443 dengan enkripsi TLS.', true);
  } else {
    pixToast('❌ Belum tepat. Coba periksa kembali protokol enkripsi web.', false);
  }
}

function testPcBeep(){
  if (window.PixelAudio) PixelAudio.sfx('click');
  pixToast('🔊 *BEEP!* POST lulus 100%. Motherboard & CPU normal.', true);
}

document.addEventListener("DOMContentLoaded", function(){
  pix = new PixelWorld("pixCanvas", {
    settings: { reducedMotion: (localStorage.getItem("vc_reduced_motion") === "1") },
    onNearbyChange: function(loc, npc){
      document.getElementById("pixWhere").textContent = loc ? loc.name : "Jalan sekolah";
      if (pix && pix.chatAreaId) window.__pixAreaId = pix.chatAreaId(); // ID lokasi resmi untuk chat area
    },
    onInteract: function(hit){
      if(hit.type === "npc" && hit.npc){
        openNpcDialog(hit.npc);
      } else if(hit.type === "furniture" && hit.item){
        openFurnitureDialog(hit.item, hit.building);
      } else if(hit.loc){
        openLocPanel(hit.loc);
      }
    },
    onPauseToggle: function(){ openPause(); },
    // Masuk ruangan = kunjungan lokasi resmi (sekali per lokasi; dasar misi berbasis lokasi).
    onEnterLocation: function(loc){
      var internalId = (loc.campusRef || "").split(":")[1] || "";
      if (!internalId || window.__visitedLocs.indexOf(internalId) >= 0) return;
      window.__visitedLocs.push(internalId);
      postApi("/api/world/visit", { location_id: internalId }).then(function(d){
        if (d && d.status === "ok") pixToast("📍 Kunjungan tercatat: " + loc.name, true);
      }).catch(function(){});
    }
  });
  var pixReady = pix.init();
  if (pixReady && pixReady.catch) pixReady.catch(function(e){ console.error(e); pixToast("Dunia pixel gagal dimuat. Muat ulang halaman.", false); });
  // Dibuka tanpa internet (dari cache) atau lewat tombol "Main Dunia Pixel (Offline)" → langsung mode offline.
  if (/[?&]offline=1/.test(location.search) || !navigator.onLine) setTimeout(function(){ pixGoOffline(!navigator.onLine); }, 300);
  else setTimeout(pixFlushQueue, 2500);

  // Setup PixelNet listeners
  if (window.PixelNet) {
    PixelNet.engine = pix;
    PixelNet.on('status', function(st){
      var pill = document.getElementById("pixMpPill");
      if (!pill) return;
      var map = {
        online: { text: '● MP: terhubung', color: '#10b981' },
        connecting: { text: '● MP: menyambung…', color: '#f59e0b' },
        error: { text: '● MP: offline', color: '#ef4444' },
        kicked: { text: '● MP: terputus', color: '#ef4444' }
      };
      var c = map[st] || { text: '● MP: ' + st, color: '#94a3b8' };
      pill.textContent = c.text;
      pill.style.color = c.color;
    });

    PixelNet.on('presence', function(){
      var count = (pix ? pix.remotes.size : 0) + 1;
      var el = document.getElementById("pixOnlineCount");
      if (el) el.textContent = count;
      var list = document.getElementById("pixPlayersList");
      if (list && pix) {
        var html = '<div style="color:#38bdf8">👤 ' + esc(pix.player.name) + ' (Anda) — ' + esc(pix.nearLoc ? pix.nearLoc.name : 'Jalan') + '</div>';
        for (var pair of pix.remotes.entries()) {
          var r = pair[1];
          html += '<div>👤 ' + esc(r.name || 'Pemain') + ' <span style="color:#94a3b8;font-size:.7rem">(' + esc(r.role || 'murid') + ')</span> — ' + esc(r.locationName || 'Kampus') + '</div>';
        }
        list.innerHTML = html;
      }
    });

    PixelNet.on('chat', function(m){
      if ((m.scope || 'global') !== pixChatScopeCur) return;
      pixAddChat({ id: m.msgId, user_id: m.id, username: m.fullName || m.username, text: m.text });
      if (m.id !== window.__SELF_ID) {
        var side = document.getElementById("pixSide");
        if (!side || side.style.display !== "block") pixToast("💬 " + (m.fullName || m.username || "Pemain") + ": " + String(m.text || "").slice(0, 60), true);
      }
    });

    PixelNet.on('join_error', function(err){
      pixToast(err || "Gagal masuk server.", false);
    });
  }

  // Emote bar buttons
  document.querySelectorAll('[data-emote]').forEach(function(b){
    b.addEventListener('click', function(){
      if (pix) pix.triggerEmote(b.getAttribute('data-emote'));
    });
  });

  // Init audio on first touch
  document.addEventListener('pointerdown', function(){
    if (window.PixelAudio) PixelAudio.init();
  }, { once: true });

  // Render initial servers in lobby
  renderServers('all');

  var sideBtn = document.getElementById("pixSideBtn");
  if (sideBtn) {
    if (window.matchMedia && (window.matchMedia("(pointer:coarse)").matches || window.matchMedia("(max-width:900px)").matches)) {
      sideBtn.style.display = "block";
    }
    sideBtn.addEventListener("click", function(){ pixSideToggle(); });
  }
});

function visitLoc(internalId){
  postApi("/api/world/visit", { location_id: internalId }).then(function(d){
    if(d.status === "ok"){
      if(window.__visitedLocs.indexOf(internalId) < 0) window.__visitedLocs.push(internalId);
      var loc = pix.nearLoc; if(loc){ openLocPanel(loc); }
    } else { pixToast(d.message || "Gagal mencatat kunjungan.", false); }
  });
}

function openPause(){
  if(!pix) return;
  var d=document.getElementById("pixPanel");
  d.innerHTML = '<h3>⚙️ Menu Permainan</h3><div class="prow">'
    + '<button class="pbtn" onclick="openServerSwitcher()" style="background:#0284c7;color:#fff">🌐 Ganti Server</button>'
    + '<a href="/" class="pbtn" style="text-decoration:none">🏠 Beranda</a>'
    + '<a href="/dunia" class="pbtn" style="text-decoration:none">🚀 Dunia 3D</a>'
    + '<a href="/peta" class="pbtn" style="text-decoration:none">🗺️ Denah Interaktif</a>'
    + '<button class="pbtn" id="pixRmBtn">🦯 Gerak minimal: ' + (pix.settings.reducedMotion ? "ON" : "OFF") + '</button>'
    + '<button class="pbtn" id="pixResumeBtn" style="background:#f59e0b;color:#1c1204">▶ Lanjut Main</button></div>';
  document.getElementById('pixRmBtn').addEventListener('click', toggleReducedMotion);
  document.getElementById('pixResumeBtn').addEventListener('click', closePanel);
  d.style.display="block"; pix.paused=true;
}

function closePanel(){ document.getElementById("pixPanel").style.display="none"; if(pix) pix.paused=false; }

// ---------- Panel Samping Chat, Misi, dan Tugas Kelas ----------
let pixChatScopeCur = 'global';
let pixChatSince = 0;

function pixSideToggle(forceOpen){
  var el = document.getElementById("pixSide");
  if (!el) return;
  var open = (forceOpen === true) ? true : (el.style.display !== "block");
  el.style.display = open ? "block" : "none";
  if (open) { pixLoadMissions(); pixPollChat(); }
}

function pixTab(which){
  document.getElementById("pixChatSec").style.display = which === "chat" ? "block" : "none";
  document.getElementById("pixMisiSec").style.display = which === "misi" ? "block" : "none";
  document.getElementById("pixTugasSec").style.display = which === "tugas" ? "block" : "none";
  ['pixTabChat','pixTabMisi','pixTabTugas'].forEach(function(i){
    var b = document.getElementById(i); if (b) b.classList.remove('is-active');
  });
  var curBtn = { chat: 'pixTabChat', misi: 'pixTabMisi', tugas: 'pixTabTugas' }[which];
  if (curBtn && document.getElementById(curBtn)) document.getElementById(curBtn).classList.add('is-active');
  if (which === "misi") pixLoadMissions();
  if (which === "tugas") pixLoadTasks();
}

function pixLoadTasks(){
  var sec = document.getElementById("pixTugasSec");
  if (!sec) return;
  var tasks = window.__PIX_TASKS || [];
  if (!tasks.length) {
    sec.innerHTML = '<div style="color:#94a3b8;padding:8px">Belum ada tugas aktif untuk kelas Anda saat ini.</div>';
    return;
  }
  var html = '<b style="color:#f59e0b;display:block;margin-bottom:8px">📝 Tugas Rombel Kelas Aktif:</b>';
  tasks.forEach(function(t){
    var stBadge = '<span style="color:#f59e0b">⏳ Belum Dikumpulkan</span>';
    if (t.status === 'submitted') stBadge = '<span style="color:#38bdf8">📤 Sudah Dikumpulkan</span>';
    if (t.status === 'graded') stBadge = '<span style="color:#10b981">✅ Dinilai: ' + (t.score !== null ? t.score : '-') + '/100</span>';

    var btnSubmit = (t.status === 'open') ? '<button class="pbtn" style="margin-top:4px;padding:3px 8px;font-size:.7rem;background:#10b981;color:#000" onclick="openTaskSubmitModal(' + t.id + ',\'' + esc(t.title) + '\')">📤 Kumpulkan Tugas</button>' : '';

    html += '<div style="background:rgba(15,23,42,.7);border:1px solid #1e293b;border-radius:8px;padding:8px;margin-bottom:8px">'
      + '<div style="font-weight:700;color:#fff">' + esc(t.title) + '</div>'
      + '<div style="font-size:.72rem;color:#94a3b8">Mapel: ' + esc(t.subject) + (t.deadline ? ' • Batas: ' + esc(t.deadline) : '') + '</div>'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;font-size:.72rem">'
      + stBadge + btnSubmit + '</div></div>';
  });
  sec.innerHTML = html;
}

function openTaskSubmitModal(taskId, taskTitle){
  var d = document.getElementById("pixPanel");
  if (!d) return;
  d.innerHTML = '<h3>📤 Pengumpulan Tugas: ' + esc(taskTitle) + '</h3>'
    + '<p style="margin-bottom:8px">Tulis jawaban, rangkuman materi, atau tautan berkas tugas Anda:</p>'
    + '<textarea id="pixTaskAnswerInput" style="width:100%;height:80px;background:#090d16;color:#fff;border:1px solid #334155;border-radius:8px;padding:8px;font-size:.78rem;box-sizing:border-box" placeholder="Ketik teks jawaban atau tautan repository/Google Drive..."></textarea>'
    + '<div class="prow" style="margin-top:8px">'
    + '<button class="pbtn" style="background:#10b981;color:#000;font-weight:700" onclick="submitTaskAnswer(' + taskId + ')">Kirim ke Guru</button>'
    + '<button class="pbtn" onclick="closePanel()">Batal</button></div>';
  d.style.display = "block";
}

async function submitTaskAnswer(taskId){
  var inp = document.getElementById("pixTaskAnswerInput");
  var text = (inp && inp.value || "").trim();
  if (!text) {
    pixToast("Silakan tulis jawaban tugas Anda.", false);
    return;
  }
  var res = await postApi("/api/task/submit", { assignment_id: taskId, content_text: text });
  if (res.status === "ok") {
    pixToast("✅ Tugas berhasil dikumpulkan ke guru!", true);
    if (window.PixelAudio) PixelAudio.sfx('join');
    (window.__PIX_TASKS || []).forEach(function(t){
      if (t.id === taskId) t.status = 'submitted';
    });
    pixLoadTasks();
    closePanel();
  } else {
    pixToast(res.message || "Gagal mengumpulkan tugas.", false);
  }
}

function pixChatScope(c){
  pixChatScopeCur = c;
  document.getElementById("pixChip-global").classList.toggle("is-active", c === "global");
  document.getElementById("pixChip-area").classList.toggle("is-active", c === "area");
  document.getElementById("pixChatBox").innerHTML = "";
  pixChatSince = 0;
  pixChatSeen = {};
  pixPollChat();
}

function pixChatArea(){ return (pix && pix.chatAreaId) ? pix.chatAreaId() : (window.__pixAreaId || "TAMAN_TENGAH"); }
function pixChatServer(){ return (window.PixelNet && PixelNet.currentServerId) || selectedServerId || 0; }
function pixChatUrl(){
  var url = "/api/game/chat?scope=" + pixChatScopeCur;
  if (pixChatScopeCur === "global" && pixChatServer() > 0) url += "&server=" + pixChatServer(); // riwayat per room/server
  if (pixChatScopeCur === "area") url += "&location_id=" + encodeURIComponent(pixChatArea());
  if (pixChatSince > 0) url += "&since_id=" + pixChatSince;
  return url;
}

// Satu pintu menampilkan pesan (WS & polling) — pesan yang sama tidak tampil dua kali.
var pixChatSeen = {};
function pixAddChat(m){
  var box = document.getElementById("pixChatBox");
  if (!box || !m || !m.text) return;
  if (m.id) { if (pixChatSeen[m.id]) return; pixChatSeen[m.id] = 1; pixChatSince = Math.max(pixChatSince, m.id); }
  var mine = m.user_id === (window.__SELF_ID || 0);
  var row = document.createElement("div");
  row.className = "pix-chat-row" + (mine ? " is-mine" : "");
  row.innerHTML = "<b>" + pixEsc(m.username || "Pemain") + ":</b> " + pixEsc(m.text);
  box.appendChild(row);
  box.scrollTop = box.scrollHeight;
}

async function pixPollChat(){
  var el = document.getElementById("pixSide");
  if (!el || el.style.display !== "block") return;
  try {
    var r = await fetch(pixChatUrl());
    if (!r.ok) return;
    var d = await r.json();
    if (d.status !== "ok" || !Array.isArray(d.messages)) return;
    d.messages.forEach(function(m){ pixAddChat({ id: m.id, user_id: m.user_id, username: m.full_name || m.username, text: m.text }); });
  } catch(e) {}
}
setInterval(pixPollChat, 3000);

async function pixChatSend(){
  var inp = document.getElementById("pixChatInput");
  var text = (inp && inp.value || "").trim();
  if (!text) return;
  inp.value = "";
  // Online: WebSocket menyimpan + menyiarkan (termasuk ke pengirim). Offline: simpan lewat API lalu muat ulang.
  if (window.PixelNet && PixelNet.status === "online") {
    PixelNet.sendChat(pixChatScopeCur, text);
    return;
  }
  var body = { scope: pixChatScopeCur, text: text };
  if (pixChatScopeCur === "area") body.location_id = pixChatArea();
  if (pixChatScopeCur === "global" && pixChatServer() > 0) body.server = pixChatServer();
  var d = await postApi("/api/game/chat/send", body);
  if (d.status === "error") { pixToast(d.message || "Pesan tidak terkirim.", false); return; }
  pixPollChat();
}

function pixOpenChat(){
  pixSideToggle(true);
  pixTab("chat");
  var inp = document.getElementById("pixChatInput");
  if (inp) setTimeout(function(){ inp.focus(); }, 50);
}
document.addEventListener("DOMContentLoaded", function(){
  var inp = document.getElementById("pixChatInput");
  if (inp) inp.addEventListener("keydown", function(e){ if (e.key === "Enter") { e.preventDefault(); pixChatSend(); } else if (e.key === "Escape") { inp.blur(); } });
  document.addEventListener("keydown", function(e){
    var t = e.target;
    if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.tagName === "SELECT")) return;
    if ((e.key === "c" || e.key === "C" || e.key === "t" || e.key === "T") && document.getElementById("pixLobi").style.display === "none") { e.preventDefault(); pixOpenChat(); }
  });
});

async function pixLoadMissions(){
  var sec = document.getElementById("pixMisiSec");
  if (!sec) return;
  sec.innerHTML = "Memuat misi…";
  try {
    var r = await fetch("/api/missions");
    var d = await r.json();
    if (d.status !== "ok" || !Array.isArray(d.missions)) { sec.textContent = "Gagal memuat misi."; return; }
    if (!d.missions.length) { sec.textContent = "Belum ada misi aktif."; return; }
    sec.innerHTML = d.missions.map(function(m){
      var done = (m.user_status === "completed");
      return "<div style=\"margin-bottom:6px\">" + (done ? "✅" : "🆕") + " <b>" + pixEsc(m.title) + "</b> — " + m.xp_reward + " XP • " + m.coin_reward + " koin" + (m.target_type === "location" ? "<br><span style=\"color:#22d3ee;font-size:.7rem\">📍 " + pixEsc(m.target_value) + "</span>" : "") + "</div>";
    }).join("");
  } catch(e) { sec.textContent = "Gagal memuat misi (jaringan)."; }
}

function toggleReducedMotion(){
  pix.settings.reducedMotion = !pix.settings.reducedMotion;
  localStorage.setItem("vc_reduced_motion", pix.settings.reducedMotion ? "1" : "0");
  openPause();
}

async function quickJoinByCode(){
  var nameInp = document.getElementById("pixQuickName");
  var codeInp = document.getElementById("pixQuickCode");
  var rawName = (nameInp ? nameInp.value : "").trim();
  var rawCode = (codeInp ? codeInp.value : "").trim().toUpperCase();

  if (!rawCode) {
    pixToast("Masukkan kode kelas dari guru (contoh: A1B2C3D4).", false);
    if (codeInp) codeInp.focus();
    return;
  }

  if (rawName && pix && pix.player) {
    pix.player.name = rawName;
  }

  var targetServer = (window.__PIX_SERVERS || []).find(function(s){
    return (s.invite_code && s.invite_code.toUpperCase() === rawCode) ||
           (s.code && s.code.toUpperCase() === rawCode);
  });
  var targetId = targetServer ? targetServer.id : 0;

  pixToast("Menghubungkan ke kelas dengan kode: " + rawCode + " ⏳", true);

  try {
    var res = await postApi("/api/servers/join", { server_id: targetId, code: rawCode });
    if (res.status === "ok" && res.world && res.world !== "pixel" && res.redirect) {
      pixToast("🚀 Kode ini untuk server Dunia 3D. Mengalihkan…", true);
      setTimeout(function(){ location.href = res.redirect; }, 1200);
      return;
    }
    if (res.status === "ok") {
      selectedServerId = res.server_id || targetId || 1;
      var sName = res.name || (targetServer ? targetServer.name : ("Kelas " + rawCode));
      var sNameEl = document.getElementById("pixCurrentServerName");
      if (sNameEl) sNameEl.textContent = sName;
      closeLobi();
      if (window.PixelAudio) PixelAudio.init();
      if (window.PixelNet) {
        PixelNet.switchServer(selectedServerId, rawCode);
      }
      pixToast("🎉 Berhasil bergabung ke: " + sName, true);
    } else {
      pixToast("⚠️ " + (res.message || "Kode kelas tidak valid atau server penuh."), false);
    }
  } catch(e) {
    if (window.PixelNet) {
      PixelNet.switchServer(targetId, rawCode);
      closeLobi();
    }
  }
}

function openTravelingModal(){
  var d = document.getElementById("pixPanel");
  if (!d) return;
  d.innerHTML = '<h3>🚌 Halte Traveling & Pindah Kota SMK Silber</h3>'
    + '<p style="font-size:.78rem;color:#cbd5e1;margin-bottom:8px">Layanan transportasi virtual & studi industri antardaerah sesuai denah sekolah dan peta nyata.</p>'
    + '<div style="margin-bottom:10px">'
    + '<b style="color:#22d3ee;font-size:.78rem;display:block;margin-bottom:6px">📍 Lokasi Cepat di Sekolah (Denah SMK Silber):</b>'
    + '<div class="prow" style="margin-top:0">'
    + '<button class="pbtn" onclick="travelToLocation(\'gate-main\', \'Gerbang Utama\')">🏫 Gerbang Utama</button>'
    + '<button class="pbtn" onclick="travelToLocation(\'futsal\', \'Lapangan Futsal\')">⚽ Lapangan Futsal</button>'
    + '<button class="pbtn" onclick="travelToLocation(\'taman-tengah\', \'Taman Sentral\')">🌳 Taman Sentral</button>'
    + '<button class="pbtn" onclick="travelToLocation(\'gedung-a\', \'Gedung A (Kantor & TU)\')">🏛️ Gedung A</button>'
    + '<button class="pbtn" onclick="travelToLocation(\'perpustakaan\', \'Perpustakaan\')">📚 Perpustakaan</button>'
    + '<button class="pbtn" onclick="travelToLocation(\'lab-tik-atas\', \'Lab Komputer TIK\')">💻 Lab Komputer</button>'
    + '<button class="pbtn" onclick="travelToLocation(\'lab-rakitan\', \'Lab Perakitan PC\')">🛠️ Lab Rakitan</button>'
    + '<button class="pbtn" onclick="travelToLocation(\'bank-mini\', \'Bank Mini & Koperasi\')">🏦 Bank Mini</button>'
    + '</div></div>'
    + '<div style="margin-bottom:10px">'
    + '<b style="color:#f59e0b;font-size:.78rem;display:block;margin-bottom:6px">🏙️ Traveling Studi Industri Antar-Kota:</b>'
    + '<div class="prow" style="margin-top:0">'
    + '<button class="pbtn" style="border-color:#f59e0b" onclick="travelToCity(\'Palembang\', \'Pusat Vokasi & Kemitraan Sumsel\')">🚌 Palembang</button>'
    + '<button class="pbtn" style="border-color:#f59e0b" onclick="travelToCity(\'Jakarta\', \'Pusat Industri Digital & Data Center\')">✈️ Jakarta</button>'
    + '<button class="pbtn" style="border-color:#f59e0b" onclick="travelToCity(\'Bandung\', \'Sentra Telekomunikasi & IoT\')">🚆 Bandung</button>'
    + '<button class="pbtn" style="border-color:#f59e0b" onclick="travelToCity(\'Yogyakarta\', \'Pusat Pendidikan & Kebudayaan\')">🚌 Yogyakarta</button>'
    + '</div></div>'
    + '<div style="margin-top:10px;padding-top:8px;border-top:1px solid #1f293d;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">'
    + '<a href="https://www.google.com/maps/search/?api=1&query=-2.976074,104.75702" target="_blank" rel="noopener noreferrer" class="pbtn" style="background:rgba(34,211,238,.18);color:#22d3ee;text-decoration:none">🗺️ Buka Peta SMK Silber di Google Maps ↗</a>'
    + '<button class="pbtn" onclick="closePanel()">✖ Tutup</button>'
    + '</div>';
  d.style.display = "block";
}

function travelToLocation(locId, locName){
  if (pix) pix.goTo(locId);
  if (window.PixelAudio) PixelAudio.sfx('door');
  pixToast("🚌 Tiba di: " + locName, true);
  closePanel();
}

function travelToCity(cityName, desc){
  if (pix) pix.goTo('gate-main');
  if (window.PixelAudio) PixelAudio.sfx('join');
  pixToast("🚌 Traveling ke " + cityName + " (" + desc + ")!", true);
  if (window.PixelNet && PixelNet.status === 'online') {
    PixelNet.sendChat('global', '🚌 sedang melakukan traveling studi industri ke kota ' + cityName + '!');
  }
  closePanel();
}

let isPixVoiceActive = false;
function togglePixVoice(){
  isPixVoiceActive = !isPixVoiceActive;
  var btn = document.getElementById("pixVoiceBtn");
  if (btn) {
    btn.textContent = isPixVoiceActive ? "🎙️ Mengudara..." : "🎙️ Voice";
    btn.style.background = isPixVoiceActive ? "#059669" : "rgba(11,20,36,.92)";
    btn.style.color = isPixVoiceActive ? "#fff" : "#e2e8f0";
  }
  if (window.PixelAudio) PixelAudio.sfx('click');
  pixToast(isPixVoiceActive ? "🎙️ Mikrofon aktif! Suara Anda terhubung ke area sekitar." : "🎙️ Mikrofon dinonaktifkan.", isPixVoiceActive);
}
</script></body></html>
PIXJS;

    }

    public static function render2DMapPage(): void
    {
        $nonce    = Headers::nonce();
        $csrf     = Csrf::token();
        $user     = Auth::user();
        $username = $user !== null ? htmlspecialchars((string)($user['full_name'] ?: $user['username']), ENT_QUOTES) : 'Tamu';
        $role     = $user !== null ? htmlspecialchars((string)$user['role'], ENT_QUOTES) : 'Tamu';
        $staff    = in_array($user['role'] ?? '', ['root', 'kepala_sekolah', 'kepala_program', 'wali_kelas', 'guru'], true);

        $level = 1; $totalXp = 0; $coins = 0; $xpPct = 0; $badgeCount = 0; $missions = []; $missionDone = 0;
        if ($user !== null) {
            try {
                $p     = \VC\Domain\Gameplay::getProgress((int)$user['id']);
                $level = (int)$p['level'];
                $totalXp = (int)$p['total_xp'];
                $coins = (int)$p['coins'];
                $into = \VC\Domain\Gameplay::xpIntoLevel($totalXp, $level);
                $need = \VC\Domain\Gameplay::xpToNextLevel($totalXp, $level);
                $xpPct = ($into + $need) > 0 ? (int)round($into * 100 / ($into + $need)) : 100;
            } catch (\Throwable $e) { $level = 1; }
            try {
                $missions = \VC\Domain\Gameplay::getMissions((int)$user['id']);
                foreach ($missions as $ms) { if (($ms['user_status'] ?? '') === 'completed') { $missionDone++; } }
            } catch (\Throwable $e) { $missions = []; }
            try {
                foreach (\VC\Domain\Gameplay::getAllBadges((int)$user['id']) as $bg) { if (!empty($bg['is_earned'])) { $badgeCount++; } }
            } catch (\Throwable $e) { $badgeCount = 0; }
        }
        $missionTotal = count($missions);
        $topMissions  = array_slice($missions, 0, 3);

        // Jumlah lokasi dokumen mapping yang sudah dikunjungi (server-side, untuk diesen progres).
        $visitedIds = [];
        try { $visitedIds = \VC\Domain\Gameplay::getVisitedLocations((int)$user['id']); } catch (\Throwable $e) { $visitedIds = []; }

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<meta name="csrf" content="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';
        echo \VC\Security\Pwa::headTags();
        echo '<title>Denah & Peta Sekolah — SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">
body { font-family: system-ui, sans-serif; background: #090d16; color: #e2e8f0; margin: 0; padding: 16px; }
.container { max-width: 1240px; margin: 0 auto; }
header.page-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; gap: 12px; flex-wrap: wrap; }
h1 { font-size: clamp(1.05rem, 3.4vw, 1.4rem); color: #fff; margin: 4px 0 0; }
.brand-row { display: flex; align-items: center; gap: 10px; }
.brand-row img.logo { width: 42px; height: 42px; border-radius: 12px; object-fit: cover; border: 2px solid #f59e0b; }
.btn { background: #22d3ee; color: #000; border: none; padding: 9px 16px; border-radius: 9px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; min-height: 40px; }
.btn-sec { background: #1f293d; color: #fff; }
.btn-gold { background: #f59e0b; color: #1c1204; }
.tabs { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.tab { background: #131a29; border: 1px solid #1f293d; color: #94a3b8; padding: 9px 16px; border-radius: 9px; cursor: pointer; font-weight: 700; font-size: .88rem; min-height: 40px; }
.tab.is-active { background: rgba(34,211,238,.15); border-color: #22d3ee; color: #22d3ee; }
.map-box { width: 100%; height: clamp(380px, 64vh, 760px); background: #0f172a; border: 1px solid #1f293d; border-radius: 14px; overflow: hidden; margin-bottom: 14px; position: relative; }
.map-tools { position: absolute; top: 10px; right: 10px; display: flex; gap: 6px; z-index: 5; flex-wrap: wrap; justify-content: flex-end; }
.map-tools .btn { padding: 6px 11px; min-height: 34px; font-size: .8rem; background: rgba(15,23,42,.9); color: #e2e8f0; border: 1px solid #334155; }
.denah-box { width: 100%; height: clamp(380px, 68vh, 800px); overflow: auto; background: #0b1220; border: 1px solid #1f293d; border-radius: 14px; margin-bottom: 14px; }
.denah-box img { display: block; width: 100%; height: auto; transform-origin: top left; transition: width .12s ease; }
.legend { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.legend span { background: #131a29; border: 1px solid #1f293d; border-radius: 999px; padding: 5px 11px; font-size: .76rem; color: #cbd5e1; }
.grid-2 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 14px; }
.card { background: #131a29; border: 1px solid #1f293d; border-radius: 12px; padding: 14px; }
@media (max-width: 860px) { .grid-2 { grid-template-columns: 1fr; } body { padding: 10px; } }
</style></head><body class="vc-game">';

        echo '<div class="container">';
        echo '<header class="page-head">';
        echo '<div class="brand-row"><img class="logo" src="/assets/img/logo-silber.jpg" alt="Logo SMK Silber">';
        echo '<div><a href="/" style="color:#22d3ee;text-decoration:none;font-size:.85rem">← Beranda</a>';
        echo '<h1>🗺️ Denah & Peta Sekolah SMK Silber</h1></div></div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        echo '<a href="/dunia" class="btn btn-gold">🚀 Dunia 3D</a>';
        echo '<a href="/pixel" class="btn btn-sec">🧱 Pixel World</a>';
        if ($staff) { echo '<a href="/editor-peta" class="btn btn-sec">📐 Editor Denah</a>'; }
        echo '</div></header>';

        echo '<div class="tabs">';
        echo '<button class="tab is-active" id="tabDenah" onclick="switchDenahTab(\'denah\')">🖼️ Denah Asli (Gambar)</button>';
        echo '<button class="tab" id="tabPeta" onclick="switchDenahTab(\'peta\')">🎮 Peta Interaktif</button>';
        echo '</div>';

        // ---- Panel 1: Denah asli (gambar resmi sekolah, bisa diperbesar) ----
        echo '<div id="panelDenah" class="denah-box">';
        echo '<div style="position:sticky;top:0;z-index:4;display:flex;gap:6px;padding:10px;background:rgba(9,13,22,.92);border-bottom:1px solid #1f293d;flex-wrap:wrap">';
        echo '<button class="btn" style="padding:6px 12px;min-height:34px;font-size:.82rem;background:#1f293d;color:#fff" onclick="zoomDenah(-25)">➖ Perkecil</button>';
        echo '<button class="btn" style="padding:6px 12px;min-height:34px;font-size:.82rem;background:#1f293d;color:#fff" onclick="zoomDenah(0)">↺ Reset</button>';
        echo '<button class="btn" style="padding:6px 12px;min-height:34px;font-size:.82rem;background:#1f293d;color:#fff" onclick="zoomDenah(25)">➕ Perbesar</button>';
        echo '<span id="denahZoomLabel" style="align-self:center;font-size:.78rem;color:#94a3b8">100%</span>';
        echo '<span style="align-self:center;font-size:.78rem;color:#94a3b8;margin-left:auto">Geser/scroll untuk melihat detail</span>';
        echo '</div>';
        echo '<img id="denahImg" src="/assets/map/denah-sekolah.jpg" alt="Denah lokasi sekolah SMK Silber" loading="eager">';
        echo '</div>';

        // ---- Panel 2: Peta interaktif 2D (hotspot 35 lokasi dokumen mapping) ----
        echo '<div id="panelPeta" style="display:none">';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">';
        echo '<div id="locFilterBar" role="group" aria-label="Filter kategori lokasi" style="display:flex;gap:6px;flex-wrap:wrap">';
        foreach ([['semua','Semua'],['belajar','📚 Belajar'],['layanan','🏛️ Layanan'],['olahraga','⚽ Olahraga'],['fasilitas','🌳 Fasilitas']] as $f) {
            echo '<button class="tab' . ($f[0]==='semua' ? ' is-active' : '') . '" data-locfilter="' . $f[0] . '" onclick="locFilter(\'' . $f[0] . '\')" style="min-height:36px;padding:6px 12px;font-size:.8rem">' . $f[1] . '</button>';
        }
        echo '</div>';
        echo '<button class="tab" id="floorInd2" onclick="locFloor(2)" style="min-height:36px;padding:6px 12px;font-size:.8rem">Lantai 2</button>';
        echo '<input id="locSearch" type="search" placeholder="🔍 Cari lokasi…" aria-label="Cari lokasi" oninput="locSearch(this.value)" style="flex:1;min-width:150px;background:#0d121d;color:#fff;border:1px solid #1f293d;border-radius:9px;padding:8px 12px;font-size:.82rem;min-height:40px">';
        echo '<span id="locProgress" style="font-size:.76rem;color:#94a3b8;white-space:nowrap">0/35 dikunjungi</span>';
        echo '</div>';
        echo '<div class="map-box">';
        echo '<div class="map-tools">';
        echo '<button class="btn" onclick="locFloor(1)" id="floorInd1">Lantai 1</button>';
        echo '<button class="btn" onclick="if(map.fitToCampus)map.fitToCampus()">⤢ Fit</button>';
        echo '<button class="btn" onclick="map.zoomIn(1.3)">➕</button>';
        echo '<button class="btn" onclick="map.zoomOut(0.77)">➖</button>';
        echo '</div>';
        echo '<canvas id="mapCanvas" style="width:100%;height:100%" tabindex="0" aria-label="Peta interaktif sekolah"></canvas>';
        echo '</div>';
        echo '<div class="legend">';
        echo '<span style="color:#22d3ee">●</span><span>Belajar</span><span style="color:#f59e0b">●</span><span>Layanan</span><span style="color:#22c55e">●</span><span>Olahraga</span><span style="color:#a78bfa">●</span><span>Fasilitas</span><span>★ = ada misi</span><span>🖱️ Klik hotspot = detail</span><span>Scroll = zoom • Tarik = geser</span>';
        echo '</div>';
        echo '</div>';

        echo '<div class="grid-2">';
        echo '<div class="card" id="roomDetail"><strong style="color:#fff">📍 Detail Lokasi</strong><br>Klik salah satu ruangan/gedung pada peta interaktif untuk melihat detail, atau buka tab Denah Asli untuk melihat gambar denah lengkap.</div>';
        echo '<div class="card" style="border-color:#f59e0b">';
        echo '<div style="display:flex;gap:10px;align-items:flex-start"><img src="/assets/mascot/silber-mascot.svg" alt="Si Silber" style="width:44px;height:44px;flex-shrink:0">';
        echo '<div style="flex:1"><strong style="color:#f59e0b">🤖 Tanya Denah ke Si Silber</strong>';
        echo '<div style="font-size:.8rem;color:#94a3b8;margin-top:3px">Aku bisa kasih petunjuk lokasi, bantu selesaikan misi, dan jelaskan fitur sekolah.</div>';
        echo '<div id="mapLobbyAi" style="display:none;margin-top:8px;background:rgba(0,0,0,.35);border:1px solid #1f293d;border-radius:9px;padding:9px;max-height:170px;overflow-y:auto;font-size:.82rem;line-height:1.55"></div>';
        echo '<div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:8px">';
        echo '<button class="btn btn-sec" style="padding:6px 11px;min-height:34px;font-size:.78rem" onclick="mapAsk(\'Di mana ruang kelas saya?\')">🧭 Cari ruang</button>';
        echo '<button class="btn btn-sec" style="padding:6px 11px;min-height:34px;font-size:.78rem" onclick="mapAsk(\'Beri petunjuk misi saya\')">🎯 Petunjuk misi</button>';
        echo '<button class="btn btn-sec" style="padding:6px 11px;min-height:34px;font-size:.78rem" onclick="mapAsk(\'Apa saja yang bisa kamu bantu?\')">💬 Bantuan</button>';
        echo '</div></div></div>';
        echo '</div>';

        echo Footer::html();
        echo '</div>';

        // ---- Dashboard lobi SEBELUM masuk denah (user & admin) ----
        echo '<div id="mapLobby" style="position:fixed;inset:0;background:rgba(4,7,15,.94);backdrop-filter:blur(6px);z-index:20000;display:flex;align-items:center;justify-content:center;padding:16px">';
        echo '<div style="background:#131a29;border:1px solid #22d3ee;border-radius:16px;max-width:780px;width:100%;max-height:92vh;overflow-y:auto;padding:22px">';
        echo '<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;border-bottom:1px solid #1f293d;padding-bottom:14px">';
        echo '<img src="/assets/img/logo-silber.jpg" alt="Logo SMK Silber" style="width:54px;height:54px;border-radius:14px;object-fit:cover;border:2px solid #f59e0b">';
        echo '<div style="flex:1;min-width:200px"><div style="display:flex;gap:8px;flex-wrap:wrap"><span style="background:rgba(34,211,238,.15);color:#22d3ee;border:1px solid #0284c7;border-radius:999px;padding:3px 10px;font-size:.72rem;font-weight:700">🗺️ Denah Sekolah</span>';
        echo '<span style="background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid #b45309;border-radius:999px;padding:3px 10px;font-size:.72rem;font-weight:700">📋 Dashboard Pra-Denah</span></div>';
        echo '<h2 style="margin:6px 0 2px;font-size:1.15rem;color:#fff">Halo, ' . $username . ' 👋</h2>';
        echo '<span style="font-size:.78rem;color:#94a3b8">Lihat denah asli & peta interaktif sebelum menjelajah sekolah</span></div>';
        echo '<img src="/assets/mascot/silber-mascot.svg" alt="Si Silber" style="width:60px;height:60px"></div>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-top:16px">';
        echo '<div class="card" style="margin:0"><div style="font-size:.78rem;color:#94a3b8">👤 Pemain</div><div style="font-weight:800;color:#fff">' . $username . ' <span style="font-size:.7rem;background:#1f293d;border-radius:6px;padding:2px 7px">' . $role . '</span></div>';
        echo '<div style="display:flex;justify-content:space-between;font-size:.78rem;margin-top:8px"><span>⭐ Level ' . $level . '</span><span>✨ ' . $totalXp . ' XP</span></div>';
        echo '<div style="height:8px;background:#0f172a;border-radius:99px;margin-top:4px;overflow:hidden"><div style="height:100%;width:' . $xpPct . '%;background:linear-gradient(90deg,#22d3ee,#f59e0b)"></div></div>';
        echo '<div style="display:flex;justify-content:space-between;font-size:.78rem;margin-top:8px"><span>🪙 ' . $coins . ' koin</span><span>🏅 ' . $badgeCount . ' lencana</span></div></div>';

        echo '<div class="card" style="margin:0"><div style="font-size:.78rem;color:#94a3b8">🎯 Misi</div><div style="font-weight:800;color:#fff">' . $missionDone . ' / ' . $missionTotal . ' selesai</div><div style="margin-top:8px;font-size:.78rem;line-height:1.7;color:#94a3b8">';
        if (!$topMissions) { echo 'Belum ada misi aktif.'; }
        foreach ($topMissions as $tm) {
            $done = ($tm['user_status'] ?? '') === 'completed';
            echo '<div>' . ($done ? '✅' : '🆕') . ' ' . htmlspecialchars((string)($tm['title'] ?? ''), ENT_QUOTES) . '</div>';
        }
        echo '</div><a href="/misi" style="color:#22d3ee;font-size:.78rem">Semua misi →</a></div>';

        echo '<div class="card" style="margin:0"><div style="font-size:.78rem;color:#94a3b8">📖 Isi Denah</div><div style="font-size:.8rem;line-height:1.75;color:#94a3b8;margin-top:4px">';
        echo '🏫 Gedung A–D • 📚 Ruang kelas 1–13 • 💻 Lab TIK, Rakitan & Jaringan<br>📚 Perpustakaan • 🏦 Bank Mini • 🛒 Mini Market<br>🕌 Musholah • ⚽ Lapangan Futsal • 🅿️ Parkir • 🌳 Taman';
        echo '</div></div>';
        echo '</div>';

        if ($staff) {
            echo '<div class="card" style="margin-top:12px;border-color:#f97316"><strong style="color:#f97316">🛡️ Panel Guru / Admin</strong><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">';
            echo '<a href="/admin" class="btn btn-sec" style="padding:7px 12px;min-height:36px;font-size:.8rem">📊 Dashboard Admin</a>';
            echo '<a href="/editor-peta" class="btn btn-sec" style="padding:7px 12px;min-height:36px;font-size:.8rem">📐 Editor Denah</a>';
            echo '<a href="/jadwal" class="btn btn-sec" style="padding:7px 12px;min-height:36px;font-size:.8rem">📅 Jadwal</a>';
            echo '<a href="/tugas" class="btn btn-sec" style="padding:7px 12px;min-height:36px;font-size:.8rem">📝 Tugas</a>';
            echo '</div></div>';
        }

        echo '<div style="display:flex;gap:8px;justify-content:space-between;flex-wrap:wrap;margin-top:18px">';
        echo '<a href="/" class="btn btn-sec">🏠 Beranda</a>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap"><a href="/dunia" class="btn btn-sec">🚀 Dunia 3D</a>';
        echo '<button class="btn" style="background:#22d3ee;color:#04121a;font-weight:800;padding:0 22px;min-height:46px" onclick="closeMapLobby()">🗺️ Mulai Melihat Denah</button></div></div>';
        echo '</div></div>';

        echo '<script src="/assets/js/map2d.js?v=7.5.0"></script>';
        echo '<script nonce="' . $nonce . '">'
            . 'let map = null; let denahZoom = 100;'
            . 'function closeMapLobby(){var el=document.getElementById("mapLobby");if(el)el.style.display="none";}'
            . 'function switchDenahTab(which){'
            . '  var d=document.getElementById("panelDenah"), p=document.getElementById("panelPeta");'
            . '  var td=document.getElementById("tabDenah"), tp=document.getElementById("tabPeta");'
            . '  var isDenah = which === "denah";'
            . '  d.style.display = isDenah ? "block" : "none"; p.style.display = isDenah ? "none" : "block";'
            . '  td.classList.toggle("is-active", isDenah); tp.classList.toggle("is-active", !isDenah);'
            . '  if(!isDenah && map){ setTimeout(function(){ map.resizeCanvas(); if(map.fitToCampus) map.fitToCampus(); }, 60); }'
            . '}'
            . 'function zoomDenah(step){'
            . '  denahZoom = step === 0 ? 100 : Math.max(50, Math.min(320, denahZoom + step));'
            . '  var img = document.getElementById("denahImg"); if(img) img.style.width = denahZoom + "%";'
            . '  var lbl = document.getElementById("denahZoomLabel"); if(lbl) lbl.textContent = denahZoom + "%";'
            . '}'
            . 'async function mapAsk(q){'
            . '  var box=document.getElementById("mapLobbyAi"); if(!box) return; box.style.display="block";'
            . '  box.innerHTML += "<div style=\"text-align:right;color:#22d3ee\"><b>Kamu:</b> "+q.replace(/</g,"&lt;")+"</div><div style=\"color:#94a3b8\">🤖 Si Silber berpikir…</div>";'
            . '  box.scrollTop = box.scrollHeight;'
            . '  try {'
            . '    var m=document.querySelector("meta[name=csrf]"); var c=m?m.content:"";'
            . '    var r=await fetch("/api/ai/ask",{method:"POST",headers:{"Content-Type":"application/json","X-CSRF":c},'
            . '      body:JSON.stringify({question:q,context:"Halaman Denah & Peta Sekolah SMK Silber",_csrf:c})});'
            . '    var d=await r.json(); var reply=(d&&(d.reply||d.message))||"Maaf, coba lagi ya.";'
            . '    if(box.lastElementChild) box.lastElementChild.remove();'
            . '    box.innerHTML += "<div style=\"background:rgba(30,41,59,.75);padding:8px 10px;border-radius:8px\">🤖 <b>Si Silber:</b> "+String(reply).replace(/</g,"&lt;")+"</div>";'
            . '  } catch(e){ if(box.lastElementChild) box.lastElementChild.remove();'
            . '    box.innerHTML += "<div style=\"color:#fca5a5\">🤖 Gagal terhubung. Coba lagi.</div>"; }'
            . '  box.scrollTop = box.scrollHeight;'
            . '}'
            . 'window.__visitedLocs = ' . json_encode(array_values($visitedIds)) . ';'
            . 'window.__LOC_NAMES = { belajar: "Belajar", layanan: "Layanan", olahraga: "Olahraga", fasilitas: "Fasilitas" };'
            . 'function locFilter(cat){'
            . '  document.querySelectorAll("#locFilterBar .tab").forEach(function(b){ b.classList.toggle("is-active", b.dataset.locfilter === cat); });'
            . '  if(map) map.setLocationFilter(cat);'
            . '}'
            . 'function locSearch(q){ if(map) map.searchLocations(q); }'
            . 'function locFloor(f){'
            . '  if(map) map.setFloor(f);'
            . '  var f1=document.getElementById("floorInd1"), f2=document.getElementById("floorInd2");'
            . '  if(f1) f1.classList.toggle("is-active", f===1);'
            . '  if(f2) f2.classList.toggle("is-active", f===2);'
            . '}'
            . 'window.addEventListener("DOMContentLoaded", () => {'
            . '  map = new CampusMap2D("mapCanvas", {'
            . '    campusDataUrl: "/api/campus", interactive: true,'
            . '    onSelectLocation: (l, opts) => {'
            . '      locPanel(l);'
            . '      switchDenahTab("peta");'
            . '      if(opts && opts.missions !== undefined) return;'
            . '    },'
            . '    onSelectRoom: (r) => {'
            . '      var b = r.bounds || { x: (r.x!==undefined?r.x:0), z: (r.z!==undefined?r.z:0), width: 18, depth: 14 };'
            . '      document.getElementById("roomDetail").innerHTML ='
            . '        "<strong style=\"color:#fff;font-size:1.05rem\">" + (r.name||"-") + "</strong><br>" +'
            . '        "Gedung: " + (r.building||"-") + " • Lantai: " + (r.floor||1) + " • Tipe: " + (r.type||"-") + "<br>" +'
            . '        "<span style=\"color:#22d3ee;font-size:.85rem\">Koordinat: (" + b.x + ", " + b.z + ") • Ukuran: " + b.width + "×" + b.depth + "</span>";'
            . '      switchDenahTab("peta");'
            . '    }'
            . '  });'
            . '  map.init().then(function(){ map.loadLocations(); });'
            . '  updateLocProgress();'
            . '});'
            . 'function updateLocProgress(){'
            . '  var el=document.getElementById("locProgress"); if(!el) return;'
            . '  var total = (map && map.locations && map.locations.length) ? map.locations.length : 35;'
            . '  var interior = (map ? map.locations.map(function(l){ return (l.campusRef||"").split(":")[1]; }) : []);'
            . '  var n = interior.filter(function(id){ return id && window.__visitedLocs.indexOf(id) >= 0; }).length;'
            . '  el.textContent = n + "/" + total + " dikunjungi";'
            . '}'
            . 'function locPanel(l){'
            . '  var d = document.getElementById("roomDetail"); if(!d || !l) return;'
            . '  var internalId = (l.campusRef || "").split(":")[1] || l.id;'
            . '  var visited = window.__visitedLocs.indexOf(internalId) >= 0;'
            . '  var color = map && map.categoryColors[l.category] ? map.categoryColors[l.category] : "#38bdf8";'
            . '  var missions = (l.missionIds && l.missionIds.length) ? l.missionIds.join(", ") : "Tidak ada misi terkait";'
            . '  d.innerHTML ='
            . '    "<strong style=\"color:" + color + ";font-size:1.05rem\">" + (l.name || l.id) + "</strong> " +'
            . '    "<span style=\"font-size:.72rem;border:1px solid " + color + ";color:" + color + ";border-radius:99px;padding:2px 9px\">" + (window.__LOC_NAMES[l.category] || l.category) + " • Lantai " + (l.floor||1) + "</span>" +'
            . '    "<p style=\"margin:8px 0 6px;font-size:.85rem;color:#cbd5e1;line-height:1.55\">" + (l.description || "") + "</p>" +'
            . '    "<div style=\"font-size:.8rem\">🎯 Misi: " + missions + "</div>" +'
            . '    "<div style=\"font-size:.76rem;color:" + (l.unlocked ? "#22c55e" : "#f87171") + "\">" + (l.unlocked ? "🔓 Terbuka" : "🔒 Terkunci") + " • Koordinat denah: (" + l.x + ", " + l.y + ") — perkiraan" + "</div>" +'
            . '    "<div style=\"display:flex;gap:8px;flex-wrap:wrap;margin-top:10px\">" +'
            . '    "<a href=\"/dunia?loc=" + l.id + "\" class=\"btn\" style=\"padding:6px 12px;min-height:34px;font-size:.78rem\">🚀 Jelajahi di Dunia 3D</a>" +'
            . '    (visited ? "<span style=\"align-self:center;font-size:.78rem;color:#22c55e\">✅ Sudah dikunjungi</span>" :'
            . '      "<button class=\"btn btn-sec\" onclick=\"markVisited(\'" + internalId + "\')\" style=\"padding:6px 12px;min-height:34px;font-size:.78rem\">📍 Tandai Dikunjungi</button>") +'
            . '    "</div>";'
            . '}'
            . 'async function markVisited(internalId){'
            . '  try {'
            . '    var m=document.querySelector("meta[name=csrf]"); var c=m?m.content:"";'
            . '    var r=await fetch("/api/world/visit",{method:"POST",headers:{"Content-Type":"application/json","X-CSRF":c},'
            . '      body:JSON.stringify({location_id:internalId,_csrf:c})});'
            . '    var d=await r.json();'
            . '    if(d.status==="ok"){ if(window.__visitedLocs.indexOf(internalId)<0) window.__visitedLocs.push(internalId); updateLocProgress(); '
            . '      var sel = map && map.locationById ? map.locationById.get(map.selectedLocationId) : null; if(sel) locPanel(sel);'
            . '    } else alert(d.message || "Gagal mencatat kunjungan.");'
            . '  } catch(e){ alert("Koneksi gagal. Coba lagi."); }'
            . '}'
            . 'document.addEventListener("keydown",function(e){ if(e.key==="Escape") closeMapLobby(); });'
            . '</script></body></html>';
    }

    /** Render Web Map Editor for ROOT / Admin */
    public static function renderEditorPage(): void
    {
        Permissions::require('world.edit_details');
        $nonce = Headers::nonce();
        $csrf = Csrf::token();

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<title>Editor Peta Sekolah — Admin SMK Silber</title>';
        // Runtime binder CSP: diperlukan agar handler inline (onclick/onsubmit) tetap berjalan.
        echo '<script src="/assets/js/ui.js" defer></script>';
        echo '<style nonce="' . $nonce . '">
body { font-family: system-ui, sans-serif; background: #090d16; color: #e2e8f0; margin: 0; padding: 16px; }
.container { max-width: 1100px; margin: 0 auto; }
header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.grid { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; }
.card { background: #131a29; border: 1px solid #1f293d; border-radius: 12px; padding: 16px; }
.canvas-box { width: 100%; height: 550px; background: #0f172a; border: 1px solid #1f293d; border-radius: 8px; overflow: hidden; }
input, select, textarea { width: 100%; background: #0d121d; color: #fff; border: 1px solid #1f293d; padding: 8px; border-radius: 6px; margin-bottom: 8px; font-size: 0.85rem; }
.btn { background: #22d3ee; color: #000; border: none; padding: 8px 16px; border-radius: 8px; font-weight: bold; cursor: pointer; }
</style></head><body class="vc-game">';
        echo '<div class="container">';
        echo '<header>';
        echo '<div><a href="/" style="color:#22d3ee;text-decoration:none;font-size:0.9rem">← Kembali</a>';
        echo '<h1>📐 Editor Peta Sekolah (Map Editor)</h1></div>';
        echo '<div style="display:flex;gap:8px">';
        echo '<button onclick="saveCampusJson()" class="btn">💾 Simpan Perubahan</button>';
        echo '</div>';
        echo '</header>';
        echo '<div class="grid">';
        echo '<div>';
        echo '<div class="card">';
        echo '<div style="display:flex;gap:8px;margin-bottom:8px">';
        echo '<button onclick="mapEditor.setFloor(1)" style="padding:4px 10px;background:#1f293d;color:#fff;border:none;border-radius:4px;cursor:pointer">Lantai 1</button>';
        echo '<button onclick="mapEditor.setFloor(2)" style="padding:4px 10px;background:#1f293d;color:#fff;border:none;border-radius:4px;cursor:pointer">Lantai 2</button>';
        echo '</div>';
        echo '<div class="canvas-box"><canvas id="editorCanvas" style="width:100%;height:100%"></canvas></div>';
        echo '</div>';
        echo '</div>';
        echo '<div>';
        echo '<div class="card">';
        echo '<h3 style="margin-bottom:12px;font-size:1rem;color:#fff">Properti Gedung / Ruangan</h3>';
        echo '<form id="editorForm" onsubmit="return false">';
        echo '<label style="font-size:0.75rem;color:#94a3b8">Nama Ruangan / Gedung</label>';
        echo '<input id="propName" required>';
        echo '<label style="font-size:0.75rem;color:#94a3b8">Gedung</label>';
        echo '<select id="propBuilding"><option value="A">Gedung A</option><option value="B">Gedung B</option><option value="C">Gedung C</option><option value="D">Gedung D</option><option value="Luar">Area Luar</option></select>';
        echo '<label style="font-size:0.75rem;color:#94a3b8">Lantai</label>';
        echo '<select id="propFloor"><option value="1">Lantai 1</option><option value="2">Lantai 2</option></select>';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">';
        echo '<div><label style="font-size:0.75rem;color:#94a3b8">Posisi X</label><input type="number" id="propX"></div>';
        echo '<div><label style="font-size:0.75rem;color:#94a3b8">Posisi Z</label><input type="number" id="propZ"></div>';
        echo '<div><label style="font-size:0.75rem;color:#94a3b8">Lebar (W)</label><input type="number" id="propW"></div>';
        echo '<div><label style="font-size:0.75rem;color:#94a3b8">Panjang (D)</label><input type="number" id="propD"></div>';
        echo '</div>';
        echo '<button onclick="updateSelectedRoom()" class="btn" style="width:100%;margin-top:8px">Terapkan Perubahan Ruangan</button>';
        echo '</form>';
        echo '</div></div></div>';
        echo Footer::html();
        echo '</div>';
        echo '<script src="/assets/js/map2d.js?v=7.5.0"></script>';
        echo '<script nonce="' . $nonce . '">
let mapEditor = null;
let currentSelected = null;

window.addEventListener("DOMContentLoaded", () => {
  mapEditor = new CampusMap2D("editorCanvas", {
    campusDataUrl: "/api/campus",
    interactive: true,
    onSelectRoom: (r) => {
      currentSelected = r;
      document.getElementById("propName").value = r.name;
      document.getElementById("propBuilding").value = r.building || "A";
      document.getElementById("propFloor").value = r.floor || 1;
      document.getElementById("propX").value = r.bounds.x;
      document.getElementById("propZ").value = r.bounds.z;
      document.getElementById("propW").value = r.bounds.width;
      document.getElementById("propD").value = r.bounds.depth;
    }
  });
  mapEditor.init();
});

function updateSelectedRoom() {
  if (!currentSelected) return;
  currentSelected.name = document.getElementById("propName").value;
  currentSelected.building = document.getElementById("propBuilding").value;
  currentSelected.floor = parseInt(document.getElementById("propFloor").value) || 1;
  currentSelected.bounds.x = parseFloat(document.getElementById("propX").value) || 0;
  currentSelected.bounds.z = parseFloat(document.getElementById("propZ").value) || 0;
  currentSelected.bounds.width = parseFloat(document.getElementById("propW").value) || 10;
  currentSelected.bounds.depth = parseFloat(document.getElementById("propD").value) || 10;
  mapEditor.render();
  alert("Properti ruangan diperbarui di memori editor.");
}

async function saveCampusJson() {
  if (!confirm("Simpan peta sekolah ke server?")) return;
  try {
    const res = await fetch("/api/campus/save", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ campus: mapEditor.campusData, _csrf: "' . $csrf . '" })
    });
    const d = await res.json();
    alert(d.message || "Peta tersimpan.");
  } catch(e) { alert("Gagal menyimpan peta."); }
}
</script></body></html>';
    }

    /** Agent 1 — Halaman "Gabung Kelas" satu-form: murid masuk room via kode. */
    public static function renderJoinClassPage(): void
    {
        $user = Auth::user();
        if ($user === null || ($user['role'] ?? '') === 'tamu') {
            self::renderGuestJoinForm($user);
            return;
        }
        $nonce = Headers::nonce();
        $csrf = Csrf::token();
        $nama = htmlspecialchars((string)($user['full_name'] ?: $user['username']), ENT_QUOTES);
        $rol = (string)($user['role'] ?? 'murid');

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<meta name="csrf" content="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';
        echo \VC\Security\Pwa::headTags();
        echo '<title>Gabung Kelas — Virtual City SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">';
        echo 'body{font-family:system-ui,sans-serif;background-color:#0b1326;background-image:radial-gradient(circle at 18% 12%,rgba(2,132,199,0.18),transparent 45%),radial-gradient(circle at 82% 78%,rgba(249,115,22,0.12),transparent 45%);color:#e2e8f0;min-height:100dvh;margin:0;display:flex;align-items:center;justify-content:center;padding:16px calc(16px + env(safe-area-inset-right)) calc(44px + env(safe-area-inset-bottom)) calc(16px + env(safe-area-inset-left))}';
        echo '.jk-card{width:100%;max-width:460px;background:linear-gradient(145deg,rgba(19,32,56,0.95),rgba(13,22,39,0.98));border:1px solid rgba(56,189,248,0.25);border-radius:20px;padding:24px;box-shadow:0 16px 44px rgba(0,0,0,0.5)}';
        echo '.jk-card h1{font-size:1.25rem;margin:0 0 4px;color:#fff}.jk-card p.sub{margin:0 0 16px;color:#94a3b8;font-size:.85rem;line-height:1.5}';
        echo '.jk-label{display:block;font-size:.8rem;color:#94a3b8;margin:0 0 6px;font-weight:700}';
        echo '.jk-input{width:100%;box-sizing:border-box;border:1.5px solid rgba(56,189,248,0.25);background:rgba(11,19,38,0.9);color:#fff;border-radius:12px;height:50px;font-size:1.1rem;letter-spacing:.18em;text-align:center;text-transform:uppercase;font-weight:700}';
        echo '.jk-input:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 3px rgba(56,189,248,.25)}';
        echo '.jk-cta{width:100%;height:50px;margin-top:12px;border:none;border-radius:12px;font-weight:800;font-size:1rem;color:#fff;background:linear-gradient(135deg,#f97316,#ea580c);cursor:pointer;transition:transform .08s ease}';
        echo '.jk-cta:active{transform:scale(.98)}.jk-cta[disabled]{opacity:.55;cursor:not-allowed}';
        echo '.jk-alert{min-height:0;margin-top:10px;font-size:.85rem;border-radius:10px;padding:10px 12px;display:none}';
        echo '.jk-alert--err{display:block;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);color:#fca5a5}';
        echo '.jk-alert--ok{display:block;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.4);color:#86efac}';
        echo '.jk-roomlist{margin-top:20px;border-top:1px solid rgba(56,189,248,0.18);padding-top:14px}';
        echo '.jk-roomlist h3{font-size:.85rem;color:#94a3b8;margin:0 0 10px;font-weight:700}';
        echo '.jk-room{display:flex;justify-content:space-between;align-items:center;gap:8px;background:rgba(11,19,38,0.85);border:1px solid rgba(56,189,248,0.18);border-radius:12px;padding:10px 12px;margin-bottom:8px;font-size:.85rem}';
        echo '.jk-room a{color:#38bdf8;text-decoration:none;font-weight:700}';
        echo '.jk-rooms-empty{color:#64748b;font-size:.8rem}';
        echo '</style></head><body>';

        echo '<main class="jk-card">';
        echo '<h1>Gabung Room & Kelas</h1>';
        echo '<p class="sub">Masuk room kelas dengan kode dari gurumu. Kamu login sebagai <strong>' . $nama . '</strong> (identitas resmi sekolah).</p>';
        echo '<form id="jkForm" novalidate>';
        echo '<label class="jk-label" for="jkCode">Kode Undangan Room Kelas</label>';
        // Link/QR "Bagikan server" = /gabung?kode=XXXX → kode terisi & langsung masuk.
        $jkPref = htmlspecialchars(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['kode'] ?? '')) ?? '', 0, 12)), ENT_QUOTES);
        echo '<input class="jk-input" id="jkCode" name="code" inputmode="text" autocomplete="off" maxlength="12" placeholder="XXXXXXXX" required aria-describedby="jkAlert" value="' . $jkPref . '">';
        echo '<div id="jkAlert" class="jk-alert" role="alert" aria-live="polite"></div>';
        echo '<button class="jk-cta" id="jkBtn" type="submit"><span id="jkBtnLabel">Masuk ke Room</span></button>';
        echo '</form>';

        // --> Daftar room yang sudah menjadi hak akses user (render server-side).
        echo '<div class="jk-roomlist"><h3>Server & Room Tersedia</h3><div id="jkRooms">';
        try {
            $rooms = \VC\Domain\GameServers::listFor((int)$user['id'], $rol);
        } catch (\Throwable $e) { $rooms = []; }
        if ($rooms === []) {
            echo '<p class="jk-rooms-empty">Belum ada room kelas terdaftar. Masukkan kode undangan di atas.</p>';
        } else {
            foreach ($rooms as $r) {
                $nm = htmlspecialchars((string)$r['name'], ENT_QUOTES);
                $st = ($r['online'] ?? 0) > 0 ? '<span style="color:#10b981">● ' . (int)$r['online'] . ' online</span>' : '<span style="color:#94a3b8">○ siaga</span>';
                $tBadge = ($r['type'] === 'class') ? 'Kelas' : 'Publik';
                echo '<div class="jk-room"><span><strong>' . $nm . '</strong> <span style="font-size:0.7rem;background:rgba(2,132,199,0.2);color:#38bdf8;padding:2px 6px;border-radius:4px">' . $tBadge . '</span><br><small style="color:#64748b">' . $st . ' • Kapasitas: ' . (int)$r['capacity'] . '</small></span>';
                echo '<div style="display:flex;gap:4px"><a href="/dunia?server=' . (int)$r['id'] . '" style="padding:4px 8px;background:rgba(56,189,248,0.15);border-radius:6px;font-size:0.75rem">3D</a><a href="/pixel?server=' . (int)$r['id'] . '" data-server="' . (int)$r['id'] . '" style="padding:4px 8px;background:#0284c7;color:#fff;border-radius:6px;font-size:0.75rem">Pixel</a></div></div>';
            }
        }
        echo '</div><div style="margin-top:10px;text-align:right"><a href="/pixel" style="color:#38bdf8;font-size:.8rem;text-decoration:none">Buka Server Browser / Pixel World →</a></div></div>';
        echo '</main>';

        echo '<script nonce="' . $nonce . '">';
        echo 'var jkCsrf = (document.querySelector("meta[name=csrf]")||{}).content || "";';
        echo "var jkF=document.getElementById('jkForm'),jkA=document.getElementById('jkAlert'),jkC=document.getElementById('jkCode'),jkB=document.getElementById('jkBtn');";
        echo "function jkMsg(t,cls){jkA.className='jk-alert jk-alert--' + (cls||'err');jkA.textContent=t;}";
        echo "jkC.addEventListener('input',function(){jkC.value=jkC.value.toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,12);});";
        echo "jkF.addEventListener('submit',async function(ev){ev.preventDefault();var code=jkC.value.trim();if(!code){jkMsg('Masukkan kode kelas terlebih dahulu.');jkC.focus();return;}jkB.disabled=true;";
        echo "try{var res=await fetch('/api/servers/join',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF':jkCsrf},body:JSON.stringify({code:code,_csrf:jkCsrf})});var d=await res.json();";
        echo 'if(res.ok&&d&&d.status==="ok"){jkA.className="jk-alert jk-alert--ok";jkA.textContent="Berhasil! Masuk room "+(d.name||code)+"…";setTimeout(function(){location.href=d.redirect||("/dunia?server="+(d.server_id||1));},600);}else{jkMsg(d.message||"Gagal bergabung. Kode salah atau akses ditolak.");jkB.disabled=false;}}catch(e){jkMsg("Koneksi ke server terputus. Coba lagi.");jkB.disabled=false;}});';
        echo 'if(jkC.value.length>=4){jkMsg("Membuka room dari link undangan…","ok");setTimeout(function(){if(jkF.requestSubmit)jkF.requestSubmit();else jkB.click();},300);}';
        echo '</script></body></html>';
    }

    /** Gabung cepat tanpa akun: Kode Room + Nama → /dunia?server={id}. Ringan & ramah HP. */
    private static function renderGuestJoinForm(?array $guest): void
    {
        $nonce = Headers::nonce();
        $csrf = Csrf::token();
        $prefName = htmlspecialchars((string)($guest['full_name'] ?? ''), ENT_QUOTES);
        $prefCode = htmlspecialchars(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['kode'] ?? '')) ?? ''), ENT_QUOTES);
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<meta name="csrf" content="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';
        echo \VC\Security\Pwa::headTags();
        echo '<title>Gabung Kelas 3D — SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">';
        echo 'body{font-family:system-ui,sans-serif;background:#0b1326;color:#e2e8f0;min-height:100dvh;margin:0;display:flex;align-items:center;justify-content:center;padding:16px}';
        echo '.g-card{width:100%;max-width:420px;background:#132038;border:1px solid rgba(56,189,248,.25);border-radius:18px;padding:22px;box-shadow:0 16px 44px rgba(0,0,0,.5)}';
        echo '.g-card h1{font-size:1.3rem;margin:0 0 4px;color:#fff}.g-card p{margin:0 0 18px;color:#94a3b8;font-size:.9rem;line-height:1.5}';
        echo 'label{display:block;font-size:.85rem;color:#cbd5e1;margin:12px 0 6px;font-weight:700}';
        // font-size >=16px: iOS tidak auto-zoom saat input difokus.
        echo 'input{width:100%;box-sizing:border-box;border:1.5px solid rgba(56,189,248,.3);background:#0b1326;color:#fff;border-radius:12px;height:52px;font-size:17px;padding:0 14px}';
        echo 'input:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 3px rgba(56,189,248,.25)}';
        echo '#gCode{letter-spacing:.18em;text-align:center;text-transform:uppercase;font-weight:800}';
        echo 'button{width:100%;height:54px;margin-top:18px;border:none;border-radius:12px;font-weight:800;font-size:17px;color:#fff;background:linear-gradient(135deg,#f97316,#ea580c);cursor:pointer}';
        echo 'button[disabled]{opacity:.55}.g-err{display:none;margin-top:12px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);color:#fca5a5;border-radius:10px;padding:10px 12px;font-size:.9rem}';
        echo '.g-foot{margin-top:16px;text-align:center;font-size:.85rem}.g-foot a{color:#38bdf8;text-decoration:none}';
        echo '</style></head><body><main class="g-card">';
        echo '<h1>🏫 Masuk Kelas 3D</h1><p>Isi kode room dari gurumu dan namamu. Di dalam kelas kamu bisa chat dan ngobrol pakai mikrofon.</p>';
        echo '<form id="gForm" novalidate>';
        echo '<label for="gCode">Kode Kelas / Room</label><input id="gCode" maxlength="12" autocomplete="off" autocapitalize="characters" placeholder="contoh: C1B4CA06" value="' . $prefCode . '" required>';
        echo '<label for="gName">Nama Kamu</label><input id="gName" maxlength="40" autocomplete="name" placeholder="contoh: Budi Santoso" value="' . $prefName . '" required>';
        echo '<div id="gErr" class="g-err" role="alert"></div>';
        echo '<button id="gBtn" type="submit">Masuk ke Kelas 3D</button></form>';
        echo '<div class="g-foot">Punya akun sekolah? <a href="/login?redirect=/gabung">Login di sini</a></div>';
        echo '</main><script nonce="' . $nonce . '">';
        echo <<<'JS'
(function(){
  var csrf=(document.querySelector('meta[name=csrf]')||{}).content||'';
  var f=document.getElementById('gForm'),c=document.getElementById('gCode'),n=document.getElementById('gName'),b=document.getElementById('gBtn'),e=document.getElementById('gErr');
  function err(t){e.textContent=t;e.style.display='block';}
  c.addEventListener('input',function(){c.value=c.value.toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,12);});
  f.addEventListener('submit',async function(ev){
    ev.preventDefault();e.style.display='none';
    var code=c.value.trim(),name=n.value.trim();
    if(code.length<3){err('Masukkan kode room dari guru.');c.focus();return;}
    if(name.length<2){err('Masukkan namamu (minimal 2 huruf).');n.focus();return;}
    b.disabled=true;b.textContent='Masuk…';
    try{
      var r=await fetch('/api/gabung',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF':csrf},body:JSON.stringify({code:code,name:name,_csrf:csrf})});
      var d=await r.json();
      if(r.ok&&d.status==='ok'){location.href=d.redirect||('/dunia?server='+d.server_id);return;}
      err(d.message||'Gagal masuk. Cek kode room.');
    }catch(x){err('Koneksi terputus. Coba lagi.');}
    b.disabled=false;b.textContent='Masuk ke Kelas 3D';
  });
  (c.value?n:c).focus();
})();
JS;
        echo '</script></body></html>';
    }

    /**
     * /kumpul — guru/ROOT mengatur jadwal kumpul per server: jam pelajaran + ruangan tujuan.
     * Saat jamnya tiba, murid di server itu (3D & Pixel) dipanggil & diarahkan ke ruangan; kehadiran tercatat.
     */
    public static function renderGatherPage(): void
    {
        $user = Auth::user();
        if ($user === null) {
            header('Location: /login?redirect=/kumpul');
            exit;
        }
        $isStaffAll = in_array($user['role'], ['root', 'kepala_sekolah', 'kepala_program'], true);
        if (!$isStaffAll && !in_array($user['role'], ['guru', 'wali_kelas'], true)) {
            http_response_code(403);
            echo 'Halaman ini khusus guru & admin.';
            return;
        }
        $nonce = Headers::nonce();
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES);
        $servers = \VC\Domain\GameServers::assignableFor((int)$user['id'], $isStaffAll);
        $sid = (int)($_GET['server'] ?? ($servers[0]['id'] ?? 0));
        $current = null;
        foreach ($servers as $sv) {
            if ((int)$sv['id'] === $sid) {
                $current = $sv;
            }
        }
        $sessions = $current ? \VC\Domain\ServerSessions::forServer($sid) : [];
        $locs = \VC\Domain\ServerSessions::locations();
        $today = Schedule::nowLocal('Y-m-d');
        $dow = (int)Schedule::nowLocal('N');

        \VC\View\Shell::start(['title' => 'Jadwal Kumpul Server — Virtual City', 'active' => 'admin', 'user' => $user,
            'head' => '<style nonce="' . $nonce . '">'
            . '.gk{max-width:920px;margin:0 auto;padding:12px;display:flex;flex-direction:column;gap:12px}.gk .px-panel{padding:14px 16px!important}'
            . '.gk h1{margin:0;font-size:clamp(22px,4vw,28px)}.gk h2{margin:0 0 10px;font-size:18px}.gk-lead{margin:4px 0 0;opacity:.85}'
            . '.gk-srv{display:flex;gap:8px;flex-wrap:wrap}.gk-srv a{padding:8px 12px;border:2px solid rgba(255,255,255,.15);text-decoration:none;color:inherit;background:rgba(0,0,0,.2)}.gk-srv a.on{border-color:#d9a441;background:rgba(217,164,65,.2)}'
            . '.gk-form [hidden]{display:none!important}.gk-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.gk-form label{display:flex;flex-direction:column;gap:4px;font-size:14px}.gk-form input,.gk-form select{font-size:16px}'
            . '.gk-list{display:flex;flex-direction:column;gap:8px}.gk-item{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px;background:rgba(0,0,0,.22);border:2px solid rgba(255,255,255,.08)}'
            . '.gk-item.is-today{border-color:#4ade80}.gk-item div{flex:1;min-width:200px}.gk-item small{display:block;opacity:.8}.gk-att{width:100%;font-size:14px;opacity:.9}.gk-msg{min-height:1.2em}'
            . '</style>']);
        echo '<div class="gk">';
        echo '<section class="px-panel"><h1>📢 Jadwal Kumpul Server</h1><p class="gk-lead">Atur jam pelajaran & ruangan. Saat waktunya tiba, semua murid di server itu mendapat panggilan <b>“Kumpul di …”</b> beserta penunjuk arah di Dunia 3D & Pixel, dan kehadiran tercatat otomatis begitu mereka masuk ruangan.</p></section>';
        if ($servers === []) {
            echo '<section class="px-panel"><p>Belum ada server yang bisa kamu atur. Buat server dulu di <a href="/admin#server-section">Konsol → Server</a>.</p></section>';
        } else {
            echo '<section class="px-panel"><h2>1️⃣ Pilih server</h2><div class="gk-srv">';
            foreach ($servers as $sv) {
                echo '<a class="' . ((int)$sv['id'] === $sid ? 'on' : '') . '" href="/kumpul?server=' . (int)$sv['id'] . '">' . ($sv['world'] === 'pixel' ? '🧱 ' : '🚀 ') . $e($sv['name'])
                    . ($sv['class_name'] ? ' · ' . $e($sv['class_name']) : '') . ' <small>(' . (int)$sv['member_count'] . ' anggota)</small></a>';
            }
            echo '</div></section>';
        }
        if ($current) {
            echo '<section class="px-panel"><h2>2️⃣ Tambah jadwal kumpul — ' . $e($current['name']) . '</h2>';
            echo '<form class="gk-form" id="gkForm"><input type="hidden" name="server_id" value="' . $sid . '">';
            echo '<label>Pelajaran / kegiatan<input name="title" maxlength="80" required placeholder="mis. Matematika, Upacara, Praktikum Jaringan"></label>';
            echo '<label>Ruangan kumpul<select name="room_id" required>';
            $byB = [];
            foreach ($locs as $id => $l) {
                $byB[$l['building'] !== '' ? 'Gedung ' . $l['building'] : 'Luar ruangan'][$id] = $l;
            }
            ksort($byB);
            foreach ($byB as $grp => $items) {
                echo '<optgroup label="' . $e($grp) . '">';
                foreach ($items as $id => $l) {
                    echo '<option value="' . $e($id) . '">' . $e($l['name']) . ($l['floor'] > 1 ? ' · Lt. ' . $l['floor'] : '') . '</option>';
                }
                echo '</optgroup>';
            }
            echo '</select></label>';
            echo '<label>Ulangi<select name="mode" id="gkMode"><option value="week">Setiap minggu</option><option value="date">Sekali (tanggal tertentu)</option></select></label>';
            echo '<label id="gkDow">Hari<select name="day_of_week">';
            foreach (\VC\Domain\ServerSessions::DAYS as $k => $d) {
                echo '<option value="' . $k . '"' . ($k === $dow ? ' selected' : '') . '>' . $d . '</option>';
            }
            echo '</select></label>';
            echo '<label id="gkDate" hidden>Tanggal<input type="date" name="on_date" value="' . $e($today) . '"></label>';
            echo '<label>Mulai<input type="time" name="start_time" required value="07:30"></label>';
            echo '<label>Selesai<input type="time" name="end_time" required value="09:00"></label>';
            echo '<div style="display:flex;align-items:flex-end"><button type="submit" class="px-btn px-btn--gold">💾 Simpan & beri tahu murid</button></div>';
            echo '</form><p class="gk-msg" id="gkMsg" aria-live="polite"></p></section>';

            echo '<section class="px-panel"><h2>3️⃣ Jadwal server ini</h2><div class="gk-list">';
            if ($sessions === []) {
                echo '<p style="opacity:.75">Belum ada jadwal kumpul.</p>';
            }
            foreach ($sessions as $ss) {
                $isToday = $ss['on_date'] === $today || (!$ss['on_date'] && (int)$ss['day_of_week'] === $dow);
                echo '<div class="gk-item' . ($isToday ? ' is-today' : '') . '"><div><b>' . $e($ss['title']) . '</b><small>📍 ' . $e($ss['room_name']) . ' · 🗓️ ' . $e($ss['when']) . ' · ⏰ ' . $e($ss['start_time']) . '–' . $e($ss['end_time']) . '</small></div>'
                    . '<button type="button" class="px-btn px-btn--dark" data-del="' . (int)$ss['id'] . '">🗑 Hapus</button>';
                if ($isToday) {
                    $att = \VC\Domain\ServerSessions::attendance((int)$ss['id'], $today);
                    echo '<p class="gk-att">✅ Hadir hari ini (' . count($att) . '): ' . ($att === [] ? '<i>belum ada</i>' : implode(', ', array_map(static fn($a) => htmlspecialchars($a['name'] . ' ' . substr((string)$a['arrived_at'], 11, 5), ENT_QUOTES), $att))) . '</p>';
                }
                echo '</div>';
            }
            echo '</div></section>';
        }
        echo '</div>';
        echo '<script nonce="' . $nonce . '">
(function () {
  var CSRF = (document.querySelector("meta[name=csrf]") || {}).content || "";
  function post(b) { return fetch("/api/servers/session/action", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF": CSRF }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  var mode = document.getElementById("gkMode");
  if (mode) mode.addEventListener("change", function () { var d = mode.value === "date"; document.getElementById("gkDate").hidden = !d; document.getElementById("gkDow").hidden = d; });
  var f = document.getElementById("gkForm"), msg = document.getElementById("gkMsg");
  if (f) f.addEventListener("submit", function (e) {
    e.preventDefault();
    var b = { action: "save" };
    ["server_id", "title", "room_id", "day_of_week", "on_date", "start_time", "end_time"].forEach(function (k) { b[k] = f.elements[k].value; });
    if (mode.value === "date") b.day_of_week = 0; else b.on_date = "";
    post(b).then(function (d) { msg.textContent = d.message || ""; msg.style.color = d.status === "ok" ? "#9fe08a" : "#ffb4a8"; if (d.status === "ok") setTimeout(function () { location.reload(); }, 700); });
  });
  document.addEventListener("click", function (e) {
    var id = e.target.getAttribute && e.target.getAttribute("data-del");
    if (!id || !confirm("Hapus jadwal kumpul ini?")) return;
    post({ action: "delete", id: parseInt(id, 10) }).then(function (d) { if (d.status === "ok") location.reload(); else alert(d.message || "Gagal"); });
  });
})();
</script>';
        \VC\View\Shell::end(['active' => 'admin']);
    }
}
