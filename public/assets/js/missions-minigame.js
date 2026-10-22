/**
 * Virtual City SMK Silber — Workstream C: Sistem Misi Mini-Game Modular.
 * Gaya Among Us: overlay mini-game dengan timer, instruksi, dan feedback.
 *
 * API publik:
 *   window.MiniGames.register(type, def)              — daftarkan mini-game baru.
 *   window.MiniGames.open(missionId, opts, onComplete)— buka mini-game.
 *       opts  : { type:'wire'|'assemble'|'cash'|'sortbooks'|<terdaftar>, seconds:30, ... }
 *       onComplete(ok, data) dipanggil setelah panel ditutup (ok=true bila sukses
 *       DAN reward server berhasil dicairkan bila missionId > 0).
 *   window.MiniGames.close()                          — tutup overlay (dipakai tombol X / ESC).
 *   window.MiniGames.renderServerProgressBar(container)— bar progres agregat server
 *       (WS event 'mission_progress' bila tersedia, polling GET /api/missions sebagai fallback).
 *
 * Modul ini MANDIRI: tidak mengubah world3d.js / pixel.js. CSS di-inject otomatis
 * dari /assets/css/minigames.css (idempotent). Semua interaksi pakai Pointer Events
 * (mouse + touch), styling pixel-crisp via image-rendering: pixelated di CSS.
 */
(function () {
  'use strict';

  /* ---------------------------------------------------------------- util */

  const $ = (sel, root) => (root || document).querySelector(sel);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const shuffle = (arr) => { const a = arr.slice(); for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); const t = a[i]; a[i] = a[j]; a[j] = t; } return a; };
  const csrf = () => { const m = document.querySelector('meta[name="csrf"]'); return m ? String(m.content || '') : ''; };

  function ensureCSS() {
    if (document.getElementById('minigames-css')) return;
    const link = document.createElement('link');
    link.id = 'minigames-css';
    link.rel = 'stylesheet';
    link.href = '/assets/css/minigames.css';
    document.head.appendChild(link);
  }

  /** Fetch POST JSON dengan CSRF (pola sama dengan pixel-net.js / GameplayController). */
  async function postJSON(url, body) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 12000);
    try {
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() },
        body: JSON.stringify(Object.assign({ _csrf: csrf() }, body))
      });
      const data = await res.json().catch(() => ({}));
      return { ok: res.ok, status: res.status, data };
    } finally { clearTimeout(timer); }
  }

  /** Fetch GET JSON ringan (pola UI.fetchJSON). */
  async function getJSON(url, timeoutMs) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeoutMs || 10000);
    try {
      const res = await fetch(url, { credentials: 'same-origin', signal: ctrl.signal });
      const data = await res.json().catch(() => ({}));
      return { ok: res.ok, status: res.status, data };
    } finally { clearTimeout(timer); }
  }

  /* ------------------------------------------------------------ registry */

  const registry = {};

  function register(type, def) {
    if (!type || typeof def !== 'object' || typeof def.render !== 'function') return false;
    registry[type] = def;
    return true;
  }

  /* ------------------------------------------------------ overlay shell */

  const MiniGames = {
    register,
    open, close,
    renderServerProgressBar,
    handleServerProgress,
    active: null,

    /** Defaul type → dipakai bila opts.type tidak diberikan. */
    guessType(opts) { return (opts && opts.type) || 'wire'; }
  };

  let overlay = null, timerId = null, timeLeft = 0, currentGame = null, finished = false;

  function buildOverlay(title, seconds) {
    ensureCSS();
    overlay = document.createElement('div');
    overlay.className = 'mg-overlay';
    overlay.id = 'mgOverlay';
    overlay.innerHTML =
      '<div class="mg-panel" role="dialog" aria-modal="true" aria-label="' + esc(title) + '">' +
      '  <div class="mg-head">' +
      '    <div class="mg-title"><span class="mg-icon">▶</span> ' + esc(title) + '</div>' +
      '    <div class="mg-timer" id="mgTimer">' + String(seconds) + 's</div>' +
      '    <button type="button" class="mg-close" id="mgClose" aria-label="Tutup mini-game">✕</button>' +
      '  </div>' +
      '  <div class="mg-instructions" id="mgInstructions"></div>' +
      '  <div class="mg-board" id="mgBoard"></div>' +
      '  <div class="mg-status" id="mgStatus"></div>' +
      '</div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('pointerdown', (e) => { if (e.target === overlay) close(false); });
    $('#mgClose', overlay).addEventListener('click', () => close(false));
    document.addEventListener('keydown', onKey);
    timeLeft = seconds;
    timerId = setInterval(tick, 1000);
    tick();
  }

  function onKey(e) {
    if (!overlay) return;
    if (e.key === 'Escape') { e.preventDefault(); close(false); }
  }

  function tick() {
    if (!overlay) return;
    const el = $('#mgTimer', overlay);
    if (el) el.textContent = Math.max(0, timeLeft) + 's';
    if (timeLeft <= 10 && el) el.classList.add('mg-timer--warn');
    if (timeLeft <= 0) { finish(false, 'Waktu habis! Coba lagi ya.'); return; }
    timeLeft--;
  }

  function setStatus(html) {
    const el = overlay ? $('#mgStatus', overlay) : null;
    if (el) el.innerHTML = html;
  }

  /** Selesaikan mini-game: ok=true → kirim ke server, false → tampil gagal. */
  async function finish(ok, msg) {
    if (finished) return;
    finished = true;
    if (timerId) { clearInterval(timerId); timerId = null; }
    const state = MiniGames.active || {};
    try { if (currentGame && currentGame.onFinish) currentGame.onFinish(ok); } catch (e) { /* abaikan */ }

    let server = null;
    if (ok && state.missionId) {
      setStatus('<span class="mg-spin"></span> Menyimpan ke server…');
      try {
        const r = await postJSON('/api/missions/complete', { mission_id: state.missionId });
        server = r;
        if (!r.ok || !r.data || r.data.status !== 'success') {
          // Gagal server (misi sudah selesai / tidak valid) → tetap tutup sebagai gagal-ganda:
          // mini-game sukses tapi reward tidak dicairkan.
          ok = false;
          msg = (r.data && r.data.message) ? r.data.message : 'Server menolak misi. ' + msg;
        }
      } catch (e) {
        ok = false;
        msg = 'Koneksi ke server gagal — reward tidak tersimpan.';
      }
    }

    const reward = (server && server.data && server.data.reward) ? server.data.reward : null;
    const endPanel = document.createElement('div');
    endPanel.className = 'mg-end mg-end--' + (ok ? 'ok' : 'fail');
    endPanel.innerHTML =
      (ok
        ? '<div class="mg-end__title">✔ BERHASIL!</div>'
        : '<div class="mg-end__title">✘ GAGAL</div>') +
      '<div class="mg-end__msg">' + esc(msg || (ok ? 'Misi selesai!' : 'Coba lagi!')) + '</div>' +
      (reward ? ('<div class="mg-end__reward">+' + esc(reward.xp) + ' XP &nbsp;•&nbsp; +' + esc(reward.coins) + ' Koin</div>') : '') +
      '<div class="mg-end__btns">' +
      '  <button type="button" class="mg-btn" id="mgOk">Lanjut</button>' +
      (ok ? '' : '<button type="button" class="mg-btn mg-btn--alt" id="mgRetry">Ulangi</button>') +
      '</div>';
    const board = overlay ? $('#mgBoard', overlay) : null;
    const instr = overlay ? $('#mgInstructions', overlay) : null;
    if (board) { board.innerHTML = ''; board.appendChild(endPanel); }
    if (instr) instr.innerHTML = '';
    endPanel.querySelector('#mgOk').addEventListener('click', () => close(ok, reward, server));
    const retry = endPanel.querySelector('#mgRetry');
    if (retry) retry.addEventListener('click', () => {
      const st = MiniGames.active;
      close(false, null, null, true);
      open(st.missionId, st.opts, st.onComplete);
    });
    try { if (ok && window.gameAudio) window.gameAudio.sfx('fanfare'); } catch (e) { /* opsional */ }
  }

  /** API yang diteruskan ke def.render(board, api, opts). */
  function makeApi() {
    return {
      succeed: (msg) => finish(true, msg || 'Mini-game selesai!'),
      fail: (msg) => finish(false, msg || 'Coba lagi!'),
      setStatus,
      esc, shuffle,
      timeLeft: () => timeLeft,
      locked: () => finished
    };
  }

  /* ---------------------------------------------------------------- open */

  function open(missionId, opts, onComplete) {
    if (overlay) close(false, null, null, true);
    opts = opts || {};
    const type = MiniGames.guessType(opts);
    const def = registry[type];
    if (!def) { console.warn('[MiniGames] tipe tidak dikenal:', type); return false; }
    const seconds = Math.max(5, Math.min(600, Number(opts.seconds) || Number(def.seconds) || 60));

    MiniGames.active = { missionId: Number(missionId) || 0, opts: Object.assign({}, opts, { type, seconds }), onComplete: typeof onComplete === 'function' ? onComplete : null };
    finished = false;
    buildOverlay(def.title || 'Misi', seconds);
    const instr = $('#mgInstructions', overlay);
    if (instr) instr.innerHTML = (def.instructions || '') + (MiniGames.active.missionId
      ? '<span class="mg-quest">Misi #' + MiniGames.active.missionId + '</span>' : '');
    currentGame = {};
    try {
      def.render($('#mgBoard', overlay), makeApi(), MiniGames.active.opts);
    } catch (e) {
      finish(false, 'Mini-game error: ' + (e && e.message ? e.message : 'tidak diketahui'));
    }
    return true;
  }

  function close(result, reward, server, silent) {
    const st = MiniGames.active;
    if (overlay) {
      if (timerId) { clearInterval(timerId); timerId = null; }
      document.removeEventListener('keydown', onKey);
      const ov = overlay; overlay = null;
      ov.classList.add('mg-overlay--out');
      setTimeout(() => ov.remove(), 220);
    }
    currentGame = null;
    MiniGames.active = null;
    if (!silent && st && st.onComplete) {
      try { st.onComplete(!!result, { reward: reward || null, server: server || null }); } catch (e) { /* abaikan */ }
    }
    refreshAllBars(); // progres agregat mungkin berubah setelah misi selesai
  }

  /* ================= MINI-GAME 1: WIRE — sambung kabel (Lab TIK) ======== */

  register('wire', {
    title: 'Sambung Kabel Jaringan',
    instructions: 'Klik kabel di <b>kiri</b>, lalu klik kabel dengan <b>warna sama</b> di kanan. Sambung semua!',
    seconds: 45,
    render(board, api, opts) {
      const n = Math.max(3, Math.min(6, Number(opts.pairs) || 4));
      const colors = ['#e64539', '#3f7fe0', '#f2b63a', '#43b95c', '#9a5fe0', '#e06fa8'];
      const pick = shuffle(colors).slice(0, n);
      const left = shuffle(pick.map((c, i) => ({ c, i })));
      const right = shuffle(pick.map((c, i) => ({ c, i })));
      const state = { selLeft: null, done: 0 };

      board.innerHTML =
        '<div class="mg-wire">' +
        '  <div class="mg-wire__col">' + left.map((w) =>
          '<button type="button" class="mg-wire__node" data-side="L" data-idx="' + w.i + '" style="--wc:' + w.c + '">' +
          '<span class="mg-wire__plug"></span></button>').join('') + '</div>' +
        '  <svg class="mg-wire__svg" id="mgWireSvg"></svg>' +
        '  <div class="mg-wire__col">' + right.map((w) =>
          '<button type="button" class="mg-wire__node" data-side="R" data-idx="' + w.i + '" style="--wc:' + w.c + '">' +
          '<span class="mg-wire__plug"></span></button>').join('') + '</div>' +
        '</div>';

      const nodes = board.querySelectorAll('.mg-wire__node');
      nodes.forEach((btn) => {
        btn.addEventListener('click', () => {
          if (api.locked()) return;
          if (btn.classList.contains('is-done')) return;
          if (btn.dataset.side === 'L') {
            board.querySelectorAll('.mg-wire__node[data-side="L"]').forEach((b) => b.classList.remove('is-sel'));
            state.selLeft = btn;
            btn.classList.add('is-sel');
            try { if (window.gameAudio) window.gameAudio.sfx('blip'); } catch (e) {}
          } else if (state.selLeft) {
            if (state.selLeft.dataset.idx === btn.dataset.idx) {
              drawWire(state.selLeft, btn, state.selLeft.style.getPropertyValue('--wc'));
              state.selLeft.classList.remove('is-sel');
              state.selLeft.classList.add('is-done');
              btn.classList.add('is-done');
              state.selLeft = null;
              state.done++;
              try { if (window.gameAudio) window.gameAudio.sfx('coin'); } catch (e) {}
              if (state.done >= n) api.succeed('Semua kabel tersambung — jaringan Lab TIK kembali online!');
            } else {
              btn.classList.add('is-err');
              setTimeout(() => btn.classList.remove('is-err'), 350);
              try { if (window.gameAudio) window.gameAudio.sfx('hurt'); } catch (e) {}
            }
          }
        });
      });

      function drawWire(a, b, color) {
        const svg = $('#mgWireSvg', board);
        const box = svg.getBoundingClientRect();
        const ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect();
        const x1 = (ra.right - box.left), y1 = (ra.top + ra.height / 2 - box.top);
        const x2 = (rb.left - box.left), y2 = (rb.top + rb.height / 2 - box.top);
        const mid = (x1 + x2) / 2;
        const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        p.setAttribute('d', 'M' + x1 + ',' + y1 + ' C' + mid + ',' + y1 + ' ' + mid + ',' + y2 + ' ' + x2 + ',' + y2);
        p.setAttribute('stroke', color);
        p.setAttribute('fill', 'none');
        p.setAttribute('stroke-width', '8');
        p.setAttribute('class', 'mg-wire__line');
        svg.appendChild(p);
      }
    }
  });

  /* ============ MINI-GAME 2: ASSEMBLE — rakit PC (Lab Rakitan) ========== */

  register('assemble', {
    title: 'Rakit PC Berurutan',
    instructions: 'Seret (atau klik) komponen sesuai <b>urutan perakitan</b>: Motherboard → CPU → RAM → SSD → PSU → Panel Depan.',
    seconds: 60,
    render(board, api, opts) {
      const parts = (opts.parts && opts.parts.length ? opts.parts : [
        { id: 'motherboard', label: 'Motherboard', icon: '🧠' },
        { id: 'cpu', label: 'CPU', icon: '⚙' },
        { id: 'ram', label: 'RAM', icon: '📊' },
        { id: 'ssd', label: 'SSD', icon: '💾' },
        { id: 'psu', label: 'PSU', icon: '🔌' },
        { id: 'panel', label: 'Panel Depan', icon: '🪟' }
      ]);
      let next = 0, placed = 0;
      let held = null, ghost = null;

      board.innerHTML =
        '<div class="mg-asm">' +
        '  <div class="mg-asm__tray" id="mgAsmTray">' + shuffle(parts.map((p) =>
          '<button type="button" class="mg-asm__part" data-id="' + esc(p.id) + '" draggable="false">' +
          '<span class="mg-asm__ico">' + p.icon + '</span><span>' + esc(p.label) + '</span></button>').join('')) + '</div>' +
        '  <div class="mg-asm__slots" id="mgAsmSlots">' + parts.map((p, i) =>
          '<div class="mg-asm__slot" data-id="' + esc(p.id) + '"><span class="mg-asm__num">' + (i + 1) + '</span>' +
          '<span class="mg-asm__lbl">' + esc(p.label) + '</span></div>').join('') + '</div>' +
        '</div>';

      const tray = $('#mgAsmTray', board);
      const slots = board.querySelectorAll('.mg-asm__slot');

      function place(partBtn, slot) {
        if (api.locked()) return;
        const want = parts[next].id;
        if (partBtn.dataset.id !== want || slot.dataset.id !== want) {
          slot.classList.add('is-err');
          setTimeout(() => slot.classList.remove('is-err'), 350);
          try { if (window.gameAudio) window.gameAudio.sfx('hurt'); } catch (e) {}
          return;
        }
        const ico = partBtn.querySelector('.mg-asm__ico').textContent;
        slot.classList.add('is-filled');
        slot.innerHTML = '<span class="mg-asm__num">' + (next + 1) + '</span>' +
          '<span class="mg-asm__ico">' + ico + '</span><span class="mg-asm__lbl">' + esc(parts[next].label) + '</span>';
        partBtn.classList.add('is-used');
        partBtn.disabled = true;
        next++; placed++;
        try { if (window.gameAudio) window.gameAudio.sfx('coin'); } catch (e) {}
        if (placed >= parts.length) api.succeed('PC selesai dirakit! Lab Rakitan siap dipakai.');
      }

      // Interaksi klik (mouse + touch): pilih komponen → klik slot.
      board.querySelectorAll('.mg-asm__part').forEach((btn) => {
        btn.addEventListener('click', () => {
          if (api.locked() || btn.classList.contains('is-used')) return;
          board.querySelectorAll('.mg-asm__part').forEach((b) => b.classList.remove('is-sel'));
          held = btn; btn.classList.add('is-sel');
          try { if (window.gameAudio) window.gameAudio.sfx('blip'); } catch (e) {}
        });
      });
      slots.forEach((slot) => {
        slot.addEventListener('click', () => { if (held) { place(held, slot); held = null; } });
        // Drag & drop via Pointer Events (lebih universal daripada DnD HTML5 di touch).
        slot.addEventListener('pointerup', () => { if (ghost) { place(ghost, slot); endDrag(); } });
      });
      board.querySelectorAll('.mg-asm__part').forEach((btn) => {
        btn.addEventListener('pointerdown', (e) => { startDrag(btn, e); });
      });
      document.addEventListener('pointermove', moveDrag);
      document.addEventListener('pointerup', endDrag);

      function startDrag(btn, e) {
        if (api.locked() || btn.classList.contains('is-used')) return;
        held = btn;
        ghost = btn.cloneNode(true);
        ghost.classList.add('mg-asm__ghost');
        document.body.appendChild(ghost);
        btn.classList.add('is-sel');
        moveDrag(e);
      }
      function moveDrag(e) {
        if (!ghost) return;
        ghost.style.left = e.clientX + 'px';
        ghost.style.top = e.clientY + 'px';
      }
      function endDrag() {
        if (ghost) ghost.remove();
        ghost = null; held = null;
      }
      api._cleanupDrag = () => { endDrag(); document.removeEventListener('pointermove', moveDrag); document.removeEventListener('pointerup', endDrag); };
      currentGame.onFinish = () => { if (api._cleanupDrag) api._cleanupDrag(); };
      void tray;
    }
  });

  /* ============ MINI-GAME 3: CASH — rekap kas bank mini ================ */

  register('cash', {
    title: 'Rekap Kas Bank Mini',
    instructions: 'Jumlahkan pecahan uang hingga <b>tepat sama</b> dengan target kas. Uang lebih = gagal!',
    seconds: 60,
    render(board, api, opts) {
      const denominations = [1000, 2000, 5000, 10000, 20000, 50000, 100000];
      const fmtID = (v) => 'Rp ' + Number(v).toLocaleString('id-ID');
      const target = Number(opts.target) || shuffle([25000, 75000, 135000, 185000, 320000])[0];
      let total = 0;

      board.innerHTML =
        '<div class="mg-cash">' +
        '  <div class="mg-cash__target">Target Kas: <b id="mgCashTarget">' + fmtID(target) + '</b></div>' +
        '  <div class="mg-cash__total" id="mgCashTotal">' + fmtID(0) + '</div>' +
        '  <div class="mg-cash__grid">' + shuffle(denominations).map((d) =>
          '<button type="button" class="mg-cash__bill" data-v="' + d + '">' +
          '<span class="mg-cash__val">' + fmtID(d) + '</span></button>').join('') + '</div>' +
        '  <button type="button" class="mg-btn mg-btn--sm" id="mgCashReset">↺ Ulang</button>' +
        '</div>';

      const totalEl = $('#mgCashTotal', board);
      const upd = () => {
        totalEl.textContent = fmtID(total);
        totalEl.classList.toggle('is-over', total > target);
        totalEl.classList.toggle('is-ok', total === target);
      };
      board.querySelectorAll('.mg-cash__bill').forEach((btn) => {
        btn.addEventListener('click', () => {
          if (api.locked()) return;
          total += Number(btn.dataset.v);
          btn.classList.add('is-pop');
          setTimeout(() => btn.classList.remove('is-pop'), 180);
          try { if (window.gameAudio) window.gameAudio.sfx('coin'); } catch (e) {}
          upd();
          if (total === target) api.succeed('Kas cocok! Laporan bank mini berhasil direkap.');
          else if (total > target) api.fail('Kelebihan ' + fmtID(total - target) + '. Kas tidak balance!');
        });
      });
      $('#mgCashReset', board).addEventListener('click', () => { if (!api.locked()) { total = 0; upd(); } });
    }
  });

  /* ========= MINI-GAME 4: SORTBOOKS — susun buku perpus ================ */

  register('sortbooks', {
    title: 'Susun Buku Perpustakaan',
    instructions: 'Klik buku sesuai <b>urutan genre</b> yang diminta pustakawan. Salah klik = gagal!',
    seconds: 40,
    render(board, api, opts) {
      const genres = ['Fiksi', 'Sains', 'Sejarah', 'Biografi', 'Komik', 'Puisi', 'Kamus', 'Atlas'];
      const n = Math.max(3, Math.min(6, Number(opts.count) || 4));
      const order = shuffle(genres).slice(0, n);       // urutan target
      const shelf = shuffle(order.concat(shuffle(genres.filter((g) => order.indexOf(g) < 0)).slice(0, 2))); // + pengganggu
      let next = 0;

      board.innerHTML =
        '<div class="mg-books">' +
        '  <div class="mg-books__order">Urutan: <b>' + order.map(esc).join(' → ') + '</b></div>' +
        '  <div class="mg-books__shelf">' + shelf.map((g, i) =>
          '<button type="button" class="mg-books__book" data-g="' + esc(g) + '" style="--bh:' + (86 + (i * 13) % 44) + 'px">' +
          '<span>' + esc(g) + '</span></button>').join('') + '</div>' +
        '  <div class="mg-books__progress" id="mgBooksProg">Buku berikutnya: ' + esc(order[0]) + '</div>' +
        '</div>';

      const prog = $('#mgBooksProg', board);
      board.querySelectorAll('.mg-books__book').forEach((btn) => {
        btn.addEventListener('click', () => {
          if (api.locked() || btn.classList.contains('is-done')) return;
          if (btn.dataset.g === order[next]) {
            btn.classList.add('is-done');
            next++;
            try { if (window.gameAudio) window.gameAudio.sfx('blip'); } catch (e) {}
            if (next >= order.length) api.succeed('Rak buku tertata rapi sesuai genre!');
            else prog.textContent = 'Buku berikutnya: ' + order[next];
          } else {
            btn.classList.add('is-err');
            setTimeout(() => btn.classList.remove('is-err'), 350);
            api.fail('Urutan salah — harusnya "' + order[next] + '".');
          }
        });
      });
    }
  });

  /* ========== Bar progres agregat server (WS / polling, robust) ========= */

  const bars = new Set();       // semua container aktif
  let pollTimer = null;
  let lastSnapshot = null;      // {completed,total,pct} terakhir diketahui

  function computeFromMissions(missions) {
    let total = 0, done = 0;
    (missions || []).forEach((m) => {
      total++;
      if (String(m.user_status || '') === 'completed') done++;
    });
    const pct = total > 0 ? Math.round(done * 100 / total) : 0;
    return { completed: done, total: total, pct: pct };
  }

  function paintBar(el, snap) {
    el.classList.add('mg-progress');
    el.innerHTML =
      '<div class="mg-progress__label">Misi Server: <b>' + snap.completed + '</b> / ' + snap.total + '</div>' +
      '<div class="mg-progress__track"><div class="mg-progress__fill" style="width:' + snap.pct + '%"></div>' +
      '<span class="mg-progress__pct">' + snap.pct + '%</span></div>';
  }

  function updateBars(snap, source) {
    lastSnapshot = snap;
    bars.forEach((el) => { if (el.isConnected) paintBar(el, snap); else bars.delete(el); });
    // Beri tahu dunia 3D / pixel bila ingin ikut reaksi (opsional).
    try { document.dispatchEvent(new CustomEvent('minigames:progress', { detail: Object.assign({ source: source }, snap) })); } catch (e) { /* abaikan */ }
  }

  function refreshAllBars() {
    if (bars.size === 0) return;
    getJSON('/api/missions', 8000).then((r) => {
      if (r.ok && r.data && r.data.status === 'ok' && Array.isArray(r.data.missions)) {
        updateBars(computeFromMissions(r.data.missions), 'poll');
      }
    }).catch(() => { /* offline: bar terakhir tetap tampil */ });
  }

  /**
   * Dipanggil dari luar (mis. saat agent WS lain menambah event 'mission_progress'):
   *   MiniGames.handleServerProgress({completed: 3, total: 12})
   * atau objek event WS mentah — kita toleran bentuk fieldnya.
   */
  function handleServerProgress(data) {
    if (!data) return null;
    let snap = null;
    if (typeof data.completed === 'number' && typeof data.total === 'number' && data.total > 0) {
      snap = { completed: data.completed, total: data.total, pct: Math.round(data.completed * 100 / data.total) };
    } else if (Array.isArray(data.missions)) {
      snap = computeFromMissions(data.missions);
    } else if (typeof data.missions_completed === 'number' && typeof data.missions_total === 'number') {
      snap = { completed: data.missions_completed, total: data.missions_total, pct: data.missions_total ? Math.round(data.missions_completed * 100 / data.missions_total) : 0 };
    }
    if (snap) updateBars(snap, 'ws');
    return snap;
  }

  function hookWS() {
    // 1) Custom DOM event (agent WS tinggal dispatch 'mission_progress' ke document).
    document.addEventListener('mission_progress', (e) => { handleServerProgress(e.detail || e.data); });
    // 2) Event CustomEvent generik 'ws-message' bila lapisan net me-relay semua pesan WS.
    document.addEventListener('ws-message', (e) => {
      const d = e.detail || {};
      if (d && (d.type === 'mission_progress' || d.event === 'mission_progress')) handleServerProgress(d);
    });
    // 3) Hook PixelNet (dunia pixel) bila tersedia — handler-nya per-instance.
    const tryHookPixel = () => {
      if (window.PixelNet && window.PixelNet.on && !window.PixelNet.__mgHooked) {
        window.PixelNet.__mgHooked = true;
        window.PixelNet.on('mission_progress', (d) => handleServerProgress(d));
      }
    };
    tryHookPixel();
    setTimeout(tryHookPixel, 1500);
    setTimeout(tryHookPixel, 6000);
  }

  /**
   * renderServerProgressBar(container)
   * container: Element atau selector CSS. Mengembalikan elemen bar.
   * Sumber data: event WS 'mission_progress' bila ada (lihat hookWS), fallback
   * polling GET /api/missions (interval 30 detik, hanya selama ada bar hidup).
   */
  function renderServerProgressBar(container) {
    const el = typeof container === 'string' ? $(container) : container;
    if (!el) return null;
    ensureCSS();
    bars.add(el);
    if (lastSnapshot) paintBar(el, lastSnapshot);
    else el.classList.add('mg-progress'), (el.innerHTML =
      '<div class="mg-progress__label">Memuat progres misi…</div>' +
      '<div class="mg-progress__track"><div class="mg-progress__fill" style="width:0%"></div></div>');
    refreshAllBars();
    if (!pollTimer) {
      pollTimer = setInterval(() => { if (bars.size > 0) refreshAllBars(); else { clearInterval(pollTimer); pollTimer = null; } }, 30000);
    }
    return el;
  }

  /* -------------------------------------------------------------- export */

  window.MiniGames = MiniGames;
  hookWS();
})();
