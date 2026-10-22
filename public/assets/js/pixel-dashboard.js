/*
 * Pixel Dashboard — panggung kota (cover, rata bawah, fokus ke minimarket), karakter pemain
 * (konfigurasi kustom v2 dari server: window.__AVATAR, digambar pixel-avatar.js — sama dengan
 * Dunia Pixel & 3D), jam siang/malam, dan PENYUNTING KARAKTER (rambut, kulit, pakaian, aksesoris,
 * emote, bio, latar belakang, karakter cerita). Nama selalu nama akun.
 * ES2017 tanpa ?. / ?? agar jalan di HP lama.
 */
(function () {
  'use strict';
  var SC = window.__DASHSCENE; // adegan latar pilihan pemilik (dash-scene.js)
  var IMG_W = SC ? SC.w : 1200, IMG_H = SC ? SC.h : 675, CHAR_X = SC ? SC.charX * SC.w : 410;
  var PA = window.PixelAvatar;
  if (!PA) return;

  var pxd = document.getElementById('pxd');
  if (!pxd) return;
  var stage = document.getElementById('pxdStage');
  var menu = pxd.querySelector('.pxd-menu');
  var sprite = document.getElementById('pxdSprite');
  var face = document.getElementById('pxdFace');
  var name = pxd.getAttribute('data-name') || '';
  var AV = window.__AVATAR || { config: null, bio: '', name: name, canSave: false };
  var design = PA.normalize(AV.config);
  var chars = [];

  // ---------- Panggung ----------
  function fit() {
    var W = pxd.clientWidth, H = pxd.clientHeight;
    var portrait = H > W;
    var availH = H;
    if (portrait && menu) availH = Math.max(H * 0.45, H - menu.offsetHeight + 14);
    var s = Math.max(W / IMG_W, availH / IMG_H);
    if (portrait) s *= 1.3; // HP tegak: karakter & minimarket lebih besar, tetap rata bawah
    var sw = IMG_W * s, sh = IMG_H * s;
    var focus = portrait ? 0.5 : 0.33;
    var left = Math.min(0, Math.max(W - sw, W * focus - CHAR_X * s));
    stage.style.width = Math.round(sw) + 'px';
    stage.style.height = Math.round(sh) + 'px';
    stage.style.left = Math.round(left) + 'px';
    stage.style.top = Math.round(availH - sh) + 'px';
    stage.style.bottom = 'auto';
  }

  function drawAll(blink) {
    if (walk.on) return; // sedang berjalan: kanvas digambar oleh langkah
    if (sprite) PA.paint(sprite, design, blink);
    if (face && sprite) {
      var c = face.getContext('2d');
      c.clearRect(0, 0, face.width, face.height);
      c.drawImage(sprite, 0, 3, 22, 14, 0, 0, 22, 14);
    }
  }

  function blinkLoop() {
    setTimeout(function () {
      if (!document.hidden) { drawAll(true); setTimeout(function () { drawAll(false); }, 140); }
      blinkLoop();
    }, 2600 + Math.random() * 2600);
  }

  // ---------- Jam & sapaan ----------
  var ICON = { pagi: '🌅 Pagi', siang: '☀️ Siang', sore: '🌇 Sore', malam: '🌙 Malam' };
  var GREET = { pagi: 'Selamat pagi', siang: 'Selamat siang', sore: 'Selamat sore', malam: 'Selamat malam' };
  function part() { return window.vcDaypart ? window.vcDaypart() : (document.documentElement.getAttribute('data-daypart') || 'malam'); }
  function tickClock() {
    var el = document.getElementById('pxdClock');
    if (!el) return;
    var d = new Date(), hh = d.getHours(), mm = d.getMinutes();
    el.textContent = ICON[part()] + ' · ' + (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
  }
  function say(text, ms) {
    var b = document.getElementById('pxdBubble');
    if (!b) return;
    b.textContent = text;
    b.classList.remove('is-hidden');
    clearTimeout(say.t);
    say.t = setTimeout(function () { b.classList.add('is-hidden'); }, ms || 9000);
  }
  function greet() { say(GREET[part()] + ', ' + name + '! Ketuk aku untuk ganti gaya.'); }

  // ---------- Penyunting karakter ----------
  var pick = document.getElementById('pxdPick');
  var body = document.getElementById('pxdCustBody');
  var draft = null, draftBio = '', tab = 'rambut';
  var SKINS = ['#f3cba5', '#f0c29b', '#e2ad84', '#d39b72', '#c98d63', '#a8714a', '#7a4e2d'];
  var HAIR_C = ['#111111', '#2b1d16', '#3b2416', '#6b4423', '#a0703f', '#d9b44a', '#9ca3af', '#f5f5f4', '#be185d', '#1f5a34', '#1d4ed8', '#7c3aed'];
  var COLORS = ['#1d4ed8', '#0e7490', '#2f8f4e', '#7a4a28', '#c2410c', '#c0392b', '#be185d', '#6d28d9', '#334155', '#111827', '#f5f5f4', '#e8892f'];
  var COLORABLE = { olahraga: 1, hoodie: 1, batik: 1, casual: 1, jas_lab: 1 };
  var TABS = [['rambut', '💇 Rambut & Kulit'], ['pakaian', '👕 Pakaian'], ['aksesoris', '🎒 Aksesoris'], ['emote', '😀 Emote'], ['profil', '📝 Bio & Latar'], ['cerita', '📖 Karakter Cerita'], ['anime', '🎭 Kostum Anime']];
  var costumes = null, animeSel = '', gSel = 'all'; // Event Kostum Anime (GET /api/costumes)
  function loadCostumes() {
    if (costumes) return;
    costumes = { loading: true, anime: [] };
    fetch('/api/costumes', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      costumes = d && d.status === 'ok' ? d : { anime: [], enabled: false };
      if (!animeSel && costumes.anime.length) animeSel = costumes.anime[0].id;
      if (tab === 'anime') render();
    }).catch(function () { costumes = { anime: [], enabled: false, failed: true }; if (tab === 'anime') render(); });
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function swatches(field, list) {
    return '<div class="pxc-sw">' + list.map(function (c) {
      return '<button type="button" class="px-plain pxc-swatch' + (draft[field] === c ? ' is-on' : '') + '" data-set="' + field + '" data-val="' + c + '" style="background:' + c + '" aria-label="' + field + ' ' + c + '"></button>';
    }).join('') + '</div>';
  }
  function chip(attr, val, label, on, extra) {
    return '<button type="button" class="px-plain pxc-chip' + (on ? ' is-on' : '') + '" ' + attr + '="' + esc(val) + '"' + (extra || '') + '>' + label + '</button>';
  }

  function panelHtml() {
    var h = '';
    if (tab === 'rambut') {
      h += '<h3>Gaya cepat</h3><div class="pxc-chips">' + chip('data-preset', 'cowok', '👦 Cowok', false) + chip('data-preset', 'cewek', '👧 Cewek', false)
        + chip('data-preset', 'hijab', '🧕 Cewek Berhijab', false) + '</div>';
      h += '<h3>Warna kulit</h3>' + swatches('skin', SKINS);
      h += '<h3>Gaya rambut</h3><div class="pxc-chips">' + Object.keys(PA.HAIRS).map(function (k) { return chip('data-hair', k, PA.HAIRS[k], draft.hairStyle === k); }).join('') + '</div>';
      h += '<h3>' + (draft.hairStyle === 'hijab' ? 'Warna hijab' : 'Warna rambut') + '</h3>' + swatches('hair', HAIR_C);
    } else if (tab === 'pakaian') {
      h += '<h3>Pakaian <small>(referensi seragam & gaya guru SMK Silber)</small></h3><div class="pxc-outfits">' + Object.keys(PA.OUTFITS).map(function (k) {
        return '<button type="button" class="px-plain pxc-outfit' + (draft.outfit === k ? ' is-on' : '') + '" data-outfit="' + k + '"><canvas width="22" height="42" data-mini="' + k + '"></canvas><span>' + esc(PA.OUTFITS[k]) + '</span></button>';
      }).join('') + '</div>';
      if (COLORABLE[draft.outfit]) h += '<h3>' + (draft.outfit === 'jas_lab' ? 'Warna kemeja dalam' : 'Warna atasan') + '</h3>' + swatches('top', COLORS);
      if (['tjkt', 'putih_abu', 'pramuka'].indexOf(draft.outfit) < 0) h += '<h3>Warna celana / rok</h3>' + swatches('bottom', COLORS);
      h += '<h3>Warna sepatu</h3>' + swatches('shoes', ['#111827', '#f5f5f4', '#7a4a28', '#c0392b', '#1d4ed8', '#334155']);
    } else if (tab === 'aksesoris') {
      h += '<h3>Aksesoris <small>(maks. 3)</small></h3><div class="pxc-chips">' + Object.keys(PA.ACCESSORIES).map(function (k) { return chip('data-acc', k, PA.ACCESSORIES[k], draft.acc.indexOf(k) >= 0); }).join('') + '</div>';
      h += '<h3>Warna tas / topi / headphone</h3>' + swatches('accColor', COLORS);
    } else if (tab === 'emote') {
      h += '<h3>Emote favorit <small>(pilih sampai 8 — tampil paling depan di bar emote; semua 18 emote tetap bisa dipakai)</small></h3><div class="pxc-chips">' + Object.keys(PA.EMOTES).map(function (k) {
        return chip('data-emote', k, PA.EMOTES[k] + ' ' + PA.EMOTE_LABEL[k], draft.emotes.indexOf(k) >= 0);
      }).join('') + '</div>';
    } else if (tab === 'profil') {
      h += '<h3>Nama</h3><p class="pxc-name">' + esc(AV.name || name) + ' <small>(mengikuti nama akun)</small></p>';
      h += '<h3>Bio</h3><textarea id="pxcBio" maxlength="160" rows="3" placeholder="Ceritakan sedikit tentang dirimu…">' + esc(draftBio) + '</textarea><p class="pxc-count" id="pxcCount">' + draftBio.length + '/160</p>';
      h += '<h3>Latar belakang kartu</h3><div class="pxc-bgs">' + Object.keys(PA.BACKGROUNDS).map(function (k) {
        return '<button type="button" class="px-plain pxc-bg' + (draft.background === k ? ' is-on' : '') + '" data-bg="' + k + '"><canvas width="48" height="36" data-bgmini="' + k + '"></canvas><span>' + esc(PA.BACKGROUNDS[k]) + '</span></button>';
      }).join('') + '</div>';
    } else if (tab === 'anime') {
      loadCostumes();
      if (!costumes || costumes.loading) return '<p class="pxc-note">Memuat kostum…</p>';
      if (!costumes.anime.length) return '<p class="pxc-note">' + (costumes.enabled === false ? 'Event kostum sedang tidak aktif.' : 'Belum ada kostum.') + '</p>';
      var an = null; costumes.anime.forEach(function (x) { if (x.id === animeSel) an = x; }); if (!an) { an = costumes.anime[0]; animeSel = an.id; }
      h += '<p class="pxc-note">🎉 ' + esc(costumes.title || 'Event Kostum Anime') + ' — kostum fan-cosplay buatan sendiri; nama karakter milik pemegang hak cipta. Setelah dipilih tetap bisa diubah, lalu 💾 Simpan.</p>';
      h += '<h3>Anime</h3><div class="pxc-chips">' + costumes.anime.map(function (x) { return chip('data-anime', x.id, x.icon + ' ' + esc(x.title), x.id === animeSel); }).join('') + '</div>';
      h += '<div class="pxc-chips" style="margin-top:6px">' + chip('data-g', 'all', 'Semua', gSel === 'all') + chip('data-g', 'm', '👦 Cowok', gSel === 'm') + chip('data-g', 'f', '👧 Cewek', gSel === 'f') + '</div>';
      h += '<div class="pxc-outfits">' + an.chars.filter(function (c) { return gSel === 'all' || c.g === gSel; }).map(function (c) {
        return '<button type="button" class="px-plain pxc-outfit' + (draft.costume === c.id ? ' is-on' : '') + '" data-costume="' + esc(c.id) + '"><canvas width="22" height="42" data-cosmini="' + esc(c.id) + '"></canvas><span>' + esc(c.name) + '</span></button>';
      }).join('') + '</div>';
    } else if (tab === 'cerita') {
      h += '<p class="pxc-note">Karakter cerita hanya gaya & lore — tidak ada tugas. Pilih untuk memakai gayanya, lalu tetap bisa diubah.</p><div class="pxc-chips">'
        + chip('data-story', '', 'Tanpa karakter cerita', !draft.story) + chars.map(function (c) { return chip('data-story', c.id, esc(c.emoji || '') + ' ' + esc(c.name), draft.story === c.id); }).join('') + '</div>';
      var cur = null; for (var i = 0; i < chars.length; i++) if (chars[i].id === draft.story) cur = chars[i];
      if (cur) h += '<p class="pxc-lore"><b>' + esc(cur.name) + '</b> — ' + esc(cur.lore) + '</p>';
    }
    return h;
  }

  function render() {
    body.innerHTML = '<div class="pxc">'
      + '<div class="pxc-card"><div class="pxc-stage"><canvas id="pxcBack" width="192" height="192"></canvas><canvas id="pxcHero" width="22" height="42"></canvas></div>'
      + '<div class="pxc-plate">' + esc(AV.name || name) + '</div><p class="pxc-bio" id="pxcBioView">' + esc(draftBio || 'Belum ada bio.') + '</p></div>'
      + '<div class="pxc-side"><div class="pxc-tabs" role="tablist">' + TABS.map(function (t) {
        return '<button type="button" role="tab" class="px-plain pxc-tab' + (tab === t[0] ? ' is-on' : '') + '" data-tab="' + t[0] + '" aria-selected="' + (tab === t[0]) + '">' + t[1] + '</button>';
      }).join('') + '</div><div class="pxc-panel">' + panelHtml() + '</div>'
      + '<div class="pxc-actions"><span id="pxcMsg" aria-live="polite"></span>'
      + (AV.canSave ? '<button type="button" class="px-btn px-btn--gold" data-save>💾 Simpan</button>' : '<span class="pxc-note">Mode tamu: gaya tidak disimpan.</span>')
      + '</div></div></div>';
    preview();
    Array.prototype.forEach.call(body.querySelectorAll('[data-mini]'), function (cv) {
      var o = clone(draft); o.outfit = cv.getAttribute('data-mini'); PA.paint(cv, o, false);
    });
    Array.prototype.forEach.call(body.querySelectorAll('[data-cosmini]'), function (cv) { var c = findCostume(cv.getAttribute('data-cosmini')); if (c) PA.paint(cv, c.config, false); });
    Array.prototype.forEach.call(body.querySelectorAll('[data-bgmini]'), function (cv) { PA.backdrop(cv.getContext('2d'), cv.width, cv.height, cv.getAttribute('data-bgmini')); });
    var bio = document.getElementById('pxcBio');
    if (bio) bio.addEventListener('input', function () {
      draftBio = bio.value.slice(0, 160);
      var c = document.getElementById('pxcCount'); if (c) c.textContent = draftBio.length + '/160';
      var v = document.getElementById('pxcBioView'); if (v) v.textContent = draftBio || 'Belum ada bio.';
    });
  }

  function findCostume(id) {
    var f = null;
    (costumes && costumes.anime || []).forEach(function (a) { a.chars.forEach(function (c) { if (c.id === id) f = c; }); });
    return f;
  }
  function preview() {
    var back = document.getElementById('pxcBack'), hero = document.getElementById('pxcHero');
    if (back) PA.backdrop(back.getContext('2d'), back.width, back.height, draft.background);
    if (hero) PA.paint(hero, draft, false);
  }

  function openEditor() {
    if (!pick || !body) return;
    draft = clone(design); draftBio = AV.bio || ''; tab = 'rambut';
    render();
    pick.hidden = false;
    document.body.classList.add('pxd-modal-open'); // sembunyikan banner "Pasang aplikasi" (menutupi tombol Simpan)
    var first = body.querySelector('.pxc-tab.is-on'); if (first) first.focus();
  }
  function closeEditor() { document.body.classList.remove('pxd-modal-open'); if (pick) pick.hidden = true; var b = document.getElementById('pxdCharBtn'); if (b) b.focus(); }

  function msg(t, ok) { var m = document.getElementById('pxcMsg'); if (m) { m.textContent = t; m.style.color = ok ? '#9fe08a' : '#ffb4a8'; } }

  function save() {
    var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
    msg('Menyimpan…', true);
    fetch('/api/avatar/me', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
      body: JSON.stringify({ _csrf: csrf, config: draft, bio: draftBio }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.status !== 'ok') { msg((d && d.message) || 'Gagal menyimpan.', false); return; }
        AV.config = d.config; AV.bio = d.bio; window.__AVATAR = AV;
        design = PA.normalize(d.config);
        drawAll(false);
        msg('✅ Tersimpan! Gaya baru dipakai di Dunia Pixel & 3D.', true);
        say('Gaya baru, siap main! ✨', 5000);
        setTimeout(closeEditor, 900);
      })
      .catch(function () { msg('Koneksi gagal. Coba lagi.', false); });
  }

  if (pick) {
    pick.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('button') : null;
      if (e.target === pick || (t && t.hasAttribute('data-close'))) { closeEditor(); return; }
      if (!t || !draft) return;
      var a;
      if ((a = t.getAttribute('data-tab'))) { tab = a; render(); return; }
      if (t.hasAttribute('data-save')) { save(); return; }
      if ((a = t.getAttribute('data-set'))) draft[a] = t.getAttribute('data-val');
      else if ((a = t.getAttribute('data-hair'))) draft.hairStyle = a;
      else if ((a = t.getAttribute('data-anime'))) { animeSel = a; render(); return; }
      else if ((a = t.getAttribute('data-g'))) { gSel = a; render(); return; }
      else if ((a = t.getAttribute('data-costume'))) {
        var cs = findCostume(a); if (!cs) return;
        ['skin', 'hairStyle', 'hair', 'outfit', 'top', 'bottom', 'shoes', 'accColor'].forEach(function (f) { draft[f] = cs.config[f]; });
        draft.acc = cs.config.acc.slice(); draft.costume = cs.id; draft.motif = cs.config.motif || ''; draft.story = '';
        msg('🎭 Kostum ' + cs.name + ' dipakai — tekan 💾 Simpan.', true);
      }
      else if ((a = t.getAttribute('data-preset'))) {
        // Gaya cepat: tetap bisa diubah satu per satu setelahnya
        var PRE = { cowok: { hairStyle: 'short', hair: '#111111', outfit: 'tjkt' }, cewek: { hairStyle: 'long', hair: '#2b1d16', outfit: 'tjkt' }, hijab: { hairStyle: 'hijab', hair: '#1d4ed8', outfit: 'tjkt' } };
        var pr = PRE[a]; if (!pr) return;
        Object.keys(pr).forEach(function (f) { draft[f] = pr[f]; });
      }
      else if ((a = t.getAttribute('data-outfit'))) { draft.outfit = a; draft.motif = ''; draft.costume = ''; }
      else if ((a = t.getAttribute('data-bg'))) draft.background = a;
      else if ((a = t.getAttribute('data-acc'))) {
        var i = draft.acc.indexOf(a);
        if (i >= 0) draft.acc.splice(i, 1); else if (draft.acc.length < 3) draft.acc.push(a); else { msg('Maksimal 3 aksesoris.', false); return; }
      } else if ((a = t.getAttribute('data-emote'))) {
        var j = draft.emotes.indexOf(a);
        if (j >= 0) { if (draft.emotes.length > 1) draft.emotes.splice(j, 1); } else if (draft.emotes.length < 8) draft.emotes.push(a); else { msg('Pilih maksimal 8 emote favorit.', false); return; }
      } else if (t.hasAttribute('data-story')) {
        var id = t.getAttribute('data-story');
        draft.story = id;
        for (var k = 0; k < chars.length; k++) if (chars[k].id === id && chars[k].design) {
          var sd = PA.normalize(chars[k].design);
          ['skin', 'hair', 'hairStyle', 'outfit', 'top', 'bottom', 'shoes', 'accColor'].forEach(function (f) { draft[f] = sd[f]; });
        }
        PA.setSelected(id);
      } else return;
      var bioEl = document.getElementById('pxcBio'); if (bioEl) draftBio = bioEl.value.slice(0, 160);
      render();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pick.hidden) closeEditor(); });
  }
  var charBtn = document.getElementById('pxdCharBtn');
  if (charBtn) charBtn.addEventListener('click', openEditor);

  // ---------- Karakter berjalan-jalan (kaki melangkah, tangan berayun) ----------
  // Sesekali berjalan ke titik lain di sekitar tempatnya berdiri, menghadap arah jalan, lalu berhenti
  // menghadap depan. Tidak jalan saat mabar (party.js yang menggerakkan), penyunting terbuka, atau reduced-motion.
  var walk = { on: false, x: 0, home: 0, to: 0, f: 0, ft: 0, wait: 2.5, dir: 1, last: 0 };
  (function walker() {
    var el = document.getElementById('pxdChar');
    if (!el || !sprite || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
    var SCn = window.__DASHSCENE;
    walk.home = walk.x = SCn ? SCn.charX * 100 : 34.2;
    var g2 = sprite.getContext('2d');
    function busy() {
      if (pick && !pick.hidden) return true;
      try { var st = window.vcParty && window.vcParty.state && window.vcParty.state(); if (st && st.party) return true; } catch (e) {}
      return false;
    }
    function step(ts) {
      requestAnimationFrame(step);
      var dt = walk.last ? Math.min(0.1, (ts - walk.last) / 1000) : 0; walk.last = ts;
      if (document.hidden || !dt) return;
      if (busy()) { if (walk.on) { walk.on = false; drawAll(false); } return; }
      if (!walk.on) {
        walk.wait -= dt;
        if (walk.wait > 0) return;
        var span = window.innerWidth < 700 ? 10 : 14;              // jangkauan jalan (% panggung)
        // batas = bagian panggung yang terlihat & tidak tertutup papan menu di sisi kanan
        var sr = stage.getBoundingClientRect(), lo = 6, hi = 94;
        if (sr.width > 0) {
          lo = Math.max(lo, (-sr.left) / sr.width * 100 + 5);
          hi = Math.min(hi, (window.innerWidth - sr.left) / sr.width * 100 - 5);
          var mr = menu ? menu.getBoundingClientRect() : null;
          if (mr && mr.left > window.innerWidth * 0.35 && mr.top < window.innerHeight * 0.8) hi = Math.min(hi, (mr.left - sr.left) / sr.width * 100 - 6);
        }
        var t = walk.home + (Math.random() * 2 - 1) * span;
        walk.to = Math.max(lo, Math.min(hi, t));
        if (Math.abs(walk.to - walk.x) < 3) { walk.wait = 1; return; }
        walk.dir = walk.to > walk.x ? 1 : -1; walk.on = true; walk.f = 1; walk.ft = 0;
      }
      var speed = 3.2; // %/detik
      walk.x += walk.dir * speed * dt;
      walk.ft += dt;
      if (walk.ft > 0.13) { walk.ft = 0; walk.f = walk.f % 4 + 1; }
      if ((walk.dir > 0 && walk.x >= walk.to) || (walk.dir < 0 && walk.x <= walk.to)) {
        walk.x = walk.to; walk.on = false; walk.wait = 3 + Math.random() * 5;
        el.style.left = walk.x + '%';
        drawAll(false); // berhenti: menghadap depan
        return;
      }
      el.style.left = walk.x + '%';
      g2.clearRect(0, 0, sprite.width, sprite.height);
      PA.draw(g2, 0, 0, design, { frame: walk.f, look: walk.dir });
    }
    requestAnimationFrame(step);
  })();

  // ---------- Mulai ----------
  fit();
  drawAll(false);
  tickClock();
  greet();
  setInterval(tickClock, 30000);
  window.addEventListener('resize', fit);
  window.addEventListener('orientationchange', function () { setTimeout(fit, 250); });
  window.addEventListener('vc:daypart', function () { tickClock(); greet(); });
  blinkLoop();
  PA.loadStories().then(function (list) { chars = list || []; });
  // /?karakter=1 (dari halaman profil) → langsung buka penyunting karakter.
  if (/[?&]karakter=1/.test(location.search)) setTimeout(openEditor, 400);
  window.vcOpenCharEditor = openEditor;
})();
