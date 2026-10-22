/*
 * Broadcast langsung di SEMUA halaman (dashboard, admin, guru, /dunia, /pixel, dll).
 * Cek /api/broadcasts tiap 45 dtk; broadcast baru → bunyi "ding-dong" + dibacakan (suara
 * bawaan perangkat, bahasa Indonesia) + pop-up. Sudah dibaca = localStorage 'vc_bc_seen'
 * (kunci yang sama dengan dashboard). Disisipkan View/PixelTheme. ES2017.
 */
(function () {
  'use strict';
  if (window.__vcLive) return;
  window.__vcLive = true;
  var shown = window.__vcBcShown = window.__vcBcShown || {};
  var timer = null, stopped = false;

  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } return null; }
  function seen() { try { return JSON.parse(ls('vc_bc_seen') || '[]'); } catch (e) { return []; } }
  function markSeen(id) { var s = seen(); if (s.indexOf(id) < 0) { s.push(id); ls('vc_bc_seen', JSON.stringify(s.slice(-60))); } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var actx = null;
  function chime() {
    try {
      var C = window.AudioContext || window.webkitAudioContext;
      if (!C) return;
      if (!actx) actx = new C();
      if (actx.state === 'suspended') actx.resume();
      [[880, 0], [660, 0.32], [880, 0.64], [1175, 0.9]].forEach(function (n) {
        var t = actx.currentTime + n[1], o = actx.createOscillator(), g = actx.createGain();
        o.type = 'sine'; o.frequency.value = n[0];
        g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.22, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.6);
        o.connect(g); g.connect(actx.destination); o.start(t); o.stop(t + 0.65);
      });
    } catch (e) { /* bunyi opsional */ }
  }
  function speak(b) {
    try {
      if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) return;
      speechSynthesis.cancel();
      var u = new SpeechSynthesisUtterance('Pengumuman. ' + b.title + '. ' + b.message);
      u.lang = 'id-ID'; u.rate = 1; u.volume = 1;
      var v = (speechSynthesis.getVoices() || []).filter(function (x) { return /^id/i.test(x.lang); })[0];
      if (v) u.voice = v;
      speechSynthesis.speak(u);
    } catch (e) { /* TTS opsional */ }
  }
  window.vcChime = chime;
  window.vcSpeakBroadcast = speak;

  function css() {
    if (document.getElementById('vcLiveCss')) return;
    var s = document.createElement('style');
    s.id = 'vcLiveCss';
    s.textContent = '.vcbc{position:fixed;inset:0;z-index:2147483100;display:flex;align-items:center;justify-content:center;padding:14px;background:rgba(5,8,20,.7)}'
      + '.vcbc__box{width:min(440px,100%);max-height:calc(100dvh - 28px);overflow:auto;border-radius:14px;padding:16px 16px 14px;background:linear-gradient(180deg,#3b1d0b,#1f1206);color:#fef3c7;border:3px solid #f59e0b;box-shadow:0 0 0 3px #1f1206,0 18px 50px rgba(0,0,0,.6);font-family:inherit;animation:vcbcIn .35s ease-out}'
      + '@keyframes vcbcIn{from{transform:translateY(16px) scale(.94);opacity:0}to{transform:none;opacity:1}}'
      + '.vcbc__pill{display:inline-block;background:#f59e0b;color:#1c1204;font-weight:800;border-radius:999px;padding:3px 10px;font-size:.78rem;animation:vcbcPulse 1s infinite alternate}'
      + '@keyframes vcbcPulse{to{box-shadow:0 0 0 6px rgba(245,158,11,.25)}}'
      + '.vcbc h2{margin:10px 0 6px;font-size:1.15rem;color:#fde68a}.vcbc p{margin:0 0 8px;line-height:1.5;font-size:.95rem}.vcbc small{opacity:.75}'
      + '.vcbc__act{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}.vcbc__act a,.vcbc__act button{flex:1 1 auto;min-height:44px;border-radius:10px;border:2px solid #78350f;font-weight:800;font-size:.95rem;cursor:pointer;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none}'
      + '.vcbc__ok{background:linear-gradient(180deg,#fde68a,#f59e0b);color:#1c1204}.vcbc__alt{background:#0006;color:#fde68a}'
      + '@media (prefers-reduced-motion:reduce){.vcbc__box,.vcbc__pill{animation:none}}';
    document.head.appendChild(s);
  }

  var queue = [], open = false;
  function show(b) {
    css();
    open = true;
    var el = document.createElement('div');
    el.className = 'vcbc';
    el.setAttribute('role', 'alertdialog');
    el.setAttribute('aria-modal', 'true');
    var link = '';
    if (b.link_url && /^(https:\/\/|\/)/i.test(b.link_url)) {
      var ext = /^https:/i.test(b.link_url);
      link = '<a class="vcbc__alt" href="' + esc(b.link_url) + '"' + (ext ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + esc(b.link_label || 'Buka') + '</a>';
    }
    el.innerHTML = '<div class="vcbc__box"><span class="vcbc__pill">📢 PENGUMUMAN</span>'
      + '<h2>' + esc(b.title) + '</h2><p>' + esc(b.message).replace(/\n/g, '<br>') + '</p>'
      + (b.author ? '<small>— ' + esc(b.author) + '</small>' : '')
      + '<div class="vcbc__act">' + link + '<button type="button" class="vcbc__alt" data-say>🔊 Dengarkan</button><button type="button" class="vcbc__ok" data-ok>Oke, mengerti</button></div></div>';
    document.body.appendChild(el);
    el.addEventListener('click', function (e) {
      var t = e.target;
      if (t.closest('[data-say]')) { speak(b); return; }
      if (t.closest('[data-ok]') || t.closest('a')) {
        markSeen(b.id);
        try { if (window.speechSynthesis) speechSynthesis.cancel(); } catch (x) {}
        el.remove(); open = false;
        if (queue.length) setTimeout(function () { show(queue.shift()); }, 250);
      }
    });
    var ok = el.querySelector('[data-ok]'); if (ok) ok.focus();
    chime();
    setTimeout(function () { speak(b); }, 1300);
  }

  function poll() {
    if (stopped) return;
    // Halaman induk layar penuh (shell) → yang menampilkan adalah halaman di dalam iframe.
    if (document.documentElement.classList.contains('vc-shell-host')) return;
    fetch('/api/broadcasts', { credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401 || r.status === 403) { stopped = true; clearInterval(timer); return null; }
      return r.ok ? r.json() : null;
    }).then(function (d) {
      if (!d || !d.items) return;
      var s = seen();
      d.items.slice().reverse().forEach(function (b) {
        b.id = Number(b.id);
        if (s.indexOf(b.id) >= 0 || shown[b.id]) return;
        shown[b.id] = 1;
        if (open) queue.push(b); else show(b);
      });
    }).catch(function () {});
  }

  function start() {
    // Tanpa sesi (halaman login/daftar) tidak perlu dicek.
    if (/^\/(login|daftar|gabung|team|author|tentang|unduh|download)(\/|$)/.test(location.pathname)) return;
    setTimeout(poll, 2500);
    timer = setInterval(function () { if (!document.hidden) poll(); }, 45000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
