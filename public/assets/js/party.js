/*
 * MABAR (party) — main bareng sampai 15 orang di dashboard secara real time.
 *  - Kapten membuat party (kode 6 huruf) lalu mengundang teman (notifikasi + pop-up) / membagi kode.
 *  - Anggota yang membuka dashboard berdiri bersama di panggung kota; ketuk jalan → karakter
 *    berjalan (posisi tersinkron), chat (balon di atas kepala, tersimpan & dimoderasi seperti chat
 *    game), mic (WebRTC voice.js, indikator 🔊), kapten pilih tujuan → "Berangkat!" → semua pindah.
 *  - Ringan untuk HP: tanpa loop animasi (posisi = transisi CSS), gerak dikirim ≤ 4×/detik,
 *    sprite digambar sekali, voice dimuat hanya saat bergabung party.
 *  - Halaman lain (mis. profil) cukup memakai window.vcParty.invite(userId).
 *  - Status online dari database (🟢 / terakhir dilihat): undangan hanya ke yang online.
 *  - Mabar PUBLIK (tampil di daftar, siapa pun masuk) atau KODE (kode / link / QR lewat vcShare).
 *  - MISI MABAR: kapten memilih tujuan bersama (Tur Sekolah, Sahabat Kucing, Jumat Bersih, Pemburu Anomali);
 *    progres dari dunia game (world-life.js / anomaly.js / kunjungan) → hadiah XP + koin semua anggota.
 * ES2017 tanpa ?. / ??.
 */
(function () {
  'use strict';
  var pxd = document.getElementById('pxd');
  var stage = document.getElementById('pxdStage');
  var myChar = document.getElementById('pxdChar');
  var PA = window.PixelAvatar;
  var state = { me: 0, party: null, invites: [], dests: [], pub: [], quests: [], online: [] };
  var ws = null, wsTries = 0, wsTimer = null, online = {}, mates = {}, myX = 34.2, lastSend = 0, sendTimer = null;
  var panel = null, inviteShown = {}, pollTimer = null;
  var X_MIN = 20, X_MAX = 52;

  function csrf() { return (document.querySelector('meta[name="csrf"]') || {}).content || ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function api(action, body) {
    body = body || {};
    body.action = action;
    return fetch('/api/party/action', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { status: 'error', message: 'Server tidak merespons.' }; }); })
      .catch(function () { return { status: 'error', message: 'Koneksi gagal.' }; });
  }
  function load() {
    return fetch('/api/party', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || d.status !== 'ok') return state;
        var had = state.party ? state.party.id : 0;
        state.me = d.me; state.party = d.party; state.invites = d.invites || []; state.dests = d.dests || [];
        state.pub = d['public'] || []; state.quests = d.quests || []; state.online = d.online || [];
        if (pxd) {
          if (state.party && (!ws || had !== state.party.id)) connect();
          if (!state.party && ws) disconnect();
          syncMates();
          showInvites();
        }
        renderPanel();
        return state;
      }).catch(function () { return state; });
  }
  function isCaptain() { return !!(state.party && state.party.captain_id === state.me); }
  function member(id) {
    var ms = state.party ? state.party.members : [];
    for (var i = 0; i < ms.length; i++) if (ms[i].id === id) return ms[i];
    return null;
  }

  // ---------------- WebSocket room party ----------------
  function connect() {
    disconnect();
    fetch('/api/party/token?_csrf=' + encodeURIComponent(csrf()), { credentials: 'same-origin', headers: { 'X-CSRF': csrf() } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || d.status !== 'ok') return;
        open(d.url, d.direct_url);
      }).catch(function () {});
  }
  function open(url, fallback) {
    var sock;
    try { sock = new WebSocket(url); } catch (e) { if (fallback) open(fallback, null); return; }
    ws = sock;
    var opened = false;
    sock.onopen = function () {
      opened = true; wsTries = 0;
      // Posisi awal berbeda per anggota (tidak menumpuk di satu titik).
      var ms = state.party ? state.party.members : [], idx = 0;
      for (var i = 0; i < ms.length; i++) if (ms[i].id === state.me) idx = i;
      myX = X_MIN + 4 + ((idx * 7) % (X_MAX - X_MIN - 6));
      if (myChar) myChar.style.left = myX + '%';
      send({ type: 'join', x: myX, y: 0, z: 0, floor: 1, locationName: 'Dashboard' });
      setStatus('🟢 Tersambung — mic & chat aktif untuk anggota yang online');
      ensureVoice(); // dengar suara teman walau mic sendiri mati
    };
    sock.onmessage = function (ev) {
      var d; try { d = JSON.parse(ev.data); } catch (e) { return; }
      onWs(d);
      if (window.VoiceChat) window.VoiceChat.handleWs(d);
      else if (d && /^(voice_signal|player_join|player_leave)$/.test(d.type) && voiceQueue.length < 200) voiceQueue.push(d); // offer teman tidak hilang selagi voice.js dimuat
    };
    sock.onclose = function (ev) {
      if (ws !== sock) return;
      ws = null;
      online = {}; syncMates();
      if (!opened && fallback) { open(fallback, null); return; }
      if (ev.code === 4002 || ev.code === 4003 || ev.code === 4001) { setStatus(ev.code === 4002 ? '⚠️ Mabar terbuka di tab lain' : '⚪ Terputus'); if (ev.code === 4003) load(); return; }
      setStatus('🟡 Menyambung ulang…');
      if (state.party && wsTries < 6) { wsTries++; wsTimer = setTimeout(connect, 1500 * wsTries); }
    };
  }
  function disconnect() {
    clearTimeout(wsTimer);
    if (ws) { var s = ws; ws = null; try { s.close(); } catch (e) {} }
    online = {};
  }
  function send(o) { if (ws && ws.readyState === 1) ws.send(JSON.stringify(o)); }

  function onWs(d) {
    switch (d.type) {
      case 'init':
        online = {};
        (d.players || []).forEach(function (p) { online[p.id] = { x: Number(p.x) || 34 }; });
        syncMates(); renderPanel();
        break;
      case 'player_join':
        if (d.player) { online[d.player.id] = { x: Number(d.player.x) || 34 }; load(); }
        break;
      case 'player_leave':
        delete online[d.id]; syncMates(); renderPanel();
        break;
      case 'player_move':
        if (online[d.id]) { online[d.id].x = Number(d.x) || online[d.id].x; placeMate(d.id); }
        break;
      case 'chat_message':
        addChat(d.id, d.fullName || d.username, d.text);
        bubble(d.id, d.text);
        break;
      case 'emote':
      case 'player_emote': // server mengirim player_emote {id, emote}; dulu hanya 'emote' → emote teman tidak pernah tampil
        if (d.id && d.emote && Number(d.id) !== state.me) {
          var PA = window.PixelAvatar, nm = String(d.emote).slice(0, 10);
          bubble(d.id, (PA && PA.EMOTES[nm]) || nm.slice(0, 4));
          if (mates[d.id] && window.vcAnimEmote) window.vcAnimEmote(mates[d.id].el.querySelector('.pxd-mate__link'), nm);
        }
        break;
      case 'warning':
        if (d.message) addChat(0, 'Sistem', d.message);
        break;
      case 'party_update':
        load();
        break;
      case 'party_kick':
        if (Number(d.id) === state.me) { toast('Kamu dikeluarkan dari mabar oleh kapten.'); disconnect(); load(); } else load();
        break;
      case 'party_go':
        goCountdown(d.url, d.by);
        break;
      case 'party_quest':
        if (d.quest && state.party) {
          state.party.quest = d.quest;
          if (d.done) toast('🎉 Misi mabar selesai: ' + d.quest.title + '! +' + d.quest.xp + ' XP · +' + d.quest.coins + ' koin untuk semua.');
          renderPanel();
        }
        break;
    }
  }

  // ---------------- Panggung: teman-teman berdiri bersama ----------------
  function syncMates() {
    if (!stage) return;
    var keep = {};
    Object.keys(online).forEach(function (k) {
      var id = Number(k);
      if (id === state.me || !member(id)) return;
      keep[id] = 1;
      if (!mates[id]) mates[id] = makeMate(member(id));
      placeMate(id);
    });
    Object.keys(mates).forEach(function (k) {
      if (!keep[k]) { var el = mates[k].el; if (el.parentNode) el.parentNode.removeChild(el); delete mates[k]; }
    });
    if (myChar) myChar.classList.toggle('is-party', !!state.party);
  }
  function makeMate(m) {
    var el = document.createElement('div');
    el.className = 'pxd-mate';
    el.innerHTML = '<span class="pxd-mate__bubble" hidden></span><span class="pxd-mate__tag">' + (m.captain ? '👑 ' : '') + esc(String(m.name).split(' ')[0]) + '<i class="pxd-mate__spk" hidden>🔊</i></span>'
      + '<a class="pxd-mate__link" href="/profil?id=' + m.id + '" title="Profil ' + esc(m.name) + '"><canvas width="22" height="42"></canvas></a><span class="pxd-char__shadow"></span>';
    if (PA) { try { PA.paint(el.querySelector('canvas'), PA.normalize(m.avatar), false); } catch (e) {} }
    stage.insertBefore(el, myChar);
    return { el: el, m: m };
  }
  function placeMate(id) {
    var mt = mates[id], o = online[id];
    if (!mt || !o) return;
    var x = Math.max(X_MIN, Math.min(X_MAX, o.x));
    var prev = parseFloat(mt.el.style.left) || x;
    mt.el.style.left = x + '%';
    if (Math.abs(prev - x) > 0.3) {
      mt.el.classList.toggle('is-flip', x < prev);
      mt.el.classList.add('is-walk');
      clearTimeout(mt.t); mt.t = setTimeout(function () { mt.el.classList.remove('is-walk'); }, 650);
    }
  }
  function bubble(id, text) {
    var el = null;
    if (id === state.me) {
      var b = document.getElementById('pxdBubble');
      if (b) { b.textContent = text; b.classList.remove('is-hidden'); clearTimeout(bubble.t); bubble.t = setTimeout(function () { b.classList.add('is-hidden'); }, 6000); }
      return;
    }
    if (mates[id]) el = mates[id].el.querySelector('.pxd-mate__bubble');
    if (!el) return;
    el.textContent = text; el.hidden = false;
    clearTimeout(el._t); el._t = setTimeout(function () { el.hidden = true; }, 6000);
  }
  function speaking(id, on) {
    if (id === state.me) { var mic = document.getElementById('ptyMic'); if (mic) mic.classList.toggle('is-live', on); return; }
    if (mates[id]) { var s = mates[id].el.querySelector('.pxd-mate__spk'); if (s) s.hidden = !on; }
    var row = document.querySelector('[data-pty-member="' + id + '"] .pty-spk'); if (row) row.hidden = !on;
  }
  // Ketuk jalan/trotoar → karakter sendiri berjalan ke sana (saat di party).
  if (stage) stage.addEventListener('click', function (e) {
    if (!state.party || (e.target.closest && e.target.closest('button,a,.pxd-mate'))) return;
    var r = stage.getBoundingClientRect();
    if (e.clientY < r.top + r.height * 0.55) return; // hanya area jalan
    walkTo(((e.clientX - r.left) / r.width) * 100);
  });
  function walkTo(x) {
    x = Math.max(X_MIN, Math.min(X_MAX, x));
    if (!myChar) return;
    myChar.classList.toggle('is-flip', x < myX);
    myChar.classList.add('is-walk');
    clearTimeout(walkTo.t); walkTo.t = setTimeout(function () { myChar.classList.remove('is-walk'); }, 800);
    myX = x;
    myChar.style.left = x + '%';
    var now = Date.now();
    clearTimeout(sendTimer);
    if (now - lastSend > 250) { lastSend = now; send({ type: 'move', x: myX, y: 0, z: 0, yaw: 0, floor: 1, isMoving: true }); }
    else sendTimer = setTimeout(function () { lastSend = Date.now(); send({ type: 'move', x: myX, y: 0, z: 0, yaw: 0, floor: 1, isMoving: false }); }, 260);
  }

  // ---------------- Voice ----------------
  var voiceLoaded = false;
  var voiceQueue = [];
  function ensureVoice() {
    if (window.VoiceChat) return Promise.resolve(window.VoiceChat);
    if (voiceLoaded) return new Promise(function (res) { setTimeout(function () { res(window.VoiceChat || null); }, 600); });
    voiceLoaded = true;
    return new Promise(function (res) {
      var s = document.createElement('script');
      s.src = '/assets/js/voice.js?v=7.7.0';
      s.onload = function () {
        var V = window.VoiceChat;
        if (!V) { res(null); return; }
        V.init(send);
        V.onSpeaking = speaking;
        V.onError = function (m) { toast(m); };
        // sambungkan peer ke anggota yang sudah online
        if (ws) V.handleWs({ type: 'init', self: { id: state.me }, players: Object.keys(online).map(function (k) { return { id: Number(k) }; }) });
        var q = voiceQueue; voiceQueue = [];
        q.forEach(function (m) { V.handleWs(m); });
        res(V);
      };
      s.onerror = function () { res(null); };
      document.head.appendChild(s);
    });
  }
  function toggleMic() {
    ensureVoice().then(function (V) {
      if (!V) { toast('Voice chat tidak tersedia di perangkat ini.'); return; }
      V.unlockAudio();
      V.setMic(!V.micOn).then(function (on) {
        var b = document.getElementById('ptyMic');
        if (b) { b.textContent = on ? '🎤 Mic ON' : '🔇 Mic OFF'; b.classList.toggle('px-btn--gold', on); }
      });
    });
  }

  // Anggota (bukan kapten) tidak boleh masuk dunia duluan / ke dunia lain sebelum kapten berangkat.
  // Server juga menolak (WorldController::partyLocked) — ini hanya agar langsung terasa di dashboard.
  document.addEventListener('click', function (ev) {
    if (!state.party || isCaptain()) return;
    var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a || a.target === '_blank') return;
    var path = a.pathname || '';
    var world = /^\/(dunia|peta)/.test(path) ? 'dunia' : (/^\/pixel/.test(path) ? 'pixel' : (/^\/gabung/.test(path) ? 'any' : ''));
    if (!world) return;
    var dest = state.party.dest_url || '';
    if (dest && world !== 'any' && dest.indexOf('/' + world) === 0) return;
    ev.preventDefault(); ev.stopPropagation();
    toast(dest ? '🚀 Kapten memilih tujuan lain — ikuti kapten ya.' : '⏳ Tunggu kapten ' + (state.party.captain_name || '') + ' memilih tujuan. Kamu ikut berangkat otomatis.');
  }, true);

  // ---------------- Berangkat bareng ----------------
  function goCountdown(url, by) {
    if (!url || !/^\/(dunia|pixel)/.test(url)) return;
    var el = document.createElement('div');
    el.className = 'pty-go';
    document.body.appendChild(el);
    var n = 3;
    (function tick() {
      el.innerHTML = '<b>🚀 ' + esc(by || 'Kapten') + ' mengajak berangkat!</b><span>' + n + '</span>';
      if (n-- <= 0) { location.href = url; return; }
      setTimeout(tick, 1000);
    })();
  }

  // ---------------- Panel MABAR ----------------
  function toast(t) {
    var el = document.createElement('div');
    el.className = 'pty-toast'; el.setAttribute('role', 'status'); el.textContent = t;
    document.body.appendChild(el);
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 4200);
  }
  function setStatus(t) { var s = document.getElementById('ptyStatus'); if (s) s.textContent = t; state.status = t; }
  var chatLog = [];
  function addChat(id, name, text) {
    chatLog.push({ id: id, name: name, text: text });
    if (chatLog.length > 40) chatLog.shift();
    var box = document.getElementById('ptyChat');
    if (box) { box.insertAdjacentHTML('beforeend', chatRow(chatLog[chatLog.length - 1])); box.scrollTop = box.scrollHeight; }
    var badge = document.getElementById('ptyBadge');
    if (badge && (!panel || panel.hidden)) { badge.hidden = false; }
  }
  function chatRow(c) { return '<p class="' + (c.id === state.me ? 'is-me' : '') + '"><b>' + esc(c.name) + ':</b> ' + esc(c.text) + '</p>'; }

  function userRow(u) {
    var inParty = !!member(u.id);
    var btn = inParty ? '<em>sudah ikut</em>'
      : u.online ? '<button type="button" class="px-btn" data-pty="invite" data-id="' + u.id + '">Undang</button>'
      : '<button type="button" class="px-btn" disabled title="Hanya pengguna online yang bisa diundang">Offline</button>';
    return '<div class="pty-inv"><span><i class="pty-dot' + (u.online ? ' is-on' : '') + '"></i> <b>' + esc(u.full_name || u.username) + '</b> <small>' + esc(u.class_name || u.role || '')
      + ' · ' + esc(u.presence || (u.online ? 'Online' + (u.place ? ' · ' + u.place : '') : 'Offline')) + '</small></span>' + btn + '</div>';
  }
  function onlineList() {
    var list = state.online.filter(function (u) { return !member(u.id); });
    return '<h3>🟢 Sedang online — langsung undang (' + list.length + ')</h3>' + (list.length
      ? '<div class="pty-results pty-online">' + list.slice(0, 12).map(userRow).join('') + '</div>'
      : '<p class="pty-hint">Belum ada teman lain yang online. Bagikan kode / QR mabar ke grup kelas.</p>');
  }
  function searchBox() {
    return '<form class="pty-join" data-pty-form="search"><input name="q" maxlength="40" placeholder="🔎 Atau cari nama teman (online / offline)…" autocomplete="off"><button class="px-btn" type="submit">Cari</button></form><div class="pty-results" id="ptyResults"></div>';
  }
  function questBox(q, cap) {
    var h = '<h3>🎯 Misi mabar</h3>';
    if (q) {
      var pct = Math.round(100 * q.progress / Math.max(1, q.target));
      h += '<div class="pty-quest' + (q.done ? ' is-done' : '') + '"><b>' + esc(q.icon + ' ' + q.title) + '</b><small>' + esc(q.desc) + '</small>'
        + '<div class="pty-bar" role="progressbar" aria-valuemin="0" aria-valuemax="' + q.target + '" aria-valuenow="' + q.progress + '"><i style="width:' + pct + '%"></i></div>'
        + '<small>' + (q.done ? '✅ Selesai! Hadiah +' + q.xp + ' XP · +' + q.coins + ' koin sudah masuk ke semua anggota.' : q.progress + ' / ' + q.target + ' · hadiah +' + q.xp + ' XP · +' + q.coins + ' koin tiap anggota') + '</small></div>';
    } else {
      h += '<p class="pty-hint">' + (cap ? 'Pilih misi supaya mabar punya tujuan — kerjakan bareng di Dunia 3D / Pixel.' : 'Kapten belum memilih misi.') + '</p>';
    }
    if (cap) {
      h += '<div class="pty-quests">' + state.quests.map(function (x) {
        var active = q && q.key === x.key && !q.done;
        return '<button type="button" class="px-btn' + (active ? ' px-btn--gold' : '') + '" data-pty="quest" data-q="' + esc(x.key) + '" title="' + esc(x.desc) + '">' + esc(x.icon + ' ' + x.title) + '</button>';
      }).join('') + '</div>';
    }
    return h;
  }

  function renderPanel() {
    if (!panel || panel.hidden) return;
    var body = panel.querySelector('.pty-body');
    var p = state.party, h = '';
    if (!p) {
      h += '<p class="pty-lead">Main bareng sampai <b>15 orang</b>: ngobrol, nyalakan mic, lalu kapten memilih tujuan dan semua berangkat bersama.</p>';
      h += onlineList() + searchBox();
      h += '<div class="pty-row"><button type="button" class="px-btn px-btn--gold" data-pty="create" data-vis="public">🌐 Buat Mabar Publik</button>'
        + '<button type="button" class="px-btn" data-pty="create" data-vis="code">🔒 Buat Mabar Pakai Kode</button></div>';
      h += '<p class="pty-hint">🌐 Publik = tampil di daftar, siapa pun bisa masuk. 🔒 Kode = hanya lewat kode, link, QR, atau undangan.</p>';
      h += '<form class="pty-join" data-pty-form="join"><input name="code" maxlength="6" placeholder="Kode mabar (6 huruf)" autocomplete="off" style="text-transform:uppercase" value="' + esc(panel.getAttribute('data-code') || '') + '"><button class="px-btn" type="submit">Gabung</button></form>';
      h += '<p class="pty-hint">📷 Punya QR dari teman? Scan pakai kamera HP — langsung terbuka di sini.</p>';
      h += '<h3>🌐 Mabar publik</h3>';
      h += state.pub.length ? state.pub.map(function (pp) {
        return '<div class="pty-inv"><span><b>' + esc(pp.name) + '</b> <small>👑 ' + esc(pp.captain_name) + ' · ' + pp.size + '/15' + (pp.quest ? ' · ' + esc(pp.quest) : '') + '</small></span>'
          + '<button type="button" class="px-btn px-btn--gold" data-pty="accept" data-id="' + pp.id + '">Masuk</button></div>';
      }).join('') : '<p class="pty-hint">Belum ada mabar publik. Buat satu — teman lain bisa langsung masuk!</p>';
      if (state.invites.length) {
        h += '<h3>📨 Undangan masuk</h3>';
        state.invites.forEach(function (iv) {
          h += '<div class="pty-inv"><span><b>' + esc(iv.from_name) + '</b> mengajak ke <b>' + esc(iv.name) + '</b> (' + iv.size + '/15)</span>'
            + '<button type="button" class="px-btn px-btn--gold" data-pty="accept" data-id="' + iv.party_id + '">Gabung</button><button type="button" class="px-btn px-btn--dark" data-pty="decline" data-id="' + iv.party_id + '">Tolak</button></div>';
        });
      }
    } else {
      var cap = isCaptain();
      h += '<div class="pty-head"><div><b>' + esc(p.name) + '</b><small>Kapten: 👑 ' + esc(p.captain_name) + ' · ' + p.members.length + '/' + p.max + '</small></div>'
        + '<button type="button" class="pty-code px-plain" data-pty="share" title="Bagikan link & QR">Kode: <b>' + esc(p.code) + '</b> 🔗 QR</button></div>';
      h += '<p class="pty-vis">' + (p.visibility === 'public' ? '🌐 <b>Publik</b> — tampil di daftar, siapa pun bisa masuk.' : '🔒 <b>Pakai kode</b> — hanya lewat kode / link / QR / undangan.')
        + (cap ? ' <button type="button" class="px-plain pty-link" data-pty="vis">' + (p.visibility === 'public' ? 'Jadikan pakai kode' : 'Jadikan publik') + '</button>' : '') + '</p>';
      if (cap) h += onlineList() + searchBox(); // yang online tampil paling atas untuk diundang
      h += questBox(p.quest, cap);
      h += '<p class="pty-status" id="ptyStatus">' + esc(state.status || '…') + '</p>';
      h += '<ul class="pty-members">';
      p.members.forEach(function (m) {
        var on = m.id === state.me || !!online[m.id];
        h += '<li data-pty-member="' + m.id + '" class="' + (on ? 'is-on' : '') + '"><span class="pty-dot"></span><a href="/profil?id=' + m.id + '">' + (m.captain ? '👑 ' : '') + esc(m.name) + (m.id === state.me ? ' (kamu)' : '') + '</a>'
          + '<small>' + esc(m.class_name || '') + (on ? '' : ' · ' + (m.online ? '🟢 ' + esc(m.presence || 'Online') : '⚪ ' + esc(m.presence || 'Offline'))) + '</small><i class="pty-spk" hidden>🔊</i>'
          + (cap && m.id !== state.me ? '<button type="button" class="px-plain pty-kick" data-pty="kick" data-id="' + m.id + '" title="Keluarkan">✕</button>' : '') + '</li>';
      });
      h += '</ul>';
      if (cap) {
        h += '<h3>🧭 Tujuan</h3><div class="pty-row"><select id="ptyDest"><option value="/dunia">🚀 Dunia 3D (server umum)</option><option value="/pixel">🧱 Dunia Pixel (server umum)</option>';
        state.dests.forEach(function (d) { h += '<option value="' + esc(d.url) + '">' + esc(d.label) + (d.online ? ' · ' + d.online + ' online' : '') + '</option>'; });
        h += '</select><button type="button" class="px-btn px-btn--gold" data-pty="go">🚀 Berangkat bareng!</button></div>';
      } else {
        h += '<p class="pty-wait">⏳ Menunggu kapten memilih tujuan… Kamu akan ikut berangkat otomatis.</p>';
      }
      h += '<h3>💬 Chat mabar</h3><div class="pty-chat" id="ptyChat">' + chatLog.map(chatRow).join('') + '</div>'
        + '<form class="pty-join" data-pty-form="chat"><input name="text" maxlength="200" placeholder="Ketik pesan…" autocomplete="off"><button class="px-btn" type="submit">Kirim</button></form>';
      h += '<div class="pty-row"><button type="button" class="px-btn" id="ptyMic" data-pty="mic">' + (window.VoiceChat && window.VoiceChat.micOn ? '🎤 Mic ON' : '🔇 Mic OFF') + '</button>'
        + '<button type="button" class="px-btn px-btn--dark" data-pty="leave">🚪 Keluar mabar</button></div>';
      h += '<p class="pty-hint">Tip: ketuk jalan di depan toko untuk menggerakkan karakter. Ketuk karakter teman untuk membuka profilnya.</p>';
    }
    var keepChat = document.activeElement && document.activeElement.name === 'text' ? document.activeElement.value : null;
    body.innerHTML = h;
    if (keepChat !== null) { var ti = body.querySelector('[name=text]'); if (ti) { ti.value = keepChat; ti.focus(); } }
    var cb = document.getElementById('ptyChat'); if (cb) cb.scrollTop = cb.scrollHeight;
  }

  function openPanel(code) {
    if (!panel) {
      panel = document.createElement('div');
      panel.className = 'pxd-pick pty';
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
      panel.setAttribute('aria-label', 'Mabar');
      panel.innerHTML = '<div class="pxd-pick__box px-panel pty-box"><div class="pxd-pick__head"><h2>🎮 Mabar</h2><button type="button" class="px-btn px-btn--dark pxd-pick__close" data-pty="close" aria-label="Tutup">✕</button></div><div class="pty-body"></div></div>';
      document.body.appendChild(panel);
      panel.addEventListener('click', onClick);
      panel.addEventListener('submit', onSubmit);
    }
    if (code) panel.setAttribute('data-code', code);
    panel.hidden = false;
    var badge = document.getElementById('ptyBadge'); if (badge) badge.hidden = true;
    renderPanel();
    load();
  }
  function onClick(e) {
    if (e.target === panel) { panel.hidden = true; return; }
    var b = e.target.closest ? e.target.closest('[data-pty]') : null;
    if (!b) return;
    var act = b.getAttribute('data-pty'), id = parseInt(b.getAttribute('data-id') || '0', 10);
    if (act === 'close') { panel.hidden = true; return; }
    if (act === 'mic') { toggleMic(); return; }
    if (act === 'share') {
      var pc = state.party ? state.party.code : '';
      if (window.vcShare) window.vcShare.open({ title: 'Ajak mabar: ' + (state.party ? state.party.name : ''), url: '/?mabar=' + pc, code: pc,
        text: 'Ayo mabar bareng di Virtual City SMK Silber!', note: 'Teman yang scan QR / buka link langsung diajak masuk mabar ini (perlu login).' });
      return;
    }
    if (act === 'vis') {
      api('visibility', { visibility: state.party && state.party.visibility === 'public' ? 'code' : 'public' }).then(function (d) { toast(d.message || ''); load(); });
      return;
    }
    if (act === 'quest') {
      var qk = b.getAttribute('data-q');
      api('quest', { quest: qk }).then(function (d) { toast(d.message || ''); load(); });
      return;
    }
    if (act === 'copy') {
      var code = state.party ? state.party.code : '';
      var link = location.origin + '/?mabar=' + code;
      if (navigator.clipboard) navigator.clipboard.writeText('Ayo mabar di Virtual City! Kode: ' + code + ' → ' + link).then(function () { toast('Kode & link mabar disalin.'); }, function () { toast('Kode: ' + code); });
      else toast('Kode: ' + code);
      return;
    }
    if (act === 'go') {
      var dest = document.getElementById('ptyDest');
      api('go', { dest: dest ? dest.value : '/dunia' }).then(function (d) {
        if (d.status === 'ok') goCountdown(d.url, 'Kamu'); else toast(d.message || 'Gagal.');
      });
      return;
    }
    if (act === 'leave' && !confirm('Keluar dari mabar?')) return;
    if (act === 'kick' && !confirm('Keluarkan anggota ini dari mabar?')) return;
    if (act === 'invite') { invite(id).then(function (d) { toast(d.message || ''); if (d.status === 'ok') b.textContent = '✅ Diundang'; }); return; }
    var map = { create: 'create', leave: 'leave', kick: 'kick', accept: 'join', decline: 'decline' };
    if (!map[act]) return;
    b.disabled = true;
    var body = act === 'kick' ? { user_id: id } : (act === 'accept' || act === 'decline') ? { party_id: id } : act === 'create' ? { visibility: b.getAttribute('data-vis') || 'code' } : {};
    api(map[act], body).then(function (d) {
      b.disabled = false;
      if (d.message) toast(d.message);
      if (act === 'leave') { disconnect(); chatLog = []; }
      load();
    });
  }
  function onSubmit(e) {
    e.preventDefault();
    var f = e.target, kind = f.getAttribute('data-pty-form');
    if (kind === 'join') {
      var code = (f.elements.code.value || '').trim().toUpperCase();
      if (code.length < 6) { toast('Kode mabar 6 huruf.'); return; }
      api('join', { code: code }).then(function (d) { toast(d.message || ''); panel.removeAttribute('data-code'); load(); });
    } else if (kind === 'chat') {
      var t = f.elements.text.value.trim();
      if (!t) return;
      if (!ws) { toast('Belum tersambung ke room mabar.'); return; }
      send({ type: 'chat', scope: 'global', text: t, locationName: 'Mabar' });
      f.elements.text.value = '';
    } else if (kind === 'search') {
      var q = f.elements.q.value.trim(), out = document.getElementById('ptyResults');
      if (q.length < 2) { out.textContent = 'Ketik minimal 2 huruf.'; return; }
      out.textContent = 'Mencari…';
      fetch('/api/users/search?q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        var list = (d && d.users) || [];
        out.innerHTML = list.length ? list.filter(function (u) { return u.id !== state.me; }).map(userRow).join('') : 'Tidak ditemukan.';
      }).catch(function () { out.textContent = 'Gagal mencari.'; });
    }
  }

  // Pop-up undangan: dialog di tengah layar (di atas semua banner), bunyi, Gabung sekarang / Tolak.
  // Tidak dijawab 45 dtk → ditutup (undangan tetap ada di panel Mabar). Tolak = undangan dihapus.
  var inviteQ = [], inviteOpen = false;
  function showInvites() {
    state.invites.forEach(function (iv) {
      var key = 'vc_pty_inv_' + iv.party_id;
      if (inviteShown[key] || state.party) return;
      try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, '1'); } catch (e) {}
      inviteShown[key] = 1;
      inviteQ.push(iv);
    });
    nextInvite();
  }
  function nextInvite() {
    if (inviteOpen || !inviteQ.length || state.party) return;
    var iv = inviteQ.shift();
    inviteOpen = true;
    var el = document.createElement('div');
    el.className = 'pty-ask';
    el.setAttribute('role', 'alertdialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML = '<div class="pty-ask__box px-panel"><div class="pty-ask__ic">🎮</div>'
      + '<h2>Ajakan Mabar!</h2><p><b>' + esc(iv.from_name) + '</b> mengajak kamu main bareng.</p>'
      + '<p class="pty-ask__meta">' + esc(iv.name) + ' · ' + iv.size + '/15 orang</p>'
      + '<div class="pty-ask__act"><button type="button" class="px-btn px-btn--gold" data-a="y">✅ Gabung sekarang</button>'
      + '<button type="button" class="px-btn px-btn--dark" data-a="n">❌ Tolak</button></div>'
      + '<p class="pty-ask__t">Otomatis ditutup dalam <span>45</span> dtk</p></div>';
    document.body.appendChild(el);
    if (window.vcChime) try { window.vcChime(); } catch (e) {}
    try { if (navigator.vibrate) navigator.vibrate([80, 40, 80]); } catch (e) {}
    var left = 45, sp = el.querySelector('.pty-ask__t span');
    var tm = setInterval(function () { left--; if (sp) sp.textContent = left; if (left <= 0) done(null); }, 1000);
    function done(a) {
      clearInterval(tm);
      if (el.parentNode) el.parentNode.removeChild(el);
      inviteOpen = false;
      if (a === 'y') api('join', { party_id: iv.party_id }).then(function (d) { toast(d.message || ''); load().then(function () { openPanel(); }); });
      else if (a === 'n') api('decline', { party_id: iv.party_id }).then(function () { toast('Ajakan dari ' + iv.from_name + ' ditolak.'); load(); });
      setTimeout(nextInvite, 400);
    }
    el.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-a]') : null;
      if (b) done(b.getAttribute('data-a'));
    });
    var y = el.querySelector('[data-a="y"]'); if (y) y.focus();
  }

  function invite(userId) { return api('invite', { user_id: userId }).then(function (d) { load(); return d; }); }

  window.vcParty = { open: openPanel, invite: invite, state: function () { return state; },
    /** Emote dashboard → teman mabar yang online ikut melihat. */
    emote: function (name) { if (ws) send({ type: 'emote', emote: String(name).slice(0, 10) }); } };

  if (pxd) {
    // Tombol cepat MABAR di HUD (dengan badge pesan baru)
    var icons = pxd.querySelector('.pxd-icons');
    if (icons) {
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'pxd-icon px-plain pty-hudbtn'; btn.title = 'Mabar'; btn.setAttribute('aria-label', 'Mabar');
      btn.innerHTML = '🎮<i id="ptyBadge" hidden></i>';
      btn.addEventListener('click', function () { openPanel(); });
      icons.insertBefore(btn, icons.children[1] || null);
    }
    var m = /[?&]mabar=([A-Za-z0-9]{6})/.exec(location.search);
    load().then(function () {
      if (m && !(state.party && state.party.code === m[1].toUpperCase())) openPanel(m[1].toUpperCase());
      else if (/[?&]mabar=1/.test(location.search)) openPanel();
    });
    // Sinkron cadangan (undangan baru, anggota, tujuan) — jarang agar hemat baterai.
    pollTimer = setInterval(function () { if (!document.hidden) load(); }, 15000);
    window.addEventListener('pagehide', disconnect);
  }
})();
