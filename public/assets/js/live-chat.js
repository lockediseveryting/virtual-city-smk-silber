/**
 * Virtual City SMK Silber — Live Chat (Workstream E)
 * Panel chat real-time per-server dengan nama karakter + lokasi.
 *
 * API publik:
 *   LiveChat.init(ws)                      → kaitkan koneksi WebSocket (objek WebSocket
 *                                            atau wrapper { send(), addEventListener })
 *   LiveChat.send(text, {charName, locationName}) → kirim pesan (metadata opsional)
 *   LiveChat.onMessage(cb)                 → callback untuk tiap chat_message (cb(msg))
 *   LiveChat.renderInto(container)         → render panel chat ke elemen/DOM
 *
 * Pesan tampil sebagai bubble: "[Lokasi] NamaKarakter: pesan"
 * Fitur: auto-scroll, indikator koneksi, batas 50 pesan tampil, kirim dgn Enter,
 * escape HTML (sanitasi utama tetap di server), seluruh UI berbahasa Indonesia.
 *
 * Kompatibel dengan payload chat_message lama maupun baru:
 *   { type:'chat_message', scope, id, username, fullName, role, text, ...,
 *     charName?, locationName? }  ← field baru opsional dari Workstream E
 */
(function () {
  'use strict';

  var MAX_MESSAGES = 50;      // batas tampilan (pesan lama dibuang dari DOM)
  var MAX_TEXT = 300;

  var state = {
    ws: null,
    container: null,
    listEl: null,
    inputEl: null,
    statusEl: null,
    callbacks: [],
    defaultCharName: '',
    defaultLocationName: 'Sekolah',
    scope: 'global'
  };

  // ---------- Util ----------

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function fmtTime(iso) {
    try {
      var d = new Date(iso);
      if (isNaN(d.getTime())) return '';
      return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    } catch (e) { return ''; }
  }

  function nowStatus() {
    if (!state.ws) return 'offline';
    var r = state.ws.readyState;
    if (r === 1) return 'online';
    if (r === 0) return 'connecting';
    return 'offline';
  }

  var STATUS_LABEL = {
    online: 'Terhubung',
    connecting: 'Menyambung…',
    offline: 'Terputus'
  };
  var STATUS_COLOR = {
    online: '#4ade80',
    connecting: '#facc15',
    offline: '#f87171'
  };

  // ---------- WebSocket ----------

  function hookWs() {
    var ws = state.ws;
    if (!ws) return;
    var origOnMsg = ws.onmessage;
    ws.onmessage = function (ev) {
      var msg = null;
      try { msg = JSON.parse(ev.data); } catch (e) { msg = null; }
      if (msg && msg.type === 'chat_message') {
        addMessage(msg);
        fireCallbacks(msg);
      }
      if (typeof origOnMsg === 'function') origOnMsg.call(ws, ev);
    };
    var origOnOpen = ws.onopen;
    ws.onopen = function (ev) {
      updateStatus();
      if (typeof origOnOpen === 'function') origOnOpen.call(ws, ev);
    };
    var origOnClose = ws.onclose;
    ws.onclose = function (ev) {
      updateStatus();
      if (typeof origOnClose === 'function') origOnClose.call(ws, ev);
    };
    updateStatus();
  }

  // ---------- Render pesan ----------

  function bubbleFrom(msg) {
    var charName = (msg.charName || msg.fullName || msg.username || 'Pemain');
    var loc = (msg.locationName || 'Sekolah');
    var isPrivate = msg.scope === 'private';
    var isSystem = msg.role === 'system';
    return {
      html:
        '<div class="lc-msg' + (isPrivate ? ' lc-private' : '') + (isSystem ? ' lc-system' : '') + '">' +
          '<span class="lc-time">' + escHtml(fmtTime(msg.timestamp)) + '</span> ' +
          (isPrivate ? '<span class="lc-tag">[privat]</span> ' : '') +
          '<span class="lc-bubble"><span class="lc-meta">[' + escHtml(loc) + '] ' +
          '<strong class="lc-name">' + escHtml(charName) + ':</strong></span> ' +
          '<span class="lc-text">' + escHtml(msg.text || '') + '</span></span>' +
        '</div>'
    };
  }

  function addMessage(msg) {
    if (!state.listEl) return;
    var div = document.createElement('div');
    div.innerHTML = bubbleFrom(msg).html;
    var node = div.firstChild;
    state.listEl.appendChild(node);
    // Batasi tampilan: buang pesan paling lama di atas 50.
    while (state.listEl.children.length > MAX_MESSAGES) {
      state.listEl.removeChild(state.listEl.firstChild);
    }
    autoScroll();
  }

  function autoScroll() {
    if (!state.listEl) return;
    // Hanya auto-scroll bila pengguna berada dekat dasar (tidak memaksa saat sedang membaca ke atas).
    var nearBottom = state.listEl.scrollHeight - state.listEl.scrollTop - state.listEl.clientHeight < 80;
    if (nearBottom) state.listEl.scrollTop = state.listEl.scrollHeight;
  }

  function updateStatus() {
    if (!state.statusEl) return;
    var s = nowStatus();
    state.statusEl.textContent = '● ' + (STATUS_LABEL[s] || s);
    state.statusEl.style.color = STATUS_COLOR[s] || '#94a3b8';
  }

  // ---------- API publik ----------

  var LiveChat = {

    /** Kaitkan koneksi WebSocket (dipanggil setelah koneksi terbentuk/di-open). */
    init: function (ws, opts) {
      opts = opts || {};
      state.ws = ws || null;
      if (opts.charName) state.defaultCharName = String(opts.charName).slice(0, 40);
      if (opts.locationName) state.defaultLocationName = String(opts.locationName).slice(0, 50);
      if (opts.scope) state.scope = String(opts.scope);
      hookWs();
      return this;
    },

    /** Perbarui metadata default (mis. lokasi berubah saat pemain berjalan). */
    setContext: function (opts) {
      opts = opts || {};
      if (opts.charName !== undefined) state.defaultCharName = String(opts.charName || '').slice(0, 40);
      if (opts.locationName !== undefined) state.defaultLocationName = String(opts.locationName || 'Sekolah').slice(0, 50);
      return this;
    },

    /**
     * Kirim pesan. Payload MENAMBAHKAN field opsional charName/locationName pada
     * event 'chat' yang sudah ada — klien/server lama tetap kompatibel.
     */
    send: function (text, opts) {
      opts = opts || {};
      var t = String(text == null ? '' : text).trim().slice(0, MAX_TEXT);
      if (!t) return false;
      if (!state.ws || state.ws.readyState !== 1) return false; // 1 = OPEN
      var payload = {
        type: 'chat',
        scope: opts.scope || state.scope || 'global',
        text: t,
        // Field opsional Workstream E (non-breaking):
        charName: String(opts.charName || state.defaultCharName || '').slice(0, 40),
        locationName: String(opts.locationName || state.defaultLocationName || 'Sekolah').slice(0, 50)
      };
      if (opts.area) payload.area = String(opts.area).slice(0, 32);
      if (opts.with) payload.with = Number(opts.with) || 0;
      try { state.ws.send(JSON.stringify(payload)); } catch (e) { return false; }
      return true;
    },

    /** Daftarkan callback untuk setiap chat_message yang masuk. */
    onMessage: function (cb) {
      if (typeof cb === 'function') state.callbacks.push(cb);
      return this;
    },

    /**
     * Render panel chat lengkap (header status + daftar bubble + input) ke dalam
     * container (elemen DOM atau selector string).
     */
    renderInto: function (container) {
      var host = (typeof container === 'string')
        ? document.querySelector(container)
        : container;
      if (!host) return this;

      host.classList.add('live-chat-panel');

      // Header + indikator koneksi
      var header = document.createElement('div');
      header.className = 'lc-header';
      header.innerHTML =
        '<span class="lc-title">💬 Chat Sekolah</span>' +
        '<span class="lc-status" role="status" aria-live="polite">● Menyambung…</span>';
      host.appendChild(header);

      // Daftar pesan
      var list = document.createElement('div');
      list.className = 'lc-list';
      list.setAttribute('aria-live', 'polite');
      host.appendChild(list);

      // Baris input
      var form = document.createElement('form');
      form.className = 'lc-input-row';
      form.autocomplete = 'off';
      var input = document.createElement('input');
      input.type = 'text';
      input.className = 'lc-input';
      input.placeholder = 'Tulis pesan… (Enter untuk kirim)';
      input.maxLength = MAX_TEXT;
      input.setAttribute('aria-label', 'Pesan chat');
      var btn = document.createElement('button');
      btn.type = 'submit';
      btn.className = 'lc-send';
      btn.textContent = 'Kirim';
      form.appendChild(input);
      form.appendChild(btn);
      host.appendChild(form);

      state.container = host;
      state.listEl = list;
      state.inputEl = input;
      state.statusEl = header.querySelector('.lc-status');

      // Enter (submit form) mengirim pesan; tidak reload halaman.
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (LiveChat.send(input.value)) {
          input.value = '';
          input.focus();
        } else {
          input.placeholder = 'Tidak dapat mengirim (koneksi terputus).';
        }
      });

      hookWs();   // pastikan indikator status ikut terpasang
      updateStatus();
      return this;
    },

    /** Tampilkan pesan lokal (info sistem, peringatan rate-limit, dsb.). */
    system: function (text) {
      addMessage({ scope: 'global', role: 'system', charName: 'Sistem', locationName: '', text: String(text || ''), timestamp: new Date().toISOString() });
      return this;
    },

    /** Hapus seluruh pesan tampilan (dipakai saat pindah server). */
    clear: function () {
      if (state.listEl) state.listEl.innerHTML = '';
      return this;
    },

    /** Status koneksi saat ini: 'online' | 'connecting' | 'offline'. */
    status: function () { return nowStatus(); }
  };

  function fireCallbacks(msg) {
    for (var i = 0; i < state.callbacks.length; i++) {
      try { state.callbacks[i](msg); } catch (e) { /* abaikan callback rusak */ }
    }
  }

  // Gaya panel (di-inject sekali, ringan, tidak menimpa tema global)
  if (!document.getElementById('live-chat-style')) {
    var st = document.createElement('style');
    st.id = 'live-chat-style';
    st.textContent =
      '.live-chat-panel{display:flex;flex-direction:column;height:100%;min-height:220px;background:rgba(2,6,12,.72);border:1px solid #1f293d;border-radius:12px;overflow:hidden;font-size:.82rem;color:#e2e8f0}' +
      '.lc-header{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:rgba(15,23,42,.9);border-bottom:1px solid #1f293d}' +
      '.lc-title{font-weight:700}' +
      '.lc-status{font-size:.72rem}' +
      '.lc-list{flex:1;overflow-y:auto;padding:8px 10px;display:flex;flex-direction:column;gap:4px}' +
      '.lc-msg{line-height:1.35;word-break:break-word}' +
      '.lc-time{color:#64748b;font-size:.68rem;margin-right:4px}' +
      '.lc-tag{color:#c084fc;font-size:.68rem}' +
      '.lc-bubble{background:rgba(30,41,59,.6);border-radius:10px;padding:4px 8px;display:inline-block;max-width:92%}' +
      '.lc-msg.lc-private .lc-bubble{background:rgba(88,28,135,.35)}' +
      '.lc-msg.lc-system .lc-bubble{background:rgba(2,6,12,.4)}' +
      '.lc-meta{color:#94a3b8}' +
      '.lc-name{color:#7dd3fc}' +
      '.lc-text{color:#e2e8f0}' +
      '.lc-input-row{display:flex;gap:6px;padding:8px;border-top:1px solid #1f293d;background:rgba(15,23,42,.9)}' +
      '.lc-input{flex:1;min-width:0;padding:8px 10px;border-radius:8px;border:1px solid #1f293d;background:rgba(2,6,12,.5);color:#e2e8f0;font-size:.8rem}' +
      '.lc-send{padding:8px 14px;border-radius:8px;border:1px solid #1f293d;background:#0ea5e9;color:#fff;font-weight:700;cursor:pointer}' +
      '.lc-send:active{background:#0284c7}';
    document.head.appendChild(st);
  }

  window.LiveChat = LiveChat;
})();
