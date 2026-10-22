/*
 * KEHIDUPAN DUNIA — Dunia 3D & Pixel:
 *  🐱 Kucing sekolah berkeliaran (5 kucing berbeda). Dekati → [E]/✋ "Elus" → meong + hati.
 *  🧹 Sampah berserakan (kertas, kaleng, botol). Dekati → "Pungut" → hilang, muncul lagi ±3 menit.
 *  🎯 Penanda misi mabar (bila sedang di party dengan misi): progres dari elus kucing, pungut sampah,
 *     kunjungan lokasi (server) & anomali (anomaly.js → vcWorldLife.report).
 * Lokal per pemain (tidak disinkron), ringan: beberapa mesh / kotak piksel saja.
 * Memakai kait mesin: world.extraTargets (3D) · pix.extraTargets + pix.drawHooks (Pixel).
 * ES2017; `world` / `pix` = variabel global halaman (let).
 */
(function () {
  'use strict';
  function get3d() { return (typeof world !== 'undefined' && world && world.scene && world.playerPos) ? world : null; } // eslint-disable-line no-undef
  function getPix() { return (typeof pix !== 'undefined' && pix && pix.player && pix.scene) ? pix : null; } // eslint-disable-line no-undef
  function rnd(a, b) { return a + Math.random() * (b - a); }
  function csrf() { return (document.querySelector('meta[name="csrf"]') || {}).content || ''; }
  function hash(s) { var h = 2166136261; for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; }
  function toast(t) {
    if (window.UI && window.UI.toast) { window.UI.toast(t, 'gold', 2600); return; }
    var el = document.createElement('div');
    el.setAttribute('role', 'status');
    el.style.cssText = 'position:fixed;left:50%;bottom:90px;transform:translateX(-50%);z-index:9000;background:rgba(32,19,10,.92);color:#fff6e0;border:2px solid #d9a441;padding:7px 14px;font:700 14px "Pixelify Sans",system-ui,sans-serif;max-width:calc(100vw - 32px);text-align:center';
    el.textContent = t;
    document.body.appendChild(el);
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 2600);
  }

  var CATS = [
    { name: 'Mpus Oren', fur: 0xf59e0b, furCss: '#f59e0b', belly: '#fde68a' },
    { name: 'Si Belang', fur: 0xe5e7eb, furCss: '#e5e7eb', belly: '#ffffff', patch: '#1f2937' },
    { name: 'Hitam Manis', fur: 0x1f1f24, furCss: '#1f1f24', belly: '#3f3f46' },
    { name: 'Abu Kalem', fur: 0x9ca3af, furCss: '#9ca3af', belly: '#e5e7eb' },
    { name: 'Calico', fur: 0xfafaf9, furCss: '#fafaf9', belly: '#ffffff', patch: '#c2410c' }
  ];
  var TRASH = [
    { name: 'kertas', css: '#f8fafc', col: 0xf1f5f9 },
    { name: 'kaleng', css: '#dc2626', col: 0xdc2626 },
    { name: 'botol', css: '#38bdf8', col: 0x7dd3fc },
    { name: 'bungkus', css: '#facc15', col: 0xfacc15 }
  ];

  // ---------------- suara meong (WebAudio, tanpa berkas) ----------------
  var actx = null;
  function meow() {
    try {
      if (!actx) { var C = window.AudioContext || window.webkitAudioContext; if (!C) return; actx = new C(); }
      if (actx.state === 'suspended') actx.resume();
      var t = actx.currentTime, o = actx.createOscillator(), f = actx.createBiquadFilter(), g = actx.createGain();
      o.type = 'sawtooth';
      o.frequency.setValueAtTime(520, t); o.frequency.linearRampToValueAtTime(820, t + 0.16); o.frequency.exponentialRampToValueAtTime(430, t + 0.5);
      f.type = 'bandpass'; f.frequency.setValueAtTime(1100, t); f.frequency.linearRampToValueAtTime(1800, t + 0.18); f.frequency.linearRampToValueAtTime(900, t + 0.5); f.Q.value = 4;
      g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.12, t + 0.05); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.55);
      o.connect(f); f.connect(g); g.connect(actx.destination); o.start(t); o.stop(t + 0.6);
    } catch (e) { /* opsional */ }
  }
  function pop() {
    try {
      if (!actx) { var C = window.AudioContext || window.webkitAudioContext; if (!C) return; actx = new C(); }
      var t = actx.currentTime, o = actx.createOscillator(), g = actx.createGain();
      o.type = 'square'; o.frequency.setValueAtTime(330, t); o.frequency.exponentialRampToValueAtTime(880, t + 0.08);
      g.gain.setValueAtTime(0.06, t); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.12);
      o.connect(g); g.connect(actx.destination); o.start(t); o.stop(t + 0.13);
    } catch (e) { /* opsional */ }
  }

  // =====================================================================
  //  MISI MABAR — penanda progres + laporan kejadian
  // =====================================================================
  var Q = { party: null, quest: null, el: null, timer: null, lastReport: 0 };
  function pill() {
    if (!Q.el) {
      Q.el = document.createElement('div');
      Q.el.id = 'vcQuestPill';
      Q.el.setAttribute('role', 'status');
      Q.el.style.cssText = 'position:fixed;left:50%;top:max(58px,env(safe-area-inset-top));transform:translateX(-50%);z-index:60;pointer-events:none;'
        + 'background:rgba(32,19,10,.86);color:#fff6e0;border:2px solid #d9a441;padding:4px 10px;font:700 13px "Pixelify Sans",system-ui,sans-serif;white-space:nowrap;max-width:calc(100vw - 24px);overflow:hidden;text-overflow:ellipsis';
      document.body.appendChild(Q.el);
    }
    // Dunia 3D: di bawah kotak "Tujuan" HUD; Pixel: di bawah topbar
    Q.el.style.top = get3d() ? 'max(140px,calc(env(safe-area-inset-top) + 140px))' : 'max(62px,env(safe-area-inset-top))';
    var q = Q.quest;
    Q.el.hidden = !q;
    if (q) Q.el.textContent = '🎯 Mabar · ' + q.icon + ' ' + q.title + ' ' + (q.done ? '✅ selesai!' : q.progress + '/' + q.target);
  }
  function setQuest(q, announce) {
    var before = Q.quest;
    Q.quest = q || null;
    pill();
    if (announce && q && before && q.key === before.key && q.progress > before.progress) {
      if (q.done && !before.done) toast('🎉 Misi mabar selesai: ' + q.title + '! +' + q.xp + ' XP · +' + q.coins + ' koin');
      else toast('🎯 ' + q.title + ': ' + q.progress + '/' + q.target);
    }
  }
  function loadParty() {
    return fetch('/api/party', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (!d || d.status !== 'ok') return;
      Q.party = d.party;
      setQuest(d.party ? d.party.quest : null, true);
      clearInterval(Q.timer);
      // progres anggota lain / kunjungan → sinkron jarang (hemat baterai), hanya saat di party
      if (d.party) Q.timer = setInterval(function () { if (!document.hidden) loadParty(); }, 30000);
    }).catch(function () {});
  }
  /** Laporkan kejadian misi (cat | trash | anomaly). Tanpa party/misi yang cocok → tidak dikirim. */
  function report(event, ref) {
    if (window.vcDaily && (event === 'cat' || event === 'trash')) window.vcDaily.report(event, ref); // misi harian
    if (!Q.quest || Q.quest.done || Q.quest.event !== event) return;
    fetch('/api/party/action', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() },
      body: JSON.stringify({ action: 'report', event: event, ref: String(ref) }) })
      .then(function (r) { return r.json(); }).then(function (d) { if (d && d.quest) setQuest(d.quest, true); }).catch(function () {});
  }
  window.vcWorldLife = { report: report, refresh: loadParty, state: Q };

  // Kunjungan lokasi dicatat server (/api/world/visit) → baca hasilnya tanpa mengubah kode halaman.
  if (window.fetch) {
    var of = window.fetch;
    window.fetch = function (u, o) {
      var p = of.apply(this, arguments);
      if (typeof u === 'string' && u.indexOf('/api/world/visit') === 0) {
        p.then(function (r) { return r.clone().json(); }).then(function (d) { if (d && d.party_quest) setQuest(d.party_quest, true); }).catch(function () {});
      }
      return p;
    };
  }

  // =====================================================================
  //  DUNIA 3D
  // =====================================================================
  var W3 = { cats: [], trash: [], ready: false, gen: 0 };
  function spot3d(w, near, minD, maxD) {
    for (var i = 0; i < 30; i++) {
      var a = rnd(0, Math.PI * 2), d = rnd(minD, maxD);
      var x = near.x + Math.sin(a) * d, z = near.z + Math.cos(a) * d;
      if (Math.abs(x) > 180 || Math.abs(z) > 100) continue;
      if (w.checkCollision && w.checkCollision(x, z)) continue;
      return { x: x, z: z };
    }
    return null;
  }
  function catMesh(T, c) {
    var g = new T.Group();
    var fur = new T.MeshLambertMaterial({ color: c.fur });
    var dark = new T.MeshBasicMaterial({ color: 0x111111 });
    var body = new T.Mesh(new T.SphereGeometry(0.55, 10, 8), fur); body.scale.set(0.8, 0.7, 1.35); body.position.y = 0.62;
    var head = new T.Mesh(new T.SphereGeometry(0.4, 10, 8), fur); head.position.set(0, 1.05, 0.72);
    var earG = new T.ConeGeometry(0.13, 0.28, 4);
    var le = new T.Mesh(earG, fur); le.position.set(-0.2, 1.42, 0.7);
    var re = new T.Mesh(earG, fur); re.position.set(0.2, 1.42, 0.7);
    var tail = new T.Mesh(new T.CylinderGeometry(0.06, 0.09, 0.95, 6), fur); tail.position.set(0, 1.0, -0.78); tail.rotation.x = -0.6;
    var ey1 = new T.Mesh(new T.SphereGeometry(0.06, 6, 6), dark); ey1.position.set(-0.14, 1.1, 1.08);
    var ey2 = new T.Mesh(new T.SphereGeometry(0.06, 6, 6), dark); ey2.position.set(0.14, 1.1, 1.08);
    var legG = new T.CylinderGeometry(0.09, 0.09, 0.4, 6), legs = [];
    [[-0.25, 0.4], [0.25, 0.4], [-0.25, -0.4], [0.25, -0.4]].forEach(function (q) { var l = new T.Mesh(legG, fur); l.position.set(q[0], 0.2, q[1]); legs.push(l); g.add(l); });
    [body, head, le, re, tail, ey1, ey2].forEach(function (m) { g.add(m); });
    g.userData = { tail: tail, legs: legs, head: head };
    return g;
  }
  function trashMesh(T, t) {
    var mat = new T.MeshLambertMaterial({ color: t.col });
    var m;
    if (t.name === 'kertas') m = new T.Mesh(new T.IcosahedronGeometry(0.32, 0), mat);
    else if (t.name === 'kaleng') { m = new T.Mesh(new T.CylinderGeometry(0.17, 0.17, 0.5, 8), mat); m.rotation.z = Math.PI / 2; }
    else if (t.name === 'botol') { m = new T.Mesh(new T.CylinderGeometry(0.14, 0.18, 0.75, 8), new T.MeshLambertMaterial({ color: t.col, transparent: true, opacity: 0.8 })); m.rotation.z = Math.PI / 2; }
    else m = new T.Mesh(new T.BoxGeometry(0.5, 0.06, 0.35), mat);
    var g = new T.Group(); m.position.y = 0.2; g.add(m);
    // cincin kecil agar terlihat di HP
    var ring = new T.Mesh(new T.RingGeometry(0.5, 0.62, 16), new T.MeshBasicMaterial({ color: 0xfacc15, transparent: true, opacity: 0.5, side: T.DoubleSide, depthWrite: false }));
    ring.rotation.x = -Math.PI / 2; ring.position.y = 0.04; g.add(ring);
    return g;
  }
  function setup3d(w) {
    var T = window.THREE;
    if (!T) return;
    W3.ready = true;
    var p = w.playerPos;
    CATS.forEach(function (c, i) {
      var s = spot3d(w, p, 12, 60) || { x: p.x + 6 + i * 3, z: p.z + 6 };
      var m = catMesh(T, c);
      w.scene.add(m);
      W3.cats.push({ i: i, c: c, m: m, x: s.x, z: s.z, tx: s.x, tz: s.z, yaw: rnd(0, 6), wait: rnd(1, 4), hop: 0, petT: 0 });
    });
    for (var k = 0; k < 14; k++) spawnTrash3d(w, k);
  }
  function spawnTrash3d(w, k) {
    var T = window.THREE, p = w.playerPos;
    var s = spot3d(w, p, 10, 85);
    var old = W3.trash[k];
    if (old && old.m) w.scene.remove(old.m);
    if (!s) { W3.trash[k] = { gone: true, back: 30 }; return; }
    var t = TRASH[k % TRASH.length], m = trashMesh(T, t);
    m.rotation.y = rnd(0, 6);
    m.position.set(s.x, w.groundHeightAt ? w.groundHeightAt(s.x, s.z, 2) : 0, s.z);
    w.scene.add(m);
    W3.gen++;
    W3.trash[k] = { k: k, t: t, m: m, x: s.x, z: s.z, gone: false, back: 0, ref: 't' + k + 'g' + W3.gen };
  }
  function tick3d(w, dt) {
    if (!W3.ready) setup3d(w);
    var p = w.playerPos, floor1 = (w.currentFloor || 1) === 1;
    W3.cats.forEach(function (ct) {
      ct.m.visible = floor1;
      var d = Math.hypot(ct.tx - ct.x, ct.tz - ct.z);
      if (ct.petT > 0) { ct.petT -= dt; ct.hop = Math.max(0, Math.sin((1.2 - ct.petT) * 9) * 0.35); }
      else if (d < 0.3) {
        ct.wait -= dt;
        if (ct.wait <= 0) {
          // kadang mendekati pemain (penasaran), kadang jalan-jalan sendiri
          var base = Math.random() < 0.3 ? p : { x: ct.x, z: ct.z };
          var s = spot3d(w, base, 3, 14);
          if (s) { ct.tx = s.x; ct.tz = s.z; }
          ct.wait = rnd(2, 6);
        }
      } else {
        var sp = 2.2 * dt, nx = ct.x + (ct.tx - ct.x) / d * Math.min(sp, d), nz = ct.z + (ct.tz - ct.z) / d * Math.min(sp, d);
        if (w.checkCollision && w.checkCollision(nx, nz)) { ct.tx = ct.x; ct.tz = ct.z; }
        else { ct.x = nx; ct.z = nz; ct.yaw = Math.atan2(ct.tx - ct.x, ct.tz - ct.z); }
      }
      // terlalu jauh dari pemain (> 140) → pindah dekat lagi agar tetap bisa ditemukan
      if (Math.hypot(p.x - ct.x, p.z - ct.z) > 140) { var r = spot3d(w, p, 30, 60); if (r) { ct.x = ct.tx = r.x; ct.z = ct.tz = r.z; } }
      var gy = w.groundHeightAt ? w.groundHeightAt(ct.x, ct.z, 2) : 0;
      ct.m.position.set(ct.x, gy + ct.hop, ct.z);
      ct.m.rotation.y = ct.yaw;
      var walk = d >= 0.3 && ct.petT <= 0, ph = performance.now() / 110;
      ct.m.userData.legs.forEach(function (l, i) { l.rotation.x = walk ? Math.sin(ph + (i % 2 ? Math.PI : 0)) * 0.5 : 0; });
      ct.m.userData.tail.rotation.z = Math.sin(performance.now() / 380 + ct.i) * 0.5;
    });
    W3.trash.forEach(function (tr, k) {
      if (tr.gone) { tr.back -= dt; if (tr.back <= 0) spawnTrash3d(w, k); return; }
      tr.m.visible = floor1;
      if (Math.hypot(p.x - tr.x, p.z - tr.z) > 150) spawnTrash3d(w, k);
    });
  }
  function targets3d(pp) {
    var w = get3d();
    if (!w || (w.currentFloor || 1) !== 1) return null;
    var best = null, bd = 4.2;
    W3.cats.forEach(function (ct) {
      var d = Math.hypot(pp.x - ct.x, pp.z - ct.z);
      if (d < bd) { bd = d; best = { name: ct.c.name, prompt: '🐱 Elus ' + ct.c.name, dist: d, pos: ct.m.position.clone(), action: function () { pet3d(ct); } }; }
    });
    W3.trash.forEach(function (tr) {
      if (tr.gone) return;
      var d = Math.hypot(pp.x - tr.x, pp.z - tr.z);
      if (d < bd) { bd = d; best = { name: 'Sampah', prompt: '🧹 Pungut ' + tr.t.name, dist: d, pos: tr.m.position.clone(), action: function () { pick3d(tr); } }; }
    });
    return best;
  }
  function pet3d(ct) {
    ct.petT = 1.2; ct.tx = ct.x; ct.tz = ct.z;
    var w = get3d();
    if (w) ct.yaw = Math.atan2(w.playerPos.x - ct.x, w.playerPos.z - ct.z);
    meow();
    toast('🐱 ' + ct.c.name + ': "Meong~" 💕');
    report('cat', 'k3d' + ct.i);
  }
  function pick3d(tr) {
    var w = get3d();
    if (w && tr.m) w.scene.remove(tr.m);
    tr.gone = true; tr.back = rnd(150, 210);
    pop();
    toast('🧹 Sampah ' + tr.t.name + ' dibuang ke tempatnya. Terima kasih!');
    report('trash', tr.ref);
  }

  // =====================================================================
  //  DUNIA PIXEL (side-view)
  // =====================================================================
  var WP = { scene: '', cats: [], trash: [], picked: {}, hooked: false, hearts: [] };
  function R(g, x, y, w, h, c) { g.fillStyle = c; g.fillRect(Math.round(x), Math.round(y), w, h); }
  function setupScene(P) {
    var sc = P.scene, h = hash(sc.id), lo = sc.walls[0] + 30, hi = sc.walls[1] - 30;
    WP.scene = sc.id; WP.cats = []; WP.trash = [];
    if (hi <= lo) return;
    // kucing: halaman 3 ekor, koridor kadang 1, beberapa ruangan 1
    var nCat = sc.kind === 'outdoor' ? 3 : (h % 3 === 0 ? 1 : 0);
    for (var i = 0; i < nCat; i++) {
      var ci = (h + i * 7) % CATS.length;
      var x = lo + ((h >>> (i * 3)) % 1000) / 1000 * (hi - lo);
      WP.cats.push({ i: ci, c: CATS[ci], x: x, tx: x, face: 1, wait: rnd(1, 4), walkT: 0, petT: 0 });
    }
    // sampah: halaman 6, koridor 2, ruangan kadang 1
    var nT = sc.kind === 'outdoor' ? 6 : sc.kind === 'corridor' ? 2 : (h % 4 === 1 ? 1 : 0);
    for (var k = 0; k < nT; k++) {
      var ref = 'p' + (hash(sc.id + ':' + k) % 100000);
      var tx = lo + (((h >>> 5) + k * 2654435761) >>> 0) % 1000 / 1000 * (hi - lo);
      WP.trash.push({ k: k, t: TRASH[(h + k) % TRASH.length], x: tx, ref: ref });
    }
  }
  function drawCat(g, ct, sx, G) {
    var c = ct.c, f = ct.face, hop = ct.petT > 0 ? Math.round(Math.abs(Math.sin((1.2 - ct.petT) * 9)) * 4) : 0, y = G - hop;
    var step = ct.walkT > 0 ? Math.floor(ct.walkT * 8) % 2 : 0;
    R(g, sx - 8, G - 1, 16, 2, 'rgba(0,0,0,.35)');                     // bayangan
    R(g, sx - 6, y - 7, 12, 5, c.furCss);                                // badan
    if (c.patch) R(g, sx - 3 * f - 2, y - 7, 4, 3, c.patch);
    R(g, sx - 5, y - 3, 2, 3 - step, c.furCss); R(g, sx + 3, y - 3, 2, 2 + step, c.furCss); // kaki
    var hx = sx + f * 6 - 3;
    R(g, hx, y - 11, 6, 5, c.furCss);                                    // kepala
    R(g, hx, y - 13, 2, 2, c.furCss); R(g, hx + 4, y - 13, 2, 2, c.furCss); // telinga
    R(g, hx + (f > 0 ? 3 : 1), y - 9, 1, 1, '#111'); R(g, hx + (f > 0 ? 5 : 0), y - 9, 1, 1, '#111'); // mata
    var tx = sx - f * 7, wag = Math.round(Math.sin(performance.now() / 300 + ct.i) * 1.5);
    R(g, tx - (f > 0 ? 1 : 0), y - 11 + wag, 2, 6, c.furCss);           // ekor
  }
  function drawTrash(g, tr, sx, G) {
    var t = tr.t;
    if (t.name === 'kertas') { R(g, sx - 3, G - 5, 6, 5, t.css); R(g, sx - 1, G - 6, 3, 1, t.css); R(g, sx - 2, G - 3, 2, 1, '#cbd5e1'); }
    else if (t.name === 'kaleng') { R(g, sx - 4, G - 4, 8, 4, t.css); R(g, sx - 4, G - 4, 1, 4, '#e5e7eb'); }
    else if (t.name === 'botol') { R(g, sx - 5, G - 3, 9, 3, t.css); R(g, sx + 4, G - 2, 2, 1, '#0ea5e9'); }
    else { R(g, sx - 4, G - 2, 8, 2, t.css); R(g, sx - 2, G - 3, 4, 1, '#ef4444'); }
    // kilau kecil (tanda bisa dipungut)
    if (Math.floor(performance.now() / 400) % 3 === 0) R(g, sx + 2, G - 8, 1, 1, '#fff6a0');
  }
  function drawPix(g, camX, G) {
    var P = getPix();
    if (!P || WP.scene !== P.scene.id) return;
    WP.trash.forEach(function (tr) { if (!WP.picked[tr.ref]) drawTrash(g, tr, tr.x - camX, G); });
    WP.cats.forEach(function (ct) { drawCat(g, ct, ct.x - camX, G); });
    for (var i = WP.hearts.length - 1; i >= 0; i--) {
      var hh = WP.hearts[i];
      if (hh.t <= 0) { WP.hearts.splice(i, 1); continue; }
      var sx = hh.x - camX, sy = G - 22 - (1.2 - hh.t) * 18;
      g.save(); g.globalAlpha = Math.min(1, hh.t * 1.5);
      R(g, sx - 3, sy, 2, 2, '#ef4444'); R(g, sx + 1, sy, 2, 2, '#ef4444'); R(g, sx - 3, sy + 1, 6, 2, '#ef4444'); R(g, sx - 2, sy + 3, 4, 1, '#ef4444'); R(g, sx - 1, sy + 4, 2, 1, '#ef4444');
      g.restore();
    }
  }
  function tickPix(P, dt) {
    if (!WP.hooked) {
      WP.hooked = true;
      P.drawHooks = P.drawHooks || [];
      P.drawHooks.push(drawPix);
      P.extraTargets = targetsPix;
    }
    if (WP.scene !== P.scene.id) setupScene(P);
    var sc = P.scene, p = P.player;
    WP.cats.forEach(function (ct) {
      if (ct.petT > 0) { ct.petT -= dt; ct.walkT = 0; return; }
      var d = ct.tx - ct.x;
      if (Math.abs(d) < 1) {
        ct.walkT = 0; ct.wait -= dt;
        if (ct.wait <= 0) {
          var base = Math.random() < 0.3 ? p.x : ct.x;
          ct.tx = Math.max(sc.walls[0] + 20, Math.min(sc.walls[1] - 20, base + rnd(-120, 120)));
          ct.wait = rnd(2, 6);
        }
      } else {
        ct.face = d > 0 ? 1 : -1;
        ct.x += ct.face * Math.min(Math.abs(d), 26 * dt);
        ct.walkT += dt;
      }
    });
    WP.hearts.forEach(function (hh) { hh.t -= dt; });
    // sampah yang dipungut muncul lagi ±3 menit kemudian
    var now = Date.now();
    Object.keys(WP.picked).forEach(function (r) { if (now > WP.picked[r]) delete WP.picked[r]; });
  }
  function targetsPix(sc) {
    if (WP.scene !== sc.id) return [];
    var out = [];
    WP.cats.forEach(function (ct) {
      out.push({ kind: 'custom', x: ct.x, verb: 'ELUS', label: 'Elus ' + ct.c.name + ' 🐱', use: function () {
        var P = getPix();
        ct.petT = 1.2; ct.tx = ct.x;
        if (P) ct.face = P.player.x >= ct.x ? 1 : -1;
        WP.hearts.push({ x: ct.x, t: 1.2 });
        meow();
        toast('🐱 ' + ct.c.name + ': "Meong~" 💕');
        report('cat', 'kpx' + ct.i + sc.id.replace(/[^A-Za-z0-9]/g, '').slice(0, 12));
      } });
    });
    WP.trash.forEach(function (tr) {
      if (WP.picked[tr.ref]) return;
      out.push({ kind: 'custom', x: tr.x, verb: 'PUNGUT', label: 'Pungut sampah ' + tr.t.name + ' 🧹', use: function () {
        WP.picked[tr.ref] = Date.now() + rnd(150, 210) * 1000;
        pop();
        toast('🧹 Sampah ' + tr.t.name + ' dibuang ke tempatnya. Terima kasih!');
        report('trash', tr.ref + 'n' + Math.floor(Date.now() / 60000));
      } });
    });
    return out;
  }

  // ---------- loop logika (tiap frame; beberapa objek saja → murah) ----------
  var last = 0, hooked3d = false;
  function lobbyOpen() {
    if (window.__vcLobbyOpen === true) return true;
    var l = document.getElementById('worldLobby') || document.getElementById('pixLobi');
    return !!(l && l.style.display !== 'none' && !l.hidden && l.getClientRects().length);
  }
  function loop(ts) {
    requestAnimationFrame(loop);
    if (document.hidden) { last = ts; return; }
    var dt = last ? Math.min(0.1, (ts - last) / 1000) : 0.016;
    last = ts;
    if (lobbyOpen()) return;
    try {
      var w = get3d();
      if (w) {
        if (!hooked3d) { hooked3d = true; w.extraTargets = targets3d; }
        tick3d(w, dt);
      } else { var P = getPix(); if (P) tickPix(P, dt); }
    } catch (e) { /* kehidupan dunia tidak boleh mengganggu permainan */ }
  }
  requestAnimationFrame(loop);
  loadParty();
})();
