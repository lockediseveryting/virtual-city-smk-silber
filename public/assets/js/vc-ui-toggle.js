/*
 * Mode Bebas (Dunia 3D & Pixel): sembunyikan SEMUA tombol/panel supaya dunia terasa seperti game,
 * tampilkan lagi kapan saja. Tombol 👁 kecil di pojok + tombol H (laptop). Kontrol gerak di HP
 * (joystick / ◀ ▶ / aksi) tetap ada tapi transparan, supaya tetap bisa bermain. Disimpan per perangkat.
 * ES2017.
 */
(function () {
  'use strict';
  if (window.vcUiToggle) return;
  var KEY = 'vc_ui_hidden';
  var css = ''
    + '.vcui-eye{position:fixed;z-index:2147482000;right:max(10px,env(safe-area-inset-right));top:calc(env(safe-area-inset-top) + var(--vcui-top,58px));width:40px;height:40px;border-radius:50%;border:2px solid #d9a441;background:rgba(32,20,12,.82);color:#fde68a;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.35);transition:opacity .2s}'
    + 'body.vc-ui-hidden .vcui-eye{opacity:.55;top:calc(env(safe-area-inset-top) + 10px)}'
    + 'body.vc-ui-hidden .vcui-eye:hover{opacity:1}'
    // 3D
    + 'body.vc-ui-hidden #topHud,body.vc-ui-hidden #objectiveCompass,body.vc-ui-hidden #pttBtn,body.vc-ui-hidden #zoomCtl,body.vc-ui-hidden .desktop-hint,'
    + 'body.vc-ui-hidden #minimapWrapper,body.vc-ui-hidden #hudShowBtn,body.vc-ui-hidden #worldLiveChatHud,body.vc-ui-hidden .app-footer--game,'
    + 'body.vc-ui-hidden #emote3dPanel,body.vc-ui-hidden .dq-chip,body.vc-ui-hidden #vcNavBox,body.vc-ui-hidden .world-clock,'
    // Pixel
    + 'body.vc-ui-hidden .pixhud,body.vc-ui-hidden #pixTalk,body.vc-ui-hidden #pixEmoteAll,body.vc-ui-hidden #pixSide'
    + '{opacity:0!important;pointer-events:none!important;visibility:hidden!important}'
    // kontrol gerak HP tetap bisa dipakai (transparan)
    + 'body.vc-ui-hidden #touchJoystickZone,body.vc-ui-hidden .mobile-action-bar,body.vc-ui-hidden #pxsPad button,body.vc-ui-hidden #pixAction,body.vc-ui-hidden #pixJoy{opacity:.32!important}'
    + '.vcui-toast{position:fixed;left:50%;top:18px;transform:translateX(-50%);z-index:2147482001;background:rgba(32,20,12,.9);color:#fde68a;border:2px solid #d9a441;border-radius:999px;padding:6px 14px;font:700 13px inherit;pointer-events:none;transition:opacity .4s}';

  function set(hidden, quiet) {
    document.body.classList.toggle('vc-ui-hidden', hidden);
    try { localStorage.setItem(KEY, hidden ? '1' : '0'); } catch (e) {}
    var b = document.querySelector('.vcui-eye');
    if (b) { b.textContent = hidden ? '👁' : '🙈'; b.title = hidden ? 'Tampilkan semua tombol (H)' : 'Sembunyikan semua tombol (H)'; b.setAttribute('aria-pressed', hidden ? 'true' : 'false'); }
    if (!quiet) {
      var t = document.createElement('div');
      t.className = 'vcui-toast';
      t.textContent = hidden ? '🎬 Mode bebas — tekan 👁 / H untuk tombol' : '🧭 Semua tombol tampil';
      document.body.appendChild(t);
      setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 450); }, 1600);
    }
  }
  function toggle() { set(!document.body.classList.contains('vc-ui-hidden')); }
  window.vcUiToggle = { toggle: toggle, set: set };

  function start() {
    var s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'vcui-eye';
    var coarse = window.matchMedia && matchMedia('(max-width: 900px), (pointer: coarse)').matches;
    b.style.setProperty('--vcui-top', location.pathname.indexOf('/pixel') === 0 ? '52px' : (coarse ? '160px' : '84px')); // HP 3D: di bawah radar
    b.addEventListener('click', toggle);
    document.body.appendChild(b);
    document.addEventListener('keydown', function (e) {
      if ((e.key === 'h' || e.key === 'H') && !e.ctrlKey && !e.metaKey && !/INPUT|TEXTAREA|SELECT/.test((e.target && e.target.tagName) || '')) toggle();
    });
    var on = false; try { on = localStorage.getItem(KEY) === '1'; } catch (e) {}
    set(on, true);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
