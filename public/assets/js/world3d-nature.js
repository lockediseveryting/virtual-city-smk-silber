/*
 * World3D Nature (v7.8) — vegetasi, lampu taman, bangku & kawasan luar pagar SMK Silber.
 * Dipanggil dari buildWorld (world3d-land.js). Semua objek berulang = InstancedMesh.
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

  /* ---------------- Geometri pohon (sekali, dipakai ulang semua instans) ---------------- */
  function treeGeos() {
    var ico = function (r, d) { return new THREE.IcosahedronGeometry(r, d === undefined ? 1 : d); };
    return {
      // tajuk lebar berlapis (beringin/ketapang): gumpalan besar + kecil → siluet organik
      broad: merge([
        { geo: ico(4.6), matrix: M4(0, 10.2, 0, 1, 0.82, 1) },
        { geo: ico(3.4), matrix: M4(3.3, 9.0, 1.2, 1, 0.85, 1) },
        { geo: ico(3.2), matrix: M4(-3.1, 9.3, -1.0, 1, 0.85, 1) },
        { geo: ico(2.9), matrix: M4(0.6, 12.6, -1.4, 1, 0.9, 1) },
        { geo: ico(2.6), matrix: M4(-1.2, 8.6, 3.0, 1, 0.8, 1) }
      ]),
      // tajuk tinggi lonjong (glodokan/cemara angin)
      tall: merge([
        { geo: ico(2.8), matrix: M4(0, 8.5, 0, 1, 1.4, 1) },
        { geo: ico(2.4), matrix: M4(0.3, 12.3, 0.2, 1, 1.35, 1) },
        { geo: ico(1.7), matrix: M4(-0.1, 15.4, 0, 1, 1.3, 1) }
      ]),
      // cemara bertingkat
      pine: merge([
        { geo: new THREE.ConeGeometry(4.2, 6, 9), matrix: M4(0, 7.5, 0, 1) },
        { geo: new THREE.ConeGeometry(3.3, 5.2, 9), matrix: M4(0, 10.6, 0, 1) },
        { geo: new THREE.ConeGeometry(2.3, 4.2, 9), matrix: M4(0, 13.4, 0, 1) }
      ]),
      // pohon berbunga (flamboyan/tabebuya) — tajuk payung
      bloom: merge([
        { geo: ico(4.2), matrix: M4(0, 10.0, 0, 1.25, 0.6, 1.25) },
        { geo: ico(3.0), matrix: M4(2.8, 9.4, 1.5, 1.1, 0.6, 1.1) },
        { geo: ico(2.8), matrix: M4(-2.6, 9.6, -1.6, 1.1, 0.6, 1.1) }
      ]),
      trunk: merge([
        { geo: new THREE.CylinderGeometry(0.55, 0.95, 8.5, 8), matrix: M4(0, 4.25, 0, 1) },
        { geo: new THREE.CylinderGeometry(0.22, 0.4, 3.6, 6), matrix: M4(1.3, 7.6, 0.3, 1, 1, 1, 0, 0, -0.7) },
        { geo: new THREE.CylinderGeometry(0.2, 0.36, 3.2, 6), matrix: M4(-1.2, 7.9, -0.2, 1, 1, 1, 0, 0, 0.75) }
      ]),
      bush: merge([
        { geo: ico(1.3, 0), matrix: M4(0, 1.0, 0, 1, 0.8, 1) },
        { geo: ico(1.0, 0), matrix: M4(1.0, 0.8, 0.4, 1, 0.8, 1) },
        { geo: ico(0.95, 0), matrix: M4(-0.9, 0.75, -0.3, 1, 0.8, 1) }
      ]),
      flower: new THREE.IcosahedronGeometry(0.28, 0)
    };
  }
  var PALETTE = {
    broad: ['#4e8a3a', '#5c9a40', '#6aa64a', '#3f7a3a', '#76ad4e', '#8bb85a'],
    tall: ['#3f7d45', '#4b8a4c', '#5a9a52'],
    pine: ['#2f6b45', '#3a7a4c', '#2a5f40'],
    bloom: ['#f2a9b8', '#f4b2c0', '#ef8f5a', '#f2c14e', '#c9a0dc'],
    bush: ['#4f8c3c', '#5f9c46', '#3f7a38', '#6ea852'],
    flower: ['#f25c5c', '#ffd166', '#ffffff', '#c084fc', '#ff8fab', '#ffa94d']
  };

  /** Apakah titik bebas untuk ditanami (bukan gedung/jalan/lapangan/plaza/pagar/jalan raya/rumah). */
  P.landFree = function (x, z, clear) {
    var L = this.layout, c = clear || 0, i, r;
    for (i = 0; i < L.rects.length; i++) { r = L.rects[i]; if (x > r.x0 - 6 - c && x < r.x1 + 6 + c && z > r.z0 - 6 - c && z < r.z1 + 9 + c) return false; }
    for (i = 0; i < L.fac.length; i++) { r = L.fac[i]; if (x > r.x0 - 4 - c && x < r.x1 + 4 + c && z > r.z0 - 4 - c && z < r.z1 + 4 + c) return false; }
    if (L.fountain && Math.hypot(x - L.fountain.x, z - L.fountain.z) < L.fountain.R + 4 + c) return false;
    var U = L.upacara; if (U && Math.abs(x - U.x) < U.w / 2 + 5 + c && Math.abs(z - U.z) < U.d / 2 + 5 + c) return false;
    if (this.pathGrid && x > L.X0 && x < L.X1 && z > L.Z0 && z < L.Z1 && this.pathGrid.isPath(x, z, 2)) return false;
    var inX = x > L.X0 - 4 && x < L.X1 + 4, inZ = z > L.Z0 - 4 && z < L.Z1 + 4;
    if (inX && inZ && (Math.abs(x - L.X0) < 4 || Math.abs(x - L.X1) < 4 || Math.abs(z - L.Z0) < 4 || Math.abs(z - L.Z1) < 4)) return false; // pagar
    var O = this.outer;
    if (O) {
      var inRing = x > O.r0x0 && x < O.r0x1 && z > O.r0z0 && z < O.r0z1, inIn = x > O.r1x0 && x < O.r1x1 && z > O.r1z0 && z < O.r1z1;
      if (inRing && !inIn) return false; // jalan lingkar
      for (i = 0; i < O.houses.length; i++) { r = O.houses[i]; if (x > r.x0 - 3 && x < r.x1 + 3 && z > r.z0 - 3 && z < r.z1 + 3) return false; }
      if (O.field && x > O.field.x0 && x < O.field.x1 && z > O.field.z0 && z < O.field.z1) return false;
      if (O.river && z > O.river.z0 - 3 && z < O.river.z1 + 3) return false;
    }
    return true;
  };

  /* ---------------- Vegetasi ---------------- */
  P.buildVegetation = function (L) {
    var self = this, rnd = rng(4242), G = treeGeos();
    var q = this.effectiveQuality, cap = q === 'low' ? 230 : q === 'high' ? 760 : 520;
    var trees = [];
    var addTree = function (x, z, kind, s) { trees.push({ x: x, z: z, kind: kind, s: s || (0.85 + rnd() * 0.35), ry: rnd() * Math.PI * 2 }); };
    var pickKind = function (x, z, inside) {
      var n = noise2(x * 1.3 + 50, z * 1.3 - 20), r = rnd();
      if (inside && r < 0.12) return 'bloom';
      if (n > 0.66) return 'pine';
      if (n < 0.3) return 'tall';
      return r < 0.06 ? 'bloom' : 'broad';
    };
    // 1) Barisan peneduh di sisi dalam pagar (sekolah rindang)
    var edgeRun = function (x0, z0, x1, z1) {
      var len = Math.hypot(x1 - x0, z1 - z0), n = Math.floor(len / 15);
      for (var i = 0; i <= n; i++) {
        var t = i / Math.max(1, n), x = x0 + (x1 - x0) * t + (rnd() - 0.5) * 3, z = z0 + (z1 - z0) * t + (rnd() - 0.5) * 3;
        if (self.landFree(x, z, 0)) addTree(x, z, pickKind(x, z, true));
      }
    };
    edgeRun(L.X0 + 9, L.Z0 + 9, L.X1 - 9, L.Z0 + 9); edgeRun(L.X0 + 9, L.Z1 - 9, L.X1 - 9, L.Z1 - 9);
    edgeRun(L.X0 + 9, L.Z0 + 9, L.X0 + 9, L.Z1 - 9); edgeRun(L.X1 - 9, L.Z0 + 9, L.X1 - 9, L.Z1 - 9);
    // 2) Pohon peneduh di tepi jalan setapak (selang-seling, tidak menutup jalan)
    var grid = this.pathGrid;
    if (grid) grid.routes.forEach(function (route) {
      for (var k = 6; k < route.length - 4; k += 9) {
        var ck = route[k], ci = ck % grid.nx, cj = (ck - ci) / grid.nx, nk = route[Math.min(route.length - 1, k + 1)], ni = nk % grid.nx, nj = (nk - ni) / grid.nx;
        var dx = ni - ci, dz = nj - cj, side = (k / 9) % 2 ? 1 : -1;
        var x = grid.cxOf(ci) + (-dz) * side * 7.5, z = grid.czOf(cj) + dx * side * 7.5;
        if (self.landFree(x, z, 0)) addTree(x, z, rnd() < 0.2 ? 'bloom' : 'broad', 0.8 + rnd() * 0.25);
      }
    });
    // 3) Kebun & kelompok pohon (noise) di halaman kampus dan di luar
    var X0 = L.X0 - 260, X1 = L.X1 + 260, Z0 = L.Z0 - 260, Z1 = L.Z1 + 260;
    // dua putaran: halaman kampus dulu (prioritas), baru sekeliling luar sampai batas jumlah
    for (var pass = 0; pass < 2; pass++) for (var gz = Z0; gz < Z1; gz += 11) for (var gx = X0; gx < X1; gx += 11) {
      if (trees.length > cap) break;
      var x = gx + (rnd() - 0.5) * 9, z = gz + (rnd() - 0.5) * 9;
      var inside = x > L.X0 && x < L.X1 && z > L.Z0 && z < L.Z1;
      if ((pass === 0) !== inside) continue;
      var dens = noise2(x, z);
      var thresh = inside ? 0.58 : 0.5;
      if (dens < thresh || rnd() > (inside ? 0.7 : 0.55)) continue;
      if (!self.landFree(x, z, 0)) continue;
      addTree(x, z, pickKind(x, z, inside));
    }
    // 4) Hutan tepi dunia (padat, siluet berlapis di kabut)
    var O = this.outer;
    if (O) for (var a = 0; a < 140; a++) {
      var ang = rnd() * Math.PI * 2, rr = 1.0 + rnd() * 0.5;
      var hx = (O.bx1 - O.bx0) / 2 * rr + 30, hz = (O.bz1 - O.bz0) / 2 * rr + 30, cx = (O.bx0 + O.bx1) / 2, cz = (O.bz0 + O.bz1) / 2;
      var fx = cx + Math.cos(ang) * hx, fz2 = cz + Math.sin(ang) * hz;
      if (self.landFree(fx, fz2, 0)) addTree(fx, fz2, rnd() < 0.5 ? 'pine' : 'broad', 1.0 + rnd() * 0.5);
    }

    var kinds = ['broad', 'tall', 'pine', 'bloom'];
    var shadowsOn = q !== 'low';
    var trunkIM = new THREE.InstancedMesh(G.trunk, this.landMat('#7a5236'), trees.length);
    trunkIM.castShadow = shadowsOn;
    var blobIM = new THREE.InstancedMesh(new THREE.CircleGeometry(1, 16), new THREE.MeshBasicMaterial({ color: 0x14210f, transparent: true, opacity: 0.22, depthWrite: false }), trees.length);
    var col = new THREE.Color();
    trees.forEach(function (t, i) {
      trunkIM.setMatrixAt(i, M4(t.x, 0, t.z, t.s, t.s, t.s, t.ry));
      blobIM.setMatrixAt(i, M4(t.x, 0.11, t.z, 6.2 * t.s, 6.2 * t.s, 1, 0, -Math.PI / 2));
      var inside = t.x > L.X0 && t.x < L.X1 && t.z > L.Z0 && t.z < L.Z1;
      if (inside || (self.outer && t.x > self.outer.bx0 && t.x < self.outer.bx1 && t.z > self.outer.bz0 && t.z < self.outer.bz1)) {
        self.colliders.push({ minX: t.x - 0.9 * t.s, maxX: t.x + 0.9 * t.s, minZ: t.z - 0.9 * t.s, maxZ: t.z + 0.9 * t.s, minY: 0, maxY: 8, height: 8 });
      }
    });
    blobIM.userData.linear = true;
    this.scene.add(trunkIM); this.scene.add(blobIM);
    kinds.forEach(function (kind) {
      var list = trees.filter(function (t) { return t.kind === kind; });
      if (!list.length) return;
      var m = self.landMat('#ffffff', { wind: { base: kind === 'tall' ? 5 : 6, k: kind === 'pine' ? 0.025 : 0.04 } });
      var im = new THREE.InstancedMesh(G[kind], m, list.length);
      im.castShadow = shadowsOn;
      var pal = PALETTE[kind];
      list.forEach(function (t, i) {
        im.setMatrixAt(i, M4(t.x, 0, t.z, t.s, t.s * (0.92 + ((i * 7) % 5) * 0.04), t.s, t.ry));
        var pi = (((i * 3 + Math.floor(t.x)) % pal.length) + pal.length) % pal.length; // x bisa negatif
        col.copy(lin(pal[pi])).multiplyScalar(0.92 + noise2(t.x * 3, t.z * 3) * 0.16);
        im.setColorAt(i, col);
      });
      self.scene.add(im);
    });
    this.treeCount = trees.length;

    // Semak & bunga: di depan gedung (bak bunga), tepi plaza, tepi jalan setapak
    var bushes = [], flowers = [];
    (this.__doorBeds || []).forEach(function (p) {
      for (var k = 0; k < 3; k++) bushes.push([p[0] - 2 + k * 2, p[1], 0.55, 1.0]);
      for (var f = 0; f < 6; f++) flowers.push([p[0] - 2.5 + rnd() * 5, p[1] - 0.6 + rnd() * 1.2, 1.4]);
    });
    L.rects.forEach(function (r) {
      if (r.type === 'gate') return;
      for (var x2 = r.x0 + 3; x2 < r.x1 - 3; x2 += 7) {
        if (Math.abs(x2 - r.door.x) < 13) continue;
        bushes.push([x2 + (rnd() - 0.5), r.z1 + 1.8, 0, 0.75 + rnd() * 0.3]);
        if (rnd() < 0.6) flowers.push([x2 + rnd() * 3, r.z1 + 2.6, 0.12]);
      }
    });
    if (L.fountain) for (var b = 0; b < 16; b++) { var an = b / 16 * Math.PI * 2; if (b % 4 === 1) continue; var bx = L.fountain.x + Math.cos(an) * (L.fountain.R + 2.5), bz = L.fountain.z + Math.sin(an) * (L.fountain.R + 2.5); if (self.landFree(bx, bz, -3.5)) { bushes.push([bx, bz, 0, 0.9]); flowers.push([bx + 1, bz, 0.12]); } }
    for (var n2 = 0; n2 < (q === 'low' ? 120 : 320); n2++) {
      var rx = L.X0 + rnd() * (L.X1 - L.X0), rz = L.Z0 + rnd() * (L.Z1 - L.Z0);
      if (noise2(rx * 2, rz * 2) < 0.45 || !self.landFree(rx, rz, -3)) continue;
      bushes.push([rx, rz, 0, 0.7 + rnd() * 0.5]);
      for (var f2 = 0; f2 < 3; f2++) flowers.push([rx + (rnd() - 0.5) * 4, rz + (rnd() - 0.5) * 4, 0.12]);
    }
    var bm = new THREE.InstancedMesh(G.bush, this.landMat('#ffffff', { wind: { base: 0.6, k: 0.06 } }), bushes.length);
    bushes.forEach(function (p, i) { bm.setMatrixAt(i, M4(p[0], p[2], p[1], p[3], p[3], p[3], rnd() * 6)); col.copy(lin(PALETTE.bush[i % PALETTE.bush.length])); bm.setColorAt(i, col); });
    bm.castShadow = q === 'high';
    this.scene.add(bm);
    var fm = new THREE.InstancedMesh(G.flower, this.landMat('#ffffff', { lambert: true }), flowers.length);
    flowers.forEach(function (p, i) { fm.setMatrixAt(i, M4(p[0], p[2] + 0.3, p[1], 1)); col.copy(lin(PALETTE.flower[i % PALETTE.flower.length])); fm.setColorAt(i, col); });
    this.scene.add(fm);
  };

  /* ---------------- Lampu taman & bangku ---------------- */
  P.buildLampsBenches = function (L) {
    var self = this, grid = this.pathGrid, lamps = [], benches = [], rnd = rng(99);
    if (grid) grid.routes.forEach(function (route, ri) {
      for (var k = 3; k < route.length - 2; k += 13) {
        var ck = route[k], ci = ck % grid.nx, cj = (ck - ci) / grid.nx, nk = route[k + 1], ni = nk % grid.nx, nj = (nk - ni) / grid.nx;
        var dx = ni - ci, dz = nj - cj, side = (k / 13 + ri) % 2 ? 1 : -1;
        var x = grid.cxOf(ci) + (-dz) * side * 4.4, z = grid.czOf(cj) + dx * side * 4.4;
        if (!grid.isPath(x, z, 0) && !lamps.some(function (p) { return Math.hypot(p[0] - x, p[1] - z) < 14; })) lamps.push([x, z]);
        if (rnd() < 0.18) { var bx = grid.cxOf(ci) - (-dz) * side * 5.2, bz = grid.czOf(cj) - dx * side * 5.2; if (!grid.isPath(bx, bz, 0)) benches.push({ x: bx, z: bz, ry: Math.atan2(dx, dz) + Math.PI / 2 }); }
      }
    });
    if (L.upacara) { var U = L.upacara; [[-1, -1], [1, -1], [-1, 1], [1, 1]].forEach(function (s) { lamps.push([U.x + s[0] * (U.w / 2 + 2.5), U.z + s[1] * (U.d / 2 + 2.5)]); }); }
    (this.plazaBenches || []).forEach(function (b) { benches.push(b); });
    var O = this.outer;
    if (O) for (var t = 0; t < 1; t += 0.035) { // lampu jalan lingkar luar
      var per = 2 * ((O.r1x1 - O.r1x0) + (O.r1z1 - O.r1z0)), d = t * per, lx, lz, w = O.r1x1 - O.r1x0, h = O.r1z1 - O.r1z0;
      if (d < w) { lx = O.r1x0 + d; lz = O.r1z0 - 1.5; } else if (d < w + h) { lx = O.r1x1 + 1.5; lz = O.r1z0 + d - w; } else if (d < 2 * w + h) { lx = O.r1x1 - (d - w - h); lz = O.r1z1 + 1.5; } else { lx = O.r1x0 - 1.5; lz = O.r1z1 - (d - 2 * w - h); }
      lamps.push([lx, lz]);
    }
    var poleM = this.landMat('#2f3a40'), lanternM = new THREE.MeshLambertMaterial({ color: lin('#fff6d8'), emissive: 0x000000 });
    (this.__linDone || (this.__linDone = new WeakSet())).add(lanternM);
    var poles = new THREE.InstancedMesh(merge([
      { geo: new THREE.CylinderGeometry(0.22, 0.32, 9, 8), matrix: M4(0, 4.5, 0, 1) },
      { geo: new THREE.CylinderGeometry(0.5, 0.6, 0.5, 8), matrix: M4(0, 0.25, 0, 1) },
      { geo: new THREE.BoxGeometry(1.8, 0.18, 0.18), matrix: M4(0.7, 8.8, 0, 1) },
      { geo: new THREE.ConeGeometry(0.85, 0.7, 6), matrix: M4(1.5, 9.1, 0, 1) }
    ]), poleM, lamps.length);
    var lant = new THREE.InstancedMesh(new THREE.SphereGeometry(0.55, 10, 8), lanternM, lamps.length);
    lamps.forEach(function (p, i) {
      var ry = (i % 4) * Math.PI / 2;
      poles.setMatrixAt(i, M4(p[0], 0, p[1], 1, 1, 1, ry));
      lant.setMatrixAt(i, M4(p[0] + Math.cos(ry) * 1.5, 8.5, p[1] - Math.sin(ry) * 1.5, 1));
      self.colliders.push({ minX: p[0] - 0.5, maxX: p[0] + 0.5, minZ: p[1] - 0.5, maxZ: p[1] + 0.5, minY: 0, maxY: 9, height: 9 });
    });
    poles.castShadow = this.effectiveQuality !== 'low';
    lant.userData.linear = true;
    this.scene.add(poles); this.scene.add(lant);
    this.streetLamps = [{ lantern: lant, pLight: { intensity: 0 } }];
    // bangku kayu (dudukan + sandaran + kaki besi)
    var seatG = merge([
      { geo: new THREE.BoxGeometry(5, 0.3, 1.4), matrix: M4(0, 1.5, 0, 1) },
      { geo: new THREE.BoxGeometry(5, 1.1, 0.25), matrix: M4(0, 2.3, -0.65, 1) }
    ]);
    var legG = merge([{ geo: new THREE.BoxGeometry(0.25, 1.5, 1.3), matrix: M4(-2.1, 0.75, 0, 1) }, { geo: new THREE.BoxGeometry(0.25, 1.5, 1.3), matrix: M4(2.1, 0.75, 0, 1) }]);
    var seats = new THREE.InstancedMesh(seatG, this.landMat('#a8693f'), benches.length), legs = new THREE.InstancedMesh(legG, this.landMat('#2f3a40'), benches.length);
    benches.forEach(function (b, i) {
      seats.setMatrixAt(i, M4(b.x, 0, b.z, 1, 1, 1, b.ry)); legs.setMatrixAt(i, M4(b.x, 0, b.z, 1, 1, 1, b.ry));
      self.colliders.push({ minX: b.x - 1.5, maxX: b.x + 1.5, minZ: b.z - 1.5, maxZ: b.z + 1.5, minY: 0, maxY: 1.6, height: 1.6 });
    });
    seats.castShadow = true;
    this.scene.add(seats); this.scene.add(legs);
  };

  /* ---------------- Kawasan luar pagar ---------------- */
  P.buildNeighborhood = function (L) {
    var self = this, rnd = rng(1998);
    var R0 = 12, R1 = 26, HB = 52; // jalan lingkar: 12–26 dari pagar; rumah sampai 52; batas dunia
    var O = this.outer = {
      r0x0: L.X0 - R1, r0x1: L.X1 + R1, r0z0: L.Z0 - R1, r0z1: L.Z1 + R1,   // tepi luar jalan
      r1x0: L.X0 - R0, r1x1: L.X1 + R0, r1z0: L.Z0 - R0, r1z1: L.Z1 + R0,   // tepi dalam jalan
      bx0: L.X0 - HB - 10, bx1: L.X1 + HB + 10, bz0: L.Z0 - HB - 10, bz1: L.Z1 + HB + 10,
      houses: []
    };
    // aspal (4 sisi) + garis putus-putus + trotoar
    var asph = this.landMat('#55595c', { lambert: true }), side = this.landMat('#c9bfa8', { lambert: true });
    var strip = function (x0, z0, x1, z1, m, y) { var me = new THREE.Mesh(new THREE.BoxGeometry(x1 - x0, 0.1, z1 - z0), m); me.position.set((x0 + x1) / 2, y, (z0 + z1) / 2); me.receiveShadow = true; self.scene.add(me); };
    strip(O.r0x0, O.r0z0, O.r0x1, O.r1z0, asph, 0.03); strip(O.r0x0, O.r1z1, O.r0x1, O.r0z1, asph, 0.03);
    strip(O.r0x0, O.r1z0, O.r1x0, O.r1z1, asph, 0.031); strip(O.r1x1, O.r1z0, O.r0x1, O.r1z1, asph, 0.031);
    strip(O.r0x0 - 4, O.r0z0 - 4, O.r0x1 + 4, O.r0z0, side, 0.05); strip(O.r0x0 - 4, O.r0z1, O.r0x1 + 4, O.r0z1 + 4, side, 0.05);
    strip(O.r0x0 - 4, O.r0z0, O.r0x0, O.r0z1, side, 0.05); strip(O.r0x1, O.r0z0, O.r0x1 + 4, O.r0z1, side, 0.05);
    // jalan masuk dari gerbang ke jalan lingkar
    strip(L.X1, L.gateZ - 11, O.r1x1, L.gateZ + 11, this.landMat('#cdbfa6', { lambert: true }), 0.04);
    var dashes = [], mid = (R0 + R1) / 2;
    for (var x = O.r0x0 + 8; x < O.r0x1 - 8; x += 9) { dashes.push([x, L.Z0 - mid, 1]); dashes.push([x, L.Z1 + mid, 1]); }
    for (var z = O.r0z0 + 8; z < O.r0z1 - 8; z += 9) { dashes.push([L.X0 - mid, z, 0]); dashes.push([L.X1 + mid, z, 0]); }
    var dm = new THREE.InstancedMesh(new THREE.BoxGeometry(4, 0.04, 0.4), this.landMat('#f3efe2', { lambert: true }), dashes.length);
    dashes.forEach(function (d, i) { dm.setMatrixAt(i, M4(d[0], 0.09, d[1], 1, 1, 1, d[2] ? 0 : Math.PI / 2)); });
    this.scene.add(dm);

    // Sisi utara: sawah bertingkat + sungai kecil (pemandangan); sisi lain: rumah warga.
    O.field = { x0: O.r0x0 - 4, x1: O.r0x1 + 4, z0: O.bz0 - 140, z1: O.r0z0 - 8 };
    var fc = document.createElement('canvas'); fc.width = 64; fc.height = 64;
    var fg = fc.getContext('2d');
    for (var row = 0; row < 8; row++) { fg.fillStyle = row % 2 ? '#8fc45a' : '#a7d06a'; fg.fillRect(0, row * 8, 64, 8); fg.fillStyle = 'rgba(120,160,190,0.35)'; fg.fillRect(0, row * 8 + 6, 64, 1); }
    var ft = new THREE.CanvasTexture(fc); ft.wrapS = ft.wrapT = THREE.RepeatWrapping; ft.repeat.set(40, 16); ft.encoding = THREE.sRGBEncoding;
    var field = new THREE.Mesh(new THREE.PlaneGeometry(O.field.x1 - O.field.x0, O.field.z1 - O.field.z0), this.landMat('#ffffff', { lambert: true, map: ft }));
    field.rotation.x = -Math.PI / 2; field.position.set((O.field.x0 + O.field.x1) / 2, 0.02, (O.field.z0 + O.field.z1) / 2); field.receiveShadow = true;
    this.scene.add(field);
    // pematang sawah (garis tanah)
    var bunds = [];
    for (var bx = O.field.x0; bx < O.field.x1; bx += 34) bunds.push([bx, (O.field.z0 + O.field.z1) / 2, 0]);
    for (var bz = O.field.z0; bz < O.field.z1; bz += 26) bunds.push([(O.field.x0 + O.field.x1) / 2, bz, 1]);
    var bundM = new THREE.InstancedMesh(new THREE.BoxGeometry(1, 0.5, 1), this.landMat('#8a6a46', { lambert: true }), bunds.length);
    bunds.forEach(function (b, i) { bundM.setMatrixAt(i, b[2] ? M4(b[0], 0.2, b[1], O.field.x1 - O.field.x0, 1, 1.2) : M4(b[0], 0.2, b[1], 1.2, 1, O.field.z1 - O.field.z0)); });
    this.scene.add(bundM);
    var rz = O.field.z0 + 40;
    O.river = { z0: rz - 9, z1: rz + 9 };
    var rc = document.createElement('canvas'); rc.width = rc.height = 128;
    var rg = rc.getContext('2d'); rg.fillStyle = '#4f9bb8'; rg.fillRect(0, 0, 128, 128);
    for (var w = 0; w < 90; w++) { rg.fillStyle = w % 3 ? 'rgba(214,240,246,0.35)' : 'rgba(30,80,110,0.25)'; rg.fillRect((w * 41) % 128, (w * 23) % 128, 10 + (w % 7) * 3, 2); }
    var rt = new THREE.CanvasTexture(rc); rt.wrapS = rt.wrapT = THREE.RepeatWrapping; rt.repeat.set(60, 3); rt.encoding = THREE.sRGBEncoding;
    this.riverTex = rt;
    var river = new THREE.Mesh(new THREE.PlaneGeometry(3600, 18), new THREE.MeshLambertMaterial({ map: rt }));
    river.rotation.x = -Math.PI / 2; river.position.set(0, 0.06, rz); river.userData.linear = true;
    this.scene.add(river);
    var bank = new THREE.Mesh(new THREE.PlaneGeometry(3600, 24), this.landMat('#b89a6a', { lambert: true }));
    bank.rotation.x = -Math.PI / 2; bank.position.set(0, 0.04, rz); this.scene.add(bank);
    var t0 = performance.now();
    this.addTick(function () { rt.offset.x = (performance.now() - t0) / 1000 * 0.02; });

    // Rumah warga (timur, barat, selatan) — dinding pastel, atap pelana, pintu & jendela
    var lots = [];
    var row2 = function (x0, z0, x1, z1, face) {
      var len = Math.hypot(x1 - x0, z1 - z0), n = Math.floor(len / 24);
      for (var i = 0; i < n; i++) {
        if (rnd() < 0.18) continue; // lahan kosong / kebun
        var t = (i + 0.5) / n, cx = x0 + (x1 - x0) * t, cz = z0 + (z1 - z0) * t;
        if (face === 'W' && Math.abs(cz - L.gateZ) < 22) continue; // depan gerbang dibiarkan lapang
        var wv = 12 + rnd() * 5, dv = 10 + rnd() * 3, hv = 6.5 + rnd() * 2.5;
        lots.push({ x: cx, z: cz, w: wv, d: dv, h: hv, face: face });
      }
    };
    var off = R1 + 15;
    row2(L.X1 + off, L.Z0 - 6, L.X1 + off, L.Z1 + 6, 'W');
    row2(L.X0 - off, L.Z0 - 6, L.X0 - off, L.Z1 + 6, 'E');
    row2(L.X0 - 6, L.Z1 + off, L.X1 + 6, L.Z1 + off, 'N');
    var walls = ['#f3e3c3', '#e9d2b4', '#d9e8d2', '#f2d0c0', '#d5e2ee', '#efe6d0'], roofs = ['#b5523b', '#9c4632', '#5f7f6b', '#7a4e3a', '#4f6f8f'];
    var bodyIM = new THREE.InstancedMesh(new THREE.BoxGeometry(1, 1, 1), this.landMat('#ffffff'), lots.length);
    var roofIM = new THREE.InstancedMesh(prismGeo(), this.landMat('#ffffff'), lots.length);
    var doorIM = new THREE.InstancedMesh(new THREE.BoxGeometry(2, 3.6, 0.3), this.landMat('#6b4a32'), lots.length);
    var winIM = new THREE.InstancedMesh(new THREE.BoxGeometry(2.2, 1.8, 0.3), this.landMat('#9ec7d9'), lots.length * 2);
    var col = new THREE.Color();
    lots.forEach(function (h, i) {
      var ry = h.face === 'W' ? -Math.PI / 2 : h.face === 'E' ? Math.PI / 2 : Math.PI; // pintu menghadap jalan
      var sw = (h.face === 'N') ? h.w : h.d, sd = (h.face === 'N') ? h.d : h.w;
      bodyIM.setMatrixAt(i, M4(h.x, h.h / 2, h.z, h.w, h.h, h.d, ry));
      col.copy(lin(walls[i % walls.length])); bodyIM.setColorAt(i, col);
      roofIM.setMatrixAt(i, M4(h.x, h.h, h.z, h.w + 1.6, 3.6, h.d + 1.4, ry));
      col.copy(lin(roofs[(i * 2) % roofs.length])); roofIM.setColorAt(i, col);
      var fx = Math.sin(ry), fz = Math.cos(ry), front = h.d / 2 + 0.1; // depan (lokal +z) setelah rotasi
      doorIM.setMatrixAt(i, M4(h.x + fx * front, 1.8, h.z + fz * front, 1, 1, 1, ry));
      var rx = Math.cos(ry), rzz = -Math.sin(ry);
      winIM.setMatrixAt(i * 2, M4(h.x + fx * front + rx * h.w * 0.28, 3.9, h.z + fz * front + rzz * h.w * 0.28, 1, 1, 1, ry));
      winIM.setMatrixAt(i * 2 + 1, M4(h.x + fx * front - rx * h.w * 0.28, 3.9, h.z + fz * front - rzz * h.w * 0.28, 1, 1, 1, ry));
      var hw = sw / 2, hd = sd / 2;
      O.houses.push({ x0: h.x - hw, x1: h.x + hw, z0: h.z - hd, z1: h.z + hd });
      self.colliders.push({ minX: h.x - hw, maxX: h.x + hw, minZ: h.z - hd, maxZ: h.z + hd, minY: 0, maxY: h.h + 3, height: h.h + 3 });
    });
    [bodyIM, roofIM, doorIM].forEach(function (m) { m.castShadow = true; m.receiveShadow = true; self.scene.add(m); });
    this.scene.add(winIM);
    // Batas dunia: pagar tanaman (semak rapat) + collider tak terlihat
    var hedge = [], b0x = O.bx0, b1x = O.bx1, b0z = O.r0z0 - 6, b1z = O.bz1;
    for (var hx = b0x; hx <= b1x; hx += 3.2) { hedge.push([hx, b0z]); hedge.push([hx, b1z]); }
    for (var hz = b0z; hz <= b1z; hz += 3.2) { hedge.push([b0x, hz]); hedge.push([b1x, hz]); }
    var hm = new THREE.InstancedMesh(new THREE.IcosahedronGeometry(1.9, 0), this.landMat('#3f7a3a'), hedge.length);
    hedge.forEach(function (p, i) { hm.setMatrixAt(i, M4(p[0], 1.3, p[1], 1, 0.85 + (i % 3) * 0.1, 1, i)); });
    this.scene.add(hm);
    [[b0x - 2, b0z - 2, b1x + 2, b0z + 1.5], [b0x - 2, b1z - 1.5, b1x + 2, b1z + 2], [b0x - 2, b0z, b0x + 1.5, b1z], [b1x - 1.5, b0z, b1x + 2, b1z]].forEach(function (c) {
      self.colliders.push({ minX: c[0], maxX: c[2], minZ: c[1], maxZ: c[3], minY: 0, maxY: 40, height: 40 });
    });
    // Bukit & gunung jauh (siluet berlapis dalam kabut)
    var hills = [];
    for (var k = 0; k < 18; k++) {
      var an = k / 18 * Math.PI * 2 + rnd() * 0.2, dist = 820 + rnd() * 380;
      hills.push([Math.cos(an) * dist, Math.sin(an) * dist, 140 + rnd() * 180, 45 + rnd() * 80]);
    }
    var hillIM = new THREE.InstancedMesh(new THREE.IcosahedronGeometry(1, 2), this.landMat('#ffffff', { lambert: true }), hills.length);
    hills.forEach(function (h, i) { hillIM.setMatrixAt(i, M4(h[0], -h[3] * 0.25, h[1], h[2], h[3], h[2] * 0.8, i)); col.copy(lin(i % 3 ? '#6f9a70' : '#7d9fb0')); hillIM.setColorAt(i, col); });
    this.scene.add(hillIM);
  };

})();
