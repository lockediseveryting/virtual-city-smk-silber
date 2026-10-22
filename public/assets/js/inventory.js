/*
 * Inventaris Dunia 3D & Pixel (semua akun sekolah). Data: GET/POST /api/inventory (Domain/Inventory.php).
 *  Laptop: hotbar bawah (tombol 1–6) + I = tas. HP: tombol 🔦 & 🎒 di kiri atas.
 *  🔦 Senter  — 3D: SpotLight + kerucut cahaya bervolume dari mata/dada ke arah pandang; Pixel: malam hari
 *               layar lebih gelap & senter membuka sorot cahaya ke arah hadap (lingkaran kecil saat mati).
 *  📕 Buku    — buku saku sekolah (baca) + 5 halaman catatan (tulis, tersimpan otomatis).
 *  ✏️ Pensil  — buku gambar (tersimpan).   🗺️ Peta — buka peta.   💧 Botol — lari ×1.25 selama 20 dtk.
 *  🪪 Kartu   — kartu pelajar (nama, kelas, level, foto).
 * ES2017. `world` / `pix` dideklarasikan `let` di halaman → diakses lewat typeof, bukan window.
 */
(function () {
  'use strict';
  if (window.vcInv) return;
  var data = null, flashOn = false, bottleUntil = 0, bottleCd = 0, modal = null;
  function W3() { try { return (typeof world !== 'undefined' && world && world.scene) ? world : null; } catch (e) { return null; } } // eslint-disable-line no-undef
  function PX() { try { return (typeof pix !== 'undefined' && pix && pix.player) ? pix : null; } catch (e) { return null; } } // eslint-disable-line no-undef
  function csrf() { var m = document.querySelector('meta[name="csrf"]'); return m ? m.content : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function night() { return (document.documentElement.getAttribute('data-daypart') === 'malam') || !!(W3() && W3().isNight) || (PX() && PX().daypart === 'malam'); }
  var toastEl = null, toastT = 0;
  function toast(m) { // satu pemberitahuan saja (yang baru menggantikan yang lama)
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'inv-toast'; document.body.appendChild(toastEl); }
    toastEl.textContent = m; toastEl.style.opacity = '1';
    clearTimeout(toastT); toastT = setTimeout(function () { toastEl.style.opacity = '0'; }, 2400);
  }
  function save(body) {
    body._csrf = csrf();
    return fetch('/api/inventory', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).catch(function () { return { status: 'error' }; });
  }

  var CSS = [
    '.inv-bar{position:fixed;left:50%;transform:translateX(-50%);bottom:calc(64px + env(safe-area-inset-bottom));z-index:118;display:flex;gap:6px;padding:6px;border-radius:12px;background:rgba(32,20,12,.82);border:2px solid #d9a441;box-shadow:0 6px 18px rgba(0,0,0,.4)}',
    '.inv-slot{position:relative;width:46px;height:46px;border-radius:9px;border:2px solid rgba(217,164,65,.45);background:rgba(0,0,0,.35);font-size:23px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff}',
    '.inv-slot:hover,.inv-slot:focus-visible{border-color:#fde68a;outline:none;transform:translateY(-2px)}',
    '.inv-slot.is-on{border-color:#fde047;box-shadow:0 0 0 3px rgba(253,224,71,.35),0 0 14px rgba(253,224,71,.6);background:rgba(120,90,10,.45)}',
    '.inv-slot kbd{position:absolute;left:3px;top:1px;font:700 9px monospace;color:#fde68a;opacity:.8}',
    '.inv-slot.is-cd::after{content:"";position:absolute;top:0;right:0;bottom:0;left:0;border-radius:7px;background:rgba(0,0,0,.55)}',
    '.inv-mini{position:fixed;z-index:118;left:max(10px,env(safe-area-inset-left));top:calc(env(safe-area-inset-top) + var(--inv-top,172px));display:none;gap:6px}',
    '.inv-mini button{width:44px;height:44px;border-radius:50%;border:2px solid #d9a441;background:rgba(32,20,12,.86);font-size:20px;color:#fff}',
    '.inv-mini button.is-on{background:#a16207;box-shadow:0 0 14px rgba(253,224,71,.8)}',
    '@media (pointer:coarse),(max-width:760px){.inv-bar{display:none}.inv-mini{display:flex}}',
    'body.vc-ui-hidden .inv-bar,body.vc-ui-hidden .inv-mini{opacity:.35}',
    '.inv-toast{position:fixed;left:50%;top:22%;transform:translateX(-50%);z-index:2147482800;background:rgba(32,20,12,.92);color:#fde68a;border:2px solid #d9a441;border-radius:10px;padding:8px 14px;font:700 14px inherit;transition:opacity .4s;pointer-events:none;max-width:calc(100vw - 32px);text-align:center}',
    '.inv-m{position:fixed;top:0;right:0;bottom:0;left:0;z-index:2147482700;display:flex;align-items:center;justify-content:center;padding:12px;background:rgba(8,6,16,.72)}',
    '.inv-m__box{width:min(560px,100%);max-height:calc(100dvh - 24px);overflow:auto;border-radius:14px;padding:14px;background:linear-gradient(180deg,#3b2414,#22140b);border:3px solid #d9a441;color:#fef3c7;font-family:inherit}',
    '.inv-m__head{display:flex;align-items:center;gap:8px;margin-bottom:8px}.inv-m__head h2{flex:1;margin:0;font-size:1.15rem;color:#fde68a}',
    '.inv-m__x{width:38px;height:38px;border-radius:8px;border:1px solid #d9a44188;background:#0005;color:#fde68a;cursor:pointer}',
    '.inv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}',
    '.inv-card{display:flex;gap:8px;align-items:flex-start;text-align:left;padding:10px;border-radius:10px;border:2px solid rgba(217,164,65,.4);background:rgba(0,0,0,.3);color:inherit;cursor:pointer;font:inherit}',
    '.inv-card span{font-size:26px}.inv-card b{display:block}.inv-card small{opacity:.8;font-size:12px}',
    '.inv-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}.inv-tabs button{min-height:36px;padding:0 10px;border-radius:8px;border:2px solid rgba(217,164,65,.4);background:#0004;color:#fde68a;cursor:pointer;font:inherit}.inv-tabs button.is-on{background:#a16207;color:#fff}',
    '.inv-page{width:100%;min-height:240px;box-sizing:border-box;padding:12px;border-radius:8px;border:0;font:16px/1.6 "Pixelify Sans",ui-monospace,monospace;color:#2b1d10;background:repeating-linear-gradient(180deg,#fdf6e3 0 25px,#d9c9a3 25px 26px);resize:vertical}',
    '.inv-read{padding:12px;border-radius:8px;background:#fdf6e3;color:#2b1d10;line-height:1.6;font-size:15px}.inv-read h3{margin:0 0 6px}',
    '.inv-sk{display:block;width:100%;max-width:520px;aspect-ratio:8/5;background:#fdf6e3;border-radius:8px;touch-action:none;cursor:crosshair}',
    '.inv-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;align-items:center}.inv-row button{min-height:38px;padding:0 12px;border-radius:8px;border:2px solid #78350f;background:linear-gradient(180deg,#fde68a,#f59e0b);color:#1c1204;font-weight:800;cursor:pointer}',
    '.inv-sw{width:30px;height:30px;border-radius:50%;border:2px solid #1a0e06;cursor:pointer}.inv-sw.is-on{outline:3px solid #fde047}',
    '.inv-id{display:flex;gap:12px;align-items:center;padding:14px;border-radius:12px;background:linear-gradient(135deg,#1e3a8a,#0e7490);color:#fff;border:3px solid #fde68a}',
    '.inv-id__ph{width:84px;height:84px;border-radius:10px;background:#fde68a center/cover;display:flex;align-items:center;justify-content:center;font-size:36px;color:#1e3a8a;flex:0 0 auto}',
    '.inv-id b{font-size:18px;display:block}.inv-id small{display:block;opacity:.9}',
    '.inv-msg{font-size:12px;opacity:.8;min-height:1.2em}'
  ].join('\n');

  // ---------- Senter 3D ----------
  var F3 = null;
  function flash3d(w) {
    if (F3 && F3.w === w) return F3;
    var light = new THREE.SpotLight(0xfff1c4, 0, 85, 0.42, 0.45, 1.2);
    light.castShadow = false;
    var tgt = new THREE.Object3D();
    w.scene.add(light); w.scene.add(tgt); light.target = tgt;
    // kerucut cahaya bervolume (aditif, lembut) agar sorotnya terlihat jelas
    var geo = new THREE.ConeGeometry(7.5, 26, 24, 1, true);
    geo.translate(0, -13, 0); geo.rotateX(-Math.PI / 2); // ujung di asal, mengarah ke -z
    var cone = new THREE.Mesh(geo, new THREE.MeshBasicMaterial({ color: 0xfff1c4, transparent: true, opacity: 0.11, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide }));
    cone.visible = false; cone.userData.linear = true; cone.renderOrder = 5;
    w.scene.add(cone);
    var glow = new THREE.Mesh(new THREE.CircleGeometry(4.5, 24), new THREE.MeshBasicMaterial({ color: 0xfff1c4, transparent: true, opacity: 0.22, depthWrite: false, blending: THREE.AdditiveBlending }));
    glow.rotation.x = -Math.PI / 2; glow.visible = false; w.scene.add(glow);
    F3 = { w: w, light: light, tgt: tgt, cone: cone, glow: glow };
    var tick = function () {
      var on = flashOn;
      light.intensity = on ? (w.isNight ? 3.2 : 1.4) : 0;
      cone.visible = on && w.isNight; glow.visible = on;
      if (!on) return;
      var p = w.playerPos, pov = w.cameraMode === 'first_person', yaw, pitch = 0, ox, oy, oz;
      if (pov) { var cp = w.camera.position; ox = cp.x; oy = cp.y - 0.4; oz = cp.z; yaw = w.camYaw + Math.PI; pitch = -w.camPitch; }
      else { yaw = w.player ? w.player.rotation.y : 0; ox = p.x + Math.sin(yaw) * 0.8; oy = p.y + 2.6; oz = p.z + Math.cos(yaw) * 0.8; }
      var dx = Math.sin(yaw) * Math.cos(pitch), dz = Math.cos(yaw) * Math.cos(pitch), dy = Math.sin(pitch) - (pov ? 0 : 0.18);
      light.position.set(ox, oy, oz);
      tgt.position.set(ox + dx * 20, oy + dy * 20, oz + dz * 20);
      cone.position.set(ox, oy, oz);
      cone.lookAt(ox - dx, oy - dy, oz - dz); // geometri mengarah ke -z
      var gy = w.groundHeightAt ? w.groundHeightAt(ox + dx * 14, oz + dz * 14, p.y + 2) : p.y;
      glow.position.set(ox + dx * 14, gy + 0.08, oz + dz * 14);
    };
    if (w.addTick) w.addTick(tick); else setInterval(tick, 33);
    return F3;
  }

  // ---------- Senter & gelap malam Pixel ----------
  function hookPixel() {
    var PW = window.PixelWorld;
    if (!PW || PW.prototype.__invHooked) return;
    PW.prototype.__invHooked = true;
    var orig = PW.prototype.drawOverlay, mask = null;
    PW.prototype.drawOverlay = function (ctx, camX, G) {
      try {
        var sc = this.scene;
        if (this.daypart === 'malam' && sc && sc.kind !== 'room') {
          var cw = ctx.canvas.width, ch = ctx.canvas.height, S = this.view.s, p = this.player;
          if (!mask) mask = document.createElement('canvas');
          if (mask.width !== cw || mask.height !== ch) { mask.width = cw; mask.height = ch; }
          var m = mask.getContext('2d');
          m.globalCompositeOperation = 'source-over';
          m.clearRect(0, 0, cw, ch);
          m.fillStyle = 'rgba(4,6,20,0.64)'; m.fillRect(0, 0, cw, ch);
          m.globalCompositeOperation = 'destination-out';
          var px = (p.x - camX) * S, py = (G - 22) * S, f = p.facing || 1;
          var r0 = 46 * S, g0 = m.createRadialGradient(px, py, 0, px, py, r0);
          g0.addColorStop(0, 'rgba(0,0,0,0.9)'); g0.addColorStop(1, 'rgba(0,0,0,0)');
          m.fillStyle = g0; m.beginPath(); m.arc(px, py, r0, 0, 6.283); m.fill();
          if (flashOn) { // sorot ke arah hadap: kerucut + bundaran terang di ujung
            var L = 230 * S, ex = px + f * L, sp = 95 * S;
            var gr = m.createLinearGradient(px, py, ex, py);
            gr.addColorStop(0, 'rgba(0,0,0,1)'); gr.addColorStop(0.75, 'rgba(0,0,0,0.85)'); gr.addColorStop(1, 'rgba(0,0,0,0)');
            m.fillStyle = gr; m.beginPath(); m.moveTo(px, py - 6 * S); m.lineTo(ex, py - sp); m.lineTo(ex, py + sp * 0.9); m.lineTo(px, py + 6 * S); m.closePath(); m.fill();
            var g1 = m.createRadialGradient(px + f * L * 0.7, py, 0, px + f * L * 0.7, py, 80 * S);
            g1.addColorStop(0, 'rgba(0,0,0,1)'); g1.addColorStop(1, 'rgba(0,0,0,0)');
            m.fillStyle = g1; m.beginPath(); m.arc(px + f * L * 0.7, py, 80 * S, 0, 6.283); m.fill();
          }
          ctx.drawImage(mask, 0, 0);
          if (flashOn) { // kilau hangat di dalam sorot
            ctx.save(); ctx.globalCompositeOperation = 'lighter'; ctx.globalAlpha = 0.16;
            var g2 = ctx.createRadialGradient(px + f * 150 * S, py, 0, px + f * 150 * S, py, 110 * S);
            g2.addColorStop(0, '#fff1c4'); g2.addColorStop(1, 'rgba(255,241,196,0)');
            ctx.fillStyle = g2; ctx.fillRect(0, 0, cw, ch); ctx.restore();
          }
        }
      } catch (e) { /* senter tidak boleh mengganggu game */ }
      return orig.call(this, ctx, camX, G);
    };
  }

  // ---------- Pakai barang ----------
  function setFlash(on) {
    flashOn = on;
    var w = W3(); if (w && window.THREE) flash3d(w);
    hookPixel();
    paintBar();
    toast(on ? (night() ? '🔦 Senter menyala' : '🔦 Senter menyala — lebih terasa saat malam') : '🔦 Senter dimatikan');
    try { if (on && window.vcChime) { /* klik ringan */ } } catch (e) {}
  }
  function use(id) {
    if (id === 'senter') return setFlash(!flashOn);
    if (id === 'buku') return openBook();
    if (id === 'pensil') return openSketch();
    if (id === 'peta') {
      if (W3() && typeof window.toggleMapModal === 'function') window.toggleMapModal();
      else if (PX() && PX().openMap) PX().openMap();
      return;
    }
    if (id === 'botol') {
      var now = Date.now();
      if (now < bottleCd) { toast('💧 Botol masih kosong — isi ulang ' + Math.ceil((bottleCd - now) / 1000) + ' dtk lagi'); return; }
      bottleUntil = now + 20000; bottleCd = now + 60000;
      var w = W3(), P = PX();
      if (w) w.speedMul = 1.25; if (P) P.player.speedMul = 1.25;
      if (w && w.playEmote) w.playEmote('hype');
      toast('💧 Glek glek… Segar! Lari lebih cepat 20 detik');
      setTimeout(function () { var w2 = W3(), P2 = PX(); if (w2) w2.speedMul = 1; if (P2) P2.player.speedMul = 1; paintBar(); }, 20000);
      paintBar();
      return;
    }
    if (id === 'kartu') return openCard();
  }

  // ---------- Modal ----------
  function openModal(title, html) {
    closeModal();
    modal = document.createElement('div');
    modal.className = 'inv-m';
    modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true');
    modal.innerHTML = '<div class="inv-m__box"><div class="inv-m__head"><h2>' + title + '</h2><button type="button" class="inv-m__x" data-x aria-label="Tutup">✕</button></div>' + html + '</div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (e) { if (e.target === modal || (e.target.closest && e.target.closest('[data-x]'))) closeModal(); });
    var P = PX(); if (P) P.paused = true;
    return modal;
  }
  function closeModal() {
    if (modal) { modal.remove(); modal = null; var P = PX(); if (P) P.paused = false; }
  }
  function openBag() {
    var h = '<div class="inv-grid">' + data.items.map(function (it, i) {
      return '<button type="button" class="inv-card" data-use="' + it.id + '"><span>' + it.icon + '</span><div><b>' + (i + 1) + '. ' + esc(it.name) + '</b><small>' + esc(it.desc) + '</small></div></button>';
    }).join('') + '</div><p class="inv-msg">Laptop: tombol 1–6 memakai barang, I membuka tas.</p>';
    var m = openModal('🎒 Tas Inventaris', h);
    m.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-use]'); if (b) { var id = b.getAttribute('data-use'); if (id !== 'buku' && id !== 'pensil' && id !== 'kartu') closeModal(); use(id); } });
  }
  var BOOK = '<div class="inv-read"><h3>📘 Buku Saku SMK Silber</h3><p><b>Jam sekolah:</b> 07.00–15.30 WIB. Datang sebelum bel, ikuti upacara Senin.</p>'
    + '<p><b>Gedung:</b> A = kantor & ruang organisasi · B = kelas & Lab TIK · C = kelas & perpustakaan · D = hall & musholah.</p>'
    + '<p><b>Tips:</b> pakai 🧭 Navigasi untuk mencari ruangan, 📅 Misi Harian untuk koin & XP, dan 🔦 senter saat malam — kabarnya ada yang suka muncul di koridor kosong…</p>'
    + '<p><b>Adab digital:</b> sopan di chat, jaga data pribadi, laporkan bug lewat 🐞 Lapor.</p></div>';
  function openBook() {
    var tab = 0, m;
    function render() {
      var h = '<div class="inv-tabs"><button type="button" data-t="0"' + (tab === 0 ? ' class="is-on"' : '') + '>📘 Buku Saku</button>';
      for (var i = 1; i <= data.notes.length; i++) h += '<button type="button" data-t="' + i + '"' + (tab === i ? ' class="is-on"' : '') + '>📝 Hal. ' + i + '</button>';
      h += '</div>' + (tab === 0 ? BOOK : '<textarea class="inv-page" maxlength="2000" placeholder="Tulis catatan pelajaran, rencana, atau cerita…">' + esc(data.notes[tab - 1]) + '</textarea><p class="inv-msg" data-msg>Tersimpan otomatis.</p>');
      m.querySelector('.inv-body').innerHTML = h;
      var ta = m.querySelector('.inv-page');
      if (ta) {
        var t = null;
        ta.addEventListener('keydown', function (e) { e.stopPropagation(); });
        ta.addEventListener('input', function () {
          data.notes[tab - 1] = ta.value;
          var msg = m.querySelector('[data-msg]'); if (msg) msg.textContent = 'Menyimpan…';
          clearTimeout(t); var page = tab;
          t = setTimeout(function () { save({ action: 'note', page: page, text: ta.value }).then(function (d) { if (msg) msg.textContent = d.status === 'ok' ? '✓ Tersimpan' : 'Gagal menyimpan'; }); }, 700);
        });
        setTimeout(function () { ta.focus(); }, 50);
      }
    }
    m = openModal('📕 Buku Catatan', '<div class="inv-body"></div>');
    m.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-t]'); if (b) { tab = +b.getAttribute('data-t'); render(); } });
    render();
  }
  function openSketch() {
    var m = openModal('✏️ Buku Gambar', '<canvas class="inv-sk" width="480" height="300"></canvas><div class="inv-row" data-pal></div><div class="inv-row"><button type="button" data-sk="clear">🧽 Hapus</button><button type="button" data-sk="save">💾 Simpan</button><span class="inv-msg" data-msg></span></div>');
    var cv = m.querySelector('canvas'), g = cv.getContext('2d'), col = '#2b1d10', down = false, last = null;
    g.fillStyle = '#fdf6e3'; g.fillRect(0, 0, cv.width, cv.height);
    if (data.sketch) { var im = new Image(); im.onload = function () { g.drawImage(im, 0, 0); }; im.src = data.sketch; }
    var PAL = ['#2b1d10', '#c0392b', '#1d4ed8', '#2f8f4e', '#f59e0b', '#7c3aed', '#fdf6e3'];
    m.querySelector('[data-pal]').innerHTML = PAL.map(function (c, i) { return '<button type="button" class="inv-sw' + (i ? '' : ' is-on') + '" data-c="' + c + '" style="background:' + c + '" aria-label="Warna ' + (i + 1) + (i === 6 ? ' (penghapus)' : '') + '"></button>'; }).join('');
    function pos(e) { var r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left) * cv.width / r.width, y: (e.clientY - r.top) * cv.height / r.height }; }
    cv.addEventListener('pointerdown', function (e) { down = true; last = pos(e); cv.setPointerCapture(e.pointerId); });
    cv.addEventListener('pointermove', function (e) {
      if (!down) return; var p = pos(e);
      g.strokeStyle = col; g.lineWidth = col === '#fdf6e3' ? 14 : 3; g.lineCap = 'round';
      g.beginPath(); g.moveTo(last.x, last.y); g.lineTo(p.x, p.y); g.stroke(); last = p;
    });
    cv.addEventListener('pointerup', function () { down = false; });
    m.addEventListener('click', function (e) {
      var c = e.target.closest && e.target.closest('[data-c]');
      if (c) { col = c.getAttribute('data-c'); m.querySelectorAll('.inv-sw').forEach(function (x) { x.classList.toggle('is-on', x === c); }); return; }
      var a = e.target.closest && e.target.closest('[data-sk]'); if (!a) return;
      var msg = m.querySelector('[data-msg]');
      if (a.getAttribute('data-sk') === 'clear') { g.fillStyle = '#fdf6e3'; g.fillRect(0, 0, cv.width, cv.height); return; }
      var url = cv.toDataURL('image/png');
      msg.textContent = 'Menyimpan…';
      save({ action: 'sketch', data: url }).then(function (d) { if (d.status === 'ok') data.sketch = url; msg.textContent = d.status === 'ok' ? '✓ Tersimpan' : (d.message || 'Gagal'); });
    });
  }
  function openCard() {
    var c = data.card, ROLE = { murid: 'Siswa', guru: 'Guru', wali_kelas: 'Wali Kelas', root: 'Admin', kepala_sekolah: 'Kepala Sekolah', kepala_program: 'Kepala Program' };
    var ph = c.photo ? '<div class="inv-id__ph" style="background-image:url(\'' + esc(c.photo) + '\')"></div>' : '<div class="inv-id__ph">' + esc((c.name || '?').charAt(0)) + '</div>';
    openModal('🪪 Kartu Pelajar', '<div class="inv-id">' + ph + '<div><small>SMK SILBER · VIRTUAL CITY</small><b>' + esc(c.name) + '</b><small>' + esc(ROLE[c.role] || c.role) + (c.class ? ' · ' + esc(c.class) : '') + '</small>'
      + (c.nis ? '<small>NIS ' + esc(c.nis) + '</small>' : '') + '<small>⭐ Level ' + c.level + ' · @' + esc(c.username) + '</small></div></div>');
    var w = W3(); if (w && w.playEmote) w.playEmote('ok');
  }

  // ---------- UI ----------
  var bar = null, mini = null;
  function paintBar() {
    if (bar) bar.querySelectorAll('[data-use]').forEach(function (b) {
      var id = b.getAttribute('data-use');
      b.classList.toggle('is-on', (id === 'senter' && flashOn) || (id === 'botol' && Date.now() < bottleUntil));
      b.classList.toggle('is-cd', id === 'botol' && Date.now() > bottleUntil && Date.now() < bottleCd);
    });
    if (mini) { var f = mini.querySelector('[data-use="senter"]'); if (f) f.classList.toggle('is-on', flashOn); }
  }
  function build() {
    var s = document.createElement('style'); s.textContent = CSS; document.head.appendChild(s);
    bar = document.createElement('div'); bar.className = 'inv-bar'; bar.setAttribute('aria-label', 'Inventaris');
    bar.innerHTML = data.items.map(function (it, i) { return '<button type="button" class="inv-slot" data-use="' + it.id + '" title="' + esc(it.name) + ' (' + (i + 1) + ')" aria-label="' + esc(it.name) + '"><kbd>' + (i + 1) + '</kbd>' + it.icon + '</button>'; }).join('')
      + '<button type="button" class="inv-slot" data-bag title="Tas (I)" aria-label="Buka tas">🎒</button>';
    document.body.appendChild(bar);
    mini = document.createElement('div'); mini.className = 'inv-mini';
    mini.style.setProperty('--inv-top', location.pathname.indexOf('/pixel') === 0 ? '140px' : '172px');
    mini.innerHTML = '<button type="button" data-use="senter" aria-label="Senter">🔦</button><button type="button" data-bag aria-label="Tas inventaris">🎒</button>';
    document.body.appendChild(mini);
    [bar, mini].forEach(function (el) {
      el.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-use],[data-bag]') : null; if (!b) return;
        if (b.hasAttribute('data-bag')) openBag(); else use(b.getAttribute('data-use'));
      });
    });
    document.addEventListener('keydown', function (e) {
      if (/INPUT|TEXTAREA|SELECT/.test((e.target && e.target.tagName) || '') || e.ctrlKey || e.metaKey || e.altKey) return;
      if (e.key === 'Escape' && modal) { closeModal(); return; }
      if (modal) return;
      var n = parseInt(e.key, 10);
      if (n >= 1 && n <= data.items.length) { use(data.items[n - 1].id); e.preventDefault(); }
      else if (e.key === 'i' || e.key === 'I') openBag();
    });
    setInterval(paintBar, 1000);
    hookPixel();
    // malam pertama: beri tahu ada senter
    if (night()) setTimeout(function () { toast('🌙 Sudah malam — tekan 1 / 🔦 untuk menyalakan senter'); }, 4000);
  }
  window.vcInv = { use: use, open: function () { if (data) openBag(); }, flash: function () { return flashOn; } };
  function start() {
    fetch('/api/inventory', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (!d || d.status !== 'ok') return; // tamu / belum login: tanpa inventaris
      data = d; build();
    }).catch(function () {});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(start, 1200); }); else setTimeout(start, 1200);
})();
