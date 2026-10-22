/*
 * Gacha harian — mesin kapsul pixel. Buka: vcGacha.open() (dashboard 🎰, /toko).
 * 1× gratis per 24 jam (hitung mundur), selanjutnya 50 koin. Hadiah dihitung server (/api/gacha/spin).
 * ES2017, tanpa pustaka.
 */
(function () {
  'use strict';
  var RARITY = {
    common: ['Biasa', '#94a3b8'], uncommon: ['Tidak Biasa', '#4ade80'], rare: ['Langka', '#38bdf8'],
    epic: ['Epik', '#c084fc'], legendary: ['LEGENDARIS', '#facc15']
  };
  var box = null, st = null, busy = false, tick = null;

  function csrf() { var m = document.querySelector('meta[name="csrf"]'); return m ? m.content : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(sec) {
    var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  var actx = null;
  function beep(freq, dur, when, type) {
    try {
      var C = window.AudioContext || window.webkitAudioContext;
      if (!C) return;
      if (!actx) actx = new C();
      var t = actx.currentTime + (when || 0), o = actx.createOscillator(), g = actx.createGain();
      o.type = type || 'square'; o.frequency.value = freq;
      g.gain.setValueAtTime(0.06, t); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      o.connect(g); g.connect(actx.destination); o.start(t); o.stop(t + dur + 0.02);
    } catch (e) { /* suara opsional */ }
  }
  function fanfare(r) {
    var notes = r === 'legendary' ? [523, 659, 784, 1047, 1319] : r === 'epic' || r === 'rare' ? [523, 659, 784, 1047] : [523, 784];
    notes.forEach(function (f, i) { beep(f, 0.18, i * 0.11); });
  }

  function css() {
    if (document.getElementById('gachaCss')) return;
    var s = document.createElement('style');
    s.id = 'gachaCss';
    s.textContent = [
      '.gx{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:12px;background:rgba(6,8,20,.78);backdrop-filter:blur(3px)}',
      '.gx[hidden]{display:none}',
      '.gx__box{width:min(420px,100%);max-height:calc(100dvh - 24px);overflow:auto;border-radius:14px;padding:16px;color:#fef3c7;background:linear-gradient(180deg,#3b1d0b,#1f1206);border:3px solid #f59e0b;box-shadow:0 0 0 3px #1f1206,0 18px 50px rgba(0,0,0,.6);text-align:center;font-family:inherit}',
      '.gx__head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}',
      '.gx__head h2{margin:0;font-size:1.15rem;letter-spacing:.04em;color:#fde68a;text-shadow:0 2px 0 #000}',
      '.gx__coins{font-size:.9rem;background:#000a;border:1px solid #f59e0b;border-radius:8px;padding:3px 8px}',
      '.gx__x{background:#0006;border:1px solid #f59e0b88;color:#fde68a;border-radius:8px;width:36px;height:36px;font-size:1rem;cursor:pointer}',
      '.gx__machine{position:relative;width:190px;height:220px;margin:6px auto 10px}',
      '.gx__dome{position:absolute;left:15px;top:0;width:160px;height:150px;border-radius:50% 50% 12px 12px;background:radial-gradient(circle at 35% 30%,#ffffff55,#ffffff10 45%,#0000 60%),#7dd3fc22;border:4px solid #e2e8f0;overflow:hidden}',
      '.gx__ball{position:absolute;width:30px;height:30px;border-radius:50%;border:2px solid #0008;background:linear-gradient(180deg,var(--c) 50%,#f8fafc 50%)}',
      '.gx--spin .gx__ball{animation:gxShake .25s infinite alternate}',
      '@keyframes gxShake{from{transform:translate(-4px,3px) rotate(-20deg)}to{transform:translate(4px,-5px) rotate(25deg)}}',
      '.gx__base{position:absolute;left:5px;bottom:0;width:180px;height:80px;border-radius:10px;background:linear-gradient(180deg,#dc2626,#991b1b);border:4px solid #450a0a}',
      '.gx__slot{position:absolute;left:68px;bottom:14px;width:44px;height:30px;border-radius:6px;background:#1c1917;border:3px solid #450a0a}',
      '.gx__knob{position:absolute;left:122px;bottom:36px;width:34px;height:34px;border-radius:50%;background:#fbbf24;border:3px solid #78350f;transition:transform .6s}',
      '.gx--spin .gx__knob{transform:rotate(720deg)}',
      '.gx__out{position:absolute;left:76px;bottom:16px;width:30px;height:30px;border-radius:50%;opacity:0;background:linear-gradient(180deg,var(--c,#facc15) 50%,#f8fafc 50%);border:2px solid #0008}',
      '.gx--drop .gx__out{animation:gxDrop .6s ease-out forwards}',
      '@keyframes gxDrop{0%{opacity:1;transform:translateY(-40px) scale(.6)}70%{transform:translateY(6px) scale(1.1)}100%{opacity:1;transform:translateY(0) scale(1)}}',
      '.gx__btns{display:flex;flex-direction:column;gap:8px}',
      '.gx__btn{min-height:48px;font-size:1rem;font-weight:800;border-radius:10px;border:3px solid #78350f;cursor:pointer;color:#1c1204;background:linear-gradient(180deg,#fde68a,#f59e0b);box-shadow:0 4px 0 #78350f}',
      '.gx__btn:active{transform:translateY(3px);box-shadow:0 1px 0 #78350f}',
      '.gx__btn[disabled]{filter:grayscale(.8);opacity:.6;cursor:not-allowed}',
      '.gx__btn--free{background:linear-gradient(180deg,#bbf7d0,#22c55e);border-color:#14532d;box-shadow:0 4px 0 #14532d}',
      '.gx__note{font-size:.78rem;color:#fcd34d;min-height:1.1em;margin:4px 0}',
      '.gx__prize{margin:8px 0 10px;padding:12px;border-radius:12px;border:2px solid var(--c);background:#000a;animation:gxPop .45s ease-out}',
      '.gx__prize b{display:block;font-size:1.2rem;color:var(--c);text-shadow:0 0 12px var(--c)}',
      '.gx__prize small{opacity:.85}',
      '@keyframes gxPop{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}',
      '.gx__odds{margin-top:10px;text-align:left;font-size:.74rem;background:#0006;border-radius:10px;padding:8px 10px}',
      '.gx__odds summary{cursor:pointer;font-weight:700}',
      '.gx__odds li{margin:2px 0}',
      '.gx__hist{margin:8px 0 0;padding:0;list-style:none;font-size:.74rem;text-align:left;opacity:.9}',
      // tema pixel global memaksa sudut 0 → bentuk bulat mesin kapsul dipulihkan
      '.gx .gx__ball,.gx .gx__out,.gx .gx__knob{border-radius:50%!important}.gx .gx__dome{border-radius:50% 50% 12px 12px!important}.gx .gx__box,.gx .gx__btn,.gx .gx__prize{border-radius:10px!important}',
      '@media (prefers-reduced-motion:reduce){.gx--spin .gx__ball,.gx--drop .gx__out,.gx__prize{animation:none}}'
    ].join('\n');
    document.head.appendChild(s);
  }

  function build() {
    css();
    box = document.createElement('div');
    box.className = 'gx';
    box.hidden = true;
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Gacha Harian');
    var balls = '', colors = ['#ef4444', '#3b82f6', '#22c55e', '#eab308', '#a855f7', '#f97316', '#06b6d4', '#ec4899', '#84cc16'];
    for (var i = 0; i < 14; i++) {
      balls += '<i class="gx__ball" style="--c:' + colors[i % colors.length] + ';left:' + (8 + (i * 37) % 118) + 'px;top:' + (62 + (i * 23) % 70) + 'px"></i>';
    }
    box.innerHTML = '<div class="gx__box">'
      + '<div class="gx__head"><h2>🎰 GACHA HARIAN</h2><span class="gx__coins">🪙 <b data-gx="coins">…</b></span><button type="button" class="gx__x" data-gx="close" aria-label="Tutup">✕</button></div>'
      + '<div class="gx__machine" data-gx="machine"><div class="gx__dome">' + balls + '</div><div class="gx__base"><div class="gx__slot"></div><div class="gx__knob"></div></div><i class="gx__out" data-gx="out"></i></div>'
      + '<div data-gx="prize"></div>'
      + '<p class="gx__note" data-gx="note"></p>'
      + '<div class="gx__btns"><button type="button" class="gx__btn gx__btn--free" data-gx="free">🎁 PUTAR GRATIS</button>'
      + '<button type="button" class="gx__btn" data-gx="paid">🪙 PUTAR — <span data-gx="cost">50</span> KOIN</button></div>'
      + '<details class="gx__odds"><summary>Peluang hadiah</summary><ul data-gx="odds"></ul></details>'
      + '<ul class="gx__hist" data-gx="hist"></ul></div>';
    document.body.appendChild(box);
    box.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-gx]') : null;
      if (e.target === box) { close(); return; }
      if (!t) return;
      var k = t.getAttribute('data-gx');
      if (k === 'close') close();
      else if (k === 'free') spin('free');
      else if (k === 'paid') spin('paid');
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && box && !box.hidden) close(); });
  }
  function q(k) { return box.querySelector('[data-gx="' + k + '"]'); }

  function render() {
    if (!st) return;
    q('coins').textContent = st.coins;
    q('cost').textContent = st.cost;
    var free = q('free'), paid = q('paid');
    free.disabled = busy || !st.free_ready;
    free.textContent = st.free_ready ? '🎁 PUTAR GRATIS (1× / 24 jam)' : '⏳ Gratis lagi dalam ' + fmt(st.free_in);
    paid.disabled = busy || st.coins < st.cost;
    var odds = '', seen = {};
    (st.prizes || []).forEach(function (p) {
      if (seen[p.label]) return; seen[p.label] = 1;
      var r = RARITY[p.rarity] || RARITY.common;
      odds += '<li><span style="color:' + r[1] + '">◆ ' + r[0] + '</span> — ' + esc(p.label) + '</li>';
    });
    q('odds').innerHTML = odds;
    var h = '';
    (st.history || []).forEach(function (x) {
      if (!x.prize_label) return;
      var r = RARITY[x.rarity] || RARITY.common;
      h += '<li>' + (x.kind === 'free' ? '🎁' : '🪙') + ' <span style="color:' + r[1] + '">' + esc(x.prize_label) + '</span></li>';
    });
    q('hist').innerHTML = h ? '<li><b>Riwayat:</b></li>' + h : '';
    if (!st.free_ready) document.querySelectorAll('[data-gacha-tile] .pxd-badge').forEach(function (b) { b.remove(); });
    // Saldo di HUD dashboard ikut diperbarui.
    document.querySelectorAll('[data-stat="coins"]').forEach(function (el) { el.textContent = st.coins; });
  }

  function load() {
    return fetch('/api/gacha', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.status !== 'ok') { q('note').textContent = d.message || 'Gacha tidak tersedia.'; return; }
      st = d; render();
    }).catch(function () { q('note').textContent = 'Gagal memuat. Periksa koneksi.'; });
  }

  function spin(mode) {
    if (busy || !st) return;
    busy = true; render();
    q('prize').innerHTML = ''; q('note').textContent = 'Memutar…';
    var m = q('machine');
    m.classList.remove('gx--drop'); m.classList.add('gx--spin');
    for (var i = 0; i < 8; i++) beep(300 + i * 40, 0.06, i * 0.1);
    var started = Date.now();
    fetch('/api/gacha/spin', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() },
      body: JSON.stringify({ _csrf: csrf(), mode: mode })
    }).then(function (r) { return r.json(); }).then(function (d) {
      setTimeout(function () {
        m.classList.remove('gx--spin');
        busy = false;
        if (d.status !== 'ok') { q('note').textContent = d.message || 'Gagal memutar.'; render(); return; }
        st = d;
        var r = RARITY[d.prize.rarity] || RARITY.common;
        q('out').style.setProperty('--c', r[1]);
        m.classList.add('gx--drop');
        var icon = d.prize.type === 'item' ? '🎁' : d.prize.type === 'xp' ? '⭐' : '🪙';
        setTimeout(function () {
          q('prize').innerHTML = '<div class="gx__prize" style="--c:' + r[1] + '"><small>' + r[0] + '</small><b>' + icon + ' ' + esc(d.prize.label) + '</b>'
            + (d.prize.type === 'item' ? '<small>Masuk koleksi — pakai di <a href="/toko" style="color:#fde68a">🛒 Toko</a></small>' : '') + '</div>';
          fanfare(d.prize.rarity);
          q('note').textContent = '';
          render();
        }, 500);
      }, Math.max(0, 1300 - (Date.now() - started)));
    }).catch(function () {
      m.classList.remove('gx--spin'); busy = false;
      q('note').textContent = 'Koneksi terputus. Coba lagi.'; render();
    });
  }

  function open() {
    if (!box) build();
    box.hidden = false;
    q('prize').innerHTML = ''; q('note').textContent = '';
    load();
    clearInterval(tick);
    tick = setInterval(function () {
      if (!st || st.free_ready) return;
      st.free_in = Math.max(0, st.free_in - 1);
      if (st.free_in === 0) st.free_ready = true;
      if (!busy) render();
    }, 1000);
    var f = q('free'); if (f) setTimeout(function () { try { f.focus(); } catch (e) {} }, 50);
  }
  function close() { if (box) box.hidden = true; clearInterval(tick); }

  window.vcGacha = { open: open, close: close };
  // Tautan /?gacha=1 (mis. dari /toko atau notifikasi) langsung membuka mesin.
  if (/[?&]gacha=1/.test(location.search)) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', open); else open();
  }
})();
