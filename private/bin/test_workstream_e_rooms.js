// Uji Workstream E: isolasi room + chat metadata + mission_progress
const crypto = require('crypto');
const fs = require('fs');
const WebSocket = require('/usr/share/nodejs/ws');

const PORT = 8091;
// Baca APP_KEY (sama seperti server)
let APP_KEY = 'virtual-city-default-key-silber-2026';
const env = fs.readFileSync('/var/www/private/config/app.env', 'utf8');
const m = env.match(/^APP_KEY=(.*)$/m);
if (m && m[1].trim()) APP_KEY = m[1].trim();

function makeToken(id, username, server_id) {
  const payload = Buffer.from(JSON.stringify({
    id, username, full_name: username.toUpperCase(), role: 'murid',
    server_id, exp: Math.floor(Date.now() / 1000) + 300
  })).toString('base64');
  const sig = crypto.createHmac('sha256', APP_KEY).update(payload).digest('hex');
  return payload + '.' + sig;
}

// Gunakan user ASLI dari DB (user_id fiktif ditolak FK di relay PHP).
const REAL_USERS = { A: { id: 4, u: 'siswa_test_2' }, B: { id: 5, u: 'siswa_test_1' }, C: { id: 41, u: 'coffeelatte314' } };

function connect(name, serverId, world) {
  return new Promise((resolve, reject) => {
    const ru = REAL_USERS[name];
    const token = makeToken(ru.id, ru.u, serverId);
    const ws = new WebSocket('ws://127.0.0.1:' + PORT + '/?token=' + encodeURIComponent(token) + '&world=' + world);
    ws.msgs = [];
    ws.on('message', (d) => { ws.msgs.push(JSON.parse(d.toString())); });
    ws.on('open', () => resolve(ws));
    ws.on('error', reject);
    setTimeout(() => reject(new Error('timeout ' + name)), 5000);
  });
}

function wait(ms) { return new Promise(r => setTimeout(r, ms)); }

(async () => {
  const A = await connect('A', 1, '3d');     // server 1, dunia 3D
  const B = await connect('B', 1, 'pixel');  // server 1, dunia Pixel
  const C = await connect('C', 2, '3d');     // server 2, dunia 3D
  await wait(400);

  const results = [];
  const check = (label, ok, detail) => { results.push([label, ok, detail]); console.log((ok ? 'PASS' : 'FAIL') + ' — ' + label + (detail ? ' | ' + detail : '')); };

  // 1. Chat global dengan metadata charName/locationName
  A.send(JSON.stringify({ type: 'chat', scope: 'global', text: 'Halo dari A', charName: 'Raka TKJ', locationName: 'Lab Komputer' }));
  await wait(800);
  const chatB = B.msgs.find(x => x.type === 'chat_message');
  const chatC = C.msgs.find(x => x.type === 'chat_message');
  check('chat global: diterima sesama server (beda world)', !!chatB && chatB.text === 'Halo dari A');
  check('chat global: TERISOLASI dari server lain (C server 2 tidak menerima)', !chatC);
  check('chat global: metadata charName terkirim', !!chatB && chatB.charName === 'Raka TKJ', JSON.stringify(chatB && { charName: chatB.charName, locationName: chatB.locationName }));
  check('chat global: metadata locationName terkirim', !!chatB && chatB.locationName === 'Lab Komputer');
  check('chat global: field lama tetap ada (kompatibilitas)', !!chatB && !!chatB.username && !!chatB.fullName && !!chatB.timestamp && chatB.msgId !== undefined);

  // 2. Chat global TANPA metadata (klien lama) — harus tetap jalan
  A.msgs.length = 0; B.msgs.length = 0; C.msgs.length = 0;
  await wait(2500); // lewati cooldown
  A.send(JSON.stringify({ type: 'chat', scope: 'global', text: 'Pesan klien lama tanpa metadata' }));
  await wait(800);
  const chatB2 = B.msgs.find(x => x.type === 'chat_message');
  check('chat global tanpa metadata (klien lama): tetap terkirim + nama tersedia (fallback/cached)', !!chatB2 && !!chatB2.charName && !!chatB2.locationName, chatB2 && ('charName=' + chatB2.charName + ', loc=' + chatB2.locationName));

  // 3. Rate limit chat: kirim cepat → warning
  const warnCount = () => (A.msgs.filter(x => x.type === 'warning').length);
  A.send(JSON.stringify({ type: 'chat', scope: 'global', text: 'spam1' }));
  A.send(JSON.stringify({ type: 'chat', scope: 'global', text: 'spam2' }));
  await wait(500);
  check('rate-limit chat server-side aktif (warning terkirim)', warnCount() >= 1, 'warnings=' + warnCount());

  // 4. mission_progress dari klien WS
  A.msgs.length = 0; B.msgs.length = 0; C.msgs.length = 0;
  A.send(JSON.stringify({ type: 'mission_progress', missionId: 3, playerProgress: 45 }));
  await wait(500);
  const mpA = A.msgs.find(x => x.type === 'mission_progress');
  const mpB = B.msgs.find(x => x.type === 'mission_progress');
  const mpC = C.msgs.find(x => x.type === 'mission_progress');
  check('mission_progress (klien): dipancarkan ke seluruh serverId=1', !!mpA && !!mpB);
  check('mission_progress (klien): TERISOLASI dari server 2', !mpC);
  check('mission_progress: payload sesuai kontrak', !!mpB && mpB.serverId === 1 && mpB.missionId === 3 && !!mpB.playerId && mpB.playerProgress === 45 && typeof mpB.serverAggregate === 'number' && !!mpB.timestamp, JSON.stringify(mpB && { serverId: mpB.serverId, missionId: mpB.missionId, playerId: mpB.playerId, playerProgress: mpB.playerProgress, serverAggregate: mpB.serverAggregate }));
  check('mission_progress: playerId dari sesi (bukan payload klien)', !!mpB && mpB.playerId === REAL_USERS.A.id, 'playerId=' + (mpB && mpB.playerId));

  // 5. mission_progress dari endpoint internal (HMAC)
  B.msgs.length = 0;
  const body = JSON.stringify({ serverId: 1, missionId: 7, playerId: 555, playerName: 'Siswa Uji', playerProgress: 80, serverAggregate: 63.5 });
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = crypto.createHmac('sha256', APP_KEY).update(ts + '.' + body).digest('hex');
  const http = require('http');
  const post = (headers, body) => new Promise((resolve) => {
    const req = http.request({ host: '127.0.0.1', port: PORT, path: '/internal/mission-progress', method: 'POST', headers }, (res) => {
      let d = ''; res.on('data', c => d += c); res.on('end', () => resolve({ code: res.statusCode, body: d }));
    });
    req.end(body);
  });
  const rOk = await post({ 'Content-Type': 'application/json', 'X-VC-Timestamp': ts, 'X-VC-Signature': sig }, body);
  check('endpoint internal: HMAC valid → 200 + event dipancarkan', rOk.code === 200, rOk.body.slice(0, 140));
  await wait(400);
  const mpInt = B.msgs.find(x => x.type === 'mission_progress');
  check('endpoint internal: event diterima klien server 1', !!mpInt && mpInt.missionId === 7 && mpInt.playerId === 555 && mpInt.serverAggregate === 63.5, JSON.stringify(mpInt && { missionId: mpInt.missionId, playerId: mpInt.playerId, serverAggregate: mpInt.serverAggregate }));
  const rBad = await post({ 'Content-Type': 'application/json', 'X-VC-Timestamp': ts, 'X-VC-Signature': 'deadbeef' }, body);
  check('endpoint internal: signature salah → 403', rBad.code === 403, 'code=' + rBad.code);
  const rOld = await post({ 'Content-Type': 'application/json', 'X-VC-Timestamp': String(Math.floor(Date.now() / 1000) - 500), 'X-VC-Signature': sig }, body);
  check('endpoint internal: timestamp basi → 403', rOld.code === 403, 'code=' + rOld.code);

  // 6. Health: statistik room
  const health = await new Promise((resolve) => {
    http.get('http://127.0.0.1:' + PORT + '/health', (res) => { let d = ''; res.on('data', c => d += c); res.on('end', () => resolve(JSON.parse(d))); });
  });
  check('health: statistik room per serverId+world', !!health.rooms && health.rooms['s1:w3d'] >= 1 && health.rooms['s1:wpixel'] >= 1 && health.rooms['s2:w3d'] >= 1, JSON.stringify(health.rooms));

  [A, B, C].forEach(w => w.close());
  const fails = results.filter(r => !r[1]).length;
  console.log('\n== HASIL: ' + (results.length - fails) + '/' + results.length + ' lulus, ' + fails + ' gagal ==');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error('ERROR:', e.message); process.exit(2); });
