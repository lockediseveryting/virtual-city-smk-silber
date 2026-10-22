/*
 * World3D Land (v7.8) — lanskap SMK Silber yang hidup & tertata (dipakai world3d-art.js).
 * Menggantikan buildWorld lama: plaza paving raksasa, "kerb" belah-ketupat (RingGeometry 4 segmen
 * yang memotong semua gedung), jalan lurus yang menembus gedung, pohon di dalam ruang kelas,
 * tiang bendera di dalam Gedung A, dan dinding luar ±245 yang memotong peta.
 *
 * Semua posisi dihitung dari campus.json (sudah direnggangkan):
 *  - Tata letak: batas kampus, gerbang, lapangan upacara (titik paling lapang), plaza air mancur.
 *  - Jalan setapak: jaringan dari gerbang ke setiap pintu gedung & fasilitas (medan jarak BFS,
 *    lintasan terpendek yang lurus) → tidak pernah menembus gedung.
 *  - Vegetasi: pohon 4 jenis (bertajuk, tinggi, cemara, berbunga) bervariasi warna & skala, bergoyang
 *    angin (shader), semak, bunga, bayangan kontak; tidak pernah di gedung / jalan / lapangan.
 *  - Kawasan luar: jalan lingkar, rumah warga, sawah, sungai, bukit di kejauhan.
 * InstancedMesh di mana-mana (draw call rendah, aman untuk HP).
 */
(function () {
  'use strict';
  var W = window.VirtualCampus3D;
  if (!W || !window.THREE) return;
  var P = W.prototype;

  function lin(hex) { return new THREE.Color(hex).convertSRGBToLinear(); }
  function rng(seed) { var s = (seed % 2147483647 + 2147483647) % 2147483647 || 1; return function () { s = s * 16807 % 2147483647; return (s - 1) / 2147483646; }; }
  function noise2(x, z) {
    var a = Math.sin(x * 0.021 + 1.7) * Math.cos(z * 0.017 - 0.4), b = Math.sin((x + z) * 0.011 + 3.1) * 0.6, c = Math.sin(x * 0.053 - z * 0.047) * 0.35;
    return (a + b + c) / 1.95 * 0.5 + 0.5;
  }
  function merge(list) {
    var pos = [], nor = [];
    list.forEach(function (it) {
      var g = it.geo.index ? it.geo.toNonIndexed() : it.geo.clone();
      if (it.matrix) g.applyMatrix4(it.matrix);
      var p = g.attributes.position.array, n = g.attributes.normal.array, i;
      for (i = 0; i < p.length; i++) { pos.push(p[i]); nor.push(n[i]); }
    });
    var out = new THREE.BufferGeometry();
    out.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    out.setAttribute('normal', new THREE.Float32BufferAttribute(nor, 3));
    out.computeBoundingSphere();
    return out;
  }
  function M4(x, y, z, sx, sy, sz, ry, rx, rz) {
    return new THREE.Matrix4().compose(new THREE.Vector3(x, y, z), new THREE.Quaternion().setFromEuler(new THREE.Euler(rx || 0, ry || 0, rz || 0)), new THREE.Vector3(sx, sy === undefined ? sx : sy, sz === undefined ? sx : sz));
  }
  /** Prisma segitiga (atap pelana) 1×1×1, alas di y=0. */
  function prismGeo() {
    var g = new THREE.BufferGeometry();
    var v = [-0.5, 0, -0.5, 0.5, 0, -0.5, 0, 1, -0.5, -0.5, 0, 0.5, 0.5, 0, 0.5, 0, 1, 0.5];
    var t = [0, 2, 1, 3, 4, 5, 0, 3, 5, 0, 5, 2, 1, 2, 5, 1, 5, 4, 0, 1, 4, 0, 4, 3];
    var pos = [];
    t.forEach(function (i) { pos.push(v[i * 3], v[i * 3 + 1], v[i * 3 + 2]); });
    g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    g.computeVertexNormals();
    return g;
  }

  /** Material berwarna linear, toon bila diizinkan; opsional goyang angin (shader). */
  P.landMat = function (hex, opt) {
    opt = opt || {};
    var toon = this.toonEnabled !== false && this.effectiveQuality !== 'low' && !opt.lambert;
    var m = toon ? new THREE.MeshToonMaterial({ color: lin(hex), gradientMap: this.toonGradient() }) : new THREE.MeshLambertMaterial({ color: lin(hex) });
    if (opt.map) { m.map = opt.map; }
    if (opt.side) m.side = opt.side;
    if (opt.transparent) { m.transparent = true; m.opacity = opt.opacity; m.depthWrite = false; }
    if (opt.wind) this.windify(m, opt.wind);
    (this.__linDone || (this.__linDone = new WeakSet())).add(m);
    return m;
  };
  /** Goyang angin lembut di shader: makin tinggi verteks makin bergoyang, fase per instance. */
  P.windify = function (m, amp) {
    var U = this.__windU || (this.__windU = { uTime: { value: 0 } });
    m.onBeforeCompile = function (sh) {
      sh.uniforms.uTime = U.uTime;
      sh.vertexShader = 'uniform float uTime;\n' + sh.vertexShader.replace('#include <begin_vertex>', [
        '#include <begin_vertex>',
        'vec3 wO = vec3(0.0);',
        '#ifdef USE_INSTANCING',
        '  wO = (instanceMatrix * vec4(0.0, 0.0, 0.0, 1.0)).xyz;',
        '#endif',
        'float hgt = max(position.y - ' + (amp.base || 3).toFixed(1) + ', 0.0);',
        'float ph = uTime * 1.25 + wO.x * 0.045 + wO.z * 0.031;',
        'float sw = (sin(ph) * 0.65 + sin(ph * 2.3 + wO.x) * 0.35) * ' + (amp.k || 0.05).toFixed(3) + ' * hgt;',
        'transformed.x += sw; transformed.z += sw * 0.55;'
      ].join('\n'));
    };
    m.customProgramCacheKey = function () { return 'wind' + (amp.base || 3) + '_' + (amp.k || 0.05); };
  };

  /* ======================================================================
   * Tata letak
   * ==================================================================== */
  P.computeLayout = function (cd) {
    var rects = [], X = [], Z = [];
    (cd.buildings || []).forEach(function (b) {
      var bb = b.bounds, r = { x0: bb.x - bb.width / 2, x1: bb.x + bb.width / 2, z0: bb.z - bb.depth / 2, z1: bb.z + bb.depth / 2, id: b.id, type: b.type, b: b };
      r.door = { x: bb.x, z: r.z1 + 3.5 };
      rects.push(r); X.push(r.x0, r.x1); Z.push(r.z0, r.z1);
    });
    var fac = [];
    var fz = cd.sports && cd.sports.futsal;
    if (fz) fac.push({ x0: fz.x - fz.width / 2 - 5, x1: fz.x + fz.width / 2 + 5, z0: fz.z - fz.depth / 2 - 5, z1: fz.z + fz.depth / 2 + 5, id: 'futsal', door: { x: fz.x - fz.width / 2 - 9, z: fz.z } });
    var pk = cd.parking && cd.parking.student;
    if (pk) fac.push({ x0: pk.x - pk.width / 2, x1: pk.x + pk.width / 2, z0: pk.z - pk.depth / 2, z1: pk.z + pk.depth / 2, id: 'parkir', door: { x: pk.x, z: pk.z + pk.depth / 2 + 4 } });
    var ct = cd.canteen && cd.canteen.outdoor;
    if (ct) fac.push({ x0: ct.x - ct.width / 2, x1: ct.x + ct.width / 2, z0: ct.z - ct.depth / 2, z1: ct.z + ct.depth / 2, id: 'kantin', open: true, door: { x: ct.x, z: ct.z + ct.depth / 2 + 3 } });
    fac.forEach(function (r) { X.push(r.x0, r.x1); Z.push(r.z0, r.z1); });
    var M = 24;
    var L = { rects: rects, fac: fac, X0: Math.min.apply(null, X) - M, X1: Math.max.apply(null, X) + M, Z0: Math.min.apply(null, Z) - M, Z1: Math.max.apply(null, Z) + M };
    var gate = rects.filter(function (r) { return r.id === 'gate-main'; })[0];
    L.gateZ = cd.__gateZ !== undefined ? cd.__gateZ : gate ? (gate.z0 + gate.z1) / 2 : (L.Z0 + L.Z1) / 2;
    var fnt = cd.garden && cd.garden.fountain;
    L.fountain = fnt ? { x: fnt.x, z: fnt.z, r: (fnt.radius || 8), R: (fnt.radius || 8) + 11 } : null;
    // Lapangan upacara: titik paling lapang antara Gedung B & A (40×28), dicari otomatis.
    var all = rects.concat(fac);
    var clearance = function (cx, cz, hw, hd) {
      var best = 1e9;
      all.forEach(function (r) {
        var dx = Math.max(r.x0 - (cx + hw), (cx - hw) - r.x1, 0), dz = Math.max(r.z0 - (cz + hd), (cz - hd) - r.z1, 0);
        var d = (dx === 0 && dz === 0) ? -1 : Math.hypot(dx, dz);
        if (d < best) best = d;
      });
      if (L.fountain) { var df = Math.hypot(Math.max(Math.abs(cx - L.fountain.x) - hw, 0), Math.max(Math.abs(cz - L.fountain.z) - hd, 0)) - L.fountain.R; if (df < best) best = df; }
      return best;
    };
    var B = rects.filter(function (r) { return r.id === 'gedung-b'; })[0], A = rects.filter(function (r) { return r.id === 'gedung-a'; })[0];
    var bestC = null, bestS = -1e9;
    var zA = B ? B.z1 + 20 : L.Z0 + 40, zB = A ? A.z0 - 20 : L.Z1 - 40, cx0 = B ? (B.x0 + B.x1) / 2 : 0;
    for (var cz = zA; cz <= zB; cz += 3) for (var cx = cx0 - 70; cx <= cx0 + 70; cx += 3) {
      var c = clearance(cx, cz, 20, 14);
      if (c < 7) continue;
      var score = Math.min(c, 16) - Math.abs(cx - cx0) * 0.08;
      if (score > bestS) { bestS = score; bestC = { x: cx, z: cz }; }
    }
    L.upacara = bestC ? { x: bestC.x, z: bestC.z, w: 40, d: 28 } : null;
    return L;
  };

  /* ======================================================================
   * Jaringan jalan setapak (medan jarak BFS dari jaringan → lintasan terpendek & lurus)
   * ==================================================================== */
  P.buildPathNetwork = function (L) {
    var C = 2, nx = Math.ceil((L.X1 - L.X0) / C), nz = Math.ceil((L.Z1 - L.Z0) / C), N = nx * nz;
    var blocked = new Uint8Array(N), core = new Uint8Array(N), net = new Uint8Array(N);
    var cxOf = function (i) { return L.X0 + (i + 0.5) * C; }, czOf = function (j) { return L.Z0 + (j + 0.5) * C; };
    var cell = function (x, z) { return { i: Math.max(0, Math.min(nx - 1, Math.floor((x - L.X0) / C))), j: Math.max(0, Math.min(nz - 1, Math.floor((z - L.Z0) / C))) }; };
    var markRect = function (r, pad, arr) {
      var a = cell(r.x0 - pad, r.z0 - pad), b = cell(r.x1 + pad, r.z1 + pad);
      for (var j = a.j; j <= b.j; j++) for (var i = a.i; i <= b.i; i++) arr[j * nx + i] = 1;
    };
    L.rects.forEach(function (r) { markRect(r, 2, blocked); markRect(r, 0.5, core); });
    L.fac.forEach(function (r) { if (!r.open) { markRect(r, 1, blocked); markRect(r, 0, core); } });
    if (L.fountain) {
      for (var j = 0; j < nz; j++) for (var i = 0; i < nx; i++) {
        var d = Math.hypot(cxOf(i) - L.fountain.x, czOf(j) - L.fountain.z);
        if (d < L.fountain.r + 3) { blocked[j * nx + i] = 1; core[j * nx + i] = 1; }
      }
    }
    var near = new Uint8Array(N);
    for (var jj = 0; jj < nz; jj++) for (var ii = 0; ii < nx; ii++) {
      if (blocked[jj * nx + ii]) continue;
      for (var dj = -1; dj <= 1; dj++) for (var di = -1; di <= 1; di++) {
        var a2 = ii + di, b2 = jj + dj;
        if (a2 >= 0 && a2 < nx && b2 >= 0 && b2 < nz && blocked[b2 * nx + a2]) near[jj * nx + ii] = 1;
      }
    }
    // Benih jaringan: gerbang (sisi timur) + keliling lapangan upacara + cincin plaza air mancur.
    var g = cell(L.X1 - 1, L.gateZ);
    for (var gi = g.i - 6; gi <= g.i; gi++) net[g.j * nx + gi] = 1;
    var seedRing = function (cx, cz, rad) {
      for (var t = 0; t < 64; t++) { var c = cell(cx + Math.cos(t / 64 * Math.PI * 2) * rad, cz + Math.sin(t / 64 * Math.PI * 2) * rad); if (!blocked[c.j * nx + c.i]) net[c.j * nx + c.i] = 2; }
    };
    if (L.upacara) { var U = L.upacara, a = cell(U.x - U.w / 2, U.z - U.d / 2), b = cell(U.x + U.w / 2, U.z + U.d / 2); for (var uj = a.j; uj <= b.j; uj++) for (var ui = a.i; ui <= b.i; ui++) if (uj === a.j || uj === b.j || ui === a.i || ui === b.i) net[uj * nx + ui] = 2; }
    // Tujuan: pintu tiap gedung & fasilitas + plaza air mancur + lapangan upacara.
    var targets = [];
    L.rects.forEach(function (r) { if (r.type !== 'gate') targets.push({ x: r.door.x, z: r.door.z, w: 1 }); });
    L.fac.forEach(function (r) { targets.push({ x: r.door.x, z: r.door.z, w: 1 }); });
    if (L.fountain) targets.push({ x: L.fountain.x, z: L.fountain.z + L.fountain.R + 1, w: 1, ring: L.fountain });
    if (L.upacara) targets.unshift({ x: L.upacara.x + L.upacara.w / 2 + 1, z: L.upacara.z, w: 2 });
    var gx = L.X1, gz = L.gateZ;
    targets.sort(function (p, q) { return Math.hypot(p.x - gx, p.z - gz) - Math.hypot(q.x - gx, q.z - gz); });
    var dist = new Int32Array(N), queue = new Int32Array(N), routes = [];
    var NB = [[1, 0], [-1, 0], [0, 1], [0, -1]];
    var self = this;
    targets.forEach(function (tg, ti) {
      // medan jarak dari jaringan saat ini (BFS 4-arah di sel bebas)
      dist.fill(-1);
      var qh = 0, qt = 0, k;
      for (k = 0; k < N; k++) if (net[k] && !blocked[k]) { dist[k] = 0; queue[qt++] = k; }
      while (qh < qt) {
        var cur = queue[qh++], ci = cur % nx, cj = (cur - ci) / nx;
        for (var n = 0; n < 4; n++) {
          var ni = ci + NB[n][0], nj = cj + NB[n][1];
          if (ni < 0 || nj < 0 || ni >= nx || nj >= nz) continue;
          var nk = nj * nx + ni;
          if (blocked[nk] || dist[nk] >= 0) continue;
          dist[nk] = dist[cur] + 1; queue[qt++] = nk;
        }
      }
      var s = cell(tg.x, tg.z), sk = s.j * nx + s.i;
      if (dist[sk] < 0) { // pintu tertutup penghalang → cari sel bebas terdekat
        var bestK = -1, bestD = 1e9;
        for (var rj = -4; rj <= 4; rj++) for (var ri = -4; ri <= 4; ri++) { var ck = (s.j + rj) * nx + (s.i + ri); if (s.j + rj >= 0 && s.j + rj < nz && s.i + ri >= 0 && s.i + ri < nx && dist[ck] >= 0 && Math.abs(ri) + Math.abs(rj) < bestD) { bestD = Math.abs(ri) + Math.abs(rj); bestK = ck; } }
        if (bestK < 0) return;
        sk = bestK;
      }
      // turun mengikuti medan jarak; utamakan arah yang sama (jalan lurus) & menjauhi dinding
      var path = [sk], dir = -1, guard = 0;
      while (dist[sk] > 0 && guard++ < 4000) {
        var si = sk % nx, sj = (sk - si) / nx, pick = -1, pickD = -1, pickScore = 1e9;
        for (var m = 0; m < 4; m++) {
          var ai = si + NB[m][0], aj = sj + NB[m][1];
          if (ai < 0 || aj < 0 || ai >= nx || aj >= nz) continue;
          var ak = aj * nx + ai;
          if (dist[ak] < 0 || dist[ak] >= dist[sk]) continue;
          var sc = (m === dir ? 0 : 1) + (near[ak] ? 0.5 : 0);
          if (sc < pickScore) { pickScore = sc; pick = ak; pickD = m; }
        }
        if (pick < 0) break;
        sk = pick; dir = pickD; path.push(sk);
      }
      path.forEach(function (pk2) { if (!net[pk2]) net[pk2] = tg.w; });
      routes.push(path);
    });
    // Topeng render: jaringan dilebarkan 1 sel (≈6 unit) tanpa masuk ke badan gedung.
    var mask = new Uint8Array(N), edge = new Uint8Array(N);
    for (var y = 0; y < nz; y++) for (var x = 0; x < nx; x++) {
      if (!net[y * nx + x]) continue;
      var rad = net[y * nx + x] >= 2 ? 1 : 1;
      for (var oy = -rad; oy <= rad; oy++) for (var ox = -rad; ox <= rad; ox++) {
        var px = x + ox, py = y + oy;
        if (px >= 0 && py >= 0 && px < nx && py < nz && !core[py * nx + px]) mask[py * nx + px] = 1;
      }
    }
    for (var y2 = 0; y2 < nz; y2++) for (var x2 = 0; x2 < nx; x2++) {
      if (mask[y2 * nx + x2]) continue;
      for (var e = 0; e < 4; e++) { var ex = x2 + NB[e][0], ey = y2 + NB[e][1]; if (ex >= 0 && ey >= 0 && ex < nx && ey < nz && mask[ey * nx + ex] && !core[y2 * nx + x2]) { edge[y2 * nx + x2] = 1; break; } }
    }
    var grid = { C: C, nx: nx, nz: nz, X0: L.X0, Z0: L.Z0, mask: mask, edge: edge, core: core, routes: routes, cxOf: cxOf, czOf: czOf };
    grid.isPath = function (wx, wz, pad) {
      var i = Math.floor((wx - L.X0) / C), j = Math.floor((wz - L.Z0) / C), p = pad || 0;
      for (var dj2 = -p; dj2 <= p; dj2++) for (var di2 = -p; di2 <= p; di2++) { var a3 = i + di2, b3 = j + dj2; if (a3 >= 0 && b3 >= 0 && a3 < nx && b3 < nz && (mask[b3 * nx + a3] || edge[b3 * nx + a3])) return true; }
      return false;
    };
    // Gambar: baris demi baris → kotak instans (1 draw call jalan + 1 tepian).
    var runs = function (arr) {
      var out = [];
      for (var r = 0; r < nz; r++) { var st = -1; for (var c2 = 0; c2 <= nx; c2++) { var on = c2 < nx && arr[r * nx + c2]; if (on && st < 0) st = c2; if (!on && st >= 0) { out.push([st, c2, r]); st = -1; } } }
      return out;
    };
    var drawRuns = function (list, hex, y, jitter) {
      if (!list.length) return null;
      var im = new THREE.InstancedMesh(new THREE.BoxGeometry(1, 0.08, 1), self.landMat(hex, { lambert: true }), list.length);
      var rnd = rng(77), col = new THREE.Color();
      list.forEach(function (rw, n2) {
        var len = (rw[1] - rw[0]) * C;
        im.setMatrixAt(n2, M4(L.X0 + rw[0] * C + len / 2, y, L.Z0 + (rw[2] + 0.5) * C, len, 1, C + 0.02));
        col.copy(lin(hex)).multiplyScalar(1 - jitter + rnd() * jitter * 2); im.setColorAt(n2, col);
      });
      im.receiveShadow = true;
      self.scene.add(im);
      return im;
    };
    drawRuns(runs(edge), '#a8916a', 0.035, 0.05);
    drawRuns(runs(mask), '#dcc9a2', 0.06, 0.04);
    this.pathGrid = grid;
    return grid;
  };

  /* ======================================================================
   * Bangunan dunia (override buildWorld)
   * ==================================================================== */
  P.buildCampusStreetLamps = function () { this.streetLamps = this.streetLamps || []; }; // dibangun di lanskap
  P.buildCampusPerimeter = function () {};                                                   // diganti batas baru

  P.buildWorld = function () {
    var cd = this.campusData || { buildings: [] };
    var self = this;
    var L = this.layout = this.computeLayout(cd);
    this.landRnd = rng(20261016);
    this.buildTerrain(L);
    (cd.buildings || []).forEach(function (b) { self.createBuildingMesh(b); });
    this.safeStep('buildAprons', function () { self.buildAprons(L); });
    this.safeStep('buildPerimeterFence', function () { self.buildPerimeterFence(); });
    this.safeStep('buildFutsalField', function () { self.buildFutsalField(); });
    this.safeStep('buildPathNetwork', function () { self.buildPathNetwork(L); });
    this.safeStep('buildGarden', function () { self.buildGarden(); });
    this.safeStep('buildUpacara', function () { self.buildUpacara(L); });
    this.safeStep('buildParkingZone', function () { self.buildParkingZone(); });
    this.safeStep('buildOutdoorCafeteria', function () { self.buildOutdoorCafeteria(); });
    this.safeStep('buildNeighborhood', function () { self.buildNeighborhood(L); });
    this.safeStep('buildVegetation', function () { self.buildVegetation(L); });
    this.safeStep('buildLampsBenches', function () { self.buildLampsBenches(L); });
    this.safeStep('buildInteractiveHotspots', function () { self.buildInteractiveHotspots(); });
    this.safeStep('linearize', function () { self.linearize(self.scene); });
    this.safeStep('toonify', function () { self.toonify(self.scene); });
    if (this.mergeBuildings) this.safeStep('mergeBuildings', function () { self.mergeBuildings(); }); // world3d-arch.js
    this.safeStep('edgeOutlines', function () { self.addEdgeOutlines(self.scene); });
    // waktu angin & air (satu jam global, diperbarui tiap frame lewat this.addTick)
    var U = this.__windU || (this.__windU = { uTime: { value: 0 } });
    var t0 = performance.now();
    this.addTick(function () { var t = (performance.now() - t0) / 1000; U.uTime.value = t; if (self.waterTex) { self.waterTex.offset.x = t * 0.012; self.waterTex.offset.y = t * 0.02; } });
  };

  P.buildTerrain = function (L) {
    var grassTex = this.makeSurfaceTexture('grass', 180, 180);
    var ground = new THREE.Mesh(new THREE.PlaneGeometry(3600, 3600), this.landMat('#ffffff', { lambert: true, map: grassTex }));
    ground.rotation.x = -Math.PI / 2; ground.position.y = -0.05; ground.receiveShadow = true;
    this.scene.add(ground);
    // halaman kampus sedikit lebih hijau-segar (dirawat) di dalam pagar
    var lawn = new THREE.Mesh(new THREE.PlaneGeometry(L.X1 - L.X0, L.Z1 - L.Z0), this.landMat('#c9e6a8', { lambert: true, map: this.makeSurfaceTexture('grass', 60, 34), transparent: true, opacity: 0.55 }));
    lawn.rotation.x = -Math.PI / 2; lawn.position.set((L.X0 + L.X1) / 2, -0.03, (L.Z0 + L.Z1) / 2); lawn.receiveShadow = true;
    this.scene.add(lawn);
  };

  /** Alas paving di sekeliling setiap gedung (gedung "duduk" di trotoar, tidak melayang di rumput). */
  P.buildAprons = function (L) {
    var list = L.rects.filter(function (r) { return r.type !== 'gate'; });
    var im = new THREE.InstancedMesh(new THREE.BoxGeometry(1, 0.1, 1), this.landMat('#d6cdb8', { lambert: true }), list.length);
    list.forEach(function (r, i) { im.setMatrixAt(i, M4((r.x0 + r.x1) / 2, 0.04, (r.z0 + r.z1) / 2, r.x1 - r.x0 + 6, 1, r.z1 - r.z0 + 6)); });
    im.receiveShadow = true;
    this.scene.add(im);
    // bak bunga di kiri-kanan setiap pintu
    var beds = [];
    list.forEach(function (r) { if ((r.x1 - r.x0) > 18) { beds.push([r.door.x - 9, r.z1 + 1.6], [r.door.x + 9, r.z1 + 1.6]); } });
    this.__doorBeds = beds;
    var bed = new THREE.InstancedMesh(new THREE.BoxGeometry(6, 0.9, 1.8), this.landMat('#9b6b4a'), beds.length);
    beds.forEach(function (p, i) { bed.setMatrixAt(i, M4(p[0], 0.45, p[1], 1)); });
    bed.castShadow = true;
    this.scene.add(bed);
  };

  /* ---------------- Pagar keliling mengikuti batas baru ---------------- */
  P.buildPerimeterFence = function () {
    var L = this.layout, X0 = L.X0, X1 = L.X1, Z0 = L.Z0, Z1 = L.Z1, H = 4.2;
    var gz = L.gateZ;
    var B = L.rects.filter(function (r) { return r.id === 'gedung-b'; })[0], A = L.rects.filter(function (r) { return r.id === 'gedung-a'; })[0];
    var GATE = { side: 'E', at: gz, w: 26 };
    var gaps = [GATE, { side: 'W', at: gz, w: 12 }, { side: 'N', at: B ? (B.x0 + B.x1) / 2 : 0, w: 12 }, { side: 'S', at: A ? (A.x0 + A.x1) / 2 : 0, w: 12 }];
    var sides = [[X0, Z0, X1, Z0, 'N'], [X0, Z1, X1, Z1, 'S'], [X0, Z0, X0, Z1, 'W'], [X1, Z0, X1, Z1, 'E']];
    var runs = [], postPts = [], barPts = [], self = this;
    sides.forEach(function (sd) {
      var alongX = sd[1] === sd[3], a = alongX ? sd[0] : sd[1], b = alongX ? sd[2] : sd[3];
      var cuts = gaps.filter(function (g2) { return g2.side === sd[4]; }).map(function (g2) { return [g2.at - g2.w / 2, g2.at + g2.w / 2]; }).sort(function (p, q) { return p[0] - q[0]; });
      var cur = a;
      cuts.forEach(function (c) { if (c[0] > cur) runs.push({ alongX: alongX, fixed: alongX ? sd[1] : sd[0], a: cur, b: c[0] }); cur = Math.max(cur, c[1]); });
      if (b > cur) runs.push({ alongX: alongX, fixed: alongX ? sd[1] : sd[0], a: cur, b: b });
    });
    var fence = new THREE.Group();
    var wallM = this.landMat('#e8dcc3'), capM = this.landMat('#8a5a3c'), barM = this.landMat('#3f4f5f');
    runs.forEach(function (r) {
      var len = r.b - r.a, at = function (t) { return r.alongX ? [t, r.fixed] : [r.fixed, t]; };
      var step = Math.max(5, len / Math.max(1, Math.round(len / 9)));
      for (var t = r.a; t <= r.b + 0.01; t += step) postPts.push(at(t));
      for (var t2 = r.a + 0.9; t2 < r.b - 0.5; t2 += 1.3) barPts.push(at(t2));
      // dinding rendah (krem) di bawah jeruji besi
      var c = at((r.a + r.b) / 2);
      var wall = new THREE.Mesh(new THREE.BoxGeometry(r.alongX ? len : 0.9, 1.4, r.alongX ? 0.9 : len), wallM);
      wall.position.set(c[0], 0.7, c[1]); wall.castShadow = true; wall.receiveShadow = true; fence.add(wall);
      var rail = new THREE.Mesh(new THREE.BoxGeometry(r.alongX ? len : 0.3, 0.25, r.alongX ? 0.3 : len), barM);
      rail.position.set(c[0], H - 0.3, c[1]); fence.add(rail);
      var p1 = at(r.a), p2 = at(r.b);
      self.colliders.push({ minX: Math.min(p1[0], p2[0]) - 0.5, maxX: Math.max(p1[0], p2[0]) + 0.5, minZ: Math.min(p1[1], p2[1]) - 0.5, maxZ: Math.max(p1[1], p2[1]) + 0.5, minY: 0, maxY: H, height: H });
    });
    var inst = function (geo, m, pts, y) {
      var im = new THREE.InstancedMesh(geo, m, pts.length), mm = new THREE.Matrix4();
      pts.forEach(function (p, i) { mm.makeTranslation(p[0], y, p[1]); im.setMatrixAt(i, mm); });
      im.castShadow = true; fence.add(im);
    };
    inst(new THREE.BoxGeometry(1.0, H + 0.4, 1.0), capM, postPts, (H + 0.4) / 2);
    inst(new THREE.BoxGeometry(0.14, H - 1.6, 0.14), barM, barPts, 1.4 + (H - 1.6) / 2);
    // Gerbang utama: pilar bata + papan nama + 2 daun gerbang terbuka
    var half = GATE.w / 2, gx = X1, pillarM = this.landMat('#a24b2a');
    [gz - half - 1.4, gz + half + 1.4].forEach(function (pz) {
      var pil = new THREE.Mesh(new THREE.BoxGeometry(2.8, 9, 2.8), pillarM); pil.position.set(gx, 4.5, pz); pil.castShadow = true;
      var cap = new THREE.Mesh(new THREE.BoxGeometry(3.4, 0.7, 3.4), capM); cap.position.set(gx, 9.35, pz);
      var lamp = new THREE.Mesh(new THREE.SphereGeometry(0.6, 10, 8), self.landMat('#fff3c4')); lamp.position.set(gx, 10.2, pz);
      fence.add(pil, cap, lamp);
      self.colliders.push({ minX: gx - 1.4, maxX: gx + 1.4, minZ: pz - 1.4, maxZ: pz + 1.4, minY: 0, maxY: 9, height: 9 });
    });
    var arch = this.createSignCanvas('🏫 SMK SILBER — SELAMAT DATANG', GATE.w + 5.6, 3.2);
    arch.position.set(gx, 10.8, gz); arch.rotation.y = Math.PI / 2; fence.add(arch);
    var leafW = half - 0.4, leafM = barM;
    [-1, 1].forEach(function (s) {
      var hinge = new THREE.Group(); hinge.position.set(gx, 0, gz + s * half); hinge.rotation.y = s * -1.25;
      var leaf = new THREE.Mesh(new THREE.BoxGeometry(0.3, 4.6, leafW), leafM); leaf.position.set(0, 2.5, -s * leafW / 2);
      hinge.add(leaf); fence.add(hinge);
    });
    this.scene.add(fence);
  };

  /* ---------------- Plaza air mancur ---------------- */
  P.buildGarden = function () {
    var F = this.layout && this.layout.fountain;
    if (!F) return;
    var g = new THREE.Group(); g.position.set(F.x, 0, F.z);
    var plaza = new THREE.Mesh(new THREE.CircleGeometry(F.R, 48), this.landMat('#ffffff', { lambert: true, map: this.makeSurfaceTexture('paving', 6, 6) }));
    plaza.rotation.x = -Math.PI / 2; plaza.position.y = 0.07; plaza.receiveShadow = true; g.add(plaza);
    var ring = new THREE.Mesh(new THREE.RingGeometry(F.R - 0.6, F.R, 48), this.landMat('#a8916a', { lambert: true }));
    ring.rotation.x = -Math.PI / 2; ring.position.y = 0.09; g.add(ring);
    // kolam: bibir batu (lathe) + air beriak (tekstur bergerak)
    var rim = new THREE.Mesh(new THREE.LatheGeometry([new THREE.Vector2(F.r + 0.3, 0), new THREE.Vector2(F.r + 0.8, 0.2), new THREE.Vector2(F.r + 0.8, 1.6), new THREE.Vector2(F.r, 1.8), new THREE.Vector2(F.r - 0.2, 0.3)], 40), this.landMat('#cdbfa6'));
    rim.castShadow = true; rim.receiveShadow = true; g.add(rim);
    var wc = document.createElement('canvas'); wc.width = wc.height = 128;
    var wg = wc.getContext('2d'); wg.fillStyle = '#3f8fb0'; wg.fillRect(0, 0, 128, 128);
    for (var i = 0; i < 70; i++) { wg.strokeStyle = i % 3 ? 'rgba(200,236,245,0.35)' : 'rgba(20,70,100,0.3)'; wg.lineWidth = 2; wg.beginPath(); var x = (i * 37) % 128, y = (i * 53) % 128; wg.arc(x, y, 6 + (i % 5) * 3, 0.2, 2.2); wg.stroke(); }
    var wt = new THREE.CanvasTexture(wc); wt.wrapS = wt.wrapT = THREE.RepeatWrapping; wt.repeat.set(3, 3); wt.encoding = THREE.sRGBEncoding;
    this.waterTex = wt;
    var water = new THREE.Mesh(new THREE.CircleGeometry(F.r, 40), new THREE.MeshLambertMaterial({ map: wt, transparent: true, opacity: 0.92 }));
    water.rotation.x = -Math.PI / 2; water.position.y = 1.35; g.add(water);
    this.fountainWater = water;
    // pancuran bertingkat
    var tier = this.landMat('#d8ccb4');
    var t1 = new THREE.Mesh(new THREE.CylinderGeometry(0.9, 1.3, 3.2, 16), tier); t1.position.y = 2.6; t1.castShadow = true; g.add(t1);
    var bowl = new THREE.Mesh(new THREE.CylinderGeometry(3.0, 1.0, 0.8, 24), tier); bowl.position.y = 4.4; bowl.castShadow = true; g.add(bowl);
    var t2 = new THREE.Mesh(new THREE.CylinderGeometry(0.5, 0.7, 2.2, 12), tier); t2.position.y = 5.6; g.add(t2);
    var top = new THREE.Mesh(new THREE.SphereGeometry(0.8, 14, 10), tier); top.position.y = 6.9; g.add(top);
    var spray = new THREE.Mesh(new THREE.ConeGeometry(2.6, 2.8, 20, 1, true), new THREE.MeshBasicMaterial({ color: lin('#e8f7ff'), transparent: true, opacity: 0.35, depthWrite: false, side: THREE.DoubleSide }));
    spray.position.y = 5.6; spray.rotation.x = Math.PI; spray.userData.linear = true; g.add(spray);
    this.colliders.push({ minX: F.x - F.r - 0.8, maxX: F.x + F.r + 0.8, minZ: F.z - F.r - 0.8, maxZ: F.z + F.r + 0.8, minY: 0, maxY: 2, height: 2 });
    this.scene.add(g);
    this.plazaBenches = [];
    for (var b = 0; b < 4; b++) { var ang = b * Math.PI / 2 + Math.PI / 4, rr = F.r + 6.5; this.plazaBenches.push({ x: F.x + Math.cos(ang) * rr, z: F.z + Math.sin(ang) * rr, ry: -ang + Math.PI / 2 }); }
  };

  /* ---------------- Lapangan upacara + bendera berkibar ---------------- */
  P.buildUpacara = function (L) {
    var U = L.upacara;
    if (!U) return;
    var g = new THREE.Group(); g.position.set(U.x, 0, U.z);
    var pad = new THREE.Mesh(new THREE.BoxGeometry(U.w, 0.12, U.d), this.landMat('#ffffff', { lambert: true, map: this.makeSurfaceTexture('paving', 10, 7) }));
    pad.position.y = 0.06; pad.receiveShadow = true; g.add(pad);
    var lineM = this.landMat('#f5f1e6', { lambert: true });
    [[U.w - 2, 0.3, 0, -U.d / 2 + 1], [U.w - 2, 0.3, 0, U.d / 2 - 1], [0.3, U.d - 2, -U.w / 2 + 1, 0], [0.3, U.d - 2, U.w / 2 - 1, 0]].forEach(function (l) {
      var m = new THREE.Mesh(new THREE.BoxGeometry(l[0], 0.02, l[1]), lineM); m.position.set(l[2], 0.13, l[3]); g.add(m);
    });
    // tiang bendera di sisi utara (menghadap selatan) + alas bertingkat
    var base1 = new THREE.Mesh(new THREE.BoxGeometry(5, 0.6, 5), this.landMat('#cdbfa6')); base1.position.set(0, 0.3, -U.d / 2 + 4); base1.castShadow = true; g.add(base1);
    var base2 = new THREE.Mesh(new THREE.BoxGeometry(3, 0.6, 3), this.landMat('#bfb194')); base2.position.set(0, 0.9, -U.d / 2 + 4); g.add(base2);
    var pole = new THREE.Mesh(new THREE.CylinderGeometry(0.18, 0.26, 20, 10), this.landMat('#e6e9ee')); pole.position.set(0, 11, -U.d / 2 + 4); pole.castShadow = true; g.add(pole);
    var knob = new THREE.Mesh(new THREE.SphereGeometry(0.4, 10, 8), this.landMat('#d9b44a')); knob.position.set(0, 21.1, -U.d / 2 + 4); g.add(knob);
    var fc = document.createElement('canvas'); fc.width = 64; fc.height = 42;
    var fg = fc.getContext('2d'); fg.fillStyle = '#d7263d'; fg.fillRect(0, 0, 64, 21); fg.fillStyle = '#ffffff'; fg.fillRect(0, 21, 64, 21);
    var ft = new THREE.CanvasTexture(fc); ft.encoding = THREE.sRGBEncoding;
    var fm = new THREE.MeshLambertMaterial({ map: ft, side: THREE.DoubleSide });
    var FU = this.flagUniforms = { uWind: { value: 1 } };
    var WU = this.__windU || (this.__windU = { uTime: { value: 0 } });
    fm.onBeforeCompile = function (sh) {
      sh.uniforms.uTime = WU.uTime; sh.uniforms.uWind = FU.uWind;
      sh.vertexShader = 'uniform float uTime;\nuniform float uWind;\n' + sh.vertexShader.replace('#include <begin_vertex>',
        '#include <begin_vertex>\nfloat fx = uv.x;\ntransformed.z += sin(fx * 7.0 - uTime * 4.2 * uWind) * 0.45 * fx * uWind;\ntransformed.y += sin(fx * 5.0 - uTime * 3.1) * 0.12 * fx;');
    };
    fm.customProgramCacheKey = function () { return 'flag'; };
    var flag = new THREE.Mesh(new THREE.PlaneGeometry(6, 4, 18, 6), fm);
    flag.position.set(3.2, 18.6, -U.d / 2 + 4); flag.userData.linear = true; flag.userData.keepMat = true; // shader kibar tidak boleh diganti toon
    g.add(flag);
    this.colliders.push({ minX: U.x - 2.5, maxX: U.x + 2.5, minZ: U.z - U.d / 2 + 1.5, maxZ: U.z - U.d / 2 + 6.5, minY: 0, maxY: 1.2, height: 1.2 });
    // mimbar pembina di sisi selatan
    var podium = new THREE.Mesh(new THREE.BoxGeometry(6, 1.2, 3.5), this.landMat('#8a5a3c')); podium.position.set(0, 0.6, U.d / 2 - 3); podium.castShadow = true; g.add(podium);
    this.scene.add(g);
  };
})();
