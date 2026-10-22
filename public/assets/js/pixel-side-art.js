/**
 * Pixel World side-view — RENDER: langit & parallax kota, fasad gedung, zona luar, koridor,
 * interior per fungsi ruangan, karakter, teks papan nama (resolusi layar), HUD navigasi.
 * Gaya mengikuti dashboard_referensi: kota malam biru gelap + cahaya jendela/lampu hangat.
 * Bagian statis dicache per potongan 256 px (dibangun ulang hanya saat waktu/ukuran berubah).
 */
(function () {
  'use strict';
  const PW = window.PixelWorld, X = window.__PXS;
  if (!PW || !X) return;
  const FH = X.FH, ROOF = X.ROOF, GPAD = X.GPAD, CW = X.CW, R = X.R, rng = X.rng, hash = X.hash, shade = X.shade, clamp = X.clamp;
  const ROOMS = X.ROOMS;
  const OUT_LINE = '#1a1220';

  const STYLE = {
    a: { wall: '#ead9bd', wall2: '#d9c4a2', trim: '#8a4b2f', base: '#9c5a40', roof: '#7b4636', accent: '#c27a3c' },
    b: { wall: '#d6e0e6', wall2: '#bccbd4', trim: '#2e6f7d', base: '#55707a', roof: '#4d5a63', accent: '#2e9cae' },
    c: { wall: '#e6d3a8', wall2: '#d1bb8c', trim: '#7d5a30', base: '#8c6a43', roof: '#6b5133', accent: '#b88a3c' },
    hall: { wall: '#efe8dc', wall2: '#dcd2c2', trim: '#6b6156', base: '#8a8174', roof: '#5d6168', accent: '#2f8f4e' }
  };
  const SKY = {
    malam: ['#0a0f2c', '#1b2350', '#3b3a6b'], pagi: ['#7aa7e0', '#f3c1a8', '#ffe1c4'],
    siang: ['#4f9be6', '#8cc4f0', '#d3ebf7'], sore: ['#3d4f96', '#e98a62', '#f8c08e']
  };
  const SHORT = {
    'kantor': 'KANTOR GURU', 'ruang-tu': 'TATA USAHA', 'ruang-praktik': 'RPS', 'ruang-seni': 'STUDIO DKV', 'ruang-ipm': 'IPM / OSIS',
    'ruang-silber-industries': 'SILBER IND.', 'lab-rakitan': 'LAB RAKITAN', 'perpus': 'PERPUSTAKAAN', 'bank-mini': 'BANK MINI',
    'mini-market': 'MINI MARKET', 'lab-tik-bawah': 'LAB TIK 1', 'lab-tik-atas': 'LAB TIK 2', 'ruang-bk-bkk': 'BK / BKK',
    'hall-serbaguna': 'HALL', 'musholah': 'MUSHOLAH'
  };
  function shortName(loc) { return SHORT[loc.id] || (loc.code ? 'RUANG ' + parseInt(loc.code.slice(1), 10) : loc.name.toUpperCase()); }
  X.shortName = shortName;

  /* ---------- konteks lukis potongan ---------- */
  function mkP(world, sc, g, G, x0, x1, H) {
    return {
      w: world, sc: sc, g: g, G: G, x0: x0, x1: x1, H: H, lights: [], night: world.daypart === 'malam',
      lit: function (x, y, w, h, c, glow) { this.lights.push([x, y, w, h, c, glow || 0]); },
      label: function (key, x, dy, text, size, color, plate) {
        if (!sc.labelMap) sc.labelMap = new Map();
        sc.labelMap.set(key, { x: x, dy: dy, text: text, size: size || 7, color: color || '#fff6e0', plate: plate || null });
      },
      vis: function (a, b) { return b >= x0 - 40 && a <= x1 + 40; }
    };
  }

  PW.prototype.getChunk = function (sc, i) {
    const key = i;
    if (sc.cache.has(key)) return sc.cache.get(key);
    const v = this.view, G = v.h - GPAD;
    const cv = document.createElement('canvas');
    cv.width = CW; cv.height = v.h;
    const g = cv.getContext('2d');
    const x0 = i * CW, x1 = x0 + CW;
    g.save(); g.translate(-x0, 0);
    const P = mkP(this, sc, g, G, x0, x1, v.h);
    if (sc.kind === 'outdoor') paintOutdoor(P);
    else if (sc.kind === 'corridor') paintCorridor(P);
    else paintRoom(P);
    // Suasana waktu: lapisan warna hanya di atas piksel yang ada (langit tetap dari lapisan langit)
    const tint = X.TINT[this.daypart];
    if (tint) {
      g.globalCompositeOperation = 'source-atop';
      g.fillStyle = sc.kind === 'outdoor' ? tint : (this.daypart === 'malam' ? 'rgba(14,22,66,0.22)' : tint);
      g.fillRect(x0, 0, CW, v.h);
      g.globalCompositeOperation = 'source-over';
    }
    // Cahaya: jendela & lampu menyala malam (dan selalu di dalam ruangan)
    const lightsOn = this.daypart === 'malam' || this.daypart === 'sore' || sc.kind !== 'outdoor';
    P.lights.forEach((L) => {
      if (!lightsOn && !L[6]) return;
      if (lightsOn) R(g, L[0], L[1], L[2], L[3], L[4]);
      if (L[5] && (this.daypart === 'malam' || (sc.kind !== 'outdoor' && L[5] > 0))) {
        const cx = L[0] + L[2] / 2, cy = L[1] + L[3] / 2, rad = L[5];
        const gr = g.createRadialGradient(cx, cy, 1, cx, cy, rad);
        gr.addColorStop(0, sc.kind === 'outdoor' ? (rad > 24 ? 'rgba(255,214,140,0.42)' : 'rgba(255,190,90,0.22)') : 'rgba(255,230,170,0.16)');
        gr.addColorStop(1, 'rgba(255,214,140,0)');
        g.globalCompositeOperation = 'lighter';
        g.fillStyle = gr; g.fillRect(cx - rad, cy - rad, rad * 2, rad * 2);
        g.globalCompositeOperation = 'source-over';
      }
    });
    g.restore();
    sc.cache.set(key, cv);
    if (sc.cache.size > 40) sc.cache.delete(sc.cache.keys().next().value);
    return cv;
  };

  /* ======================= Langit & parallax ======================= */
  PW.prototype.skyLayer = function () {
    const v = this.view, k = 'sky' + this.daypart + v.h;
    if (this.layerCache[k]) return this.layerCache[k];
    const cv = document.createElement('canvas'); cv.width = 1; cv.height = v.h;
    const g = cv.getContext('2d'), c = SKY[this.daypart] || SKY.malam;
    const gr = g.createLinearGradient(0, 0, 0, v.h - GPAD);
    gr.addColorStop(0, c[0]); gr.addColorStop(0.6, c[1]); gr.addColorStop(1, c[2]);
    g.fillStyle = gr; g.fillRect(0, 0, 1, v.h);
    this.layerCache[k] = cv;
    return cv;
  };

  function cityLayer(world, depth) {
    const v = world.view, night = world.daypart === 'malam', dusk = world.daypart === 'sore';
    const k = 'city' + depth + world.daypart + v.h;
    if (world.layerCache[k]) return world.layerCache[k];
    const W = depth === 0 ? 720 : 960, G = v.h - GPAD;
    const cv = document.createElement('canvas'); cv.width = W; cv.height = v.h;
    const g = cv.getContext('2d'), r = rng(depth ? 77 : 31);
    const body = depth === 0 ? (night ? '#1a2147' : dusk ? '#7b6a8e' : '#a9bfdc') : (night ? '#252d5a' : dusk ? '#8d7593' : '#bccbe0');
    const edge = depth === 0 ? (night ? '#151a3a' : '#9fb3cf') : (night ? '#1e2550' : '#aebed6');
    let x = 0;
    while (x < W) {
      const bw = depth === 0 ? 30 + Math.floor(r() * 50) : 44 + Math.floor(r() * 60);
      const bh = depth === 0 ? 50 + Math.floor(r() * 80) : 70 + Math.floor(r() * 110);
      const top = G - 8 - bh;
      R(g, x, top, bw, bh + 8, body);
      R(g, x, top, 2, bh + 8, edge);
      if (r() < 0.35) { R(g, x + bw / 2 - 1, top - 14, 2, 14, body); if (night) R(g, x + bw / 2 - 1, top - 16, 2, 2, '#ff5a5a'); }
      if (r() < 0.3) R(g, x + 4, top - 6, bw - 8, 6, body);
      const step = depth === 0 ? 5 : 7;
      for (let wy = top + 6; wy < G - 12; wy += step) {
        for (let wx = x + 4; wx < x + bw - 4; wx += step - 1) {
          const on = r();
          if (night ? on < 0.32 : on < 0.12) R(g, wx, wy, depth === 0 ? 2 : 3, depth === 0 ? 2 : 3, night ? (on < 0.08 ? '#ffe9a8' : '#f2c46a') : shade(body, 0.86));
        }
      }
      x += bw + Math.floor(r() * 10);
    }
    world.layerCache[k] = cv;
    return cv;
  }

  PW.prototype.drawSky = function (g, camX) {
    const v = this.view, G = v.h - GPAD;
    g.drawImage(this.skyLayer(), 0, 0, v.w, v.h);
    const night = this.daypart === 'malam';
    if (night) {
      const r = rng(9);
      for (let i = 0; i < 70; i++) {
        const sx = ((r() * 1600 - camX * 0.03) % 1600 + 1600) % 1600;
        if (sx > v.w) continue;
        const sy = r() * (G - 120);
        const tw = this.settings.reducedMotion ? 1 : (Math.sin(this.clock * 2 + i) > 0.2 ? 1 : 0.4);
        g.globalAlpha = tw; R(g, sx, sy, 1, 1, i % 7 === 0 ? '#ffe9b0' : '#ffffff'); g.globalAlpha = 1;
      }
      const mx = v.w * 0.78 - camX * 0.02;
      R(g, mx, 18, 12, 12, '#fff3c8'); R(g, mx + 2, 16, 8, 16, '#fff3c8'); R(g, mx - 2, 20, 16, 8, '#fff3c8'); R(g, mx + 5, 19, 7, 9, '#e6d9a8');
    } else {
      const sx = v.w * (this.daypart === 'pagi' ? 0.2 : this.daypart === 'sore' ? 0.85 : 0.6) - camX * 0.02;
      const sy = this.daypart === 'siang' ? 16 : 46;
      R(g, sx - 6, sy, 12, 12, '#fff2b0'); R(g, sx - 8, sy + 2, 16, 8, '#fff2b0'); R(g, sx - 4, sy - 2, 8, 16, '#fff2b0');
      const drift = this.settings.reducedMotion ? 0 : this.clock * 4;
      for (let i = 0; i < 6; i++) {
        const cx = ((i * 310 - camX * 0.06 + drift) % (v.w + 160) + v.w + 160) % (v.w + 160) - 80;
        const cy = 20 + (i * 37) % 60;
        const col = this.daypart === 'sore' ? '#f6c7a8' : '#ffffff';
        R(g, cx, cy, 34, 6, col); R(g, cx + 6, cy - 4, 18, 5, col); R(g, cx + 22, cy - 2, 10, 4, col);
      }
    }
    const far = cityLayer(this, 0), mid = cityLayer(this, 1);
    const ofar = -((camX * 0.12) % far.width), omid = -((camX * 0.3) % mid.width);
    for (let x = ofar - far.width; x < v.w; x += far.width) g.drawImage(far, Math.round(x), 0);
    for (let x = omid - mid.width; x < v.w; x += mid.width) g.drawImage(mid, Math.round(x), 0);
  };

  /* ======================= Halaman luar ======================= */
  function paintOutdoor(P) {
    const g = P.g, G = P.G, sc = P.sc;
    // pagar keliling (dinding rendah + jeruji besi) di belakang zona terbuka
    for (let x = Math.floor(P.x0 / 48) * 48 - 48; x < P.x1 + 48; x += 48) {
      R(g, x, G - 20, 48, 20, '#d8cdb8'); R(g, x, G - 20, 48, 2, '#efe6d4'); R(g, x, G - 4, 48, 4, '#a89a82');
      for (let b = 2; b < 48; b += 5) R(g, x + b, G - 44, 2, 24, '#3d3f4a');
      R(g, x, G - 46, 48, 3, '#3d3f4a');
      R(g, x, G - 50, 7, 50, '#c9bba2'); R(g, x - 1, G - 52, 9, 3, '#efe6d4');
    }
    sc.zones.forEach((z) => { if (P.vis(z.x, z.x + z.w)) ZONE[z.type](P, z); });
    sc.deco.trees.forEach((x) => { if (P.vis(x - 20, x + 20)) tree(P, x); });
    sc.deco.lamps.forEach((x) => { if (P.vis(x - 30, x + 30)) lamp(P, x); });
    // trotoar, kanstin, jalan raya
    for (let x = Math.floor(P.x0 / 12) * 12; x < P.x1; x += 12) {
      R(g, x, G, 12, 9, (x / 12) % 2 ? '#b8a88c' : '#b1a184'); R(g, x, G, 1, 9, '#9c8d72');
    }
    R(g, P.x0, G, CW, 1, '#d8cbb0');
    R(g, P.x0, G + 9, CW, 3, '#7d7468');
    R(g, P.x0, G + 12, CW, P.H - G - 12, '#3a3c45');
    for (let x = Math.floor(P.x0 / 24) * 24; x < P.x1; x += 24) R(g, x, G + 21, 12, 2, '#d9d2b0');
    R(g, P.x0, P.H - 2, CW, 2, '#2c2e36');
  }

  function plate(P, key, cx, y, w, h, text, size, bg, fg) {
    const g = P.g;
    R(g, cx - w / 2 - 1, y - 1, w + 2, h + 2, OUT_LINE);
    R(g, cx - w / 2, y, w, h, bg || '#3a2414');
    R(g, cx - w / 2, y, w, 1, shade(bg || '#3a2414', 1.4));
    P.label(key, cx, y + h / 2 - P.G, text, size || 7, fg || '#ffe9a8');
  }

  function windowPane(P, x, y, w, h, lit, seed) {
    const g = P.g;
    R(g, x - 1, y - 1, w + 2, h + 2, '#5b4636');
    R(g, x, y, w, h, '#8fbfe0');
    R(g, x, y, w, Math.floor(h / 2), '#a9d3ef');
    R(g, x + 1, y + 1, 2, h - 2, '#d6ecf7');
    if (lit) {
      // jendela menyala hangat + pendar halus (seperti kota malam di referensi)
      const r = rng(seed);
      const warm = r() < 0.5 ? '#ffd36e' : '#ffc252';
      P.lit(x, y, w, h, warm, Math.max(w, h) * 0.9);
      P.lit(x + 1, y + 1, w - 2, 3, '#fff0b8', 0);
      if (r() < 0.4) P.lit(x + 1, y + 4, 3, h - 5, '#ffe9a8', 0);
      if (r() < 0.3) P.lit(x + w - 6, y, 6, h, '#d9894a', 0);
    }
    R(g, x + Math.floor(w / 2), y, 1, h, '#5b4636');
    R(g, x, y + Math.floor(h / 2), w, 1, '#5b4636');
    R(g, x - 2, y + h + 1, w + 4, 2, '#cfc6b4');
  }

  function lamp(P, x) {
    const g = P.g, G = P.G;
    R(g, x - 1, G - 60, 3, 60, '#3b3f4a'); R(g, x - 3, G - 3, 7, 3, '#2c2f37');
    R(g, x, G - 62, 10, 2, '#3b3f4a'); R(g, x + 7, G - 61, 8, 3, '#2c2f37');
    R(g, x + 8, G - 58, 6, 2, '#e8e2c8');
    P.lit(x + 8, G - 58, 6, 2, '#fff2c0', 34);
  }

  function tree(P, x) {
    const g = P.g, G = P.G;
    R(g, x - 2, G - 24, 5, 24, '#6b4a2f'); R(g, x - 2, G - 24, 2, 24, '#5a3d26');
    const blobs = [[-14, -46, 16, 14], [-4, -54, 18, 14], [2, -44, 16, 14], [-10, -36, 22, 10]];
    blobs.forEach((b, i) => { R(g, x + b[0], G + b[1], b[2], b[3], i % 2 ? '#4f9a45' : '#3f7d3a'); });
    R(g, x - 8, G - 52, 6, 4, '#6fbf5a'); R(g, x + 4, G - 46, 5, 3, '#6fbf5a');
    R(g, x - 14, G - 30, 30, 3, '#2f6430');
  }

  function bench(P, x) { const g = P.g, G = P.G; R(g, x, G - 9, 26, 3, '#8a5a35'); R(g, x, G - 15, 26, 2, '#8a5a35'); R(g, x + 2, G - 6, 2, 6, '#3d3f4a'); R(g, x + 22, G - 6, 2, 6, '#3d3f4a'); }
  function flowers(P, x, w) { const g = P.g, G = P.G, cols = ['#e85d75', '#f7d046', '#ffffff', '#c084fc']; R(g, x, G - 6, w, 6, '#3f7d3a'); for (let i = 2; i < w; i += 4) R(g, x + i, G - 8 - (i % 3), 2, 2, cols[i % 4]); }

  const ZONE = {
    building: function (P, z) {
      const g = P.g, G = P.G, st = STYLE[z.style] || STYLE.a, n = z.floors;
      const hall = z.style === 'hall';
      const bodyH = hall ? 104 : n * FH;
      const top = G - bodyH, x = z.x, w = z.w;
      const r = rng(hash(z.id));
      // badan gedung
      R(g, x - 1, top - ROOF - 1, w + 2, bodyH + ROOF + 1, OUT_LINE);
      R(g, x, top, w, bodyH, st.wall);
      for (let px = x + 8; px < x + w - 8; px += 96) R(g, px, top, 6, bodyH, st.wall2);
      R(g, x, G - 8, w, 8, st.base);
      for (let bx = x; bx < x + w; bx += 8) R(g, bx, G - 8, 1, 8, shade(st.base, 0.8));
      // atap & parapet + perlengkapan atap
      R(g, x - 4, top - ROOF, w + 8, ROOF, st.roof);
      R(g, x - 4, top - ROOF, w + 8, 3, shade(st.roof, 1.3));
      R(g, x - 4, top - 4, w + 8, 4, st.trim);
      if (!hall) {
        R(g, x + 24, top - ROOF - 16, 22, 16, '#9aa1ab'); R(g, x + 24, top - ROOF - 16, 22, 3, '#c1c7cf'); R(g, x + 28, top - ROOF - 20, 14, 4, '#9aa1ab');
        R(g, x + w - 70, top - ROOF - 8, 14, 8, '#b9bec6'); R(g, x + w - 52, top - ROOF - 8, 14, 8, '#b9bec6');
        R(g, x + w - 96, top - ROOF - 30, 1, 30, '#3d3f4a'); R(g, x + w - 100, top - ROOF - 26, 9, 1, '#3d3f4a');
        if (P.night) R(g, x + w - 97, top - ROOF - 32, 3, 2, '#ff5a5a');
      }
      // papan nama di parapet
      const sw = Math.min(w - 40, 34 + z.name.length * 9);
      plate(P, z.id + ':nm', x + w / 2, top - ROOF + 2, sw, 15, z.name, 9, '#2a1a0e', '#ffcf4a');
      P.lit(x + w / 2 - sw / 2, top - ROOF + 16, sw, 1, '#ffcf4a', 0);
      const doorX = z.door, shop = z.shopDoor;
      const skip = (wx, f) => (f === 1 && Math.abs(wx - doorX) < 36) || (f === 1 && shop && wx > shop - 84 && wx < shop + 84) || (n > 1 && wx > x + w - 52);
      if (hall) {
        // hall: jendela lengkung tinggi; bagian musholah dengan kubah hijau di kanan
        const mx = x + w - 120;
        for (let wx = x + 22; wx < mx - 16; wx += 40) {
          if (Math.abs(wx - doorX) < 34) continue;
          windowPane(P, wx, G - 78, 20, 48, P.night || r() < 0.4, hash(z.id) + wx);
          R(g, wx + 2, G - 82, 16, 3, '#5b4636'); R(g, wx + 5, G - 85, 10, 3, '#5b4636');
        }
        R(g, mx, top + 10, 120, bodyH - 10, '#f6f2ea');
        R(g, mx, top + 10, 120, 3, st.accent);
        const dc = mx + 60;
        for (let i = 0; i < 22; i++) { const hw = Math.round(Math.sqrt(22 * 22 - i * i)); R(g, dc - hw, top + 10 - i - ROOF, hw * 2, 1, i < 3 ? '#1f6b3a' : '#2f8f4e'); }
        R(g, dc - 1, top - ROOF - 34, 2, 8, '#d9b44a'); R(g, dc - 4, top - ROOF - 40, 5, 5, '#d9b44a'); R(g, dc - 2, top - ROOF - 41, 4, 4, '#2f8f4e');
        for (let wx = mx + 14; wx < mx + 112; wx += 34) { windowPane(P, wx, G - 62, 16, 26, true, wx); R(g, wx + 3, G - 66, 10, 3, '#2f8f4e'); }
        plate(P, z.id + ':mus', dc, G - 92, 70, 12, 'MUSHOLAH', 7, '#1f5a34', '#e9f7d0');
        plate(P, z.id + ':hall', (x + mx) / 2, top + 8, 110, 12, 'HALL SERBAGUNA', 7, '#2a1a0e', '#ffe9a8');
      } else {
        for (let f = 1; f <= n; f++) {
          const yb = G - (f - 1) * FH;
          if (f > 1) {
            // pelat lantai + balkon koridor lantai atas
            R(g, x - 3, yb - 4, w + 6, 5, st.trim);
            R(g, x, yb - 18, w, 1, shade(st.trim, 1.2));
            for (let bx = x + 2; bx < x + w - 2; bx += 5) R(g, bx, yb - 17, 1, 13, shade(st.trim, 0.9));
            R(g, x, yb - 19, w, 2, st.trim);
          }
          for (let wx = x + 18; wx < x + w - 24; wx += 34) {
            if (skip(wx, f)) continue;
            windowPane(P, wx, yb - (f > 1 ? 50 : 52), 18, 24, P.night ? r() < 0.7 : r() < 0.25, hash(z.id) + wx * 7 + f);
          }
          // pintu-pintu kelas di lantai 2 terlihat dari balkon
          if (f > 1) for (let dx = x + 52; dx < x + w - 60; dx += 102) { R(g, dx, yb - 46, 12, 28, '#8a5a35'); R(g, dx + 2, yb - 44, 8, 8, '#a9d3ef'); }
          plate(P, z.id + ':lt' + f, x + 18, yb - FH + 8, 26, 9, 'LT.' + f, 6, '#2a1a0e', '#ffe9a8');
        }
        // tangga luar (tanda tangga di ujung kanan)
        if (n > 1) {
          const sx = x + w - 46;
          R(g, sx, top + 4, 40, bodyH - 12, shade(st.wall, 0.9));
          for (let i = 0; i < 8; i++) R(g, sx + 4 + i * 4, G - 14 - i * 9, 10, 3, shade(st.trim, 1.1));
          for (let i = 0; i < 8; i++) R(g, sx + 32 - i * 4, G - FH - 14 - i * 6, 10, 3, shade(st.trim, 1.1));
          plate(P, z.id + ':tg', sx + 20, top + 8, 34, 9, 'TANGGA', 5, '#2a1a0e', '#ffe9a8');
        }
      }
      // pintu utama + kanopi
      const dw = 26;
      R(g, doorX - 26, G - 58, 52, 6, st.trim); R(g, doorX - 26, G - 58, 52, 1, shade(st.trim, 1.4));
      R(g, doorX - 24, G - 52, 2, 52, st.trim); R(g, doorX + 22, G - 52, 2, 52, st.trim);
      R(g, doorX - dw / 2 - 2, G - 46, dw + 4, 46, '#3a2a1e');
      R(g, doorX - dw / 2, G - 44, dw / 2 - 1, 44, '#8fbfe0'); R(g, doorX + 1, G - 44, dw / 2 - 1, 44, '#8fbfe0');
      R(g, doorX - dw / 2 + 2, G - 42, 2, 38, '#d6ecf7'); R(g, doorX + 3, G - 42, 2, 38, '#d6ecf7');
      P.lit(doorX - dw / 2, G - 44, dw, 44, '#ffe2a0', 0);
      P.lit(doorX - 2, G - 56, 4, 2, '#fff2c0', 26);
      R(g, doorX - 30, G - 2, 60, 2, '#cfc6b4');
      P.label(z.id + ':sub', doorX, -66, z.sub, 6, '#2a1a0e');
      if (shop) {
        // etalase Mini Market (fasad toko seperti referensi): lis warna, kaca terang berisi rak
        const s0 = shop - 80, s1 = shop + 80;
        R(g, s0, G - 64, s1 - s0, 14, '#f2f2f2');
        R(g, s0, G - 63, s1 - s0, 3, '#e8892f'); R(g, s0, G - 59, s1 - s0, 3, '#2f8f4e'); R(g, s0, G - 55, s1 - s0, 3, '#c0392b');
        plate(P, z.id + ':shop', shop, G - 76, 74, 11, 'SILBER MART', 7, '#2f8f4e', '#ffffff');
        for (let wx = s0 + 6; wx < s1 - 6; wx += 52) {
          if (Math.abs(wx + 22 - shop) < 20) continue;
          R(g, wx - 1, G - 49, 46, 42, '#3a2a1e'); R(g, wx, G - 48, 44, 40, '#cfe6f2');
          P.lit(wx, G - 48, 44, 40, '#fff1c8', 0);
          for (let sy = G - 44; sy < G - 12; sy += 9) { R(g, wx + 3, sy + 6, 38, 2, '#9aa1ab'); for (let i = 0; i < 9; i++) R(g, wx + 4 + i * 4, sy, 3, 6, ['#e74c3c', '#f1c40f', '#3498db', '#2ecc71'][(i + sy) % 4]); }
        }
        R(g, shop - 12, G - 46, 24, 46, '#3a2a1e'); R(g, shop - 11, G - 45, 22, 45, '#bfe0f0'); P.lit(shop - 11, G - 45, 22, 45, '#fff1c8', 22);
        plate(P, z.id + ':perp', x + 150, G - 64, 76, 10, 'PERPUSTAKAAN', 6, '#2a1a0e', '#ffe9a8');
        plate(P, z.id + ':bank', x + 470, G - 64, 60, 10, 'BANK MINI', 6, '#1d3a5c', '#e3f1ff');
      }
      // tanaman di depan gedung
      flowers(P, x + 6, 26); flowers(P, x + w - 32, 26);
    },
    pool: function (P, z) {
      const g = P.g, G = P.G, x = z.x, w = z.w;
      for (let cx = x; cx < x + w; cx += 4) for (let cy = G - 56; cy < G - 20; cy += 4) R(g, cx + ((cy / 4) % 2) * 2, cy, 1, 1, '#7d8794');
      R(g, x, G - 58, w, 2, '#5b6470');
      R(g, x + 10, G - 18, w - 20, 18, '#d8eef7'); for (let tx = x + 10; tx < x + w - 10; tx += 8) R(g, tx, G - 18, 1, 18, '#b5d5e4');
      R(g, x + 14, G - 15, w - 28, 12, '#3aa3d9'); R(g, x + 14, G - 15, w - 28, 2, '#8fd6f5');
      R(g, x + 30, G - 30, 2, 16, '#c9d1d9'); R(g, x + 38, G - 30, 2, 16, '#c9d1d9'); for (let i = 0; i < 4; i++) R(g, x + 30, G - 28 + i * 4, 10, 1, '#c9d1d9');
      R(g, x + w - 52, G - 50, 3, 32, '#f5f5f5'); R(g, x + w - 40, G - 50, 3, 32, '#f5f5f5'); R(g, x + w - 54, G - 52, 20, 4, '#e74c3c');
      R(g, x + 70, G - 22, 40, 3, '#e8e2c8'); R(g, x + 70, G - 22, 3, 6, '#9aa1ab');
      plate(P, z.id + ':nm', x + w / 2, G - 72, 88, 12, 'KOLAM RENANG', 7, '#1d3a5c', '#e3f1ff');
      R(g, x + w / 2 - 1, G - 60, 2, 10, '#3d3f4a');
    },
    plaza: function (P, z) {
      const g = P.g, G = P.G, x = z.x, w = z.w;
      R(g, x, G - 12, w, 12, '#c9b99a'); for (let tx = x; tx < x + w; tx += 10) R(g, tx, G - 12, 1, 12, '#b3a383');
      R(g, x + 20, G - 30, 70, 18, '#7d5a3a'); R(g, x + 20, G - 30, 70, 2, '#a07a52');
      R(g, x + 26, G - 64, 58, 30, '#c0392b'); R(g, x + 28, G - 62, 54, 26, '#e74c3c');
      P.label(z.id + ':bn', x + 55, -49, 'EKSKUL', 6, '#ffffff');
      R(g, x + w - 30, G - 76, 3, 76, '#3d3f4a'); R(g, x + w - 44, G - 78, 16, 10, '#f5f5f5'); R(g, x + w - 46, G - 70, 12, 2, '#e8892f');
      bench(P, x + 100);
      plate(P, z.id + ':nm', x + w / 2, G - 92, 72, 12, 'AREA F', 7, '#2a1a0e', '#ffcf4a');
    },
    kiosk: function (P, z) {
      const g = P.g, G = P.G, x = z.x + 20, w = 150;
      R(g, x - 1, G - 63, w + 2, 63, OUT_LINE);
      R(g, x, G - 62, w, 62, '#f0d9b5'); R(g, x, G - 8, w, 8, '#a0623f');
      R(g, x - 6, G - 70, w + 12, 8, '#7b4636');
      for (let i = 0; i < w + 12; i += 10) { R(g, x - 6 + i, G - 62, 5, 10, '#c0392b'); R(g, x - 1 + i, G - 62, 5, 10, '#fff6e0'); }
      R(g, x + 10, G - 46, 60, 26, '#3a2a1e'); R(g, x + 11, G - 45, 58, 24, '#ffe9b8'); P.lit(x + 11, G - 45, 58, 24, '#ffd98a', 18);
      for (let i = 0; i < 5; i++) R(g, x + 14 + i * 11, G - 30, 8, 6, ['#e67e22', '#c0392b', '#f1c40f', '#27ae60', '#8e5a2b'][i]);
      R(g, x + 8, G - 20, 64, 4, '#8a5a35');
      R(g, z.door - 11, G - 44, 22, 44, '#6b4423'); R(g, z.door - 9, G - 42, 18, 18, '#a9d3ef');
      plate(P, z.id + ':nm', x + w / 2, G - 86, 56, 12, 'KANTIN', 7, '#c0392b', '#ffffff');
      R(g, z.x + z.w - 26, G - 34, 2, 34, '#8a5a35'); R(g, z.x + z.w - 40, G - 38, 30, 5, '#2e9cae');
      R(g, z.x + z.w - 34, G - 12, 18, 3, '#8a5a35');
    },
    gazebo: function (P, z) {
      const g = P.g, G = P.G, cx = z.x + z.w / 2;
      for (let i = 0; i < 16; i++) R(g, cx - 40 + i * 2.5, G - 70 + i, 80 - i * 5, 1, i % 4 ? '#8a4b2f' : '#6b3a24');
      R(g, cx - 42, G - 56, 84, 4, '#6b3a24');
      R(g, cx - 34, G - 52, 4, 52, '#8a5a35'); R(g, cx + 30, G - 52, 4, 52, '#8a5a35');
      R(g, cx - 30, G - 14, 60, 4, '#a07a52'); R(g, cx - 30, G - 10, 2, 10, '#6b4423'); R(g, cx + 28, G - 10, 2, 10, '#6b4423');
      P.lit(cx - 2, G - 52, 4, 3, '#fff2c0', 20);
      flowers(P, z.x + 2, 22); flowers(P, z.x + z.w - 24, 22);
      plate(P, z.id + ':nm', cx, G - 88, 88, 12, 'TAMAN TERBUKA', 7, '#2f6430', '#e9f7d0');
    },
    fountain: function (P, z) {
      const g = P.g, G = P.G, cx = z.x + z.w / 2;
      R(g, cx - 40, G - 14, 80, 14, '#b9b2a6'); R(g, cx - 40, G - 14, 80, 2, '#d8d2c6'); R(g, cx - 36, G - 12, 72, 4, '#3aa3d9');
      R(g, cx - 4, G - 38, 8, 24, '#b9b2a6'); R(g, cx - 18, G - 40, 36, 5, '#b9b2a6'); R(g, cx - 16, G - 39, 32, 2, '#3aa3d9');
      R(g, cx - 2, G - 54, 4, 14, '#b9b2a6'); R(g, cx - 8, G - 56, 16, 3, '#b9b2a6');
      P.lit(cx - 36, G - 12, 72, 3, '#7fd3ff', 0);
      bench(P, z.x + 8); bench(P, z.x + z.w - 34);
      flowers(P, cx - 64, 20); flowers(P, cx + 44, 20);
      plate(P, z.id + ':nm', cx, G - 82, 124, 12, 'TAMAN TENGAH & AIR MANCUR', 6, '#2f6430', '#e9f7d0');
    },
    futsal: function (P, z) {
      const g = P.g, G = P.G, x = z.x, w = z.w;
      R(g, x, G - 8, w, 8, '#3f9a4a'); for (let tx = x; tx < x + w; tx += 16) R(g, tx, G - 8, 8, 8, '#47a852');
      R(g, x + w / 2, G - 8, 1, 8, '#ffffff');
      for (let mx = x; mx < x + w; mx += 3) for (let my = G - 70; my < G - 8; my += 3) if ((mx + my) % 6 === 0) R(g, mx, my, 1, 1, '#9aa1ab');
      R(g, x, G - 72, w, 2, '#5b6470'); for (let px = x; px <= x + w; px += 40) R(g, px, G - 72, 2, 64, '#5b6470');
      const goal = (gx, dir) => { R(g, gx, G - 26, 2, 26, '#ffffff'); R(g, gx, G - 26, 16 * dir, 2, '#ffffff'); for (let i = 2; i < 24; i += 4) R(g, gx + (dir > 0 ? 2 : -14), G - 26 + i, 14, 1, '#d5dbe0'); };
      goal(x + 10, 1); goal(x + w - 12, -1);
      R(g, x + 30, G - 110, 2, 40, '#3d3f4a'); R(g, x + 24, G - 112, 14, 4, '#d9dde2'); P.lit(x + 24, G - 108, 14, 2, '#ffffff', 40);
      R(g, x + w - 32, G - 110, 2, 40, '#3d3f4a'); R(g, x + w - 38, G - 112, 14, 4, '#d9dde2'); P.lit(x + w - 38, G - 108, 14, 2, '#ffffff', 40);
      plate(P, z.id + ':nm', x + w / 2, G - 88, 104, 12, 'LAPANGAN FUTSAL', 7, '#1f5a34', '#e9f7d0');
    },
    motor: function (P, z) {
      const g = P.g, G = P.G, x = z.x, w = z.w;
      R(g, x, G - 52, w, 5, '#9aa1ab'); for (let i = 0; i < w; i += 6) R(g, x + i, G - 52, 3, 5, '#b9bec6');
      R(g, x + 4, G - 47, 3, 47, '#5b6470'); R(g, x + w - 7, G - 47, 3, 47, '#5b6470');
      const cols = ['#c0392b', '#2c3e50', '#2980b9', '#8e44ad', '#16a085'];
      for (let i = 0; i < 5; i++) {
        const mx = x + 14 + i * 28, c = cols[i];
        R(g, mx, G - 8, 7, 7, '#1c1c1c'); R(g, mx + 16, G - 8, 7, 7, '#1c1c1c'); R(g, mx + 2, G - 6, 3, 3, '#7d7d7d'); R(g, mx + 18, G - 6, 3, 3, '#7d7d7d');
        R(g, mx + 4, G - 15, 16, 6, c); R(g, mx + 6, G - 18, 9, 3, '#1c1c1c'); R(g, mx + 18, G - 20, 2, 6, '#7d7d7d'); R(g, mx + 17, G - 21, 6, 2, '#1c1c1c');
        P.lit(mx + 21, G - 14, 2, 2, '#fff2c0', 0);
      }
      plate(P, z.id + ':nm', x + w / 2, G - 66, 92, 11, 'PARKIR MOTOR', 7, '#1d3a5c', '#e3f1ff');
    },
    car: function (P, z) {
      const g = P.g, G = P.G, x = z.x;
      const cols = ['#e5e7eb', '#c0392b', '#2c3e50'];
      for (let i = 0; i < 3; i++) {
        const cx = x + 6 + i * 72, c = cols[i];
        R(g, cx, G - 18, 62, 12, c); R(g, cx + 12, G - 28, 34, 11, c); R(g, cx + 15, G - 26, 13, 8, '#9cc7e2'); R(g, cx + 30, G - 26, 13, 8, '#9cc7e2');
        R(g, cx, G - 12, 62, 2, shade(c, 0.75));
        R(g, cx + 8, G - 8, 11, 8, '#1c1c1c'); R(g, cx + 44, G - 8, 11, 8, '#1c1c1c'); R(g, cx + 11, G - 5, 5, 3, '#9aa1ab'); R(g, cx + 47, G - 5, 5, 3, '#9aa1ab');
        P.lit(cx + 58, G - 16, 4, 3, '#fff2c0', 0);
      }
      plate(P, z.id + ':nm', x + z.w / 2, G - 60, 92, 11, 'PARKIR MOBIL', 7, '#1d3a5c', '#e3f1ff');
    },
    booth: function (P, z) {
      const g = P.g, G = P.G, x = z.x + 10;
      R(g, x - 1, G - 51, 64, 51, OUT_LINE); R(g, x, G - 50, 62, 50, '#e6e0d4'); R(g, x - 4, G - 58, 70, 8, '#2c3e50');
      R(g, x + 6, G - 40, 22, 18, '#3a2a1e'); R(g, x + 7, G - 39, 20, 16, '#a9d3ef'); P.lit(x + 7, G - 39, 20, 16, '#ffe2a0', 16);
      R(g, z.door - 10, G - 42, 20, 42, '#6b4423'); R(g, z.door - 8, G - 40, 16, 12, '#a9d3ef');
      R(g, x + 62, G - 46, 6, 4, '#3d3f4a'); R(g, x + 66, G - 44, 4, 2, '#1c1c1c');
      plate(P, z.id + ':nm', x + 31, G - 70, 72, 11, 'POS SATPAM', 7, '#2c3e50', '#e3f1ff');
      const bx = z.x + z.w + 4;
      R(g, bx, G - 18, 6, 18, '#9aa1ab'); for (let i = 0; i < 36; i += 6) R(g, bx + 6 + i, G - 16, 6, 3, (i / 6) % 2 ? '#ffffff' : '#c0392b');
    },
    gate: function (P, z) {
      const g = P.g, G = P.G, x = z.x, w = z.w;
      const pil = (px) => { R(g, px - 1, G - 79, 18, 79, OUT_LINE); R(g, px, G - 78, 16, 78, '#c9bba2'); R(g, px, G - 78, 16, 3, '#efe6d4'); for (let y = G - 70; y < G; y += 8) R(g, px, y, 16, 1, '#b3a58c'); R(g, px + 4, G - 86, 8, 8, '#efe6d4'); P.lit(px + 5, G - 88, 6, 3, '#fff2c0', 28); };
      pil(x + 4); pil(x + w - 20);
      R(g, x + 4, G - 104, w - 8, 20, '#2a1a0e'); R(g, x + 4, G - 104, w - 8, 2, '#5b3a22'); R(g, x + 4, G - 86, w - 8, 2, '#5b3a22');
      P.label(z.id + ':nm', x + w / 2, -96, 'SMK SILBER', 9, '#ffcf4a');
      P.lit(x + 10, G - 102, w - 20, 1, '#ffcf4a', 0);
      for (let i = 0; i < 12; i += 3) { R(g, x + 22 + i, G - 60, 2, 60, '#3d3f4a'); R(g, x + w - 36 + i, G - 60, 2, 60, '#3d3f4a'); }
      R(g, x + 20, G - 62, 16, 3, '#3d3f4a'); R(g, x + w - 38, G - 62, 16, 3, '#3d3f4a');
      plate(P, z.id + ':wel', x + w / 2, G - 76, 70, 9, 'SELAMAT DATANG', 5, '#2f8f4e', '#ffffff');
    }
  };

  /* ======================= Koridor ======================= */
  function corridorStyle(sc) { return STYLE[{ A: 'a', B: 'b', C: 'c', D: 'hall' }[sc.building] || 'a']; }

  function shell(P, wall, wall2, floorA, floorB, ceil) {
    // dinding setinggi layar (plafon di atas) agar interior tidak menyisakan area kosong
    const g = P.g, G = P.G, top = Math.max(18, G - 168);
    R(g, P.x0, 0, CW, P.H, '#140d1d');
    R(g, P.x0, top, CW, G - top, wall);
    R(g, P.x0, G - 30, CW, 30, wall2);
    R(g, P.x0, G - 31, CW, 2, shade(wall2, 0.8));
    R(g, P.x0, top - 14, CW, 14, ceil || '#3a3046');
    R(g, P.x0, top, CW, 2, shade(wall, 0.8));
    for (let x = Math.floor(P.x0 / 14) * 14; x < P.x1; x += 14) { R(g, x, G, 14, P.H - G, ((x / 14) % 2) ? floorA : floorB); R(g, x, G, 1, P.H - G, shade(floorA, 0.85)); }
    R(g, P.x0, G, CW, 2, shade(floorA, 1.15));
    R(g, P.x0, G - 3, CW, 3, shade(wall2, 0.6));
    for (let x = Math.floor(P.x0 / 90) * 90 + 40; x < P.x1 + 90; x += 90) { R(g, x - 8, top - 2, 16, 3, '#e8e2c8'); P.lit(x - 8, top - 2, 16, 3, '#fff6d8', 46); }
    return top;
  }

  function backWindow(P, x, y, w, h) {
    const g = P.g, night = P.w.daypart === 'malam';
    R(g, x - 2, y - 2, w + 4, h + 4, '#5b4636');
    const sky = SKY[P.w.daypart] || SKY.malam;
    R(g, x, y, w, h, sky[1]); R(g, x, y + h * 0.6, w, h * 0.4, sky[2]);
    if (night) { R(g, x + 4, y + 5, 1, 1, '#ffffff'); R(g, x + w - 6, y + 9, 1, 1, '#ffffff'); }
    R(g, x + 3, y + h - 8, 6, 8, night ? '#252d5a' : '#b7c7dc'); R(g, x + 11, y + h - 12, 7, 12, night ? '#252d5a' : '#b7c7dc');
    R(g, x + Math.floor(w / 2), y, 2, h, '#5b4636'); R(g, x, y + Math.floor(h / 2), w, 2, '#5b4636');
    R(g, x - 3, y + h + 2, w + 6, 3, '#cfc6b4');
  }

  function door(P, x, G, label, key, exit) {
    const g = P.g;
    if (exit) {
      R(g, x - 16, G - 52, 32, 52, '#3a2a1e'); R(g, x - 14, G - 50, 13, 50, '#a9d3ef'); R(g, x + 1, G - 50, 13, 50, '#a9d3ef');
      R(g, x - 12, G - 48, 2, 44, '#e3f3fb'); R(g, x + 3, G - 48, 2, 44, '#e3f3fb');
      plate(P, key, x, G - 64, 44, 10, 'KELUAR', 6, '#1f6b3a', '#e9f7d0');
      P.lit(x - 18, G - 64, 4, 3, '#7dff9a', 0);
      return;
    }
    R(g, x - 13, G - 50, 26, 50, '#3a2a1e');
    R(g, x - 11, G - 48, 22, 48, '#8a5a35'); R(g, x - 11, G - 48, 22, 2, '#a0703f');
    R(g, x - 7, G - 44, 14, 10, '#a9d3ef'); P.lit(x - 7, G - 44, 14, 10, '#ffe2a0', 0);
    R(g, x + 6, G - 24, 3, 2, '#d9b44a');
    if (label) plate(P, key, x, G - 64, Math.max(46, label.length * 5 + 10), 10, label, 6, '#2a1a0e', '#ffe9a8');
  }

  function paintCorridor(P) {
    const sc = P.sc, g = P.g, G = P.G, st = corridorStyle(sc);
    const top = shell(P, shade(st.wall, 0.96), st.wall2, '#cdc6b6', '#bfb7a6', '#3a3046');
    plate(P, 'flr', 96, top + 10, 150, 13, (sc.buildingName + ' · LANTAI ' + sc.floor).toUpperCase(), 8, '#2a1a0e', '#ffcf4a');
    sc.objects.forEach((o) => {
      if (!P.vis(o.x - 60, o.x + 60)) return;
      if (o.kind === 'door') door(P, o.x, G, o.exit ? null : shortName(o.loc), 'd' + o.x, o.exit);
    });
    // jendela, loker & papan pengumuman di antara pintu
    for (let i = 0; i < sc.rooms.length; i++) {
      const xm = sc.rooms[i].x + 58;
      if (!P.vis(xm - 30, xm + 30)) continue;
      backWindow(P, xm - 14, G - 104, 28, 32);
      if (i % 2 === 0) { R(g, xm - 16, G - 46, 32, 46, '#5d7f99'); for (let lx = xm - 16; lx < xm + 16; lx += 8) { R(g, lx, G - 46, 1, 46, '#3f5d75'); R(g, lx + 5, G - 30, 2, 3, '#c9d1d9'); } }
      else { R(g, xm - 16, G - 60, 32, 22, '#a0703f'); R(g, xm - 14, G - 58, 28, 18, '#c79a5f'); R(g, xm - 12, G - 56, 8, 6, '#fff6d8'); R(g, xm, G - 54, 10, 8, '#ffd9e0'); R(g, xm - 8, G - 47, 9, 5, '#d8f0ff'); R(g, xm - 16, G - 12, 32, 4, '#8a5a35'); }
    }
    // tangga
    sc.objects.forEach((o) => {
      if (o.kind !== 'stairs' || !P.vis(o.x - 80, o.x + 80)) return;
      const sx = o.x;
      if (o.dir > 0) {
        for (let i = 0; i < 11; i++) { R(g, sx - 30 + i * 7, G - 6 - i * 10, 14, 6, '#b9b2a6'); R(g, sx - 30 + i * 7, G - 6 - i * 10, 14, 1, '#d8d2c6'); }
        R(g, sx - 34, G - 26, 2, 26, '#5b4636');
        for (let i = 0; i < 11; i++) R(g, sx - 34 + i * 7, G - 26 - i * 10, 7, 2, '#5b4636');
        plate(P, 'st' + o.x + 'u', sx + 4, G - 24, 62, 11, '▲ ' + o.name.toUpperCase(), 6, '#1f6b3a', '#e9f7d0');
      } else {
        R(g, sx - 40, G - 2, 90, 2, '#8a8174');
        for (let i = 0; i < 6; i++) R(g, sx + 40 - i * 8, top + 12 + i * 12, 14, 5, '#b9b2a6');
        R(g, sx - 44, G - 18, 98, 2, '#5b4636'); for (let rx = sx - 44; rx < sx + 54; rx += 7) R(g, rx, G - 18, 1, 16, '#5b4636');
        plate(P, 'st' + o.x + 'd', sx, G - 38, 62, 11, '▼ ' + o.name.toUpperCase(), 6, '#1f6b3a', '#e9f7d0');
      }
    });
    // ujung koridor
    R(g, sc.width - 14, 0, 14, P.H, '#140d1d'); R(g, 0, 0, 14, P.H, '#140d1d');
  }

  /* ======================= Ruangan ======================= */
  function paintRoom(P) {
    const sc = P.sc, th = sc.theme, G = P.G;
    const top = shell(P, th.wall, th.wall2, th.floorA, th.floorB, th.ceil);
    (th.windows || []).forEach((wx) => { if (P.vis(wx - 20, wx + 20)) backWindow(P, wx - 14, G - 108, 28, 34); });
    door(P, 34, G, null, 'exit', true);
    // papan info lokasi (dekat pintu) — interaksi "INFO" membuka panel lokasi & kunjungan
    const g = P.g;
    R(P.g, 60, G - 82, 30, 22, '#3a2414'); R(P.g, 62, G - 80, 26, 18, '#f3e3c3'); R(g, 64, G - 76, 18, 2, '#8a5a35'); R(g, 64, G - 72, 22, 1, '#8a5a35'); R(g, 64, G - 69, 14, 1, '#8a5a35');
    R(g, 73, G - 60, 4, 60, '#5b3a22');
    plate(P, 'title', sc.width / 2 + 30, top + 6, Math.min(sc.width - 140, 30 + sc.name.length * 5.4), 13, sc.name.toUpperCase(), 7, '#2a1a0e', '#ffcf4a');
    sc.items.forEach((it) => { if (P.vis(it.x - 10, it.x + (it.w || 30) + 10)) { const f = FURN[it.k]; if (f) f(P, it); } });
    R(g, sc.width - 14, 0, 14, P.H, '#140d1d'); R(g, 0, 0, 14, P.H, '#140d1d');
  }

  /* ---- Perabot (tampak depan, dasar di garis lantai G) ---- */
  function desk(g, x, G, w, h, c) { R(g, x, G - h, w, 3, c); R(g, x, G - h + 3, w, 2, shade(c, 0.75)); R(g, x + 2, G - h + 5, 2, h - 5, shade(c, 0.6)); R(g, x + w - 4, G - h + 5, 2, h - 5, shade(c, 0.6)); }
  function chair(g, x, G, c) { R(g, x, G - 10, 10, 2, c); R(g, x, G - 20, 2, 12, c); R(g, x + 1, G - 8, 1, 8, '#3d3f4a'); R(g, x + 8, G - 8, 1, 8, '#3d3f4a'); }
  function monitor(P, x, y, scr) { const g = P.g; R(g, x, y, 14, 10, '#1c1c24'); R(g, x + 1, y + 1, 12, 8, scr || '#5bd0ff'); P.lit(x + 1, y + 1, 12, 8, scr || '#5bd0ff', 10); R(g, x + 6, y + 10, 2, 3, '#1c1c24'); R(g, x + 3, y + 13, 8, 1, '#1c1c24'); }
  function books(g, x, y, w, seed) { const r = rng(seed), cols = ['#c0392b', '#2980b9', '#27ae60', '#f39c12', '#8e44ad', '#16a085', '#d35400', '#ecf0f1']; for (let bx = x; bx < x + w - 2;) { const bw = 2 + Math.floor(r() * 3), bh = 7 + Math.floor(r() * 4); R(g, bx, y + 11 - bh, bw, bh, cols[Math.floor(r() * cols.length)]); bx += bw + (r() < 0.2 ? 1 : 0); } }

  const FURN = {
    whiteboard: (P, it) => { const g = P.g, G = P.G; R(g, it.x - 1, G - 97, 74, 34, '#6b6156'); R(g, it.x, G - 96, 72, 32, '#f8f8f4'); R(g, it.x + 6, G - 90, 30, 2, '#2c5aa0'); R(g, it.x + 6, G - 85, 44, 1, '#2c5aa0'); R(g, it.x + 6, G - 81, 36, 1, '#c0392b'); R(g, it.x + 50, G - 90, 14, 12, '#2c5aa0'); R(g, it.x, G - 64, 72, 2, '#9aa1ab'); },
    tdesk: (P, it) => { const g = P.g, G = P.G; desk(g, it.x, G, 40, 20, '#8a5a35'); R(g, it.x + 26, G - 17, 12, 14, '#7a4a28'); R(g, it.x + 6, G - 24, 12, 4, '#3d3f4a'); R(g, it.x + 7, G - 31, 10, 7, '#2c3e50'); P.lit(it.x + 8, G - 30, 8, 5, '#9fe0ff', 0); chair(g, it.x + 44, G, '#5b3a22'); },
    sdesks: (P, it) => { const g = P.g, G = P.G; for (let i = 0; i < it.n; i++) { const x = it.x + i * 30; desk(g, x, G, 22, 15, '#b07a46'); chair(g, x + 22, G, '#2e6f7d'); R(g, x + 4, G - 17, 8, 2, '#f5f5f5'); } },
    clock: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 112, 10, 10, '#2c2c2c'); R(g, it.x + 1, G - 111, 8, 8, '#ffffff'); R(g, it.x + 4, G - 110, 1, 4, '#2c2c2c'); R(g, it.x + 5, G - 107, 3, 1, '#c0392b'); },
    poster: (P, it) => { const g = P.g, G = P.G, c = it.c || '#e8892f'; R(g, it.x, G - 92, 18, 24, '#2a1a0e'); R(g, it.x + 1, G - 91, 16, 22, c); R(g, it.x + 3, G - 88, 12, 6, '#fff6e0'); R(g, it.x + 3, G - 79, 9, 2, '#fff6e0'); },
    flagframe: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 100, 14, 10, '#c0392b'); R(g, it.x, G - 95, 14, 5, '#ffffff'); R(g, it.x + 20, G - 104, 14, 16, '#5b4636'); R(g, it.x + 21, G - 103, 12, 14, '#d9c4a2'); R(g, it.x + 40, G - 104, 14, 16, '#5b4636'); R(g, it.x + 41, G - 103, 12, 14, '#d9c4a2'); },
    pcdesks: (P, it) => { const g = P.g, G = P.G; for (let i = 0; i < it.n; i++) { const x = it.x + i * 34; desk(g, x, G, 30, 17, '#9aa1ab'); monitor(P, x + 8, G - 32, it.scr); R(g, x + 6, G - 19, 18, 2, '#2c2c2c'); chair(g, x + 30, G, '#2c3e50'); } },
    cabinet: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 40, 18, 40, '#8f969e'); for (let i = 0; i < 4; i++) { R(g, it.x + 1, G - 39 + i * 10, 16, 9, '#a5acb4'); R(g, it.x + 7, G - 35 + i * 10, 4, 1, '#3d3f4a'); } },
    fileshelf: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 60, 34, 60, '#7a4a28'); for (let s = 0; s < 4; s++) { R(g, it.x + 2, G - 58 + s * 14, 30, 12, '#3a2414'); books(g, it.x + 3, G - 58 + s * 14, 28, it.x + s); } },
    counter: (P, it) => { const g = P.g, G = P.G, w = it.w || 60; R(g, it.x, G - 26, w, 26, it.c || '#8a5a35'); R(g, it.x, G - 28, w, 3, shade(it.c || '#8a5a35', 1.3)); R(g, it.x + 2, G - 60, 2, 32, '#9aa1ab'); R(g, it.x + w - 4, G - 60, 2, 32, '#9aa1ab'); R(g, it.x + 4, G - 58, w - 8, 28, 'rgba(190,225,245,0.45)'); R(g, it.x, G - 62, w, 3, '#9aa1ab'); if (it.sign) plate(P, 'c' + it.x, it.x + w / 2, G - 74, Math.max(40, it.sign.length * 5 + 8), 10, it.sign, 6, '#2a1a0e', '#ffe9a8'); },
    printer: (P, it) => { const g = P.g, G = P.G; desk(g, it.x, G, 20, 14, '#8a5a35'); R(g, it.x + 2, G - 24, 16, 10, '#d5d8dc'); R(g, it.x + 4, G - 26, 12, 2, '#ffffff'); },
    workbench: (P, it) => { const g = P.g, G = P.G, w = it.w || 50; desk(g, it.x, G, w, 18, '#6b4a2f'); R(g, it.x, G - 18, w, 3, '#9aa1ab'); R(g, it.x + 6, G - 24, 10, 6, '#5b6470'); R(g, it.x + 22, G - 22, 12, 4, '#2c3e50'); R(g, it.x + 38, G - 26, 3, 8, '#c0392b'); R(g, it.x, G - 92, w, 40, '#c8a46e'); for (let i = 4; i < w - 4; i += 7) { R(g, it.x + i, G - 86, 2, 12, '#3d3f4a'); R(g, it.x + i + 1, G - 70, 4, 2, '#7d8794'); } },
    toolbox: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 10, 16, 10, '#c0392b'); R(g, it.x + 5, G - 13, 6, 3, '#3d3f4a'); },
    easel: (P, it) => { const g = P.g, G = P.G; R(g, it.x + 4, G - 50, 2, 50, '#8a5a35'); R(g, it.x + 20, G - 50, 2, 50, '#8a5a35'); R(g, it.x, G - 52, 26, 30, '#fff6e0'); R(g, it.x + 3, G - 46, 20, 10, '#7fb8e6'); R(g, it.x + 3, G - 36, 20, 10, '#6fbf5a'); R(g, it.x + 14, G - 44, 6, 6, '#f7d046'); },
    camera: (P, it) => { const g = P.g, G = P.G; R(g, it.x + 6, G - 40, 2, 40, '#3d3f4a'); R(g, it.x, G - 2, 14, 2, '#3d3f4a'); R(g, it.x + 2, G - 48, 12, 8, '#1c1c24'); R(g, it.x + 12, G - 46, 4, 4, '#5b6470'); R(g, it.x + 22, G - 70, 2, 70, '#3d3f4a'); R(g, it.x + 16, G - 80, 16, 12, '#f5f5f5'); P.lit(it.x + 16, G - 80, 16, 12, '#ffffff', 26); },
    art: (P, it) => { const g = P.g, G = P.G; const cols = ['#e85d75', '#7fb8e6', '#f7d046']; for (let i = 0; i < 3; i++) { R(g, it.x + i * 26, G - 100, 22, 18, '#2a1a0e'); R(g, it.x + i * 26 + 1, G - 99, 20, 16, cols[i]); R(g, it.x + i * 26 + 5, G - 94, 8, 6, '#fff6e0'); } },
    mtable: (P, it) => { const g = P.g, G = P.G, w = it.w || 80; desk(g, it.x, G, w, 16, '#7a4a28'); for (let i = 6; i < w - 6; i += 16) { R(g, it.x + i, G - 26, 8, 10, '#2f8f4e'); R(g, it.x + i + 3, G - 18, 2, 2, '#fff6e0'); } R(g, it.x + w / 2 - 6, G - 20, 12, 4, '#f5f5f5'); },
    flags: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 80, 2, 80, '#d9b44a'); R(g, it.x + 2, G - 78, 18, 12, '#2f8f4e'); R(g, it.x + 8, G - 74, 6, 4, '#ffffff'); R(g, it.x + 26, G - 80, 2, 80, '#d9b44a'); R(g, it.x + 28, G - 78, 18, 6, '#c0392b'); R(g, it.x + 28, G - 72, 18, 6, '#ffffff'); },
    trophy: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 50, 40, 50, '#5b3a22'); for (let s = 0; s < 3; s++) { R(g, it.x + 2, G - 48 + s * 16, 36, 14, '#3a2414'); for (let i = 0; i < 3; i++) { R(g, it.x + 6 + i * 11, G - 44 + s * 16, 6, 6, '#e8b923'); R(g, it.x + 8 + i * 11, G - 38 + s * 16, 2, 3, '#e8b923'); R(g, it.x + 5 + i * 11, G - 35 + s * 16, 8, 2, '#8a6a1e'); } } },
    racks: (P, it) => { const g = P.g, G = P.G; for (let i = 0; i < it.n; i++) { const x = it.x + i * 24; R(g, x, G - 70, 20, 70, '#1c1c24'); R(g, x + 1, G - 69, 18, 68, '#2a2a36'); for (let u = 0; u < 8; u++) R(g, x + 3, G - 66 + u * 8, 14, 6, '#3d3f4a'); } },
    bigscreen: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 104, 70, 40, '#1c1c24'); R(g, it.x + 2, G - 102, 66, 36, it.c || '#123a5c'); P.lit(it.x + 2, G - 102, 66, 36, it.c || '#123a5c', 20); for (let i = 0; i < 6; i++) R(g, it.x + 8 + i * 9, G - 72 - (i * 7) % 22, 5, (i * 7) % 22 + 4, '#38bdf8'); if (it.t) plate(P, 'bs' + it.x, it.x + 35, G - 114, Math.max(40, it.t.length * 5 + 8), 9, it.t, 5, '#123a5c', '#e3f1ff'); },
    parts: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 64, 36, 64, '#7d8794'); for (let s = 0; s < 4; s++) { R(g, it.x + 2, G - 62 + s * 16, 32, 14, '#5b6470'); for (let i = 0; i < 3; i++) R(g, it.x + 4 + i * 10, G - 58 + s * 16, 8, 8, ['#2e9cae', '#c27a3c', '#27ae60', '#8e44ad'][(i + s) % 4]); } },
    pccase: (P, it) => { const g = P.g, G = P.G; desk(g, it.x, G, 40, 16, '#6b4a2f'); R(g, it.x + 6, G - 36, 16, 20, '#2c2c34'); R(g, it.x + 8, G - 34, 12, 16, '#3d4a5c'); R(g, it.x + 10, G - 32, 8, 4, '#27ae60'); R(g, it.x + 10, G - 26, 8, 2, '#c27a3c'); R(g, it.x + 26, G - 20, 10, 4, '#2e9cae'); },
    bookshelves: (P, it) => { const g = P.g, G = P.G; for (let i = 0; i < it.n; i++) { const x = it.x + i * 40; R(g, x, G - 80, 36, 80, '#6b4423'); for (let s = 0; s < 5; s++) { R(g, x + 2, G - 78 + s * 15, 32, 13, '#3a2414'); books(g, x + 3, G - 77 + s * 15, 30, x * 7 + s); } } },
    readtable: (P, it) => { const g = P.g, G = P.G; desk(g, it.x, G, 46, 16, '#8a5a35'); R(g, it.x + 6, G - 19, 10, 3, '#c0392b'); R(g, it.x + 18, G - 18, 8, 2, '#2980b9'); R(g, it.x + 34, G - 28, 2, 12, '#3d3f4a'); R(g, it.x + 30, G - 30, 10, 3, '#f7d046'); P.lit(it.x + 30, G - 30, 10, 3, '#fff2c0', 14); chair(g, it.x - 12, G, '#5b3a22'); chair(g, it.x + 48, G, '#5b3a22'); },
    kiosk: (P, it) => { const g = P.g, G = P.G; R(g, it.x + 6, G - 26, 6, 26, '#5b6470'); R(g, it.x, G - 44, 18, 18, '#1c1c24'); R(g, it.x + 1, G - 43, 16, 14, '#5bd0ff'); P.lit(it.x + 1, G - 43, 16, 14, '#5bd0ff', 12); },
    atm: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 50, 24, 50, '#2e6f7d'); R(g, it.x + 4, G - 44, 16, 12, '#1c1c24'); R(g, it.x + 5, G - 43, 14, 10, '#7fd3ff'); P.lit(it.x + 5, G - 43, 14, 10, '#7fd3ff', 10); R(g, it.x + 6, G - 28, 12, 6, '#c9d1d9'); plate(P, 'atm' + it.x, it.x + 12, G - 58, 26, 8, 'ATM', 5, '#1d3a5c', '#e3f1ff'); },
    queue: (P, it) => { const g = P.g, G = P.G; for (let i = 0; i < 3; i++) { R(g, it.x + i * 20, G - 22, 3, 22, '#d9b44a'); R(g, it.x + i * 20 - 2, G - 2, 7, 2, '#8a6a1e'); } R(g, it.x + 3, G - 20, 40, 2, '#c0392b'); },
    shelves: (P, it) => { const g = P.g, G = P.G, cols = ['#e74c3c', '#f1c40f', '#3498db', '#2ecc71', '#e67e22', '#9b59b6', '#ecf0f1']; for (let i = 0; i < it.n; i++) { const x = it.x + i * 46; R(g, x, G - 56, 42, 56, '#d5d8dc'); for (let s = 0; s < 4; s++) { R(g, x + 1, G - 44 + s * 12, 40, 2, '#9aa1ab'); for (let j = 0; j < 8; j++) R(g, x + 2 + j * 5, G - 54 + s * 12, 4, 8 + ((j + s) % 2), cols[(j * 3 + s + i) % cols.length]); } } },
    fridge: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 60, 26, 60, '#c9d1d9'); R(g, it.x + 2, G - 58, 22, 50, '#dff4ff'); P.lit(it.x + 2, G - 58, 22, 50, '#e8f8ff', 16); for (let s = 0; s < 4; s++) for (let j = 0; j < 4; j++) R(g, it.x + 4 + j * 5, G - 56 + s * 12, 3, 9, ['#e74c3c', '#27ae60', '#f39c12', '#2980b9'][(j + s) % 4]); },
    cashier: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 24, 44, 24, '#2f8f4e'); R(g, it.x, G - 26, 44, 3, '#3fb36a'); R(g, it.x + 8, G - 36, 14, 10, '#1c1c24'); R(g, it.x + 9, G - 35, 12, 7, '#7dff9a'); P.lit(it.x + 9, G - 35, 12, 7, '#7dff9a', 10); R(g, it.x + 26, G - 32, 12, 6, '#3d3f4a'); plate(P, 'ksr' + it.x, it.x + 22, G - 48, 36, 9, 'KASIR', 5, '#2f8f4e', '#ffffff'); },
    screen: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 108, 80, 3, '#3d3f4a'); R(g, it.x + 2, G - 105, 76, 44, '#f8f8f4'); R(g, it.x + 10, G - 98, 40, 4, '#2c5aa0'); R(g, it.x + 10, G - 90, 56, 2, '#7d8794'); R(g, it.x + 10, G - 85, 50, 2, '#7d8794'); R(g, it.x + 34, G - 122, 12, 6, '#2c2c34'); },
    sofa: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 16, 40, 10, '#8e44ad'); R(g, it.x, G - 24, 40, 8, '#7d3c98'); R(g, it.x - 2, G - 18, 4, 12, '#7d3c98'); R(g, it.x + 38, G - 18, 4, 12, '#7d3c98'); R(g, it.x + 2, G - 6, 2, 6, '#3d3f4a'); R(g, it.x + 36, G - 6, 2, 6, '#3d3f4a'); },
    corkboard: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 100, 60, 36, '#7a4a28'); R(g, it.x + 2, G - 98, 56, 32, '#c79a5f'); const n = [[4, 4, '#fff6d8'], [18, 6, '#ffd9e0'], [34, 3, '#d8f0ff'], [8, 18, '#e9f7d0'], [26, 18, '#fff6d8'], [42, 16, '#ffd9e0']]; n.forEach((q) => R(g, it.x + 2 + q[0], G - 98 + q[1], 12, 10, q[2])); if (it.t) plate(P, 'cb' + it.x, it.x + 30, G - 110, Math.max(50, it.t.length * 5 + 8), 9, it.t, 5, '#2a1a0e', '#ffe9a8'); },
    stage: (P, it) => { const g = P.g, G = P.G, w = it.w; R(g, it.x, G - 18, w, 18, '#6b4423'); R(g, it.x, G - 18, w, 3, '#a0703f'); for (let i = 0; i < w; i += 12) R(g, it.x + i, G - 15, 1, 15, '#4a2a12'); R(g, it.x, G - 124, w, 12, '#8e1b1b'); for (let i = 0; i < 6; i++) { R(g, it.x + i * 3, G - 112, 3, 94, i % 2 ? '#a52626' : '#8e1b1b'); R(g, it.x + w - 18 + i * 3, G - 112, 3, 94, i % 2 ? '#a52626' : '#8e1b1b'); } R(g, it.x + 24, G - 104, w - 48, 34, '#1d3a5c'); plate(P, 'stg', it.x + w / 2, G - 94, 120, 12, 'HALL SERBAGUNA', 8, '#1d3a5c', '#ffcf4a'); for (let i = 0; i < 4; i++) { R(g, it.x + 30 + i * (w - 60) / 3, G - 114, 6, 4, '#d9dde2'); P.lit(it.x + 30 + i * (w - 60) / 3, G - 110, 6, 2, '#fff6d8', 30); } },
    podium: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 18 - 30, 18, 30, '#7a4a28'); R(g, it.x - 2, G - 50, 22, 4, '#a0703f'); R(g, it.x + 8, G - 58, 1, 8, '#3d3f4a'); R(g, it.x + 6, G - 60, 5, 3, '#1c1c24'); R(g, it.x + 4, G - 42, 10, 6, '#d9b44a'); },
    chairs: (P, it) => { const g = P.g; for (let i = 0; i < it.n; i++) chair(g, it.x + i * 14, P.G, '#2e6f7d'); },
    mihrab: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 100, 50, 100, '#2f8f4e'); R(g, it.x + 6, G - 88, 38, 88, '#e9f7d0'); for (let i = 0; i < 10; i++) { const hw = Math.round(19 * Math.sqrt(1 - (i / 10) * (i / 10))); R(g, it.x + 25 - hw, G - 88 - i, hw * 2, 1, '#e9f7d0'); } for (let y = G - 80; y < G - 6; y += 8) for (let x = it.x + 10; x < it.x + 40; x += 8) R(g, x, y, 3, 3, '#b9e3c6'); R(g, it.x + 54, G - 30, 22, 30, '#7a4a28'); for (let i = 0; i < 3; i++) R(g, it.x + 54 + i * 7, G - 10 * i - 10, 22 - i * 7, 2, '#a0703f'); },
    mats: (P, it) => { const g = P.g, G = P.G; for (let i = 0; i < it.n; i++) { const x = it.x + i * 26; R(g, x, G - 2, 22, 4, '#1f6b3a'); R(g, x + 2, G - 1, 18, 2, '#d9b44a'); } R(g, it.x - 6, G - 32, it.n * 26 + 6, 2, '#2f8f4e'); },
    quran: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 44, 26, 44, '#5b3a22'); for (let s = 0; s < 3; s++) { R(g, it.x + 2, G - 42 + s * 14, 22, 12, '#3a2414'); for (let i = 0; i < 6; i++) R(g, it.x + 3 + i * 3, G - 40 + s * 14, 2, 10, i % 2 ? '#1f6b3a' : '#2f8f4e'); } },
    wudhu: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 70, 70, 70, '#cfe8f0'); for (let x = it.x; x < it.x + 70; x += 7) R(g, x, G - 70, 1, 70, '#b5d5e4'); R(g, it.x, G - 30, 70, 4, '#9aa1ab'); for (let i = 0; i < 5; i++) { R(g, it.x + 6 + i * 13, G - 46, 3, 8, '#c9d1d9'); R(g, it.x + 6 + i * 13, G - 46, 6, 2, '#c9d1d9'); } plate(P, 'wdh', it.x + 35, G - 82, 72, 10, 'TEMPAT WUDHU', 6, '#1d3a5c', '#e3f1ff'); },
    cctv: (P, it) => { const g = P.g, G = P.G; desk(g, it.x, G, 50, 18, '#5b6470'); for (let i = 0; i < 4; i++) { const x = it.x + 4 + (i % 2) * 22, y = G - 62 + Math.floor(i / 2) * 18; R(g, x, y, 20, 16, '#1c1c24'); R(g, x + 1, y + 1, 18, 13, '#2f6b4a'); P.lit(x + 1, y + 1, 18, 13, '#4fae7a', 8); } R(g, it.x + 40, G - 28, 6, 10, '#1c1c24'); },
    keys: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 90, 30, 22, '#7a4a28'); for (let i = 0; i < 5; i++) { R(g, it.x + 4 + i * 5, G - 86, 1, 4, '#3d3f4a'); R(g, it.x + 3 + i * 5, G - 82, 3, 4, '#d9b44a'); } },
    stalls: (P, it) => { const g = P.g, G = P.G; for (let i = 0; i < it.n; i++) { const x = it.x + i * 70; R(g, x, G - 28, 62, 28, '#c27a3c'); R(g, x, G - 30, 62, 3, '#e0a464'); R(g, x + 4, G - 46, 54, 16, 'rgba(255,240,210,0.6)'); for (let j = 0; j < 4; j++) R(g, x + 8 + j * 12, G - 40, 8, 6, ['#e67e22', '#f1c40f', '#c0392b', '#27ae60'][(i + j) % 4]); R(g, x + 6, G - 80, 50, 22, '#2a1a0e'); plate(P, 'stl' + i + x, x + 31, G - 72, 46, 12, ['NASI', 'BAKSO', 'MINUM'][i % 3], 6, '#2a1a0e', '#ffcf4a'); P.lit(x + 4, G - 46, 54, 16, '#ffe2a0', 0); } },
    tables: (P, it) => { const g = P.g, G = P.G; for (let i = 0; i < it.n; i++) { const x = it.x + i * 46; desk(g, x, G, 30, 15, '#8a5a35'); R(g, x - 10, G - 8, 8, 2, '#5b3a22'); R(g, x - 9, G - 6, 2, 6, '#5b3a22'); R(g, x + 32, G - 8, 8, 2, '#5b3a22'); R(g, x + 37, G - 6, 2, 6, '#5b3a22'); R(g, x + 10, G - 18, 6, 3, '#f5f5f5'); } },
    plant: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 10, 12, 10, '#a0623f'); R(g, it.x - 2, G - 24, 16, 14, '#3f7d3a'); R(g, it.x + 2, G - 30, 8, 8, '#4f9a45'); },
    dispenser: (P, it) => { const g = P.g, G = P.G; R(g, it.x, G - 34, 14, 34, '#e5e7eb'); R(g, it.x + 2, G - 50, 10, 16, '#7fd3ff'); R(g, it.x + 3, G - 26, 3, 3, '#c0392b'); R(g, it.x + 8, G - 26, 3, 3, '#2980b9'); },
    banner: (P, it) => { plate(P, 'bn' + it.x, it.x, P.G - 112, Math.max(60, it.t.length * 5 + 12), 11, it.t, 6, it.c || '#c0392b', '#ffffff'); }
  };

  function theme(wall, wall2, floorA, floorB, windows, ceil) { return { wall: wall, wall2: wall2, floorA: floorA, floorB: floorB, windows: windows || [], ceil: ceil }; }
  const WOOD = ['#b07a46', '#a46f3e'], TILE = ['#d5d8dc', '#c9cdd2'], DARKT = ['#5b6470', '#4f5763'];
  function npc(name, design, lines, x) { return { x: x, name: name, design: design, lines: lines }; }
  const D = {
    guru: { skin: '#d39b72', hair: '#1c1917', hairStyle: 'short', top: '#7a4a28', bottom: '#2c2c34', accent: '#d9b44a', shoes: '#1c1917' },
    bu: { skin: '#f0c29b', hair: '#1f5a34', hairStyle: 'hijab', top: '#2e6f7d', bottom: '#1e293b', accent: '#ffffff', shoes: '#1c1917' },
    satpam: { skin: '#c98d63', hair: '#111111', hairStyle: 'short', top: '#1e3a5f', bottom: '#111827', accent: '#d9b44a', shoes: '#111111' },
    kasir: { skin: '#f1c7a0', hair: '#c0392b', hairStyle: 'hijab', top: '#2f8f4e', bottom: '#334155', accent: '#e8892f', shoes: '#1f2937' },
    teller: { skin: '#e2ad84', hair: '#2b1d16', hairStyle: 'ponytail', top: '#f8fafc', bottom: '#1d3a5c', accent: '#1d4ed8', shoes: '#111827' },
    kantin: { skin: '#e9b48a', hair: '#8e44ad', hairStyle: 'hijab', top: '#c0392b', bottom: '#475569', accent: '#ffffff', shoes: '#334155' }
  };

  // Tata letak interior tiap fungsi ruangan (lebar & daftar perabot / NPC)
  function classroom(sc, loc) {
    sc.items.push({ k: 'clock', x: 104 }, { k: 'poster', x: 114, c: '#2e9cae' }, { k: 'whiteboard', x: 140, w: 72, act: 'whiteboard', label: 'Papan Tulis' },
      { k: 'flagframe', x: 222 }, { k: 'tdesk', x: 152, w: 40, act: 'tdesk', label: 'Meja Guru' }, { k: 'sdesks', x: 214, n: 5 }, { k: 'poster', x: 300, c: '#e8892f' }, { k: 'plant', x: 360 });
  }
  ROOMS.classroom = { width: 400, theme: theme('#efe3c8', '#cdb48a', WOOD[0], WOOD[1], [116, 330]), build: classroom };
  ROOMS.office = { width: 420, theme: theme('#e8dcc4', '#a46f3e', WOOD[0], WOOD[1], [250]), build: (sc) => {
    sc.items.push({ k: 'pcdesks', x: 110, n: 3, act: 'pcdesk', label: 'Meja Kerja Guru', w: 100 }, { k: 'cabinet', x: 216 }, { k: 'corkboard', x: 290, t: 'JADWAL PIKET', act: 'note', label: 'Papan Jadwal Guru' }, { k: 'fileshelf', x: 360 }, { k: 'dispenser', x: 300 }, { k: 'clock', x: 240 });
    sc.npcs.push(npc('Pak Hendra (Guru)', D.guru, ['Selamat datang di Kantor Guru.', 'Tugas & misi kelas diberikan guru lewat server kelasmu.'], 250)); } };
  ROOMS.tu = { width: 380, theme: theme('#dfe7ea', '#7d8c96', TILE[0], TILE[1], [320]), build: (sc) => {
    sc.items.push({ k: 'counter', x: 110, w: 80, act: 'counter', label: 'Loket Tata Usaha', sign: 'LOKET TU', c: '#5b6470' }, { k: 'fileshelf', x: 210 }, { k: 'fileshelf', x: 248 }, { k: 'printer', x: 292 }, { k: 'plant', x: 350 });
    sc.npcs.push(npc('Bu Rina (TU)', D.bu, ['Layanan surat & administrasi siswa dibuka setiap hari sekolah.'], 150)); } };
  ROOMS.workshop = { width: 420, theme: theme('#d9d4c8', '#6b6156', DARKT[0], DARKT[1], [380]), build: (sc) => {
    sc.items.push({ k: 'workbench', x: 106, w: 60, act: 'workbench', label: 'Meja Praktik' }, { k: 'toolbox', x: 172 }, { k: 'workbench', x: 196, w: 60, act: 'workbench', label: 'Meja Praktik' }, { k: 'parts', x: 280 }, { k: 'toolbox', x: 324 }, { k: 'banner', x: 210, t: 'BENGKEL PRAKTIK TERPADU', c: '#c27a3c' }); } };
  ROOMS.studio = { width: 420, theme: theme('#2d2a3a', '#1f1c2a', '#3a3446', '#332e40', [380], '#16131f'), build: (sc) => {
    sc.items.push({ k: 'art', x: 110 }, { k: 'easel', x: 112, act: 'easel', label: 'Kanvas Lukis' }, { k: 'pcdesks', x: 160, n: 2, act: 'pcdesk', label: 'Komputer Desain DKV', scr: '#f0a6ff' }, { k: 'camera', x: 250, act: 'camera', label: 'Kamera & Lampu Studio' }, { k: 'banner', x: 300, t: 'STUDIO DKV', c: '#8e44ad' }, { k: 'plant', x: 340 }); } };
  ROOMS.org = { width: 380, theme: theme('#e9f2e6', '#2f8f4e', WOOD[0], WOOD[1], [330]), build: (sc) => {
    sc.items.push({ k: 'flags', x: 106 }, { k: 'mtable', x: 160, w: 90, act: 'meetingdesk', label: 'Meja Rapat IPM' }, { k: 'trophy', x: 270 }, { k: 'corkboard', x: 160, t: 'AGENDA IPM / OSIS', act: 'note', label: 'Agenda Organisasi' }); } };
  ROOMS.industry = { width: 440, theme: theme('#1f2a3a', '#16202e', '#2a3546', '#243041', [], '#111827'), build: (sc) => {
    sc.items.push({ k: 'bigscreen', x: 110, t: 'SILBER INDUSTRIES · AI LAB', c: '#123a5c' }, { k: 'pcdesks', x: 108, n: 3, act: 'workstation', label: 'Workstation Tim', scr: '#7dff9a' }, { k: 'racks', x: 230, n: 2, act: 'workstation', label: 'Server Teaching Factory', w: 46 }, { k: 'mtable', x: 300, w: 70, act: 'meetingdesk', label: 'Meja Klien Industri' }); } };
  ROOMS.rakitan = { width: 420, theme: theme('#dfe3e6', '#5b6470', TILE[0], TILE[1], [380]), build: (sc) => {
    sc.items.push({ k: 'pccase', x: 108, act: 'workbench', label: 'Meja Perakitan PC', w: 40 }, { k: 'pccase', x: 170, act: 'workbench', label: 'Meja Perakitan PC', w: 40 }, { k: 'parts', x: 232 }, { k: 'workbench', x: 280, w: 50, act: 'workbench', label: 'Meja Troubleshooting' }, { k: 'banner', x: 200, t: 'LAB RAKITAN KOMPUTER', c: '#2e6f7d' }); } };
  ROOMS.library = { width: 460, theme: theme('#efe3c8', '#7a4a28', WOOD[0], WOOD[1], [420]), build: (sc) => {
    sc.items.push({ k: 'bookshelves', x: 104, n: 3 }, { k: 'readtable', x: 250, act: 'readtable', label: 'Meja Baca' }, { k: 'kiosk', x: 316, act: 'catalog', label: 'Katalog Digital' }, { k: 'bookshelves', x: 344, n: 2 }, { k: 'banner', x: 230, t: 'RUANG LITERASI', c: '#2e6f7d' });
    sc.npcs.push(npc('Bu Wulan (Pustakawan)', D.bu, ['Ssst... perpustakaan tempat literasi.', 'Cari modul di Katalog Digital ya.'], 300)); } };
  ROOMS.bank = { width: 400, theme: theme('#e6eef5', '#1d3a5c', TILE[0], TILE[1], [360]), build: (sc) => {
    sc.items.push({ k: 'queue', x: 104 }, { k: 'counter', x: 160, w: 90, act: 'counter', label: 'Loket Teller', sign: 'TELLER BANK MINI', c: '#1d3a5c' }, { k: 'atm', x: 272, act: 'counter', label: 'ATM Edukasi' }, { k: 'plant', x: 320 }, { k: 'clock', x: 200 });
    sc.npcs.push(npc('Kak Dinda (Teller AKL)', D.teller, ['Selamat datang di Bank Mini Silber!', 'Saldo koinmu tercatat aman di ledger sekolah.'], 205)); } };
  ROOMS.market = { width: 460, theme: theme('#f5f5f0', '#2f8f4e', TILE[0], TILE[1], []), build: (sc) => {
    sc.items.push({ k: 'banner', x: 230, t: 'SILBER MART — LAB RITEL BD', c: '#2f8f4e' }, { k: 'shelves', x: 104, n: 3 }, { k: 'fridge', x: 246 }, { k: 'fridge', x: 276 }, { k: 'cashier', x: 330, act: 'counter', label: 'Kasir POS', w: 44 }, { k: 'plant', x: 400 });
    sc.npcs.push(npc('Kak Sari (Kasir BD)', D.kasir, ['Selamat datang di Silber Mart!', 'Kasir POS ini dipakai praktik siswa Bisnis Digital.'], 360)); } };
  ROOMS.labtik = { width: 460, theme: theme('#e3ecf2', '#2e6f7d', TILE[0], TILE[1], [420]), build: (sc) => {
    sc.items.push({ k: 'screen', x: 110 }, { k: 'pcdesks', x: 104, n: 6, act: 'pcdesk', label: 'Komputer Lab TIK', w: 200 }, { k: 'racks', x: 330, n: 1 }, { k: 'banner', x: 380, t: 'LAB TIK 1', c: '#2e6f7d' }); } };
  ROOMS.server = { width: 460, theme: theme('#1c2433', '#121826', '#2a3546', '#243041', [], '#0d1220'), build: (sc) => {
    sc.items.push({ k: 'bigscreen', x: 112, t: 'CYBER SECURITY OPS', c: '#3a1220' }, { k: 'racks', x: 106, n: 3, act: 'pcdesk', label: 'Rak Server', w: 70 }, { k: 'pcdesks', x: 200, n: 4, act: 'pcdesk', label: 'Komputer Lab TIK 2', scr: '#7dff9a', w: 136 }, { k: 'banner', x: 380, t: 'LAB TIK 2', c: '#123a5c' }); } };
  ROOMS.bk = { width: 380, theme: theme('#f2ebe0', '#8e44ad', WOOD[0], WOOD[1], [330]), build: (sc) => {
    sc.items.push({ k: 'sofa', x: 106 }, { k: 'tdesk', x: 172, act: 'consultdesk', label: 'Meja Konseling', w: 40 }, { k: 'corkboard', x: 250, t: 'LOWONGAN BKK', act: 'consultdesk', label: 'Papan Bursa Kerja' }, { k: 'plant', x: 330 });
    sc.npcs.push(npc('Bu Ratna (BK)', D.bu, ['Ada yang ingin diceritakan? Pintu BK selalu terbuka.', 'Info PKL & lowongan kerja ada di papan BKK.'], 230)); } };
  ROOMS.hall = { width: 600, theme: theme('#efe8dc', '#8a8174', WOOD[0], WOOD[1], [130, 250]), build: (sc) => {
    sc.items.push({ k: 'chairs', x: 104, n: 14 }, { k: 'stage', x: 330, w: 250 }, { k: 'podium', x: 470, act: 'podium', label: 'Podium & Mikrofon' });
  } };
  ROOMS.musholah = { width: 460, theme: theme('#f4f1e8', '#2f8f4e', '#d8ccb0', '#cfc2a4', [200]), build: (sc) => {
    sc.items.push({ k: 'wudhu', x: 100, act: 'note', label: 'Tempat Wudhu' }, { k: 'banner', x: 200, t: 'MOHON LEPAS ALAS KAKI', c: '#1f6b3a' }, { k: 'mats', x: 190, n: 6 }, { k: 'quran', x: 236, act: 'note', label: 'Rak Al-Qur\'an' }, { k: 'mihrab', x: 340 }); } };
  ROOMS.pos = { width: 280, theme: theme('#e6e0d4', '#2c3e50', DARKT[0], DARKT[1], [220]), build: (sc) => {
    sc.items.push({ k: 'cctv', x: 110, act: 'cctv', label: 'Monitor CCTV', w: 50 }, { k: 'keys', x: 180 }, { k: 'dispenser', x: 230 });
    sc.npcs.push(npc('Pak Budi (Satpam)', D.satpam, ['Selamat datang di SMK Silber!', 'Gerbang buka 06.00. Tetap tertib ya.'], 196)); } };
  ROOMS.kantin = { width: 440, theme: theme('#f6e7cf', '#c27a3c', TILE[0], TILE[1], [400]), build: (sc) => {
    sc.items.push({ k: 'stalls', x: 104, n: 3, act: 'counter', label: 'Etalase Kantin', w: 200 }, { k: 'tables', x: 318, n: 2 });
    sc.npcs.push(npc('Bu Iyah (Kantin)', D.kantin, ['Mau makan apa, Nak? Nasi, bakso, atau es teh?'], 180)); } };

  /* ======================= Gambar per frame ======================= */
  PW.prototype.draw = function () {
    const sc = this.scene;
    if (!sc) return;
    const v = this.view, g = this.bctx, G = v.h - GPAD, p = this.player;
    let target = p.x - v.w / 2 + p.facing * 24;
    if (sc.width <= v.w) target = -(v.w - sc.width) / 2; else target = clamp(target, 0, sc.width - v.w);
    this.camX = (this.camX == null || this.settings.reducedMotion) ? target : this.camX + (target - this.camX) * 0.12;
    const camX = Math.round(this.camX);
    g.imageSmoothingEnabled = false;
    if (sc.kind === 'outdoor') this.drawSky(g, camX); else R(g, 0, 0, v.w, v.h, '#140d1d');
    const i0 = Math.floor(Math.max(0, camX) / CW), i1 = Math.floor((camX + v.w) / CW);
    for (let i = i0; i <= i1; i++) { if (i * CW < sc.width) g.drawImage(this.getChunk(sc, i), i * CW - camX, 0); }
    this.drawDynamic(g, camX, G);
    // karakter: NPC → pemain lain (scene sama) → pemain
    sc.npcs.forEach((n) => { if (n.x - camX > -30 && n.x - camX < v.w + 30) this.blit(g, this.sheetFor(n.design, 'npc:' + n.name), n.x - camX, G, 0, Math.sin(this.clock * 1.6 + n.x) > 0.96 ? 0 : 1, true, this.charAnim('', 0, true, n.x)); });
    const area = this.currentAreaId();
    this.remotes.forEach((r) => {
      if ((r.area || 'OUT') !== area || r.rx == null) return;
      const sx = r.rx - camX; if (sx < -30 || sx > v.w + 30) return;
      if (r.sit) this.blitSeated(g, this.remoteSheet(r), sx, G);
      else this.blit(g, this.remoteSheet(r), sx, G, r.walkT ? 1 + Math.floor(r.walkT) % 4 : 0, r.face || 1, !r.walkT && r.idleT > 1.5,
        this.charAnim(r.emoteT > 0 ? r.emote : '', r.emoteStart, !r.walkT, r.rx));
    });
    if (this.onDrawWorld) { try { this.onDrawWorld(g, camX, G); } catch (e) { this.onDrawWorld = null; } } // anomaly.js
    if (this.drawHooks) this.drawHooks.forEach((fn, i) => { try { fn(g, camX, G); } catch (e) { this.drawHooks[i] = function () {}; } }); // world-life.js dll.
    if (p.sitting) this.blitSeated(g, this.sheet, p.x - camX, G);
    else this.blit(g, this.sheet, p.x - camX, G, p.moving ? 1 + Math.floor(p.animT) % 4 : 0, p.facing, !p.moving && (p.frontT > 0 || p.idleT > 1.6) || (p.emoteT > 0 && !!p.emoteName),
      this.charAnim(p.emoteT > 0 ? p.emoteName : '', p.emoteStart, !p.moving, 0));
    this.drawForeground(g, camX, G);
    if (this.navWp && this.navWp.x != null && !this.navWp.arrive) {
      const sx = this.navWp.x - camX, bob = this.settings.reducedMotion ? 0 : Math.round(Math.sin(this.clock * 6) * 2);
      if (sx > 4 && sx < v.w - 4) { R(g, sx - 4, G - 74 + bob, 9, 3, '#ffcf4a'); R(g, sx - 3, G - 71 + bob, 7, 2, '#ffcf4a'); R(g, sx - 1, G - 69 + bob, 3, 2, '#ffcf4a'); }
    }
    // tampilkan ke kanvas layar (tajam)
    const ctx = this.ctx;
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(this.buf, 0, 0, v.w, v.h, 0, 0, Math.round(v.w * v.s), Math.round(v.h * v.s));
    this.drawOverlay(ctx, camX, G);
  };

  /**
   * Gerak hidup karakter (tidak kaku): emote → lompat/joget/bungkuk… (PixelAvatar.emoteAnim);
   * diam → napas 1 px bergantian. `seed` membuat pemain lain tidak bernapas serempak.
   */
  PW.prototype.charAnim = function (emote, start, idle, seed) {
    if (this.settings.reducedMotion) return null;
    const PA = window.PixelAvatar;
    if (emote && PA && PA.emoteAnim) return PA.emoteAnim(emote, Math.max(0, this.clock - (start || 0)));
    if (idle) return { dx: 0, dy: Math.floor(this.clock * 1.4 + (seed || 0) * 0.37) % 2, rot: 0, sy: 1 };
    return null;
  };

  PW.prototype.blit = function (g, sheet, sx, G, frame, facing, front, a) {
    if (!sheet) return;
    const row = front ? 1 : (facing < 0 ? 0 : 2);
    const SW = window.PixelAvatar ? window.PixelAvatar.W : 22, SH = window.PixelAvatar ? window.PixelAvatar.H : 38;
    const lift = a ? Math.min(0, a.dy) : 0;
    const shw = Math.max(6, 17 + Math.round(lift / 1.5)); // bayangan mengecil saat melompat
    R(g, Math.round(sx - shw / 2), G - 2, shw, 2, 'rgba(0,0,0,0.35)');
    if (!a || (!a.rot && a.sy === 1)) {
      g.drawImage(sheet, frame * SW, row * SH, SW, SH, Math.round(sx - SW / 2 + (a ? a.dx : 0)), G - SH + 2 + Math.round(a ? a.dy : 0), SW, SH);
      return;
    }
    // Putar / regang di sekitar kaki agar tetap berpijak.
    g.save();
    g.translate(Math.round(sx + a.dx), G + 2 + Math.round(a.dy));
    if (a.rot) g.rotate(a.rot * (facing < 0 ? -1 : 1));
    if (a.sy !== 1) g.scale(1, a.sy);
    g.drawImage(sheet, frame * SW, row * SH, SW, SH, -Math.round(SW / 2), -SH, SW, SH);
    g.restore();
  };

  /** Pose duduk menghadap depan: badan atas di dudukan kursi (G-10), kaki menjuntai ke depan. */
  PW.prototype.blitSeated = function (g, sheet, sx, G) {
    if (!sheet) return;
    const SW = window.PixelAvatar ? window.PixelAvatar.W : 22, SH = window.PixelAvatar ? window.PixelAvatar.H : 38;
    const cut = SH - 11, top = G - 10 - cut + 3, x0 = Math.round(sx - SW / 2);
    R(g, sx - 8, G - 2, 17, 2, 'rgba(0,0,0,0.35)');
    g.drawImage(sheet, 0, SH, SW, cut, x0, top, SW, cut);       // baris 1 = hadap depan
    // paha & betis (warna diambil dari bagian bawah badan sprite agar sesuai celana/rok)
    g.drawImage(sheet, 0, SH + cut - 3, SW, 3, x0, top + cut, SW, 3);
    R(g, Math.round(sx) - 4, G - 8, 3, 7, '#2b2f3a'); R(g, Math.round(sx) + 1, G - 8, 3, 7, '#2b2f3a');
    R(g, Math.round(sx) - 5, G - 2, 4, 2, '#151515'); R(g, Math.round(sx) + 1, G - 2, 4, 2, '#151515');
  };

  PW.prototype.drawDynamic = function (g, camX, G) {
    const sc = this.scene, t = this.settings.reducedMotion ? 0 : this.clock;
    if (sc.kind === 'outdoor') {
      sc.zones.forEach((z) => {
        const cx = z.x + z.w / 2 - camX;
        if (cx < -120 || cx > this.view.w + 120) return;
        if (z.type === 'fountain') {
          for (let i = 0; i < 10; i++) {
            const ph = (t * 1.4 + i / 10) % 1, dx = (i - 4.5) * 3.2 * ph, dy = -10 * Math.sin(ph * Math.PI);
            R(g, cx + dx, G - 56 + dy + ph * 16, 2, 2, i % 2 ? '#bfeaff' : '#7fd3ff');
          }
          R(g, cx - 1, G - 64 + (Math.sin(t * 8) > 0 ? 0 : 1), 2, 8, '#bfeaff');
        }
        if (z.type === 'pool') for (let i = 0; i < 6; i++) R(g, z.x + 20 - camX + ((i * 37 + t * 14) % (z.w - 44)), G - 14, 6, 1, '#d9f4ff');
        if (z.type === 'futsal' && this.goalFlash && this.clock - this.goalFlash < 0.6) R(g, cx - 30, G - 96, 60, 2, '#ffcf4a');
      });
      if (this.ball && !this.ball.hidden) {
        const b = this.ball, bx = Math.round(b.x - camX), by = Math.round(G - 6 - b.y);
        R(g, bx - 4, G - 1, 9, 1, 'rgba(0,0,0,0.35)');
        R(g, bx - 3, by - 3, 7, 7, '#f5f5f5'); R(g, bx - 4, by - 2, 9, 5, '#f5f5f5');
        const k = Math.floor(b.rot || 0) % 2;
        R(g, bx - 1 + k, by - 1, 2, 2, '#1c1c1c'); R(g, bx + 2 - k, by + 1, 1, 1, '#1c1c1c');
      }
    } else if (sc.items) {
      sc.items.forEach((it) => {
        if (it.k !== 'racks') return;
        for (let i = 0; i < it.n; i++) for (let u = 0; u < 8; u++) {
          const on = Math.sin(t * 5 + u * 1.7 + i * 3) > 0;
          R(g, it.x + i * 24 + 5 - camX, G - 64 + u * 8, 1, 1, on ? '#7dff9a' : '#1f4a2a');
          R(g, it.x + i * 24 + 8 - camX, G - 64 + u * 8, 1, 1, (on && u % 3 === 0) ? '#ffb347' : '#3a2a1a');
        }
      });
    }
  };

  PW.prototype.drawForeground = function (g, camX, G) {
    const sc = this.scene;
    if (sc.kind !== 'outdoor') return;
    // semak di depan trotoar (parallax 1.15) memberi kedalaman tanpa menutupi pemain
    for (let x = Math.floor(camX * 1.15 / 140) * 140 - 140; x < camX * 1.15 + this.view.w + 140; x += 140) {
      const sx = x - camX * 1.15;
      R(g, sx, G + 25, 34, 9, '#2f6430'); R(g, sx + 4, G + 21, 12, 6, '#3f7d3a'); R(g, sx + 18, G + 22, 12, 5, '#3f7d3a');
    }
  };

  /* ---------- Teks & HUD (resolusi layar → papan nama terbaca) ---------- */
  function text(ctx, s, x, y, size, color, align, shadow) {
    ctx.font = '700 ' + Math.round(size) + 'px "Pixelify Sans", ui-monospace, monospace';
    ctx.textAlign = align || 'center'; ctx.textBaseline = 'middle';
    if (shadow !== false) { ctx.fillStyle = 'rgba(0,0,0,0.75)'; ctx.fillText(s, x + Math.max(1, size / 12), y + Math.max(1, size / 12)); }
    ctx.fillStyle = color; ctx.fillText(s, x, y);
  }
  function box(ctx, x, y, w, h, bg, border) { ctx.fillStyle = border || '#20130a'; ctx.fillRect(x - 3, y - 3, w + 6, h + 6); ctx.fillStyle = bg; ctx.fillRect(x, y, w, h); }

  PW.prototype.drawOverlay = function (ctx, camX, G) {
    const v = this.view, S = v.s, sc = this.scene, p = this.player;
    const sxOf = (wx) => (wx - camX) * S, syOf = (wy) => wy * S;
    const fs = (n) => Math.max(11 * v.dpr, n * S);
    if (sc.labelMap) sc.labelMap.forEach((L) => {
      const sx = sxOf(L.x);
      if (sx < -200 || sx > v.Wd + 200) return;
      text(ctx, L.text, sx, syOf(G + L.dy), L.size * S, L.color, 'center', false);
    });
    const tag = (wx, s, col, lift) => { const sx = sxOf(wx), sy = syOf(G - 44 - (lift || 0)); ctx.font = '700 ' + Math.round(fs(6)) + 'px "Pixelify Sans", monospace'; const w = ctx.measureText(s).width + 10 * v.dpr; box(ctx, sx - w / 2, sy - fs(6) * 0.7, w, fs(6) * 1.4, 'rgba(32,19,10,0.82)'); text(ctx, s, sx, sy, fs(6), col || '#fff6e0'); };
    sc.npcs.forEach((n) => { if (Math.abs(n.x - p.x) < 90) tag(n.x, n.name, '#ffcf4a'); });
    const area = this.currentAreaId();
    this.remotes.forEach((r) => { if ((r.area || 'OUT') === area && r.rx != null) { tag(r.rx, r.name || 'Pemain', '#9fe0ff', Math.abs(r.rx - p.x) < 44 ? 11 : 0); /* label tidak menumpuk */ if (r.emote && r.emoteT > 0) text(ctx, EMO(r.emote), sxOf(r.rx), syOf(G - 56), fs(10), '#fff', 'center', false); } });
    tag(p.x, p.name || 'Kamu', '#fff6e0');
    if (p.emote) text(ctx, p.emote, sxOf(p.x), syOf(G - 58 - Math.min(6, Math.max(0, (p.emoteT || 0) - 1.6) * 12)), fs(11), '#fff', 'center', false); // emoji naik sedikit saat muncul
    // ajakan interaksi
    const t = p.sitting ? { kind: 'stand', x: p.x } : this.nearTarget;
    if (t && !this.trans) {
      const coarse = this.actionBtn && getComputedStyle(this.actionBtn).display !== 'none';
      const key = coarse ? '✋' : 'E';
      let msg = this.verbFor(t);
      if (t.kind === 'door') msg = (t.exit ? 'Keluar' : 'Masuk') + (t.name ? ' — ' + t.name : '');
      else if (t.kind === 'stairs') msg = (t.dir > 0 ? 'Naik ke ' : 'Turun ke ') + t.name + (coarse ? '' : (t.dir > 0 ? '  (W/↑)' : '  (S/↓)'));
      else if (t.kind === 'sign') msg = 'Info lokasi — ' + t.loc.name;
      else if (t.kind === 'furniture') msg = t.label || 'Gunakan';
      else if (t.kind === 'npc') msg = 'Bicara — ' + t.npc.name;
      else if (t.kind === 'ball') msg = 'Tendang bola';
      else if (t.kind === 'custom') msg = t.label || 'Gunakan';
      else if (t.kind === 'seat') msg = 'Duduk' + (coarse ? '' : '  (E / S↓)');
      else if (t.kind === 'stand') msg = 'Berdiri' + (coarse ? '' : '  (E / W↑ / A D)');
      const s = '[' + key + '] ' + msg;
      ctx.font = '700 ' + Math.round(fs(7)) + 'px "Pixelify Sans", monospace';
      const w = ctx.measureText(s).width + 16 * v.dpr, h = fs(7) * 1.6;
      let sx = clamp(sxOf(t.x) - w / 2, 8, v.Wd - w - 8);
      const sy = syOf(G - (t.kind === 'door' || t.kind === 'stairs' ? 84 : 70));
      box(ctx, sx, sy, w, h, '#fff1d0', '#20130a');
      text(ctx, s, sx + w / 2, sy + h / 2, fs(7), '#20130a', 'center', false);
    }
    // navigasi
    if (this.navTarget && this.navWp) {
      const wp = this.navWp;
      const dist = Math.round(Math.abs(wp.x - p.x) / 10);
      const dirArrow = wp.arrive ? '🎯' : (wp.x < p.x ? '◀' : '▶');
      const s = '🧭 ' + this.navTarget.loc.name + '  ·  ' + (wp.arrive ? (dist <= 3 ? 'Sudah sampai' : dirArrow + ' ' + dist + ' m') : dirArrow + ' ' + wp.label + ' (' + dist + ' m)');
      ctx.font = '700 ' + Math.round(fs(7)) + 'px "Pixelify Sans", monospace';
      const w = Math.min(v.Wd - 20, ctx.measureText(s).width + 20 * v.dpr), h = fs(7) * 1.7;
      const y = Math.round(v.Hd * 0.14);
      box(ctx, (v.Wd - w) / 2, y, w, h, 'rgba(34,23,43,0.92)', '#8a5a2b');
      text(ctx, s, v.Wd / 2, y + h / 2, fs(7), '#ffcf4a');
      if (!wp.arrive) {
        const sx = sxOf(wp.x);
        if (sx < 0) text(ctx, '◀', 24 * v.dpr, syOf(G - 60), fs(14), '#ffcf4a');
        else if (sx > v.Wd) text(ctx, '▶', v.Wd - 24 * v.dpr, syOf(G - 60), fs(14), '#ffcf4a');
      }
    }
    if (this.banner) {
      const a = Math.min(1, this.banner.t / 0.5);
      ctx.globalAlpha = a;
      ctx.font = '700 ' + Math.round(fs(10)) + 'px "Pixelify Sans", monospace';
      const w1 = ctx.measureText(this.banner.title).width;
      ctx.font = '700 ' + Math.round(fs(6)) + 'px "Pixelify Sans", monospace';
      const w2 = Math.min(v.Wd - 40, ctx.measureText(this.banner.sub).width);
      const w = Math.min(v.Wd - 20, Math.max(w1, w2) + 30 * v.dpr), h = fs(10) * 1.3 + fs(6) * 1.8;
      const y = Math.round(v.Hd * 0.24);
      box(ctx, (v.Wd - w) / 2, y, w, h, 'rgba(34,23,43,0.9)', '#8a5a2b');
      text(ctx, this.banner.title, v.Wd / 2, y + fs(10) * 0.8, fs(10), '#ffcf4a');
      text(ctx, this.banner.sub.length > 90 ? this.banner.sub.slice(0, 88) + '…' : this.banner.sub, v.Wd / 2, y + fs(10) * 1.3 + fs(6) * 0.8, fs(6), '#fff1d0');
      ctx.globalAlpha = 1;
    }
    if (this.trans) {
      const k = this.trans.t / this.trans.dur;
      ctx.globalAlpha = k < 0.5 ? k * 2 : (1 - k) * 2;
      ctx.fillStyle = '#0a0610'; ctx.fillRect(0, 0, v.Wd, v.Hd);
      ctx.globalAlpha = 1;
    }
  };
  function EMO(name) { const all = window.PixelAvatar ? window.PixelAvatar.EMOTES : {}; return all[name] || ({ wave: '👋', point: '👉', clap: '👏', cheer: '🎉', sit: '🪑' })[name] || '✨'; }
})();
