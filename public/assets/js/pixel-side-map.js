/**
 * Pixel World side-view — PETA NAVIGASI, MINIMAP & KONTROL SENTUH.
 * Peta memakai denah resmi (koordinat x/z campus.json) untuk 38 lokasi ruang.txt:
 * pilih lokasi → "Tunjukkan arah" (panduan pintu/tangga langkah demi langkah) atau "Antar ke sana".
 */
(function () {
  'use strict';
  const PW = window.PixelWorld, X = window.__PXS;
  if (!PW || !X) return;
  const CAT = X.CAT, CAT_LABEL = X.CAT_LABEL;
  const BX = [-210, 205], BZ = [-118, 132];

  const CSS = ''
    + '#pxsPad{position:fixed;left:0;right:0;bottom:0;height:0;z-index:61;pointer-events:none}'
    + '#pxsPad.pxs-hide{display:none}'
    + '#pxsPad button{pointer-events:auto;position:fixed;width:62px;height:62px;font:700 24px "Pixelify Sans",monospace;color:#fff6e0;border:0;'
    + 'background:repeating-linear-gradient(180deg,rgba(255,255,255,.07) 0 3px,rgba(0,0,0,.07) 3px 6px),#8a5a2b;opacity:.88;touch-action:none;-webkit-user-select:none;user-select:none;'
    + 'box-shadow:inset 0 3px 0 #c9925a,inset 0 -4px 0 #4a2a12,0 -3px 0 #20130a,0 3px 0 #20130a,-3px 0 0 #20130a,3px 0 0 #20130a}'
    + '#pxsPad button.on{transform:translateY(3px);background:#c27a3c}'
    + '#pxsPad [data-k=left]{left:max(14px,env(safe-area-inset-left));bottom:22px}'
    + '#pxsPad [data-k=right]{left:calc(max(14px,env(safe-area-inset-left)) + 76px);bottom:22px}'
    + '#pxsPad [data-k=up]{right:calc(max(14px,env(safe-area-inset-right)) + 154px);bottom:86px;width:54px;height:50px;font-size:20px}'
    + '#pxsPad [data-k=down]{right:calc(max(14px,env(safe-area-inset-right)) + 154px);bottom:22px;width:54px;height:50px;font-size:20px}'
    + '#pixAction.pxs-ready{background:#c27a3c!important;color:#fff6e0!important;animation:pxsPulse 1s steps(2) infinite}'
    + '@keyframes pxsPulse{50%{filter:brightness(1.25)}}'
    + '.pxs-map{position:fixed;top:0;left:0;right:0;bottom:0;z-index:95;background:rgba(10,6,14,.82);display:flex;align-items:center;justify-content:center;padding:10px}'
    + '.pxs-map[hidden]{display:none}'
    + '.pxs-map__box{width:min(1100px,100%);height:min(680px,100%);display:flex;flex-direction:column;background:#22172b;color:#fff1d0;font-family:"Pixelify Sans",monospace;'
    + 'box-shadow:inset 0 0 0 4px #6b4423,inset 0 0 0 8px #4a2a12,0 -4px 0 #20130a,0 4px 0 #20130a,-4px 0 0 #20130a,4px 0 0 #20130a}'
    + '.pxs-map__head{display:flex;align-items:center;gap:10px;padding:12px 14px 6px}'
    + '.pxs-map__head b{font-size:18px;color:#ffcf4a}.pxs-map__head small{font-size:12px;color:#c9b48f;flex:1}'
    + '.pxs-map__body{flex:1;min-height:0;display:flex;gap:10px;padding:6px 14px 14px}'
    + '.pxs-map__cv{flex:1.4;min-width:0;min-height:0;position:relative;background:#2f5a2f;box-shadow:0 0 0 3px #20130a}'
    + '.pxs-map__cv canvas{position:absolute;top:0;left:0;width:100%;height:100%;cursor:pointer}'
    + '.pxs-map__side{flex:1;min-width:0;display:flex;flex-direction:column;gap:8px;min-height:0}'
    + '.pxs-chips{display:flex;flex-wrap:wrap;gap:6px}'
    + '.pxs-chip{font:700 13px "Pixelify Sans",monospace;padding:5px 9px;border:0;cursor:pointer;color:#fff1d0;background:#3a2a4a;box-shadow:0 0 0 2px #20130a}'
    + '.pxs-chip.on{background:#c27a3c;color:#fff6e0}'
    + '.pxs-search{font:16px "Pixelify Sans",monospace;padding:7px 10px;color:#fff1d0;background:#170f1e;border:0;box-shadow:inset 2px 2px 0 #0a0610,0 0 0 3px #6b4423}'
    + '.pxs-list{flex:1;min-height:80px;overflow:auto;background:#170f1e;box-shadow:0 0 0 3px #20130a;padding:4px}'
    + '.pxs-list h4{margin:8px 6px 4px;font-size:13px;color:#ffcf4a}'
    + '.pxs-item{display:flex;align-items:center;gap:8px;width:100%;text-align:left;font:14px "Pixelify Sans",monospace;color:#fff1d0;background:none;border:0;padding:6px 8px;cursor:pointer}'
    + '.pxs-item:hover,.pxs-item.on{background:#3a2a4a}.pxs-item i{width:10px;height:10px;flex:0 0 auto;box-shadow:0 0 0 2px #20130a}.pxs-item small{margin-left:auto;color:#c9b48f}'
    + '.pxs-detail{background:#170f1e;box-shadow:0 0 0 3px #6b4423;padding:10px;font-size:14px;line-height:1.4}'
    + '.pxs-detail b{color:#ffcf4a;font-size:16px}.pxs-detail p{margin:4px 0;color:#e9d6b0}'
    + '.pxs-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}'
    + '.pxs-btn{font:700 15px "Pixelify Sans",monospace;color:#fff6e0;border:0;cursor:pointer;padding:9px 12px;min-height:42px;'
    + 'background:repeating-linear-gradient(180deg,rgba(255,255,255,.07) 0 3px,rgba(0,0,0,.07) 3px 6px),#c27a3c;box-shadow:inset 0 3px 0 #f0b878,inset 0 -4px 0 #7a4520,0 0 0 3px #20130a}'
    + '.pxs-btn--dark{background:#5b3a22;box-shadow:inset 0 3px 0 #8a5a2b,inset 0 -4px 0 #3a2414,0 0 0 3px #20130a}'
    + '.pxs-legend{display:flex;flex-wrap:wrap;gap:10px;font-size:12px;color:#c9b48f}.pxs-legend i{display:inline-block;width:10px;height:10px;margin-right:4px;vertical-align:-1px}'
    + '@media (max-width:760px),(max-height:520px){.pxs-map{padding:0}.pxs-map__box{height:100%}.pxs-map__body{flex-direction:row;padding:6px}.pxs-map__head small{display:none}.pxs-detail{font-size:13px}}'
    + '@media (orientation:portrait) and (max-width:760px){.pxs-map__body{flex-direction:column}.pxs-map__cv{flex:0 0 42%}}'
    + '#pxsRotate{position:fixed;top:0;left:0;right:0;bottom:0;z-index:99;display:flex;align-items:center;justify-content:center;padding:16px;background:#140d1d;color:#fff1d0;font-family:"Pixelify Sans",monospace;text-align:center}'
    + '#pxsRotate[hidden]{display:none}'
    + '.pxs-rot__box{max-width:360px;padding:22px 18px;background:#22172b;box-shadow:inset 0 0 0 4px #6b4423,inset 0 0 0 8px #4a2a12,0 0 0 4px #20130a}'
    + '.pxs-rot__box b{display:block;font-size:22px;color:#ffcf4a;text-shadow:3px 3px 0 #4a2a12;margin:6px 0}'
    + '.pxs-rot__box p{font-size:16px;line-height:1.4;margin:0 0 14px}.pxs-rot__box small{display:block;margin-top:12px;font-size:13px;color:#c9b48f}'
    + '.pxs-rot__phone{display:inline-block;width:30px;height:52px;border:4px solid #fff1d0;box-shadow:4px 4px 0 #4a2a12;animation:pxsRot 2.4s steps(6) infinite}'
    + '@keyframes pxsRot{0%,30%{transform:rotate(0)}60%,100%{transform:rotate(-90deg)}}';

  function injectCss() {
    if (document.getElementById('pxsCss')) return;
    const st = document.createElement('style'); st.id = 'pxsCss'; st.textContent = CSS; document.head.appendChild(st);
  }
  injectCss();
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }

  /* ======================= Wajib landscape di HP ======================= */
  // Dunia Pixel dimainkan miring: saat potret di HP, game dijeda & ditutup layar "putar HP"
  // (tidak bisa dilewati). Tombol memakai layar penuh + kunci landscape (Android).
  PW.prototype.setupLandscapeLock = function () {
    const mq = (q) => !!(window.matchMedia && window.matchMedia(q).matches);
    const shortSide = Math.min(screen.width || 0, screen.height || 0);
    if (!(mq('(pointer: coarse)') && shortSide > 0 && shortSide < 600)) return;
    const el = document.createElement('div');
    el.id = 'pxsRotate';
    el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true');
    const ios = /iP(hone|od|ad)/.test(navigator.userAgent);
    const canLock = !ios && !!(document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen);
    el.innerHTML = '<div class="pxs-rot__box"><div class="pxs-rot__phone" aria-hidden="true"></div>'
      + '<b>PUTAR HP KAMU</b><p>Dunia Pixel hanya bisa dimainkan dalam posisi <u>miring (landscape)</u>.</p>'
      + (canLock ? '<button type="button" class="pxs-btn" data-rot>⟳ PUTAR OTOMATIS</button>' : '')
      + '<small>' + (ios ? 'Matikan kunci rotasi (Pusat Kontrol), lalu miringkan iPhone.' : 'Atau matikan kunci rotasi lalu miringkan HP.') + '</small></div>';
    document.body.appendChild(el);
    const btn = el.querySelector('[data-rot]');
    if (btn) btn.addEventListener('click', () => {
      const r = document.documentElement;
      let p = null;
      try { p = r.requestFullscreen ? r.requestFullscreen({ navigationUI: 'hide' }) : r.webkitRequestFullscreen(); } catch (e) { p = null; }
      Promise.resolve(p).then(() => (screen.orientation && screen.orientation.lock) ? screen.orientation.lock('landscape') : null).catch(() => {});
    });
    const check = () => {
      const portrait = window.innerHeight > window.innerWidth;
      el.hidden = !portrait;
      this.portraitLock = portrait;
      if (portrait) { this.keys = {}; this.touch.left = this.touch.right = false; }
      this.resize();
    };
    window.addEventListener('resize', check);
    window.addEventListener('orientationchange', () => setTimeout(check, 200));
    check();
  };

  /* ======================= Minimap ======================= */
  PW.prototype.setMinimap = function (cv) {
    this.minimap = cv;
    cv.addEventListener('click', () => this.openMap());
    const wrap = cv.parentElement;
    if (wrap && wrap.lastElementChild && wrap.lastElementChild !== cv) wrap.lastElementChild.textContent = 'Klik untuk peta navigasi (M)';
    setInterval(() => this.drawMinimap(), 250);
  };

  PW.prototype.drawMinimap = function () {
    const cv = this.minimap;
    if (!cv || !this.scene || !this.data) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const W = Math.round((cv.clientWidth || 180) * dpr), H = Math.round((cv.clientHeight || 96) * dpr);
    if (cv.width !== W) { cv.width = W; cv.height = H; }
    const g = cv.getContext('2d'), sc = this.scene, p = this.player;
    g.fillStyle = '#170f1e'; g.fillRect(0, 0, W, H);
    g.font = '700 ' + Math.round(10 * dpr) + 'px "Pixelify Sans", monospace'; g.textBaseline = 'top';
    if (sc.kind === 'outdoor') {
      const k = W / this.data.world.width, base = H * 0.72;
      g.fillStyle = '#5b5f6a'; g.fillRect(0, base, W, 3 * dpr);
      this.data.world.zones.forEach((z) => {
        const loc = z.loc ? this.locById.get(z.loc) : null;
        const h = z.type === 'building' ? (z.floors > 1 ? 30 : 20) * dpr : 9 * dpr;
        g.fillStyle = z.type === 'building' ? '#c27a3c' : (loc ? CAT[loc.category] : '#7d8794');
        g.fillRect(z.x * k, base - h, Math.max(2, z.w * k - 1), h);
        if (z.type === 'building') { g.fillStyle = '#fff6e0'; g.fillText(z.name.slice(-1), (z.x + z.w / 2) * k - 3 * dpr, base - h + 2 * dpr); }
      });
      this.remotes.forEach((r) => { if ((r.area || 'OUT') === 'OUT' && r.rx != null) { g.fillStyle = '#9fe0ff'; g.fillRect(r.rx * k - 2 * dpr, base + 5 * dpr, 4 * dpr, 4 * dpr); } });
      g.fillStyle = '#ff5a5a'; g.fillRect(p.x * k - 3 * dpr, base + 4 * dpr, 6 * dpr, 6 * dpr);
      g.fillStyle = '#fff1d0'; g.fillText('Halaman · ◀ Barat   Timur ▶', 4 * dpr, 3 * dpr);
    } else {
      const key = sc.kind === 'corridor' ? sc.building : sc.loc.building;
      const b = this.data.buildings[key];
      if (!b) { g.fillStyle = '#fff1d0'; g.fillText(sc.name, 4 * dpr, 4 * dpr); return; }
      const floors = Object.keys(b.floors).sort();
      const rowH = (H - 16 * dpr) / floors.length;
      g.fillStyle = '#ffcf4a'; g.fillText(b.name, 4 * dpr, 2 * dpr);
      floors.forEach((f, i) => {
        const y = 14 * dpr + (floors.length - 1 - i) * rowH, ids = b.floors[f];
        const cw = (W - 24 * dpr) / ids.length;
        g.fillStyle = '#c9b48f'; g.fillText('L' + f, 2 * dpr, y + 2 * dpr);
        ids.forEach((id, j) => {
          const on = sc.id === id, loc = this.locById.get(id);
          g.fillStyle = on ? '#ff5a5a' : CAT[loc.category];
          g.fillRect(20 * dpr + j * cw, y + 2 * dpr, cw - 2 * dpr, rowH - 6 * dpr);
        });
        if (sc.kind === 'corridor' && String(sc.floor) === f) {
          const k2 = (W - 24 * dpr) / sc.width;
          g.fillStyle = '#ffffff'; g.fillRect(20 * dpr + p.x * k2 - 2 * dpr, y + rowH - 6 * dpr, 4 * dpr, 4 * dpr);
        }
      });
    }
  };

  /* ======================= Peta navigasi ======================= */
  PW.prototype.buildMap = function () {
    const el = document.createElement('div');
    el.className = 'pxs-map'; el.hidden = true;
    el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true'); el.setAttribute('aria-label', 'Peta navigasi sekolah');
    const cats = ['semua', 'belajar', 'layanan', 'olahraga', 'fasilitas'];
    el.innerHTML = '<div class="pxs-map__box"><div class="pxs-map__head"><b>🧭 Peta Navigasi SMK Silber</b>'
      + '<small>Denah resmi (koordinat campus.json) · ' + this.data.locations.length + ' lokasi dari ruang.txt</small>'
      + '<button type="button" class="pxs-btn pxs-btn--dark" data-close>✕ Tutup</button></div>'
      + '<div class="pxs-map__body"><div class="pxs-map__cv"><canvas aria-label="Denah sekolah, ketuk titik lokasi"></canvas></div>'
      + '<div class="pxs-map__side"><div class="pxs-chips">' + cats.map((c, i) => '<button type="button" class="pxs-chip' + (i ? '' : ' on') + '" data-cat="' + c + '">' + (c === 'semua' ? 'Semua' : CAT_LABEL[c]) + '</button>').join('') + '</div>'
      + '<input class="pxs-search" type="search" placeholder="Cari ruang / kelas / rombel…" aria-label="Cari lokasi">'
      + '<div class="pxs-list"></div><div class="pxs-detail">Pilih lokasi di peta atau daftar.</div>'
      + '<div class="pxs-legend">' + Object.keys(CAT).map((c) => '<span><i style="background:' + CAT[c] + '"></i>' + CAT_LABEL[c] + '</span>').join('') + '<span><i style="background:#ff5a5a"></i>Kamu</span></div>'
      + '</div></div></div>';
    document.body.appendChild(el);
    this.mapEl = el;
    this.mapCv = el.querySelector('canvas');
    this.mapCat = 'semua'; this.mapQuery = ''; this.mapSel = null;
    el.addEventListener('click', (e) => {
      const t = e.target;
      if (t === el || t.closest('[data-close]')) { this.closeMap(); return; }
      const chip = t.closest('.pxs-chip');
      if (chip) { this.mapCat = chip.getAttribute('data-cat'); el.querySelectorAll('.pxs-chip').forEach((c) => c.classList.toggle('on', c === chip)); this.renderMapList(); this.drawMap(); return; }
      const item = t.closest('.pxs-item');
      if (item) { this.selectMapLoc(item.getAttribute('data-id')); return; }
      const act = t.closest('[data-act]');
      if (act && this.mapSel) {
        const id = this.mapSel.id;
        this.closeMap();
        if (act.getAttribute('data-act') === 'nav') this.navigateTo(id); else { this.goTo(id); this.navTarget = null; }
      }
    });
    el.querySelector('.pxs-search').addEventListener('input', (e) => { this.mapQuery = e.target.value.toLowerCase(); this.renderMapList(); this.drawMap(); });
    this.mapCv.addEventListener('click', (e) => {
      const r = this.mapCv.getBoundingClientRect();
      const mx = (e.clientX - r.left) * (this.mapCv.width / r.width), my = (e.clientY - r.top) * (this.mapCv.height / r.height);
      let best = null, bd = 18 * (window.devicePixelRatio || 1);
      (this.mapPts || []).forEach((pt) => { const d = Math.hypot(pt.x - mx, pt.y - my); if (d < bd) { bd = d; best = pt.loc; } });
      if (best) this.selectMapLoc(best.id);
    });
    window.addEventListener('resize', () => { if (!el.hidden) this.drawMap(); });
  };

  PW.prototype.openMap = function () {
    if (!this.data) return;
    if (!this.mapEl) this.buildMap();
    this.mapEl.hidden = false;
    const pad = document.getElementById('pxsPad'); if (pad) pad.style.visibility = 'hidden';
    this.keys = {}; this.touch.left = this.touch.right = false;
    this.renderMapList();
    if (!this.mapSel && this.navTarget && this.navTarget.loc) this.mapSel = this.navTarget.loc;
    this.renderMapDetail();
    requestAnimationFrame(() => this.drawMap());
    this.mapTimer = setInterval(() => this.drawMap(), 500);
  };

  PW.prototype.closeMap = function () {
    if (!this.mapEl) return;
    this.mapEl.hidden = true;
    const pad = document.getElementById('pxsPad'); if (pad) pad.style.visibility = '';
    clearInterval(this.mapTimer);
    try { this.canvas.focus({ preventScroll: true }); } catch (e) { /* lama */ }
  };

  PW.prototype.mapFiltered = function () {
    const q = this.mapQuery, c = this.mapCat;
    return this.data.locations.filter((l) => (c === 'semua' || l.category === c) &&
      (!q || (l.name + ' ' + (l.rombel || '') + ' ' + l.description + ' gedung ' + l.building).toLowerCase().indexOf(q) >= 0));
  };

  PW.prototype.renderMapList = function () {
    const list = this.mapEl.querySelector('.pxs-list');
    const groups = [['A', 'Gedung A'], ['B', 'Gedung B'], ['C', 'Gedung C'], ['D', 'Gedung D'], ['Luar', 'Area Luar & Olahraga']];
    const items = this.mapFiltered();
    let html = '';
    groups.forEach((gp) => {
      const rows = items.filter((l) => l.building === gp[0]).sort((a, b) => a.floor - b.floor);
      if (!rows.length) return;
      html += '<h4>' + gp[1] + '</h4>';
      rows.forEach((l) => {
        html += '<button type="button" class="pxs-item' + (this.mapSel && this.mapSel.id === l.id ? ' on' : '') + '" data-id="' + esc(l.id) + '"><i style="background:' + CAT[l.category] + '"></i>'
          + esc(l.name) + '<small>' + (l.building === 'Luar' ? 'luar' : 'Lt.' + l.floor) + '</small></button>';
      });
    });
    list.innerHTML = html || '<p style="padding:8px;color:#c9b48f">Tidak ada lokasi yang cocok.</p>';
  };

  PW.prototype.selectMapLoc = function (id) {
    this.mapSel = this.locById.get(id) || null;
    this.renderMapList(); this.renderMapDetail(); this.drawMap();
  };

  PW.prototype.renderMapDetail = function () {
    const d = this.mapEl.querySelector('.pxs-detail'), l = this.mapSel;
    if (!l) { d.innerHTML = 'Pilih lokasi di peta atau daftar.'; return; }
    const where = l.building === 'Luar' ? 'Area luar ruangan' : 'Gedung ' + l.building + ' · Lantai ' + l.floor;
    d.innerHTML = '<b>' + esc(l.name) + '</b><p>' + esc(CAT_LABEL[l.category]) + ' · ' + esc(where) + (l.rombel ? ' · Rombel ' + esc(l.rombel) : '') + '</p>'
      + '<p>' + esc(l.description) + '</p><div class="pxs-row"><button type="button" class="pxs-btn" data-act="nav">🧭 Tunjukkan Arah</button>'
      + '<button type="button" class="pxs-btn pxs-btn--dark" data-act="go">⚡ Antar ke Sana</button></div>';
  };

  PW.prototype.drawMap = function () {
    const cv = this.mapCv;
    if (!cv || this.mapEl.hidden) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2), box = cv.parentElement;
    const W = Math.round(box.clientWidth * dpr), H = Math.round(box.clientHeight * dpr);
    if (cv.width !== W || cv.height !== H) { cv.width = W; cv.height = H; }
    const g = cv.getContext('2d');
    const sx = (W - 20 * dpr) / (BX[1] - BX[0]), sz = (H - 20 * dpr) / (BZ[1] - BZ[0]), s = Math.min(sx, sz);
    const ox = (W - (BX[1] - BX[0]) * s) / 2, oz = (H - (BZ[1] - BZ[0]) * s) / 2;
    const P = (x, z) => ({ x: ox + (x - BX[0]) * s, y: oz + (z - BZ[0]) * s });
    g.fillStyle = '#3f6e3a'; g.fillRect(0, 0, W, H);
    for (let i = 0; i < W; i += 8 * dpr) for (let j = (i / 8) % 2 * 4 * dpr; j < H; j += 16 * dpr) { g.fillStyle = '#467a40'; g.fillRect(i, j, 2 * dpr, 2 * dpr); }
    // pagar keliling & jalan utama (dari gerbang timur ke barat)
    const tl = P(-203, -106), br = P(198, 122);
    g.strokeStyle = '#d8cdb8'; g.lineWidth = 3 * dpr; g.strokeRect(tl.x, tl.y, br.x - tl.x, br.y - tl.y);
    const r1 = P(-200, 8), r2 = P(200, 20);
    g.fillStyle = '#b8a88c'; g.fillRect(r1.x, r1.y, r2.x - r1.x, r2.y - r1.y);
    g.font = '700 ' + Math.round(11 * dpr) + 'px "Pixelify Sans", monospace'; g.textAlign = 'center'; g.textBaseline = 'middle';
    const rect = (b, fill, label) => {
      const a = P(b.x - b.width / 2, b.z - b.depth / 2), c = P(b.x + b.width / 2, b.z + b.depth / 2);
      g.fillStyle = '#20130a'; g.fillRect(a.x - 2, a.y - 2, c.x - a.x + 4, c.y - a.y + 4);
      g.fillStyle = fill; g.fillRect(a.x, a.y, c.x - a.x, c.y - a.y);
      if (label) { g.fillStyle = '#20130a'; g.fillText(label, (a.x + c.x) / 2 + dpr, (a.y + c.y) / 2 + dpr); g.fillStyle = '#fff6e0'; g.fillText(label, (a.x + c.x) / 2, (a.y + c.y) / 2); }
    };
    const C = this.campus;
    if (C) {
      const bl = {};
      C.buildings.forEach((b) => { bl[b.id] = b.bounds; });
      if (C.sports && C.sports.futsal) rect(C.sports.futsal, '#3f9a4a', 'Futsal');
      if (C.parking && C.parking.student) rect(C.parking.student, '#5b6470', 'Parkir Motor');
      if (C.canteen && C.canteen.outdoor) rect(C.canteen.outdoor, '#a07a52', 'Kantin');
      [['kolam-renang', '#3aa3d9', 'Kolam'], ['area-f', '#c9b99a', 'Area F'], ['parkir-mobil', '#5b6470', 'Parkir Mobil'],
        ['gedung-a', '#9c5a40', 'GEDUNG A'], ['gedung-b', '#2e6f7d', 'GEDUNG B'], ['gedung-c', '#8c6a43', 'GEDUNG C'], ['gedung-d', '#6b6156', 'GEDUNG D'],
        ['musholah', '#2f8f4e', 'Musholah'], ['gate-main', '#3a2414', 'Gerbang']].forEach((q) => { if (bl[q[0]]) rect(bl[q[0]], q[1], q[2]); });
      if (C.garden && C.garden.fountain) { const f = P(C.garden.fountain.x, C.garden.fountain.z); g.fillStyle = '#7fd3ff'; g.beginPath(); g.arc(f.x, f.y, C.garden.fountain.radius * s, 0, 7); g.fill(); }
    }
    // titik lokasi
    const shown = this.mapFiltered();
    this.mapPts = [];
    shown.forEach((l) => {
      const c = P(l.campus.x, l.campus.z), off = l.floor === 2 ? 4 * dpr : 0;
      const pt = { x: c.x + off, y: c.y - off, loc: l };
      this.mapPts.push(pt);
      const sel = this.mapSel && this.mapSel.id === l.id;
      const rr = (sel ? 7 : 4.5) * dpr;
      g.fillStyle = '#20130a'; g.fillRect(pt.x - rr - dpr, pt.y - rr - dpr, rr * 2 + 2 * dpr, rr * 2 + 2 * dpr);
      g.fillStyle = CAT[l.category]; g.fillRect(pt.x - rr, pt.y - rr, rr * 2, rr * 2);
      if (l.floor === 2) { g.fillStyle = '#20130a'; g.fillText('2', pt.x, pt.y); }
      if (sel) { g.fillStyle = '#20130a'; const tw = g.measureText(l.name).width + 10 * dpr; g.fillRect(pt.x - tw / 2, pt.y - 22 * dpr, tw, 15 * dpr); g.fillStyle = '#ffcf4a'; g.fillText(l.name, pt.x, pt.y - 14.5 * dpr); }
    });
    // pemain
    const me = this.campusPos(), m = P(me.x, me.z), pulse = 6 + (Math.floor(Date.now() / 300) % 2) * 2;
    g.fillStyle = '#20130a'; g.fillRect(m.x - (pulse + 1) * dpr, m.y - (pulse + 1) * dpr, (pulse * 2 + 2) * dpr, (pulse * 2 + 2) * dpr);
    g.fillStyle = '#ff5a5a'; g.fillRect(m.x - pulse * dpr, m.y - pulse * dpr, pulse * 2 * dpr, pulse * 2 * dpr);
    g.fillStyle = '#fff6e0'; g.fillText('KAMU', m.x, m.y - (pulse + 9) * dpr);
    // kompas
    g.fillStyle = '#fff6e0'; g.textAlign = 'left'; g.fillText('U ▲   Barat ◀ ▶ Timur', 10 * dpr, 12 * dpr);
  };
})();
