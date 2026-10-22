#!/usr/bin/env node
/**
 * WebSocket Server untuk Virtual City SMK Silber v5
 * Layanan real-time posisi avatar, chat obrolan, emote, dan WebRTC voice signaling.
 * Berjalan pada port 8090 (atau konfigurasi WS_PORT).
 */

const WebSocket = require('ws');
const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const PORT = parseInt(process.env.WS_PORT || '8090', 10);
const MAX_PLAYERS_PER_FLOOR = 150;
// MABAR: room party = serverId PARTY_BASE + id party (world "party"), maks. 15 anggota (dicek PHP).
const PARTY_BASE = 1000000;

// Baca secret key dari app.env atau fallback
let APP_SECRET = 'virtual-city-default-key-silber-2026';
try {
  const envPath = path.join(__dirname, '../config/app.env');
  if (fs.existsSync(envPath)) {
    const lines = fs.readFileSync(envPath, 'utf8').split('\n');
    for (const line of lines) {
      const match = line.match(/^APP_KEY=(.*)$/);
      if (match && match[1].trim()) {
        APP_SECRET = match[1].trim();
        break;
      }
    }
  }
} catch (e) {
  // Gunakan fallback secret
}

// Agent 1 — konfigurasi chat in-game disinkron dari PHP (sumber kebenaran = tabel settings).
const RELAY_BASE = (process.env.VC_RELAY_URL || 'http://127.0.0.1').replace(/\/$/, '');
const CHAT_SETTINGS_URL = RELAY_BASE + '/api/chat/settings';
const CHAT_RELAY_URL = RELAY_BASE + '/api/game/chat_relay';
let CHAT_SETTINGS = { enabled: true, mode: 'global_area_private', scopes: ['global', 'area', 'private'], cooldown: 2 };

// HMAC untuk relay server-to-server (endpoint /api/game/chat_relay memverifikasi ini).
function relaySign(ts, body) {
  return crypto.createHmac('sha256', APP_SECRET).update(ts + '.' + body).digest('hex');
}

async function refreshChatSettings() {
  try {
    const res = await fetch(CHAT_SETTINGS_URL, { signal: AbortSignal.timeout(4000) });
    if (!res.ok) return;
    const data = await res.json();
    if (data && data.status === 'ok') {
      CHAT_SETTINGS = {
        enabled: !!data.enabled,
        mode: data.mode || 'global_area_private',
        scopes: Array.isArray(data.scopes) ? data.scopes : ['global', 'area', 'private'],
        cooldown: Math.max(1, Math.min(30, parseInt(data.cooldown, 10) || 2))
      };
    }
  } catch (e) {
    // Jangan rebah layanan; pertahankan pengaturan terlahir.
  }
}

// Persist + validasi chat lewat PHP (rate limit, moderasi, filter, penyimpanan)
async function persistChat(player, scope, scopeKey, text) {
  const ts = String(Math.floor(Date.now() / 1000));
  const body = JSON.stringify({
    user_id: player.id,
    scope: scope,
    scope_key: scopeKey || '',
    text: String(text || '').slice(0, 300),
    username: player.username,
    role: player.role
  });
  const res = await fetch(CHAT_RELAY_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-VC-Timestamp': ts,
      'X-VC-Signature': relaySign(ts, body)
    },
    body,
    signal: AbortSignal.timeout(5000)
  });
  const data = await res.json().catch(() => ({}));
  return { ok: res.ok && data && data.status === 'ok', id: data.id || 0, message: data.message || '' };
}

function verifyToken(token) {
  if (!token || typeof token !== 'string') return null;
  const parts = token.split('.');
  if (parts.length !== 2) return null;
  const [payloadB64, sig] = parts;

  const expectedSig = crypto.createHmac('sha256', APP_SECRET).update(payloadB64).digest('hex');
  if (sig !== expectedSig) return null;

  try {
    const jsonStr = Buffer.from(payloadB64, 'base64').toString('utf8');
    const data = JSON.parse(jsonStr);
    if (!data.id || !data.exp) return null;
    if (Math.floor(Date.now() / 1000) > data.exp) return null; // Kedaluwarsa
    return data;
  } catch (err) {
    return null;
  }
}

// HTTP Server untuk health check status WebSocket + endpoint internal push event
const server = http.createServer((req, res) => {
  const cleanUrl = req.url.split('?')[0].replace(/^\/ws/, '').replace(/\/+/g, '/');
  if (cleanUrl === '/internal/mission-progress' || cleanUrl === '/internal/mission_progress') {
    // WORKSTREAM E — endpoint INTERNAL (server-to-server, bukan klien browser).
    // PHP/agent lain POST di sini setelah misi maju/selesai (mis. di
    // Gameplay::completeMission). Payload JSON:
    //   { serverId, missionId, playerId, playerName, playerProgress, serverAggregate }
    // Wajib header X-VC-Timestamp + X-VC-Signature (HMAC APP_KEY, skema sama
    // dengan /api/game/chat_relay) + rate-limit token bucket 60 event/detik.
    if (req.method !== 'POST') {
      res.writeHead(405, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ status: 'error', message: 'Gunakan POST.' }));
      return;
    }
    let raw = '';
    req.on('data', (c) => { raw += c; if (raw.length > 4096) req.destroy(); });
    req.on('end', () => {
      const send = (code, obj) => {
        res.writeHead(code, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(obj));
      };
      const ts = String(req.headers['x-vc-timestamp'] || '');
      const sig = String(req.headers['x-vc-signature'] || '');
      if (!/^\d+$/.test(ts) || !sig || Math.abs(Date.now() / 1000 - Number(ts)) > 60) {
        send(403, { status: 'error', message: 'Timestamp tidak valid.' });
        return;
      }
      const expected = crypto.createHmac('sha256', APP_SECRET).update(ts + '.' + raw).digest('hex');
      if (sig !== expected) {
        send(403, { status: 'error', message: 'Signature tidak sah.' });
        return;
      }
      if (!missionProgressAllowed()) {
        send(429, { status: 'error', message: 'Terlalu banyak event, coba lagi.' });
        return;
      }
      let body = null;
      try { body = JSON.parse(raw); } catch (e) { body = null; }
      const missionId = parseInt(body && body.missionId, 10);
      const playerId = parseInt(body && body.playerId, 10);
      if (!Number.isFinite(missionId) || missionId < 1 || !Number.isFinite(playerId) || playerId < 1) {
        send(400, { status: 'error', message: 'missionId dan playerId wajib angka positif.' });
        return;
      }
      const payload = broadcastMissionProgress(
        (body && body.serverId) || 1,
        missionId,
        playerId,
        (body && body.playerName) || '',
        (body && body.playerProgress) || 0,
        (body && body.serverAggregate) || (body && body.playerProgress) || 0
      );
      send(200, { status: 'ok', broadcast: 'mission_progress', room: 's' + payload.serverId, event: payload });
    });
    return;
  }
  if (cleanUrl === '/internal/party') {
    // MABAR — PHP (Domain\\Party::signal) mengirim event ke anggota party yang terhubung:
    // { serverId: PARTY_BASE+id, payload: { type: 'party_go' | 'party_update' | 'party_kick', ... } }
    // HMAC sama dengan endpoint internal lain.
    if (req.method !== 'POST') { res.writeHead(405); res.end(); return; }
    let raw = '';
    req.on('data', (c) => { raw += c; if (raw.length > 4096) req.destroy(); });
    req.on('end', () => {
      const send = (code, obj) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };
      const ts = String(req.headers['x-vc-timestamp'] || '');
      const sig = String(req.headers['x-vc-signature'] || '');
      if (!/^\d+$/.test(ts) || !sig || Math.abs(Date.now() / 1000 - Number(ts)) > 60) { send(403, { status: 'error' }); return; }
      const expected = crypto.createHmac('sha256', APP_SECRET).update(ts + '.' + raw).digest('hex');
      if (sig !== expected) { send(403, { status: 'error' }); return; }
      let body = null;
      try { body = JSON.parse(raw); } catch (e) { body = null; }
      const sid = parseInt(body && body.serverId, 10);
      const payload = body && body.payload;
      if (!Number.isFinite(sid) || sid <= PARTY_BASE || !payload || !/^party_[a-z]+$/.test(String(payload.type || ''))) { send(400, { status: 'error' }); return; }
      let n = 0;
      const json = JSON.stringify(payload);
      for (const [client, p] of players.entries()) {
        if ((p.serverId || 1) !== sid || client.readyState !== WebSocket.OPEN) continue;
        client.send(json); n++;
        if (payload.type === 'party_kick' && p.id === Number(payload.id)) {
          players.delete(client);
          broadcast({ type: 'player_leave', id: p.id }, null, sid, null);
          try { client.close(4003, 'Kicked from party'); } catch (e) { /* abaikan */ }
        }
      }
      send(200, { status: 'ok', delivered: n });
    });
    return;
  }
  if (cleanUrl === '/internal/server-deleted' || cleanUrl === '/internal/server_deleted') {
    // ROOT menghapus server game (dashboard admin) — broadcast `server_deleted`
    // ke SEMUA klien (3D & Pixel) dengan serverId tersebut lalu tutup soketnya.
    // Skema HMAC sama dengan /internal/mission-progress.
    if (req.method !== 'POST') {
      res.writeHead(405, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ status: 'error', message: 'Gunakan POST.' }));
      return;
    }
    let raw = '';
    req.on('data', (c) => { raw += c; if (raw.length > 4096) req.destroy(); });
    req.on('end', () => {
      const send = (code, obj) => {
        res.writeHead(code, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(obj));
      };
      const ts = String(req.headers['x-vc-timestamp'] || '');
      const sig = String(req.headers['x-vc-signature'] || '');
      if (!/^\d+$/.test(ts) || !sig || Math.abs(Date.now() / 1000 - Number(ts)) > 60) {
        send(403, { status: 'error', message: 'Timestamp tidak valid.' });
        return;
      }
      const expected = crypto.createHmac('sha256', APP_SECRET).update(ts + '.' + raw).digest('hex');
      if (sig !== expected) {
        send(403, { status: 'error', message: 'Signature tidak sah.' });
        return;
      }
      let body = null;
      try { body = JSON.parse(raw); } catch (e) { body = null; }
      const sid = parseInt(body && body.serverId, 10);
      if (!Number.isFinite(sid) || sid < 1) {
        send(400, { status: 'error', message: 'serverId wajib angka positif.' });
        return;
      }
      let kicked = 0;
      for (const [sock, p] of players.entries()) {
        if ((p.serverId || 1) !== sid) continue;
        try {
          if (sock.readyState === WebSocket.OPEN) {
            sock.send(JSON.stringify({
              type: 'server_deleted', serverId: sid,
              message: 'Server ini telah dihapus oleh admin. Kamu dikeluarkan dari server.',
              timestamp: new Date().toISOString()
            }));
          }
          sock.close(4000, 'server_deleted');
          kicked++;
        } catch (e) { /* best-effort */ }
      }
      send(200, { status: 'ok', broadcast: 'server_deleted', serverId: sid, kicked });
    });
    return;
  }
  if (cleanUrl === '/health' || cleanUrl === '/status' || req.url.includes('/health') || req.url.includes('/status')) {
    const perServer = {};
    const perWorld = {};
    const perRoom = {};
    for (const [, p] of players.entries()) {
      const sid = p.serverId || 1;
      perServer[sid] = (perServer[sid] || 0) + 1;
      const w = p.world || '3d';
      perWorld[w] = (perWorld[w] || 0) + 1;
      const rk = roomKey(sid, w);
      perRoom[rk] = (perRoom[rk] || 0) + 1;
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      status: 'ok',
      service: 'virtual-city-websocket',
      connectedClients: wss.clients.size,
      onlineTotal: players.size,
      worlds: perWorld,
      servers: perServer,
      rooms: perRoom,
      uptime: Math.floor(process.uptime()),
      timestamp: new Date().toISOString()
    }));
    return;
  }
  res.writeHead(404);
  res.end('Not found');
});

const wss = new WebSocket.Server({ server });

// Map soket ke data pemain
const players = new Map();
// Anomaly Director: AI anomali server-authoritative (lihat anomaly_director.js)
const director = require('./anomaly_director')({ players, WebSocket, log: (m) => console.log(m) });

// ============================================================
// Room = serverId. Semua klien pada server yang sama (baik 3D maupun Pixel)
// saling terhubung dalam satu server room tanpa lag dan ringan.
// ============================================================
function roomKey(serverId, world = null) {
  return 's' + (Number(serverId) || 1);
}
function roomOf(p) {
  return roomKey(p.serverId, p.world);
}
// Daftar soket aktif dalam satu room
function roomClients(key) {
  const out = [];
  for (const [sock, p] of players.entries()) {
    if (roomOf(p) === key && sock.readyState === WebSocket.OPEN) out.push([sock, p]);
  }
  return out;
}
function sameRoom(a, b) {
  return roomOf(a) === roomOf(b);
}

// WORKSTREAM E — broadcast event mission_progress ke room server.
// Sumber 1: klien WS (case 'mission_progress', identitas dari sesi, anti-spoof).
// Sumber 2: internal/API — endpoint HTTP POST /internal/mission-progress (HMAC).
// Payload keluaran (dipakai agent lain):
//   { type:'mission_progress', serverId, missionId, playerId, playerName,
//     playerProgress, serverAggregate, timestamp }
const MISSION_PROGRESS_MAX = 60;      // event/detik global (internal push)
const MISSION_PROGRESS_WINDOW = 1000; // ms
let missionProgressBudget = MISSION_PROGRESS_MAX;
let missionProgressWindowStart = Date.now();
function missionProgressAllowed() {
  const now = Date.now();
  if (now - missionProgressWindowStart > MISSION_PROGRESS_WINDOW) {
    missionProgressWindowStart = now;
    missionProgressBudget = MISSION_PROGRESS_MAX;
  }
  if (missionProgressBudget <= 0) return false;
  missionProgressBudget--;
  return true;
}
function sanitizeProgress(v, fallback) {
  const n = Number(v);
  if (!Number.isFinite(n)) return Number.isFinite(Number(fallback)) ? Number(fallback) : 0;
  return Math.max(0, Math.min(100, Math.round(n * 100) / 100));
}
function broadcastMissionProgress(serverId, missionId, playerId, playerName, playerProgress, serverAggregate) {
  const payload = {
    type: 'mission_progress',
    serverId: Number(serverId) || 1,
    missionId: Number(missionId) || 0,
    playerId: Number(playerId) || 0,
    playerName: String(playerName || '').slice(0, 64),
    playerProgress: sanitizeProgress(playerProgress, 0),
    serverAggregate: sanitizeProgress(serverAggregate, playerProgress),
    timestamp: new Date().toISOString()
  };
  // Kirim ke SEMUA klien pada serverId (semua world: 3D & Pixel).
  const json = JSON.stringify(payload);
  const sid = payload.serverId;
  for (const [sock, p] of players.entries()) {
    if (sock.readyState !== WebSocket.OPEN) continue;
    if ((p.serverId || 1) !== sid) continue;
    sock.send(json);
  }
  return payload;
}

wss.on('connection', (ws, req) => {
  // Ambil token dari query string ?token=...
  // Jangan pernah biarkan URL rusak dari klien menjatuhkan seluruh server (restart loop).
  let urlObj = null;
  try {
    // Apache ProxyPass dapat meneruskan request-target "//?query" (double-slash,
    // bentuk scheme-relative tanpa host) yang membuat new URL() gagal.
    // Normalisasi: hapus satu leading slash; bentuk absolute (ws://host/...) tetap valid.
    let rawUrl = req.url || '/';
    if (rawUrl.startsWith('//')) rawUrl = rawUrl.slice(1);
    urlObj = new URL(rawUrl, 'http://localhost');
  } catch (err) {
    console.error('[WS DEBUG] unparseable req.url:', JSON.stringify(String(req.url).slice(0, 300)));
    try {
      ws.send(JSON.stringify({ type: 'error', message: 'URL koneksi tidak valid.' }));
      ws.close(4001, 'Unauthorized');
    } catch (e2) { /* abaikan */ }
    return;
  }
  const token = urlObj.searchParams.get('token');
  const user = verifyToken(token);

  if (!user) {
    ws.send(JSON.stringify({ type: 'error', message: 'Autentikasi gagal atau token kedaluwarsa.' }));
    ws.close(4001, 'Unauthorized');
    return;
  }

  // Cek apakah pemain sudah memiliki koneksi lain di world yang sama (kick koneksi lama).
  // Satu user boleh terhubung di dua world berbeda (3D & Pixel) secara bersamaan.
  let worldParam = (urlObj.searchParams.get('world') || '3d').replace(/[^a-z0-9_-]/gi, '').slice(0, 16);
  // Token room mabar hanya untuk world "party"; world "party" hanya dengan token room mabar.
  const isPartyRoom = Number(user.server_id) > PARTY_BASE;
  if (isPartyRoom) worldParam = 'party';
  else if (worldParam === 'party') {
    ws.send(JSON.stringify({ type: 'error', message: 'Token mabar tidak valid.' }));
    ws.close(4001, 'Unauthorized');
    return;
  }
  for (const [s, p] of players.entries()) {
    if (p.id === user.id && (p.world || '3d') === worldParam) {
      s.send(JSON.stringify({ type: 'kicked', message: 'Terhubung dari perangkat atau tab lain.' }));
      s.close(4002, 'Duplicate connection');
      players.delete(s);
    }
  }

  const playerData = {
    id: user.id,
    // Tamu gabung-cepat: tampilkan nama asli (bukan username teknis tamu_xxx) di chat & nameplate.
    username: (user.role === 'tamu' && user.full_name) ? String(user.full_name).slice(0, 32) : user.username,
    fullName: user.full_name || user.username,
    role: user.role || 'murid',
    serverId: user.server_id || 1,
    world: worldParam,
    programColor: user.program_color || '#0284c7',
    avatarConfig: user.avatar_config || null,
    x: 160,
    y: 0,
    z: 12,
    yaw: Math.PI,
    floor: 1,
    locationName: 'Sekolah',
    area: '',
    isMoving: false,
    isSprinting: false,
    lastMsgTime: 0,
    lastMoveTime: Date.now()
  };

  players.set(ws, playerData);

  // Kirim data inisialisasi & daftar pemain lain di server yang sama (sinkron lintas 3D & Pixel)
  const existing = [];
  for (const [s, p] of players.entries()) {
    if (s !== ws && (p.serverId || 1) === (playerData.serverId || 1)) {
      existing.push({
        id: p.id,
        username: p.username,
        fullName: p.fullName,
        role: p.role,
        world: p.world || '3d',
        programColor: p.programColor,
        avatarConfig: p.avatarConfig,
        x: p.x,
        y: p.y,
        z: p.z,
        yaw: p.yaw,
        floor: p.floor,
        area: p.area || '',
        locationName: p.locationName || 'Sekolah',
        isMoving: p.isMoving,
        isSprinting: p.isSprinting
      });
    }
  }

  ws.send(JSON.stringify({
    type: 'init',
    self: playerData,
    players: existing
  }));
  try { director.onJoin(ws, playerData); } catch (e) { /* anomali opsional */ }

  // Beritahu pemain lain di server yang sama (3D & Pixel) bahwa ada pemain baru yang masuk
  broadcast({
    type: 'player_join',
    player: {
      id: playerData.id,
      username: playerData.username,
      fullName: playerData.fullName,
      role: playerData.role,
      world: playerData.world || '3d',
      programColor: playerData.programColor,
      avatarConfig: playerData.avatarConfig,
      x: playerData.x,
      y: playerData.y,
      z: playerData.z,
      yaw: playerData.yaw,
      floor: playerData.floor,
      locationName: playerData.locationName || 'Sekolah'
    }
  }, ws, playerData.serverId, null);

  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });

  ws.on('message', (msgStr) => {
    try {
      const msg = JSON.parse(msgStr);
      const player = players.get(ws);
      if (!player) return;

      switch (msg.type) {
        case 'join': {
          // Terapkan posisi & konteks awal klien (pixel/3D mengirim spawn point).
          // Tanpa ini player selalu spawn di 160,12 dan move pertama klien
          // ditolak anti-speedhack → sinkronisasi terasa mati/stuck.
          if (Number.isFinite(Number(msg.x))) player.x = Number(msg.x);
          if (Number.isFinite(Number(msg.y))) player.y = Number(msg.y);
          if (Number.isFinite(Number(msg.z))) player.z = Number(msg.z);
          if (Number(msg.floor) > 0) player.floor = Number(msg.floor);
          if (typeof msg.area === 'string') player.area = msg.area.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 32);
          if (msg.locationName) player.locationName = String(msg.locationName).slice(0, 50);
          player.lastMoveTime = Date.now();
          break;
        }

        case 'move': {
          const now = Date.now();
          // Validasi anti-teleport / batas kecepatan wajar (maks 35 unit/detik kecuali ganti lantai)
          const dt = Math.max(0.01, (now - player.lastMoveTime) / 1000);
          const dx = (msg.x ?? player.x) - player.x;
          const dz = (msg.z ?? player.z) - player.z;
          const dist = Math.sqrt(dx * dx + dz * dz);
          const speed = dist / dt;

          player.lastMoveTime = now;
          const speedCap = (player.world === 'pixel' || player.world === 'party') ? 90 : 40;
          // Lompatan janggal (teleport hotspot, spawn karakter, lag) tidak di-broadcast sekali,
          // tapi posisi tetap disimpan. Dulu di-return tanpa menyimpan → posisi server basi dan
          // SEMUA langkah berikutnya ikut ditolak → pemain terlihat membeku bagi pemain lain.
          const suspicious = speed > speedCap && msg.floor === player.floor;

          player.x = Number(msg.x) || player.x;
          player.y = Number(msg.y) || player.y;
          player.z = Number(msg.z) || player.z;
          player.yaw = Number(msg.yaw) || player.yaw;
          player.floor = Number(msg.floor) || player.floor;
          if (typeof msg.area === 'string') player.area = msg.area.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 32);
          if (msg.locationName) player.locationName = String(msg.locationName).slice(0, 50);
          player.isMoving = !!msg.isMoving;
          player.isSprinting = !!msg.isSprinting;

          if (suspicious) break;

          // Interest management broadcast: kirim ke pemain di lantai sama & radius visual wajar
          const MOVE_INTEREST_RADIUS = player.world === 'party' ? 100000 : (player.world === 'pixel') ? 60 : 50;
          broadcast({
            type: 'player_move',
            id: player.id,
            world: player.world || '3d',
            x: player.x,
            y: player.y,
            z: player.z,
            yaw: player.yaw,
            floor: player.floor,
            area: player.area || '',
            locationName: player.locationName || 'Sekolah',
            isMoving: player.isMoving,
            isSprinting: player.isSprinting
          }, ws, player.serverId, null, (target) => {
            if (target.floor !== player.floor) return false;
            if (player.area && target.area && player.area !== target.area) return false;
            const distSq = (target.x - player.x) ** 2 + (target.z - player.z) ** 2;
            return distSq <= (MOVE_INTEREST_RADIUS ** 2);
          });
          break;
        }

        case 'emote': {
          const emote = String(msg.emote || '').slice(0, 10);
          if (emote) {
            broadcast({
              type: 'player_emote',
              id: player.id,
              emote: emote
            }, null, player.serverId, null);
          }
          break;
        }

        case 'player_interact': {
          const targetId = Number(msg.targetId);
          const action = String(msg.action || 'wave').slice(0, 30);
          const meta = (msg.meta && typeof msg.meta === 'object') ? msg.meta : {};
          const targetName = String(msg.targetName || meta.targetName || '').slice(0, 50);
          let targetFound = false;

          for (const [clientSocket, clientData] of players.entries()) {
            if (clientData.id === targetId && clientSocket.readyState === WebSocket.OPEN) {
              targetFound = true;
              clientSocket.send(JSON.stringify({
                type: 'player_interacted',
                fromId: player.id,
                fromName: player.fullName || player.username,
                fromUsername: player.username,
                fromRole: player.role,
                action: action,
                meta: meta,
                timestamp: new Date().toISOString()
              }));
              break;
            }
          }

          broadcast({
            type: 'player_interaction',
            fromId: player.id,
            fromName: player.fullName || player.username,
            fromUsername: player.username,
            fromRole: player.role,
            targetId: targetId,
            targetName: targetName,
            action: action,
            meta: meta,
            timestamp: new Date().toISOString()
          }, null, player.serverId, null);

          if (action === 'wave' || action === 'cheer') {
            broadcast({
              type: 'player_emote',
              id: player.id,
              emote: action === 'cheer' ? 'cheer' : 'wave'
            }, null, player.serverId, null);
          }

          ws.send(JSON.stringify({
            type: 'interact_ack',
            targetId: targetId,
            action: action,
            delivered: targetFound
          }));
          break;
        }

        case 'avatar_change': {
          // Batasi ukuran gaya karakter (v2 ±400 byte) agar klien nakal tidak membanjiri pemain lain.
          let avLen = 0;
          try { avLen = JSON.stringify(msg.avatarConfig || null).length; } catch (e) { avLen = 99999; }
          if (msg.avatarConfig && typeof msg.avatarConfig === 'object' && !Array.isArray(msg.avatarConfig) && avLen <= 2048) {
            player.avatarConfig = msg.avatarConfig;
            broadcast({
              type: 'player_avatar_change',
              id: player.id,
              avatarConfig: player.avatarConfig
            }, ws, player.serverId, null);
          }
          break;
        }

        case 'chat': {
          // Agent 1 — chat multiplayer: scope global / area / private.
          // Validasi & penyimpanan tetap di PHP (relay HMAC); WS hanya transport.
          const now = Date.now();
          const gate = Math.max(700, CHAT_SETTINGS.cooldown * 1000);
          if (now - player.lastMsgTime < gate) {
            ws.send(JSON.stringify({ type: 'warning', message: 'Mohon tunggu sejenak sebelum mengirim pesan lagi.' }));
            return;
          }
          player.lastMsgTime = now;

          if (!CHAT_SETTINGS.enabled) {
            ws.send(JSON.stringify({ type: 'warning', message: 'Chat sedang dinonaktifkan oleh admin sekolah.' }));
            return;
          }

          let scope = String(msg.scope || 'global').toLowerCase();
          if (!['global', 'area', 'private'].includes(scope)) scope = 'global';
          if (CHAT_SETTINGS.scopes && !CHAT_SETTINGS.scopes.includes(scope)) {
            ws.send(JSON.stringify({ type: 'warning', message: 'Mode chat saat ini tidak mengizinkan kanal ini.' }));
            return;
          }

          let scopeKey = '';
          if (scope === 'area') {
            const areaId = String(msg.area || player.area || '').replace(/[^A-Za-z0-9_-]/g, '').slice(0, 32);
            if (!areaId) {
              ws.send(JSON.stringify({ type: 'warning', message: 'Chat area belum bisa: lokasi kamu belum terdeteksi.' }));
              return;
            }
            scopeKey = areaId;
          } else if (scope === 'private') {
            const targetId = Number(msg.with);
            if (!targetId || targetId === player.id) {
              ws.send(JSON.stringify({ type: 'warning', message: 'Penerima chat privat tidak valid.' }));
              return;
            }
            scopeKey = [player.id, targetId].sort((a, b) => a - b).join('-');
          }

          const text = String(msg.text || '').trim().slice(0, 280);
          if (!text) return;

          // WORKSTREAM E — metadata opsional nama karakter + lokasi (kompatibel:
          // klien lama yang tidak mengirim field ini tetap diterima).
          const charName = String(msg.charName || '').trim().slice(0, 40);
          const locationName = String(msg.locationName || '').trim().slice(0, 50);
          if (charName) player.charName = charName;
          if (locationName) player.locationName = locationName;

          // Global = chat room/kelas (per serverId) → disimpan dengan kunci srv-<id>.
          if (scope === 'global') scopeKey = 'srv-' + (player.serverId || 1);
          persistChat(player, scope, scopeKey, text).then((res) => {
            if (!res.ok) {
              ws.send(JSON.stringify({ type: 'warning', message: res.message || 'Pesan tidak terkirim.' }));
              return;
            }
            // WORKSTREAM E — field baru opsional (charName, locationName) ditambahkan
            // tanpa menghapus field lama → payload existing tetap kompatibel.
            const payload = {
              type: 'chat_message',
              msgId: res.id,
              scope: scope,
              id: player.id,
              username: player.username,
              fullName: player.fullName,
              role: player.role,
              charName: player.charName || player.fullName || player.username,
              locationName: player.locationName || 'Sekolah',
              text: text,
              floor: player.floor,
              to: scope === 'private' ? Number(msg.with) : 0,
              timestamp: new Date().toISOString()
            };
            if (scope === 'global') {
              // WORKSTREAM E — FIX isolasi: chat "global" kini TERBATAS PER-SERVER
              // (semua world: 3D & Pixel di server yang sama), bukan ke seluruh
              // sekolah lintas server seperti sebelumnya (kebocoran room).
              broadcast(payload, null, player.serverId, null);
            } else if (scope === 'area') {
              // Area: pemain server sama, lantai sama & dekat (radius)
              // atau berada di lokasi yang sama (lintas 3D & Pixel pada server yang sama)
              const RADIUS = 60;
              for (const [sock, p] of players.entries()) {
                if (sock.readyState !== WebSocket.OPEN) continue;
                if ((p.serverId || 1) !== (player.serverId || 1)) continue;
                const sameArea = !!scopeKey && p.area === scopeKey;
                const dist = Math.hypot(p.x - player.x, p.z - player.z);
                if (sameArea || (p.floor === player.floor && dist <= RADIUS)) {
                  sock.send(JSON.stringify(payload));
                }
              }
            } else {
              // Private: kirim ke penerima + salinan ke pengirim
              for (const [sock, p] of players.entries()) {
                if (sock.readyState !== WebSocket.OPEN) continue;
                if (p.id === Number(msg.with) || p.id === player.id) {
                  sock.send(JSON.stringify(payload));
                }
              }
            }
          }).catch(() => {
            ws.send(JSON.stringify({ type: 'warning', message: 'Server chat sibuk, coba lagi.' }));
          });
          break;
        }

        case 'mission_progress': {
          // WORKSTREAM E — stub event mission_progress dari KLIEN.
          // Anti-spoof: serverId & playerId diambil dari SESI terautentikasi,
          // bukan dari payload klien. serverAggregate dari klien diabaikan
          // (hanya sumber internal/API yang dipercaya untuk agregat).
          const missionId = parseInt(msg.missionId, 10);
          if (!Number.isFinite(missionId) || missionId < 1) return;
          if (!missionProgressAllowed()) return; // rate-limit
          const playerProgress = sanitizeProgress(msg.playerProgress, 0);
          broadcastMissionProgress(
            player.serverId,
            missionId,
            player.id,
            player.charName || player.fullName || player.username,
            playerProgress,
            playerProgress // stub agregat: progres pemain sendiri
          );
          break;
        }

        case 'voice_signal': {
          // WebRTC signaling (offer/answer/ICE) — hanya ke pemain di room (serverId) yang sama.
          const targetId = Number(msg.targetId);
          if (!targetId || targetId === player.id || !msg.signal || typeof msg.signal !== 'object') break;
          if (JSON.stringify(msg.signal).length > 16384) break; // SDP audio normal < 4 KB
          for (const [clientSocket, clientData] of players.entries()) {
            if (clientData.id === targetId && (clientData.serverId || 1) === (player.serverId || 1)
                && (clientData.world || '3d') === (player.world || '3d')
                && clientSocket.readyState === WebSocket.OPEN) {
              clientSocket.send(JSON.stringify({
                type: 'voice_signal',
                fromId: player.id,
                signal: msg.signal
              }));
              break;
            }
          }
          break;
        }

        case 'anomaly_near':
        case 'anomaly_debug':
          try { director.onMessage(ws, player, msg); } catch (e) { /* abaikan */ }
          break;

        case 'voice_pcm': {
          // Cadangan suara bila WebRTC P2P gagal (tanpa TURN): 8 kHz µ-law base64, ±120 ms/paket.
          // Diteruskan ke room & world yang sama; maks. 20 paket/detik per pemain.
          if (typeof msg.d !== 'string' || msg.d.length > 2400) break;
          const now = Date.now();
          if (!player.vpWin || now - player.vpWin > 1000) { player.vpWin = now; player.vpCount = 0; }
          if (++player.vpCount > 20) break;
          broadcast({ type: 'voice_pcm', fromId: player.id, d: msg.d }, ws, player.serverId || 1, player.world || '3d');
          break;
        }
      }
    } catch (err) {
      // Abaikan payload malformed
    }
  });

  ws.on('close', () => {
    const p = players.get(ws);
    if (p) {
      players.delete(ws);
      try { director.onLeave(p); } catch (e) { /* abaikan */ }
      broadcast({ type: 'player_leave', id: p.id }, null, p.serverId, null);
    }
  });

  ws.on('error', () => {
    const p = players.get(ws);
    if (p) {
      players.delete(ws);
      broadcast({ type: 'player_leave', id: p.id }, null, p.serverId, null);
    }
  });
});

// WORKSTREAM E — broadcast TERKONFINASI ROOM (serverId + world).
// Filter parsial tetap didukung (serverId saja / world saja), namun SEMUA
// pemanggilan chat/posisi kini eksplisit memakai kedua dimensi room
// (lihat roomKey/roomOf) sehingga tidak ada kebocoran lintas server/world.
function broadcast(obj, exceptWs = null, serverId = null, world = null, filterFn = null) {
  const json = JSON.stringify(obj);
  for (const [client, p] of players.entries()) {
    if (client === exceptWs || client.readyState !== WebSocket.OPEN) continue;
    if (serverId !== null && (p.serverId || 1) !== (Number(serverId) || 1)) continue;
    if (world !== null && (p.world || '3d') !== world) continue;
    if (filterFn && !filterFn(p)) continue;
    client.send(json);
  }
}

// Heartbeat interval 30 detik untuk mendeteksi koneksi mati
const interval = setInterval(() => {
  wss.clients.forEach((ws) => {
    if (ws.isAlive === false) return ws.terminate();
    ws.isAlive = false;
    ws.ping();
  });
}, 30000);

wss.on('close', () => {
  clearInterval(interval);
});

server.listen(PORT, '0.0.0.0', () => {
  refreshChatSettings();
  setInterval(refreshChatSettings, 60000); // sinkron pengaturan chat tiap 60 detik
  console.log(`[Virtual City WebSocket] Berjalan pada port ${PORT} (0.0.0.0:${PORT})`);
});
