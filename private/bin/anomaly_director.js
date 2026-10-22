/*
 * Anomaly Director — AI anomali server-authoritative untuk Dunia 3D & Pixel (Virtual City SMK Silber).
 *
 * Satu director per room (serverId + world). Klien hanya MENGGAMBAR; keputusan (siapa, kapan, di mana,
 * aksi apa) dibuat di sini sehingga semua pemain di room melihat kejadian yang sama.
 *
 *  - Arketipe data-driven (ARCH): pengamat, pengembara, peniru, pengejar, pengubah suasana — parameter
 *    curiosity / aggression / patience / visibility / territory / pursuit / cooldown.
 *  - Utility AI: tiap tick (1 dtk) aksi dipilih dari skor konteks (jarak target, pemain sendirian,
 *    intensitas terakhir, lama sejak kejadian, sudah terlihat atau belum) + sedikit acak (bukan urutan tetap).
 *  - FSM eksekusi: observe → reposition / follow / wander / pursue / distort → vanish; memori per room
 *    (titik yang pernah dipakai, target terakhir, intensitas).
 *  - Batas & keadilan: maks. 1 anomali aktif per room, 1 kejar / 3 menit, pengejar LEBIH LAMBAT dari pemain
 *    jalan (8 vs 18 u/dtk) & berhenti setelah 8 dtk / jarak > 55 / target keluar; "tertangkap" hanya jeritan,
 *    tanpa penalti progres. Tidak memakai data akun selain id & posisi di game.
 *  - Hanya malam (WIB 18.00–05.00) kecuali dipicu ROOT/pemilik ('anomaly_debug') untuk uji.
 *  - Pemulihan: state di memori; restart server = bersih (tak ada kejar macet). Pemain baru → 'anomaly' op sync.
 */
'use strict';

const ARCH = {
  watcher:   { label: 'Pengamat',   kinds: ['bayangan', 'mata'], curiosity: 0.9, aggression: 0.05, patience: 0.9, visibility: 0.55, territory: 140, pursuit: 0,  cooldown: [35, 75], life: [18, 40] },
  wanderer:  { label: 'Pengembara', kinds: ['kunti'],            curiosity: 0.4, aggression: 0.1,  patience: 0.6, visibility: 0.7,  territory: 220, pursuit: 0,  cooldown: [40, 90], life: [20, 45] },
  mimic:     { label: 'Peniru',     kinds: ['bayangan'],         curiosity: 0.7, aggression: 0.2,  patience: 0.7, visibility: 0.4,  territory: 90,  pursuit: 0,  cooldown: [45, 90], life: [15, 30] },
  pursuer:   { label: 'Pengejar',   kinds: ['pocong'],           curiosity: 0.6, aggression: 0.8,  patience: 0.4, visibility: 0.8,  territory: 120, pursuit: 8,  cooldown: [60, 120], life: [14, 26] },
  distorter: { label: 'Pengubah',   kinds: ['mata'],             curiosity: 0.5, aggression: 0.2,  patience: 0.5, visibility: 0.3,  territory: 160, pursuit: 0,  cooldown: [40, 80], life: [12, 24] },
};
const ENV = ['flicker', 'fog', 'steps', 'whisper'];
const TICK_MS = 1000, CHASE_GAP_MS = 180000;
// Per dunia: 3D (unit dunia, jalan 18 u/dtk) · Pixel (unit jaringan = 10 px, jalan 8.4 u/dtk) — pengejar selalu lebih lambat.
const WP = {
  '3d':    { spawn: [30, 46], chase: 8, catchD: 3.2, escape: 55, near: 50, follow: [26, 34], back: [22, 34], wander: [30, 70] },
  'pixel': { spawn: [17, 28], chase: 5, catchD: 2.5, escape: 30, near: 26, follow: [12, 18], back: [12, 20], wander: [15, 32] },
};
function dist(e, p) { return e.world === 'pixel' ? Math.abs(p.x - e.x) : Math.hypot(p.x - e.x, p.z - e.z); }

function rnd(a, b) { return a + Math.random() * (b - a); }
function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }
function hourWIB() { return (new Date().getUTCHours() + 7) % 24; }
function isNight() { const h = hourWIB(); return h >= 18 || h < 5; }

module.exports = function createDirector({ players, WebSocket, log }) {
  const rooms = new Map(); // key → state
  let nextId = 1;

  function roomKey(p) { return (p.serverId || 1) + '|' + (p.world || '3d'); }
  function members(key) {
    const out = [];
    for (const [s, p] of players.entries()) if (roomKey(p) === key && s.readyState === WebSocket.OPEN && (p.world === '3d' || p.world === 'pixel')) out.push({ s, p });
    return out;
  }
  function send(key, obj) { const j = JSON.stringify(obj); members(key).forEach(({ s }) => s.send(j)); }
  function room(key) {
    let r = rooms.get(key);
    if (!r) { r = { key, ent: null, intensity: 0, nextAt: Date.now() + rnd(20, 45) * 1000, lastChaseAt: 0, lastTarget: 0, spots: [], forced: false }; rooms.set(key, r); }
    return r;
  }
  function view(e) {
    return { id: e.id, arch: e.arch, kind: e.kind, action: e.action, targetId: e.targetId, x: e.x, z: e.z, dx: e.dx, dz: e.dz, area: e.area, mode: e.mode, until: e.until };
  }

  /** Pilih arketipe dengan skor utilitas berdasarkan konteks room. */
  function chooseArch(r, ms) {
    const alone = ms.length === 1, many = ms.length >= 4, now = Date.now();
    const score = {};
    for (const k of Object.keys(ARCH)) {
      const A = ARCH[k];
      let s = 1 + Math.random() * 0.6;
      s += alone ? A.curiosity * 0.8 : 0;                      // pemain sendirian → pengamat/peniru lebih menarik
      s += many ? A.visibility * 0.6 : 0;                      // rombongan → sosok yang mudah terlihat
      s -= r.intensity * A.aggression * 1.5;                   // baru saja tegang → hindari agresif
      if (A.pursuit && (now - r.lastChaseAt < CHASE_GAP_MS || r.intensity > 0.5)) s -= 5;
      score[k] = s;
    }
    return Object.keys(score).sort((a, b) => score[b] - score[a])[0];
  }
  /** Target: utamakan pemain yang terpisah dari rombongan & bukan target terakhir. */
  function chooseTarget(r, ms) {
    let best = null, bestS = -1e9;
    for (const m of ms) {
      let near = 0;
      for (const o of ms) if (o !== m && (m.p.world !== '3d' || Math.hypot(o.p.x - m.p.x, o.p.z - m.p.z) < 40) && (m.p.world !== 'pixel' || o.p.area === m.p.area)) near++;
      const s = -near * 2 + (m.p.id === r.lastTarget ? -1.5 : 0) + Math.random();
      if (s > bestS) { bestS = s; best = m; }
    }
    return best;
  }
  function spawn(r, ms, archKey, forceTarget) {
    const tgt = forceTarget || chooseTarget(r, ms);
    if (!tgt) return;
    const A = ARCH[archKey], p = tgt.p, is3d = p.world === '3d', K = WP[p.world] || WP['3d'];
    const ang = rnd(0, Math.PI * 2), dd = rnd(K.spawn[0], K.spawn[1]);
    const e = {
      id: nextId++, arch: archKey, kind: pick(A.kinds), targetId: p.id, area: p.area || '', world: p.world,
      mode: 'abs', x: is3d ? p.x + Math.sin(ang) * dd : p.x + (Math.random() < 0.5 ? -1 : 1) * dd, z: is3d ? p.z + Math.cos(ang) * dd : 0, dx: 0, dz: 0,
      action: 'observe', born: Date.now(), until: Date.now() + rnd(A.life[0], A.life[1]) * 1000, seen: false, chaseStart: 0, stepT: 0,
    };
    r.ent = e; r.lastTarget = p.id;
    r.spots.push([Math.round(e.x), Math.round(e.z)]); if (r.spots.length > 12) r.spots.shift();
    send(r.key, Object.assign({ type: 'anomaly', op: 'spawn' }, view(e)));
    if (log) log('[anomaly] ' + r.key + ' spawn ' + archKey + '/' + e.kind + ' → ' + p.id);
  }
  function despawn(r, why) {
    const e = r.ent; if (!e) return;
    r.ent = null;
    const A = ARCH[e.arch];
    r.intensity = Math.min(1, r.intensity + (e.action === 'pursue' || why === 'catch' ? 0.45 : 0.2));
    r.nextAt = Date.now() + rnd(A.cooldown[0], A.cooldown[1]) * 1000 * (1 + r.intensity * 1.5);
    send(r.key, { type: 'anomaly', op: 'despawn', id: e.id, why: why || 'vanish' });
    if (log) log('[anomaly] ' + r.key + ' despawn ' + e.arch + ' (' + (why || 'vanish') + ')');
  }

  /** Utility AI per tick untuk anomali aktif. */
  function think(r, ms) {
    const e = r.ent, A = ARCH[e.arch], now = Date.now();
    const tgtM = ms.find((m) => m.p.id === e.targetId);
    if (!tgtM || (e.world === 'pixel' && tgtM.p.area !== e.area && e.action !== 'pursue')) { despawn(r, 'lost'); return; }
    const p = tgtM.p, K = WP[e.world] || WP['3d'];
    const d = dist(e, p);
    if (now > e.until) { despawn(r, 'timeout'); return; }
    if (e.action === 'pursue') {
      const t = (now - e.chaseStart) / 1000;
      if (t > A.pursuit || d > K.escape || (e.world === 'pixel' && p.area !== e.area)) { despawn(r, 'escaped'); return; }
      if (d < K.catchD) { send(r.key, { type: 'anomaly', op: 'catch', id: e.id, targetId: e.targetId, kind: e.kind }); despawn(r, 'catch'); return; }
      const step = K.chase * TICK_MS / 1000;
      if (e.world === 'pixel') e.x += Math.sign(p.x - e.x) * Math.min(Math.abs(p.x - e.x), step);
      else { const k = Math.min(1, step / Math.max(0.01, d)); e.x += (p.x - e.x) * k; e.z += (p.z - e.z) * k; }
      send(r.key, { type: 'anomaly', op: 'move', id: e.id, x: e.x, z: e.z, dx: e.dx, action: 'pursue' });
      return;
    }
    // skor aksi berdasarkan konteks
    const S = {
      observe: 1 + A.patience - (e.seen ? 0.8 : 0),
      reposition: (e.seen ? 1.2 : 0.2) + A.curiosity * 0.5,
      follow: A.curiosity * (d > K.near ? 1.4 : 0.3),
      wander: (1 - A.curiosity) * 1.2,
      pursue: A.pursuit && now - r.lastChaseAt > CHASE_GAP_MS && d < K.near * 0.8 && r.intensity < 0.6 ? A.aggression * 2 : -9,
      distort: e.arch === 'distorter' ? 1.6 : 0.15,
      vanish: (now - e.born) / 1000 > 12 ? (e.seen ? 1.4 : 0.4) : -9,
    };
    Object.keys(S).forEach((k) => { S[k] += Math.random() * 0.7; });
    const act = Object.keys(S).sort((a, b) => S[b] - S[a])[0];
    if (act === e.action && act === 'observe') return; // tetap diam: tidak perlu kirim apa-apa
    e.action = act;
    if (act === 'vanish') { despawn(r, 'vanish'); return; }
    if (act === 'pursue') { e.chaseStart = now; r.lastChaseAt = now; e.until = now + (A.pursuit + 2) * 1000; send(r.key, { type: 'anomaly', op: 'act', id: e.id, action: 'pursue', x: e.x, z: e.z, dx: e.dx }); return; }
    if (act === 'distort') { send(r.key, { type: 'anomaly', op: 'env', id: e.id, env: pick(ENV), targetId: e.targetId, x: e.x, z: e.z, dur: 6 }); e.action = 'observe'; return; }
    if (e.world !== 'pixel') {
      let ang, dd;
      if (act === 'reposition') { ang = Math.atan2(e.x - p.x, e.z - p.z) + Math.PI + rnd(-0.8, 0.8); dd = rnd(K.back[0], K.back[1]); } // pindah ke belakang/sisi lain
      else if (act === 'follow') { ang = Math.atan2(e.x - p.x, e.z - p.z) + rnd(-0.3, 0.3); dd = rnd(K.follow[0], K.follow[1]); }
      else { ang = rnd(0, Math.PI * 2); dd = rnd(K.wander[0], Math.min(K.wander[1], A.territory / 2)); }
      e.x = p.x + Math.sin(ang) * dd; e.z = p.z + Math.cos(ang) * dd;
    } else {
      const side = act === 'reposition' ? -Math.sign(e.x - p.x || 1) : Math.sign(e.x - p.x || 1);
      const R = act === 'follow' ? K.follow : act === 'reposition' ? K.back : K.wander;
      e.x = p.x + side * rnd(R[0], R[1]);
    }
    e.seen = false;
    send(r.key, { type: 'anomaly', op: 'act', id: e.id, action: act, x: e.x, z: e.z, dx: e.dx });
  }

  function tick() {
    const keys = new Set();
    for (const [, p] of players.entries()) if (p.world === '3d' || p.world === 'pixel') keys.add(roomKey(p));
    for (const [key, r] of rooms.entries()) if (!keys.has(key)) { rooms.delete(key); } // room kosong: lupakan (tak ada state macet)
    keys.forEach((key) => {
      const r = room(key), ms = members(key);
      if (!ms.length) return;
      r.intensity = Math.max(0, r.intensity - 0.01);
      if (r.ent) { try { think(r, ms); } catch (e) { r.ent = null; } return; }
      if (!isNight() && !r.forced) return;
      if (Date.now() < r.nextAt) return;
      r.forced = false;
      spawn(r, ms, chooseArch(r, ms));
    });
  }
  const timer = setInterval(tick, TICK_MS);

  return {
    ARCH,
    /** Pemain baru masuk room → kirim anomali yang sedang aktif (state konsisten). */
    onJoin(ws, p) {
      const r = rooms.get(roomKey(p));
      if (r && r.ent && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(Object.assign({ type: 'anomaly', op: 'sync' }, view(r.ent))));
    },
    onLeave(p) {
      const r = rooms.get(roomKey(p));
      if (r && r.ent && r.ent.targetId === p.id) despawn(r, 'lost');
    },
    /** Pesan klien: anomaly_near (pemain mendekat → lenyap utk semua), anomaly_debug (ROOT: paksa muncul). */
    onMessage(ws, p, msg) {
      const r = room(roomKey(p));
      if (msg.type === 'anomaly_near') {
        const e = r.ent;
        if (!e || Number(msg.id) !== e.id || e.action === 'pursue') return;
        if (!e.seen) { e.seen = true; return; }        // pertama kali didekati: tandai (AI cenderung pindah)
        despawn(r, 'approached');
      } else if (msg.type === 'anomaly_debug' && p.role === 'root') {
        // uji ROOT: selalu menargetkan pengirim sendiri (tidak pernah pemain lain)
        const arch = ARCH[msg.arch] ? msg.arch : 'watcher', ms = members(r.key), me = ms.find((m) => m.s === ws);
        if (!me) return;
        if (r.ent) despawn(r, 'debug');
        spawn(r, ms, arch, me);
      }
    },
    stats() { const o = {}; rooms.forEach((r, k) => { o[k] = { ent: r.ent ? view(r.ent) : null, intensity: r.intensity, nextIn: Math.round((r.nextAt - Date.now()) / 1000) }; }); return o; },
    stop() { clearInterval(timer); },
  };
};
