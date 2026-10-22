/*
 * Konsol admin bertab + Broadcast + pratinjau musim.
 *  - Satu bagian tampil per waktu; tombol tab di atas. Hash lama (#server-section, #siswa,
 *    #ringkasan, #broadcast) membuka tab yang sesuai, jadi menu papan kayu tetap berfungsi.
 *  - Broadcast: kirim (POST /api/admin/broadcast/save), tes = pratinjau pop-up lokal
 *    (window.vcShowBroadcast dari pixel-dashboard-plus.js), hapus (POST …/delete).
 * ES2017 tanpa ?. / ??.
 */
(function () {
  'use strict';
  var btns = document.querySelectorAll('.adm-tabbtn');
  var tabs = document.querySelectorAll('.adm-tab');
  if (!btns.length) return;
  var HASH = { 'server-section': 'server', siswa: 'pengguna', ringkasan: 'ringkasan', broadcast: 'broadcast', pxdBelow: '' };

  function show(name, scroll) {
    var ok = false;
    for (var i = 0; i < btns.length; i++) if (btns[i].getAttribute('data-tab') === name) ok = true;
    if (!ok) name = btns[0].getAttribute('data-tab');
    for (var j = 0; j < btns.length; j++) {
      var on = btns[j].getAttribute('data-tab') === name;
      btns[j].classList.toggle('is-on', on);
      btns[j].setAttribute('aria-selected', String(on));
    }
    for (var k = 0; k < tabs.length; k++) tabs[k].hidden = tabs[k].getAttribute('data-tab') !== name;
    try { sessionStorage.setItem('vc_adm_tab', name); } catch (e) {}
    if (scroll) { var nav = document.querySelector('.adm-tabs'); if (nav) nav.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    if (name === 'broadcast') loadList();
  }
  for (var i = 0; i < btns.length; i++) btns[i].addEventListener('click', function () { show(this.getAttribute('data-tab'), false); });
  function fromHash(scroll) {
    var h = location.hash.replace('#', '');
    if (h && HASH[h] !== undefined) { if (HASH[h]) show(HASH[h], scroll); return true; }
    return false;
  }
  window.addEventListener('hashchange', function () { fromHash(true); });
  if (!fromHash(true)) {
    var last = null;
    try { last = sessionStorage.getItem('vc_adm_tab'); } catch (e) {}
    show(last || 'ringkasan', false);
  }

  // ---------- Broadcast ----------
  var form = document.getElementById('bcForm');
  var list = document.getElementById('bcList');
  var msg = document.getElementById('bcMsg');
  function csrf() { return (document.querySelector('meta[name="csrf"]') || {}).content || ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function say(t, ok) { if (msg) { msg.textContent = t; msg.style.color = ok ? '#22c55e' : '#f87171'; } }
  function post(url, body) {
    body._csrf = csrf();
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': body._csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }
  function data() {
    var d = {};
    ['title', 'message', 'link_url', 'link_label', 'audience', 'days'].forEach(function (k) { d[k] = form.elements[k].value.trim(); });
    return d;
  }
  var AUD = { all: 'Semua', murid: 'Murid', staf: 'Staf' };
  var items = [];
  function loadList() {
    if (!list) return;
    fetch('/api/broadcasts?all=1', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        items = d.items || [];
        if (!items.length) { list.innerHTML = '<tr><td colspan="5" style="color:#94a3b8">Belum ada broadcast.</td></tr>'; return; }
        list.innerHTML = items.map(function (b, i) {
          return '<tr><td><b>' + esc(b.title) + '</b><div style="font-size:.78rem;color:#94a3b8">' + esc(String(b.message).slice(0, 90)) + '</div></td>'
            + '<td>' + esc(AUD[b.audience] || b.audience) + '</td>'
            + '<td>' + (Number(b.is_active) ? '<span style="color:#22c55e">● Aktif</span>' : '<span style="color:#94a3b8">Selesai</span>')
            + '<div style="font-size:.72rem;color:#94a3b8">' + (b.expires_at ? 's/d ' + esc(b.expires_at) + ' UTC' : 'tanpa batas') + '</div></td>'
            + '<td>' + esc(b.author) + '</td>'
            + '<td style="white-space:nowrap"><button type="button" class="btn btn-sec" data-test="' + i + '">👁 Tes</button> '
            + '<button type="button" class="btn btn-red" data-del="' + b.id + '">🗑 Hapus</button></td></tr>';
        }).join('');
      })
      .catch(function () { list.innerHTML = '<tr><td colspan="5">Gagal memuat.</td></tr>'; });
  }
  if (list) list.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('button') : null;
    if (!t) return;
    if (t.hasAttribute('data-test')) { var b = items[Number(t.getAttribute('data-test'))]; if (b && window.vcShowBroadcast) window.vcShowBroadcast(b, true); return; }
    var id = Number(t.getAttribute('data-del'));
    if (!id || !window.confirm('Hapus broadcast ini? Pop-up tidak akan tampil lagi ke pengguna.')) return;
    post('/api/admin/broadcast/delete', { id: id }).then(function (d) {
      say(d.status === 'ok' ? '🗑 Broadcast dihapus.' : (d.message || 'Gagal menghapus.'), d.status === 'ok');
      loadList();
    }).catch(function () { say('Koneksi gagal.', false); });
  });
  if (form) {
    document.getElementById('bcTest').addEventListener('click', function () {
      var d = data();
      if (!d.title || !d.message) { say('Isi judul & pesan dulu untuk dites.', false); return; }
      d.author = 'Pratinjau';
      if (window.vcShowBroadcast) window.vcShowBroadcast(d, true);
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = data();
      if (!d.title || !d.message) { say('Judul dan pesan wajib diisi.', false); return; }
      say('Mengirim…', true);
      post('/api/admin/broadcast/save', d).then(function (r) {
        if (r.status !== 'ok') { say(r.message || 'Gagal mengirim.', false); return; }
        say('✅ Broadcast terkirim! Muncul di dashboard pengguna.', true);
        form.reset();
        loadList();
      }).catch(function () { say('Koneksi gagal.', false); });
    });
  }

  // ---------- Pratinjau musim ----------
  var sp = document.getElementById('seasonPick');
  if (sp && window.vcSeasonList) {
    var cur = '';
    try { cur = localStorage.getItem('vc_season_preview') || ''; } catch (e) {}
    sp.innerHTML = '<button type="button" class="btn btn-sec' + (cur ? '' : ' is-on') + '" data-season="">📅 Otomatis (tanggal)</button>'
      + window.vcSeasonList().map(function (s) {
        return '<button type="button" class="btn btn-sec' + (cur === s.id ? ' is-on' : '') + '" data-season="' + s.id + '">' + s.icon + ' ' + esc(s.name) + '</button>';
      }).join('');
    sp.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-season]') : null;
      if (!t) return;
      try { var v = t.getAttribute('data-season'); if (v) localStorage.setItem('vc_season_preview', v); else localStorage.removeItem('vc_season_preview'); } catch (er) {}
      location.hash = '';
      location.reload();
    });
  }
})();
