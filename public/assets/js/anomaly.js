/*
 * ANOMALI MALAM — 4 jenis sosok yang hanya muncul MALAM hari di Dunia 3D & Pixel:
 *   👤 Bayangan (hitam tinggi bermata merah, diam menatap) · 🧟 Pocong (melompat-lompat) ·
 *   👻 Kuntilanak (gaun putih, melayang, tertawa lirih) · 👁️ Mata Merah (hanya mata di kegelapan, berkedip).
 *  Setiap kemunculan memilih jenis acak (tidak sama dua kali berturut-turut).
 *  - Berdiri diam di kejauhan / sudut, selalu menghadap pemain.
 *  - Didekati (≤ jarak tertentu) → berkedip lalu MENGHILANG, beberapa detik kemudian muncul lagi
 *    di tempat lain — sering tepat DI BELAKANG pemain (berlawanan arah kamera / arah hadap).
 *  - Lokal per pemain (tidak disinkron), murah: beberapa mesh/kotak piksel, tanpa tekstur.
 *  - SELALU aktif di setiap server (keputusan pemilik 16 Okt 2026: pemain tidak bisa mematikan). Hormati prefers-reduced-motion
 *    (tetap muncul, tanpa kedip cepat).
 *  - Ditemukan (didekati sampai menghilang) → vcWorldLife.report('anomaly', jenis) untuk misi mabar
 *    "Pemburu Anomali" (world-life.js).
 * ES2017; `world` / `pix` = variabel global halaman (let).
 */
(function () {
  'use strict';
  function enabled() { return true; } // tidak bisa dimatikan pemain
  function night() {
    var w = get3d();
    if (w) return !!w.isNight;
    var px = getPix();
    if (px && px.daypart) return px.daypart === 'malam';
    var p = window.vcDaypart ? window.vcDaypart() : (document.documentElement.getAttribute('data-daypart') || '');
    return p === 'malam';
  }
  function get3d() { return (typeof world !== 'undefined' && world && world.scene && world.playerPos) ? world : null; } // eslint-disable-line no-undef
  function getPix() { return (typeof pix !== 'undefined' && pix && pix.player && pix.scene) ? pix : null; } // eslint-disable-line no-undef
  function rnd(a, b) { return a + Math.random() * (b - a); }
  function lobbyOpen() {
    if (window.__vcLobbyOpen === true) return true;
    var ids = ['worldLobby', 'pixLobi'];
    for (var i = 0; i < ids.length; i++) {
      var l = document.getElementById(ids[i]);
      if (l && l.style.display !== 'none' && !l.hidden && l.getClientRects().length) return true;
    }
    return false;
  }
  var reduced = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  var TYPES = ['bayangan', 'pocong', 'kunti', 'mata'];
  var lastType = '';
  function pickType() {
    var c = TYPES.filter(function (t) { return t !== lastType; });
    lastType = c[Math.floor(Math.random() * c.length)];
    return lastType;
  }
  function found(type) { if (window.vcWorldLife) window.vcWorldLife.report('anomaly', type); }

  // ---------- suara samar (WebAudio, tanpa berkas) ----------
  var actx = null;
  /** Suara khas tiap jenis saat muncul: pocong = debam lompatan, kunti = tawa lirih, mata = bisikan. */
  function cue(type) {
    try {
      if (!actx) { var C = window.AudioContext || window.webkitAudioContext; if (!C) return; actx = new C(); }
      if (actx.state === 'suspended') actx.resume();
      var t = actx.currentTime, g = actx.createGain(), i;
      g.connect(actx.destination);
      if (type === 'kunti') {
        // "hi-hi-hi-hiii" — nada tinggi bergetar menurun
        for (i = 0; i < 5; i++) {
          var o = actx.createOscillator(), og = actx.createGain();
          o.type = 'triangle';
          o.frequency.setValueAtTime(1250 - i * 70, t + i * 0.16);
          o.frequency.exponentialRampToValueAtTime(980 - i * 60, t + i * 0.16 + 0.12);
          og.gain.setValueAtTime(0.0001, t + i * 0.16); og.gain.exponentialRampToValueAtTime(0.035, t + i * 0.16 + 0.02); og.gain.exponentialRampToValueAtTime(0.0001, t + i * 0.16 + 0.14);
          o.connect(og); og.connect(actx.destination); o.start(t + i * 0.16); o.stop(t + i * 0.16 + 0.16);
        }
      } else if (type === 'pocong') {
        for (i = 0; i < 3; i++) {
          var b = actx.createOscillator(), bg = actx.createGain();
          b.type = 'sine'; b.frequency.setValueAtTime(110, t + i * 0.55); b.frequency.exponentialRampToValueAtTime(38, t + i * 0.55 + 0.18);
          bg.gain.setValueAtTime(0.12, t + i * 0.55); bg.gain.exponentialRampToValueAtTime(0.0001, t + i * 0.55 + 0.22);
          b.connect(bg); bg.connect(actx.destination); b.start(t + i * 0.55); b.stop(t + i * 0.55 + 0.25);
        }
      } else if (type === 'mata') {
        var dur = 1.4, buf = actx.createBuffer(1, Math.floor(actx.sampleRate * dur), actx.sampleRate), d = buf.getChannelData(0);
        for (i = 0; i < d.length; i++) d[i] = (Math.random() * 2 - 1) * Math.sin(Math.PI * i / d.length) * (0.6 + 0.4 * Math.sin(i / 900));
        var n = actx.createBufferSource(), f = actx.createBiquadFilter();
        n.buffer = buf; f.type = 'bandpass'; f.frequency.value = 2600; f.Q.value = 1.2;
        g.gain.value = 0.05; n.connect(f); f.connect(g); n.start(t); n.stop(t + dur);
      } else { hum('appear'); }
    } catch (e) { /* audio opsional */ }
  }
  function hum(kind) {
    try {
      if (!actx) { var C = window.AudioContext || window.webkitAudioContext; if (!C) return; actx = new C(); }
      if (actx.state === 'suspended') actx.resume();
      var t = actx.currentTime, o = actx.createOscillator(), g = actx.createGain();
      o.type = 'sine';
      o.frequency.setValueAtTime(kind === 'vanish' ? 180 : 55, t);
      o.frequency.exponentialRampToValueAtTime(kind === 'vanish' ? 40 : 48, t + 0.9);
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(kind === 'vanish' ? 0.05 : 0.03, t + 0.08);
      g.gain.exponentialRampToValueAtTime(0.0001, t + 1);
      o.connect(g); g.connect(actx.destination);
      o.start(t); o.stop(t + 1.05);
    } catch (e) { /* audio opsional */ }
  }

  /** Jeritan sintetis (noise + nada tinggi bergetar) + kilatan wajah — saat pemain TERLALU dekat. */
  var FACES = {
    bayangan: '<div style="position:relative;width:min(46vw,46vh);height:min(60vw,60vh);background:#050407;border-radius:46% 46% 40% 40%">'
      + '<i style="position:absolute;left:24%;top:38%;width:14%;height:7%;background:#ff1a1a;box-shadow:0 0 24px 8px rgba(255,0,0,.7)"></i>'
      + '<i style="position:absolute;right:24%;top:38%;width:14%;height:7%;background:#ff1a1a;box-shadow:0 0 24px 8px rgba(255,0,0,.7)"></i>'
      + '<i style="position:absolute;left:36%;top:64%;width:28%;height:16%;background:#1a0000;border-radius:40%"></i></div>',
    pocong: '<div style="position:relative;width:min(44vw,44vh);height:min(62vw,62vh);background:#e7e2d6;border-radius:48% 48% 30% 30%;box-shadow:inset 0 0 40px #8a8270">'
      + '<i style="position:absolute;left:44%;top:-6%;width:12%;height:12%;background:#d6cfbf;border-radius:30%"></i>'
      + '<i style="position:absolute;left:18%;top:30%;width:64%;height:44%;background:#3a3428;border-radius:44%"></i>'
      + '<i style="position:absolute;left:30%;top:42%;width:12%;height:8%;background:#fff;border-radius:50%;box-shadow:0 0 14px #fff"></i>'
      + '<i style="position:absolute;right:30%;top:42%;width:12%;height:8%;background:#fff;border-radius:50%;box-shadow:0 0 14px #fff"></i></div>',
    kunti: '<div style="position:relative;width:min(50vw,50vh);height:min(66vw,66vh);background:#0b0b0d;border-radius:44% 44% 10% 10%">'
      + '<i style="position:absolute;left:26%;top:16%;width:48%;height:56%;background:#d9dde3;border-radius:42% 42% 46% 46%"></i>'
      + '<i style="position:absolute;left:34%;top:34%;width:10%;height:9%;background:#000;border-radius:50%"></i>'
      + '<i style="position:absolute;right:34%;top:34%;width:10%;height:9%;background:#000;border-radius:50%"></i>'
      + '<i style="position:absolute;left:40%;top:54%;width:20%;height:12%;background:#3b0000;border-radius:45%"></i>'
      + '<i style="position:absolute;left:20%;top:10%;width:14%;height:84%;background:#0b0b0d"></i><i style="position:absolute;right:20%;top:10%;width:14%;height:84%;background:#0b0b0d"></i></div>',
    mata: '<div style="position:relative;width:min(80vw,80vh);height:min(40vw,40vh)">'
      + '<i style="position:absolute;left:6%;top:20%;width:36%;height:60%;background:radial-gradient(circle,#000 0 18%,#ffdd00 19% 30%,#ff2200 31% 60%,transparent 61%);border-radius:50%;box-shadow:0 0 60px 10px rgba(255,40,0,.6)"></i>'
      + '<i style="position:absolute;right:6%;top:20%;width:36%;height:60%;background:radial-gradient(circle,#000 0 18%,#ffdd00 19% 30%,#ff2200 31% 60%,transparent 61%);border-radius:50%;box-shadow:0 0 60px 10px rgba(255,40,0,.6)"></i></div>'
  };
  var scareType = 'bayangan';
  function scream() {
    try {
      if (!actx) { var C = window.AudioContext || window.webkitAudioContext; if (C) actx = new C(); }
      if (actx) {
        if (actx.state === 'suspended') actx.resume();
        var t = actx.currentTime, dur = 1.1;
        var buf = actx.createBuffer(1, Math.floor(actx.sampleRate * dur), actx.sampleRate), d = buf.getChannelData(0);
        for (var i = 0; i < d.length; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / d.length);
        var noise = actx.createBufferSource(); noise.buffer = buf;
        var bp = actx.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.setValueAtTime(1800, t); bp.frequency.exponentialRampToValueAtTime(700, t + dur); bp.Q.value = 3;
        var o1 = actx.createOscillator(), o2 = actx.createOscillator(), lfo = actx.createOscillator(), lg = actx.createGain();
        o1.type = 'sawtooth'; o2.type = 'square';
        o1.frequency.setValueAtTime(950, t); o1.frequency.exponentialRampToValueAtTime(420, t + dur);
        o2.frequency.setValueAtTime(1430, t); o2.frequency.exponentialRampToValueAtTime(610, t + dur);
        lfo.frequency.value = 23; lg.gain.value = 60; lfo.connect(lg); lg.connect(o1.frequency); lg.connect(o2.frequency);
        var g = actx.createGain();
        g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.32, t + 0.03); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
        var tone = actx.createGain(); tone.gain.value = 0.35;
        noise.connect(bp); bp.connect(g); o1.connect(tone); o2.connect(tone); tone.connect(g); g.connect(actx.destination);
        [noise, o1, o2, lfo].forEach(function (n) { n.start(t); n.stop(t + dur + 0.05); });
      }
    } catch (e) { /* audio opsional */ }
    try { if (navigator.vibrate) navigator.vibrate([120, 40, 220]); } catch (e) {}
    if (reduced) return;
    // kilatan wajah + layar bergetar ±0,4 dtk
    var f = document.createElement('div');
    f.setAttribute('aria-hidden', 'true');
    f.style.cssText = 'position:fixed;inset:0;z-index:9999;pointer-events:none;display:flex;align-items:center;justify-content:center;'
      + 'background:radial-gradient(circle at 50% 45%,rgba(0,0,0,.2) 0,rgba(0,0,0,.92) 55%,#000 100%);animation:vcScare .42s steps(6) both';
    f.innerHTML = FACES[scareType] || FACES.bayangan;
    if (!document.getElementById('vcScareCss')) {
      var st = document.createElement('style'); st.id = 'vcScareCss';
      st.textContent = '@keyframes vcScare{0%{opacity:0;transform:scale(1.25)}15%{opacity:1;transform:scale(1) translate(-6px,4px)}35%{transform:translate(7px,-5px)}55%{transform:translate(-5px,-3px)}80%{opacity:1}100%{opacity:0;transform:scale(1.05)}}'
        + '@keyframes vcShake{0%,100%{transform:none}20%{transform:translate(-8px,5px)}40%{transform:translate(7px,-6px)}60%{transform:translate(-6px,-4px)}80%{transform:translate(5px,6px)}}';
      document.head.appendChild(st);
    }
    document.body.appendChild(f);
    var cv = document.querySelector('canvas');
    if (cv) { cv.style.animation = 'vcShake .4s linear'; setTimeout(function () { cv.style.animation = ''; }, 420); }
    setTimeout(function () { if (f.parentNode) f.parentNode.removeChild(f); }, 450);
  }
  var lastScream = 0;
  function maybeScream(close, type) {
    // hanya bila benar-benar terlalu dekat, maks. sekali per 25 detik (agar tetap mengejutkan, bukan mengganggu)
    var now = Date.now();
    if (close && now - lastScream > 25000) { lastScream = now; scareType = type || 'bayangan'; scream(); return true; }
    return false;
  }

  // =====================================================================
  //  DUNIA 3D
  // =====================================================================
  var A3 = { mesh: null, meshes: {}, type: 'bayangan', state: 'off', t: 0, next: rnd(6, 14), x: 0, z: 0, flick: 0, seen: 0 };
  function build3d(w, type) {
    var T = window.THREE;
    if (!T) return null;
    if (type === 'pocong') return buildPocong(w, T);
    if (type === 'kunti') return buildKunti(w, T);
    if (type === 'mata') return buildMata(w, T);
    var g = new T.Group();
    var black = new T.MeshBasicMaterial({ color: 0x050407, transparent: true, opacity: 0.94 });
    var body = new T.Mesh(new T.CylinderGeometry(0.45, 0.95, 6.2, 8), black);
    body.position.y = 3.1;
    var head = new T.Mesh(new T.SphereGeometry(0.85, 10, 8), black);
    head.position.y = 7.0; head.scale.set(0.9, 1.15, 0.9);
    var armGeo = new T.CylinderGeometry(0.16, 0.12, 4.6, 6);
    var la = new T.Mesh(armGeo, black); la.position.set(-0.9, 4.1, 0); la.rotation.z = 0.08;
    var ra = new T.Mesh(armGeo, black); ra.position.set(0.9, 4.1, 0); ra.rotation.z = -0.08;
    var eyeMat = new T.MeshBasicMaterial({ color: 0xff2a2a });
    var le = new T.Mesh(new T.SphereGeometry(0.14, 6, 6), eyeMat); le.position.set(-0.3, 7.15, 0.74);
    var re = new T.Mesh(new T.SphereGeometry(0.14, 6, 6), eyeMat); re.position.set(0.3, 7.15, 0.74);
    var glow = new T.Mesh(new T.PlaneGeometry(1.6, 0.6), new T.MeshBasicMaterial({ color: 0xff0000, transparent: true, opacity: 0.18, depthWrite: false }));
    glow.position.set(0, 7.15, 0.8);
    [body, head, la, ra, le, re, glow].forEach(function (m) { g.add(m); });
    g.visible = false;
    g.userData.mat = black;
    w.scene.add(g);
    return g;
  }
  function finish(w, g, mat) { g.visible = false; g.userData.mat = mat; w.scene.add(g); return g; }
  function buildPocong(w, T) {
    var g = new T.Group();
    var cloth = new T.MeshBasicMaterial({ color: 0xe9e4d8, transparent: true, opacity: 0.96 });
    var body = new T.Mesh(new T.CylinderGeometry(0.75, 0.6, 5.0, 10), cloth); body.position.y = 2.5;
    var head = new T.Mesh(new T.SphereGeometry(0.9, 10, 8), cloth); head.position.y = 5.5; head.scale.set(0.95, 1.1, 0.95);
    var knot = new T.Mesh(new T.ConeGeometry(0.28, 0.7, 6), cloth); knot.position.y = 6.75;
    var tie1 = new T.Mesh(new T.TorusGeometry(0.62, 0.07, 4, 12), new T.MeshBasicMaterial({ color: 0xb8b1a0 })); tie1.position.y = 4.65; tie1.rotation.x = Math.PI / 2;
    var tie2 = tie1.clone(); tie2.position.y = 0.6;
    var face = new T.Mesh(new T.CircleGeometry(0.55, 12), new T.MeshBasicMaterial({ color: 0x2e2a22 })); face.position.set(0, 5.5, 0.84);
    var eyeM = new T.MeshBasicMaterial({ color: 0xffffff });
    var e1 = new T.Mesh(new T.SphereGeometry(0.1, 6, 6), eyeM); e1.position.set(-0.2, 5.6, 0.92);
    var e2 = new T.Mesh(new T.SphereGeometry(0.1, 6, 6), eyeM); e2.position.set(0.2, 5.6, 0.92);
    [body, head, knot, tie1, tie2, face, e1, e2].forEach(function (m) { g.add(m); });
    return finish(w, g, cloth);
  }
  function buildKunti(w, T) {
    var g = new T.Group();
    var dress = new T.MeshBasicMaterial({ color: 0xf2f2f0, transparent: true, opacity: 0.88 });
    var hairM = new T.MeshBasicMaterial({ color: 0x08080a });
    var gown = new T.Mesh(new T.ConeGeometry(1.5, 5.6, 10, 1, true), dress); gown.position.y = 3.0;
    var chest = new T.Mesh(new T.CylinderGeometry(0.45, 0.6, 1.4, 8), dress); chest.position.y = 5.9;
    var head = new T.Mesh(new T.SphereGeometry(0.62, 10, 8), new T.MeshBasicMaterial({ color: 0xd9dde3 })); head.position.y = 7.05;
    var hair = new T.Mesh(new T.CylinderGeometry(0.75, 0.95, 4.2, 10, 1, true), hairM); hair.position.y = 5.6;
    var veil = new T.Mesh(new T.PlaneGeometry(0.9, 2.2), hairM); veil.position.set(0, 6.3, 0.62); // rambut menutupi wajah
    var armG = new T.CylinderGeometry(0.12, 0.1, 2.6, 6);
    var la = new T.Mesh(armG, dress); la.position.set(-0.75, 5.3, 0.3); la.rotation.x = -0.9;
    var ra = new T.Mesh(armG, dress); ra.position.set(0.75, 5.3, 0.3); ra.rotation.x = -0.9;
    var eyeM = new T.MeshBasicMaterial({ color: 0xff2a2a });
    var e1 = new T.Mesh(new T.SphereGeometry(0.07, 6, 6), eyeM); e1.position.set(-0.17, 7.1, 0.66);
    [gown, chest, hair, head, veil, la, ra, e1].forEach(function (m) { g.add(m); });
    return finish(w, g, dress);
  }
  function buildMata(w, T) {
    var g = new T.Group(), mat = new T.MeshBasicMaterial({ color: 0xff3b1a, transparent: true, opacity: 1 });
    var glowM = new T.MeshBasicMaterial({ color: 0xff2200, transparent: true, opacity: 0.22, depthWrite: false });
    // 3 pasang mata di ketinggian berbeda — tanpa tubuh
    [[0, 4.2, 0], [-2.2, 2.6, -1], [2.0, 5.6, -0.6]].forEach(function (q, i) {
      var pair = new T.Group();
      var a = new T.Mesh(new T.SphereGeometry(0.17, 8, 6), mat), b = a.clone();
      a.position.x = -0.32; b.position.x = 0.32;
      var gl = new T.Mesh(new T.PlaneGeometry(1.4, 0.55), glowM);
      pair.add(a); pair.add(b); pair.add(gl);
      pair.position.set(q[0], q[1], q[2]);
      pair.userData.phase = i * 1.7;
      g.add(pair);
    });
    return finish(w, g, mat);
  }
  /** Gerak khas tiap jenis selama "watch". */
  function animate3d(m, type, dt) {
    var now = performance.now();
    if (type === 'pocong') {
      var hop = Math.abs(Math.sin(now / 280));
      m.position.y += hop * 1.1;
      m.scale.y = 1 - (1 - hop) * 0.06;
    } else if (type === 'kunti') {
      m.position.y += 1.6 + Math.sin(now / 600) * 0.45; // melayang
      m.rotation.z = Math.sin(now / 900) * 0.05;
    } else if (type === 'mata') {
      m.children.forEach(function (pair) {
        var bl = (Math.sin(now / 520 + pair.userData.phase) > 0.93);
        pair.scale.y = bl ? 0.1 : 1; // berkedip
      });
    } else {
      m.scale.y = 1 + Math.sin(now / 700) * 0.015;
    }
  }
  function spot3d(w, behind) {
    var p = w.playerPos;
    var camYaw = (typeof w.camYaw === 'number') ? w.camYaw : w.headingAngle || 0;
    for (var i = 0; i < 24; i++) {
      // "di belakang" = berlawanan arah pandang kamera
      var base = behind ? camYaw : rnd(0, Math.PI * 2);
      var a = base + rnd(-0.7, 0.7), d = behind ? rnd(16, 26) : rnd(28, 46);
      var x = p.x + Math.sin(a) * d, z = p.z + Math.cos(a) * d;
      var L = w.layout; if (L ? (x < L.X0 || x > L.X1 || z < L.Z0 || z > L.Z1) : (Math.abs(x) > 185 || Math.abs(z) > 105)) continue;
      if (w.checkCollision && w.checkCollision(x, z)) continue;
      return { x: x, z: z };
    }
    return null;
  }
  function useType(w, type) {
    if (A3.mesh) A3.mesh.visible = false;
    if (!A3.meshes[type]) A3.meshes[type] = build3d(w, type);
    A3.type = type;
    A3.mesh = A3.meshes[type];
    return A3.mesh;
  }
  function tick3d(w, dt) {
    if (!A3.mesh) useType(w, 'bayangan');
    var m = A3.mesh;
    if (!m) return;
    var active = enabled() && night() && (w.currentFloor || 1) === 1;
    if (!active) { m.visible = false; A3.state = 'off'; return; }
    if (A3.state === 'off') { A3.state = 'wait'; A3.next = rnd(5, 12); }
    var p = w.playerPos;
    if (A3.state === 'wait') {
      A3.next -= dt;
      if (A3.next <= 0) {
        var s = spot3d(w, A3.behind);
        if (s) {
          m = useType(w, pickType());
          if (!m) return;
          A3.x = s.x; A3.z = s.z; A3.state = 'watch'; A3.seen = 0; m.visible = true; m.scale.set(1, 1, 1); m.rotation.z = 0;
          m.userData.mat.opacity = A3.type === 'kunti' ? 0.88 : A3.type === 'mata' ? 1 : 0.94; A3.behind = false;
          if (A3.type !== 'bayangan') setTimeout(function () { if (A3.state === 'watch') cue(A3.type); }, 400);
        }
        else A3.next = 2;
      }
      return;
    }
    var gy = w.groundHeightAt ? w.groundHeightAt(A3.x, A3.z, 0) : 0;
    m.position.set(A3.x, gy, A3.z);
    m.rotation.y = Math.atan2(p.x - A3.x, p.z - A3.z); // selalu menatap pemain
    var dist = Math.hypot(p.x - A3.x, p.z - A3.z);
    if (A3.state === 'watch') {
      A3.seen += dt;
      // gerak khas jenis & sesekali berkedip
      if (!reduced) animate3d(m, A3.type, dt);
      if (!reduced && Math.random() < 0.006) { m.visible = false; setTimeout(function () { if (A3.state === 'watch') m.visible = true; }, 70); }
      var near = A3.type === 'mata' ? 11 : 14;
      if (dist < near || A3.seen > 35) {
        A3.state = 'vanish'; A3.t = 0;
        if (dist < near) found(A3.type);
        if (!maybeScream(dist < 9, A3.type)) hum('vanish');
      }
    } else if (A3.state === 'vanish') {
      A3.t += dt;
      m.visible = reduced ? A3.t < 0.3 : (Math.floor(A3.t * 20) % 2 === 0);
      m.userData.mat.opacity = Math.max(0, 0.94 - A3.t * 1.6);
      m.scale.set(1 - A3.t * 0.6, 1 + A3.t * 0.4, 1 - A3.t * 0.6);
      if (A3.t > 0.55) {
        m.visible = false;
        A3.state = 'wait';
        A3.behind = Math.random() < 0.6;      // sering muncul lagi di belakang
        A3.next = A3.behind ? rnd(3, 7) : rnd(8, 20);
        if (A3.behind) setTimeout(function () { hum('appear'); }, A3.next * 1000);
      }
    }
  }

  // =====================================================================
  //  DUNIA PIXEL (side-view)
  // =====================================================================
  var AP = { state: 'off', type: 'bayangan', x: 0, scene: '', next: 6, t: 0, behind: false, seen: 0 };
  function R(g, x, y, w, h, c) { g.fillStyle = c; g.fillRect(Math.round(x), Math.round(y), w, h); }
  function drawPix(g, camX, G) {
    var P = getPix();
    if (!P || AP.state === 'off' || AP.state === 'wait' || AP.scene !== P.scene.id) return;
    if (AP.state === 'vanish' && !reduced && Math.floor(AP.t * 20) % 2 === 1) return;
    var sx = AP.x - camX, h = AP.state === 'vanish' ? Math.round(44 * (1 + AP.t * 0.5)) : 44, w = AP.state === 'vanish' ? Math.max(2, Math.round(10 * (1 - AP.t))) : 10;
    var a = AP.state === 'vanish' ? Math.max(0, 1 - AP.t * 1.6) : 1;
    g.save(); g.globalAlpha = a;
    if (AP.type !== 'bayangan') { drawPixType(g, AP.type, sx, G, h / 44, w / 10); g.restore(); return; }
    R(g, sx - w / 2 - 2, G - 2, w + 4, 2, 'rgba(0,0,0,0.5)');
    R(g, sx - w / 2, G - h + 10, w, h - 10, '#050407');           // badan
    R(g, sx - w / 2 - 2, G - h + 14, 2, 20, '#050407');             // lengan panjang
    R(g, sx + w / 2, G - h + 14, 2, 20, '#050407');
    R(g, sx - 4, G - h, 8, 11, '#050407');                          // kepala
    R(g, sx - 3, G - h + 4, 2, 1, '#ff2a2a'); R(g, sx + 1, G - h + 4, 2, 1, '#ff2a2a'); // mata
    R(g, sx - 5, G - h + 3, 10, 3, 'rgba(255,0,0,0.12)');
    g.restore();
  }
  function drawPixType(g, type, sx, G, sy, sw) {
    var now = performance.now();
    if (type === 'pocong') {
      var hop = reduced ? 0 : Math.round(Math.abs(Math.sin(now / 280)) * 7), y = G - hop, W = Math.max(2, Math.round(10 * sw)), H = Math.round(40 * sy);
      R(g, sx - 6, G - 2, 12, 2, 'rgba(0,0,0,0.45)');
      R(g, sx - W / 2, y - H + 10, W, H - 10, '#e9e4d8');                 // kain kafan
      R(g, sx - W / 2, y - H + 18, W, 1, '#b8b1a0'); R(g, sx - W / 2, y - 4, W, 1, '#b8b1a0'); // tali
      R(g, sx - 5, y - H, 10, 11, '#e9e4d8');                             // kepala
      R(g, sx - 1, y - H - 3, 3, 3, '#d6cfbf');                           // simpul atas
      R(g, sx - 3, y - H + 3, 6, 6, '#2e2a22');                           // wajah gelap
      R(g, sx - 2, y - H + 5, 1, 1, '#fff'); R(g, sx + 1, y - H + 5, 1, 1, '#fff');
    } else if (type === 'kunti') {
      var fl = reduced ? 8 : 8 + Math.round(Math.sin(now / 600) * 3), b = G - fl;
      R(g, sx - 5, G - 2, 10, 2, 'rgba(0,0,0,0.3)');
      R(g, sx - 7, b - 18, 14, 18, '#f2f2f0'); R(g, sx - 5, b - 30, 10, 12, '#f2f2f0'); // gaun
      R(g, sx - 8, b - 3, 16, 3, 'rgba(242,242,240,.6)');                // ujung gaun berkibar
      R(g, sx - 6, b - 44, 12, 24, '#08080a');                            // rambut panjang
      R(g, sx - 3, b - 41, 6, 7, '#d9dde3');                              // wajah pucat
      R(g, sx - 3, b - 39, 6, 5, '#08080a');                              // rambut menutupi wajah
      R(g, sx - 2, b - 37, 1, 1, '#ff2a2a');                              // satu mata mengintip
      R(g, sx - 9, b - 30, 3, 10, '#f2f2f0'); R(g, sx + 6, b - 30, 3, 10, '#f2f2f0'); // lengan terjulur
    } else if (type === 'mata') {
      [[0, 30], [-14, 18], [12, 40]].forEach(function (q, i) {
        var blink = !reduced && Math.sin(now / 520 + i * 1.7) > 0.93;
        var ex = sx + q[0], ey = G - q[1];
        R(g, ex - 6, ey - 2, 12, 5, 'rgba(255,40,0,0.14)');
        if (blink) { R(g, ex - 4, ey, 3, 1, '#ff3b1a'); R(g, ex + 1, ey, 3, 1, '#ff3b1a'); }
        else { R(g, ex - 4, ey - 1, 3, 2, '#ff3b1a'); R(g, ex + 1, ey - 1, 3, 2, '#ff3b1a'); R(g, ex - 3, ey - 1, 1, 1, '#ffe14a'); R(g, ex + 2, ey - 1, 1, 1, '#ffe14a'); }
      });
    }
  }
  function tickPix(P, dt) {
    if (!P.onDrawWorld) P.onDrawWorld = drawPix;
    var sc = P.scene, p = P.player;
    var active = enabled() && night() && !P.paused && (sc.kind === 'outdoor' || sc.kind === 'corridor');
    if (!active) { AP.state = 'off'; return; }
    if (AP.state === 'off') { AP.state = 'wait'; AP.next = rnd(5, 12); }
    if (AP.scene !== sc.id && AP.state !== 'wait') { AP.state = 'wait'; AP.next = rnd(4, 10); }
    if (AP.state === 'wait') {
      AP.next -= dt;
      if (AP.next <= 0) {
        var dir = AP.behind ? -(p.facing || 1) : (Math.random() < 0.5 ? 1 : -1);
        var x = p.x + dir * (AP.behind ? rnd(90, 140) : rnd(170, 280));
        x = Math.max(sc.walls[0] + 10, Math.min(sc.walls[1] - 10, x));
        if (Math.abs(x - p.x) < 80) { AP.next = 2; return; }
        AP.x = x; AP.scene = sc.id; AP.state = 'watch'; AP.seen = 0; AP.behind = false; AP.type = pickType();
        if (AP.type !== 'bayangan') setTimeout(function () { if (AP.state === 'watch') cue(AP.type); }, 400);
      }
      return;
    }
    if (AP.state === 'watch') {
      AP.seen += dt;
      var dd = Math.abs(p.x - AP.x);
      if (dd < 64 || AP.seen > 35) {
        AP.state = 'vanish'; AP.t = 0;
        if (dd < 64) found(AP.type);
        if (!maybeScream(dd < 40, AP.type)) hum('vanish');
      }
    } else if (AP.state === 'vanish') {
      AP.t += dt;
      if (AP.t > 0.55) { AP.state = 'wait'; AP.behind = Math.random() < 0.6; AP.next = AP.behind ? rnd(3, 7) : rnd(8, 20); }
    }
  }

  // =====================================================================
  //  MODE SERVER (Anomaly Director di websocket_server): klien hanya menggambar.
  //  Tanpa koneksi (mode offline Pixel) → logika lokal di atas tetap dipakai.
  // =====================================================================
  var SV = { on: false, me: 0, ent: null, nearAt: 0, env: null };
  function sendWs(o) {
    try {
      if (get3d() && typeof window.sendWsMessage === 'function') window.sendWsMessage(o);
      else if (window.PixelNet && PixelNet.sendNow) PixelNet.sendNow(o);
    } catch (e) {}
  }
  function envEffect(kind, dur) {
    var w = get3d(), until = performance.now() + (dur || 6) * 1000;
    SV.env = { kind: kind, until: until };
    if (kind === 'steps') { for (var i = 0; i < 6; i++) setTimeout(function () { beepStep(); }, 380 * i); }
    else if (kind === 'whisper') hum('appear');
    else if (kind === 'fog' && w && w.scene && w.scene.fog && w.scene.fog.isFogExp2) {
      var base = w.scene.fog.density; w.scene.fog.density = base * 2.6;
      setTimeout(function () { if (w.scene.fog) w.scene.fog.density = base; }, (dur || 6) * 1000);
    }
  }
  function beepStep() {
    try {
      var C = window.AudioContext || window.webkitAudioContext; if (!C) return;
      if (!actx) actx = new C();
      var t = actx.currentTime, o = actx.createOscillator(), g = actx.createGain();
      o.type = 'triangle'; o.frequency.value = 70 + Math.random() * 20;
      g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.18, t + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.12);
      o.connect(g); g.connect(actx.destination); o.start(t); o.stop(t + 0.14);
    } catch (e) {}
  }
  function server(msg) {
    if (!msg) return;
    if (msg.op === 'hello') { SV.on = true; SV.me = Number(msg.me) || SV.me; A3.state = 'off'; AP.state = 'off'; return; }
    SV.on = true;
    var e = SV.ent;
    if (msg.op === 'spawn' || msg.op === 'sync') {
      SV.ent = { id: msg.id, kind: TYPES.indexOf(msg.kind) >= 0 ? msg.kind : 'bayangan', action: msg.action || 'observe', x: +msg.x || 0, z: +msg.z || 0, targetId: msg.targetId, area: msg.area || '', fresh: true };
      if (SV.ent.kind !== 'bayangan') setTimeout(function () { if (SV.ent && SV.ent.id === msg.id) cue(SV.ent.kind); }, 400);
    } else if ((msg.op === 'act' || msg.op === 'move') && e && e.id === msg.id) {
      e.action = msg.action || e.action; e.x = +msg.x; e.z = +msg.z; e.fresh = msg.op === 'act';
      if (msg.op === 'act' && msg.action === 'pursue') { cue(e.kind); hum('appear'); }
    } else if (msg.op === 'despawn' && e && e.id === msg.id) {
      SV.ent = null; e.gone = true; SV.last = e;
      if (A3.state === 'watch') { A3.state = 'vanish'; A3.t = 0; }
      if (AP.state === 'watch') { AP.state = 'vanish'; AP.t = 0; }
      if (msg.why === 'approached') { found(e.kind); hum('vanish'); }
    } else if (msg.op === 'catch') {
      if (Number(msg.targetId) === SV.me) { scareType = msg.kind || 'pocong'; lastScream = 0; maybeScream(true, scareType); }
    } else if (msg.op === 'env') {
      envEffect(msg.env, msg.dur);
    }
  }
  function serverTick3d(w, dt) {
    if (!A3.mesh) useType(w, 'bayangan');
    var e = SV.ent, p = w.playerPos;
    if (!e) { // lenyap: animasi vanish lalu sembunyi
      if (A3.state === 'vanish') {
        A3.t += dt; var m0 = A3.mesh;
        if (m0) { m0.visible = reduced ? A3.t < 0.3 : (Math.floor(A3.t * 20) % 2 === 0); m0.userData.mat.opacity = Math.max(0, 0.94 - A3.t * 1.6); }
        if (A3.t > 0.55) { if (m0) m0.visible = false; A3.state = 'off'; }
      } else if (A3.mesh) A3.mesh.visible = false;
      return;
    }
    var m = A3.type === e.kind && A3.mesh ? A3.mesh : useType(w, e.kind);
    if (!m) return;
    if (e.fresh) { // titik dari server bisa di dalam dinding → geser ke titik bebas terdekat
      e.fresh = false;
      if (w.checkCollision && w.checkCollision(e.x, e.z)) {
        for (var k = 1; k < 16; k++) { var a = k * 2.4, rr = 2 + k * 1.5, nx = e.x + Math.sin(a) * rr, nz = e.z + Math.cos(a) * rr; if (!w.checkCollision(nx, nz)) { e.x = nx; e.z = nz; break; } }
      }
      if (A3.state !== 'watch') { A3.x = e.x; A3.z = e.z; m.scale.set(1, 1, 1); m.rotation.z = 0; m.userData.mat.opacity = e.kind === 'kunti' ? 0.88 : e.kind === 'mata' ? 1 : 0.94; }
      A3.state = 'watch';
    }
    var sp = e.action === 'pursue' ? 6 : 2.2;                  // interpolasi halus ke posisi server
    A3.x += (e.x - A3.x) * Math.min(1, dt * sp); A3.z += (e.z - A3.z) * Math.min(1, dt * sp);
    m.visible = true;
    var gy = w.groundHeightAt ? w.groundHeightAt(A3.x, A3.z, 0) : 0;
    m.position.set(A3.x, gy, A3.z);
    m.rotation.y = Math.atan2(p.x - A3.x, p.z - A3.z);
    if (!reduced) animate3d(m, e.kind, e.action === 'pursue' ? dt * 2.2 : dt);
    if (!reduced && e.action !== 'pursue' && Math.random() < 0.006) { m.visible = false; setTimeout(function () { if (SV.ent) m.visible = true; }, 70); }
    if (SV.env && SV.env.kind === 'flicker' && performance.now() < SV.env.until) { // lampu taman & jendela berkedip
      var on = Math.random() < 0.5;
      (w.streetLamps || []).forEach(function (l) { if (l.lantern && l.lantern.material && l.lantern.material.emissive) l.lantern.material.emissive.setScalar(on ? 0.6 : 0); });
    }
    var dist = Math.hypot(p.x - A3.x, p.z - A3.z), near = e.kind === 'mata' ? 11 : 14;
    if (dist < near && e.action !== 'pursue' && performance.now() - SV.nearAt > 900) { SV.nearAt = performance.now(); sendWs({ type: 'anomaly_near', id: e.id }); }
  }
  function serverTickPix(P, dt) {
    if (!P.onDrawWorld) P.onDrawWorld = drawPix;
    var e = SV.ent, area = P.currentAreaId ? P.currentAreaId() : '';
    if (!e || (e.area && e.area !== area)) {
      if (AP.state === 'vanish') { AP.t += dt; if (AP.t > 0.55) AP.state = 'off'; } else AP.state = 'off';
      return;
    }
    var scale = P.netScale || 10, tx = e.x * scale;
    if (AP.state !== 'watch' || e.fresh) { e.fresh = false; if (AP.state !== 'watch') AP.x = tx; AP.type = e.kind; AP.scene = P.scene.id; AP.state = 'watch'; AP.seen = 0; }
    AP.x += (tx - AP.x) * Math.min(1, dt * (e.action === 'pursue' ? 6 : 2.2));
    var dd = Math.abs(P.player.x - AP.x);
    if (dd < 64 && e.action !== 'pursue' && performance.now() - SV.nearAt > 900) { SV.nearAt = performance.now(); sendWs({ type: 'anomaly_near', id: e.id }); }
  }

  window.__vcAnomaly = { a3: A3, ap: AP, scream: function (t) { scareType = t || 'bayangan'; scream(); }, types: TYPES, server: server, sv: SV }; // diagnosis/tes + pesan server

  // ---------- loop logika ringan (±20×/detik, berhenti saat tab tersembunyi) ----------
  var last = 0;
  function loop(ts) {
    requestAnimationFrame(loop);
    if (document.hidden) { last = ts; return; }
    var dt = last ? Math.min(0.1, (ts - last) / 1000) : 0.05;
    if (dt < 0.045) return;
    last = ts;
    if (lobbyOpen()) return;
    try {
      var w = get3d();
      if (w) { if (SV.on) serverTick3d(w, dt); else tick3d(w, dt); }
      else { var P = getPix(); if (P) { if (SV.on && !window.__pixOffline) serverTickPix(P, dt); else tickPix(P, dt); } }
    } catch (e) { /* anomali tidak boleh mengganggu permainan */ }
  }
  requestAnimationFrame(loop);
})();
