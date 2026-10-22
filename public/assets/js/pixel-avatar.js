/*
 * PixelAvatar v2 — satu-satunya penggambar karakter pixel (22×42 px termasuk garis tepi & ruang topi).
 * Dipakai dashboard (/ & /admin), Dunia Pixel (/pixel) dan dinormalisasi juga oleh Dunia 3D (world3d.js)
 * → karakter pemain identik di semua tempat.
 *
 * Format v2 (disimpan server: user_profiles.avatar_config, lihat VC\Domain\AvatarStyle):
 *   { v:2, skin, hairStyle, hair, outfit, top, bottom, shoes, acc:[…≤3], accColor, emotes:[…≤8], background, story }
 * Pakaian mengikuti referensi /var/www/assets/custom-assets (seragam TJKT, putih-abu, batik, jas lab, koki, kerja…).
 * Frame: 0 diam, 1..4 jalan. look: -1 kiri, 0 depan, 1 kanan. ES2017 tanpa ?. / ??.
 */
(function () {
  'use strict';
  var W = 22, H = 42, OX = 1, OY = 4, OUTLINE = '#20130a';
  var KEY = 'vc_story_char_v2';

  var OUTFITS = {
    tjkt: 'Seragam TJKT', putih_abu: 'Putih Abu-abu', pramuka: 'Pramuka', olahraga: 'Kaos Olahraga', hoodie: 'Hoodie',
    almamater: 'Jas Almamater', batik: 'Batik', jas_lab: 'Jas Lab', koki: 'Seragam Koki', kerja: 'Kemeja Kerja + Apron', casual: 'Kasual',
    maid: 'Baju Pelayan', dress: 'Gaun', sailor: 'Seragam Pelaut', gakuran: 'Gakuran', armor: 'Zirah', kimono: 'Kimono / Hakama', robe: 'Jubah Penyihir', tunic: 'Tunik Viking'
  };
  var HAIRS = { short: 'Pendek', spiky: 'Jabrik', messy: 'Acak', curly: 'Keriting', long: 'Panjang', ponytail: 'Kuncir', bun: 'Sanggul', hijab: 'Hijab',
    bob: 'Bob', twintail: 'Kuncir Dua', braids: 'Kepang Dua', topknot: 'Cepol Samurai' };
  var ACCESSORIES = { backpack: '🎒 Tas Ransel', glasses: '👓 Kacamata', cap: '🧢 Topi', lanyard: '🪪 Lanyard ID', headphones: '🎧 Headphone', tie: '👔 Dasi', chef_hat: '👨‍🍳 Topi Koki', watch: '⌚ Jam Tangan',
    sword: '⚔️ Pedang', witch_hat: '🧙 Topi Penyihir', ribbon: '🎀 Pita' };
  // 18 emote (17 bisa dipakai di 3D, Pixel & dashboard; 'sit' khusus Pixel). Animasi per engine
  // memakai nama yang sama (lihat EMOTE_ANIM). Favorit di penyunting maks. 8.
  var EMOTES = { wave: '👋', point: '👉', clap: '👏', cheer: '🎉', sit: '🪑', love: '❤️', laugh: '😂', think: '🤔', ok: '👌', cool: '😎',
    dance: '💃', salute: '🎖️', bow: '🙇', sad: '😢', angry: '😠', sleep: '😴', shock: '😱', hype: '🙌' };
  var EMOTE_LABEL = { wave: 'Lambai', point: 'Tunjuk', clap: 'Tepuk', cheer: 'Sorak', sit: 'Duduk', love: 'Suka', laugh: 'Tawa', think: 'Mikir', ok: 'Oke', cool: 'Keren',
    dance: 'Joget', salute: 'Hormat', bow: 'Membungkuk', sad: 'Sedih', angry: 'Marah', sleep: 'Ngantuk', shock: 'Kaget', hype: 'Semangat' };
  /**
   * Gerak emote 2D (Pixel & dashboard): offset dalam piksel sprite pada waktu t (detik sejak mulai).
   * → { dx, dy, rot (radian), sy (skala tinggi) }. 3D punya animasi tulangnya sendiri (world3d.js).
   */
  function emoteAnim(name, t) {
    var a = { dx: 0, dy: 0, rot: 0, sy: 1 }, s = Math.sin;
    switch (name) {
      case 'cheer': case 'hype': a.dy = -Math.abs(s(t * 7)) * 6; break;
      case 'dance': a.dx = s(t * 9) * 2.5; a.rot = s(t * 9) * 0.16; a.dy = -Math.abs(s(t * 18)) * 1.5; break;
      case 'laugh': a.dy = -Math.abs(s(t * 20)) * 1.5; a.rot = s(t * 10) * 0.05; break;
      case 'angry': a.dx = s(t * 45) * 1.2; a.dy = -Math.abs(s(t * 6)) * 1; break;
      case 'shock': a.dy = t < 0.25 ? -t * 24 : Math.max(-6, -6 + (t - 0.25) * 20); a.sy = 1.04; break;
      case 'bow': a.rot = Math.min(1, t * 4) * 0.32 * (t < 1.8 ? 1 : Math.max(0, (2.4 - t) / 0.6)); break;
      case 'sad': a.sy = 0.95; a.dy = 1; a.rot = 0.06; break;
      case 'sleep': a.rot = 0.1 + s(t * 2) * 0.04; a.sy = 0.97 + s(t * 2) * 0.02; break;
      case 'love': case 'clap': case 'wave': case 'ok': case 'cool': case 'point': case 'salute': case 'think':
        a.dy = -Math.abs(s(t * 6)) * 2; break;
    }
    return a;
  }
  var BACKGROUNDS = { bengkel: 'Bengkel TJKT', perpustakaan: 'Perpustakaan', lab: 'Laboratorium', studio: 'Studio DKV', dapur: 'Dapur Tata Boga', kelas: 'Ruang Kelas', taman: 'Taman Sekolah', kota: 'Kota Malam' };
  var DEFAULT = { v: 2, skin: '#e2ad84', hairStyle: 'short', hair: '#2b1d16', outfit: 'tjkt', top: '#1d4ed8', bottom: '#1e3a8a', shoes: '#111827',
    acc: ['backpack'], accColor: '#2f8f4e', emotes: ['wave', 'point', 'clap', 'cheer', 'sit'], background: 'bengkel', story: '' };

  function shade(hex, f) {
    var n = parseInt(String(hex).replace('#', ''), 16);
    if (isNaN(n)) return hex;
    var r = Math.min(255, Math.round(((n >> 16) & 255) * f));
    var g = Math.min(255, Math.round(((n >> 8) & 255) * f));
    var b = Math.min(255, Math.round((n & 255) * f));
    return 'rgb(' + r + ',' + g + ',' + b + ')';
  }
  function isHex(v) { return typeof v === 'string' && /^#[0-9a-fA-F]{6}$/.test(v); }
  function toHex(v) {
    if (isHex(v)) return v;
    if (typeof v === 'number') return '#' + ('000000' + v.toString(16)).slice(-6);
    return null;
  }
  function assign(base, extra) { var o = {}, k; for (k in base) o[k] = base[k]; for (k in extra) if (extra[k] !== undefined && extra[k] !== null) o[k] = extra[k]; return o; }

  /** Terima v2, desain karakter cerita, atau format 3D lama → v2. */
  function normalize(c) {
    if (!c) return assign(DEFAULT, {});
    if (c.config && typeof c.config === 'object') c = c.config;
    if (c.v === 2) {
      var o = assign(DEFAULT, c);
      o.acc = Array.isArray(c.acc) ? c.acc.filter(function (a) { return ACCESSORIES[a]; }).slice(0, 3) : DEFAULT.acc.slice();
      o.emotes = Array.isArray(c.emotes) && c.emotes.length ? c.emotes.filter(function (e) { return EMOTES[e]; }).slice(0, 8) : DEFAULT.emotes.slice();
      if (!OUTFITS[o.outfit]) o.outfit = 'tjkt';
      if (!HAIRS[o.hairStyle]) o.hairStyle = 'short';
      return o;
    }
    if (c.top || c.hairStyle) { // desain karakter cerita
      return assign(DEFAULT, { skin: c.skin, hair: c.hair, hairStyle: HAIRS[c.hairStyle] ? c.hairStyle : 'short', outfit: 'casual',
        top: c.top, bottom: c.bottom, shoes: c.shoes, accColor: c.accent, acc: [] });
    }
    var hairMap = { neat_short: 'short', undercut: 'spiky', ponytail: 'ponytail', hijab: 'hijab' };
    var outMap = { casual: 'casual', blazer: 'almamater', cyber_hoodie: 'hoodie', tech_jacket: 'hoodie', creative_vest: 'casual', varsity: 'olahraga' };
    var accMap = { tie: 'tie', headphones: 'headphones', glasses: 'glasses', backpack: 'backpack', cyber_visor: 'glasses' };
    return assign(DEFAULT, {
      skin: toHex(c.skin_tone) || undefined, hair: toHex(c.hair_color) || undefined, hairStyle: hairMap[c.hair] || undefined,
      outfit: outMap[c.outfit] || undefined, top: toHex(c.outfit_color) || undefined, bottom: toHex(c.pants_color) || undefined,
      shoes: toHex(c.shoes_color) || undefined, acc: accMap[c.accessory] ? [accMap[c.accessory]] : []
    });
  }

  function has(d, a) { return d.acc && d.acc.indexOf(a) >= 0; }

  /** Gambar satu frame ke ctx pada (ox, oy). d = konfigurasi (format apa pun). */
  function draw(ctx, ox, oy, raw, opt) {
    opt = opt || {};
    var d = normalize(raw);
    var frame = opt.frame || 0, look = opt.look || 0, blink = !!opt.blink;
    var grid = [];
    for (var i = 0; i < W * H; i++) grid.push(null);
    var bob = (frame === 2 || frame === 4) ? 1 : 0;
    function px(x, y, c) { x += OX; y += OY; if (x >= 0 && x < W && y >= 0 && y < H) grid[y * W + x] = c; }
    function rect(x, y, w, h, c) { for (var yy = y; yy < y + h; yy++) for (var xx = x; xx < x + w; xx++) px(xx, yy, c); }
    function up(x, y, w, h, c) { rect(x, y + bob, w, h, c); }

    var hs = d.hairStyle, of = d.outfit;
    var hair = d.hair, hairD = shade(hair, 0.75);
    var skin = d.skin, skinD = shade(skin, 0.85);
    var top = d.top, bot = d.bottom, shoes = d.shoes, acc = d.accColor;
    // warna tetap per seragam (seperti referensi custom-assets)
    if (of === 'tjkt') { top = '#1d4ed8'; bot = '#1e3a8a'; }
    else if (of === 'putih_abu') { top = '#f5f5f4'; bot = '#6b7280'; }
    else if (of === 'pramuka') { top = '#b08d57'; bot = '#4a3726'; }
    else if (of === 'almamater') { top = '#1e3a8a'; }
    else if (of === 'jas_lab' || of === 'koki') { top = '#f8fafc'; }
    else if (of === 'kerja') { top = '#d6c7a1'; }
    else if (of === 'sailor') { top = '#f8fafc'; }
    else if (of === 'gakuran') { top = '#1d1d28'; bot = '#1d1d28'; }
    var topD = shade(top, 0.8), botD = shade(bot, 0.75);

    // pedang di punggung (di belakang badan)
    if (has(d, 'sword')) { up(15, 1, 2, 22, '#9aa3ad'); up(16, 1, 1, 22, '#d9dee4'); up(14, 9, 4, 1, '#5b3a1e'); up(15, 6, 2, 3, '#3a2414'); }

    // tas ransel di belakang badan (mengintip di samping lengan)
    if (has(d, 'backpack')) { up(2, 11, 16, 12, shade(acc, 0.85)); up(2, 11, 16, 1, shade(acc, 1.2)); up(7, 9, 6, 2, shade(acc, 0.7)); }

    // kaki & sepatu (berjalan)
    var lLift = frame === 1 ? 2 : 0, rLift = frame === 3 ? 2 : 0;
    rect(6, 21, 4, 11 - lLift, bot);
    rect(10, 21, 4, 11 - rLift, botD);
    rect(9, 23, 1, 9 - Math.max(lLift, rLift), shade(bot, 0.55));
    var shoeC = of === 'olahraga' ? '#f5f5f5' : shoes;
    rect(5, 32 - lLift, 5, 3, shoeC); rect(10, 32 - rLift, 5, 3, shoeC);
    rect(5, 32 - lLift, 5, 1, shade(shoeC, of === 'olahraga' ? 0.85 : 1.8)); rect(10, 32 - rLift, 5, 1, shade(shoeC, of === 'olahraga' ? 0.85 : 1.8));

    if (hs === 'long') up(4, 4, 12, 13, hairD);
    if (hs === 'braids') { up(3, 5, 2, 13, hair); up(15, 5, 2, 13, hair); for (var bi = 6; bi < 18; bi += 2) { up(3, bi, 2, 1, hairD); up(15, bi, 2, 1, hairD); } up(3, 18, 2, 1, acc); up(15, 18, 2, 1, acc); }
    if (hs === 'twintail') { up(0, 2, 3, 3, hair); up(0, 5, 2, 10, hairD); up(17, 2, 3, 3, hair); up(18, 5, 2, 10, hairD); }
    if (hs === 'ponytail') { up(15, 3, 2, 3, hair); up(16, 5, 2, 7, hairD); }
    if (hs === 'hijab') up(3, 3, 14, 12, hairD);
    if (of === 'hoodie') up(4, 8, 12, 4, topD); // tudung di belakang kepala

    // kepala
    up(5, 2, 10, 9, skin);
    up(5, 9, 10, 2, skinD);
    up(8, 10, 4, 1, skinD);

    // badan & lengan
    var coatLong = of === 'jas_lab' || of === 'robe';
    up(5, 11, 10, coatLong ? 15 : 11, top);
    up(12, 11, 3, coatLong ? 15 : 11, topD);
    var la = frame === 1 ? -2 : (frame === 3 ? 2 : 0), ra = -la; // ayunan tangan saat melangkah (lebih jelas)
    var sleeve = top, sleeveD = topD;
    up(3, 12 + la, 2, 8, sleeve); up(15, 12 + ra, 2, 8, sleeveD);
    up(3, 20 + la, 2, 2, skin); up(15, 20 + ra, 2, 2, skinD);
    if (!coatLong) up(5, 20, 10, 1, shade(bot, 0.6));

    // detail pakaian
    if (of === 'tjkt') {
      up(5, 11, 3, 3, '#f5f5f5'); up(12, 11, 3, 3, '#e5e7eb'); up(3, 12 + la, 2, 2, '#f5f5f5'); up(15, 12 + ra, 2, 2, '#e5e7eb');
      up(3, 14 + la, 2, 1, '#2f8f4e'); up(15, 14 + ra, 2, 1, '#2f8f4e');
      up(7, 11, 6, 1, '#2f8f4e'); up(8, 12, 1, 1, '#2f8f4e'); up(11, 12, 1, 1, '#2f8f4e');
      up(9, 12, 2, 8, '#93c5fd'); up(12, 14, 2, 2, '#facc15');
    } else if (of === 'putih_abu') {
      up(7, 11, 6, 1, '#e5e7eb'); up(6, 14, 2, 2, '#d1d5db'); up(12, 14, 1, 1, '#c0392b');
      if (!has(d, 'tie')) { up(9, 12, 2, 7, '#6b7280'); }
    } else if (of === 'pramuka') {
      up(7, 11, 6, 2, '#c0392b'); up(8, 13, 4, 1, '#f5f5f5'); up(9, 14, 2, 2, '#c0392b');
      up(5, 14, 3, 2, shade(top, 0.85)); up(12, 14, 3, 2, shade(top, 0.85));
    } else if (of === 'olahraga') {
      up(5, 11, 1, 10, '#f5f5f5'); up(14, 11, 1, 10, '#f5f5f5'); up(7, 11, 6, 1, shade(top, 0.7)); up(8, 14, 4, 2, '#f5f5f5');
    } else if (of === 'hoodie') {
      up(7, 11, 6, 1, topD); up(8, 12, 1, 3, '#f5f5f5'); up(11, 12, 1, 3, '#f5f5f5'); up(7, 17, 6, 3, topD);
    } else if (of === 'almamater') {
      up(8, 11, 4, 6, '#f5f5f5'); up(7, 11, 1, 7, '#152a66'); up(12, 11, 1, 7, '#152a66'); up(12, 14, 2, 2, '#d9b44a');
    } else if (of === 'batik') {
      for (var by = 11; by < 21; by++) for (var bx = 5; bx < 15; bx++) {
        var k = (bx * 3 + by * 5) % 7;
        if (k === 0) up(bx, by, 1, 1, '#d9b44a'); else if (k === 3) up(bx, by, 1, 1, shade(top, 0.6));
      }
      up(3, 14 + la, 2, 1, '#d9b44a'); up(15, 14 + ra, 2, 1, '#d9b44a');
      up(7, 11, 6, 1, shade(top, 0.6));
    } else if (of === 'jas_lab') {
      up(8, 11, 4, 8, d.top); up(9, 11, 2, 1, shade(d.top, 0.7)); up(6, 19, 3, 2, '#e5e7eb'); up(11, 19, 3, 2, '#e5e7eb');
      up(10, 19, 1, 7, '#e5e7eb');
    } else if (of === 'koki') {
      up(7, 11, 6, 1, '#c0392b'); for (var cy = 13; cy < 20; cy += 2) { up(8, cy, 1, 1, '#9aa1ab'); up(11, cy, 1, 1, '#9aa1ab'); }
      up(5, 20, 10, 1, '#c0392b');
    } else if (of === 'kerja') {
      up(7, 11, 6, 1, shade(top, 0.75)); up(6, 14, 8, 12, '#8a6d3b'); up(6, 14, 8, 1, '#6b5129'); up(9, 18, 2, 2, '#6b5129');
    } else if (of === 'maid') { // gaun gelap + celemek putih + kerah putih
      up(4, 20, 12, 7, top); up(4, 26, 12, 1, topD); up(6, 14, 8, 12, '#f8fafc'); up(6, 14, 8, 1, '#e5e7eb'); up(7, 11, 6, 2, '#f8fafc'); up(9, 13, 2, 1, '#c0392b');
    } else if (of === 'dress') {
      up(4, 20, 12, 7, top); up(4, 26, 12, 1, topD); up(5, 18, 10, 2, acc); up(7, 11, 6, 1, shade(top, 1.15));
    } else if (of === 'sailor') {
      up(4, 11, 12, 3, bot); up(4, 13, 12, 1, '#f8fafc'); up(8, 14, 4, 2, '#c0392b'); up(9, 16, 2, 1, '#c0392b'); up(4, 20, 12, 5, bot); up(5, 24, 10, 1, botD);
    } else if (of === 'gakuran') {
      up(7, 11, 6, 2, '#2b2b38'); for (var gy = 13; gy < 21; gy += 2) up(9, gy, 2, 1, '#d9b44a'); up(3, 19 + la, 2, 1, '#2b2b38'); up(15, 19 + ra, 2, 1, '#2b2b38');
    } else if (of === 'armor') { // zirah: bahu besar, sabuk, pelat dada
      up(2, 10, 5, 3, shade(top, 1.2)); up(13, 10, 5, 3, shade(top, 1.2)); up(6, 13, 8, 1, shade(top, 1.3)); up(6, 16, 8, 1, shade(top, 1.3)); up(5, 19, 10, 2, '#4a3220');
    } else if (of === 'kimono') { // kerah V silang + obi + lengan lebar
      up(8, 11, 2, 5, '#f0e6d0'); up(10, 11, 2, 3, '#f0e6d0'); up(5, 17, 10, 3, acc); up(5, 18, 10, 1, shade(acc, 0.7));
      up(2, 13 + la, 3, 7, top); up(15, 13 + ra, 3, 7, topD);
    } else if (of === 'robe') { // jubah panjang + tepi emas
      up(5, 11, 1, 15, acc); up(14, 11, 1, 15, acc); up(7, 11, 6, 1, acc); up(9, 12, 2, 2, acc);
    } else if (of === 'tunic') { // tunik + kerah bulu + sabuk kulit
      up(5, 11, 10, 2, '#d8c3a0'); up(5, 19, 10, 1, '#5b3a1e'); up(9, 19, 2, 1, '#c9a227'); up(5, 20, 10, 2, shade(top, 0.85));
    } else { // kasual
      up(8, 11, 4, 1, acc); up(9, 12, 2, 3, acc);
    }

    // ---- ciri khas kostum anime (motif) ----
    var mo = d.motif || '';
    if (mo === 'checker') { for (var cy2 = 11; cy2 < 21; cy2 += 2) for (var cx2 = 3 + (cy2 % 4 ? 2 : 0); cx2 < 17; cx2 += 4) up(cx2, cy2, 2, 2, '#1d1d28'); up(8, 11, 4, 9, '#1d1d28'); }
    if (mo === 'triangle') { for (var ty = 13; ty < 21; ty += 3) for (var tx = 3; tx < 17; tx += 3) { up(tx, ty, 2, 1, '#f8fafc'); up(tx, ty - 1, 1, 1, '#f8fafc'); } up(8, 11, 4, 9, '#1d1d28'); }
    if (mo === 'halfsplit') { up(3, 11, 6, 10, '#7a1f2a'); up(3, 12 + la, 2, 8, '#7a1f2a'); for (var hy = 12; hy < 21; hy += 2) for (var hx = 11; hx < 17; hx += 2) up(hx, hy, 1, 1, '#d9b44a'); up(9, 11, 2, 9, '#1d1d28'); }
    if (mo === 'flame') { up(3, 18, 14, 3, '#f2b33a'); up(4, 17, 2, 1, '#d9433b'); up(8, 16, 2, 2, '#d9433b'); up(12, 17, 2, 1, '#d9433b'); up(15, 16, 2, 2, '#d9433b'); up(3, 20, 14, 1, '#d9433b'); up(8, 11, 4, 5, '#1d1d28'); }
    if (mo === 'butterfly') { up(3, 11, 14, 10, '#c9a6e8'); up(3, 17, 14, 4, '#7fc9a0'); up(5, 13, 2, 2, '#f8fafc'); up(13, 14, 2, 2, '#f8fafc'); up(8, 11, 4, 9, '#1d1d28'); }
    if (mo === 'wings') { up(2, 11, 16, 14, '#2f6b4a'); up(5, 11, 10, 10, top); up(8, 14, 4, 3, '#f8fafc'); up(7, 15, 1, 1, '#3b6ac9'); up(12, 15, 1, 1, '#3b6ac9'); }
    if (mo === 'cape') { up(2, 11, 2, 16, acc); up(16, 11, 2, 16, acc); up(3, 11, 14, 1, shade(acc, 0.8)); }
    if (mo === 'prosthetic') { up(3, 12 + la, 2, 10, '#3a3f46'); up(3, 20 + la, 2, 2, '#5b616b'); }
    // aksesoris depan
    if (has(d, 'backpack')) { up(6, 11, 2, 8, shade(acc, 0.7)); up(12, 11, 2, 8, shade(acc, 0.7)); }
    if (has(d, 'tie')) up(9, 12, 2, 8, of === 'putih_abu' ? '#6b7280' : '#c0392b');
    if (has(d, 'lanyard')) { up(8, 11, 1, 4, '#1d4ed8'); up(11, 11, 1, 4, '#1d4ed8'); up(8, 15, 4, 4, '#ffffff'); up(9, 16, 2, 1, '#2c5aa0'); up(9, 17, 1, 1, skinD); }
    if (has(d, 'watch')) { up(3, 19 + la, 2, 1, '#1c1c24'); up(3, 19 + la, 1, 1, '#d9b44a'); }

    // rambut
    if (hs === 'hijab') {
      up(4, 1, 12, 3, hair); up(4, 4, 2, 9, hair); up(14, 4, 2, 9, hair); up(5, 10, 10, 3, hair); up(6, 11, 8, 2, hairD);
    } else {
      up(4, 1, 12, 3, hair); up(4, 4, 2, 3, hair); up(14, 4, 2, 3, hair);
      if (hs === 'spiky') { up(5, 0, 1, 1, hair); up(7, -1, 1, 2, hair); up(10, 0, 1, 1, hair); up(12, -1, 1, 2, hair); up(14, 0, 1, 1, hair); }
      if (hs === 'messy') { up(6, 0, 1, 1, hair); up(9, 0, 1, 1, hair); up(13, 0, 1, 1, hair); up(7, 4, 3, 1, hair); up(12, 4, 1, 1, hair); }
      if (hs === 'short') up(6, 4, 3, 1, hair);
      if (hs === 'curly') { up(4, 0, 12, 1, hair); up(3, 2, 1, 5, hair); up(16, 2, 1, 5, hair); up(5, -1, 2, 1, hair); up(9, -1, 2, 1, hair); up(13, -1, 2, 1, hair); up(6, 4, 1, 1, hair); up(9, 4, 1, 1, hair); up(12, 4, 1, 1, hair); }
      if (hs === 'long') { up(4, 4, 2, 10, hair); up(14, 4, 2, 10, hair); }
      if (hs === 'bun') { up(8, -2, 4, 3, hair); up(9, -3, 2, 1, hairD); }
      if (hs === 'topknot') { up(8, -2, 4, 2, hair); up(9, -3, 2, 1, hairD); up(9, 0, 2, 1, acc); up(4, 4, 2, 6, hair); up(14, 4, 2, 6, hair); }
      if (hs === 'bob') { up(4, 4, 2, 7, hair); up(14, 4, 2, 7, hair); up(5, 4, 4, 1, hair); up(12, 4, 3, 1, hair); up(4, 10, 2, 1, hairD); up(14, 10, 2, 1, hairD); }
      if (hs === 'twintail' || hs === 'braids') up(6, 4, 3, 1, hair);
      up(4, 1, 12, 1, hairD);
    }

    // penutup kepala
    if (has(d, 'cap')) { up(4, 0, 12, 3, acc); up(4, 0, 12, 1, shade(acc, 1.25)); up(3, 3, 14, 1, shade(acc, 0.7)); up(9, 1, 2, 1, '#ffffff'); }
    if (has(d, 'chef_hat')) { up(5, -3, 10, 4, '#ffffff'); up(4, -2, 12, 2, '#ffffff'); up(5, 1, 10, 2, '#e5e7eb'); }
    if (has(d, 'witch_hat')) { up(1, 1, 18, 2, acc); up(1, 2, 18, 1, shade(acc, 0.7)); up(5, -2, 10, 3, acc); up(7, -4, 7, 2, acc); up(10, -6, 4, 2, shade(acc, 0.85)); up(5, 0, 10, 1, '#d9b44a'); }
    if (has(d, 'ribbon')) { up(12, 0, 2, 2, acc); up(15, 0, 2, 2, acc); up(14, 1, 1, 1, shade(acc, 0.7)); }
    if (has(d, 'headphones')) { up(4, 0, 12, 1, '#1c1c24'); up(3, 1, 1, 4, '#1c1c24'); up(16, 1, 1, 4, '#1c1c24'); up(2, 5, 2, 4, acc); up(16, 5, 2, 4, acc); }

    if (mo === 'maidcap') { up(5, 0, 10, 1, '#f8fafc'); up(6, -1, 8, 1, '#f8fafc'); up(7, -1, 1, 1, '#c0392b'); up(12, -1, 1, 1, '#c0392b'); up(13, 3, 2, 1, '#c9d6ff'); up(13, 2, 1, 1, '#c9d6ff'); up(14, 4, 1, 1, '#c9d6ff'); }
    if (mo === 'horns') { up(4, 0, 2, 2, '#1c1c24'); up(14, 0, 2, 2, '#1c1c24'); up(5, -1, 1, 1, '#1c1c24'); up(14, -1, 1, 1, '#1c1c24'); }
    if (mo === 'rose') { up(4, 1, 12, 1, '#d9b44a'); up(13, 0, 3, 2, '#c0392b'); }
    if (mo === 'boar') { up(4, -2, 12, 11, '#7a6a5a'); up(4, -2, 12, 2, '#5a4a3a'); up(3, -3, 2, 3, '#5a4a3a'); up(15, -3, 2, 3, '#5a4a3a'); up(7, 7, 6, 3, '#9a8a7a'); up(8, 8, 1, 1, '#2b2b33'); up(11, 8, 1, 1, '#2b2b33'); up(6, 4, 2, 2, '#1b1020'); up(12, 4, 2, 2, '#1b1020'); }
    // wajah
    var ex = look < 0 ? -1 : (look > 0 ? 1 : 0);
    if (has(d, 'glasses')) { up(6 + ex, 5, 4, 1, '#1c1c24'); up(10 + ex, 5, 4, 1, '#1c1c24'); up(6 + ex, 8, 4, 1, '#1c1c24'); up(10 + ex, 8, 4, 1, '#1c1c24'); up(9 + ex, 6, 2, 1, '#1c1c24'); up(6 + ex, 6, 1, 2, '#1c1c24'); up(13 + ex, 6, 1, 2, '#1c1c24'); }
    if (mo === 'boar') { /* wajah tertutup topeng */ }
    else if (blink) { up(7 + ex, 7, 2, 1, skinD); up(11 + ex, 7, 2, 1, skinD); }
    else {
      up(7 + ex, 6, 2, 2, '#1b1020'); up(11 + ex, 6, 2, 2, '#1b1020');
      up(7 + ex + (ex > 0 ? 1 : 0), 6, 1, 1, '#ffffff'); up(11 + ex + (ex > 0 ? 1 : 0), 6, 1, 1, '#ffffff');
    }
    if (mo !== 'boar') up(9 + ex, 9, 2, 1, shade(skin, 0.7));
    if (mo === 'muzzle') { up(6, 9, 8, 2, '#5b8a3a'); up(6, 9, 8, 1, '#7aa64a'); up(5, 9, 1, 1, '#c0392b'); up(14, 9, 1, 1, '#c0392b'); }
    if (mo === 'scarf') { up(5, 10, 10, 3, '#c0392b'); up(12, 12, 3, 6, '#a02a22'); }
    up(6, 8, 1, 1, shade(skin, 0.93)); up(13, 8, 1, 1, shade(skin, 0.93));

    for (var y = 0; y < H; y++) {
      for (var x = 0; x < W; x++) {
        var c = grid[y * W + x];
        if (c) { ctx.fillStyle = c; ctx.fillRect(ox + x, oy + y, 1, 1); continue; }
        if ((x > 0 && grid[y * W + x - 1]) || (x < W - 1 && grid[y * W + x + 1]) ||
            (y > 0 && grid[(y - 1) * W + x]) || (y < H - 1 && grid[(y + 1) * W + x])) {
          ctx.fillStyle = OUTLINE; ctx.fillRect(ox + x, oy + y, 1, 1);
        }
      }
    }
  }

  /** Lembar sprite: kolom = frame 0..4, baris = look kiri / depan / kanan. */
  function sheet(design) {
    var cv = document.createElement('canvas');
    cv.width = W * 5; cv.height = H * 3;
    var g = cv.getContext('2d'), d = normalize(design);
    for (var row = 0; row < 3; row++) for (var f = 0; f < 5; f++) draw(g, f * W, row * H, d, { frame: f, look: row - 1 });
    return cv;
  }

  /* ---------- Latar belakang kartu karakter (pixel-art 64×64) ---------- */
  function backdrop(ctx, w, h, key) {
    var c = document.createElement('canvas'); c.width = 64; c.height = 64;
    var g = c.getContext('2d');
    function R(x, y, ww, hh, col) { g.fillStyle = col; g.fillRect(x, y, ww, hh); }
    var i;
    if (key === 'perpustakaan') {
      R(0, 0, 64, 64, '#6b4423');
      for (var s = 0; s < 5; s++) { R(2, 4 + s * 11, 60, 9, '#3a2414'); for (i = 0; i < 18; i++) R(3 + i * 3.3, 5 + s * 11 + (i % 3), 2, 8 - (i % 3), ['#c0392b', '#2980b9', '#27ae60', '#f39c12', '#8e44ad', '#ecf0f1'][(i + s) % 6]); }
    } else if (key === 'lab') {
      R(0, 0, 64, 64, '#cfe0ea'); R(0, 44, 64, 20, '#9aa1ab'); R(0, 44, 64, 2, '#e5e7eb');
      for (i = 0; i < 2; i++) R(4, 8 + i * 16, 56, 2, '#7d8794');
      for (i = 0; i < 7; i++) { R(6 + i * 8, 2 + (i % 2) * 16, 4, 6, '#ffffff'); R(6 + i * 8, 5 + (i % 2) * 16, 4, 3, ['#7fd3ff', '#7dff9a', '#ff9ab0', '#ffd36e'][i % 4]); }
      R(46, 30, 14, 10, '#1d3a5c'); R(48, 32, 10, 6, '#7fd3ff');
    } else if (key === 'studio') {
      R(0, 0, 64, 64, '#2d2a3a'); R(0, 46, 64, 18, '#3a3446');
      for (i = 0; i < 12; i++) R(4 + (i % 4) * 7, 6 + Math.floor(i / 4) * 7, 6, 6, ['#e74c3c', '#f1c40f', '#2ecc71', '#3498db', '#9b59b6', '#e67e22'][i % 6]);
      R(38, 8, 22, 16, '#1c1c24'); R(40, 10, 18, 12, '#f0a6ff'); R(46, 14, 6, 4, '#ffcf4a');
    } else if (key === 'dapur') {
      R(0, 0, 64, 64, '#e8eef2'); for (i = 0; i < 64; i += 8) { R(i, 0, 1, 40, '#cfd8dc'); R(0, i, 64, 1, '#cfd8dc'); }
      R(0, 40, 64, 24, '#9aa1ab'); R(6, 30, 24, 10, '#5b6470'); R(10, 26, 8, 4, '#c9d1d9'); R(36, 6, 22, 8, '#c9d1d9'); R(44, 14, 2, 12, '#7d8794'); R(50, 14, 2, 10, '#7d8794');
    } else if (key === 'kelas') {
      R(0, 0, 64, 64, '#efe3c8'); R(0, 48, 64, 16, '#b07a46'); R(8, 6, 48, 22, '#6b6156'); R(9, 7, 46, 20, '#f8f8f4'); R(12, 11, 20, 2, '#2c5aa0'); R(12, 16, 30, 1, '#7d8794'); R(12, 20, 24, 1, '#c0392b');
      for (i = 0; i < 4; i++) R(2 + i * 16, 40, 12, 3, '#8a5a35');
    } else if (key === 'taman') {
      R(0, 0, 64, 40, '#8cc4f0'); R(0, 40, 64, 24, '#5f9e4a'); R(6, 8, 14, 4, '#ffffff'); R(40, 14, 12, 3, '#ffffff');
      for (i = 0; i < 3; i++) { R(6 + i * 22, 26, 3, 14, '#6b4a2f'); R(1 + i * 22, 14, 13, 14, i % 2 ? '#4f9a45' : '#3f7d3a'); }
      for (i = 0; i < 16; i++) R(i * 4, 44 + (i % 3), 2, 2, ['#e85d75', '#f7d046', '#ffffff'][i % 3]);
    } else if (key === 'kota') {
      R(0, 0, 64, 64, '#1b2350'); for (i = 0; i < 12; i++) R((i * 23) % 64, (i * 11) % 30, 1, 1, '#ffffff');
      for (i = 0; i < 6; i++) { var bh = 18 + (i * 13) % 26; R(i * 11, 64 - bh, 10, bh, '#252d5a'); for (var wy = 64 - bh + 3; wy < 60; wy += 4) for (var wx = i * 11 + 2; wx < i * 11 + 9; wx += 3) if ((wx + wy) % 3) R(wx, wy, 1, 2, '#f2c46a'); }
    } else { // bengkel
      R(0, 0, 64, 64, '#7d93a8'); R(4, 6, 56, 28, '#93a8bb');
      for (i = 0; i < 9; i++) { R(8 + i * 6, 10, 2, 12 + (i % 3) * 3, '#3d4a5c'); R(7 + i * 6, 9, 4, 3, i % 2 ? '#c0392b' : '#7d8794'); }
      R(0, 44, 64, 20, '#5b6e80'); R(0, 40, 64, 4, '#b8a07a');
    }
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(c, 0, 0, 64, 64, 0, 0, w, h);
  }

  function selectedId() { try { return localStorage.getItem(KEY) || ''; } catch (e) { return ''; } }
  function setSelected(id) { try { if (id) localStorage.setItem(KEY, id); else localStorage.removeItem(KEY); } catch (e) {} }

  var storiesPromise = null;
  function loadStories() {
    if (!storiesPromise) {
      storiesPromise = fetch('/assets/data/character-stories.json', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) { return (j && j.characters) || []; })
        .catch(function () { return []; });
    }
    return storiesPromise;
  }
  /** Konfigurasi karakter pemain: dari server (window.__AVATAR) → satu sumber di semua dunia. */
  function currentDesign() {
    if (window.__AVATAR && window.__AVATAR.config) return Promise.resolve(normalize(window.__AVATAR.config));
    var id = selectedId();
    return loadStories().then(function (list) {
      for (var i = 0; i < list.length; i++) if (list[i].id === id && list[i].design) return normalize(list[i].design);
      return normalize(null);
    });
  }

  window.PixelAvatar = {
    W: W, H: H, KEY: KEY, DEFAULT_DESIGN: DEFAULT, DEFAULT: DEFAULT,
    OUTFITS: OUTFITS, HAIRS: HAIRS, ACCESSORIES: ACCESSORIES, EMOTES: EMOTES, EMOTE_LABEL: EMOTE_LABEL, BACKGROUNDS: BACKGROUNDS, emoteAnim: emoteAnim,
    shade: shade, normalize: normalize, draw: draw, sheet: sheet, backdrop: backdrop,
    selectedId: selectedId, setSelected: setSelected, loadStories: loadStories, currentDesign: currentDesign,
    /** Gambar frame diam ke kanvas (ukuran W×H). */
    paint: function (cv, design, blink) {
      var g = cv.getContext('2d');
      g.clearRect(0, 0, cv.width, cv.height);
      draw(g, 0, 0, design, { blink: blink });
    }
  };
})();
