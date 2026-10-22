/*!
 * CharacterStory — Virtual City SMK Silber (Workstream B)
 * Data karakter + story arc + layar pilih karakter.
 * UMD/IIFE-safe, tanpa dependensi framework.
 * API: window.CharacterStory = { getAll, getById, getArc, advanceStep,
 *                                getSpawn, getDialog, getProgress, showSelect }
 * Event: 'characterstory:changed' { detail: { charId, step, completed } }
 */
(function (root, factory) {
  if (typeof define === 'function' && define.amd) {
    define([], factory);
  } else if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.CharacterStory = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var DATA_URL = '/assets/data/character-stories.json';
  var LS_PREFIX = 'vc_charstory_';
  var EVT_CHANGED = 'characterstory:changed';

  var state = { loaded: false, characters: [], byId: {} };
  var pendingFetch = null;

  /* ---------- util ---------- */

  function currentUserKey() {
    // Per user+charId; identitas user diambil dari sesi global bila tersedia,
    // fallback ke 'anon' (kompatibel dengan klien apa pun).
    var u = '';
    try {
      if (root.VCSession && root.VCSession.user && root.VCSession.user.id) {
        u = String(root.VCSession.user.id);
      } else if (root.__VC_USER_ID__) {
        u = String(root.__VC_USER_ID__);
      }
    } catch (e) { /* noop */ }
    return u || 'anon';
  }

  function progressKey(charId) {
    return LS_PREFIX + currentUserKey() + ':' + charId;
  }

  function loadProgress(charId) {
    try {
      var raw = root.localStorage && root.localStorage.getItem(progressKey(charId));
      if (!raw) return { step: 0 };
      var p = JSON.parse(raw);
      return (typeof p === 'object' && p !== null && typeof p.step === 'number') ? p : { step: 0 };
    } catch (e) { return { step: 0 }; }
  }

  function saveProgress(charId, progress) {
    try {
      root.localStorage && root.localStorage.setItem(progressKey(charId), JSON.stringify(progress));
    } catch (e) { /* storage penuh/private mode: abaikan */ }
  }

  function dispatchChanged(charId, step, completed) {
    try {
      root.dispatchEvent(new CustomEvent(EVT_CHANGED, {
        detail: { charId: charId, step: step, completed: !!completed }
      }));
    } catch (e) { /* noop */ }
  }

  function shallowCopy(obj) {
    var out = {}, k;
    for (k in obj) if (Object.prototype.hasOwnProperty.call(obj, k)) out[k] = obj[k];
    return out;
  }

  /* ---------- data ---------- */

  function fetchJson(url, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status >= 200 && xhr.status < 300) {
        try { cb(null, JSON.parse(xhr.responseText)); }
        catch (e) { cb(e); }
      } else {
        cb(new Error('HTTP ' + xhr.status + ' memuat ' + url));
      }
    };
    xhr.send();
  }

  function ensureData(cb) {
    if (state.loaded) return cb(null, state.characters);
    if (pendingFetch) return pendingFetch.push(cb);
    pendingFetch = [cb];
    fetchJson(DATA_URL, function (err, json) {
      var queue = pendingFetch; pendingFetch = null;
      var e = null, chars = [];
      if (!err && json && Array.isArray(json.characters)) {
        chars = json.characters;
        state.characters = chars;
        state.byId = {};
        chars.forEach(function (c) { state.byId[c.id] = c; });
        state.loaded = true;
      } else {
        e = err || new Error('Format character-stories.json tidak valid');
      }
      queue.forEach(function (fn) { fn(e, e ? null : chars); });
    });
  }

  /* ---------- API sinkron (data harus sudah termuat) ---------- */

  function getAll() {
    return state.loaded ? state.characters.slice() : [];
  }

  function getById(id) {
    var c = state.byId[id];
    return c ? shallowCopy(c) : null;
  }

  function getArc(id) {
    var c = state.byId[id];
    return (c && Array.isArray(c.storyArc)) ? c.storyArc.slice() : [];
  }

  function getSpawn(id) {
    var c = state.byId[id];
    return (c && c.spawnLocationId) || null;
  }

  function getDialog(id, npcId) {
    var c = state.byId[id];
    if (!c || !c.uniqueDialogs) return [];
    var d = c.uniqueDialogs[npcId];
    return Array.isArray(d) ? d.slice() : [];
  }

  function getProgress(id) {
    return loadProgress(id);
  }

  /**
   * Maju satu langkah arc untuk charId. Return objek hasil atau null bila
   * karakter tidak ditemukan / arc sudah selesai.
   */
  function advanceStep(id) {
    var c = state.byId[id];
    if (!c) return null;
    var arc = Array.isArray(c.storyArc) ? c.storyArc : [];
    var maxStep = arc.length;
    var p = loadProgress(id);
    if (p.step >= maxStep) return null; // selesai
    p.step += 1;
    saveProgress(id, p);
    var completed = p.step >= maxStep;
    dispatchChanged(id, p.step, completed);
    return { charId: id, step: p.step, completed: completed, totalSteps: maxStep };
  }

  /* ---------- Layar pilih karakter ---------- */

  var CSS_ID = 'character-story-select-css';
  var CSS_TEXT = [
    '.cs-overlay{position:fixed;inset:0;z-index:9000;display:flex;align-items:center;justify-content:center;',
    'background:radial-gradient(ellipse at 50% 0%,rgba(30,41,59,.92),rgba(2,6,23,.97));',
    'font-family:"Segoe UI",system-ui,-apple-system,sans-serif;color:#e2e8f0;padding:24px;box-sizing:border-box;}',
    '.cs-panel{width:min(1060px,96vw);max-height:92vh;display:flex;flex-direction:column;',
    'background:linear-gradient(160deg,rgba(15,23,42,.96),rgba(2,6,23,.96));border:1px solid rgba(148,163,184,.25);',
    'border-radius:20px;box-shadow:0 30px 90px rgba(0,0,0,.7);overflow:hidden;}',
    '.cs-head{padding:22px 28px;border-bottom:1px solid rgba(148,163,184,.2);display:flex;justify-content:space-between;align-items:center;}',
    '.cs-title{margin:0;font-size:1.35rem;letter-spacing:.06em;text-transform:uppercase;',
    'background:linear-gradient(90deg,#7dd3fc,#a78bfa);-webkit-background-clip:text;background-clip:text;color:transparent;}',
    '.cs-sub{margin:6px 0 0;font-size:.85rem;color:#94a3b8;}',
    '.cs-close{background:none;border:1px solid rgba(148,163,184,.35);color:#94a3b8;border-radius:10px;',
    'padding:8px 14px;cursor:pointer;font-size:.85rem;transition:.2s;}',
    '.cs-close:hover{color:#e2e8f0;border-color:#7dd3fc;}',
    '.cs-grid{padding:24px 28px;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px;}',
    '.cs-card{position:relative;display:flex;gap:14px;padding:16px;border-radius:14px;cursor:pointer;',
    'background:rgba(30,41,59,.55);border:1px solid rgba(148,163,184,.18);transition:transform .18s,border-color .18s,box-shadow .18s;}',
    '.cs-card:hover{transform:translateY(-4px);border-color:var(--csc,#7dd3fc);box-shadow:0 12px 32px rgba(0,0,0,.45);}',
    '.cs-portrait{flex:0 0 64px;width:64px;height:64px;border-radius:14px;display:flex;align-items:center;justify-content:center;',
    'font-size:2rem;background:linear-gradient(150deg,var(--csc,#1e293b),rgba(2,6,23,.9));',
    'border:1px solid var(--csc,#334155);box-shadow:inset 0 0 24px rgba(255,255,255,.06);}',
    '.cs-body{min-width:0;flex:1;}',
    '.cs-name{margin:0;font-size:1.02rem;font-weight:700;color:#f1f5f9;}',
    '.cs-jurusan{margin:3px 0 8px;font-size:.75rem;letter-spacing:.05em;text-transform:uppercase;color:var(--csc,#7dd3fc);}',
    '.cs-lore{margin:0;font-size:.82rem;line-height:1.45;color:#94a3b8;display:-webkit-box;-webkit-line-clamp:3;',
    '-webkit-box-orient:vertical;overflow:hidden;}',
    '.cs-foot{margin-top:10px;font-size:.72rem;color:#64748b;}',
    '@media(max-width:640px){.cs-grid{grid-template-columns:1fr;padding:16px;}}'
  ].join('');

  function injectCss() {
    if (document.getElementById(CSS_ID)) return;
    var st = document.createElement('style');
    st.id = CSS_ID;
    st.textContent = CSS_TEXT;
    document.head.appendChild(st);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  /**
   * Menampilkan overlay pilih karakter.
   * @param {function(char:Object):void} [onPick] callback saat karakter dipilih.
   */
  function showSelect(onPick) {
    ensureData(function (err) {
      injectCss();
      var old = document.getElementById('cs-select-overlay');
      if (old && old.parentNode) old.parentNode.removeChild(old);

      var overlay = document.createElement('div');
      overlay.className = 'cs-overlay';
      overlay.id = 'cs-select-overlay';

      var listHtml = err
        ? '<div class="cs-sub">Gagal memuat data karakter: ' + esc(err.message) + '</div>'
        : state.characters.map(function (c) {
            var color = c.color || '#7dd3fc';
            var arcLen = (c.storyArc || []).length;
            return '<article class="cs-card" data-cs-id="' + esc(c.id) + '" style="--csc:' + esc(color) + '" tabindex="0" role="button">' +
              '<div class="cs-portrait">' + esc(c.emoji || '👤') + '</div>' +
              '<div class="cs-body">' +
              '<h3 class="cs-name">' + esc(c.name) + '</h3>' +
              '<div class="cs-jurusan">' + esc(c.jurusan || c.role || '') + '</div>' +
              '<p class="cs-lore">' + esc(c.lore || '') + '</p>' +
              '<div class="cs-foot">' + arcLen + ' langkah cerita &bull; muncul di ' + esc(c.spawnLocationId || '-') + '</div>' +
              '</div></article>';
          }).join('');

      overlay.innerHTML =
        '<div class="cs-panel" role="dialog" aria-modal="true" aria-label="Pilih Karakter">' +
        '<div class="cs-head"><div><h2 class="cs-title">Pilih Karaktermu</h2>' +
        '<p class="cs-sub">Setiap karakter punya latar belakang, titik kemunculan, dan alur cerita rahasianya sendiri.</p></div>' +
        '<button class="cs-close" type="button">Tutup</button></div>' +
        '<div class="cs-grid">' + listHtml + '</div></div>';

      function close() {
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        document.removeEventListener('keydown', onKey);
      }
      function onKey(e) { if (e.key === 'Escape') close(); }

      overlay.querySelector('.cs-close').addEventListener('click', close);
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) close();
      });
      document.addEventListener('keydown', onKey);

      Array.prototype.forEach.call(overlay.querySelectorAll('.cs-card'), function (card) {
        function pick() {
          var c = state.byId[card.getAttribute('data-cs-id')];
          if (!c) return;
          close();
          if (typeof onPick === 'function') onPick(shallowCopy(c));
        }
        card.addEventListener('click', pick);
        card.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); }
        });
      });

      document.body.appendChild(overlay);
    });
  }

  /* ---------- migrasi v2: hapus pilihan & progres karakter lama ---------- */
  // 14-10-2026: karakter hanya untuk cerita + desain; tugas datang dari guru/staf (misi server).
  // Pilihan lama ("vc_selected_char") dan progres storyArc dihapus sekali per perangkat.
  try {
    if (root.localStorage && !root.localStorage.getItem('vc_story_v2_migrated')) {
      var drop = [];
      for (var i = 0; i < root.localStorage.length; i++) {
        var k = root.localStorage.key(i);
        if (k === 'vc_selected_char' || (k && k.indexOf(LS_PREFIX) === 0)) drop.push(k);
      }
      drop.forEach(function (k) { root.localStorage.removeItem(k); });
      root.localStorage.setItem('vc_story_v2_migrated', '1');
    }
  } catch (e) { /* storage diblokir → abaikan */ }

  /** Pilihan karakter (v2): disimpan per perangkat, dipilih di lobi. */
  var SEL_KEY = 'vc_story_char_v2';
  function getSelected() { try { return (root.localStorage && root.localStorage.getItem(SEL_KEY)) || ''; } catch (e) { return ''; } }
  function setSelected(id) { try { if (id) root.localStorage.setItem(SEL_KEY, id); else root.localStorage.removeItem(SEL_KEY); } catch (e) {} }

  /* ---------- public API ---------- */

  var api = {
    getAll: getAll,
    getById: getById,
    getArc: getArc,
    advanceStep: advanceStep,
    getSpawn: getSpawn,
    getDialog: getDialog,
    getProgress: getProgress,
    showSelect: showSelect,
    getSelected: getSelected,
    setSelected: setSelected,
    load: ensureData,                 // pra-muat data (opsional): CharacterStory.load(cb)
    EVENT_CHANGED: EVT_CHANGED
  };

  // Auto-load di browser agar API sinkron siap secepat mungkin.
  if (typeof document !== 'undefined' && typeof XMLHttpRequest !== 'undefined') {
    ensureData(function () { /* hasil di-cache di state */ });
  }

  return api;
});
