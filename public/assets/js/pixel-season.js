/*
 * Pixel Season — musim & hari raya untuk panggung dashboard (beranda & admin), digambar di satu
 * kanvas pixel (resolusi 1/2 foto, diskalakan pixelated) di atas foto kota.
 *   Musim (8): tahunbaru · dingin · ramadan · semi · panas · merdeka · gugur · natal
 *   Hari raya semua agama (menimpa musim pada tanggalnya): Idul Fitri · Idul Adha (Islam),
 *   Natal & Paskah (Kristen/Katolik), Nyepi (Hindu), Waisak (Buddha), Imlek (Konghucu).
 * Tanggal hari raya = perkiraan kalender 2026–2030 (hijriah/lunar bisa bergeser ±1 hari).
 * Musim ditentukan tanggal perangkat (zona waktu pengguna). Malam: bintang berkelip + bintang
 * jatuh + suasana malam khas musim (bulan sabit, lampion, kembang api, kunang-kunang, salju …).
 * Siang: awan & burung bergerak. Pratinjau: ?musim=imlek atau localStorage vc_season_preview.
 * Hemat: ±30 fps, berhenti saat tab tersembunyi, statis bila prefers-reduced-motion.
 * ES2017 tanpa ?. / ??.
 */
(function () {
  'use strict';
  var stage = document.getElementById('pxdStage');
  if (!stage) return;

  var W = 600, H = 338; // koordinat = foto 1200×675 dibagi 2
  var ROOF_Y = 192, GROUND_Y = 305, SHOP_X1 = 0, SHOP_X2 = 290;

  var SEASONS = {
    tahunbaru: { icon: '🎆', name: 'Tahun Baru', tint: ['rgba(255,215,120,.10)', 'rgba(60,20,90,.18)'] },
    dingin: { icon: '❄️', name: 'Musim Dingin', tint: ['rgba(190,220,255,.22)', 'rgba(40,70,140,.28)'] },
    ramadan: { icon: '🌙', name: 'Ramadan & Lebaran', tint: ['rgba(255,230,160,.10)', 'rgba(20,70,60,.22)'] },
    semi: { icon: '🌸', name: 'Musim Semi', tint: ['rgba(255,190,220,.18)', 'rgba(120,60,120,.18)'] },
    panas: { icon: '☀️', name: 'Musim Panas', tint: ['rgba(255,230,120,.16)', 'rgba(20,40,90,.10)'] },
    merdeka: { icon: '🇮🇩', name: 'Bulan Kemerdekaan', tint: ['rgba(255,120,120,.08)', 'rgba(90,20,30,.14)'] },
    gugur: { icon: '🍂', name: 'Musim Gugur', tint: ['rgba(255,150,60,.18)', 'rgba(110,50,20,.20)'] },
    natal: { icon: '🎄', name: 'Natal', tint: ['rgba(200,230,255,.20)', 'rgba(20,60,50,.22)'], greet: '🎄 Selamat Hari Natal! Damai dan sukacita untuk kita semua.' },
    // ---- Hari raya (acara) ----
    idulfitri: { icon: '🕌', name: 'Idul Fitri', tint: ['rgba(255,230,160,.12)', 'rgba(20,80,50,.22)'], greet: '🕌 Selamat Hari Raya Idul Fitri! Mohon maaf lahir dan batin.' },
    iduladha: { icon: '🐐', name: 'Idul Adha', tint: ['rgba(255,236,170,.12)', 'rgba(30,70,40,.22)'], greet: '🐐 Selamat Hari Raya Idul Adha! Semoga berkah untuk semua.' },
    paskah: { icon: '🥚', name: 'Paskah', tint: ['rgba(255,240,200,.16)', 'rgba(80,60,120,.18)'], greet: '🥚 Selamat Hari Paskah! Damai dan sukacita kebangkitan.' },
    nyepi: { icon: '🕉️', name: 'Nyepi', tint: ['rgba(255,230,190,.10)', 'rgba(5,5,20,.42)'], greet: '🕉️ Selamat Hari Raya Nyepi. Om Shanti Shanti Shanti Om.' },
    waisak: { icon: '🪷', name: 'Waisak', tint: ['rgba(255,220,150,.14)', 'rgba(60,40,10,.22)'], greet: '🪷 Selamat Hari Raya Waisak. Semoga semua makhluk berbahagia.' },
    imlek: { icon: '🧧', name: 'Imlek', tint: ['rgba(255,120,100,.14)', 'rgba(110,10,20,.24)'], greet: '🧧 Gong Xi Fa Cai! Selamat Tahun Baru Imlek.' }
  };
  SEASONS.tahunbaru.greet = '🎆 Selamat Tahun Baru! Semangat belajar di tahun yang baru.';
  SEASONS.merdeka.greet = '🇮🇩 Dirgahayu Republik Indonesia! Merdeka!';
  SEASONS.ramadan.greet = '🌙 Marhaban ya Ramadan. Selamat menunaikan ibadah puasa.';
  // Hari raya: [bulan(0-based), tanggal] per tahun + jendela tampil [hari sebelum, hari sesudah].
  // Perkiraan kalender nasional; hari raya hijriah/lunar bisa bergeser ±1 hari.
  var HOLIDAYS = {
    idulfitri: { win: [0, 6], d: { 2026: [2, 20], 2027: [2, 10], 2028: [1, 27], 2029: [1, 14], 2030: [1, 4] } },
    iduladha: { win: [1, 3], d: { 2026: [4, 27], 2027: [4, 16], 2028: [4, 5], 2029: [3, 24], 2030: [3, 13] } },
    paskah: { win: [2, 1], d: { 2026: [3, 5], 2027: [2, 28], 2028: [3, 16], 2029: [3, 1], 2030: [3, 21] } },
    nyepi: { win: [1, 1], d: { 2026: [2, 19], 2027: [2, 8], 2028: [2, 26], 2029: [2, 15], 2030: [2, 5] } },
    waisak: { win: [1, 2], d: { 2026: [4, 31], 2027: [4, 20], 2028: [4, 9], 2029: [4, 28], 2030: [4, 17] } },
    imlek: { win: [1, 5], d: { 2026: [1, 17], 2027: [1, 6], 2028: [0, 26], 2029: [1, 13], 2030: [1, 3] } }
  };
  function holidayOf(d) {
    var day0 = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(), k;
    for (k in HOLIDAYS) {
      var h = HOLIDAYS[k], ymd = h.d[d.getFullYear()];
      if (!ymd) continue;
      var diff = Math.round((day0 - new Date(d.getFullYear(), ymd[0], ymd[1]).getTime()) / 86400000);
      if (diff >= -h.win[0] && diff <= h.win[1]) return k;
    }
    return '';
  }
  // Perkiraan 1 Ramadan s.d. ±3 hari setelah Idul Fitri (bulan 0-based)
  var RAMADAN = { 2026: [[1, 18], [2, 23]], 2027: [[1, 8], [2, 12]], 2028: [[0, 28], [2, 1]], 2029: [[0, 16], [1, 17]], 2030: [[0, 5], [1, 6]] };

  function seasonOf(d) {
    var hol = holidayOf(d);
    if (hol) return hol;
    var m = d.getMonth(), day = d.getDate(), y = d.getFullYear(), r = RAMADAN[y];
    if (r) {
      var t = m * 100 + day;
      if (t >= r[0][0] * 100 + r[0][1] && t <= r[1][0] * 100 + r[1][1]) return 'ramadan';
    }
    if (m === 0 && day <= 7) return 'tahunbaru';
    if (m === 11 && day >= 20) return 'natal';
    if (m === 11 || m <= 1) return 'dingin';
    if (m <= 4) return 'semi';
    if (m <= 6) return 'panas';
    if (m === 7) return 'merdeka';
    return 'gugur';
  }
  function preview() {
    var q = /[?&]musim=([a-z]+)/.exec(location.search);
    if (q && SEASONS[q[1]]) return q[1];
    try { var p = localStorage.getItem('vc_season_preview'); if (p && SEASONS[p]) return p; } catch (e) {}
    var D = window.__DASH; // musim dipilih pemilik untuk semua pengguna (Domain/DashScene)
    if (D && D.season && D.season !== 'auto' && SEASONS[D.season]) return D.season;
    return '';
  }
  var season = preview() || seasonOf(new Date());
  window.vcSeason = function () { return { id: season, icon: SEASONS[season].icon, name: SEASONS[season].name, greet: SEASONS[season].greet || '' }; };
  window.vcSeasonList = function () { return Object.keys(SEASONS).map(function (k) { return { id: k, icon: SEASONS[k].icon, name: SEASONS[k].name, holiday: !!HOLIDAYS[k] }; }); };
  document.documentElement.setAttribute('data-season', season);

  // ---------- Kanvas ----------
  var cv = document.createElement('canvas');
  cv.className = 'pxd-season';
  cv.width = W; cv.height = H;
  cv.setAttribute('aria-hidden', 'true');
  var tint = document.createElement('div');
  tint.className = 'pxd-season-tint';
  tint.setAttribute('aria-hidden', 'true');
  var charEl = document.getElementById('pxdChar');
  stage.insertBefore(tint, charEl);
  stage.insertBefore(cv, charEl);
  var g = cv.getContext('2d');

  function night() { var p = window.vcDaypart ? window.vcDaypart() : 'malam'; return p === 'malam'; }
  function rnd(a, b) { return a + Math.random() * (b - a); }
  function pick(a) { return a[(Math.random() * a.length) | 0]; }
  function px(x, y, w, h, c) { g.fillStyle = c; g.fillRect(x | 0, y | 0, w, h); }

  // ---------- Partikel ----------
  var parts = [], fireworks = [], stars = [], shoot = null, clouds = [], birds = [];
  var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var lowEnd = (navigator.hardwareConcurrency || 4) <= 2;

  function maxParts() { return lowEnd ? 26 : 55; }
  function spawn(top) {
    var p = { x: rnd(-20, W), y: top ? rnd(-H, 0) : rnd(0, H), t: rnd(0, 6.28), s: rnd(0.6, 1.4) };
    if (season === 'dingin' || season === 'natal') { p.k = 'snow'; p.vx = rnd(-4, 4); p.vy = rnd(10, 22); p.c = Math.random() < 0.8 ? '#ffffff' : '#dbeafe'; p.z = Math.random() < 0.35 ? 3 : 2; }
    else if (season === 'semi') { p.k = 'petal'; p.vx = rnd(6, 16); p.vy = rnd(8, 16); p.c = pick(['#ffc0d9', '#ffb3cf', '#fbd5e5', '#f9a8c9']); }
    else if (season === 'gugur') { p.k = 'leaf'; p.vx = rnd(4, 14); p.vy = rnd(10, 20); p.c = pick(['#e8892f', '#c2410c', '#d9a43a', '#a0522d', '#b91c1c']); }
    else if (season === 'imlek') { p.k = 'confetti'; p.vx = rnd(-6, 6); p.vy = rnd(12, 22); p.c = pick(['#e11d2e', '#ffcf4a', '#e11d2e', '#f59e0b']); }
    else if (season === 'paskah') { p.k = 'petal'; p.vx = rnd(4, 12); p.vy = rnd(8, 14); p.c = pick(['#fde68a', '#c4b5fd', '#a7f3d0', '#fbcfe8']); }
    else if (season === 'waisak') { p.k = 'lantern'; p.x = rnd(20, W - 20); p.y = top ? rnd(GROUND_Y - 20, GROUND_Y + 40) : rnd(20, GROUND_Y); p.vx = rnd(-2, 2); p.vy = -rnd(5, 10); p.c = pick(['#ffcf4a', '#ffb347', '#ffe08a']); }
    else if (season === 'nyepi') { p.k = 'sparkle'; p.x = rnd(0, W); p.y = rnd(0, ROOF_Y - 40); p.vx = 0; p.vy = 0; p.c = '#ffffff'; p.life = rnd(1, 3); }
    else if (season === 'idulfitri' || season === 'iduladha') { p.k = 'sparkle'; p.x = rnd(0, W); p.y = rnd(0, ROOF_Y); p.vx = 0; p.vy = 0; p.c = '#ffe9a8'; p.life = rnd(1, 3); }
    else if (season === 'merdeka' || season === 'tahunbaru') { p.k = 'confetti'; p.vx = rnd(-6, 6); p.vy = rnd(14, 26); p.c = season === 'merdeka' ? pick(['#e11d2e', '#ffffff', '#e11d2e']) : pick(['#ffcf4a', '#f472b6', '#38bdf8', '#a3e635', '#ffffff']); }
    else if (season === 'panas') { p.k = night() ? 'firefly' : 'butterfly'; p.x = rnd(0, W); p.y = rnd(ROOF_Y - 30, GROUND_Y - 6); p.vx = rnd(-6, 6); p.vy = rnd(-4, 4); p.c = p.k === 'firefly' ? '#d9ff7a' : pick(['#fde047', '#f9a8d4', '#93c5fd']); }
    else if (season === 'ramadan') { p.k = 'sparkle'; p.x = rnd(0, W); p.y = rnd(0, ROOF_Y); p.vx = 0; p.vy = 0; p.c = '#ffe9a8'; p.life = rnd(1, 3); }
    return p;
  }
  function fillParts() {
    var n = season === 'panas' ? (lowEnd ? 8 : 16) : (season === 'ramadan' || season === 'idulfitri' || season === 'iduladha' || season === 'nyepi') ? 14 : season === 'waisak' ? (lowEnd ? 8 : 14) : maxParts();
    parts = [];
    for (var i = 0; i < n; i++) parts.push(spawn(false));
  }
  function buildSky() {
    stars = [];
    for (var i = 0; i < (lowEnd ? 40 : 70); i++) stars.push({ x: rnd(0, W), y: rnd(0, 120), p: rnd(0, 6.28), sp: rnd(1.5, 4), big: Math.random() < 0.12 });
    clouds = [];
    for (var j = 0; j < 4; j++) clouds.push({ x: rnd(0, W), y: rnd(8, 70), w: rnd(30, 60), v: rnd(3, 7) });
    birds = [];
  }

  function stepParts(dt) {
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i];
      p.t += dt * 2 * p.s;
      if (p.k === 'firefly' || p.k === 'butterfly') {
        p.vx += rnd(-20, 20) * dt; p.vy += rnd(-20, 20) * dt;
        p.vx = Math.max(-10, Math.min(10, p.vx)); p.vy = Math.max(-8, Math.min(8, p.vy));
        p.x += p.vx * dt; p.y += p.vy * dt;
        if (p.x < 0 || p.x > W || p.y < ROOF_Y - 40 || p.y > GROUND_Y) parts[i] = spawn(false);
        continue;
      }
      if (p.k === 'sparkle') { p.life -= dt; if (p.life <= 0) parts[i] = spawn(false); continue; }
      if (p.k === 'lantern') { p.x += (p.vx + Math.sin(p.t) * 2) * dt; p.y += p.vy * dt; if (p.y < -10) parts[i] = spawn(true); continue; }
      p.x += (p.vx + Math.sin(p.t) * 6) * dt;
      p.y += p.vy * dt;
      if (p.y > GROUND_Y + 4 || p.x > W + 20) { parts[i] = spawn(true); parts[i].y = rnd(-20, 0); }
    }
  }
  function drawParts() {
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i], c = p.c;
      if (p.k === 'snow') px(p.x, p.y, p.z, p.z, c);
      else if (p.k === 'petal') { px(p.x, p.y, 2, 1, c); px(p.x + (Math.sin(p.t) > 0 ? 1 : 0), p.y + 1, 1, 1, c); }
      else if (p.k === 'leaf') { var f = Math.sin(p.t) > 0; px(p.x, p.y, f ? 3 : 2, f ? 2 : 3, c); px(p.x + 1, p.y + 1, 1, 1, '#5b3a22'); }
      else if (p.k === 'confetti') px(p.x, p.y, Math.sin(p.t) > 0 ? 2 : 1, 2, c);
      else if (p.k === 'firefly') { g.globalAlpha = 0.5 + 0.5 * Math.sin(p.t * 2); px(p.x - 1, p.y - 1, 3, 3, 'rgba(217,255,122,.35)'); px(p.x, p.y, 1, 1, c); g.globalAlpha = 1; }
      else if (p.k === 'butterfly') { var o = Math.sin(p.t * 5) > 0; px(p.x - (o ? 2 : 1), p.y, o ? 2 : 1, 2, c); px(p.x + 1, p.y, o ? 2 : 1, 2, c); px(p.x, p.y, 1, 2, '#3a2414'); }
      else if (p.k === 'lantern') { if (night()) px(p.x - 3, p.y - 3, 8, 9, 'rgba(255,200,90,.16)'); px(p.x, p.y, 3, 4, c); px(p.x, p.y + 4, 3, 1, '#c2410c'); }
      else if (p.k === 'sparkle') { g.globalAlpha = Math.max(0, Math.min(1, p.life)); px(p.x, p.y - 1, 1, 3, c); px(p.x - 1, p.y, 3, 1, c); g.globalAlpha = 1; }
    }
  }

  function drawStars(t) {
    for (var i = 0; i < stars.length; i++) {
      var s = stars[i], a = 0.35 + 0.65 * Math.abs(Math.sin(s.p + t * s.sp * 0.5));
      g.globalAlpha = a;
      px(s.x, s.y, 1, 1, '#fff6d8');
      if (s.big && a > 0.8) { px(s.x - 1, s.y, 3, 1, 'rgba(255,246,216,.6)'); px(s.x, s.y - 1, 1, 3, 'rgba(255,246,216,.6)'); }
    }
    g.globalAlpha = 1;
    if (!shoot && Math.random() < 0.004) shoot = { x: rnd(80, W), y: rnd(5, 50), l: 1 };
    if (shoot) {
      for (var k = 0; k < 10; k++) { g.globalAlpha = shoot.l * (1 - k / 10); px(shoot.x + k * 2, shoot.y - k, 2, 1, '#ffffff'); }
      g.globalAlpha = 1;
      shoot.x -= 4; shoot.y += 2; shoot.l -= 0.03;
      if (shoot.l <= 0) shoot = null;
    }
  }
  function drawMoon() {
    var x = 395, y = 30;
    if (season === 'ramadan' || season === 'idulfitri' || season === 'iduladha') { // bulan sabit
      g.fillStyle = 'rgba(255,240,180,.25)'; g.beginPath(); g.arc(x, y, 16, 0, 6.3); g.fill();
      g.fillStyle = '#ffe9a8'; g.beginPath(); g.arc(x, y, 11, 0, 6.3); g.fill();
      g.globalCompositeOperation = 'destination-out'; g.beginPath(); g.arc(x + 5, y - 3, 10, 0, 6.3); g.fill(); g.globalCompositeOperation = 'source-over';
      px(x + 9, y - 10, 1, 3, '#ffe9a8'); px(x + 8, y - 9, 3, 1, '#ffe9a8');
      return;
    }
    var c = season === 'gugur' ? '#ffcf8a' : season === 'semi' ? '#ffe4f0' : '#fff6d8';
    g.fillStyle = 'rgba(255,246,216,.16)'; g.beginPath(); g.arc(x, y, 15, 0, 6.3); g.fill();
    g.fillStyle = c; g.beginPath(); g.arc(x, y, 9, 0, 6.3); g.fill();
    px(x - 4, y - 2, 3, 2, 'rgba(0,0,0,.08)'); px(x + 2, y + 3, 2, 2, 'rgba(0,0,0,.08)');
  }
  function drawClouds(dt) {
    for (var i = 0; i < clouds.length; i++) {
      var c = clouds[i];
      c.x += c.v * dt; if (c.x > W + 10) { c.x = -c.w - 10; c.y = rnd(8, 70); }
      var col = season === 'dingin' || season === 'natal' ? 'rgba(235,242,255,.85)' : 'rgba(255,255,255,.8)';
      px(c.x, c.y + 4, c.w, 6, col); px(c.x + c.w * 0.2, c.y, c.w * 0.45, 5, col); px(c.x + c.w * 0.55, c.y + 1, c.w * 0.3, 4, col);
      px(c.x, c.y + 10, c.w, 1, 'rgba(160,180,210,.5)');
    }
  }
  function drawBirds(dt, t) {
    if (birds.length < 3 && Math.random() < 0.003) birds.push({ x: -10, y: rnd(30, 110), v: rnd(18, 30) });
    for (var i = birds.length - 1; i >= 0; i--) {
      var b = birds[i]; b.x += b.v * dt; b.y += Math.sin(t * 2 + i) * 0.1;
      var up = Math.sin(t * 10 + i) > 0;
      px(b.x - 2, b.y - (up ? 1 : 0), 2, 1, '#2b1d16'); px(b.x, b.y, 1, 1, '#2b1d16'); px(b.x + 1, b.y - (up ? 1 : 0), 2, 1, '#2b1d16');
      if (b.x > W + 10) birds.splice(i, 1);
    }
  }
  function drawFireworks(dt) {
    if (fireworks.length < 3 && Math.random() < 0.02) {
      var col = season === 'merdeka' ? pick(['#ff4d5e', '#ffffff']) : season === 'imlek' ? pick(['#ff4d5e', '#ffcf4a']) : season === 'idulfitri' ? pick(['#ffe9a8', '#4ade80', '#ffffff']) : pick(['#ffcf4a', '#f472b6', '#38bdf8', '#a3e635', '#ffffff']);
      var cx = rnd(60, W - 60), cy = rnd(20, 100), sp = [];
      for (var i = 0; i < 18; i++) { var a = i / 18 * 6.283; sp.push({ vx: Math.cos(a) * rnd(18, 30), vy: Math.sin(a) * rnd(18, 30) }); }
      fireworks.push({ x: cx, y: cy, sp: sp, l: 1.4, c: col, r: 0 });
    }
    for (var j = fireworks.length - 1; j >= 0; j--) {
      var f = fireworks[j];
      f.l -= dt * 0.9; f.r += dt;
      g.globalAlpha = Math.max(0, Math.min(1, f.l));
      for (var k = 0; k < f.sp.length; k++) {
        var s = f.sp[k];
        px(f.x + s.vx * f.r, f.y + s.vy * f.r + 10 * f.r * f.r, 1, 1, f.c);
        px(f.x + s.vx * f.r * 0.8, f.y + s.vy * f.r * 0.8 + 8 * f.r * f.r, 1, 1, 'rgba(255,255,255,.7)');
      }
      g.globalAlpha = 1;
      if (f.l <= 0) fireworks.splice(j, 1);
    }
  }

  // ---------- Dekorasi statis per musim (atap minimarket & tanah) ----------
  function drawDecor(t, isNight) {
    var x, i, sc = (document.getElementById('pxd') || document.body).getAttribute('data-scene');
    if (sc && sc !== 'kota') return; // dekorasi atap/tanah hanya cocok untuk foto kota minimarket
    if (season === 'natal' || season === 'dingin') {
      // salju di atap & trotoar
      px(SHOP_X1, ROOF_Y - 3, SHOP_X2 - SHOP_X1 + 22, 3, '#f8fbff');
      for (x = 0; x < W; x += 7) px(x, GROUND_Y - 2 + (x % 3 === 0 ? -1 : 0), 7, 3, 'rgba(248,251,255,.9)');
      px(485, 221, 106, 2, '#f8fbff'); // atap toko roti
    }
    if (season === 'natal') {
      // lampu warna-warni sepanjang atap
      var cols = ['#ff4d5e', '#ffcf4a', '#4ade80', '#38bdf8'];
      for (i = 0, x = 4; x < SHOP_X2; x += 9, i++) {
        var on = (Math.floor(t * 2) + i) % 4 !== 0;
        px(x, ROOF_Y + 2 + (i % 2), 2, 2, on ? cols[i % 4] : '#3a2a4a');
        if (on && isNight) px(x - 1, ROOF_Y + 1 + (i % 2), 4, 4, 'rgba(255,255,255,.12)');
      }
      // pohon natal kecil di samping pintu
      var tx = 318, ty = GROUND_Y;
      px(tx - 1, ty - 4, 3, 4, '#5b3a22');
      for (var r = 0; r < 5; r++) px(tx - 2 - r * 2, ty - 6 - (4 - r) * 4, 5 + r * 4, 4, r % 2 ? '#1f7a3a' : '#2f8f4e');
      px(tx, ty - 28, 1, 2, '#ffcf4a'); px(tx - 1, ty - 27, 3, 1, '#ffcf4a');
      for (var b = 0; b < 6; b++) px(tx - 7 + ((b * 5) % 14), ty - 22 + b * 3, 1, 1, cols[(b + Math.floor(t * 3)) % 4]);
    }
    if (season === 'ramadan') {
      // lampion/ketupat tergantung di atap
      for (x = 18; x < SHOP_X2; x += 46) {
        px(x, ROOF_Y, 1, 8, '#3a2414');
        var glow = 0.7 + 0.3 * Math.sin(t * 2 + x);
        g.globalAlpha = glow;
        px(x - 3, ROOF_Y + 8, 7, 9, x % 92 === 18 ? '#e8892f' : '#2f8f4e');
        px(x - 2, ROOF_Y + 10, 5, 5, '#ffe9a8');
        if (isNight) px(x - 6, ROOF_Y + 5, 13, 15, 'rgba(255,220,140,.12)');
        g.globalAlpha = 1;
      }
    }
    if (season === 'merdeka') {
      // umbul-umbul merah putih sepanjang atap
      for (x = 0; x < SHOP_X2; x += 8) {
        var y = ROOF_Y + 1 + Math.abs(((x / 8) % 4) - 2);
        px(x, y, 5, 3, '#e11d2e'); px(x, y + 3, 5, 3, '#ffffff'); px(x + 2, y + 6, 1, 1, '#ffffff');
      }
      // bendera di tiang depan
      px(372, GROUND_Y - 70, 1, 70, '#d6d3d1');
      var wv = Math.sin(t * 4) > 0 ? 1 : 0;
      px(373, GROUND_Y - 70 + wv, 16, 5, '#e11d2e'); px(373, GROUND_Y - 65 + wv, 16, 5, '#ffffff');
    }
    if (season === 'idulfitri' || season === 'iduladha') {
      // ketupat tergantung di atap + lampu kuning
      for (i = 0, x = 14; x < SHOP_X2; x += 34, i++) {
        px(x, ROOF_Y, 1, 6 + (i % 2) * 3, '#3a2414');
        var ky = ROOF_Y + 6 + (i % 2) * 3;
        px(x - 1, ky, 3, 1, '#2f8f4e'); px(x - 3, ky + 1, 7, 2, i % 2 ? '#d9b44a' : '#2f8f4e'); px(x - 2, ky + 3, 5, 2, i % 2 ? '#2f8f4e' : '#d9b44a'); px(x - 1, ky + 5, 3, 1, '#2f8f4e');
        px(x + 1, ky + 6, 1, 3, '#ffcf4a');
        if (isNight) px(x - 4, ky - 1, 9, 9, 'rgba(255,230,140,.10)');
      }
    }
    if (season === 'iduladha') {
      // kambing kecil di trotoar (kaki melangkah pelan)
      var gx = 352 + Math.sin(t * 0.4) * 6, gy = GROUND_Y - 9, st = Math.floor(t * 3) % 2;
      px(gx, gy, 10, 5, '#f5f5f4'); px(gx + 9, gy - 3, 4, 4, '#f5f5f4'); px(gx + 12, gy - 1, 1, 1, '#2b1d16');
      px(gx + 9, gy - 5, 1, 2, '#a8a29e'); px(gx + 12, gy - 5, 1, 2, '#a8a29e');
      px(gx + 1, gy + 5, 1, 4 - st, '#57534e'); px(gx + 3, gy + 5, 1, 3 + st, '#57534e'); px(gx + 6, gy + 5, 1, 4 - st, '#57534e'); px(gx + 8, gy + 5, 1, 3 + st, '#57534e');
      px(gx - 1, gy + 1, 1, 2, '#f5f5f4');
    }
    if (season === 'imlek') {
      // lampion merah bulat + hiasan emas
      for (i = 0, x = 16; x < SHOP_X2; x += 40, i++) {
        px(x, ROOF_Y, 1, 5, '#3a2414');
        var sw = Math.round(Math.sin(t * 1.5 + i) * 1);
        px(x - 4 + sw, ROOF_Y + 6, 9, 7, '#d61f26'); px(x - 3 + sw, ROOF_Y + 5, 7, 9, '#d61f26');
        px(x - 4 + sw, ROOF_Y + 9, 9, 1, '#ffcf4a'); px(x - 1 + sw, ROOF_Y + 14, 3, 1, '#ffcf4a'); px(x + sw, ROOF_Y + 15, 1, 3, '#ffcf4a');
        if (isNight) px(x - 7 + sw, ROOF_Y + 2, 15, 16, 'rgba(255,80,60,.14)');
      }
    }
    if (season === 'nyepi') {
      // penjor (bambu melengkung berhias) di dua sisi pintu + canang di tanah
      [[300, 1], [372, -1]].forEach(function (pj) {
        var bx = pj[0], dir = pj[1];
        for (var k = 0; k < 18; k++) px(bx + (k > 12 ? (k - 12) * dir * 2 : 0), GROUND_Y - 4 - k * 5, 2, 5, '#c9a46a');
        px(bx + 12 * dir - 2, GROUND_Y - 96, 5, 6, '#e8d5a3'); px(bx + 12 * dir, GROUND_Y - 90, 1, 8, '#e8d5a3');
        for (var q = 0; q < 6; q++) px(bx - 2, GROUND_Y - 30 - q * 9, 6, 2, '#d9b44a');
      });
      for (x = 320; x < 360; x += 14) { px(x, GROUND_Y - 3, 6, 3, '#4d7c3a'); px(x + 1, GROUND_Y - 4, 1, 1, '#e11d2e'); px(x + 3, GROUND_Y - 4, 1, 1, '#fde047'); px(x + 5, GROUND_Y - 4, 1, 1, '#ffffff'); }
    }
    if (season === 'paskah') {
      var eg = ['#fca5a5', '#fde68a', '#a7f3d0', '#c4b5fd', '#93c5fd', '#fbcfe8'];
      for (i = 0, x = 150; x < 300; x += 24, i++) { px(x, GROUND_Y - 5, 4, 5, eg[i % 6]); px(x + 1, GROUND_Y - 6, 2, 1, eg[i % 6]); px(x, GROUND_Y - 3, 4, 1, '#ffffff'); }
      // kelinci kecil melompat
      var hop = Math.abs(Math.sin(t * 2)) * 4, bx2 = 340;
      px(bx2, GROUND_Y - 6 - hop, 6, 5, '#f5f5f4'); px(bx2 + 4, GROUND_Y - 9 - hop, 4, 4, '#f5f5f4'); px(bx2 + 5, GROUND_Y - 13 - hop, 1, 4, '#f5f5f4'); px(bx2 + 7, GROUND_Y - 13 - hop, 1, 4, '#f5f5f4'); px(bx2 + 6, GROUND_Y - 8 - hop, 1, 1, '#2b1d16');
    }
    if (season === 'waisak') {
      for (x = 140; x < 300; x += 32) { px(x, GROUND_Y - 3, 8, 2, '#ec8fb5'); px(x + 2, GROUND_Y - 5, 4, 2, '#f9c4d8'); px(x + 3, GROUND_Y - 8, 2, 3, '#ffcf4a'); if (isNight) px(x - 2, GROUND_Y - 12, 12, 10, 'rgba(255,207,74,.14)'); }
    }
    if (season === 'semi') {
      for (x = 140; x < 300; x += 47) { px(x, GROUND_Y - 4, 4, 4, '#f9a8c9'); px(x + 1, GROUND_Y - 6, 2, 2, '#fde047'); }
    }
    if (season === 'gugur') {
      for (x = 3; x < W; x += 11) px(x, GROUND_Y - 1, 3, 2, ['#e8892f', '#c2410c', '#d9a43a'][x % 3]);
    }
  }

  // ---------- Loop ----------
  var last = 0, acc = 0, t0 = 0, running = false;
  function frame(ts) {
    if (!running) return;
    requestAnimationFrame(frame);
    if (document.hidden) { last = ts; return; }
    var dt = last ? Math.min(0.1, (ts - last) / 1000) : 0.016;
    last = ts; acc += dt;
    if (acc < 1 / 30) return; // ±30 fps cukup untuk pixel art
    dt = acc; acc = 0; t0 += dt;
    draw(dt, t0);
  }
  function draw(dt, t) {
    var isNight = night();
    g.clearRect(0, 0, W, H);
    if (isNight) { drawStars(t); drawMoon(); }
    else { drawClouds(dt); drawBirds(dt, t); }
    if ((season === 'tahunbaru' || season === 'merdeka' || season === 'imlek' || season === 'idulfitri') && (isNight || season === 'tahunbaru')) drawFireworks(dt);
    drawDecor(t, isNight);
    stepParts(dt);
    drawParts();
  }
  function applyTint() {
    var s = SEASONS[season];
    tint.style.background = 'linear-gradient(180deg,' + s.tint[night() ? 1 : 0] + ',rgba(0,0,0,0) 75%)';
  }

  // Ucapan hari raya / musim: pita kecil di atas panggung, sekali per hari per perangkat.
  function greet() {
    var txt = SEASONS[season].greet;
    if (!txt) return;
    var key = 'vc_greet_' + season + '_' + new Date().toDateString();
    try { if (localStorage.getItem(key) && !preview()) return; localStorage.setItem(key, '1'); } catch (e) {}
    var el = document.createElement('div');
    el.className = 'pxd-greet';
    el.setAttribute('role', 'status');
    el.textContent = txt;
    (document.getElementById('pxd') || document.body).appendChild(el);
    setTimeout(function () { el.classList.add('is-out'); }, 9000);
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 10000);
  }

  buildSky();
  fillParts();
  applyTint();
  setTimeout(greet, 1600);
  window.addEventListener('vc:daypart', function () { applyTint(); if (season === 'panas') fillParts(); });
  if (reduced) { draw(0, 0); return; }
  running = true;
  requestAnimationFrame(frame);
})();
