/**
 * Virtual City SMK Silber — Pixel World SIDE-VIEW (kamera menghadap depan).
 *
 * Sumber data: /assets/map/pixel-side.json (38 lokasi resmi dari /var/www/ruang.txt,
 * urutan barat→timur dari koordinat campus.json) + /assets/map/campus.json (peta navigasi).
 *
 * Struktur dunia:
 *   OUT (halaman sekolah) ──pintu──▶ G{A,B,C,D}_{1,2} (koridor per lantai) ──pintu──▶ ruangan
 *                         ◀─tangga─▶ (koridor lantai 1 ⇄ lantai 2)
 *   Kantin & Pos Satpam: pintu langsung dari halaman. Mini Market: pintu toko + pintu koridor.
 *
 * Render: kanvas internal beresolusi rendah (tinggi ±230–300 px) diskalakan tajam (pixelated).
 * Bagian statis dirender sekali ke potongan cache 256 px; tiap frame hanya karakter, efek,
 * dan teks (teks digambar di resolusi layar agar papan nama tetap terbaca).
 *
 * Antarmuka untuk halaman /pixel & PixelNet sama dengan engine lama:
 *   new PixelWorld(canvasId, {onInteract, onNearbyChange, onPauseToggle, onEnterLocation, settings})
 *   .init() .paused .settings .remotes .player .nearLoc .interiors .floorSyncNum() .currentAreaId()
 *   .triggerEmote(name) .goTo(locId) .openMap() .navigateTo(locId) .setMinimap(canvas)
 * Karakter: window.PixelAvatar (sama persis dengan dashboard).
 * ES2017 (tanpa ?. / ?? / spread objek) agar jalan di HP lama.
 */
(function () {
  'use strict';
  // Suara tidak boleh pernah menghentikan game (versi audio lama di cache HP pernah melempar error).
  function sfx(name, opt) { try { const A = window.PixelAudio; if (A && typeof A.sfx === 'function') A.sfx(name, opt); } catch (e) { /* abaikan */ } }

  const FH = 72;          // tinggi satu lantai (px internal)
  const ROOF = 20;        // atap + parapet
  const GPAD = 34;        // garis jalan → bawah layar (trotoar + jalan raya)
  const CW = 256;         // lebar potongan cache
  const WALK = 84, RUN = 150;   // px/dtk (= 8.4 / 15 unit jaringan, di bawah batas server 90)
  const NEAR = 22;        // jangkauan interaksi
  const NET_SCALE = 10;   // 1 unit jaringan = 10 px (batas kecepatan server 90 u/dtk)
  const CAT = { belajar: '#38bdf8', layanan: '#f59e0b', olahraga: '#22c55e', fasilitas: '#a78bfa' };
  const CAT_LABEL = { belajar: 'Belajar', layanan: 'Layanan', olahraga: 'Olahraga', fasilitas: 'Fasilitas & Area' };
  const EMOJI = { wave: '👋', point: '👉', clap: '👏', cheer: '🎉', sit: '🪑' };
  const TINT = { pagi: 'rgba(255,196,160,0.10)', siang: null, sore: 'rgba(255,128,70,0.20)', malam: 'rgba(14,22,66,0.64)' };
  const ALIAS = { 'gate-main': 'gerbang-utama', 'area-parkir': 'parkir-motor', 'gedung-a': '@gedung-a', 'gedung-b': '@gedung-b', 'gedung-c': '@gedung-c', 'gedung-d': '@gedung-d' };

  function R(g, x, y, w, h, c) { g.fillStyle = c; g.fillRect(Math.round(x), Math.round(y), Math.round(w), Math.round(h)); }
  function rng(seed) { let s = (seed >>> 0) || 1; return function () { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; }; }
  function hash(str) { let h = 2166136261; for (let i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; }
  function shade(hex, f) { return window.PixelAvatar ? window.PixelAvatar.shade(hex, f) : hex; }
  function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
  function isTyping(e) {
    const t = e.target;
    return !!(t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable));
  }

  class PixelWorld {
    constructor(canvasId, options) {
      this.canvas = document.getElementById(canvasId);
      if (!this.canvas) throw new Error('Canvas pixel tidak ditemukan');
      this.ctx = this.canvas.getContext('2d');
      this.options = Object.assign({
        dataUrl: '/assets/map/pixel-side.json?v=7.2.0', campusUrl: '/assets/map/campus.json',
        onInteract: null, onNearbyChange: null, onPauseToggle: null, onEnterLocation: null
      }, options || {});
      this.settings = Object.assign({ reducedMotion: false }, this.options.settings || {});
      this.netScale = NET_SCALE;
      this.data = null; this.campus = null;
      this.scenes = new Map();
      this.interiors = new Map();   // dipakai PixelNet: interiors.get(id).name
      this.locById = new Map();
      this.remotes = new Map();
      this.player = { x: 0, z: 0, yaw: 0, dir: 1, facing: -1, moving: false, sprinting: false, animT: 0,
        interior: null, name: this.options.playerName || window.__PIX_NAME || '', emote: null, emoteT: 0 };
      this.scene = null;
      this.keys = {};
      this.touch = { left: false, right: false };
      this.paused = false; this.running = false;
      this.nearTarget = null; this.nearLoc = null; this.nearFurniture = null;
      this.returnTo = {};           // ruangan → asal {scene, x}
      this.trans = null;            // transisi fade
      this.navTarget = null; this.navDoneT = 0;
      this.banner = null;
      this.daypart = window.vcDaypart ? window.vcDaypart() : 'malam';
      this.sheet = null; this.sheetCache = new Map();
      this.view = { w: 480, h: 270, s: 2 };
      this.buf = document.createElement('canvas');
      this.bctx = this.buf.getContext('2d');
      this.layerCache = {};
      this.clock = 0; this.lastT = 0;
      this.minimap = null;
      this.ball = null;
      this.lastAreaName = '';
    }

    /* ======================= Inisialisasi ======================= */
    async init() {
      const res = await fetch(this.options.dataUrl, { credentials: 'same-origin' });
      this.data = await res.json();
      try { this.campus = await (await fetch(this.options.campusUrl, { credentials: 'same-origin' })).json(); } catch (e) { this.campus = null; }
      this.data.locations.forEach((l) => this.locById.set(l.id, l));
      this.buildScenes();
      await this.loadAvatar();
      this.bindEvents();
      this.setupTouch();
      if (this.setupLandscapeLock) this.setupLandscapeLock(); // HP: wajib landscape
      const mini = document.getElementById('pixMini');
      if (mini) this.setMinimap(mini);
      this.resize();
      const sp = this.data.world.spawn;
      this.enterScene('OUT', sp.x, true);
      this.player.facing = sp.facing || -1;
      this.showBanner('Gerbang Utama SMK Silber', 'Jalan ke kiri untuk masuk ke sekolah · 🧭 Peta untuk navigasi');
      window.addEventListener('vc:daypart', (e) => { this.daypart = e.detail; this.layerCache = {}; this.scenes.forEach((s) => { s.cache = new Map(); }); });
      try { if (document.fonts && document.fonts.load) await document.fonts.load('12px "Pixelify Sans"'); } catch (e) { /* font cadangan */ }
      this.running = true;
      requestAnimationFrame((t) => this.loop(t));
    }

    async loadAvatar() {
      let design = window.PixelAvatar ? window.PixelAvatar.DEFAULT_DESIGN : null;
      try { if (window.PixelAvatar) design = await window.PixelAvatar.currentDesign(); } catch (e) { /* default */ }
      this.playerDesign = design;
      this.sheet = window.PixelAvatar ? window.PixelAvatar.sheet(design) : null;
      this.setupEmoteBar(design);
      window.addEventListener('storage', (e) => {
        if (e.key === 'vc_story_char_v2' && window.PixelAvatar) {
          window.PixelAvatar.currentDesign().then((d) => { this.playerDesign = d; this.sheet = window.PixelAvatar.sheet(d); });
        }
      });
    }

    /** Emote bar mengikuti 5 emote favorit pilihan pemain (penyunting karakter di dashboard). */
    setupEmoteBar(design) {
      const bar = document.getElementById('pixEmoteBar');
      const PA = window.PixelAvatar;
      if (!bar || !PA || !design || !Array.isArray(design.emotes)) return;
      // Favorit (maks. 8) + tombol ✨ = panel semua 18 emote.
      const btn = (e) => '<button class="pbtn" type="button" data-emote="' + e + '" title="' + (PA.EMOTE_LABEL[e] || e) + '" style="min-width:40px;min-height:40px">' + (PA.EMOTES[e] || '✨') + '</button>';
      bar.innerHTML = design.emotes.map(btn).join('') + '<button class="pbtn" type="button" data-emote-all title="Semua emote" style="min-width:40px;min-height:40px">✨</button>';
      let all = document.getElementById('pixEmoteAll');
      if (!all) {
        all = document.createElement('div');
        all.id = 'pixEmoteAll';
        all.hidden = true;
        all.style.cssText = 'position:absolute;z-index:96;right:62px;top:50%;transform:translateY(-50%);display:none;grid-template-columns:repeat(6,44px);gap:6px;padding:10px;background:rgba(20,13,29,.95);border:2px solid #d9a441;border-radius:10px;max-width:calc(100vw - 80px)';
        all.innerHTML = Object.keys(PA.EMOTES).map((e) => '<button class="pbtn" type="button" data-emote="' + e + '" title="' + (PA.EMOTE_LABEL[e] || e) + '" style="min-width:44px;min-height:44px;padding:0;font-size:20px">' + PA.EMOTES[e] + '</button>').join('');
        document.body.appendChild(all);
        all.addEventListener('click', (ev) => {
          const b = ev.target.closest ? ev.target.closest('[data-emote]') : null;
          if (b) { this.triggerEmote(b.getAttribute('data-emote')); all.style.display = 'none'; }
        });
      }
      bar.addEventListener('click', (ev) => {
        const b = ev.target.closest ? ev.target.closest('[data-emote],[data-emote-all]') : null;
        if (!b) return;
        if (b.hasAttribute('data-emote-all')) all.style.display = all.style.display === 'none' ? 'grid' : 'none';
        else this.triggerEmote(b.getAttribute('data-emote'));
      });
    }

    /** Lembar sprite pemain lain: gaya kustom (avatarConfig) atau warna jurusan bila belum ada. */
    remoteSheet(r) {
      const PA = window.PixelAvatar;
      if (!PA) return null;
      let cfg = r.avatarConfig;
      if (cfg && cfg.config) cfg = cfg.config;
      if (!cfg || (cfg.v !== 2 && !cfg.top && !cfg.skin_tone)) cfg = Object.assign({}, PA.DEFAULT, { outfit: 'casual', top: r.color || '#38bdf8', acc: [] });
      const key = 'r:' + JSON.stringify(cfg);
      return this.sheetFor(cfg, key.length > 600 ? key.slice(0, 600) : key);
    }

    sheetFor(design, key) {
      if (!window.PixelAvatar) return null;
      if (!this.sheetCache.has(key)) this.sheetCache.set(key, window.PixelAvatar.sheet(design));
      return this.sheetCache.get(key);
    }

    /* ======================= Scene ======================= */
    buildScenes() {
      const W = this.data.world;
      const out = { id: 'OUT', kind: 'outdoor', name: 'Halaman Sekolah', floor: 1, width: W.width,
        walls: W.walls.slice(), objects: [], labels: [], npcs: [], cache: new Map(), zones: W.zones, deco: null };
      this.scenes.set('OUT', out);
      const zoneById = {};
      W.zones.forEach((z) => { zoneById[z.id] = z; });
      this.zoneById = zoneById;

      // Pintu & papan lokasi luar ruangan
      W.zones.forEach((z) => {
        const cx = z.x + z.w / 2;
        if (z.type === 'building') {
          const key = z.id.slice(-1).toUpperCase();
          out.objects.push({ kind: 'door', x: z.door, to: 'G' + key + '_1', toX: 46, verb: 'Masuk', name: 'Gedung ' + key });
          if (z.shopDoor) out.objects.push({ kind: 'door', x: z.shopDoor, to: 'mini-market', toX: 46, verb: 'Masuk', name: 'Mini Market Sekolah', fromOut: true });
        }
        if (z.loc) {
          const loc = this.locById.get(z.loc);
          if (loc && loc.kind === 'room') {
            out.objects.push({ kind: 'door', x: z.door, to: loc.id, toX: 46, verb: 'Masuk', name: loc.name, fromOut: true });
          } else if (loc) {
            out.objects.push({ kind: 'sign', x: z.type === 'gate' ? z.x + 18 : cx, loc: loc });
          }
        }
        if (z.id === 'kantin') { // Taman Terbuka berada di zona tersendiri; kantin = bangunan
          /* pintu sudah ditambahkan lewat loc kantin (kind room) */
        }
        if (z.type === 'futsal') this.ball = { scene: 'OUT', x: cx, y: 0, vx: 0, vy: 0, min: z.x + 22, max: z.x + z.w - 22 };
      });
      // Dekorasi outdoor (lampu & pohon) di sela zona
      const deco = { lamps: [], trees: [], bushes: [] };
      for (let i = 0; i < W.zones.length; i++) {
        const z = W.zones[i], nz = W.zones[i + 1];
        const gapA = z.x + z.w, gapB = nz ? nz.x : W.width - 20;
        if (gapB - gapA >= 26) { deco.lamps.push(Math.round((gapA + gapB) / 2)); }
        if (z.type !== 'building') deco.bushes.push(z.x + 6);
      }
      deco.lamps.push(40);
      [30, 444, 1318, 1722, 1980, 2620, 3360, 3720, 4160, 4470].forEach((x) => deco.trees.push(x));
      out.deco = deco;

      // Koridor tiap gedung & lantai
      Object.keys(this.data.buildings).forEach((key) => {
        const b = this.data.buildings[key];
        const z = zoneById[b.zone];
        const floors = Object.keys(b.floors).map(Number).sort();
        const multi = floors.length > 1;
        // semua lantai satu gedung selebar sama → tangga lantai 1 & 2 sejajar (posisi tidak meloncat)
        const maxRooms = Math.max.apply(null, floors.map((f) => b.floors[String(f)].length));
        const width = 96 + maxRooms * 116 + (multi ? 170 : 40);
        floors.forEach((f) => {
          const ids = b.floors[String(f)];
          const sc = { id: 'G' + key + '_' + f, kind: 'corridor', building: key, buildingName: b.name, floor: f,
            name: b.name + ' · Lantai ' + f, width: width, walls: [14, width - 14], objects: [], labels: [], npcs: [], cache: new Map(), rooms: [] };
          if (f === 1) sc.objects.push({ kind: 'door', x: 40, to: 'OUT', toX: z.door, verb: 'Keluar', name: 'Halaman Sekolah', exit: true });
          ids.forEach((id, i) => {
            const loc = this.locById.get(id);
            const x = 120 + i * 116;
            sc.objects.push({ kind: 'door', x: x, to: id, toX: 46, verb: 'Masuk', name: loc.name, loc: loc });
            sc.rooms.push({ id: id, x: x });
          });
          if (multi) {
            const sx = width - 92;
            if (f < floors[floors.length - 1]) sc.objects.push({ kind: 'stairs', x: sx, dir: 1, to: 'G' + key + '_' + (f + 1), toX: sx, name: 'Lantai ' + (f + 1) });
            if (f > floors[0]) sc.objects.push({ kind: 'stairs', x: sx, dir: -1, to: 'G' + key + '_' + (f - 1), toX: sx, name: 'Lantai ' + (f - 1) });
            sc.stairsX = sx;
          }
          this.scenes.set(sc.id, sc);
          this.interiors.set(sc.id, sc);
        });
      });

      // Ruangan (interior sesuai fungsi)
      this.data.locations.forEach((loc) => {
        if (loc.kind !== 'room') return;
        const layout = ROOMS[loc.interior] || ROOMS.classroom;
        const sc = { id: loc.id, kind: 'room', loc: loc, name: loc.name, floor: loc.floor, building: loc.building,
          width: layout.width, walls: [14, layout.width - 14], objects: [], labels: [], npcs: [], items: [], cache: new Map(), theme: layout.theme };
        sc.objects.push({ kind: 'door', x: 34, to: null, verb: 'Keluar', name: '', exit: true });
        sc.objects.push({ kind: 'sign', x: 74, loc: loc, info: true });
        layout.build(sc, loc);
        sc.items.forEach((it) => {
          // Kursi yang bisa diduduki (posisi = gambar kursi di pixel-side-art.js)
          const seat = (x) => sc.objects.push({ kind: 'seat', x: x + 5 });
          if (it.k === 'sdesks') for (let i = 0; i < it.n; i++) seat(it.x + i * 30 + 22);
          else if (it.k === 'pcdesks') for (let i = 0; i < it.n; i++) seat(it.x + i * 34 + 30);
          else if (it.k === 'chairs') for (let i = 0; i < it.n; i++) seat(it.x + i * 14);
          else if (it.k === 'readtable') { seat(it.x - 12); seat(it.x + 48); }
          else if (it.k === 'tdesk') seat(it.x + 44);
          if (it.act) sc.objects.push({ kind: 'furniture', x: it.x + (it.w || 20) / 2, item: { type: it.act, label: it.label || '' }, label: it.label });
          if (it.solid) { if (it.solid === 'right') sc.walls[1] = Math.min(sc.walls[1], it.x - 6); }
        });
        sc.npcs.forEach((n) => sc.objects.push({ kind: 'npc', x: n.x, npc: n }));
        this.scenes.set(sc.id, sc);
        this.interiors.set(sc.id, sc);
      });
      // Default asal ruangan = pintunya di koridor
      this.scenes.forEach((sc) => {
        if (sc.kind !== 'corridor') return;
        sc.rooms.forEach((r) => { this.returnTo[r.id] = { scene: sc.id, x: r.x }; });
      });
      ['kantin', 'pos-keamanan'].forEach((id) => {
        const z = this.data.world.zones.find((q) => q.loc === id);
        if (z) this.returnTo[id] = { scene: 'OUT', x: z.door };
      });
    }

    /* ======================= Pindah scene ======================= */
    enterScene(id, x, instant, from) {
      if (this.player) { this.player.sitting = false; this.player.frontT = 0; }
      const sc = this.scenes.get(id);
      if (!sc) return;
      const go = () => {
        if (from && sc.kind === 'room') this.returnTo[id] = from;
        this.scene = sc;
        this.player.interior = sc.kind === 'outdoor' ? null : sc.id;
        this.player.x = clamp(x, sc.walls[0] + 4, sc.walls[1] - 4);
        this.camX = null;
        if (this.ball) this.ball.hidden = sc.id !== this.ball.scene;
        this.updateNearby(true);
        if (sc.kind !== 'outdoor') this.showBanner(sc.name, sc.kind === 'room' ? this.roomSubtitle(sc.loc) : 'Pintu ruangan ada di sepanjang koridor');
        if (sc.kind === 'room' && typeof this.options.onEnterLocation === 'function') this.options.onEnterLocation(sc.loc);
        if (window.PixelNet && window.PixelNet.pushMoveNow) window.PixelNet.pushMoveNow(this.player, this.currentAreaId());
      };
      if (instant || this.settings.reducedMotion) { go(); return; }
      sfx('door');
      this.trans = { t: 0, dur: 0.36, mid: go, done: false };
    }

    roomSubtitle(loc) {
      const b = loc.building === 'Luar' ? 'Area luar' : 'Gedung ' + loc.building + ' · Lantai ' + loc.floor;
      return b + (loc.rombel ? ' · Rombel ' + loc.rombel : '') + ' — ' + loc.description;
    }

    showBanner(title, sub) { this.banner = { title: title, sub: sub || '', t: 3.4 }; }

    floorSyncNum() { return this.scene ? (this.scene.floor || 1) : 1; }

    /** ID lokasi resmi (campus.json) untuk chat area: ruangan → ID ruang, koridor → gedung, halaman → zona terdekat. */
    chatAreaId() {
      const sc = this.scene;
      if (!sc) return 'TAMAN_TENGAH';
      const ref = (loc) => String(loc.campusRef || '').split(':')[1] || '';
      if (sc.kind === 'room') return ref(sc.loc) || 'TAMAN_TENGAH';
      if (sc.kind === 'corridor') return 'gedung-' + String(sc.building).toLowerCase();
      const x = this.player.x;
      let best = null, bd = Infinity;
      this.data.world.zones.forEach((z) => { const d = Math.abs(z.x + z.w / 2 - x); if (d < bd) { bd = d; best = z; } });
      if (best && best.type === 'building') return best.id;
      const loc = best && best.loc ? this.locById.get(best.loc) : null;
      return (loc && ref(loc)) || 'TAMAN_TENGAH';
    }
    currentAreaId() { return this.scene ? String(this.scene.id).replace(/[^A-Za-z0-9_-]/g, '').slice(0, 32) : 'OUT'; }

    /* ======================= Input ======================= */
    bindEvents() {
      const map = { arrowleft: 'left', a: 'left', arrowright: 'right', d: 'right', arrowup: 'up', w: 'up', arrowdown: 'down', s: 'down', shift: 'run' };
      window.addEventListener('keydown', (e) => {
        if (isTyping(e)) return;
        const k = (e.key || '').toLowerCase();
        if (k === 'escape') { if (this.mapEl && !this.mapEl.hidden) { this.closeMap(); return; } if (this.options.onPauseToggle) this.options.onPauseToggle(); return; }
        if (k === 'm') { this.mapEl && !this.mapEl.hidden ? this.closeMap() : this.openMap(); return; }
        if (this.paused || this.portraitLock || (this.mapEl && !this.mapEl.hidden)) return;
        if (k === 'e' || k === 'enter' || k === ' ') { e.preventDefault(); this.interact(); return; }
        const m = map[k];
        if (!m) return;
        if (m === 'up' && !this.keys.up) this.vertical(1);
        if (m === 'down' && !this.keys.down) this.vertical(-1);
        this.keys[m] = true;
        if (k.indexOf('arrow') === 0) e.preventDefault();
      });
      window.addEventListener('keyup', (e) => {
        const k = (e.key || '').toLowerCase();
        const m = map[k];
        if (m) this.keys[m] = false;
      });
      window.addEventListener('blur', () => { this.keys = {}; this.touch.left = this.touch.right = false; });
      window.addEventListener('resize', () => this.resize());
      window.addEventListener('orientationchange', () => setTimeout(() => this.resize(), 250));
      this.canvas.addEventListener('pointerdown', () => { try { this.canvas.focus({ preventScroll: true }); } catch (e) { /* lama */ } });
      // Zoom: scroll mouse, cubit dua jari (HP), tombol ＋/－ di topbar (pix.zoomBy).
      this.canvas.addEventListener('wheel', (e) => { e.preventDefault(); this.zoomBy(e.deltaY < 0 ? 1.1 : 1 / 1.1); }, { passive: false });
      let pinch = 0;
      const span = (tl) => Math.hypot(tl[0].clientX - tl[1].clientX, tl[0].clientY - tl[1].clientY);
      this.canvas.addEventListener('touchstart', (e) => { if (e.touches.length >= 2) pinch = span(e.touches); }, { passive: true });
      this.canvas.addEventListener('touchmove', (e) => {
        if (e.touches.length < 2 || !pinch) return;
        const d = span(e.touches);
        if (Math.abs(d - pinch) > 12) { this.zoomBy(d / pinch); pinch = d; }
      }, { passive: true });
      this.canvas.addEventListener('touchend', (e) => { if (e.touches.length < 2) pinch = 0; });
    }

    setupTouch() {
      const coarse = window.matchMedia && (window.matchMedia('(pointer:coarse)').matches || window.matchMedia('(max-width:900px)').matches);
      const joy = document.getElementById('pixJoy');
      if (joy) joy.style.display = 'none';
      const act = document.getElementById('pixAction');
      if (act) {
        act.addEventListener('click', (e) => { e.preventDefault(); this.interact(); });
        this.actionBtn = act;
      }
      const pad = document.createElement('div');
      pad.id = 'pxsPad';
      pad.innerHTML = '<button type="button" data-k="left" aria-label="Kiri">◀</button><button type="button" data-k="right" aria-label="Kanan">▶</button>'
        + '<span class="pxs-vert"><button type="button" data-k="up" aria-label="Masuk / naik">▲</button><button type="button" data-k="down" aria-label="Keluar / turun">▼</button></span>';
      document.body.appendChild(pad);
      if (!coarse) pad.classList.add('pxs-hide');
      pad.querySelectorAll('button').forEach((b) => {
        const k = b.getAttribute('data-k');
        const down = (e) => { e.preventDefault(); if (k === 'up') this.vertical(1); else if (k === 'down') this.vertical(-1); else { this.touch[k] = true; } b.classList.add('on'); };
        const up = (e) => { if (e) e.preventDefault(); if (k === 'left' || k === 'right') this.touch[k] = false; b.classList.remove('on'); };
        b.addEventListener('pointerdown', down);
        b.addEventListener('pointerup', up);
        b.addEventListener('pointerleave', up);
        b.addEventListener('pointercancel', up);
        b.addEventListener('contextmenu', (e) => e.preventDefault());
      });
    }

    /** ▲ = masuk pintu / naik tangga, ▼ = keluar / turun tangga. */
    vertical(dir) {
      if (this.paused || this.trans) return;
      const t = this.nearTarget, p = this.player;
      if (p.sitting) { if (dir > 0) this.standUp(); return; }
      if (dir < 0 && t && t.kind === 'seat') { this.sitOn(t); return; }
      if (dir < 0 && (!t || !(t.kind === 'door' && t.exit))) { p.frontT = 4; return; } // S/↓ = menghadap depan
      if (!t) return;
      if (t.kind === 'stairs' && t.dir === dir) { this.interact(); return; }
      if (t.kind === 'door' && ((dir > 0 && !t.exit) || (dir < 0 && t.exit))) { this.interact(); }
    }

    resizeCanvas() { this.resize(); } // nama lama yang masih dipanggil halaman /pixel

    /** Zoom kamera 0.7× – 1.6× (disimpan per perangkat). */
    zoomBy(f) {
      const z = Math.max(0.7, Math.min(1.6, (this.zoom || 1) * f));
      if (Math.abs(z - (this.zoom || 1)) < 0.001) return;
      this.zoom = z;
      try { localStorage.setItem('vc_pixel_zoom', String(z)); } catch (e) { /* mode privat */ }
      this.resize();
    }

    resize() {
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      const cw = Math.max(1, this.canvas.clientWidth || window.innerWidth);
      const ch = Math.max(1, this.canvas.clientHeight || window.innerHeight);
      const Wd = Math.round(cw * dpr), Hd = Math.round(ch * dpr);
      this.canvas.width = Wd; this.canvas.height = Hd;
      let s = Math.floor(Hd / 230);
      let vh;
      if (s >= 2) { vh = Math.floor(Hd / s); if (vh > 300) { s = Math.ceil(Hd / 300); vh = Math.floor(Hd / s); } }
      else { vh = 230; s = Hd / 230; }
      if (this.zoom === undefined) {
        let z0 = 1;
        try { z0 = parseFloat(localStorage.getItem('vc_pixel_zoom')) || 1; } catch (e) { /* mode privat */ }
        this.zoom = Math.max(0.7, Math.min(1.6, z0));
      }
      if (this.zoom !== 1) { vh = Math.max(160, Math.min(420, Math.floor(Hd / (s * this.zoom)))); s = Hd / vh; }
      const vw = Math.ceil(Wd / s);
      const changed = vh !== this.view.h;
      this.view = { w: vw, h: vh, s: s, dpr: dpr, Wd: Wd, Hd: Hd };
      this.buf.width = vw; this.buf.height = vh;
      if (changed) { this.layerCache = {}; this.scenes.forEach((sc) => { sc.cache = new Map(); }); }
    }

    /* ======================= Loop ======================= */
    loop(t) {
      if (!this.running) return;
      requestAnimationFrame((tt) => this.loop(tt)); // dijadwalkan dulu → satu error tidak membekukan game
      const dt = Math.min(0.1, ((t - (this.lastT || t)) / 1000) || 0); // HP lambat tetap jalan normal
      this.lastT = t;
      if (document.hidden) return;
      try {
        if (!this.paused && !this.portraitLock) this.update(dt);
        this.draw();
      } catch (e) {
        if (!this.loopErr) { this.loopErr = true; console.error('[PixelWorld]', e); }
      }
    }

    update(dt) {
      this.clock += dt;
      const p = this.player, sc = this.scene;
      if (this.trans) {
        this.trans.t += dt;
        if (!this.trans.done && this.trans.t >= this.trans.dur / 2) { this.trans.done = true; this.trans.mid(); }
        if (this.trans.t >= this.trans.dur) this.trans = null;
      }
      const mapOpen = this.mapEl && !this.mapEl.hidden;
      let ax = 0;
      if (!this.trans && !mapOpen) {
        if (this.keys.left || this.touch.left) ax -= 1;
        if (this.keys.right || this.touch.right) ax += 1;
      }
      if (p.sitting && ax !== 0) this.standUp();
      if (p.sitting) ax = 0;
      p.sprinting = !!this.keys.run && ax !== 0;
      const speed = (p.sprinting ? RUN : WALK) * (p.speedMul || 1); // botol minum ≤1.25 → tetap di bawah batas server
      const ox = p.x;
      if (ax !== 0) {
        p.facing = ax;
        p.x = clamp(p.x + ax * speed * dt, sc.walls[0], sc.walls[1]);
      }
      p.moving = Math.abs(p.x - ox) > 0.01;
      p.idleT = p.moving ? 0 : (p.idleT || 0) + dt;   // diam lama → menghadap depan
      if (p.frontT > 0) p.frontT = p.moving ? 0 : p.frontT - dt;
      p.yaw = p.sitting ? 9 : (p.facing > 0 ? Math.PI / 2 : Math.PI * 1.5);
      p.dir = p.facing > 0 ? 2 : 1;
      if (p.moving) {
        p.animT += dt * (p.sprinting ? 12 : 8);
        if (window.PixelAudio && Math.floor(p.animT) !== Math.floor(p.animT - dt * (p.sprinting ? 12 : 8)) && Math.floor(p.animT) % 2 === 0) sfx('step', sc.kind === 'outdoor' ? 'paving' : 'tile');
      } else p.animT = 0;
      if (p.emoteT > 0) { p.emoteT -= dt; if (p.emoteT <= 0) p.emote = null; }
      if (this.banner) { this.banner.t -= dt; if (this.banner.t <= 0) this.banner = null; }
      this.updateBall(dt);
      this.remotes.forEach((r) => {
        const tx = (Number(r.x) || 0) * NET_SCALE;
        if (r.rx == null || Math.abs(tx - r.rx) > 300) r.rx = tx;
        const d = tx - r.rx;
        r.rx += d * Math.min(1, dt * 10);
        if (Math.abs(d) > 0.5) r.face = d > 0 ? 1 : -1;
        r.walkT = (r.moving || Math.abs(d) > 0.5) ? (r.walkT || 0) + dt * 8 : 0;
        r.idleT = r.walkT ? 0 : (r.idleT || 0) + dt;
        if (r.emoteT > 0) r.emoteT -= dt;
      });
      this.updateNearby(false);
      this.updateNav(dt);
      if (window.PixelNet && window.PixelNet.pushMove) window.PixelNet.pushMove(this.player, this.currentAreaId());
    }

    updateBall(dt) {
      const b = this.ball;
      if (!b || this.scene.id !== b.scene) return;
      b.vy -= 260 * dt;
      b.y += b.vy * dt;
      if (b.y <= 0) { b.y = 0; b.vy = Math.abs(b.vy) > 30 ? -b.vy * 0.45 : 0; b.vx *= 0.96; }
      b.x += b.vx * dt;
      if (b.x < b.min) { b.x = b.min; b.vx = Math.abs(b.vx) * 0.7; this.goalFlash = this.clock; }
      if (b.x > b.max) { b.x = b.max; b.vx = -Math.abs(b.vx) * 0.7; this.goalFlash = this.clock; }
      b.vx *= Math.pow(0.55, dt);
      b.rot = (b.rot || 0) + b.vx * dt / 4;
      // dorong bola saat berjalan menabraknya
      const p = this.player;
      if (Math.abs(p.x - b.x) < 9 && b.y < 6 && p.moving) { b.vx = p.facing * (p.sprinting ? 150 : 90); b.vy = 40; }
    }

    /* ======================= Interaksi ======================= */
    updateNearby(force) {
      const sc = this.scene, p = this.player;
      let best = null, bd = NEAR;
      sc.objects.forEach((o) => {
        const d = Math.abs(o.x - p.x);
        const dd = o.kind === 'seat' ? d + 6 : d; // kursi kalah prioritas dari pintu/papan/meja
        if (dd < bd || (dd === bd && best && o.kind === 'door')) { bd = dd; best = o; }
      });
      if (this.ball && this.ball.scene === sc.id && Math.abs(this.ball.x - p.x) < NEAR && (!best || Math.abs(this.ball.x - p.x) < bd)) best = { kind: 'ball', x: this.ball.x };
      // Objek tambahan dari skrip lain (world-life.js: kucing, sampah) → { kind:'custom', x, verb, label, use() }
      if (this.extraTargets) {
        try {
          (this.extraTargets(sc, p) || []).forEach((o) => {
            const d = Math.abs(o.x - p.x);
            if (d < NEAR && (!best || d < Math.abs(best.x - p.x))) best = o;
          });
        } catch (e) { this.extraTargets = null; }
      }
      this.nearTarget = best;
      this.nearFurniture = best && best.kind === 'furniture' ? best.item : null;
      // lokasi saat ini (untuk HUD "📍", panel kunjungan & chat area)
      let loc = null;
      if (sc.kind === 'room') loc = sc.loc;
      else if (sc.kind === 'outdoor') {
        const z = sc.zones.find((q) => p.x >= q.x - 20 && p.x <= q.x + q.w + 20);
        if (z) loc = z.loc ? this.locById.get(z.loc) : null;
        if (best && best.kind === 'sign') loc = best.loc;
      }
      const name = loc ? loc.name : (sc.kind === 'corridor' ? sc.name : (this.zoneNameAt(p.x) || 'Jalan sekolah'));
      if (force || loc !== this.nearLoc || name !== this.lastAreaName) {
        this.nearLoc = loc;
        this.lastAreaName = name;
        if (this.options.onNearbyChange) this.options.onNearbyChange(loc ? Object.assign({}, loc, { name: name }) : { name: name }, null, name);
      }
      if (this.actionBtn) {
        const lbl = this.player.sitting ? 'BERDIRI' : best ? this.verbFor(best) : 'AKSI';
        if (this.actionBtn.textContent !== lbl) this.actionBtn.textContent = lbl;
        this.actionBtn.classList.toggle('pxs-ready', !!best);
      }
    }

    zoneNameAt(x) {
      const z = this.data.world.zones.find((q) => x >= q.x - 30 && x <= q.x + q.w + 30);
      if (!z) return '';
      if (z.loc && this.locById.get(z.loc)) return this.locById.get(z.loc).name;
      return z.name ? z.name.charAt(0) + z.name.slice(1).toLowerCase() : '';
    }

    verbFor(o) {
      if (o.kind === 'door') return o.exit ? 'KELUAR' : 'MASUK';
      if (o.kind === 'stairs') return o.dir > 0 ? 'NAIK' : 'TURUN';
      if (o.kind === 'npc') return 'BICARA';
      if (o.kind === 'seat') return 'DUDUK';
      if (o.kind === 'ball') return 'TENDANG';
      if (o.kind === 'sign') return 'INFO';
      if (o.kind === 'custom') return o.verb || 'PAKAI';
      return 'PAKAI';
    }

    /** Duduk di kursi: menghadap depan (kamera), tersinkron ke pemain lain lewat yaw = 9. */
    sitOn(seat) {
      const p = this.player;
      p.sitting = true; p.x = seat.x; p.moving = false; p.frontT = 0;
      sfx('click');
      if (this.actionBtn) this.actionBtn.textContent = 'BERDIRI';
      if (window.PixelNet && window.PixelNet.pushMoveNow) { p.yaw = 9; window.PixelNet.pushMoveNow(p, this.currentAreaId()); }
    }
    standUp() {
      const p = this.player;
      if (!p.sitting) return;
      p.sitting = false; p.idleT = 0;
      this.updateNearby(true);
    }

    interact() {
      if (this.paused || this.trans) return;
      if (this.player.sitting) { this.standUp(); return; }
      const o = this.nearTarget;
      if (!o) return;
      if (o.kind === 'seat') { this.sitOn(o); return; }
      if (o.kind === 'custom') { try { o.use(); } catch (e) { /* objek tambahan opsional */ } this.updateNearby(true); return; }
      const sc = this.scene;
      if (o.kind === 'door') {
        if (o.exit && sc.kind === 'room') {
          const back = this.returnTo[sc.id] || { scene: 'OUT', x: this.data.world.spawn.x };
          this.enterScene(back.scene, back.x);
        } else {
          this.enterScene(o.to, o.toX, false, o.fromOut ? { scene: 'OUT', x: o.x } : { scene: sc.id, x: o.x });
        }
      } else if (o.kind === 'stairs') {
        sfx('door');
        this.enterScene(o.to, o.toX);
      } else if (o.kind === 'sign') {
        if (this.options.onInteract) this.options.onInteract({ type: 'location', loc: o.loc });
      } else if (o.kind === 'furniture') {
        sfx('click');
        if (this.options.onInteract) this.options.onInteract({ type: 'furniture', item: o.item, building: { name: sc.name } });
      } else if (o.kind === 'npc') {
        sfx('pop');
        if (this.options.onInteract) this.options.onInteract({ type: 'npc', npc: o.npc, loc: sc.loc || null });
      } else if (o.kind === 'ball' && this.ball) {
        this.ball.vx = this.player.facing * 230; this.ball.vy = 150;
        sfx('pop');
      }
    }

    triggerEmote(name) {
      const all = window.PixelAvatar ? window.PixelAvatar.EMOTES : EMOJI;
      if (name === 'sit') { this.player.emote = all.sit || '🪑'; this.player.emoteT = 2.4; this.player.emoteName = ''; }
      else { this.player.emote = all[name] || EMOJI[name] || '✨'; this.player.emoteT = 2.4; this.player.emoteName = name; this.player.emoteStart = this.clock; }
      sfx('pop');
      if (window.PixelNet && window.PixelNet.sendEmote) window.PixelNet.sendEmote(name);
      if (window.vcDaily && name !== 'sit') window.vcDaily.report('emote', name); // misi harian
    }
    playEmote(name) { this.triggerEmote(name); }

    /* ======================= Teleport & navigasi ======================= */
    resolveLoc(id) {
      const a = ALIAS[id] || id;
      if (a.charAt(0) === '@') return { zone: this.zoneById[a.slice(1)] };
      const loc = this.locById.get(a);
      return loc ? { loc: loc } : null;
    }

    /** "Antar langsung": pindah ke depan lokasi tujuan. */
    goTo(id) {
      const r = this.resolveLoc(id);
      if (!r) return false;
      if (r.zone) { this.enterScene('OUT', r.zone.door || (r.zone.x + r.zone.w / 2)); return true; }
      const loc = r.loc;
      if (loc.kind === 'room') this.enterScene(loc.id, 60);
      else {
        const z = this.data.world.zones.find((q) => q.loc === loc.id);
        this.enterScene('OUT', z ? (z.type === 'gate' ? z.x + 30 : z.x + z.w / 2) : this.player.x);
      }
      return true;
    }

    navigateTo(id) {
      const r = this.resolveLoc(id);
      if (!r || !r.loc) { if (r && r.zone) { this.navTarget = { zoneDoor: r.zone }; } return; }
      this.navTarget = { loc: r.loc };
      this.navDoneT = 0;
      this.showBanner('🧭 Menuju ' + r.loc.name, this.roomSubtitle(r.loc));
    }

    targetSceneOf(loc) { return loc.kind === 'room' ? loc.id : 'OUT'; }

    /** BFS antar-scene lewat pintu & tangga → objek berikutnya di scene sekarang. */
    nextWaypoint() {
      const nt = this.navTarget;
      if (!nt || !nt.loc) return null;
      const target = this.targetSceneOf(nt.loc);
      const cur = this.scene.id;
      if (cur === target) {
        if (target === 'OUT') {
          const z = this.data.world.zones.find((q) => q.loc === nt.loc.id);
          return { x: z ? (z.type === 'gate' ? z.x + 18 : z.x + z.w / 2) : this.player.x, arrive: true, label: nt.loc.name };
        }
        return { x: this.player.x, arrive: true, label: nt.loc.name };
      }
      const edges = (sid) => {
        const sc = this.scenes.get(sid);
        const list = [];
        sc.objects.forEach((o) => {
          if (o.kind === 'door' && o.to) list.push({ to: o.to, obj: o });
          if (o.kind === 'stairs') list.push({ to: o.to, obj: o });
          if (o.kind === 'door' && o.exit && sc.kind === 'room') { const b = this.returnTo[sid]; if (b) list.push({ to: b.scene, obj: o }); }
        });
        return list;
      };
      const prev = new Map([[cur, null]]);
      const q = [cur];
      while (q.length) {
        const s = q.shift();
        if (s === target) break;
        edges(s).forEach((e) => { if (!prev.has(e.to)) { prev.set(e.to, { from: s, obj: e.obj }); q.push(e.to); } });
      }
      if (!prev.has(target)) return null;
      let s = target, step = prev.get(s);
      while (step && step.from !== cur) { s = step.from; step = prev.get(s); }
      if (!step) return null;
      const o = step.obj;
      const nextSc = this.scenes.get(s);
      const lbl = o.kind === 'stairs' ? (o.dir > 0 ? 'Naik tangga ke ' : 'Turun tangga ke ') + o.name
        : (o.exit ? 'Keluar ke ' + (nextSc ? nextSc.name : 'halaman') : 'Masuk: ' + (nextSc ? nextSc.name : o.name));
      return { x: o.x, obj: o, label: lbl };
    }

    updateNav(dt) {
      if (!this.navTarget) { this.navWp = null; return; }
      const wp = this.nextWaypoint();
      this.navWp = wp;
      if (wp && wp.arrive && Math.abs(wp.x - this.player.x) < 26) {
        this.navDoneT += dt;
        if (this.navDoneT > 0.2 && !this.navTarget.done) {
          this.navTarget.done = true;
          this.showBanner('🎯 Sampai: ' + this.navTarget.loc.name, 'Navigasi selesai');
          sfx('join');
          setTimeout(() => { if (this.navTarget && this.navTarget.done) this.navTarget = null; }, 2500);
        }
      }
    }

    /** Posisi pemain pada denah resmi (campus.json x/z) — untuk peta navigasi. */
    campusPos() {
      const sc = this.scene;
      if (sc.kind === 'room') return sc.loc.campus;
      if (sc.kind === 'corridor') {
        const z = this.zoneById[this.data.buildings[sc.building].zone];
        return z.campus;
      }
      const pts = this.data.world.zones.map((z) => {
        const c = z.campus || (z.loc && this.locById.get(z.loc) ? this.locById.get(z.loc).campus : null);
        return c ? { sx: z.x + z.w / 2, c: c } : null;
      }).filter(Boolean);
      const x = this.player.x;
      if (x <= pts[0].sx) return pts[0].c;
      for (let i = 0; i < pts.length - 1; i++) {
        const a = pts[i], b = pts[i + 1];
        if (x >= a.sx && x <= b.sx) {
          const t = (x - a.sx) / Math.max(1, b.sx - a.sx);
          return { x: a.c.x + (b.c.x - a.c.x) * t, z: a.c.z + (b.c.z - a.c.z) * t };
        }
      }
      return pts[pts.length - 1].c;
    }
  }

  // Bagian render, interior & peta ditambahkan di bawah (prototype) agar file tetap terbaca.
  window.PixelWorld = PixelWorld;
  window.__PXS = { FH: FH, ROOF: ROOF, GPAD: GPAD, CW: CW, CAT: CAT, CAT_LABEL: CAT_LABEL, TINT: TINT, R: R, rng: rng, hash: hash, shade: shade, clamp: clamp };
  const ROOMS = {};
  window.__PXS.ROOMS = ROOMS;
})();
