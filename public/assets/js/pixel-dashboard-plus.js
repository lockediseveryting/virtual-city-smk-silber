/*
 * Pixel Dashboard Plus — pop-up di dashboard (beranda & admin):
 *  1. Broadcast dari konsol admin (GET /api/broadcasts), sekali per broadcast per perangkat.
 *  2. Ajakan ikuti Saluran WhatsApp resmi (markup #pxdWaModal di PixelDashboard), maks. 1×/hari;
 *     tidak muncul lagi setelah pengguna menekan "Gabung".
 *  3. Cara Main, Lapor Bug / Aduan (POST /api/report → DM ROOT).
 *  + label musim & zona waktu di jam HUD.
 * window.vcShowBroadcast(item, isTest) dipakai tombol "Tes" di konsol admin.
 * ES2017 tanpa ?. / ??.
 */
(function () {
  'use strict';
  var pxd = document.getElementById('pxd');
  var LS = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function csrf() { return (document.querySelector('meta[name="csrf"]') || {}).content || ''; }
  function today() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }

  // ---------- Zona waktu ----------
  function tzLabel() {
    var off = -new Date().getTimezoneOffset(); // menit
    if (off === 420) return 'WIB';
    if (off === 480) return 'WITA';
    if (off === 540) return 'WIT';
    var h = Math.floor(Math.abs(off) / 60), m = Math.abs(off) % 60;
    return 'GMT' + (off >= 0 ? '+' : '-') + h + (m ? ':' + (m < 10 ? '0' : '') + m : '');
  }
  window.vcTzLabel = tzLabel;

  // ---------- Modal generik (gaya papan kayu .pxd-pick) ----------
  var queue = [], open = null;
  function modal(id, html, onClose) {
    var el = document.createElement('div');
    el.className = 'pxd-pick pxd-pop';
    el.id = id;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML = '<div class="pxd-pick__box px-panel pxd-pop__box">' + html + '</div>';
    el.addEventListener('click', function (e) {
      var t = e.target;
      if (t === el || (t.closest && t.closest('[data-close]'))) close(el, onClose);
    });
    document.body.appendChild(el);
    var f = el.querySelector('[data-focus]') || el.querySelector('button');
    if (f) setTimeout(function () { f.focus(); }, 30);
    return el;
  }
  function close(el, cb) {
    if (el && el.parentNode) el.parentNode.removeChild(el);
    if (cb) cb();
    if (el === open) { open = null; next(); }
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var pops = document.querySelectorAll('.pxd-pop');
    if (pops.length) close(pops[pops.length - 1]);
  });
  // Antrean pop-up otomatis: satu per satu
  function enqueue(fn) { queue.push(fn); if (!open) next(); }
  function next() { var fn = queue.shift(); if (fn) open = fn(); }

  // ---------- 1. Broadcast ----------
  function seenIds() { try { return JSON.parse(LS.get('vc_bc_seen') || '[]'); } catch (e) { return []; } }
  function markSeen(id) { var s = seenIds(); if (s.indexOf(id) < 0) { s.push(id); LS.set('vc_bc_seen', JSON.stringify(s.slice(-60))); } }
  function showBroadcast(b, isTest) {
    var link = '';
    if (b.link_url) {
      var ext = /^https:/i.test(b.link_url);
      link = '<a class="px-btn px-btn--gold" href="' + esc(b.link_url) + '"' + (ext ? ' target="_blank" rel="noopener noreferrer"' : '') + ' data-close>' + esc(b.link_label || 'Buka') + '</a>';
    }
    var el = modal('pxdBc' + (b.id || 'test'),
      '<div class="pxd-pick__head"><span class="pxd-wa-pill">📢 ' + (isTest ? 'TES BROADCAST' : 'PENGUMUMAN') + '</span>'
      + '<button type="button" class="px-btn px-btn--dark pxd-pick__close" data-close aria-label="Tutup">✕</button></div>'
      + '<h2 class="pxd-pop__title">' + esc(b.title) + '</h2>'
      + '<p class="pxd-pop__msg">' + esc(b.message).replace(/\n/g, '<br>') + '</p>'
      + (b.author ? '<p class="pxd-pop__meta">— ' + esc(b.author) + '</p>' : '')
      + '<div class="pxd-pop__actions">' + link + '<button type="button" class="px-btn px-btn--dark" data-close data-focus>Oke, mengerti</button></div>',
      function () { if (!isTest && b.id) markSeen(b.id); });
    return el;
  }
  window.vcShowBroadcast = function (b, isTest) {
    if (isTest) { showBroadcast(b, true); return; }
    enqueue(function () { return showBroadcast(b, false); });
  };
  function loadBroadcasts() {
    return fetch('/api/broadcasts', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { items: [] }; })
      .then(function (d) {
        var seen = seenIds();
        (d.items || []).slice().reverse().forEach(function (b) {
          if (seen.indexOf(Number(b.id)) < 0 && seen.indexOf(b.id) < 0) {
            b.id = Number(b.id);
            window.__vcBcShown = window.__vcBcShown || {};
            if (window.__vcBcShown[b.id]) return; // sudah ditampilkan vc-live.js
            window.__vcBcShown[b.id] = 1;
            enqueue(function () {
              // Terdengar: bunyi + dibacakan (vc-live.js, disisip di semua halaman).
              if (window.vcChime) window.vcChime();
              if (window.vcSpeakBroadcast) setTimeout(function () { window.vcSpeakBroadcast(b); }, 1300);
              return showBroadcast(b, false);
            });
          }
        });
      })
      .catch(function () {});
  }

  // ---------- 2. Saluran WhatsApp ----------
  var wa = document.getElementById('pxdWaModal');
  window.vcOpenWaModal = function () { if (wa) { wa.hidden = false; open = wa; var b = wa.querySelector('.pxd-btn-wa'); if (b) b.focus(); } };
  window.vcCloseWaModal = function () {
    if (!wa) return;
    var cb = document.getElementById('pxdWaDontShow');
    LS.set('vc_wa_snooze', cb && cb.checked ? today() : ('s' + today()));
    wa.hidden = true;
    if (open === wa) { open = null; next(); }
  };
  window.vcOnWaJoined = function () { LS.set('vc_wa_joined', '1'); setTimeout(window.vcCloseWaModal, 300); };
  if (wa) wa.addEventListener('click', function (e) { if (e.target === wa) window.vcCloseWaModal(); });
  function maybeWa() {
    if (!wa || LS.get('vc_wa_joined') === '1') return;
    var snooze = LS.get('vc_wa_snooze') || '';
    // "Nanti saja" = sembunyi sampai sesi berikutnya hari ini; centang = sepanjang hari ini
    if (snooze === today()) return;
    try { if (snooze === 's' + today() && sessionStorage.getItem('vc_wa_shown') === '1') return; } catch (e) {}
    enqueue(function () { try { sessionStorage.setItem('vc_wa_shown', '1'); } catch (e) {} window.vcOpenWaModal(); return wa; });
  }

  // ---------- 3. Cara Main ----------
  window.vcHowToPlay = function () {
    modal('pxdHow',
      '<div class="pxd-pick__head"><h2>❓ Cara Main</h2><button type="button" class="px-btn px-btn--dark pxd-pick__close" data-close aria-label="Tutup">✕</button></div>'
      + '<div class="pxd-how">'
      + '<section><h3>🚪 Masuk</h3><ol><li>Pilih <b>Dunia 3D</b> atau <b>Dunia Pixel</b> di menu.</li><li>Punya kode dari guru? Pakai <b>Gabung Kelas</b>.</li><li>Ketuk karakter di dashboard untuk <b>ganti baju, rambut & aksesoris</b> (cowok / cewek bebas).</li></ol></section>'
      + '<section><h3>🎮 Dunia 3D</h3><table><tr><th>Laptop</th><th>HP</th><th></th></tr>'
      + '<tr><td>W A S D</td><td>Joystick kiri</td><td>Jalan</td></tr>'
      + '<tr><td>Shift</td><td>Dorong joystick penuh</td><td>Lari</td></tr>'
      + '<tr><td>Spasi</td><td>⤒ Lompat</td><td>Lompat</td></tr>'
      + '<tr><td>E</td><td>✋ Aksi</td><td>Duduk / bicara / buka</td></tr>'
      + '<tr><td>Geser mouse</td><td>Geser layar</td><td>Putar kamera</td></tr>'
      + '<tr><td>Scroll / + −</td><td>Cubit 2 jari / + −</td><td>Zoom</td></tr>'
      + '<tr><td>Enter / T</td><td>💬</td><td>Chat</td></tr>'
      + '<tr><td>V</td><td>🎤</td><td>Bicara (mic)</td></tr></table></section>'
      + '<section><h3>🧱 Dunia Pixel</h3><table><tr><td>A / D</td><td>◀ ▶</td><td>Jalan</td></tr>'
      + '<tr><td>W / S</td><td>▲ ▼</td><td>Masuk pintu, naik/turun tangga</td></tr>'
      + '<tr><td>E</td><td>AKSI</td><td>Interaksi</td></tr><tr><td>M</td><td>🗺</td><td>Peta</td></tr>'
      + '<tr><td>+ − / scroll</td><td>Cubit 2 jari / + −</td><td>Zoom</td></tr><tr><td>C / T</td><td>💬</td><td>Chat</td></tr></table></section>'
      + '<section><h3>🏆 Misi & Hadiah</h3><p>Selesaikan <b>misi</b> dari guru → dapat <b>XP & koin</b> → tukarkan koin di <b>Toko</b> dengan item langka (gelar, aksesoris, rambut, karakter).</p></section>'
      + '<section><h3>🎮 Mabar (main bareng)</h3><ol><li>Tekan <b>🎮 Mabar</b> → <b>🌐 Buat Mabar Publik</b> (siapa pun bisa masuk dari daftar) atau <b>🔒 Pakai Kode</b> (kamu jadi 👑 kapten).</li><li>Teman yang <b>🟢 sedang online</b> tampil paling atas — tekan <b>Undang</b>. Bisa juga cari nama, atau tekan <b>🔗 QR</b> untuk bagikan link / QR / WhatsApp.</li><li>Sampai <b>15 orang</b> berdiri bareng di dashboard: chat, nyalakan 🎤 mic, ketuk jalan untuk berjalan.</li><li>Kapten pilih <b>🎯 Misi Mabar</b> (Tur Sekolah, Sahabat Kucing, Jumat Bersih, Pemburu Anomali) lalu tujuan → <b>🚀 Berangkat bareng!</b></li><li>Selesaikan misi bersama di Dunia 3D / Pixel → semua anggota dapat <b>XP + koin</b>.</li></ol></section>'
      + '<section><h3>🐱 Kucing &amp; 🧹 Sampah</h3><p>Kucing sekolah berkeliaran di 3D &amp; Pixel — dekati lalu tekan <b>E / ✋</b> untuk mengelus. Sampah berserakan bisa dipungut (muncul lagi beberapa menit kemudian).</p></section>'
      + '<section><h3>📝 Tugas dari Guru</h3><p>Buka <b>Tugas</b>. Ada 2 jenis: <b>Uraian/Upload</b> (tulis jawaban atau lampirkan file) dan <b>Soal</b> (pilihan ganda + essay). Pilihan ganda langsung dinilai; kalau semua soal pilihan ganda, nilaimu langsung keluar. Essay dinilai guru.</p></section>'
      + '<section><h3>🧭 Navigasi & Jadwal Kumpul</h3><p>Di Dunia 3D tekan <b>🧭 Navigasi</b>: cari ruangan → <b>Arahkan</b> (ikuti kompas & tiang cahaya kuning) atau <b>Antar</b> (langsung pindah). Di Dunia Pixel pakai <b>🧭 Peta</b>. Saat jam pelajaran tiba, muncul banner <b>📢 Kumpul di …</b> — ikuti arahnya, kehadiranmu tercatat otomatis.</p></section>'
      + '<section><h3>🪑 Duduk & Menghadap Depan (Pixel)</h3><p>Dekati kursi → <b>E / ✋ DUDUK</b> (atau S/↓). Berdiri: E, W/↑, atau jalan. Diam sebentar / tekan S/↓ di tempat kosong → karakter menghadap depan.</p></section>'
      + '<section><h3>👻 Malam Hari…</h3><p>Hati-hati, malam hari ada <b>sosok misterius</b> di Dunia 3D & Pixel. Ada 4 jenis: Bayangan, Pocong, Kuntilanak & Mata Merah. Mereka menjaga jarak, menghilang bila didekati — lalu muncul lagi di belakangmu. Anomali selalu ada di setiap server, tidak bisa dimatikan. Berani buru semuanya di misi mabar <b>Pemburu Anomali</b>?</p></section>'
      + '<section><h3>👥 Team</h3><p>Virtual City dibuat oleh <b>M. Fardan AlFarizi</b> bersama para contributor. Lihat <a href="/team">halaman Team</a>.</p></section>'
      + '</div><div class="pxd-pop__actions"><a class="px-btn px-btn--gold" href="/misi">🎯 Lihat Misi</a><a class="px-btn" href="/toko">🛒 Toko</a><button type="button" class="px-btn px-btn--dark" data-close data-focus>Siap main!</button></div>');
  };

  // ---------- 4. Lapor Bug / Aduan ----------
  window.vcReportBug = function () {
    var nm = pxd ? (pxd.getAttribute('data-fullname') || '') : '';
    var cls = pxd ? (pxd.getAttribute('data-class') || '') : '';
    var el = modal('pxdReport',
      '<div class="pxd-pick__head"><h2>🐞 Lapor Bug / Aduan</h2><button type="button" class="px-btn px-btn--dark pxd-pick__close" data-close aria-label="Tutup">✕</button></div>'
      + '<p class="pxd-pop__meta">Laporan dikirim <b>langsung & hanya</b> ke DM admin ROOT. Balasan muncul di menu Chat.</p>'
      + '<form class="pxd-form" novalidate>'
      + '<label>Jenis<select name="kind"><option value="bug">🐞 Bug / Error</option><option value="aduan">📣 Aduan</option><option value="saran">💡 Saran Fitur</option></select></label>'
      + '<div class="pxd-form__row"><label>Nama<input name="name" maxlength="80" required value="' + esc(nm) + '" data-focus></label>'
      + '<label>Kelas<input name="class_name" maxlength="40" required placeholder="mis. X TJKT 2" value="' + esc(cls) + '"></label></div>'
      + '<label>Apa yang terjadi?<textarea name="what" rows="4" maxlength="1500" required placeholder="Contoh: di Dunia 3D lantai 2, karakter nyangkut di tangga setelah lompat."></textarea></label>'
      + '<label>Saran kamu<textarea name="suggestion" rows="3" maxlength="1000" placeholder="Contoh: tambah tombol keluar dari tangga."></textarea></label>'
      + '<p class="pxd-form__msg" aria-live="polite"></p>'
      + '<div class="pxd-pop__actions"><button type="button" class="px-btn px-btn--dark" data-close>Batal</button><button type="submit" class="px-btn px-btn--gold">📨 Kirim ke ROOT</button></div>'
      + '</form>');
    var form = el.querySelector('form'), msg = el.querySelector('.pxd-form__msg');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = { _csrf: csrf(), page: location.pathname, device: navigator.userAgent.slice(0, 150) };
      ['kind', 'name', 'class_name', 'what', 'suggestion'].forEach(function (k) { data[k] = form.elements[k].value.trim(); });
      if (!data.name || !data.class_name || !data.what) { msg.textContent = 'Nama, kelas, dan apa yang terjadi wajib diisi.'; msg.style.color = '#ffb4a8'; return; }
      var btn = form.querySelector('[type=submit]'); btn.disabled = true; msg.textContent = 'Mengirim…'; msg.style.color = '';
      fetch('/api/report', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': data._csrf }, body: JSON.stringify(data) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          btn.disabled = false;
          msg.textContent = (d && d.message) || 'Gagal mengirim.';
          msg.style.color = d && d.status === 'ok' ? '#9fe08a' : '#ffb4a8';
          if (d && d.status === 'ok') { form.reset(); setTimeout(function () { close(el); }, 1800); }
        })
        .catch(function () { btn.disabled = false; msg.textContent = 'Koneksi gagal. Coba lagi.'; msg.style.color = '#ffb4a8'; });
    });
  };

  // ---------- Label musim + zona waktu di jam ----------
  function decorateClock() {
    var el = document.getElementById('pxdClock');
    if (!el) return;
    var s = window.vcSeason ? window.vcSeason() : null;
    var base = el.getAttribute('data-base');
    var txt = el.textContent;
    if (base !== txt) {
      var out = txt + ' ' + tzLabel() + (s ? ' · ' + s.icon + ' ' + s.name : '');
      el.textContent = out;
      el.setAttribute('data-base', out);
    }
  }
  if (document.getElementById('pxdClock')) {
    decorateClock();
    setInterval(decorateClock, 1000);
  }

  // ---------- Emote dashboard (18 emote, sama dengan Dunia Pixel & 3D) ----------
  /** Animasikan elemen karakter (tombol/tautan berisi kanvas 22 px) memakai kurva PixelAvatar.emoteAnim. */
  window.vcAnimEmote = function (el, name) {
    var PA = window.PixelAvatar;
    if (!el || !PA || !PA.emoteAnim) return;
    if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var t0 = performance.now();
    el.style.transformOrigin = '50% 100%';
    (function step(now) {
      var t = (now - t0) / 1000;
      if (t > 2.4) { el.style.transform = ''; return; }
      var a = PA.emoteAnim(name, t), k = 100 / 22; // 1 px sprite = 1/22 lebar karakter
      el.style.transform = 'translate(' + (a.dx * k) + '%,' + (a.dy * k / 1.9) + '%) rotate(' + a.rot + 'rad) scaleY(' + a.sy + ')';
      requestAnimationFrame(step);
    })(t0);
  };
  var emoBox = document.getElementById('pxdEmotes');
  function playEmote(name) {
    var PA = window.PixelAvatar, emo = document.getElementById('pxdEmo');
    if (!PA) return;
    if (emo) {
      emo.textContent = PA.EMOTES[name] || '✨';
      emo.classList.remove('is-on'); void emo.offsetWidth; emo.classList.add('is-on');
    }
    window.vcAnimEmote(document.getElementById('pxdCharBtn'), name);
    var b = document.getElementById('pxdBubble'); if (b) b.classList.add('is-hidden');
    if (window.vcSfx) try { window.vcSfx('click'); } catch (e) {}
    if (window.vcParty && window.vcParty.emote) window.vcParty.emote(name);
    if (window.vcDaily) window.vcDaily.report('emote', name); // misi harian
  }
  window.vcEmote = {
    play: playEmote,
    toggle: function () {
      var PA = window.PixelAvatar;
      if (!emoBox || !PA) return;
      if (!emoBox.innerHTML) {
        var fav = (window.__AVATAR && window.__AVATAR.config && window.__AVATAR.config.emotes) || [];
        var keys = Object.keys(PA.EMOTES).filter(function (k) { return k !== 'sit'; });
        keys.sort(function (a, b) { return (fav.indexOf(a) < 0 ? 99 : fav.indexOf(a)) - (fav.indexOf(b) < 0 ? 99 : fav.indexOf(b)); });
        emoBox.innerHTML = '<div class="pxd-emotes__head"><b>😄 Emote</b><button type="button" class="px-plain pxd-emotes__x" data-emo-close aria-label="Tutup">✕</button></div><div class="pxd-emotes__grid">'
          + keys.map(function (k) { return '<button type="button" class="px-plain pxd-emote" data-emo="' + k + '" title="' + esc(PA.EMOTE_LABEL[k] || k) + '"><span>' + PA.EMOTES[k] + '</span><small>' + esc(PA.EMOTE_LABEL[k] || k) + '</small></button>'; }).join('') + '</div>';
        emoBox.addEventListener('click', function (e) {
          var t = e.target.closest ? e.target.closest('[data-emo],[data-emo-close]') : null;
          if (!t) return;
          if (t.hasAttribute('data-emo-close')) { emoBox.hidden = true; return; }
          playEmote(t.getAttribute('data-emo'));
        });
      }
      emoBox.hidden = !emoBox.hidden;
    }
  };
  // ⋯ Lainnya: lipat/buka tombol jarang dipakai
  window.vcMoreToggle = function () {
    var m = document.getElementById('pxdMore'), b = document.querySelector('[data-more-btn]');
    if (!m) return;
    m.hidden = !m.hidden;
    if (b) b.setAttribute('aria-expanded', m.hidden ? 'false' : 'true');
  };
  // Badge "GRATIS" di tombol Gacha bila putaran gratis harian tersedia.
  var gTile = document.querySelector('[data-gacha-tile]');
  if (gTile) {
    fetch('/api/gacha', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (d && d.free_ready) { var i = document.createElement('i'); i.className = 'pxd-badge'; i.textContent = 'GRATIS'; gTile.appendChild(i); }
    }).catch(function () {});
  }

  // ---------- Mulai: broadcast dulu, lalu WA ----------
  if (pxd) {
    setTimeout(function () { loadBroadcasts().then(maybeWa); }, 900);
  }
})();
