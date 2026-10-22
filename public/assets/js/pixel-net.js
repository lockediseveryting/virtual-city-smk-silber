/**
 * Virtual City SMK Silber — Pixel Net (multiplayer WS, server 8090 via proxy /ws/).
 * Menggunakan sistem multiplayer yang sama dengan dunia 3D (token HMAC /api/ws/token,
 * satu sesi per user per world; world=pixel). Move delta-only throttled, reconnect backoff.
 */
const PixelNet = {
  engine: null, ws: null, token: null, myId: 0,
  status: 'offline', backoff: 1, closedByUser: false, lastMoveSent: 0, lastMoveKey: '',
  handlers: {},

  init(engine) {
    this.engine = engine;
    this.connect();
  },

  on(evt, cb) { this.handlers[evt] = cb; },
  emit(evt, data) { if (this.handlers[evt]) this.handlers[evt](data); },
  setStatus(s) {
    this.status = s;
    this.emit('status', s);
  },

  currentServerId: 1, currentCode: '',
  async connect(serverId = null, code = '') {
    if (this.closedByUser) return;
    if (serverId !== null) this.currentServerId = serverId;
    if (code !== '') this.currentCode = code;
    this.setStatus('connecting');
    try {
      const csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
      let urlToken = '/api/ws/token?world=pixel&_csrf=' + encodeURIComponent(csrf);
      if (this.currentServerId) urlToken += '&server=' + encodeURIComponent(this.currentServerId);
      if (this.currentCode) urlToken += '&code=' + encodeURIComponent(this.currentCode);
      const res = await fetch(urlToken, { headers: { 'X-CSRF': csrf } });
      const d = await res.json();
      if (!d || d.status !== 'ok' || !d.url) {
        // Ditolak server (bukan anggota, kode salah, server dunia 3D…): JANGAN reconnect berulang.
        const errMsg = (d && d.message) ? d.message : 'Gagal memperoleh token server';
        this.closedByUser = true;
        this.setStatus('error');
        if (d && d.code === 'wrong_world') this.emit('wrong_world', d);
        this.emit('join_error', errMsg);
        return;
      }
      if (d.server_id) this.currentServerId = d.server_id;
      this.token = d.token;
      const url = d.url + (d.url.includes('?') ? '&' : '?') + 'world=pixel';
      this.open(url);
    } catch (e) {
      this.setStatus('error');
      this.scheduleReconnect();
    }
  },

  switchServer(serverId, code = '') {
    this.currentServerId = serverId;
    this.currentCode = code;
    this.closedByUser = false;
    try { if (this.ws) this.ws.close(); } catch (e) {}
    this.connect(serverId, code);
  },

  open(url) {
    try { this.ws = new WebSocket(url); } catch (e) { this.scheduleReconnect(); return; }
    this.ws.onopen = () => {
      this.backoff = 1;
      this.setStatus('online');
      const eng = this.engine;
      this.sendNow({ type: 'join', world: 'pixel', area: eng.currentAreaId ? eng.currentAreaId() : 'OUT', locationName: 'Pintu Masuk',
        floor: eng.floorSyncNum ? eng.floorSyncNum() : 1, x: this.toNet(eng.player.x), z: this.toNet(eng.player.z), yaw: 0 });
      // Gaya karakter kustom (v2) → pemain lain melihat pakaian & aksesoris yang sama.
      if (window.__AVATAR && window.__AVATAR.config) this.sendNow({ type: 'avatar_change', avatarConfig: window.__AVATAR.config });
    };
    this.ws.onmessage = (ev) => {
      let msg = null;
      try { msg = JSON.parse(ev.data); } catch (e) { return; }
      this.handle(msg);
    };
    this.ws.onclose = (ev) => {
      for (const [, r] of this.engine.remotes) r.left = true;
      this.engine.remotes.clear();
      // 4001 = token ditolak, 4002 = akun dibuka di tab/perangkat lain → jangan saling tendang tanpa henti.
      if (!this.closedByUser) {
        const final = ev.code === 4001 || ev.code === 4002;
        this.setStatus(final ? 'kicked' : 'reconnecting');
        if (final) this.emit('kicked', ev.code === 4002 ? 'Akun ini dibuka di tab/perangkat lain.' : 'Sesi multiplayer ditolak.');
        else this.scheduleReconnect();
      }
    };
    this.ws.onerror = () => { try { this.ws.close(); } catch (e) { /* abaikan */ } };
  },

  scheduleReconnect() {
    const delay = Math.min(30, this.backoff) * 1000;
    this.backoff = Math.min(30, this.backoff * 2);
    setTimeout(() => this.connect(this.currentServerId, this.currentCode), delay);
  },

  sendNow(obj) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      try { this.ws.send(JSON.stringify(obj)); } catch (e) { /* abaikan */ }
    }
  },

  pushMove(player, area) {
    const now = performance.now();
    if (now - this.lastMoveSent < 110) return;
    const key = Math.round(player.x * 10) + ':' + Math.round(player.z * 10) + ':' + player.dir + ':' + (player.interior || '') + ':' + (player.sitting ? 1 : 0);
    if (key === this.lastMoveKey && !player.moving) return;
    this.lastMoveSent = now;
    this.lastMoveKey = key;
    this.sendNow({
      type: 'move', world: 'pixel', x: Math.round(this.toNet(player.x) * 10) / 10, z: Math.round(this.toNet(player.z) * 10) / 10,
      yaw: Math.round(player.yaw * 100) / 100, floor: this.engine.floorSyncNum(),
      area: area, locationName: player.interior ? this.engine.interiors.get(player.interior).name : (this.engine.nearLoc ? this.engine.nearLoc.name : 'Jalan sekolah'),
      isMoving: player.moving, isSprinting: player.sprinting
    });
  },

  pushMoveNow(player, area) { this.lastMoveSent = 0; this.lastMoveKey = ''; this.pushMove(player, area); },

  // Dunia side-view memakai piksel (engine.netScale px = 1 unit jaringan) agar batas
  // kecepatan & radius minat server tetap wajar. Posisi pemain lain disimpan dalam unit jaringan.
  toNet(v) { return (Number(v) || 0) / ((this.engine && this.engine.netScale) || 1); },

  sendEmote(emote) { this.sendNow({ type: 'emote', emote: emote }); },

  sendChat(scope, text) {
    // area = ID lokasi resmi (divalidasi server); ID scene side-view bukan lokasi resmi.
    const area = (this.engine && this.engine.chatAreaId) ? this.engine.chatAreaId() : '';
    this.sendNow({ type: 'chat', scope: scope || 'global', text: String(text || '').slice(0, 300), area: area });
  },

  upsertRemote(id, data) {
    let r = this.engine.remotes.get(id);
    if (!r) {
      r = { ix: data.x, iz: data.z, animT: 0, emote: null, emoteT: 0 };
      this.engine.remotes.set(id, r);
      if (window.PixelAudio) PixelAudio.sfx('join');
      this.emit('presence');
    }
    Object.assign(r, data);
    return r;
  },

  handle(msg) {
    // Voice chat (voice.js): signaling + relay audio lewat WS yang sama dengan dunia 3D.
    if (window.VoiceChat && /^(init|player_join|player_leave|voice_signal|voice_pcm)$/.test(msg.type)) {
      try {
        VoiceChat.handleWs(msg.type === 'init'
          ? { type: 'init', self: msg.self, players: (msg.players || []).filter((p) => !p.world || p.world === 'pixel') }
          : msg);
      } catch (e) { /* suara tidak boleh mengganggu game */ }
    }
    // Anomaly Director (server) → anomaly.js menggambar kejadian yang sama untuk semua pemain.
    if (window.__vcAnomaly && window.__vcAnomaly.server) {
      if (msg.type === 'init' && msg.self) window.__vcAnomaly.server({ op: 'hello', me: msg.self.id });
      else if (msg.type === 'anomaly') window.__vcAnomaly.server(msg);
    }
    switch (msg.type) {
      case 'init': {
        const self = msg.self || {};
        this.myId = self.id || 0;
        this.engine.player.name = self.fullName || self.username || this.engine.player.name;
        for (const p of (msg.players || [])) {
          if (p.id === this.myId || (p.world && p.world !== 'pixel')) continue; // pemain dunia 3D beda koordinat
          this.upsertRemote(p.id, {
            x: p.x, z: p.z, floor: p.floor || 1, area: p.area || 'OUT', avatarConfig: p.avatarConfig || null,
            name: p.fullName || p.name || p.username || 'Pemain',
            color: p.programColor || p.color || '#38bdf8',
            role: p.role || 'murid'
          });
        }
        this.emit('presence');
        break;
      }
      case 'player_join': {
        const p = msg.player || msg;
        if (p.id === this.myId || (p.world && p.world !== 'pixel')) break;
        this.upsertRemote(p.id, {
          x: p.x, z: p.z, floor: p.floor || 1, area: p.area || 'OUT', avatarConfig: p.avatarConfig || null,
          name: p.fullName || p.name || p.username || 'Pemain',
          color: p.programColor || p.color || '#38bdf8',
          role: p.role || 'murid'
        });
        break;
      }
      case 'player_move': {
        if (msg.world && msg.world !== 'pixel') break;
        const r = this.engine.remotes.get(msg.id);
        if (!r) break;
        r.x = msg.x; r.z = msg.z;
        r.floor = msg.floor || 1;
        r.area = msg.area || 'OUT';
        r.sit = msg.yaw === 9; // yaw 9 = sedang duduk (lihat PixelWorld.sitOn)
        r.dir = (typeof msg.yaw === 'number' && !r.sit) ? this.yawToDir(msg.yaw) : r.dir;
        r.moving = !!msg.isMoving; r.sprint = !!msg.isSprinting;
        r.locationName = msg.locationName || '';
        break;
      }
      case 'player_leave': {
        if (this.engine.remotes.delete(msg.id)) {
          if (window.PixelAudio) PixelAudio.sfx('leave');
          this.emit('presence');
        }
        break;
      }
      case 'player_avatar_change': {
        const r = this.engine.remotes.get(msg.id);
        if (r && msg.avatarConfig && typeof msg.avatarConfig === 'object') r.avatarConfig = msg.avatarConfig;
        break;
      }
      case 'player_emote': {
        const r = this.engine.remotes.get(msg.id);
        if (r) { r.emote = msg.emote; r.emoteT = 2.4; r.emoteStart = this.engine.clock || 0; }
        break;
      }
      case 'chat_message': {
        if (window.PixelAudio) PixelAudio.sfx('chat');
        this.emit('chat', msg);
        break;
      }
      case 'kicked': {
        this.closedByUser = true;
        this.setStatus('kicked');
        this.emit('kicked', msg.message || 'Koneksi digantikan perangkat lain.');
        break;
      }
      case 'warning': {
        this.emit('warning', msg.message || '');
        break;
      }
      case 'server_deleted': {
        // Integrasi Modul Game: server dihapus admin → hentikan reconnect & umumkan.
        this.closedByUser = true;
        try { if (this.ws) this.ws.close(); } catch (e) { /* abaikan */ }
        this.emit('server_deleted', msg.message || 'Server permainan ini telah dihapus.');
        break;
      }
    }
  },

  yawToDir(yaw) {
    const deg = ((yaw * 180 / Math.PI) % 360 + 360) % 360;
    if (deg >= 315 || deg < 45) return 3;
    if (deg >= 45 && deg < 135) return 2;
    if (deg >= 135 && deg < 225) return 0;
    return 1;
  },

  destroy() {
    this.closedByUser = true;
    try { if (this.ws) this.ws.close(); } catch (e) { /* abaikan */ }
  }
};

window.PixelNet = PixelNet;
