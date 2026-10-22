/*
 * Dash Scene — latar dashboard yang dipilih PEMILIK untuk semua pengguna (window.__DASH dari server,
 * Domain/DashScene.php). Berjalan SETELAH pixel-season.js dan SEBELUM pixel-dashboard.js:
 *  1. Pilih adegan: tetap (pilihan pemilik) atau "otomatis" = adegan yang cocok dengan musim berjalan,
 *     bergilir tiap hari. Pratinjau per perangkat: /?adegan=<id>.
 *  2. Waktu (pagi/siang/sore/malam) mengikuti jam perangkat, atau dipaksa pemilik.
 *  3. Susun latar: gambar (gerak pelan "Ken Burns" berporos di kaki karakter) atau video loop;
 *     pewarnaan sesuai waktu; efek bergerak per adegan (kelopak, daun, kilau laut, awan, kabut).
 *  4. Karakter & teman mabar berdiri di titik pijak adegan (CSS var --pxd-cx / --pxd-gb / --pxd-cw);
 *     window.__DASHSCENE dipakai pixel-dashboard.js untuk ukuran panggung.
 * ES2017.
 */
(function () {
  'use strict';
  var pxd = document.getElementById('pxd'), stage = document.getElementById('pxdStage');
  var D = window.__DASH;
  if (!pxd || !stage || !D || !D.scenes) return;
  var reduced = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  var saveData = !!(navigator.connection && navigator.connection.saveData);

  // ---------- 1. Waktu ----------
  var qw = /[?&]waktu=(pagi|siang|sore|malam)/.exec(location.search); // pratinjau per perangkat
  var forceDp = qw ? qw[1] : (D.daypart && D.daypart !== 'auto' ? D.daypart : '');
  if (forceDp) {
    window.__vcDaypartForce = forceDp;
    if (window.vcApplyDaypart) window.vcApplyDaypart();
  }

  // ---------- 2. Pilih adegan ----------
  var ids = Object.keys(D.scenes);
  var q = /[?&]adegan=([a-z0-9-]+)/.exec(location.search);
  var id = q && D.scenes[q[1]] ? q[1] : D.scene;
  if (!id || id === 'auto' || !D.scenes[id]) {
    var season = window.vcSeason ? window.vcSeason().id : '';
    var fit = ids.filter(function (k) { return (D.scenes[k].seasons || []).indexOf(season) >= 0; });
    if (!fit.length) fit = ['kota'];
    var now = new Date(), doy = Math.floor((now - new Date(now.getFullYear(), 0, 0)) / 864e5);
    id = fit[doy % fit.length];
  }
  var S = D.scenes[id] || D.scenes.kota;
  S.id = id;
  window.__DASHSCENE = S;
  pxd.setAttribute('data-scene', id);

  // ---------- 3. Titik pijak karakter ----------
  var cw = S.charH * S.h / S.w * (22 / 42) * 100; // lebar kanvas karakter (22×42) dalam % lebar panggung
  stage.style.setProperty('--pxd-cx', (S.charX * 100) + '%');
  stage.style.setProperty('--pxd-gb', ((1 - S.ground) * 100) + '%');
  stage.style.setProperty('--pxd-gy', (S.ground * 100) + '%');
  stage.style.setProperty('--pxd-cw', cw.toFixed(2) + '%');
  if (id === 'kota') return; // adegan bawaan: latar & dekorasi lama
  if (D.effects !== 'on') pxd.setAttribute('data-fx', 'off'); // efek musim/waktu dimatikan pemilik → terang & kontras

  // ---------- 4. Latar ----------
  var wrap = document.createElement('div');
  wrap.className = 'pxd-scene pxd-scene--' + (S.type === 'video' ? 'video' : 'img');
  wrap.setAttribute('aria-hidden', 'true');
  var bg;
  if (S.type === 'video' && !reduced && !saveData) {
    bg = document.createElement('video');
    bg.muted = true; bg.loop = true; bg.autoplay = true; bg.playsInline = true;
    bg.setAttribute('muted', ''); bg.setAttribute('playsinline', ''); bg.setAttribute('preload', 'auto');
    bg.poster = S.img;
    if (S.webm) { var s1 = document.createElement('source'); s1.src = S.webm; s1.type = 'video/webm'; bg.appendChild(s1); }
    if (S.mp4) { var s2 = document.createElement('source'); s2.src = S.mp4; s2.type = 'video/mp4'; bg.appendChild(s2); }
    var tryPlay = function () { var p = bg.play(); if (p && p.catch) p.catch(function () {}); };
    bg.addEventListener('canplay', tryPlay, { once: true });
    document.addEventListener('visibilitychange', function () { if (document.hidden) bg.pause(); else tryPlay(); });
  } else {
    bg = document.createElement('img');
    bg.src = S.img; bg.alt = ''; bg.decoding = 'async';
  }
  bg.className = 'pxd-scene__bg';
  bg.style.transformOrigin = (S.charX * 100) + '% ' + (S.ground * 100) + '%';
  wrap.appendChild(bg);
  var shade = document.createElement('div'); shade.className = 'pxd-scene__tone'; wrap.appendChild(shade);
  stage.insertBefore(wrap, stage.firstChild);

  // ---------- 5. Efek bergerak per adegan ----------
  if (reduced || !S.fx || S.fx === 'none') return;
  var cv = document.createElement('canvas');
  cv.className = 'pxd-scene__fx';
  var W = 320, H = Math.round(320 * S.h / S.w);
  cv.width = W; cv.height = H;
  wrap.appendChild(cv);
  var g = cv.getContext('2d'), parts = [], t0 = 0, last = 0;
  var lowEnd = (navigator.hardwareConcurrency || 4) <= 2;
  var N = { petals: 34, leaves: 24, sea: 40, clouds: 5, mist: 7 }[S.fx] || 0;
  if (lowEnd) N = Math.ceil(N / 2);
  function rnd(a, b) { return a + Math.random() * (b - a); }
  function seaY() { return H * (S.id === 'pelabuhan' ? rnd(0.6, 0.78) : rnd(0.55, 0.8)); }
  function spawn(p, init) {
    p = p || {};
    if (S.fx === 'petals' || S.fx === 'leaves') {
      p.x = init ? rnd(0, W) : rnd(-20, W * 0.9); p.y = init ? rnd(0, H) : rnd(-20, -4);
      p.vx = rnd(8, 22); p.vy = rnd(10, 22); p.r = rnd(0, 6.28); p.vr = rnd(-3, 3); p.s = rnd(1.2, 2.6);
      p.c = S.fx === 'petals' ? (Math.random() < 0.7 ? '#ffc4d6' : '#ff9fbf') : ['#e8833a', '#d9622b', '#f2b34a', '#b5532a'][(Math.random() * 4) | 0];
    } else if (S.fx === 'sea') {
      p.x = rnd(W * 0.3, W * 0.75); p.y = seaY(); p.l = rnd(0.6, 2); p.t = init ? rnd(0, p.l) : 0; p.w = rnd(2, 5);
    } else if (S.fx === 'clouds') {
      p.x = init ? rnd(-60, W) : -70; p.y = rnd(H * 0.05, H * 0.4); p.vx = rnd(2, 5); p.w = rnd(36, 70); p.h = p.w * rnd(0.22, 0.32); p.a = rnd(0.25, 0.45);
    } else if (S.fx === 'mist') {
      p.x = init ? rnd(-80, W) : -90; p.y = rnd(H * 0.45, H * 0.85); p.vx = rnd(1.5, 3.5); p.w = rnd(80, 150); p.h = rnd(10, 22); p.a = rnd(0.08, 0.16);
    }
    return p;
  }
  for (var i = 0; i < N; i++) parts.push(spawn(null, true));
  function frame(ts) {
    requestAnimationFrame(frame);
    if (document.hidden) { last = ts; return; }
    var dt = last ? Math.min(0.1, (ts - last) / 1000) : 0; last = ts;
    t0 += dt;
    if (t0 < 1 / 30) return;
    dt = t0; t0 = 0;
    g.clearRect(0, 0, W, H);
    var night = (document.documentElement.getAttribute('data-daypart') === 'malam');
    for (var k = 0; k < parts.length; k++) {
      var p = parts[k];
      if (S.fx === 'petals' || S.fx === 'leaves') {
        p.x += (p.vx + Math.sin(p.y * 0.05) * 6) * dt; p.y += p.vy * dt; p.r += p.vr * dt;
        if (p.y > H + 6 || p.x > W + 10) spawn(p, false);
        g.save(); g.translate(p.x, p.y); g.rotate(p.r); g.fillStyle = p.c;
        g.fillRect(-p.s, -p.s / 2, p.s * 2, p.s); g.restore();
      } else if (S.fx === 'sea') {
        p.t += dt;
        if (p.t > p.l) spawn(p, false);
        var a = Math.sin(p.t / p.l * Math.PI);
        g.fillStyle = night ? 'rgba(255,240,190,' + (a * 0.55) + ')' : 'rgba(255,255,255,' + (a * 0.8) + ')';
        g.fillRect(p.x | 0, p.y | 0, p.w | 0, 1);
      } else {
        p.x += p.vx * dt;
        if (p.x > W + 10) spawn(p, false);
        g.fillStyle = S.fx === 'mist' ? 'rgba(235,238,242,' + p.a + ')' : 'rgba(255,255,255,' + p.a + ')';
        g.beginPath(); if (g.ellipse) g.ellipse(p.x, p.y, p.w / 2, p.h / 2, 0, 0, 6.283); else g.rect(p.x - p.w / 2, p.y - p.h / 2, p.w, p.h); g.fill();
      }
    }
  }
  requestAnimationFrame(frame);
})();
