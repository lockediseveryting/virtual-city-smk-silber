/*
 * World3D Architecture (v7.9) — fasad gedung SMK Silber yang berkarakter + penggabungan mesh.
 *  - Fasad: alas batu (plint), pilaster di sudut & sepanjang fasad, lis tiap lantai, luifel (peneduh
 *    beton) di atas deret jendela, dinding krem hangat yang harmonis (bukan putih polos).
 *  - Atap genteng pelana terakota (gedung kelas, lab, perpustakaan, bank, minimarket). Musholah tetap kubah.
 *  - Kinerja: mesh statis per lantai digabung per material (dulu 500–650 mesh per gedung bertingkat)
 *    → draw call turun drastis, aman di HP. Kursi yang bisa diduduki & mesh bergaris-tepi tidak digabung;
 *    kaca jendela gabungan tetap ikut berubah warna malam (windowMeshes diperbarui).
 * Posisi/ukuran gedung TIDAK berubah (denah & collider tetap). ES2017.
 */
(function () {
  'use strict';
  var W = window.VirtualCampus3D;
  if (!W || !window.THREE) return;
  var P = W.prototype;
  var FH = typeof FLOOR_H !== 'undefined' ? FLOOR_H : 10;
  var DECOR_TYPES = { academic: 1, library: 1, bank: 1, market: 1, workshop: 1, lab: 1 };

  function prism() { // atap pelana 1×1×1 (alas y=0, bubungan sepanjang x)
    var g = new THREE.BufferGeometry();
    var v = [-0.5, 0, -0.5, 0.5, 0, -0.5, -0.5, 1, 0, 0.5, 1, 0, -0.5, 0, 0.5, 0.5, 0, 0.5];
    var t = [0, 2, 1, 1, 2, 3, 4, 5, 2, 5, 3, 2, 0, 4, 2, 1, 3, 5, 0, 1, 4, 1, 5, 4];
    var pos = [];
    t.forEach(function (i) { pos.push(v[i * 3], v[i * 3 + 1], v[i * 3 + 2]); });
    g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    g.computeVertexNormals();
    return g;
  }
  function warmWall(hex) { // dinding putih/abu dingin → krem hangat; warna lain dilunakkan ke krem
    var c = new THREE.Color(hex), cream = new THREE.Color('#efe4cd');
    var hsl = {}; c.getHSL(hsl);
    if (hsl.s < 0.15 && hsl.l > 0.7) return cream.getHex();
    return c.lerp(cream, 0.35).getHex();
  }

  var origCreate = P.createBuildingMesh;
  P.createBuildingMesh = function (b) {
    if (b && b.wallColor && DECOR_TYPES[b.type]) b.wallColor = '#' + new THREE.Color(warmWall(b.wallColor)).getHexString();
    origCreate.call(this, b);
    try { this.decorateBuilding(b); } catch (e) { /* dekorasi opsional */ }
  };

  P.decorateBuilding = function (b) {
    if (!b || !DECOR_TYPES[b.type] || !b.levelGroups || !b.levelGroups.length) return;
    var w = b.bounds.width, d = b.bounds.depth, floors = b.levelGroups.length, multi = floors > 1;
    var wallH = multi ? FH : b.bounds.height, h = multi ? floors * FH : b.bounds.height;
    var trim = new THREE.MeshLambertMaterial({ color: 0xcdb48c });   // lis & luifel (krem tua)
    var stone = new THREE.MeshLambertMaterial({ color: 0x8d8073 });  // plint batu
    var pil = new THREE.MeshLambertMaterial({ color: 0xe0d2b4 });    // pilaster
    var doorW = Math.min(8.0, w * 0.42);
    var box = function (parent, sx, sy, sz, x, y, z, m) { var o = new THREE.Mesh(new THREE.BoxGeometry(sx, sy, sz), m); o.position.set(x, y, z); o.castShadow = true; o.receiveShadow = true; parent.add(o); return o; };
    b.levelGroups.forEach(function (lg, k) {
      var y0 = k * FH, yTop = y0 + wallH;
      if (k === 0) { // plint batu keliling (sisi depan terbelah pintu)
        var sideW = (w - doorW) / 2;
        box(lg, sideW, 1.2, 0.5, -w / 2 + sideW / 2, 0.6, d / 2 + 0.15, stone);
        box(lg, sideW, 1.2, 0.5, w / 2 - sideW / 2, 0.6, d / 2 + 0.15, stone);
        box(lg, w + 0.3, 1.2, 0.5, 0, 0.6, -d / 2 - 0.15, stone);
        box(lg, 0.5, 1.2, d + 0.3, -w / 2 - 0.15, 0.6, 0, stone);
        box(lg, 0.5, 1.2, d + 0.3, w / 2 + 0.15, 0.6, 0, stone);
      }
      // lis atas lantai (sabuk horizontal keliling)
      box(lg, w + 1.0, 0.55, 0.7, 0, yTop - 0.3, d / 2 + 0.25, trim);
      box(lg, w + 1.0, 0.55, 0.7, 0, yTop - 0.3, -d / 2 - 0.25, trim);
      box(lg, 0.7, 0.55, d + 1.0, -w / 2 - 0.25, yTop - 0.3, 0, trim);
      box(lg, 0.7, 0.55, d + 1.0, w / 2 + 0.25, yTop - 0.3, 0, trim);
      // luifel di atas deret jendela (depan & belakang)
      var shY = y0 + 6.25;
      if (shY < yTop - 1) {
        if (k === 0) {
          var sw = (w - doorW) / 2 - 1.5;
          if (sw > 2) { box(lg, sw, 0.2, 1.2, -w / 2 + 0.75 + sw / 2, shY, d / 2 + 0.6, trim); box(lg, sw, 0.2, 1.2, w / 2 - 0.75 - sw / 2, shY, d / 2 + 0.6, trim); }
        } else box(lg, w - 1.5, 0.2, 1.2, 0, shY, d / 2 + 0.6, trim);
        box(lg, w - 1.5, 0.2, 1.2, 0, shY, -d / 2 - 0.6, trim);
      }
      // pilaster: sudut + tiap ±12 unit di fasad depan & belakang
      var n = Math.max(1, Math.round(w / 12)), step = w / n;
      for (var i = 0; i <= n; i++) {
        var px = -w / 2 + i * step;
        [d / 2 + 0.2, -d / 2 - 0.2].forEach(function (pz, side) {
          if (k === 0 && side === 0 && Math.abs(px) < doorW / 2 + 1.2) return; // jangan menutup pintu
          box(lg, 0.8, wallH - 0.6, 0.45, px, y0 + (wallH - 0.6) / 2, pz, pil);
        });
      }
      [-w / 2 - 0.2, w / 2 + 0.2].forEach(function (px) { box(lg, 0.45, wallH - 0.6, 0.8, px, y0 + (wallH - 0.6) / 2, d / 2 - 0.4, pil); box(lg, 0.45, wallH - 0.6, 0.8, px, y0 + (wallH - 0.6) / 2, -d / 2 + 0.4, pil); });
    });
    // Atap genteng pelana terakota (sumbu bubungan = sisi terpanjang)
    if (b.roofGroup && !b.hasDome) {
      var along = w >= d, L = (along ? w : d) + 3.4, S = (along ? d : w) + 4.2;
      var rise = Math.min(9, S * 0.32);
      var tile = new THREE.MeshLambertMaterial({ color: 0xb4583e });
      var roof = new THREE.Mesh(prism(), tile);
      roof.scale.set(L, rise, S);
      if (!along) roof.rotation.y = Math.PI / 2;
      roof.position.y = h + 2.05;
      roof.castShadow = true; roof.userData.outline = true;
      b.roofGroup.add(roof);
      // rusuk genteng di kedua bidang atap + bubungan (dibangun searah sumbu x lalu diputar bersama)
      var ribM = new THREE.MeshLambertMaterial({ color: 0x8e3f2c });
      var rg = new THREE.Group(); rg.position.y = h + 2.05; if (!along) rg.rotation.y = Math.PI / 2;
      var ribs = Math.floor(L / 3.2), a = Math.atan2(rise, S / 2), slope = Math.hypot(S / 2, rise);
      for (var r = 1; r < ribs; r++) {
        var u = -L / 2 + r * (L / ribs);
        [-1, 1].forEach(function (sgn) {
          var rib = new THREE.Mesh(new THREE.BoxGeometry(0.22, 0.18, slope + 0.2), ribM);
          rib.rotation.x = -sgn * a; // ujung +z (ke bubungan) naik
          rib.position.set(u, rise / 2 + 0.08, -sgn * S / 4);
          rg.add(rib);
        });
      }
      var ridge = new THREE.Mesh(new THREE.BoxGeometry(L + 0.4, 0.5, 0.7), ribM);
      ridge.position.y = rise + 0.1;
      rg.add(ridge);
      b.roofGroup.add(rg);
    }
  };

  /* ---------------- Penggabungan mesh statis ---------------- */
  P.mergeStatic = function (container) {
    if (!container) return 0;
    container.updateMatrixWorld(true);
    var inv = new THREE.Matrix4().copy(container.matrixWorld).invert();
    var exclude = this.__mergeExclude, winSet = this.__winSet;
    var buckets = {}, order = [];
    container.traverse(function (o) {
      if (o === container || !o.isMesh || o.isInstancedMesh || o.isSkinnedMesh) return;
      if (exclude.has(o) || o.userData.noMerge || o.userData.isOutline) return;
      if (o.children.length) return;                                     // punya garis tepi / hull / anak lain
      if (!o.visible) return;
      var m = o.material, g = o.geometry;
      if (!m || Array.isArray(m) || !g || !g.attributes || !g.attributes.position || !g.attributes.normal) return;
      if (m.isMeshBasicMaterial && m.map) return;                       // papan nama kanvas: biarkan
      var hasUV = !!g.attributes.uv;
      var key = [m.type, m.color ? m.color.getHex() : '', m.emissive ? m.emissive.getHex() : '', m.map ? m.map.uuid : '', m.transparent ? 1 : 0, m.opacity, m.side,
        hasUV ? 1 : 0, o.castShadow ? 1 : 0, o.receiveShadow ? 1 : 0, o.userData.outline ? 1 : 0, winSet.has(o) ? 'win' : ''].join('|');
      if (!buckets[key]) { buckets[key] = { mat: m, uv: hasUV, items: [], win: winSet.has(o), cast: o.castShadow, recv: o.receiveShadow, outline: !!o.userData.outline }; order.push(key); }
      buckets[key].items.push(o);
    });
    var made = 0, tmp = new THREE.Matrix4(), nm = new THREE.Matrix3(), v = new THREE.Vector3();
    var self = this;
    order.forEach(function (key) {
      var B = buckets[key];
      if (B.items.length < 2) return;
      var pos = [], nor = [], uvs = [];
      B.items.forEach(function (o) {
        var g = o.geometry.index ? o.geometry.toNonIndexed() : o.geometry;
        tmp.multiplyMatrices(inv, o.matrixWorld);
        nm.getNormalMatrix(tmp);
        var pa = g.attributes.position.array, na = g.attributes.normal.array, ua = B.uv ? g.attributes.uv.array : null;
        for (var i = 0; i < pa.length; i += 3) {
          v.set(pa[i], pa[i + 1], pa[i + 2]).applyMatrix4(tmp); pos.push(v.x, v.y, v.z);
          v.set(na[i], na[i + 1], na[i + 2]).applyMatrix3(nm).normalize(); nor.push(v.x, v.y, v.z);
        }
        if (ua) for (var j = 0; j < ua.length; j++) uvs.push(ua[j]);
      });
      var geo = new THREE.BufferGeometry();
      geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
      geo.setAttribute('normal', new THREE.Float32BufferAttribute(nor, 3));
      if (B.uv) geo.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
      geo.computeBoundingSphere();
      var mesh = new THREE.Mesh(geo, B.mat);
      mesh.castShadow = B.cast; mesh.receiveShadow = B.recv;
      if (B.outline) mesh.userData.outline = true;
      mesh.userData.merged = B.items.length;
      container.add(mesh);
      B.items.forEach(function (o) { if (o.parent) o.parent.remove(o); });
      if (B.win) self.__newWin.push(mesh);
      made += B.items.length - 1;
    });
    return made;
  };

  P.mergeBuildings = function () {
    var self = this, cd = this.campusData || {};
    // Kursi: diganti penanda kosong di posisi yang sama (fitur duduk & sembunyi-lantai tetap jalan),
    // mesh kursinya ikut digabung (dulu ±40 kursi per gedung = 40 draw call).
    (this.seats || []).forEach(function (s) {
      var o = s.obj;
      if (!s.world) { s.world = new THREE.Vector3(); o.getWorldPosition(s.world); }
      if (!o.parent || !o.isMesh) return;
      var ph = new THREE.Object3D();
      ph.position.copy(o.position); ph.quaternion.copy(o.quaternion); ph.scale.copy(o.scale);
      o.parent.add(ph);
      s.obj = ph;
    });
    this.__mergeExclude = new Set();
    this.__winSet = new Set(this.windowMeshes || []);
    this.__newWin = [];
    var saved = 0;
    (cd.buildings || []).forEach(function (b) {
      (b.levelGroups || []).forEach(function (lg) { saved += self.mergeStatic(lg); });
      if (b.roofGroup) saved += self.mergeStatic(b.roofGroup);
      var g = b.levelGroups && b.levelGroups[0] && b.levelGroups[0].parent;
      if (g) { // anak langsung grup gedung (pondasi, lantai, kanopi) — tanpa masuk ke grup lantai/atap
        var kids = g.children.filter(function (c) { return c.isMesh; });
        if (kids.length > 1) {
          var holder = new THREE.Group(); g.add(holder);
          kids.forEach(function (c) { if (!c.children.length) { g.remove(c); holder.add(c); } });
          saved += self.mergeStatic(holder);
        }
      }
    });
    // kaca jendela: yang masih utuh + hasil gabungan (warna malam tetap jalan)
    this.windowMeshes = (this.windowMeshes || []).filter(function (m) { return !!m.parent; }).concat(this.__newWin);
    this.mergedMeshes = saved;
    return saved;
  };
})();
