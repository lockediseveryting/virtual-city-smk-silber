/*
 * Misi Harian — panel + pelacak kecil (dashboard, Dunia 3D, Dunia Pixel).
 *  vcDaily.open()               buka panel (3 misi, progres, ambil hadiah, peti bonus, teman online)
 *  vcDaily.report(event, ref)   laporkan kejadian klien (emote / cat / trash / pc) — server yang menilai
 * Dashboard: panel muncul otomatis sekali per hari setelah login. Dunia game: chip "📅 1/3" di layar.
 * ES2017.
 */
(function () {
  'use strict';
  if (window.vcDaily) return;
  var st = null, box = null, chip = null, busy = false;
  function csrf() { var m = document.querySelector('meta[name="csrf"]'); return m ? m.content : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } return null; }
  var isGame = document.body && document.body.classList.contains('vc-game');

  function css() {
    if (document.getElementById('dqCss')) return;
    var s = document.createElement('style'); s.id = 'dqCss';
    s.textContent = [
      '.dq{position:fixed;inset:0;z-index:2147482900;display:flex;align-items:center;justify-content:center;padding:12px;background:rgba(8,6,16,.7)}',
      '.dq[hidden]{display:none}',
      '.dq__box{width:min(460px,100%);max-height:calc(100dvh - 24px);overflow:auto;border-radius:14px;padding:14px;color:#fef3c7;background:linear-gradient(180deg,#3b2414,#22140b);border:3px solid #d9a441;box-shadow:0 0 0 3px #1a0e06,0 18px 50px rgba(0,0,0,.55);font-family:inherit;animation:dqIn .3s ease-out}',
      '@keyframes dqIn{from{transform:translateY(14px);opacity:0}to{transform:none;opacity:1}}',
      '.dq__head{display:flex;align-items:center;gap:8px;margin-bottom:4px}.dq__head h2{flex:1;margin:0;font-size:1.1rem;color:#fde68a}',
      '.dq__x{width:38px;height:38px;border-radius:8px;border:1px solid #d9a44188;background:#0005;color:#fde68a;cursor:pointer;font-size:1rem}',
      '.dq__sub{margin:0 0 10px;font-size:.82rem;opacity:.85}',
      '.dq__q{display:flex;gap:10px;align-items:center;padding:10px;margin:8px 0;border-radius:10px;background:#0004;border:1px solid #d9a44144}',
      '.dq__q.is-done{border-color:#4ade80aa;background:rgba(34,197,94,.12)}',
      '.dq__ic{font-size:1.6rem;width:40px;text-align:center;flex:0 0 auto}',
      '.dq__t{flex:1;min-width:0}.dq__t b{display:block;font-size:.95rem}.dq__t small{display:block;opacity:.8;font-size:.74rem;margin-top:2px}',
      '.dq__bar{height:8px;border-radius:6px;background:#0007;margin-top:6px;overflow:hidden}.dq__bar i{display:block;height:100%;background:linear-gradient(90deg,#ffcf4a,#4ade80);transition:width .4s}',
      '.dq__btn{min-height:40px;min-width:84px;padding:0 10px;border-radius:9px;border:2px solid #78350f;font-weight:800;cursor:pointer;background:linear-gradient(180deg,#fde68a,#f59e0b);color:#1c1204;font-size:.82rem}',
      '.dq__btn--go{background:#0006;color:#fde68a;border-color:#d9a44188}',
      '.dq__btn[disabled]{opacity:.55;cursor:default}',
      '.dq__bonus{margin-top:10px;padding:10px;border-radius:10px;text-align:center;background:rgba(250,204,21,.12);border:1px dashed #facc15}',
      '.dq__on{margin-top:10px;font-size:.8rem}.dq__on b{display:block;margin-bottom:6px}',
      '.dq__mate{display:inline-flex;align-items:center;gap:6px;margin:0 6px 6px 0;padding:4px 8px;border-radius:999px;background:#0005;border:1px solid #4ade8066}',
      '.dq__mate i{width:8px;height:8px;border-radius:50%;background:#4ade80;display:inline-block}',
      '.dq__foot{display:flex;justify-content:space-between;gap:8px;margin-top:10px;font-size:.78rem}.dq__foot a{color:#fde68a}',
      '.dq-chip{position:fixed;z-index:70;left:max(10px,env(safe-area-inset-left));top:calc(var(--dq-top,64px) + env(safe-area-inset-top));display:flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:2px solid #d9a441;background:rgba(32,20,12,.88);color:#fde68a;font:700 13px/1 inherit;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.35)}',
      '.dq-chip.is-ready{animation:dqPulse 1.2s ease-in-out infinite}',
      '@keyframes dqPulse{50%{box-shadow:0 0 0 6px rgba(250,204,21,.25)}}',
      'body.vc-ui-hidden .dq-chip{display:none}',
      // HP landscape (layar pendek): panel ringkas — petunjuk disembunyikan, kartu lebih pendek
      '@media (max-height:520px){.dq__box{padding:10px;width:min(560px,100%)}.dq__sub{display:none}.dq__q{padding:6px 8px;margin:5px 0}.dq__t small:first-of-type{display:none}.dq__ic{font-size:1.2rem;width:30px}.dq__btn{min-height:34px}.dq__on{display:none}.dq__bonus{padding:6px}}',
      '@media (prefers-reduced-motion:reduce){.dq__box,.dq-chip.is-ready{animation:none}}'
    ].join('\n');
    document.head.appendChild(s);
  }

  function render() {
    if (!box || !st) return;
    var h = '<div class="dq__box"><div class="dq__head"><span style="font-size:1.5rem">📅</span><h2>Misi Harian</h2>'
      + (st.streak > 1 ? '<span title="Login beruntun">🔥 ' + st.streak + ' hari</span>' : '')
      + '<button type="button" class="dq__x" data-dq="close" aria-label="Tutup">✕</button></div>'
      + '<p class="dq__sub">3 misi baru setiap hari. Selesaikan semua → buka peti bonus! 🎁</p>';
    st.quests.forEach(function (q) {
      var pct = Math.round(100 * q.progress / q.target);
      var btn = q.claimed ? '<button class="dq__btn" disabled>✓ Diambil</button>'
        : q.done ? '<button type="button" class="dq__btn" data-dq="claim" data-q="' + esc(q.id) + '">🎁 Ambil</button>'
        : '<button type="button" class="dq__btn dq__btn--go" data-dq="go" data-act="' + esc(q.action) + '">Ayo ›</button>';
      h += '<div class="dq__q' + (q.done ? ' is-done' : '') + '"><span class="dq__ic">' + q.icon + '</span><div class="dq__t"><b>' + esc(q.title) + '</b>'
        + '<small>' + esc(q.hint) + ' · +' + q.xp + ' XP' + (q.coins ? ' · +' + q.coins + ' 🪙' : '') + '</small>'
        + '<div class="dq__bar" role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"><i style="width:' + pct + '%"></i></div>'
        + '<small>' + q.progress + ' / ' + q.target + '</small></div>' + btn + '</div>';
    });
    h += '<div class="dq__bonus">' + (st.bonus.claimed ? '🎉 Peti bonus hari ini sudah dibuka. Sampai jumpa besok!'
      : st.bonus.ready ? '<button type="button" class="dq__btn" data-dq="claim" data-q="__bonus">🎁 Buka Peti Bonus (+' + st.bonus.xp + ' XP · +' + st.bonus.coins + ' 🪙)</button>'
      : '🔒 Peti bonus: selesaikan ketiga misi (+' + st.bonus.xp + ' XP · +' + st.bonus.coins + ' 🪙)') + '</div>';
    if (st.online && st.online.length) {
      h += '<div class="dq__on"><b>👋 Teman yang sedang online — ajak main bareng!</b>' + st.online.map(function (o) {
        return '<a class="dq__mate" href="/profil?id=' + o.id + '"><i></i>' + esc(String(o.name || '').split(' ')[0] || 'Teman') + '</a>';
      }).join('') + '</div>';
    } else {
      h += '<div class="dq__on">🌙 Belum ada teman online. Jelajahi sekolah dulu — kucing & anomali menunggu!</div>';
    }
    h += '<div class="dq__foot"><a href="/misi">🎯 Misi sekolah dari guru ›</a><span data-dq="msg" aria-live="polite"></span></div></div>';
    box.innerHTML = h;
    updateChip();
  }
  function updateChip() {
    if (!chip || !st) return;
    var done = st.quests.filter(function (q) { return q.done; }).length, ready = st.quests.some(function (q) { return q.done && !q.claimed; }) || (st.bonus.ready && !st.bonus.claimed);
    chip.innerHTML = '📅 <span>' + done + '/' + st.quests.length + '</span>' + (ready ? ' 🎁' : '');
    chip.classList.toggle('is-ready', ready);
    chip.title = 'Misi Harian: ' + done + ' dari ' + st.quests.length + ' selesai';
  }
  function load() {
    return fetch('/api/daily', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (d && d.status === 'ok') { st = d; render(); updateChip(); }
      return d;
    }).catch(function () { return null; });
  }
  function post(body) {
    body._csrf = csrf();
    return fetch('/api/daily', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }
  function build() {
    css();
    box = document.createElement('div');
    box.className = 'dq'; box.hidden = true;
    box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', 'Misi Harian');
    document.body.appendChild(box);
    box.addEventListener('click', function (e) {
      if (e.target === box) { box.hidden = true; return; }
      var t = e.target.closest ? e.target.closest('[data-dq]') : null;
      if (!t) return;
      var k = t.getAttribute('data-dq');
      if (k === 'close') box.hidden = true;
      else if (k === 'go') {
        var act = t.getAttribute('data-act') || '';
        box.hidden = true;
        // tanpa eval (CSP): aksi js: dipetakan ke fungsi yang tersedia di halaman ini
        var JS = {
          'js:vcEmote.toggle()': function () { if (window.vcEmote) vcEmote.toggle(); else if (typeof window.triggerWorldEmote === 'function') window.triggerWorldEmote(); else location.href = '/'; },
          'js:vcParty.open()': function () { if (window.vcParty) vcParty.open(); else location.href = '/?mabar=1'; },
          'js:vcGacha.open()': function () { if (window.vcGacha) vcGacha.open(); else location.href = '/?gacha=1'; }
        };
        if (JS[act]) JS[act]();
        else if (act && act.charAt(0) === '/' && location.pathname !== act) location.href = act;
      } else if (k === 'claim' && !busy) {
        busy = true; t.disabled = true;
        post({ action: 'claim', quest: t.getAttribute('data-q') }).then(function (d) {
          busy = false;
          if (d.status === 'ok') {
            st = d; render();
            var m = box.querySelector('[data-dq="msg"]'); if (m) m.textContent = '+' + d.reward.xp + ' XP · +' + d.reward.coins + ' 🪙';
            document.querySelectorAll('[data-stat="coins"]').forEach(function (el) { el.textContent = d.coins; });
            if (window.vcChime) window.vcChime();
          } else { t.disabled = false; var m2 = box.querySelector('[data-dq="msg"]'); if (m2) m2.textContent = d.message || 'Gagal.'; }
        }).catch(function () { busy = false; t.disabled = false; });
      }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && box && !box.hidden) box.hidden = true; });
  }
  function open() { if (!box) build(); box.hidden = false; load(); }

  var lastRep = {};
  function report(event, ref) {
    var key = event + ':' + ref;
    if (lastRep[key] && Date.now() - lastRep[key] < 2500) return;
    lastRep[key] = Date.now();
    post({ action: 'report', event: event, ref: String(ref || 'x') }).then(function (d) {
      if (!d || d.status !== 'ok') return;
      var before = st ? st.quests.filter(function (q) { return q.done; }).length : 0;
      st = d; if (box && !box.hidden) render(); updateChip();
      var after = st.quests.filter(function (q) { return q.done; }).length;
      if (after > before) toast('📅 Misi harian selesai! Ambil hadiahnya di panel 🎁');
    }).catch(function () {});
  }
  function toast(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;left:50%;top:84px;transform:translateX(-50%);z-index:2147482950;background:rgba(32,20,12,.95);color:#fde68a;border:2px solid #d9a441;border-radius:10px;padding:8px 14px;font:700 14px inherit;max-width:calc(100vw - 32px);text-align:center';
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3800);
    if (window.vcChime) window.vcChime();
  }

  window.vcDaily = { open: open, report: report, reload: load };

  function start() {
    if (/^\/(login|daftar|gabung)/.test(location.pathname)) return;
    css();
    if (isGame) { // dunia 3D / Pixel: chip kecil di kiri atas
      chip = document.createElement('button');
      chip.type = 'button'; chip.className = 'dq-chip'; chip.innerHTML = '📅 …';
      chip.style.setProperty('--dq-top', location.pathname.indexOf('/pixel') === 0 ? '96px' : '128px');
      chip.addEventListener('click', open);
      document.body.appendChild(chip);
      load().then(function (d) { if (!d || d.status !== 'ok') chip.remove(); });
      return;
    }
    if (document.getElementById('pxd')) { // dashboard: muncul sekali per hari setelah pop-up lain
      load().then(function (d) {
        if (!d || d.status !== 'ok' || ls('vc_daily_seen') === d.day) return;
        var tries = 0;
        (function wait() {
          if (document.querySelector('.pxd-pop:not([hidden]), .vcbc, #pxdWaModal:not([hidden]), .gx:not([hidden])') && tries++ < 20) { setTimeout(wait, 1500); return; }
          ls('vc_daily_seen', d.day);
          open();
        })();
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(start, 1800); }); else setTimeout(start, 1800);
})();
