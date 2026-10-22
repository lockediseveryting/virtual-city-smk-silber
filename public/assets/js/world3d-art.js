/*
 * World3D Art Pass (v7.8) — "cozy · nostalgic · natural" (inspirasi atmosfer Studio Ghibli &
 * A Space for the Unbound, desain orisinal SMK Silber). Memperluas VirtualCampus3D TANPA
 * mengganti engine Three.js / kontrol / collision:
 *
 *  1. Denah direnggangkan ×SPREAD (gedung tidak lagi berdempetan; ukuran gedung tetap). campus.json
 *     tidak diubah — titik di dalam gedung ikut gedungnya, titik luar diskalakan (mapPoint/unmapPoint).
 *  2. Manajemen warna: warna hex sRGB → linear (dulu tampil pucat/neon karena outputEncoding sRGB).
 *  3. Karakter 3D baru: rig berhierarki (rambut/wajah menempel di kepala, detail baju di badan),
 *     badan bervolume, wajah 3D, 17 emote beranimasi (sama dengan Pixel & dashboard).
 *  4. Lanskap: jalan setapak otomatis (A*, tidak pernah menembus gedung), pepohonan beragam yang
 *     bergoyang angin, semak & bunga, lapangan upacara + bendera berkibar, air mancur, lampu taman,
 *     bangku, pagar sesuai batas baru, dan kawasan luar pagar (jalan, rumah warga, sawah, bukit).
 *  5. Preset suasana: Pagi · Siang · Sore · Mendung · Malam (tombol ☀️ berputar).
 *
 * ES2017 (tanpa ?. ?? / class field) — HP lama tetap bisa.
 */
(function () {
  'use strict';
  var W = window.VirtualCampus3D;
  if (!W || !window.THREE) return;
  var P = W.prototype;
  var SPREAD = 1.4;
  W.SPREAD = SPREAD;

  /* ======================================================================
   * Utilitas
   * ==================================================================== */
  function lin(hex) { return new THREE.Color(hex).convertSRGBToLinear(); }
  function rng(seed) {
    var s = (seed % 2147483647 + 2147483647) % 2147483647 || 1;
    return function () { s = s * 16807 % 2147483647; return (s - 1) / 2147483646; };
  }
  function noise2(x, z) { // value-noise halus untuk pengelompokan pohon (bukan acak murni)
    var a = Math.sin(x * 0.021 + 1.7) * Math.cos(z * 0.017 - 0.4);
    var b = Math.sin((x + z) * 0.011 + 3.1) * 0.6;
    var c = Math.sin(x * 0.053 - z * 0.047) * 0.35;
    return (a + b + c) / 1.95 * 0.5 + 0.5;
  }
  /** Gabungkan beberapa geometri (non-indexed) menjadi satu → 1 draw call per jenis objek. */
  function merge(list) {
    var pos = [], nor = [];
    list.forEach(function (it) {
      var g = it.geo.index ? it.geo.toNonIndexed() : it.geo;
      if (it.matrix) g.applyMatrix4(it.matrix);
      var p = g.attributes.position.array, n = g.attributes.normal.array;
      for (var i = 0; i < p.length; i++) { pos.push(p[i]); nor.push(n[i]); }
    });
    var out = new THREE.BufferGeometry();
    out.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    out.setAttribute('normal', new THREE.Float32BufferAttribute(nor, 3));
    out.computeBoundingSphere();
    return out;
  }
  function mtx(x, y, z, sx, sy, sz, ry) {
    var m = new THREE.Matrix4();
    m.compose(new THREE.Vector3(x, y, z), new THREE.Quaternion().setFromEuler(new THREE.Euler(0, ry || 0, 0)), new THREE.Vector3(sx, sy === undefined ? sx : sy, sz === undefined ? sx : sz));
    return m;
  }
  /** Kapsul halus (LatheGeometry; three r128 belum punya CapsuleGeometry), pusat di origin. */
  function capsule(r, len, seg) {
    var pts = [], n = 6, half = len / 2, i, a;
    for (i = 0; i <= n; i++) { a = -Math.PI / 2 + (i / n) * Math.PI / 2; pts.push(new THREE.Vector2(Math.cos(a) * r + 0.0001, -half + Math.sin(a) * r)); }
    for (i = 0; i <= n; i++) { a = (i / n) * Math.PI / 2; pts.push(new THREE.Vector2(Math.cos(a) * r + 0.0001, half + Math.sin(a) * r)); }
    return new THREE.LatheGeometry(pts, seg || 12);
  }
  function lathe(profile, seg) { // profile: [[r, y], …] dari bawah ke atas, pusat vertikal di origin
    var ys = profile.map(function (p) { return p[1]; }), mid = (Math.min.apply(null, ys) + Math.max.apply(null, ys)) / 2;
    return { geo: new THREE.LatheGeometry(profile.map(function (p) { return new THREE.Vector2(Math.max(0.0001, p[0]), p[1] - mid); }), seg || 18), mid: mid };
  }

  /* ======================================================================
   * 1. Denah direnggangkan
   * ==================================================================== */
  var origLoad = P.loadCampusData;
  P.loadCampusData = function () {
    var self = this;
    return Promise.resolve(origLoad.call(this)).then(function () { self.spreadCampus(self.campusData); });
  };

  P.spreadCampus = function (cd) {
    if (!cd || cd.__spread) return;
    cd.__spread = SPREAD;
    this.SPREAD = SPREAD;
    var bs = cd.buildings || [];
    var olds = bs.map(function (b) { return { x: b.bounds.x, z: b.bounds.z, w: b.bounds.width, d: b.bounds.depth }; });
    bs.forEach(function (b) { b.bounds.x *= SPREAD; b.bounds.z *= SPREAD; });
    // Pos satpam (gate-main 26×6) dulu berdiri MELINTANG di jalur gerbang → pemain tertahan.
    // Kini pos jaga 14×12 di samping jalan masuk; gerbang (lengkung di pagar) tetap di posisi lama.
    bs.forEach(function (b) {
      if (b.id === 'gate-main' || b.type === 'gate') { cd.__gateZ = b.bounds.z; b.bounds.width = 14; b.bounds.depth = 12; b.bounds.height = 10; b.bounds.z += 22; }
    });
    var news = bs.map(function (b) { return { x: b.bounds.x, z: b.bounds.z, w: b.bounds.width, d: b.bounds.depth }; });
    // Titik di dalam gedung (ruang kelas, tangga, spawn) ikut gedungnya; titik luar diskalakan.
    this.mapPoint = function (x, z) {
      for (var i = 0; i < olds.length; i++) {
        var q = olds[i];
        if (Math.abs(x - q.x) <= q.w / 2 + 0.01 && Math.abs(z - q.z) <= q.d / 2 + 0.01) return { x: news[i].x + (x - q.x), z: news[i].z + (z - q.z) };
      }
      return { x: x * SPREAD, z: z * SPREAD };
    };
    this.unmapPoint = function (x, z) { // kebalikan (radar/peta 2D memakai denah asli)
      for (var i = 0; i < news.length; i++) {
        var q = news[i];
        if (Math.abs(x - q.x) <= q.w / 2 + 0.01 && Math.abs(z - q.z) <= q.d / 2 + 0.01) return { x: olds[i].x + (x - q.x), z: olds[i].z + (z - q.z) };
      }
      return { x: x / SPREAD, z: z / SPREAD };
    };
    var self = this;
    function mv(o) { var q = self.mapPoint(o.x, o.z); o.x = q.x; o.z = q.z; }
    (cd.rooms || []).forEach(function (r) {
      if (typeof r.x === 'number' && typeof r.z === 'number') mv(r);
      else if (r.bounds) { var q = self.mapPoint(r.bounds.x, r.bounds.z); r.bounds.x = q.x; r.bounds.z = q.z; }
    });
    (cd.stairs || []).forEach(mv);
    if (cd.spawn) mv(cd.spawn);
    var sc = function (o) { if (o) { o.x *= SPREAD; o.z *= SPREAD; } };
    if (cd.sports) sc(cd.sports.futsal);
    if (cd.parking) sc(cd.parking.student);
    if (cd.canteen) sc(cd.canteen.outdoor);
    if (cd.garden) sc(cd.garden.fountain);
  };

  /** Fungsi per frame (angin, air, sungai) tanpa mengubah loop animate engine. */
  P.addTick = function (fn) { (this.__ticks || (this.__ticks = [])).push(fn); };
  var origAnimate = P.animate;
  P.animate = function () {
    var tk = this.__ticks;
    if (tk && window.__vcLobbyOpen !== true) for (var i = 0; i < tk.length; i++) { try { tk[i](); } catch (e) { tk.splice(i--, 1); } }
    return origAnimate.call(this);
  };
  // toonify lama + lewati objek bershader khusus (bendera berkibar, dll.)
  var origToonify = P.toonify;
  P.toonify = function (root) {
    if (!root) return;
    var keep = [];
    root.traverse(function (o) { if (o.userData && o.userData.keepMat && o.material) keep.push([o, o.material]); });
    origToonify.call(this, root);
    keep.forEach(function (k) { k[0].material = k[1]; });
  };

  /* ======================================================================
   * 2. Manajemen warna + preset suasana
   * ==================================================================== */
  P.linearize = function (root) {
    var done = this.__linDone || (this.__linDone = new WeakSet());
    root.traverse(function (o) {
      if (!o.material || o.userData.linear) return;
      (Array.isArray(o.material) ? o.material : [o.material]).forEach(function (m) {
        if (!m || done.has(m)) return;
        done.add(m);
        if (m.color) m.color.convertSRGBToLinear();
        if (m.emissive) m.emissive.convertSRGBToLinear();
      });
    });
  };
  P.artMat = function (hex, opts) { // material yang sudah linear (tidak dikonversi dua kali)
    opts = opts || {};
    var m = (this.toonEnabled === false || this.effectiveQuality === 'low' || opts.lambert)
      ? new THREE.MeshLambertMaterial({ color: lin(hex) })
      : new THREE.MeshToonMaterial({ color: lin(hex), gradientMap: this.toonGradient() });
    if (opts.transparent) { m.transparent = true; m.opacity = opts.opacity; m.depthWrite = false; }
    if (opts.vertexColors) m.vertexColors = true;
    (this.__linDone || (this.__linDone = new WeakSet())).add(m);
    return m;
  };

  // Palet hangat orisinal: langit pastel, kabut tipis, matahari keemasan, bayangan kebiruan.
  if (typeof TIME_PRESETS !== 'undefined') {
    TIME_PRESETS.pagi = { label: '🌅 Pagi', sky: ['#7fa7d8', '#f6c9a8', '#fde7cf'], fog: 0xf3dcc4, hemi: [0xfff0dc, 0x6f8a5e, 0.5], sun: [0xffd9a8, 0.72], sunOffset: [110, 70, 80], lamps: false };
    TIME_PRESETS.siang = { label: '☀️ Siang', sky: ['#5b9bd8', '#a9d2ee', '#e6f2f2'], fog: 0xdcebe8, hemi: [0xe8f3ff, 0x6c8f58, 0.52], sun: [0xfff3dc, 0.82], sunOffset: [80, 130, 60], lamps: false };
    TIME_PRESETS.sore = { label: '🌇 Sore', sky: ['#5a5f9e', '#f0a070', '#fbd8a6'], fog: 0xf2c99c, hemi: [0xffe2c4, 0x6a5848, 0.46], sun: [0xffb070, 0.8], sunOffset: [-120, 48, 50], lamps: false };
    TIME_PRESETS.mendung = { label: '☁️ Mendung', sky: ['#8995a3', '#b8c1c7', '#d5d9d6'], fog: 0xc9cfcd, hemi: [0xdfe5e8, 0x7a8670, 0.72], sun: [0xe8ecf0, 0.22], sunOffset: [40, 140, 30], lamps: false };
    TIME_PRESETS.malam = { label: '🌙 Malam', sky: ['#0b1230', '#1b2550', '#33406b'], fog: 0x1f2a4a, hemi: [0x3a4c80, 0x14182a, 0.45], sun: [0x9fb7ff, 0.32], sunOffset: [-60, 110, -50], lamps: true };
  }
  var TIME_CYCLE = ['pagi', 'siang', 'sore', 'mendung', 'malam'];
  var origTOD = P.setTimeOfDay;
  P.setTimeOfDay = function (part, silent) {
    origTOD.call(this, part, silent);
    var seen = new Set();
    var fixMat = function (m) {
      if (!m || seen.has(m)) return; seen.add(m);
      if (m.color) m.color.convertSRGBToLinear();
      if (m.emissive) m.emissive.convertSRGBToLinear();
    };
    (this.windowMeshes || []).forEach(function (wm) { fixMat(wm.material); });
    (this.streetLamps || []).forEach(function (l) { if (l.lantern && l.lantern.material && l.lantern.material.emissive) { if (!seen.has(l.lantern.material)) { seen.add(l.lantern.material); l.lantern.material.emissive.convertSRGBToLinear(); } } });
    if (this.hemiLight) { this.hemiLight.color.convertSRGBToLinear(); this.hemiLight.groundColor.convertSRGBToLinear(); }
    if (this.sunLight) this.sunLight.color.convertSRGBToLinear();
    if (this.scene && this.scene.fog) {
      this.scene.fog.color.convertSRGBToLinear();
      // kabut atmosferik halus: jauh lebih tipis siang, lebih rapat saat mendung/malam
      var t = this.timeOfDay;
      // HP lemah (kamera jarak pandang 750) tetap butuh kabut lebih rapat agar ujung dunia tidak terpotong.
      if (this.scene.fog.isFogExp2) this.scene.fog.density = Math.max(({ pagi: 0.0013, siang: 0.0010, sore: 0.0013, mendung: 0.0021, malam: 0.0017 })[t] || 0.0012, this.effectiveQuality === 'low' ? 0.003 : 0);
      else if (this.scene.fog.isFog) { this.scene.fog.near = t === 'mendung' ? 160 : 320; this.scene.fog.far = t === 'mendung' ? 1000 : 1700; }
    }
    if (this.renderer) this.renderer.toneMappingExposure = this.timeOfDay === 'malam' ? 1.15 : 1.05;
    if (this.flagUniforms) this.flagUniforms.uWind.value = this.timeOfDay === 'mendung' ? 1.6 : 1.0;
  };
  P.toggleDayNight = function () { // tombol ☀️ berputar: Pagi → Siang → Sore → Mendung → Malam
    this.manualTime = true;
    var i = TIME_CYCLE.indexOf(this.timeOfDay);
    this.setTimeOfDay(TIME_CYCLE[(i + 1) % TIME_CYCLE.length], false);
  };

  /* ======================================================================
   * 3. Karakter 3D (rig berhierarki) + emote
   * ==================================================================== */
  var TORSO_Y = 2.15, HEAD_Y = 2.6; // HEAD_Y relatif terhadap badan → kepala ikut condong badan
  // Pengaman: model karakter yang gagal dibangun TIDAK boleh menggagalkan dunia 3D → karakter sederhana.
  P.buildStylizedAvatar = function (rawCfg, isRemote) {
    try { return this.buildAvatarRig(rawCfg, isRemote); } catch (e) {
      try { fetch('/api/client-error', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': ((document.querySelector('meta[name="csrf"]') || {}).content || '') }, body: JSON.stringify({ message: '3D avatar: ' + (e && e.message), url: location.pathname }) }); } catch (e2) {}
      try { return this.buildAvatarRig({}, isRemote); } catch (e3) {
        var g = new THREE.Group(), m = new THREE.MeshLambertMaterial({ color: 0x1d4ed8 }), body = new THREE.Group(), head = new THREE.Group();
        var b = new THREE.Mesh(new THREE.CylinderGeometry(1.1, 1.0, 4, 10), m); b.position.y = 2; body.add(b);
        var h = new THREE.Mesh(new THREE.SphereGeometry(1.3, 12, 10), new THREE.MeshLambertMaterial({ color: 0xe2ad84 })); h.position.y = 5.3; head.add(h);
        g.add(body); g.add(head); g.scale.setScalar(0.6);
        g.userData = { head: head, torso: body, rig: g, torsoY: 0, headY: 0, avatarScale: 0.6, config: {}, linear: true };
        return g;
      }
    }
  };
  P.buildAvatarRig = function (rawCfg, isRemote) {
    var PA = window.PixelAvatar;
    var cfg = PA ? PA.normalize(rawCfg || {}) : { skin: '#e2ad84', hairStyle: 'short', hair: '#2b1d16', outfit: 'casual', top: '#1d4ed8', bottom: '#1e3a8a', shoes: '#111827', acc: [], accColor: '#2f8f4e' };
    var self = this, cache = this.__avMat || (this.__avMat = {});
    var toon = this.toonEnabled !== false && this.effectiveQuality !== 'low';
    function mat(h, f) {
      var key = h + '|' + (f || 1) + '|' + toon;
      if (cache[key]) return cache[key];
      var c = new THREE.Color(h); if (f) c.multiplyScalar(f);
      c.convertSRGBToLinear();
      var m = toon ? new THREE.MeshToonMaterial({ color: c, gradientMap: self.toonGradient() }) : new THREE.MeshLambertMaterial({ color: c });
      (self.__linDone || (self.__linDone = new WeakSet())).add(m);
      return (cache[key] = m);
    }
    var has = function (a) { return cfg.acc.indexOf(a) >= 0; };
    var of = cfg.outfit, top = cfg.top, bot = cfg.bottom;
    if (of === 'tjkt') { top = '#1d4ed8'; bot = '#1e3a8a'; }
    else if (of === 'putih_abu') { top = '#f5f5f4'; bot = '#6b7280'; }
    else if (of === 'pramuka') { top = '#b08d57'; bot = '#4a3726'; }
    else if (of === 'almamater') { top = '#1e3a8a'; }
    else if (of === 'jas_lab' || of === 'koki') { top = '#f8fafc'; }
    else if (of === 'kerja') { top = '#d6c7a1'; }
    else if (of === 'sailor') { top = '#f8fafc'; }
    else if (of === 'gakuran') { top = '#1d1d28'; bot = '#1d1d28'; }
    var skinM = mat(cfg.skin), skinD = mat(cfg.skin, 0.9), hairM = mat(cfg.hair), pantsM = mat(bot), shoeM = mat(of === 'olahraga' ? '#f5f5f5' : cfg.shoes);
    var topM = mat(top);
    if (of === 'batik') {
      this.__batikTex = this.__batikTex || {};
      if (!this.__batikTex[top]) {
        var cv = document.createElement('canvas'); cv.width = cv.height = 32;
        var g = cv.getContext('2d'); g.fillStyle = top; g.fillRect(0, 0, 32, 32); g.fillStyle = '#d9b44a';
        for (var by = 0; by < 32; by += 8) for (var bx = (by / 8) % 2 ? 4 : 0; bx < 32; bx += 8) { g.fillRect(bx + 2, by + 1, 3, 3); g.fillRect(bx + 3, by, 1, 5); g.fillRect(bx + 1, by + 2, 5, 1); }
        var tx = new THREE.CanvasTexture(cv); tx.wrapS = tx.wrapT = THREE.RepeatWrapping; tx.repeat.set(3, 2); tx.magFilter = THREE.NearestFilter; tx.encoding = THREE.sRGBEncoding;
        this.__batikTex[top] = tx;
      }
      topM = toon ? new THREE.MeshToonMaterial({ map: this.__batikTex[top], gradientMap: this.toonGradient() }) : new THREE.MeshLambertMaterial({ map: this.__batikTex[top] });
      (this.__linDone || (this.__linDone = new WeakSet())).add(topM);
    }
    // ---- ciri khas kostum anime: pola haori sebagai tekstur badan & lengan ----
    var mo = cfg.motif || '';
    var PAT = { checker: 1, triangle: 1, halfsplit: 1, flame: 1, butterfly: 1 };
    if (PAT[mo]) {
      this.__motifTex = this.__motifTex || {};
      var tk = mo + top;
      if (!this.__motifTex[tk]) {
        var mc2 = document.createElement('canvas'); mc2.width = mc2.height = 32;
        var mg = mc2.getContext('2d'), X;
        mg.fillStyle = top; mg.fillRect(0, 0, 32, 32);
        if (mo === 'checker') { mg.fillStyle = '#1d1d28'; for (var yy = 0; yy < 32; yy += 8) for (X = (yy / 8) % 2 ? 8 : 0; X < 32; X += 16) mg.fillRect(X, yy, 8, 8); }
        if (mo === 'triangle') { mg.fillStyle = '#f8fafc'; for (var ty2 = 4; ty2 < 32; ty2 += 10) for (X = 2; X < 32; X += 10) { mg.beginPath(); mg.moveTo(X, ty2 + 6); mg.lineTo(X + 4, ty2); mg.lineTo(X + 8, ty2 + 6); mg.fill(); } }
        if (mo === 'halfsplit') { mg.fillStyle = '#7a1f2a'; mg.fillRect(0, 0, 16, 32); mg.fillStyle = '#d9b44a'; for (var hy2 = 2; hy2 < 32; hy2 += 6) for (X = 18; X < 32; X += 6) mg.fillRect(X, hy2, 2, 2); }
        if (mo === 'flame') { mg.fillStyle = '#f2b33a'; mg.fillRect(0, 20, 32, 12); mg.fillStyle = '#d9433b'; for (X = 0; X < 32; X += 8) { mg.beginPath(); mg.moveTo(X, 32); mg.lineTo(X + 4, 14); mg.lineTo(X + 8, 32); mg.fill(); } }
        if (mo === 'butterfly') { var gr = mg.createLinearGradient(0, 0, 0, 32); gr.addColorStop(0, '#c9a6e8'); gr.addColorStop(1, '#7fc9a0'); mg.fillStyle = gr; mg.fillRect(0, 0, 32, 32); mg.fillStyle = '#f8fafc'; mg.fillRect(6, 8, 4, 4); mg.fillRect(20, 18, 4, 4); }
        var mtx2 = new THREE.CanvasTexture(mc2); mtx2.wrapS = mtx2.wrapT = THREE.RepeatWrapping; mtx2.magFilter = THREE.NearestFilter; mtx2.encoding = THREE.sRGBEncoding;
        this.__motifTex[tk] = mtx2;
      }
      topM = toon ? new THREE.MeshToonMaterial({ map: this.__motifTex[tk], gradientMap: this.toonGradient() }) : new THREE.MeshLambertMaterial({ map: this.__motifTex[tk] });
      (this.__linDone || (this.__linDone = new WeakSet())).add(topM);
    }
    var sleeveM = of === 'tjkt' ? mat('#f5f5f5') : topM;

    var group = new THREE.Group();
    var rig = new THREE.Group(); group.add(rig);             // lompat / putar emote
    var body = new THREE.Group(); body.position.y = TORSO_Y; rig.add(body); // condong di pinggang
    var head = new THREE.Group(); head.position.y = HEAD_Y; body.add(head);
    function add(geo, m, x, y, z, parent) { var o = new THREE.Mesh(geo, m); o.position.set(x, y, z); o.castShadow = true; parent.add(o); return o; }

    // --- Panggul (warna celana) + badan bervolume (lathe oval: pinggul–pinggang–dada–bahu) ---
    var pelvis = lathe([[0.95, 0], [1.08, 0.35], [1.1, 0.7], [0.98, 0.95]], 16);
    add(pelvis.geo, pantsM, 0, 1.55 + pelvis.mid, 0, rig).scale.z = 0.78;
    var coatLong = of === 'jas_lab' || of === 'robe';
    var tor = lathe([[1.02, 0], [1.0, 0.45], [1.12, 1.2], [1.26, 1.75], [1.18, 2.1], [0.62, 2.38]], 18);
    var torso = add(tor.geo, topM, 0, tor.mid, 0, body); torso.scale.z = 0.74;
    if (coatLong) { var tail = add(lathe([[1.22, 0], [1.12, 0.9], [1.05, 1.3]], 16).geo, topM, 0, -0.35, 0, body); tail.scale.z = 0.8; }
    add(new THREE.CylinderGeometry(0.36, 0.42, 0.45, 12), skinM, 0, 2.45, 0, body);            // leher
    var front = function (geo, m, x, y, z) { return add(geo, m, x, y, z === undefined ? 0.9 : z, body); };
    var flat = function (w, h) { return new THREE.BoxGeometry(w, h, 0.06); };
    // Detail pakaian (menempel di badan → ikut bergerak)
    if (of === 'tjkt') { var collar = add(new THREE.TorusGeometry(0.52, 0.13, 8, 18), mat('#2f8f4e'), 0, 2.28, 0.05, body); collar.rotation.x = Math.PI / 2; collar.scale.y = 0.75; front(flat(0.14, 1.9), mat('#93c5fd'), 0, 1.15, 0.9); front(flat(0.34, 0.34), mat('#facc15'), 0.55, 1.75, 0.86); }
    else if (of === 'putih_abu') { if (!has('tie')) front(flat(0.28, 1.5), mat('#6b7280'), 0, 1.35, 0.9); front(flat(0.26, 0.26), mat('#c0392b'), 0.58, 1.8, 0.86); }
    else if (of === 'pramuka') { var scf = add(new THREE.ConeGeometry(0.5, 0.9, 3), mat('#c0392b'), 0, 1.95, 0.8, body); scf.rotation.x = Math.PI; }
    else if (of === 'olahraga') { add(flat(0.12, 2.0), mat('#ffffff'), -1.05, 1.15, 0.38, body).rotation.y = -1.0; add(flat(0.12, 2.0), mat('#ffffff'), 1.05, 1.15, 0.38, body).rotation.y = 1.0; front(flat(0.6, 0.42), mat('#ffffff'), 0, 1.6, 0.9); }
    else if (of === 'hoodie') { front(flat(1.2, 0.6), mat(top, 0.82), 0, 0.5, 0.86); front(flat(0.07, 0.6), mat('#f5f5f5'), -0.26, 1.95, 0.84); front(flat(0.07, 0.6), mat('#f5f5f5'), 0.26, 1.95, 0.84); var hood = add(new THREE.SphereGeometry(0.95, 14, 10, 0, Math.PI * 2, 0, Math.PI * 0.6), topM, 0, 2.3, -0.75, body); hood.rotation.x = -0.7; }
    else if (of === 'almamater') { front(flat(0.6, 1.5), mat('#f5f5f5'), 0, 1.6, 0.9); front(flat(0.16, 1.8), mat('#152a66'), -0.4, 1.4, 0.92); front(flat(0.16, 1.8), mat('#152a66'), 0.4, 1.4, 0.92); front(flat(0.28, 0.28), mat('#d9b44a'), 0.66, 1.65, 0.88); }
    else if (of === 'jas_lab') { front(flat(0.5, 1.7), mat(cfg.top), 0, 1.45, 0.9); }
    else if (of === 'koki') { for (var k = 0; k < 3; k++) { front(new THREE.SphereGeometry(0.09, 6, 4), mat('#9aa1ab'), -0.3, 1.85 - k * 0.5, 0.93); front(new THREE.SphereGeometry(0.09, 6, 4), mat('#9aa1ab'), 0.3, 1.85 - k * 0.5, 0.93); } var kc = add(new THREE.TorusGeometry(0.52, 0.12, 8, 16), mat('#c0392b'), 0, 2.28, 0.05, body); kc.rotation.x = Math.PI / 2; }
    else if (of === 'kerja') { front(flat(1.4, 1.9), mat('#8a6d3b'), 0, 0.75, 0.92); }
    else if (of === 'maid' || of === 'dress' || of === 'sailor') {
      var skM = of === 'sailor' ? pantsM : topM;
      var sk = add(lathe([[1.05, 0], [1.35, 0.6], [1.7, 1.25]], 18).geo, skM, 0, 1.45, 0, rig); sk.rotation.x = Math.PI; sk.scale.z = 0.85; // rok mengembang
      if (of === 'maid') { front(flat(1.2, 1.8), mat('#f8fafc'), 0, 0.9, 0.93); var mc = add(new THREE.TorusGeometry(0.52, 0.12, 8, 16), mat('#f8fafc'), 0, 2.28, 0.05, body); mc.rotation.x = Math.PI / 2; front(new THREE.SphereGeometry(0.13, 8, 6), mat('#c0392b'), 0, 2.0, 0.92); }
      if (of === 'dress') add(new THREE.TorusGeometry(1.03, 0.13, 8, 20), mat(cfg.accColor), 0, 0.15, 0, body).rotation.x = Math.PI / 2;
      if (of === 'sailor') { var sc2 = add(new THREE.CylinderGeometry(0.95, 1.2, 0.55, 16), pantsM, 0, 2.05, -0.05, body); sc2.scale.z = 0.8; front(new THREE.ConeGeometry(0.22, 0.5, 4), mat('#c0392b'), 0, 1.75, 0.95).rotation.x = Math.PI; }
    }
    else if (of === 'gakuran') { for (var gb = 0; gb < 4; gb++) front(new THREE.SphereGeometry(0.08, 6, 4), mat('#d9b44a'), 0, 1.9 - gb * 0.45, 0.92); var gc = add(new THREE.CylinderGeometry(0.55, 0.6, 0.3, 14), topM, 0, 2.35, 0, body); gc.scale.z = 0.85; }
    else if (of === 'armor') {
      [-1, 1].forEach(function (s3) { var pa = add(new THREE.SphereGeometry(0.75, 12, 8, 0, Math.PI * 2, 0, Math.PI / 2), mat(top, 1.2), s3 * 1.4, 2.2, 0, body); pa.scale.set(1, 0.8, 1); });
      front(flat(1.5, 0.12), mat(top, 1.3), 0, 1.6, 0.94); front(flat(1.5, 0.12), mat(top, 1.3), 0, 1.1, 0.94);
      add(new THREE.TorusGeometry(1.03, 0.12, 8, 20), mat('#4a3220'), 0, 0.15, 0, body).rotation.x = Math.PI / 2;
    }
    else if (of === 'kimono') {
      front(flat(0.18, 1.3), mat('#f0e6d0'), -0.2, 1.75, 0.92).rotation.z = 0.5; front(flat(0.18, 1.3), mat('#f0e6d0'), 0.2, 1.75, 0.93).rotation.z = -0.5;
      var obi = add(new THREE.CylinderGeometry(1.06, 1.04, 0.55, 18), mat(cfg.accColor), 0, 0.45, 0, body); obi.scale.z = 0.78;
    }
    else if (of === 'robe') { front(flat(0.14, 2.2), mat(cfg.accColor), -0.45, 1.2, 0.93); front(flat(0.14, 2.2), mat(cfg.accColor), 0.45, 1.2, 0.93); }
    else if (of === 'tunic') {
      var fur = add(new THREE.TorusGeometry(0.62, 0.22, 8, 16), mat('#d8c3a0'), 0, 2.25, 0.02, body); fur.rotation.x = Math.PI / 2;
      var belt = add(new THREE.CylinderGeometry(1.04, 1.03, 0.22, 18), mat('#5b3a1e'), 0, 0.25, 0, body); belt.scale.z = 0.78;
    }
    else { front(flat(0.55, 0.65), mat(cfg.accColor), 0, 1.7, 0.9); }

    // --- Kepala: bola utuh + rambut penuh (atas & belakang menyatu) + wajah 3D ---
    var skull = add(new THREE.SphereGeometry(1.42, 24, 18), skinM, 0, 1.25, 0, head); skull.scale.set(1, 0.98, 0.95);
    [-1, 1].forEach(function (s2) { add(new THREE.SphereGeometry(0.28, 10, 8), skinD, s2 * 1.36, 1.15, 0.05, head).scale.set(0.6, 1, 0.8); }); // telinga
    // mata (bola gelap pipih + kilau), alis, hidung, mulut senyum, pipi merona
    var eyeM = mat('#241826'), whiteM = mat('#ffffff'), browM = mat(cfg.hair, 0.8);
    [-1, 1].forEach(function (s2) {
      var eye = add(new THREE.SphereGeometry(0.2, 12, 10), eyeM, s2 * 0.47, 1.18, 1.27, head); eye.scale.set(0.85, 1.25, 0.45); eye.castShadow = false;
      add(new THREE.SphereGeometry(0.065, 8, 6), whiteM, s2 * 0.47 + 0.06, 1.28, 1.36, head).castShadow = false;
      var brow = add(new THREE.BoxGeometry(0.4, 0.08, 0.08), browM, s2 * 0.47, 1.58, 1.24, head); brow.rotation.z = s2 * -0.12; brow.castShadow = false;
      var blush = add(new THREE.SphereGeometry(0.2, 10, 8), mat('#f08f8f'), s2 * 0.78, 0.86, 1.13, head); blush.scale.set(1.2, 0.6, 0.3); blush.castShadow = false;
    });
    add(new THREE.SphereGeometry(0.1, 8, 6), skinD, 0, 0.98, 1.38, head).castShadow = false;
    var mouth = add(new THREE.TorusGeometry(0.17, 0.045, 6, 12, Math.PI), mat('#8a3a2c'), 0, 0.72, 1.3, head); mouth.rotation.z = Math.PI; mouth.castShadow = false;
    if (has('glasses')) {
      var gM = mat('#1c1c24');
      [-1, 1].forEach(function (s2) { add(new THREE.TorusGeometry(0.3, 0.05, 6, 16), gM, s2 * 0.47, 1.18, 1.42, head); });
      add(new THREE.BoxGeometry(0.3, 0.05, 0.05), gM, 0, 1.22, 1.44, head);
    }
    var hs = cfg.hairStyle, hatOn = has('cap') || has('chef_hat');
    if (hs === 'hijab') {
      var hj = add(new THREE.SphereGeometry(1.56, 24, 16, Math.PI * 0.62, Math.PI * 1.76), hairM, 0, 1.22, -0.04, head); hj.scale.set(1, 1.04, 1);
      add(lathe([[1.0, 0], [1.25, 0.5], [1.55, 1.1]], 18).geo, hairM, 0, -0.35, -0.05, head);
    } else {
      // tempurung rambut (atas) + belakang kepala sampai tengkuk — satu kesatuan, tak ada celah kulit
      add(new THREE.SphereGeometry(1.5, 24, 14, 0, Math.PI * 2, 0, Math.PI * 0.5), hairM, 0, 1.3, -0.02, head).scale.set(1.02, 0.95, 1.0);
      add(new THREE.SphereGeometry(1.49, 22, 14, Math.PI, Math.PI, Math.PI * 0.35, Math.PI * 0.45), hairM, 0, 1.25, -0.03, head);
      // poni menyamping (bukan menutup mata)
      var fr = add(new THREE.SphereGeometry(0.62, 12, 8), hairM, -0.5, 2.05, 1.0, head); fr.scale.set(1.5, 0.5, 0.55); fr.rotation.z = 0.25;
      var fr2 = add(new THREE.SphereGeometry(0.45, 10, 8), hairM, 0.62, 2.12, 0.95, head); fr2.scale.set(1.2, 0.45, 0.5); fr2.rotation.z = -0.3;
      [-1, 1].forEach(function (s2) { var sb = add(new THREE.SphereGeometry(0.42, 10, 8), hairM, s2 * 1.3, 1.45, 0.25, head); sb.scale.set(0.5, 1.15, 0.9); }); // jambang
      if (hs === 'spiky' && !hatOn) for (var i = 0; i < 7; i++) { var sp = add(new THREE.ConeGeometry(0.3, 0.9, 6), hairM, Math.cos(i * 0.9) * 0.75, 2.55, Math.sin(i * 1.7) * 0.65 - 0.1, head); sp.rotation.z = Math.cos(i * 0.9) * -0.45; sp.rotation.x = Math.sin(i * 1.7) * 0.35; }
      if (hs === 'messy' && !hatOn) for (var j = 0; j < 6; j++) add(new THREE.SphereGeometry(0.42, 8, 6), hairM, -1.0 + j * 0.4, 2.45 - (j % 2) * 0.15, 0.5 - (j % 3) * 0.45, head);
      if (hs === 'curly') { var nC = hatOn ? 9 : 14; for (var c2 = 0; c2 < nC; c2++) { var ang = c2 / nC * Math.PI * 2; if (hatOn && Math.sin(ang) > 0.3) continue; add(new THREE.SphereGeometry(0.5, 8, 6), hairM, Math.cos(ang) * 1.32, hatOn ? 1.25 : 2.05 + (c2 % 2) * 0.25, Math.sin(ang) * 1.25 - 0.1, head); } }
      if (hs === 'long') { var back = add(lathe([[1.2, 0], [1.42, 1.3], [1.5, 2.1]], 18).geo, hairM, 0, 0.3, -0.12, head); back.scale.z = 0.82; }
      if (hs === 'ponytail') { var tl = add(capsule(0.38, 1.6, 10), hairM, 0, 1.0, -1.75, head); tl.rotation.x = 0.35; add(new THREE.SphereGeometry(0.22, 8, 6), mat(cfg.accColor), 0, 1.85, -1.55, head); }
      if (hs === 'bun' && !hatOn) add(new THREE.SphereGeometry(0.62, 14, 10), hairM, 0, 2.65, -0.65, head);
      if (hs === 'topknot' && !hatOn) { add(new THREE.SphereGeometry(0.45, 12, 8), hairM, 0, 2.75, -0.35, head); add(capsule(0.22, 0.5, 8), hairM, 0, 2.95, -0.75, head).rotation.x = 1.2; }
      if (hs === 'bob') { [-1, 1].forEach(function (s3) { var bs = add(new THREE.SphereGeometry(0.75, 14, 10), hairM, s3 * 1.05, 0.95, -0.05, head); bs.scale.set(0.7, 1.35, 1.15); }); var fb = add(new THREE.SphereGeometry(1.0, 14, 8), hairM, 0, 2.0, 0.85, head); fb.scale.set(1.35, 0.45, 0.6); }
      if (hs === 'twintail') { [-1, 1].forEach(function (s3) { add(new THREE.SphereGeometry(0.32, 10, 8), mat(cfg.accColor), s3 * 1.45, 1.9, -0.2, head); var tt = add(capsule(0.42, 1.9, 10), hairM, s3 * 1.75, 0.6, -0.35, head); tt.rotation.z = s3 * 0.25; }); }
      if (hs === 'braids') { [-1, 1].forEach(function (s3) { for (var bk = 0; bk < 4; bk++) add(new THREE.SphereGeometry(0.3 - bk * 0.03, 10, 8), hairM, s3 * 1.05, 0.35 - bk * 0.48, 0.55, head); add(new THREE.SphereGeometry(0.16, 8, 6), mat(cfg.accColor), s3 * 1.05, -1.6, 0.55, head); }); }
    }
    var accM = mat(cfg.accColor);
    if (has('cap')) { var cp = add(new THREE.SphereGeometry(1.56, 22, 10, 0, Math.PI * 2, 0, Math.PI * 0.5), accM, 0, 1.45, -0.02, head); cp.scale.y = 0.78; var brim = add(new THREE.CylinderGeometry(1.0, 1.0, 0.12, 18, 1, false, -Math.PI * 0.5, Math.PI), accM, 0, 1.55, 0.98, head); brim.scale.z = 0.9; }
    if (has('chef_hat')) { add(new THREE.CylinderGeometry(1.2, 1.25, 0.9, 18), mat('#ffffff'), 0, 2.55, 0, head); add(new THREE.SphereGeometry(1.4, 16, 10), mat('#ffffff'), 0, 3.35, 0, head).scale.y = 0.65; }
    if (has('witch_hat')) { var wb = add(new THREE.CylinderGeometry(2.4, 2.4, 0.14, 24), accM, 0, 2.15, 0, head); wb.castShadow = true; var wc = add(new THREE.ConeGeometry(1.3, 2.8, 18), accM, 0, 3.55, -0.15, head); wc.rotation.x = -0.18; add(new THREE.CylinderGeometry(1.32, 1.32, 0.3, 18), mat('#d9b44a'), 0, 2.35, 0, head); }
    if (has('ribbon')) { [-1, 1].forEach(function (s3) { add(new THREE.SphereGeometry(0.32, 10, 8), accM, 0.75 + s3 * 0.38, 2.45, 0.35, head).scale.set(1.2, 0.8, 0.5); }); }
    if (has('sword')) { var sw = add(new THREE.BoxGeometry(0.42, 4.2, 0.12), mat('#b9c1ca'), 0.45, 1.3, -1.15, body); sw.rotation.z = -0.45; var hilt = add(new THREE.BoxGeometry(1.1, 0.18, 0.18), mat('#5b3a1e'), 0.95 + 0.0, 2.55, -1.15, body); hilt.rotation.z = -0.45; }
    if (has('headphones')) { var band = add(new THREE.TorusGeometry(1.55, 0.13, 8, 20, Math.PI), mat('#1c1c24'), 0, 1.25, 0, head); band.rotation.y = Math.PI / 2; [-1, 1].forEach(function (s2) { var cup = add(new THREE.CylinderGeometry(0.5, 0.5, 0.35, 14), accM, s2 * 1.5, 1.15, 0, head); cup.rotation.z = Math.PI / 2; }); }

    // --- motif pada kepala / leher ---
    if (mo === 'maidcap') { var mcp = add(new THREE.TorusGeometry(1.25, 0.18, 6, 18, Math.PI), mat('#f8fafc'), 0, 2.15, 0.3, head); mcp.rotation.x = -0.3; add(new THREE.BoxGeometry(0.45, 0.1, 0.1), mat('#c9d6ff'), 0.85, 1.8, 1.25, head).rotation.z = 0.7; add(new THREE.BoxGeometry(0.45, 0.1, 0.1), mat('#c9d6ff'), 0.85, 1.8, 1.25, head).rotation.z = -0.7; }
    if (mo === 'horns') [-1, 1].forEach(function (s3) { var hn = add(new THREE.ConeGeometry(0.25, 0.6, 8), mat('#1c1c24'), s3 * 0.85, 2.5, 0.2, head); hn.rotation.z = -s3 * 0.4; });
    if (mo === 'rose') { add(new THREE.TorusGeometry(1.45, 0.08, 6, 24), mat('#d9b44a'), 0, 1.75, 0, head).rotation.x = Math.PI / 2.3; add(new THREE.SphereGeometry(0.3, 10, 8), mat('#c0392b'), 0.95, 2.2, 0.6, head); }
    if (mo === 'muzzle') { var mz = add(new THREE.CylinderGeometry(0.16, 0.16, 1.4, 10), mat('#5b8a3a'), 0, 0.72, 1.32, head); mz.rotation.z = Math.PI / 2; }
    if (mo === 'boar') { var bh = add(new THREE.SphereGeometry(1.6, 18, 14), mat('#7a6a5a'), 0, 1.3, 0.08, head); bh.scale.set(1.02, 1.0, 1.05); add(new THREE.CylinderGeometry(0.45, 0.55, 0.6, 12), mat('#9a8a7a'), 0, 0.9, 1.5, head).rotation.x = Math.PI / 2; [-1, 1].forEach(function (s3) { add(new THREE.ConeGeometry(0.3, 0.6, 8), mat('#5a4a3a'), s3 * 1.0, 2.6, 0, head); add(new THREE.SphereGeometry(0.15, 8, 6), mat('#1b1020'), s3 * 0.5, 1.45, 1.52, head); }); }
    if (mo === 'scarf') { var sf = add(new THREE.TorusGeometry(0.62, 0.26, 8, 18), mat('#c0392b'), 0, 2.35, 0.05, body); sf.rotation.x = Math.PI / 2; add(new THREE.BoxGeometry(0.4, 1.0, 0.18), mat('#a02a22'), 0.45, 1.8, 0.85, body); }
    if (mo === 'cape' || mo === 'wings') {
      var cpM = mat(mo === 'wings' ? '#2f6b4a' : cfg.accColor);
      var cp2 = add(new THREE.BoxGeometry(2.5, 3.2, 0.12), cpM, 0, 0.9, -1.0, body); cp2.rotation.x = 0.12;
      if (mo === 'wings') { add(new THREE.BoxGeometry(0.9, 0.7, 0.05), mat('#f8fafc'), 0, 1.6, -1.1, body); add(new THREE.BoxGeometry(0.3, 0.6, 0.06), mat('#3b6ac9'), 0.15, 1.6, -1.12, body); }
    }
    // --- Lengan (pivot bahu, menempel di badan) ---
    var longSleeve = ['jas_lab', 'almamater', 'hoodie', 'batik', 'koki', 'pramuka'].indexOf(of) >= 0;
    function makeArm(side) {
      var pivot = new THREE.Group(); pivot.position.set(side * 1.42, 2.05, 0); body.add(pivot);
      add(new THREE.SphereGeometry(0.5, 12, 10), sleeveM, 0, 0, 0, pivot);              // bahu bulat
      add(capsule(0.38, 0.9, 12), sleeveM, 0, -0.7, 0, pivot);                             // lengan atas
      if (of === 'tjkt') add(new THREE.CylinderGeometry(0.41, 0.41, 0.16, 12), mat('#2f8f4e'), 0, -1.12, 0, pivot);
      var metal = mo === 'prosthetic' && side < 0; // lengan besi (Guts)
      add(capsule(0.32, 0.85, 12), metal ? mat('#3a3f46') : (longSleeve ? topM : skinM), 0, -1.7, 0, pivot);          // lengan bawah
      add(new THREE.SphereGeometry(0.36, 12, 10), metal ? mat('#5b616b') : skinM, 0, -2.42, 0.02, pivot);           // tangan
      if (side < 0 && has('watch')) { var wt = add(new THREE.TorusGeometry(0.33, 0.08, 6, 12), mat('#1c1c24'), 0, -2.05, 0, pivot); wt.rotation.x = Math.PI / 2; }
      return pivot;
    }
    var leftArm = makeArm(-1), rightArm = makeArm(1);
    // --- Kaki (pivot pinggul, di rig — tidak ikut condong) ---
    function makeLeg(side) {
      var pivot = new THREE.Group(); pivot.position.set(side * 0.52, 1.75, 0); rig.add(pivot);
      add(capsule(0.45, 1.05, 12), pantsM, 0, -0.75, 0, pivot);
      var shoe = add(new THREE.SphereGeometry(0.52, 14, 10), shoeM, 0, -1.55, 0.2, pivot); shoe.scale.set(0.95, 0.55, 1.4);
      if (of !== 'olahraga') add(new THREE.BoxGeometry(0.9, 0.08, 1.2), mat('#f1f1f1'), 0, -1.78, 0.18, pivot); // sol
      return pivot;
    }
    var leftLeg = makeLeg(-1), rightLeg = makeLeg(1);

    // --- Aksesoris badan ---
    if (has('backpack')) { var bp = add(capsule(0.85, 1.3, 14), accM, 0, 1.2, -1.15, body); bp.scale.z = 0.6; add(new THREE.BoxGeometry(1.1, 0.5, 0.3), mat(cfg.accColor, 0.75), 0, 0.75, -1.62, body); [-0.62, 0.62].forEach(function (x) { front(flat(0.18, 1.9), mat(cfg.accColor, 0.7), x, 1.35, 0.92); }); }
    if (has('tie')) front(new THREE.ConeGeometry(0.2, 1.5, 4), mat(of === 'putih_abu' ? '#6b7280' : '#c0392b'), 0, 1.45, 0.94).rotation.x = Math.PI;
    if (has('lanyard')) { var cord = add(new THREE.TorusGeometry(0.62, 0.045, 6, 16, Math.PI), mat('#1d4ed8'), 0, 2.0, 0.78, body); cord.rotation.z = Math.PI; front(flat(0.5, 0.65), mat('#ffffff'), 0, 1.2, 0.96); }

    group.scale.setScalar(typeof AVATAR_EFFECTIVE_SCALE !== 'undefined' ? AVATAR_EFFECTIVE_SCALE : 0.6);
    group.userData = { head: head, torso: body, rig: rig, leftArm: leftArm, rightArm: rightArm, leftLeg: leftLeg, rightLeg: rightLeg,
      torsoY: TORSO_Y, headY: HEAD_Y, hipY: 1.75, legR: 0.45, avatarScale: group.scale.x, config: cfg, linear: true };
    return group;
  };
  // Garis tepi kartun juga untuk badan/lengan kapsul (LatheGeometry).
  P.addAvatarOutline = function (group, thickness) {
    thickness = thickness || 1.06;
    if (!group || !this.outlinesEnabled()) return;
    this.__hullMat = this.__hullMat || new THREE.MeshBasicMaterial({ color: 0x0b1020, side: THREE.BackSide });
    var meshes = [];
    group.traverse(function (o) { if (o.isMesh && !o.userData.isOutline && o.castShadow && o.geometry && /^(Box|Sphere|Cylinder|Cone|Lathe)Geometry$/.test(o.geometry.type)) meshes.push(o); });
    var hull = this.__hullMat;
    meshes.forEach(function (o) {
      if (!o.geometry.boundingSphere) o.geometry.computeBoundingSphere();
      if (o.geometry.boundingSphere && o.geometry.boundingSphere.radius < 0.45) return;
      var h = new THREE.Mesh(o.geometry, hull); h.scale.setScalar(thickness); h.userData.isOutline = true; o.add(h);
    });
  };

  // ---- Emote 3D: pose per emote (nama sama dengan PixelAvatar.EMOTES) ----
  var EMO_DUR = 2.6;
  function L(a, b, t) { return a + (b - a) * t; }
  /** Terapkan pose emote ke rig ud pada waktu t (detik). env = transisi masuk/keluar halus. */
  function emotePose(ud, name, t) {
    var env = Math.min(1, t / 0.22) * Math.min(1, Math.max(0, (EMO_DUR - t) / 0.35));
    var la = ud.leftArm, ra = ud.rightArm, ll = ud.leftLeg, rl = ud.rightLeg, bd = ud.torso, hd = ud.head, rig = ud.rig;
    var s = Math.sin, tgt = { lax: 0, laz: -0.06, rax: 0, raz: 0.06, llx: 0, rlx: 0, bx: 0, bz: 0, hx: 0, hz: 0, hy: 0, y: 0, ry: 0 };
    switch (name) {
      case 'wave': tgt.raz = -2.55 + s(t * 11) * 0.42; tgt.hz = -0.1; break;
      case 'point': tgt.rax = -1.5; tgt.raz = 0.08; tgt.hy = -0.15; break;
      case 'clap': { var c = Math.abs(s(t * 13)); tgt.lax = -1.25; tgt.rax = -1.25; tgt.laz = 0.25 + c * 0.45; tgt.raz = -(0.25 + c * 0.45); break; }
      case 'cheer': tgt.laz = 2.7; tgt.raz = -2.7; tgt.y = Math.abs(s(t * 7)) * 1.6; tgt.llx = -0.4 * Math.abs(s(t * 7)); tgt.rlx = tgt.llx; break;
      case 'hype': tgt.laz = 2.3 + s(t * 12) * 0.35; tgt.raz = -(2.3 + s(t * 12 + 1) * 0.35); tgt.y = Math.abs(s(t * 6)) * 0.7; tgt.hx = -0.15; break;
      case 'love': tgt.lax = -1.05; tgt.rax = -1.05; tgt.laz = 0.95; tgt.raz = -0.95; tgt.hz = 0.16 * s(t * 3); tgt.bz = 0.06 * s(t * 3); break;
      case 'laugh': tgt.bx = -0.18; tgt.lax = -0.55; tgt.rax = -0.55; tgt.laz = 0.55; tgt.raz = -0.55; tgt.y = Math.abs(s(t * 16)) * 0.25; tgt.hx = -0.2 + s(t * 16) * 0.06; break;
      case 'think': tgt.rax = -2.15; tgt.raz = -0.55; tgt.lax = -0.95; tgt.laz = 0.75; tgt.hz = 0.2; tgt.hx = 0.08; break;
      case 'ok': tgt.rax = -1.15; tgt.raz = -0.35; tgt.hx = 0.12 * Math.abs(s(t * 5)); break;
      case 'cool': tgt.lax = -1.1; tgt.rax = -1.1; tgt.laz = 0.9; tgt.raz = -0.9; tgt.hx = -0.14; tgt.hz = 0.08; break;
      case 'dance': tgt.ry = s(t * 4) * 0.7; tgt.laz = 1.4 + s(t * 8) * 0.9; tgt.raz = -(1.4 + s(t * 8 + Math.PI) * 0.9); tgt.llx = s(t * 8) * 0.45; tgt.rlx = -s(t * 8) * 0.45; tgt.y = Math.abs(s(t * 8)) * 0.45; tgt.bz = s(t * 8) * 0.12; break;
      case 'salute': tgt.rax = -0.55; tgt.raz = -2.25; tgt.hx = -0.05; break;
      case 'bow': tgt.bx = 0.75; tgt.hx = 0.25; tgt.lax = 0.25; tgt.rax = 0.25; break;
      case 'sad': tgt.hx = 0.5; tgt.bx = 0.22; tgt.laz = 0.02; tgt.raz = -0.02; tgt.y = -0.08; break;
      case 'angry': tgt.lax = -0.3; tgt.rax = -0.3; tgt.laz = -0.75; tgt.raz = 0.75; tgt.llx = Math.max(0, s(t * 9)) * -0.6; tgt.rlx = Math.max(0, -s(t * 9)) * -0.6; tgt.bz = s(t * 40) * 0.05; tgt.hx = 0.12; break;
      case 'sleep': tgt.hz = 0.42; tgt.hx = 0.3 + s(t * 2) * 0.05; tgt.bx = 0.08; tgt.y = s(t * 2) * 0.05; break;
      case 'shock': tgt.laz = 1.8; tgt.raz = -1.8; tgt.lax = -0.6; tgt.rax = -0.6; tgt.hx = -0.28; tgt.y = t < 0.35 ? s(t / 0.35 * Math.PI) * 1.2 : 0; break;
      default: tgt.raz = -2.4 + s(t * 10) * 0.4;
    }
    if (la) { la.rotation.x = L(la.rotation.x, tgt.lax, env); la.rotation.z = L(la.rotation.z, tgt.laz, env); }
    if (ra) { ra.rotation.x = L(ra.rotation.x, tgt.rax, env); ra.rotation.z = L(ra.rotation.z, tgt.raz, env); }
    if (ll && (tgt.llx || env < 1)) ll.rotation.x = L(ll.rotation.x, tgt.llx, env);
    if (rl && (tgt.rlx || env < 1)) rl.rotation.x = L(rl.rotation.x, tgt.rlx, env);
    if (bd) { bd.rotation.x = L(bd.rotation.x, tgt.bx, env); bd.rotation.z = L(bd.rotation.z, tgt.bz, env); }
    if (hd) { hd.rotation.x = L(hd.rotation.x, tgt.hx, env); hd.rotation.z = L(hd.rotation.z, tgt.hz, env); hd.rotation.y = L(hd.rotation.y, tgt.hy, env); }
    if (rig) { rig.position.y = tgt.y * env; rig.rotation.y = tgt.ry * env; }
  }
  function resetRig(ud) { if (ud && ud.rig) { ud.rig.position.y = 0; ud.rig.rotation.y = 0; } }

  P.emojiSprite = function (emoji) {
    this.__emoTex = this.__emoTex || {};
    var tex = this.__emoTex[emoji];
    if (!tex) {
      var c = document.createElement('canvas'); c.width = c.height = 128;
      var g = c.getContext('2d');
      g.fillStyle = 'rgba(255,248,230,0.95)'; g.beginPath(); g.arc(64, 60, 52, 0, Math.PI * 2); g.fill();
      g.beginPath(); g.moveTo(52, 106); g.lineTo(64, 124); g.lineTo(76, 106); g.fill();
      g.font = '64px "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
      g.fillText(emoji, 64, 64);
      tex = this.__emoTex[emoji] = new THREE.CanvasTexture(c);
      tex.encoding = THREE.sRGBEncoding;
    }
    var sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, transparent: true, depthTest: false }));
    sp.renderOrder = 999;
    sp.scale.set(3.2, 3.2, 1);
    return sp;
  };
  function showEmoji(world, holder, name) {
    var PA = window.PixelAvatar, emoji = (PA && PA.EMOTES[name]) || '✨';
    if (holder.__emoSprite) { holder.remove(holder.__emoSprite); holder.__emoSprite = null; }
    var sp = world.emojiSprite(emoji);
    sp.position.set(0, 10.6, 0);
    holder.add(sp);
    holder.__emoSprite = sp;
    return sp;
  }
  function tickEmoji(holder, t) {
    var sp = holder.__emoSprite;
    if (!sp) return;
    var k = t < 0.25 ? t / 0.25 : 1, fade = Math.min(1, Math.max(0, (EMO_DUR - t) / 0.4));
    sp.scale.set(3.2 * (0.6 + 0.4 * k), 3.2 * (0.6 + 0.4 * k), 1);
    sp.position.y = 10.6 + (1 - k) * -1 + Math.sin(t * 3) * 0.15;
    sp.material.opacity = fade;
    if (t >= EMO_DUR) { holder.remove(sp); holder.__emoSprite = null; }
  }

  /** Mainkan emote pemain sendiri (HUD 😀 / panel emote). Mengirim ke pemain lain lewat WS oleh pemanggil. */
  P.playEmote = function (name) {
    if (!this.player) return false;
    if (this.isSitting && this.standUp) this.standUp();
    this.emoteName = String(name || 'wave');
    this.emoteT = 0;
    this.emoteTime = 0; // matikan lambai lama
    showEmoji(this, this.player, this.emoteName);
    this.playTone(660, 'sine', 0.08);
    var self = this; setTimeout(function () { self.playTone(880, 'sine', 0.1); }, 120);
    return true;
  };
  P.emote = function () { return this.playEmote('wave'); };
  P.remoteEmote = function (id, name) {
    var rec = this.remotePlayers && this.remotePlayers.get(id);
    if (!rec || !rec.mesh) return;
    rec.emoteName = String(name || 'wave').slice(0, 12);
    rec.emoteT = 0;
    rec.emoteTime = 0;
    showEmoji(this, rec.mesh, rec.emoteName);
  };
  var origUpdatePlayer = P.updatePlayer;
  P.updatePlayer = function (delta) {
    origUpdatePlayer.call(this, delta);
    if (!this.player) return;
    var ud = this.player.userData;
    if (this.emoteName) {
      this.emoteT += delta;
      if (this.emoteT >= EMO_DUR || (this.isMoving && this.emoteT > 0.3)) { this.emoteName = ''; resetRig(ud); if (this.player.__emoSprite) { this.player.remove(this.player.__emoSprite); this.player.__emoSprite = null; } }
      else { emotePose(ud, this.emoteName, this.emoteT); tickEmoji(this.player, this.emoteT); }
    }
  };
  var origUpdateRemote = P.updateRemotePlayers;
  P.updateRemotePlayers = function (delta) {
    origUpdateRemote.call(this, delta);
    if (!this.remotePlayers) return;
    this.remotePlayers.forEach(function (rec) {
      if (!rec.emoteName || !rec.mesh) return;
      rec.emoteT += delta;
      if (rec.emoteT >= EMO_DUR) { rec.emoteName = ''; resetRig(rec.mesh.userData); if (rec.mesh.__emoSprite) { rec.mesh.remove(rec.mesh.__emoSprite); rec.mesh.__emoSprite = null; } }
      else { emotePose(rec.mesh.userData, rec.emoteName, rec.emoteT); tickEmoji(rec.mesh, rec.emoteT); }
    });
  };

  /** Panel emote 3D (18 emote, favorit di depan). Tombol 😀 di HUD & tombol G. */
  W.emotePanel = function (world, send) {
    var PA = window.PixelAvatar;
    if (!PA) return null;
    var el = document.getElementById('emote3dPanel');
    if (!el) {
      el = document.createElement('div');
      el.id = 'emote3dPanel';
      el.hidden = true;
      el.setAttribute('role', 'dialog');
      el.setAttribute('aria-label', 'Emote');
      el.style.cssText = 'position:fixed;z-index:1200;left:50%;bottom:max(96px,env(safe-area-inset-bottom));transform:translateX(-50%);display:grid;grid-template-columns:repeat(6,48px);gap:6px;padding:10px;background:rgba(32,20,12,.94);border:2px solid #d9a441;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.45)';
      var fav = (window.__AVATAR && window.__AVATAR.config && window.__AVATAR.config.emotes) || [];
      var keys = Object.keys(PA.EMOTES).filter(function (k) { return k !== 'sit'; });
      keys.sort(function (a, b) { return (fav.indexOf(a) < 0 ? 99 : fav.indexOf(a)) - (fav.indexOf(b) < 0 ? 99 : fav.indexOf(b)); });
      el.innerHTML = keys.map(function (k) {
        return '<button type="button" data-emo="' + k + '" title="' + (PA.EMOTE_LABEL[k] || k) + '" aria-label="' + (PA.EMOTE_LABEL[k] || k) + '" style="width:48px;height:48px;font-size:24px;border-radius:10px;border:1px solid rgba(217,164,65,.5);background:rgba(0,0,0,.3);cursor:pointer;line-height:1">' + PA.EMOTES[k] + '</button>';
      }).join('');
      document.body.appendChild(el);
      el.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-emo]') : null;
        if (!b) return;
        var name = b.getAttribute('data-emo');
        if (world && world.playEmote) world.playEmote(name);
        if (typeof send === 'function') send({ type: 'emote', emote: name });
        if (window.vcDaily) window.vcDaily.report('emote', name); // misi harian
        el.hidden = true;
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') el.hidden = true;
      });
    }
    return { toggle: function () { el.hidden = !el.hidden; }, el: el };
  };
})();
