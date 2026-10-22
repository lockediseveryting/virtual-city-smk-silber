/*
 * World3D Camera Flow (v7.9) — kamera orang-ketiga SETINGGI WAJAH / BAHU (bukan dari atas):
 *  - Over-the-shoulder: kamera sedikit di kanan bahu, menatap ke depan karakter → dunia terasa 3D.
 *  - Mengalir: redaman berbasis waktu (sama di HP 30 fps & 120 Hz); saat berjalan maju kamera pelan-pelan
 *    kembali ke belakang karakter (kecuali baru diputar pemain).
 *  - Anti tembus dinding: lintasan kepala→kamera diperiksa terhadap collider; kamera maju bila terhalang.
 *  - BAWAAN = POV "mata sendiri" seperti dunia nyata: kamera di mata, badan sendiri tak terlihat, hanya
 *    kedua tangan (warna lengan & kulit sesuai karakter) yang berayun saat berjalan + kepala bergoyang
 *    (head-bob) + tangan ikut beremote. Pemain lain tetap melihat avatar lengkap.
 *  - Mode kamera (tombol C / 🎥): POV → Bahu → 🤳 Lihat Wajah → Atas. Pilihan disimpan per perangkat.
 *  - Intro sinematik saat masuk dunia: terbang mengitari sekolah lalu turun ke belakang bahu (bisa dilewati).
 * Memperluas VirtualCampus3D tanpa mengganti engine. ES2017.
 */
(function () {
  'use strict';
  var W = window.VirtualCampus3D;
  if (!W || !window.THREE) return;
  var P = W.prototype;
  var HEAD = 3.75;        // tinggi kepala karakter (dunia, skala 0.6)
  var SHOULDER = 1.25;    // geser kamera ke kanan bahu
  var EYE = 3.55;         // tinggi mata POV
  // Tiga pandangan (permintaan pemilik): Mata (POV) → Atas Kepala → Normal.
  var MODES = ['first_person', 'top_down', 'third_person'];
  var LABEL = { first_person: '👀 Mata (POV)', top_down: '🔭 Atas Kepala', third_person: '🎥 Normal', selfie: '🤳 Lihat Wajah' };

  function lerpAngle(a, b, t) { var d = ((b - a + Math.PI) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2) - Math.PI; return a + d * t; }
  function ease(t) { return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; }

  /** Titik di dalam collider padat (dinding/gedung/pohon) pada ketinggian y? */
  P.camBlocked = function (x, y, z) {
    var cs = this.colliders || [];
    for (var i = 0; i < cs.length; i++) {
      var c = cs[i];
      if (x < c.minX || x > c.maxX || z < c.minZ || z > c.maxZ) continue;
      var lo = c.minY || 0, hi = c.maxY !== undefined ? c.maxY : (c.height || 0);
      if (y >= lo && y <= hi) return true;
    }
    return false;
  };

  var origUpdateCamera = P.updateCamera;
  P.updateCamera = function () {
    if (!this.player) return;
    var now = performance.now();
    var dt = Math.min(0.1, Math.max(0.001, (now - (this.__camT || now - 16)) / 1000));
    this.__camT = now;
    if (!this.__camInit) { // kamera baru: POV di mata (dulu 26 unit, 13 unit di atas kepala)
      this.__camInit = true;
      this.camDistance = 12.5; this.camPitch = 0.05;
      var saved = ''; try { saved = localStorage.getItem('vc_cam_mode') || ''; } catch (e) {}
      this.cameraMode = MODES.indexOf(saved) >= 0 ? saved : 'first_person';
      this.__look = new THREE.Vector3(); this.__camPos = this.camera.position.clone();
    }
    if (this.introActive) { this.setViewModel(false); this.updateIntro(now); return; }
    if (this.cameraMode === 'top_down') { this.setViewModel(false); this.updateOverhead(dt); return; }
    if (this.cameraMode === 'first_person') { this.updatePOV(dt); return; }
    this.setViewModel(false);

    var p = this.playerPos, indoors = !!this.insideBuilding, selfie = this.cameraMode === 'selfie';
    // FOV halus
    var fov = selfie ? 50 : indoors ? 66 : 58;
    if (Math.abs(this.camera.fov - fov) > 0.05) { this.camera.fov += (fov - this.camera.fov) * (1 - Math.exp(-dt * 6)); this.camera.updateProjectionMatrix(); }

    // Kamera ikut ke belakang karakter saat berjalan maju (bila pemain tidak sedang memutar kamera)
    if (this.__lastYaw !== undefined && Math.abs(this.camYaw - this.__lastYaw) > 1e-6) this.__userCamAt = now;
    if (!selfie && this.isMoving && now - (this.__userCamAt || 0) > 1500) {
      var behind = this.player.rotation.y + Math.PI;
      var diff = Math.abs(((behind - this.camYaw + Math.PI) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2) - Math.PI);
      if (diff < 1.05) this.camYaw = lerpAngle(this.camYaw, behind, 1 - Math.exp(-dt * 1.2));
    }
    this.__lastYaw = this.camYaw;

    var yaw = selfie ? this.player.rotation.y : this.camYaw;
    var dist = selfie ? Math.min(Math.max(this.camDistance, 5), 8) : Math.max(4, Math.min(115, this.camDistance));
    if (indoors && !selfie) dist = Math.min(dist, 10.5);
    var pitch = selfie ? 0.04 : (indoors ? Math.max(this.camPitch, 0.16) : this.camPitch);
    var s = Math.sin(yaw), c = Math.cos(yaw);
    var rx = c, rz = -s;                                       // vektor kanan kamera
    var sh = 0; // Normal: karakter di tengah layar (seperti sebelumnya)
    var headY = p.y + (selfie ? HEAD - 0.35 : HEAD);
    var pivot = new THREE.Vector3(p.x + rx * sh, headY, p.z + rz * sh);
    var h = dist * Math.cos(pitch);
    var want = new THREE.Vector3(pivot.x + s * h, headY + dist * Math.sin(pitch) + 0.35, pivot.z + c * h);
    // anti tembus dinding / pohon: maju sampai titik bebas terakhir
    var steps = Math.ceil(dist / 0.7), free = 1;
    for (var k = 1; k <= steps; k++) {
      var t = k / steps;
      if (this.camBlocked(pivot.x + (want.x - pivot.x) * t, pivot.y + (want.y - pivot.y) * t, pivot.z + (want.z - pivot.z) * t)) { free = Math.max(0.12, (k - 1.5) / steps); break; }
    }
    if (free < 1) want.set(pivot.x + (want.x - pivot.x) * free, pivot.y + (want.y - pivot.y) * free, pivot.z + (want.z - pivot.z) * free);
    if (indoors && free < 0.6 && !selfie) { // terhalang sekat di dalam ruangan → naik ke dekat plafon, bukan menempel kepala
      var back = Math.max(3.2, h * free);
      want.set(p.x + s * back, p.y + 8.3, p.z + c * back);
    }
    var ib = this.insideBuilding && this.insideBuilding.bounds;
    if (ib) { // tetap di dalam ruangan
      want.x = Math.max(ib.x - ib.width / 2 + 1.4, Math.min(ib.x + ib.width / 2 - 1.4, want.x));
      want.z = Math.max(ib.z - ib.depth / 2 + 1.4, Math.min(ib.z + ib.depth / 2 - 1.4, want.z));
    }
    var floorY = (this.groundHeightAt ? this.groundHeightAt(want.x, want.z, p.y + 2) : 0) + 0.8;
    if (want.y < floorY) want.y = floorY;
    // titik pandang: sedikit di depan karakter setinggi mata (bukan ke kaki)
    var look = selfie
      ? new THREE.Vector3(p.x, headY - 0.25, p.z)
      : new THREE.Vector3(pivot.x - s * 3.2, headY - 0.35 - Math.sin(pitch) * 1.5, pivot.z - c * 3.2);
    var k1 = 1 - Math.exp(-dt * (free < 1 ? 18 : 9)), k2 = 1 - Math.exp(-dt * 12);
    this.camera.position.lerp(want, k1);
    this.__look.lerp(look, k2);
    if (this.__look.lengthSq() === 0) this.__look.copy(look);
    this.camera.lookAt(this.__look);
  };

  /* ---------------- POV: mata sendiri + tangan ---------------- */
  /** Tangan POV (menempel di kamera): lengan baju + tangan, warna dari karakter pemain. */
  P.buildViewModel = function () {
    var ud = this.player && this.player.userData, cfg = (ud && ud.config) || {};
    var key = JSON.stringify([cfg.outfit, cfg.top, cfg.skin, cfg.acc]);
    if (this.__vm && this.__vmKey === key) return this.__vm;
    if (this.__vm) { this.camera.remove(this.__vm.root); }
    if (!this.camera.parent) this.scene.add(this.camera);   // anak kamera ikut dirender
    var of = cfg.outfit, top = cfg.top || '#1d4ed8';
    if (of === 'tjkt') top = '#f5f5f5'; else if (of === 'putih_abu') top = '#f5f5f4'; else if (of === 'pramuka') top = '#b08d57';
    else if (of === 'almamater') top = '#1e3a8a'; else if (of === 'jas_lab' || of === 'koki') top = '#f8fafc'; else if (of === 'kerja') top = '#d6c7a1';
    var longSleeve = ['jas_lab', 'almamater', 'hoodie', 'batik', 'koki', 'pramuka'].indexOf(of) >= 0;
    var m = function (hex) { var c = new THREE.Color(hex).convertSRGBToLinear(); var mm = new THREE.MeshLambertMaterial({ color: c }); mm.depthTest = true; return mm; };
    var sleeveM = m(top), skinM = m(cfg.skin || '#e2ad84'), cuffM = m(of === 'tjkt' ? '#2f8f4e' : top);
    var root = new THREE.Group();
    var mk = function (side) {
      var arm = new THREE.Group();
      var fore = new THREE.Mesh(new THREE.CylinderGeometry(0.075, 0.09, 0.55, 12), longSleeve ? sleeveM : skinM);
      fore.rotation.x = Math.PI / 2; fore.position.z = 0.18;
      var sleeve = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.11, 0.3, 12), sleeveM);
      sleeve.rotation.x = Math.PI / 2; sleeve.position.z = 0.5;
      var cuff = new THREE.Mesh(new THREE.CylinderGeometry(0.104, 0.104, 0.05, 12), cuffM);
      cuff.rotation.x = Math.PI / 2; cuff.position.z = 0.36;
      var hand = new THREE.Mesh(new THREE.SphereGeometry(0.095, 12, 10), skinM);
      hand.scale.set(0.95, 0.75, 1.15); hand.position.z = -0.12;
      var thumb = new THREE.Mesh(new THREE.SphereGeometry(0.04, 8, 6), skinM);
      thumb.position.set(-side * 0.07, 0.02, -0.08);
      arm.add(fore, sleeve, cuff, hand, thumb);
      arm.userData.base = new THREE.Vector3(side * 0.36, -0.42, -0.78);
      arm.scale.setScalar(0.78);
      arm.position.copy(arm.userData.base);
      arm.rotation.set(-0.12, side * 0.18, 0);
      arm.traverse(function (o) { if (o.isMesh) { o.renderOrder = 10; o.frustumCulled = false; } });
      root.add(arm);
      return arm;
    };
    var L = mk(-1), R = mk(1);
    this.camera.add(root);
    this.__vm = { root: root, L: L, R: R };
    this.__vmKey = key;
    return this.__vm;
  };
  P.setViewModel = function (on) {
    if (this.__vm) this.__vm.root.visible = !!on;
    if (this.player && !on && this.player.visible === false) this.player.visible = true;
  };
  P.updatePOV = function (dt) {
    var p = this.playerPos, vm = this.buildViewModel();
    vm.root.visible = true;
    this.player.visible = false;                 // badan sendiri tak terlihat (pemain lain tetap melihatnya)
    var fov = this.insideBuilding ? 74 : 70;
    if (Math.abs(this.camera.fov - fov) > 0.05) { this.camera.fov += (fov - this.camera.fov) * (1 - Math.exp(-dt * 6)); this.camera.updateProjectionMatrix(); }
    if (!this.isMoving && !this.isSitting) { this.headingAngle = this.camYaw + Math.PI; this.player.rotation.y = this.headingAngle; } // arah hadap ikut pandangan
    // head-bob & ayunan tangan mengikuti langkah
    var run = this.isSprinting || (this.keys && this.keys.shift);
    this.__bobT = (this.__bobT || 0) + (this.isMoving && this.isGrounded ? dt * (run ? 13 : 9) : 0);
    var amp = this.isMoving && this.isGrounded ? (run ? 0.09 : 0.055) : 0;
    this.__bobA = (this.__bobA || 0) + (amp - (this.__bobA || 0)) * (1 - Math.exp(-dt * 8));
    var bobY = Math.abs(Math.sin(this.__bobT)) * this.__bobA * 2 - this.__bobA, bobX = Math.sin(this.__bobT) * this.__bobA * 0.6;
    var eye = (this.isSitting ? EYE - 1.3 : EYE);
    var sx = Math.sin(this.camYaw), cz = Math.cos(this.camYaw);
    this.camera.position.set(p.x + Math.cos(this.camYaw) * bobX, p.y + eye + bobY, p.z - Math.sin(this.camYaw) * bobX);
    var pc = Math.cos(this.camPitch), ps = Math.sin(this.camPitch);
    this.camera.lookAt(this.camera.position.x - sx * pc * 10, this.camera.position.y - ps * 10, this.camera.position.z - cz * pc * 10);
    // tangan: ayun saat jalan, napas saat diam, emote
    var t = performance.now() / 1000, sw = Math.sin(this.__bobT) * (this.isMoving ? (run ? 0.12 : 0.08) : 0), br = Math.sin(t * 2) * 0.008;
    [vm.L, vm.R].forEach(function (a, i) {
      var b = a.userData.base, s2 = i ? -1 : 1;
      a.position.set(b.x, b.y + br + (i ? -sw : sw) * 0.4, b.z + (i ? sw : -sw));
      a.rotation.set(-0.12 + (i ? -sw : sw), (i ? 1 : -1) * 0.18, 0);
    });
    var en = this.emoteName, et = this.emoteT || 0;
    if (en) { // gerak tangan POV untuk emote
      var R = vm.R, Lh = vm.L, k = Math.min(1, et / 0.2) * Math.min(1, Math.max(0, (2.6 - et) / 0.35));
      var wave = Math.sin(et * 11);
      if (en === 'wave' || en === 'salute' || en === 'ok' || en === 'point' || en === 'think') {
        R.position.y += k * (en === 'point' ? 0.1 : 0.26); R.position.x -= k * (en === 'salute' || en === 'think' ? 0.14 : 0.02); R.position.z -= k * 0.08;
        R.rotation.x += k * (en === 'point' ? 0.3 : 1.1); R.rotation.z += k * (en === 'wave' ? wave * 0.5 : 0);
      } else if (en === 'clap' || en === 'love' || en === 'cool') {
        var cl = en === 'clap' ? Math.abs(Math.sin(et * 13)) : 1;
        R.position.x -= k * 0.22 * cl; Lh.position.x += k * 0.22 * cl; R.position.y += k * 0.12; Lh.position.y += k * 0.12;
      } else if (en === 'cheer' || en === 'hype' || en === 'shock' || en === 'dance') {
        var up = en === 'dance' ? (Math.sin(et * 8) * 0.5 + 0.5) : 1;
        R.position.y += k * 0.45 * up; Lh.position.y += k * 0.45 * (en === 'dance' ? 1 - up : 1); R.rotation.x += k * 1.2; Lh.rotation.x += k * 1.2;
      } else if (en === 'sad' || en === 'sleep' || en === 'bow') {
        R.position.y -= k * 0.2; Lh.position.y -= k * 0.2;
      } else if (en === 'angry') { R.position.y += k * 0.05 * Math.sin(et * 40); Lh.position.y += k * 0.05 * Math.cos(et * 40); }
      else if (en === 'laugh') { R.position.y += k * 0.06 * Math.sin(et * 18); Lh.position.y += k * 0.06 * Math.sin(et * 18); }
    }
  };

  /** 🔭 Atas Kepala: kamera tinggi tepat di atas-belakang kepala, memandang ke bawah, tetap bisa diputar. */
  P.updateOverhead = function (dt) {
    var p = this.playerPos, yaw = this.camYaw, indoors = !!this.insideBuilding;
    var hgt = indoors ? 8.5 : Math.max(16, Math.min(60, this.camDistance * 1.4)), back = indoors ? 2.5 : hgt * 0.28;
    var want = new THREE.Vector3(p.x + Math.sin(yaw) * back, p.y + hgt, p.z + Math.cos(yaw) * back);
    var ib = this.insideBuilding && this.insideBuilding.bounds;
    if (ib) { want.x = Math.max(ib.x - ib.width / 2 + 1.4, Math.min(ib.x + ib.width / 2 - 1.4, want.x)); want.z = Math.max(ib.z - ib.depth / 2 + 1.4, Math.min(ib.z + ib.depth / 2 - 1.4, want.z)); }
    var fov = indoors ? 72 : 60;
    if (Math.abs(this.camera.fov - fov) > 0.05) { this.camera.fov += (fov - this.camera.fov) * (1 - Math.exp(-dt * 6)); this.camera.updateProjectionMatrix(); }
    this.camera.position.lerp(want, 1 - Math.exp(-dt * 8));
    var look = new THREE.Vector3(p.x - Math.sin(yaw) * 2, p.y + 1.5, p.z - Math.cos(yaw) * 2);
    this.__look.lerp(look, 1 - Math.exp(-dt * 10));
    this.camera.lookAt(this.__look);
  };

  /* ---------------- Intro sinematik ---------------- */
  P.startIntro = function () {
    if (this.__introDone) return;
    this.__introDone = true;
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || this.effectiveQuality === 'low') return;
    var L = this.layout;
    var cx = L ? (L.X0 + L.X1) / 2 : 0, cz = L ? (L.Z0 + L.Z1) / 2 : 0;
    this.introActive = true;
    this.__intro = { t0: performance.now(), dur: 5200, cx: cx, cz: cz, r: L ? Math.max(L.X1 - L.X0, L.Z1 - L.Z0) * 0.62 : 260 };
    var self = this;
    var skip = function () { if (self.introActive) self.endIntro(); };
    this.__introSkip = skip;
    ['keydown', 'pointerdown', 'touchstart', 'wheel'].forEach(function (ev) { window.addEventListener(ev, skip, { once: true, passive: true }); });
    var cap = document.createElement('div');
    cap.id = 'introCaption';
    cap.innerHTML = '<b>SMK SILBER</b><span>Virtual City · ketuk untuk mulai</span>';
    cap.style.cssText = 'position:fixed;left:50%;bottom:18%;transform:translateX(-50%);z-index:1500;text-align:center;color:#fff6e0;text-shadow:0 2px 8px rgba(0,0,0,.6);pointer-events:none;font-family:inherit;transition:opacity .6s';
    cap.querySelector('b').style.cssText = 'display:block;font-size:clamp(28px,6vw,54px);letter-spacing:6px';
    cap.querySelector('span').style.cssText = 'font-size:clamp(13px,2vw,17px);opacity:.85';
    document.body.appendChild(cap);
  };
  P.updateIntro = function (now) {
    var I = this.__intro, t = Math.min(1, (now - I.t0) / I.dur), p = this.playerPos;
    if (t < 0.62) { // terbang mengitari sekolah, pelan menurun
      var u = ease(t / 0.62), ang = 0.6 + u * 1.9;
      this.camera.position.set(I.cx + Math.cos(ang) * I.r * (1 - u * 0.35), 170 - u * 90, I.cz + Math.sin(ang) * I.r * (1 - u * 0.35));
      this.camera.lookAt(I.cx + (p.x - I.cx) * u, 0, I.cz + (p.z - I.cz) * u);
      this.__look.set(p.x, p.y + HEAD, p.z);
    } else { // turun ke belakang bahu
      if (!I.fromPos) { I.fromPos = this.camera.position.clone(); }
      var v = ease((t - 0.62) / 0.38), yaw = this.camYaw, d = 12.5, sh = SHOULDER;
      var to = this.cameraMode === 'first_person'
        ? new THREE.Vector3(p.x, p.y + EYE, p.z)                         // masuk ke mata karakter
        : new THREE.Vector3(p.x + Math.cos(yaw) * sh + Math.sin(yaw) * d * Math.cos(0.14), p.y + HEAD + d * Math.sin(0.14) + 0.35, p.z - Math.sin(yaw) * sh + Math.cos(yaw) * d * Math.cos(0.14));
      if (this.insideBuilding && this.cameraMode !== 'first_person') to.y = Math.max(to.y, p.y + 6);
      this.camera.position.copy(I.fromPos).lerp(to, v);
      if (this.cameraMode === 'first_person') this.camera.lookAt(p.x - Math.sin(yaw) * 40 * v + (1 - v) * 0, p.y + HEAD - 0.3 * (1 - v), p.z - Math.cos(yaw) * 40 * v);
      else this.camera.lookAt(p.x, p.y + HEAD - 0.3 * v, p.z);
      this.__look.set(p.x, p.y + HEAD - 0.3, p.z);
      var cap = document.getElementById('introCaption'); if (cap) cap.style.opacity = String(1 - v);
    }
    if (t >= 1) this.endIntro();
  };
  P.endIntro = function () {
    this.introActive = false;
    var cap = document.getElementById('introCaption'); if (cap) { cap.style.opacity = '0'; setTimeout(function () { cap.remove(); }, 650); }
  };
  // Intro dimulai saat lobi ditutup pertama kali (render 3D berjalan).
  var origAnimate = P.animate;
  P.animate = function () {
    if (!this.__introDone && window.__vcLobbyOpen !== true && this.player && this.layout) this.startIntro();
    return origAnimate.call(this);
  };

  /* ---------------- Mode kamera ---------------- */
  P.cycleCamera = function () {
    var i = MODES.indexOf(this.cameraMode);
    this.cameraMode = MODES[(i + 1) % MODES.length];
    if (this.cameraMode === 'third_person') { this.camDistance = 22; this.camPitch = 0.32; } // Normal ≈ kamera lama
    if (this.cameraMode === 'first_person') this.camPitch = 0.05;
    try { if (window.UI) UI.toast(LABEL[this.cameraMode], 'info', 1400); } catch (e) {}
    var b = document.getElementById('camModeBtn'); if (b) b.title = LABEL[this.cameraMode] + ' (C)';
    try { localStorage.setItem('vc_cam_mode', this.cameraMode); } catch (e) {}
    if (this.cameraMode !== 'first_person') this.setViewModel(false);
    return this.cameraMode;
  };
  // Tombol 🎥 di samping zoom (HP & laptop).
  function addCamButton() {
    var z = document.getElementById('zoomCtl');
    if (!z || document.getElementById('camModeBtn')) return;
    var b = document.createElement('button');
    b.type = 'button'; b.id = 'camModeBtn'; b.textContent = '🎥'; b.title = 'Ganti kamera: POV mata · Bahu · Wajah · Atas (C)';
    b.setAttribute('aria-label', 'Ganti mode kamera');
    b.addEventListener('click', function () { if (window.world && world.cycleCamera) world.cycleCamera(); else if (typeof world !== 'undefined' && world) world.cycleCamera(); });
    z.appendChild(b);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addCamButton); else addCamButton();
})();
