/*
 * Navigasi + panggilan KUMPUL untuk Dunia 3D (/dunia) dan Dunia Pixel (/pixel).
 *  - Tombol 🧭 Navigasi: cari ruangan (38 lokasi resmi) → "Arahkan" (kompas + tiang cahaya di 3D,
 *    panah navigasi di Pixel) atau "Antar" (langsung pindah ke lokasi).
 *  - Jadwal kumpul server (/kumpul, diatur guru/ROOT): saat jamnya tiba muncul banner
 *    "📢 Kumpul di …" + navigasi otomatis; tiba di ruangan → kehadiran tercatat.
 * ES2017 tanpa ?. / ?? — `world` (3D) & `pix` (Pixel) adalah variabel global halaman (let), bukan window.*.
 */
(function () {
  'use strict';
  var css = document.createElement('style');
  css.textContent = '.vnav{position:fixed;inset:0;z-index:400;display:flex;align-items:center;justify-content:center;padding:12px;background:rgba(5,8,16,.6)}.vnav[hidden]{display:none}'
    + '.vnav__box{width:min(460px,100%);max-height:calc(100dvh - 24px);display:flex;flex-direction:column;gap:8px;padding:14px;background:#2a1a10;border:3px solid #d9a441;color:#fff6e0;box-shadow:0 6px 0 rgba(0,0,0,.45)}'
    + '.vnav__head{display:flex;justify-content:space-between;align-items:center;font-size:17px}.vnav__x{background:#3a2414;color:#fff;border:2px solid #20130a;min-width:40px;min-height:40px;cursor:pointer}'
    + '.vnav__q{font-size:16px;padding:10px;background:#160d07;color:#fff;border:2px solid #6b4423}.vnav__list{overflow-y:auto;display:flex;flex-direction:column;gap:4px}'
    + '.vnav__list h4{margin:8px 0 2px;color:#ffcf4a;font-size:14px}.vnav__row{display:flex;align-items:center;gap:6px;padding:6px;background:rgba(0,0,0,.25)}.vnav__row span{flex:1;font-size:14px}.vnav__row small{opacity:.7}'
    + '.vnav__row button,.vgather button{min-height:36px;padding:4px 9px;background:#e8a33d;color:#20130a;border:2px solid #20130a;font-weight:700;cursor:pointer;font-size:13px}.vnav__row button+button{background:#7dd3fc}.vnav__tip{margin:0;font-size:12px;opacity:.8}'
    + '.vgather{position:fixed;left:50%;top:136px;transform:translateX(-50%);z-index:300;display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center;max-width:calc(100vw - 16px);padding:8px 12px;background:rgba(58,20,10,.94);border:3px solid #ffcf4a;color:#fff6e0;font-size:14px;box-shadow:0 4px 0 rgba(0,0,0,.4);animation:vgPulse 1.6s ease-in-out infinite}'
    + '.vgather.is-done{border-color:#4ade80;animation:none}.vgather[hidden]{display:none}.vgather [data-gx]{background:#3a2414;color:#fff}'
    + '@keyframes vgPulse{50%{box-shadow:0 4px 0 rgba(0,0,0,.4),0 0 0 4px rgba(255,207,74,.35)}}@media (max-width:700px){.vgather{top:auto;bottom:150px;font-size:13px}}';
  document.head.appendChild(css);
  var LOCS = null, CAMPUS = null, banner = null, panel = null, gather = null;
  function is3d() { return typeof world !== 'undefined' && world && world.setNavTarget; } // eslint-disable-line no-undef
  function isPix() { return typeof pix !== 'undefined' && pix && pix.navigateTo; } // eslint-disable-line no-undef
  function W() { return is3d() ? world : null; } // eslint-disable-line no-undef
  function P() { return isPix() ? pix : null; } // eslint-disable-line no-undef
  function csrf() { return (document.querySelector('meta[name="csrf"]') || {}).content || ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(t, kind) { if (window.UI && window.UI.toast) window.UI.toast(t, kind || 'gold', 4000); else alert(t); }
  function serverId() {
    var q = parseInt(new URLSearchParams(location.search).get('server') || '', 10);
    if (q > 0) return q;
    if (window.PixelNet && window.PixelNet.currentServerId) return window.PixelNet.currentServerId;
    try { return parseInt(sessionStorage.getItem('vc_server_id'), 10) || 1; } catch (e) { return 1; }
  }

  function loadLocs() {
    if (LOCS) return Promise.resolve(LOCS);
    return fetch('/assets/map/pixel-side.json?v=7.5.0').then(function (r) { return r.json(); }).then(function (d) {
      LOCS = (d.locations || []).map(function (l) { return { id: l.id, name: l.name, building: l.building || '', floor: l.floor || 1, ref: l.campusRef || '', campus: l.campus || { x: 0, z: 0 } }; });
      return LOCS;
    }).catch(function () { LOCS = []; return LOCS; });
  }

  /** Koordinat 3D sebuah lokasi resmi (pakai campus.json dunia bila ada). */
  function pos3d(loc) {
    var w = W(), cd = w && w.campusData, p = { x: loc.campus.x, z: loc.campus.z, floor: 1 };
    var ref = String(loc.ref).split(':');
    if (cd && ref[0] === 'room') {
      var r = (cd.rooms || []).filter(function (x) { return x.id === ref[1]; })[0];
      if (r) { p.x = r.x; p.z = r.z; p.floor = r.floor || 1; }
    } else if (cd && ref[0] === 'building') {
      var b = (cd.buildings || []).filter(function (x) { return x.id === ref[1]; })[0];
      if (b && b.bounds) { p.x = b.bounds.x; p.z = b.bounds.z + (b.bounds.depth || 0) / 2 + 3; }
    }
    if (loc.building && ref[0] === 'room' && p.floor === 1) p.floor = loc.floor || 1;
    // Titik luar (bukan room/building): denah asli → tata letak dunia 3D yang direnggangkan.
    if (w && w.mapPoint && !(cd && (ref[0] === 'room' || ref[0] === 'building') && (p.x !== loc.campus.x || p.z !== loc.campus.z))) { var q = w.mapPoint(p.x, p.z); p.x = q.x; p.z = q.z; }
    return p;
  }

  function navTo(loc, onArrive) {
    if (is3d()) {
      var p = pos3d(loc);
      W().setNavTarget({ x: p.x, z: p.z, floor: p.floor, name: loc.name, onArrive: onArrive });
      toast('🧭 Ikuti kompas & tiang cahaya kuning ke ' + loc.name + (p.floor > 1 ? ' (Lantai ' + p.floor + ', naik tangga di dalam gedung)' : ''));
    } else if (isPix()) {
      P().navigateTo(loc.id);
      if (onArrive) watchPixel(loc.id, onArrive);
    }
  }
  function goNow(loc, onArrive) {
    if (is3d()) {
      var p = pos3d(loc);
      W().setNavTarget(null);
      W().teleportTo(p.x, p.z + 3, p.floor);
      toast('⚡ Diantar ke ' + loc.name, 'green');
      if (onArrive) setTimeout(onArrive, 600);
    } else if (isPix()) {
      P().goTo(loc.id);
      if (onArrive) setTimeout(onArrive, 600);
    }
  }
  var pixWatch = null;
  function watchPixel(id, cb) {
    clearInterval(pixWatch);
    pixWatch = setInterval(function () {
      if (!isPix()) return;
      var here = P().currentAreaId ? P().currentAreaId() : '';
      if (here === id.replace(/[^A-Za-z0-9_-]/g, '')) { clearInterval(pixWatch); cb(); }
    }, 1000);
  }

  // ---------------- Panel navigasi ----------------
  function openPanel() {
    loadLocs().then(function () {
      if (!panel) {
        panel = document.createElement('div');
        panel.className = 'vnav';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Navigasi');
        panel.innerHTML = '<div class="vnav__box"><div class="vnav__head"><b>🧭 Navigasi Sekolah</b><button type="button" class="vnav__x" data-x aria-label="Tutup">✕</button></div>'
          + '<input type="search" class="vnav__q" placeholder="Cari ruangan… (mis. lab, ruang 8, kantin)" autocomplete="off">'
          + '<div class="vnav__list"></div><p class="vnav__tip">🧭 Arahkan = ikuti penunjuk arah · ⚡ Antar = langsung pindah</p></div>';
        document.body.appendChild(panel);
        panel.addEventListener('click', function (e) {
          if (e.target === panel || e.target.hasAttribute('data-x')) { panel.hidden = true; return; }
          var id = e.target.getAttribute('data-nav') || e.target.getAttribute('data-go');
          if (!id) return;
          var loc = LOCS.filter(function (l) { return l.id === id; })[0];
          if (!loc) return;
          panel.hidden = true;
          if (e.target.hasAttribute('data-go')) goNow(loc); else navTo(loc);
        });
        panel.querySelector('.vnav__q').addEventListener('input', render);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel && !panel.hidden) panel.hidden = true; });
      }
      panel.hidden = false;
      render();
      var q = panel.querySelector('.vnav__q');
      if (!matchMedia('(pointer: coarse)').matches) q.focus();
    });
  }
  function render() {
    var q = panel.querySelector('.vnav__q').value.trim().toLowerCase();
    var groups = {};
    LOCS.forEach(function (l) {
      if (q && (l.name + ' ' + l.id).toLowerCase().indexOf(q) < 0) return;
      var g = l.building ? 'Gedung ' + l.building : 'Luar Ruangan';
      (groups[g] = groups[g] || []).push(l);
    });
    var h = '';
    Object.keys(groups).sort().forEach(function (g) {
      h += '<h4>' + esc(g) + '</h4>';
      groups[g].forEach(function (l) {
        h += '<div class="vnav__row"><span>' + esc(l.name) + (l.floor > 1 ? ' <small>Lt. ' + l.floor + '</small>' : '') + '</span>'
          + '<button type="button" data-nav="' + esc(l.id) + '">🧭 Arahkan</button><button type="button" data-go="' + esc(l.id) + '">⚡ Antar</button></div>';
      });
    });
    panel.querySelector('.vnav__list').innerHTML = h || '<p>Tidak ditemukan.</p>';
  }
  window.vcNavOpen = openPanel;

  // ---------------- Panggilan kumpul ----------------
  function arrivedKey(s) { return 'vc_gather_' + s.id + '_' + s.date; }
  function hasArrived(s) { try { return sessionStorage.getItem(arrivedKey(s)) === '1'; } catch (e) { return false; } }
  function markArrived(s) {
    fetch('/api/servers/session/action', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() },
      body: JSON.stringify({ action: 'arrive', session_id: s.id, server_id: serverId() }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.status === 'ok') { try { sessionStorage.setItem(arrivedKey(s), '1'); } catch (e) {} toast(d.message, 'green'); showBanner(s); }
      }).catch(function () {});
  }
  function showBanner(s) {
    if (!banner) {
      banner = document.createElement('div');
      banner.className = 'vgather';
      banner.setAttribute('role', 'status');
      document.body.appendChild(banner);
      banner.addEventListener('click', function (e) {
        if (!gather) return;
        var loc = (LOCS || []).filter(function (l) { return l.id === gather.room_id; })[0];
        if (!loc) return;
        if (e.target.hasAttribute('data-gnav')) navTo(loc, function () { markArrived(gather); });
        if (e.target.hasAttribute('data-ggo')) goNow(loc, function () { markArrived(gather); });
        if (e.target.hasAttribute('data-gx')) banner.hidden = true;
      });
    }
    var done = hasArrived(s);
    banner.hidden = false;
    banner.classList.toggle('is-done', done);
    banner.innerHTML = '<span>📢 <b>' + esc(s.title) + '</b> ' + esc(s.start) + '–' + esc(s.end) + (s.started ? '' : ' (segera)') + ' · Kumpul di <b>' + esc(s.room_name) + '</b>' + (done ? ' · ✅ kamu sudah hadir' : '') + '</span>'
      + (done ? '' : '<button type="button" data-gnav>🧭 Arahkan</button><button type="button" data-ggo>⚡ Antar</button>') + '<button type="button" data-gx aria-label="Tutup">✕</button>';
  }
  function poll() {
    if (document.hidden) return;
    fetch('/api/servers/session?server=' + serverId(), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var s = d && d.session;
        if (!s) { gather = null; if (banner) banner.hidden = true; return; }
        var isNew = !gather || gather.id !== s.id;
        gather = s;
        loadLocs().then(function () {
          showBanner(s);
          if (isNew && !hasArrived(s)) {
            var loc = LOCS.filter(function (l) { return l.id === s.room_id; })[0];
            if (loc) {
              if (window.VcMusic && window.UI && window.UI.toast) window.UI.toast('📢 ' + s.title + ': kumpul di ' + s.room_name + '!', 'gold', 6000);
              // tunggu dunia siap lalu pasang navigasi otomatis
              var tries = 0;
              (function wait() {
                if (is3d() || isPix()) { navTo(loc, function () { markArrived(s); }); return; }
                if (tries++ < 30) setTimeout(wait, 1000);
              })();
            }
          }
        });
      }).catch(function () {});
  }
  setTimeout(poll, 4000);
  setInterval(poll, 45000);
})();
