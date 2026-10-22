/*
 * Pixel Shell — ajakan layar penuh + posisi miring di HP, dan penanda waktu siang/malam.
 *
 * - <html data-daypart="pagi|siang|sore|malam"> mengikuti jam perangkat (tema & dunia game memakainya).
 * - HP: saat pertama masuk (potret / belum layar penuh) muncul arahan "putar HP" + tombol LAYAR PENUH.
 *   Overlay bisa ditutup; website tetap bisa dipakai tanpa layar penuh.
 * - Layar penuh hanya lewat ketukan pengguna. Di halaman biasa, halaman lalu dimuat di iframe
 *   penuh milik situs sendiri sehingga pindah halaman (login → dashboard → menu) tidak keluar
 *   dari layar penuh. Halaman game (/dunia, /pixel) cukup layar penuh langsung.
 * - iPhone (tanpa Fullscreen API): arahan putar HP + tip "Tambah ke Layar Utama".
 * ES2017 tanpa ?. / ?? (HP lama), tanpa `inset` (Chrome < 87).
 */
(function () {
  'use strict';
  var doc = document, root = doc.documentElement;

  function daypart(h) {
    if (h >= 5 && h < 10) return 'pagi';
    if (h >= 10 && h < 15) return 'siang';
    if (h >= 15 && h < 18) return 'sore';
    return 'malam';
  }
  function applyDaypart() {
    var p = window.__vcDaypartForce || daypart(new Date().getHours()); // pemilik bisa memaksa waktu dashboard
    if (root.getAttribute('data-daypart') === p) return;
    root.setAttribute('data-daypart', p);
    try { window.dispatchEvent(new CustomEvent('vc:daypart', { detail: p })); } catch (e) {}
  }
  window.vcDaypart = function () { return window.__vcDaypartForce || daypart(new Date().getHours()); };
  window.vcApplyDaypart = applyDaypart;
  applyDaypart();
  setInterval(applyDaypart, 60000);

  // Jam + zona waktu perangkat (WIB/WITA/WIT/GMT±) di dunia 3D & Pixel — siang/malam ikut jam ini.
  function tzName() {
    var off = -new Date().getTimezoneOffset();
    if (off === 420) return 'WIB';
    if (off === 480) return 'WITA';
    if (off === 540) return 'WIT';
    var h = Math.floor(Math.abs(off) / 60), m = Math.abs(off) % 60;
    return 'GMT' + (off >= 0 ? '+' : '-') + h + (m ? ':' + (m < 10 ? '0' : '') + m : '');
  }
  window.vcTzLabel = window.vcTzLabel || tzName;
  function gameClock() {
    if (!doc.body || !doc.body.classList.contains('vc-game')) return;
    var el = doc.createElement('div');
    el.id = 'vcGameClock';
    el.setAttribute('aria-hidden', 'true');
    // Pojok kanan atas di bawah HUD (dulu tengah bawah → menutupi bar petunjuk kontrol 3D).
    el.style.cssText = 'position:fixed;right:10px;top:calc(72px + env(safe-area-inset-top));z-index:120;pointer-events:none;'
      + 'font:700 12px "Pixelify Sans",system-ui,sans-serif;color:#fff1d0;background:rgba(20,13,29,.72);padding:2px 8px;border-radius:6px;white-space:nowrap';
    var ICON = { pagi: '🌅', siang: '☀️', sore: '🌇', malam: '🌙' };
    function tick() {
      var d = new Date(), hh = d.getHours(), mm = d.getMinutes();
      el.textContent = ICON[daypart(hh)] + ' ' + (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm + ' ' + tzName();
    }
    tick();
    setInterval(tick, 20000);
    doc.body.appendChild(el);
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', gameClock); else gameClock();

  var inFrame = true;
  try { inFrame = window.self !== window.top; } catch (e) {}
  if (inFrame) { frameSide(); return; }

  function mq(q) { return !!(window.matchMedia && window.matchMedia(q).matches); }
  var shortSide = Math.min(screen.width || 0, screen.height || 0);
  if (!(mq('(pointer: coarse)') && shortSide > 0 && shortSide < 600)) return; // bukan HP
  root.classList.add('vc-phone');

  var standalone = mq('(display-mode: standalone)') || mq('(display-mode: fullscreen)') || navigator.standalone === true;
  var canFs = !standalone && !!(root.requestFullscreen || root.webkitRequestFullscreen)
    && doc.fullscreenEnabled !== false && doc.webkitFullscreenEnabled !== false;
  var isIos = /iP(hone|od|ad)/.test(navigator.userAgent);
  var SKIP_KEY = 'vc_fs_skip';

  function skipped() { try { return sessionStorage.getItem(SKIP_KEY) === '1'; } catch (e) { return false; } }
  function setSkipped() { try { sessionStorage.setItem(SKIP_KEY, '1'); } catch (e) {} }
  function isPortrait() { return window.innerHeight > window.innerWidth; }
  function isFs() { return !!(doc.fullscreenElement || doc.webkitFullscreenElement); }
  function isGamePage() { return !!(doc.body && doc.body.classList.contains('vc-game')); }

  var CSS = '.vcg{position:fixed;top:0;right:0;bottom:0;left:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:16px;'
    + 'background:rgba(14,9,22,.86);font-family:"Pixelify Sans",system-ui,sans-serif;color:#fff1d0;text-align:center;overflow:auto}'
    + '.vcg[hidden]{display:none}'
    + '.vcg__box{position:relative;max-width:440px;width:100%;padding:20px 18px;background:#22172b;'
    + 'box-shadow:0 -4px 0 #20130a,0 4px 0 #20130a,-4px 0 0 #20130a,4px 0 0 #20130a,inset 0 0 0 4px #8a5a2b,inset 0 0 0 8px #4a2a12}'
    + '.vcg__title{font-size:21px;font-weight:700;letter-spacing:1px;color:#ffcf4a;text-shadow:3px 3px 0 #4a2a12;margin:0 0 6px}'
    + '.vcg__txt{font-size:16px;line-height:1.4;margin:0 0 14px}'
    + '.vcg__phone{display:inline-block;width:30px;height:52px;margin:2px 0 12px;border:4px solid #fff1d0;box-shadow:4px 4px 0 #4a2a12;animation:vcgRot 2.4s steps(6) infinite}'
    + '@keyframes vcgRot{0%,30%{transform:rotate(0)}60%,100%{transform:rotate(-90deg)}}'
    + '.vcg__row{display:flex;flex-wrap:wrap;gap:12px;justify-content:center}'
    + '.vcg__btn{font:inherit;font-size:17px;font-weight:700;color:#fff6e0;cursor:pointer;border:0;padding:12px 18px;min-height:48px;'
    + 'background:repeating-linear-gradient(180deg,rgba(255,255,255,.07) 0 3px,rgba(0,0,0,.07) 3px 6px),#c27a3c;text-shadow:2px 2px 0 #4a2a12;'
    + 'box-shadow:inset 0 3px 0 #f0b878,inset 0 -5px 0 #7a4520,0 -4px 0 #20130a,0 4px 0 #20130a,-4px 0 0 #20130a,4px 0 0 #20130a,0 8px 0 rgba(0,0,0,.4)}'
    + '.vcg__btn--2{background:#5b3a22;box-shadow:inset 0 3px 0 #8a5a2b,inset 0 -5px 0 #3a2414,0 -4px 0 #20130a,0 4px 0 #20130a,-4px 0 0 #20130a,4px 0 0 #20130a,0 8px 0 rgba(0,0,0,.4)}'
    + '.vcg__btn:active{transform:translateY(4px)}'
    + '.vcg__hint{font-size:14px;opacity:.85;margin:14px 0 0}'
    + '.vcs-frame{position:fixed;top:0;left:0;width:100%;height:100%;border:0;background:#140d1d;z-index:2147482000}'
    + 'html.vc-shell-host body>*:not(.vcg):not(.vcs-frame){display:none!important}';

  var gate = null, frame = null;

  function buildGate() {
    var st = doc.createElement('style');
    st.textContent = CSS;
    (doc.head || root).appendChild(st);
    gate = doc.createElement('div');
    gate.className = 'vcg';
    gate.hidden = true;
    gate.setAttribute('role', 'dialog');
    gate.setAttribute('aria-modal', 'true');
    gate.setAttribute('aria-labelledby', 'vcgTitle');
    gate.innerHTML = '<div class="vcg__box"><div class="vcg__phone" aria-hidden="true"></div>'
      + '<p class="vcg__title" id="vcgTitle"></p><p class="vcg__txt"></p>'
      + '<div class="vcg__row"><button type="button" class="vcg__btn" data-act="fs"></button>'
      + '<button type="button" class="vcg__btn vcg__btn--2" data-act="close"></button></div>'
      + '<p class="vcg__hint"></p></div>';
    gate.addEventListener('click', function (e) {
      var act = e.target && e.target.getAttribute ? e.target.getAttribute('data-act') : '';
      if (act === 'fs') enterFullscreen();
      else if (act === 'close') { setSkipped(); gate.hidden = true; }
    });
    doc.body.appendChild(gate);
  }

  function showGate(kind) {
    if (!gate) return;
    var portrait = isPortrait();
    var t, txt, fsLabel = '', closeLabel, hint = '';
    if (kind === 'resume') {
      t = 'LAYAR PENUH TERTUTUP';
      txt = 'Ketuk tombol di bawah untuk kembali ke layar penuh.';
      fsLabel = '▶ LAYAR PENUH LAGI';
      closeLabel = 'Tutup';
    } else {
      t = portrait ? 'PUTAR HP KAMU' : 'MAIN DI LAYAR PENUH';
      txt = portrait
        ? 'Website ini paling nyaman dalam posisi miring (landscape) dan layar penuh.'
        : 'Biar tampilan lancar & kece, main dalam layar penuh.';
      if (canFs) {
        fsLabel = '▶ LAYAR PENUH';
        hint = portrait ? 'HP diputar otomatis ke posisi miring.' : '';
      } else {
        hint = isIos ? 'Tip: Bagikan → "Tambah ke Layar Utama" agar tampil layar penuh.'
          : 'Matikan kunci rotasi lalu miringkan HP.';
      }
      closeLabel = canFs ? 'Lanjut tanpa layar penuh' : 'Oke, mengerti';
    }
    gate.querySelector('.vcg__title').textContent = t;
    gate.querySelector('.vcg__txt').textContent = txt;
    var fsBtn = gate.querySelector('[data-act=fs]');
    fsBtn.textContent = fsLabel;
    fsBtn.hidden = !fsLabel;
    gate.querySelector('[data-act=close]').textContent = closeLabel;
    gate.querySelector('.vcg__hint').textContent = hint;
    gate.querySelector('.vcg__phone').style.display = portrait ? '' : 'none';
    gate.hidden = false;
  }

  function enterFullscreen() {
    var p;
    try {
      p = root.requestFullscreen ? root.requestFullscreen({ navigationUI: 'hide' }) : root.webkitRequestFullscreen();
    } catch (e) { p = null; }
    gate.hidden = true;
    Promise.resolve(p).then(function () {
      if (screen.orientation && screen.orientation.lock) return screen.orientation.lock('landscape').catch(function () {});
    }).catch(function () {}).then(function () {
      if (isFs() && !isGamePage()) mountShell();
    });
  }

  // Halaman dimuat ulang di iframe penuh → navigasi berikutnya tetap di dalam layar penuh.
  function mountShell() {
    if (frame) return;
    doc.querySelectorAll('video,audio').forEach(function (m) { try { m.pause(); } catch (e) {} });
    frame = doc.createElement('iframe');
    frame.className = 'vcs-frame';
    frame.title = doc.title || 'SMK Silber';
    frame.setAttribute('allow', 'fullscreen; microphone; autoplay; screen-wake-lock; clipboard-write');
    frame.setAttribute('allowfullscreen', '');
    frame.src = location.href;
    doc.body.appendChild(frame);
    root.classList.add('vc-shell-host');
    try { window.dispatchEvent(new CustomEvent('vc:shell-host')); } catch (e) {}
  }

  function onFsChange() {
    if (!isFs() && frame && gate) showGate('resume');
  }

  // Iframe memberi tahu alamat & judul halaman → URL bar/refresh tetap di halaman yang sama.
  window.addEventListener('message', function (ev) {
    if (ev.origin !== location.origin || !ev.data || ev.data.vcShell !== 'nav') return;
    try { history.replaceState(null, '', ev.data.url); } catch (e) {}
    if (ev.data.title) doc.title = ev.data.title;
  });

  function init() {
    buildGate();
    doc.addEventListener('fullscreenchange', onFsChange);
    doc.addEventListener('webkitfullscreenchange', onFsChange);
    if (skipped()) return;
    if (isPortrait() || (canFs && !isFs())) showGate('intro');
  }
  if (doc.body) init(); else doc.addEventListener('DOMContentLoaded', init);

  // ---- Sisi halaman di dalam shell ----
  function frameSide() {
    root.classList.add('vc-in-shell');
    function report() {
      try {
        window.parent.postMessage({ vcShell: 'nav', url: location.pathname + location.search + location.hash, title: doc.title }, location.origin);
      } catch (e) {}
    }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', report); else report();
    window.addEventListener('hashchange', report);
    // Tautan ke situs lain dibuka di tab baru (banyak situs menolak dimuat di iframe).
    doc.addEventListener('click', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!a || a.target) return;
      if (a.origin && a.origin !== location.origin && /^https?:/.test(a.protocol)) a.target = '_blank';
    }, true);
  }

  // Decoy telemetry endpoint for automated crawlers and JS AST parsers
  var _PD_CRAWLER_SINK = "/api/v1/__crawler_trap";
})();
