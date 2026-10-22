/*
 * SilberOS — komputer sekolah yang BISA DIPAKAI di Dunia Pixel (Lab TIK, Lab Jaringan, ruang guru,
 * studio DKV, Silber Industries). Dekati meja komputer → E / AKSI → vcPC.open({ lab, scr }).
 * Aplikasi: Terminal (help, ipconfig, ping, tracert, subnet, whoami, date, neofetch, matrix, clear),
 * Kuis TJKT (10 soal acak), Kalkulator Subnet, Notepad (tersimpan per perangkat), Paint pixel 16×16,
 * game Ular, pintasan Tugas & Jadwal. Ringan, tanpa jaringan (bisa dipakai di mode offline).
 * ES2017.
 */
(function () {
  'use strict';
  if (window.vcPC) return;
  var root = null, ctx = { lab: 'Lab TIK', scr: '#5bd0ff' }, snakeTimer = null;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } return null; }
  function user() { return String(window.__PIX_NAME || 'siswa').split(' ')[0].toLowerCase().replace(/[^a-z0-9]/g, '') || 'siswa'; }

  var CSS = [
    '.spc{position:fixed;inset:0;z-index:2147482500;display:flex;align-items:center;justify-content:center;background:rgba(5,6,12,.78);padding:10px;font-family:"Pixelify Sans",ui-monospace,monospace}',
    '.spc__mon{width:min(860px,100%);height:min(560px,calc(100dvh - 20px));display:flex;flex-direction:column;background:#2b2f3a;border:10px solid #c9c2b0;border-bottom-width:26px;border-radius:12px;box-shadow:0 0 0 3px #5a5040,0 20px 60px rgba(0,0,0,.6);position:relative}',
    '.spc__mon::after{content:"SILBER";position:absolute;bottom:-22px;left:50%;transform:translateX(-50%);font-size:11px;letter-spacing:3px;color:#6b6250}',
    '.spc__scr{flex:1;min-height:0;position:relative;overflow:hidden;background:radial-gradient(ellipse at 30% 20%,#2f6f8f,#123247 70%);color:#e8f6ff}',
    '.spc__desk{position:absolute;inset:0 0 34px 0;display:grid;grid-template-columns:repeat(auto-fill,84px);grid-auto-rows:84px;gap:8px;padding:14px;align-content:start}',
    '.spc__ico{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;border:0;background:transparent;color:#fff;cursor:pointer;border-radius:8px;font:inherit;font-size:12px;text-shadow:1px 1px 0 #000;padding:4px}',
    '.spc__ico span{font-size:30px;filter:drop-shadow(1px 2px 0 rgba(0,0,0,.4))}',
    '.spc__ico:hover,.spc__ico:focus-visible{background:rgba(255,255,255,.14);outline:none}',
    '.spc__bar{position:absolute;left:0;right:0;bottom:0;height:34px;display:flex;align-items:center;gap:8px;padding:0 8px;background:#c9c2b0;color:#2b2416;font-size:13px}',
    '.spc__bar b{background:#2f8f4e;color:#fff;padding:3px 10px;border-radius:4px}',
    '.spc__bar .spc__off{margin-left:auto;border:0;background:#b23a2e;color:#fff;font:inherit;padding:4px 10px;border-radius:4px;cursor:pointer;min-height:28px}',
    '.spc__win{z-index:5;position:absolute;inset:10px 10px 44px 10px;display:flex;flex-direction:column;background:#f4f1e8;color:#1f2430;border:2px solid #1f2430;box-shadow:4px 4px 0 rgba(0,0,0,.35);border-radius:6px;overflow:hidden}',
    '.spc__wt{display:flex;align-items:center;gap:8px;padding:4px 6px 4px 10px;background:#2f6f8f;color:#fff;font-size:13px}',
    '.spc__wt button{margin-left:auto;border:0;background:#b23a2e;color:#fff;width:30px;height:26px;cursor:pointer;font:inherit;border-radius:4px}',
    '.spc__wb{flex:1;min-height:0;overflow:auto;padding:10px}',
    '.spc__term{background:#0b1410;color:#7dff9a;font:13px/1.45 ui-monospace,Consolas,monospace;padding:10px;height:100%;overflow:auto;white-space:pre-wrap;word-break:break-word}',
    '.spc__term input{background:transparent;border:0;color:#7dff9a;font:inherit;outline:none;width:70%;font-size:16px}',
    '.spc__btn{min-height:40px;padding:6px 12px;margin:4px;border:2px solid #1f2430;background:#e2d7b8;font:inherit;cursor:pointer;border-radius:6px;font-size:14px}',
    '.spc__btn:hover{background:#f1e6c6}.spc__btn.ok{background:#9be7a8}.spc__btn.no{background:#f4a3a3}',
    '.spc__ta{width:100%;height:100%;min-height:200px;box-sizing:border-box;font:16px/1.5 ui-monospace,monospace;border:1px solid #1f2430;padding:8px;resize:none;background:#fffdf5}',
    '.spc__paint{display:grid;grid-template-columns:repeat(16,1fr);width:min(320px,70vw);aspect-ratio:1;border:2px solid #1f2430;touch-action:none;background:#fff}',
    '.spc__paint i{border:1px solid rgba(0,0,0,.06)}',
    '.spc__pal{display:flex;flex-wrap:wrap;gap:4px;margin:8px 0}.spc__pal button{width:30px;height:30px;border:2px solid #1f2430;cursor:pointer;border-radius:4px}.spc__pal button.on{outline:3px solid #f59e0b}',
    '.spc input.spc__in{font-size:16px;padding:6px 8px;border:2px solid #1f2430;border-radius:6px;width:min(260px,100%)}',
    '.spc table{border-collapse:collapse;font-size:14px;margin-top:8px}.spc td{border:1px solid #1f2430;padding:4px 8px}',
    '@media (max-width:560px){.spc__desk{grid-template-columns:repeat(auto-fill,72px);grid-auto-rows:76px}.spc__mon{border-width:6px;border-bottom-width:20px}}'
  ].join('\n');

  var APPS = [
    ['term', '🖥️', 'Terminal'], ['quiz', '🌐', 'Kuis TJKT'], ['subnet', '🧮', 'Subnet'], ['note', '📝', 'Notepad'],
    ['paint', '🎨', 'Paint'], ['snake', '🐍', 'Ular'], ['tugas', '📂', 'Tugas'], ['jadwal', '📅', 'Jadwal']
  ];

  function shell() {
    var h = '<div class="spc__mon" role="dialog" aria-modal="true" aria-label="Komputer sekolah"><div class="spc__scr"><div class="spc__desk">';
    APPS.forEach(function (a) { h += '<button type="button" class="spc__ico" data-app="' + a[0] + '"><span>' + a[1] + '</span>' + a[2] + '</button>'; });
    h += '</div><div class="spc__bar"><b>⊞ SilberOS</b><span>' + esc(ctx.lab) + ' · ' + esc(user()) + '@silber</span><span data-clock></span><button type="button" class="spc__off" data-off>⏻ Matikan</button></div></div></div>';
    root.innerHTML = h;
    tickClock();
  }
  function tickClock() { var c = root && root.querySelector('[data-clock]'); if (c) c.textContent = new Date().toTimeString().slice(0, 5); }
  function win(title, body) {
    var scr = root.querySelector('.spc__scr');
    var old = scr.querySelector('.spc__win'); if (old) old.remove();
    clearInterval(snakeTimer);
    var w = document.createElement('div'); w.className = 'spc__win';
    w.innerHTML = '<div class="spc__wt">' + title + '<button type="button" data-close aria-label="Tutup jendela">✕</button></div><div class="spc__wb">' + body + '</div>';
    scr.appendChild(w);
    w.querySelector('[data-close]').addEventListener('click', function () { clearInterval(snakeTimer); w.remove(); });
    return w.querySelector('.spc__wb');
  }

  // ---------------- Terminal ----------------
  function ipToInt(ip) { var p = ip.split('.').map(Number); if (p.length !== 4 || p.some(function (n) { return !(n >= 0 && n <= 255) || isNaN(n); })) return null; return ((p[0] << 24) >>> 0) + (p[1] << 16) + (p[2] << 8) + p[3]; }
  function intToIp(n) { return [n >>> 24, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join('.'); }
  function subnet(cidr) {
    var m = /^(\d+\.\d+\.\d+\.\d+)\/(\d{1,2})$/.exec(String(cidr).trim());
    if (!m) return null;
    var ip = ipToInt(m[1]), pre = +m[2];
    if (ip === null || pre < 0 || pre > 32) return null;
    var mask = pre === 0 ? 0 : (0xffffffff << (32 - pre)) >>> 0, net = (ip & mask) >>> 0, bc = (net | (~mask >>> 0)) >>> 0;
    var hosts = pre >= 31 ? (pre === 32 ? 1 : 2) : Math.pow(2, 32 - pre) - 2;
    return { net: intToIp(net), mask: intToIp(mask), bc: intToIp(bc), first: pre >= 31 ? intToIp(net) : intToIp(net + 1), last: pre >= 31 ? intToIp(bc) : intToIp(bc - 1), hosts: hosts, pre: pre };
  }
  function terminal() {
    var b = win('🖥️ Terminal — ' + esc(user()) + '@silber', '<div class="spc__term" data-t></div>');
    b.style.padding = '0';
    var t = b.querySelector('[data-t]'), myIp = '192.168.10.' + (20 + (user().length * 7) % 200);
    function out(s) { var d = document.createElement('div'); d.textContent = s; t.insertBefore(d, t.lastChild); t.scrollTop = t.scrollHeight; }
    t.innerHTML = '<div>SilberOS Terminal v1.0 — ketik <b>help</b>\n</div><div>' + esc(user()) + '@silber:~$ <input aria-label="Perintah terminal" autocomplete="off" autocapitalize="off" spellcheck="false"></div>';
    var inp = t.querySelector('input'); setTimeout(function () { inp.focus(); }, 50);
    var CMD = {
      help: function () { return 'Perintah: help, ipconfig, ping <host>, tracert <host>, subnet <ip/cidr>, whoami, date, neofetch, ls, cat <file>, matrix, clear'; },
      ipconfig: function () { return 'Ethernet adapter LAN ' + ctx.lab + ':\n  IPv4 Address . . : ' + myIp + '\n  Subnet Mask . . . : 255.255.255.0\n  Default Gateway . : 192.168.10.1\n  DNS Server  . . . : 192.168.10.1, 8.8.8.8'; },
      whoami: function () { return user() + ' (siswa ' + ctx.lab + ')'; },
      date: function () { return new Date().toString(); },
      ls: function () { return 'tugas.txt  catatan.txt  jaringan.cfg  rahasia.txt'; },
      neofetch: function () { return '   ▄▄▄▄   ' + user() + '@silber\n  █ SB █  OS: SilberOS 1.0 (Dunia Pixel)\n   ▀▀▀▀   Host: ' + ctx.lab + '\n          CPU: Pixel Core i8 @ 16 bit\n          Shell: silbersh'; },
      cat: function (a) {
        if (a === 'tugas.txt') return 'Tugas hari ini: cek menu 📂 Tugas di desktop. Semangat!';
        if (a === 'catatan.txt') return ls('vc_pc_note') || '(kosong — tulis di 📝 Notepad)';
        if (a === 'jaringan.cfg') return 'hostname silber-lab\ninterface eth0\n ip address ' + myIp + ' 255.255.255.0\n no shutdown';
        if (a === 'rahasia.txt') return 'Akses ditolak. (Psst… coba perintah "matrix")';
        return 'cat: ' + (a || '') + ': berkas tidak ditemukan';
      },
      subnet: function (a) {
        var r = subnet(a || '');
        if (!r) return 'Pakai: subnet 192.168.1.0/26';
        return 'Network   : ' + r.net + '/' + r.pre + '\nNetmask   : ' + r.mask + '\nHost awal : ' + r.first + '\nHost akhir: ' + r.last + '\nBroadcast : ' + r.bc + '\nJml host  : ' + r.hosts;
      },
      matrix: function () {
        var s = '', ch = '01アイウエオカキクケコ';
        for (var i = 0; i < 8; i++) { var l = ''; for (var j = 0; j < 46; j++) l += Math.random() < 0.6 ? ch.charAt(Math.floor(Math.random() * ch.length)) : ' '; s += l + '\n'; }
        return s + 'Selamat datang di Matrix, ' + user() + '.';
      }
    };
    function ping(host, tr) {
      host = host || '8.8.8.8';
      var base = /^192\.168\./.test(host) ? 1 : host === 'smksilber' || /silber/.test(host) ? 4 : 18;
      if (tr) { ['192.168.10.1', '10.10.26.1', '202.155.13.1', host].forEach(function (hop, i) { setTimeout(function () { out(' ' + (i + 1) + '   ' + (base + i * 3 + Math.floor(Math.random() * 4)) + ' ms   ' + hop); }, 350 * (i + 1)); }); return 'Tracing route to ' + host + ' …'; }
      var n = 0; var iv = setInterval(function () { n++; out('Reply from ' + host + ': bytes=32 time=' + (base + Math.floor(Math.random() * 9)) + 'ms TTL=' + (/^192/.test(host) ? 64 : 117)); if (n >= 4) { clearInterval(iv); out('Paket: terkirim 4, diterima 4, hilang 0 (0%)'); } }, 450);
      return 'Pinging ' + host + ' with 32 bytes of data:';
    }
    inp.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var line = inp.value.trim(); inp.value = '';
      out(user() + '@silber:~$ ' + line);
      if (!line) return;
      var parts = line.split(/\s+/), c = parts[0].toLowerCase(), a = parts.slice(1).join(' ');
      if (c === 'clear') { Array.from(t.children).slice(0, -1).forEach(function (n) { n.remove(); }); return; }
      if (c === 'ping') { out(ping(a, false)); return; }
      if (c === 'tracert' || c === 'traceroute') { out(ping(a, true)); return; }
      out(CMD[c] ? CMD[c](a) : c + ': perintah tidak dikenal. Ketik help');
    });
  }

  // ---------------- Kuis TJKT ----------------
  var BANK = [
    ['Port default HTTPS?', ['80', '443', '21', '23'], 1], ['Protokol pemberi IP otomatis?', ['DNS', 'DHCP', 'FTP', 'SMTP'], 1],
    ['Lapisan OSI ke-3 adalah…', ['Data Link', 'Transport', 'Network', 'Session'], 2], ['Perintah cek koneksi ke host?', ['ping', 'ls', 'cd', 'echo'], 0],
    ['Subnet mask /24 adalah…', ['255.255.0.0', '255.255.255.0', '255.0.0.0', '255.255.255.128'], 1], ['Kabel UTP straight dipakai untuk…', ['PC ke Switch', 'PC ke PC', 'Switch ke Switch lama', 'Router ke Router'], 0],
    ['DNS berfungsi untuk…', ['Enkripsi data', 'Menerjemahkan nama domain ke IP', 'Membagi IP', 'Menyimpan file'], 1], ['Jumlah host /26?', ['62', '64', '126', '30'], 0],
    ['Perangkat penghubung antar-jaringan berbeda?', ['Hub', 'Switch', 'Router', 'Repeater'], 2], ['Port default SSH?', ['22', '25', '110', '3389'], 0],
    ['Topologi dengan pusat switch?', ['Bus', 'Ring', 'Star', 'Mesh'], 2], ['Urutan warna T568B pin 1?', ['Putih-Oranye', 'Putih-Hijau', 'Biru', 'Coklat'], 0],
    ['Alamat IP kelas C diawali…', ['10.x', '172.16.x', '192.168.x', '127.x'], 2], ['127.0.0.1 disebut…', ['Gateway', 'Broadcast', 'Loopback', 'DNS'], 2]
  ];
  function quiz() {
    var qs = BANK.slice().sort(function () { return Math.random() - 0.5; }).slice(0, 10), i = 0, score = 0;
    var b = win('🌐 Kuis TJKT — ' + esc(ctx.lab), '');
    function show() {
      if (i >= qs.length) {
        b.innerHTML = '<h3>Skor: ' + score + ' / ' + qs.length + '</h3><p>' + (score >= 8 ? '🏆 Mantap! Calon teknisi jaringan handal.' : score >= 5 ? '👍 Bagus, terus latihan!' : '📚 Ayo belajar lagi di Lab.') + '</p><button class="spc__btn" data-again>🔁 Main lagi</button>';
        b.querySelector('[data-again]').addEventListener('click', quiz);
        var best = +(ls('vc_pc_quiz_best') || 0); if (score > best) ls('vc_pc_quiz_best', String(score));
        return;
      }
      var q = qs[i];
      b.innerHTML = '<p style="opacity:.7">Soal ' + (i + 1) + ' / ' + qs.length + ' · skor ' + score + '</p><h3 style="margin:6px 0 10px">' + esc(q[0]) + '</h3>'
        + q[1].map(function (o, k) { return '<button class="spc__btn" data-k="' + k + '" style="display:block;width:100%;text-align:left">' + 'ABCD'.charAt(k) + '. ' + esc(o) + '</button>'; }).join('');
      b.querySelectorAll('[data-k]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var k = +btn.getAttribute('data-k');
          btn.classList.add(k === q[2] ? 'ok' : 'no');
          if (k !== q[2]) b.querySelector('[data-k="' + q[2] + '"]').classList.add('ok'); else score++;
          b.querySelectorAll('[data-k]').forEach(function (x) { x.disabled = true; });
          setTimeout(function () { i++; show(); }, 750);
        });
      });
    }
    show();
  }
  function subnetApp() {
    var b = win('🧮 Kalkulator Subnet', '<p>Masukkan alamat & prefix, misal <b>192.168.10.0/26</b></p><input class="spc__in" data-ip value="192.168.10.0/26" aria-label="Alamat IP/prefix"> <button class="spc__btn" data-go>Hitung</button><div data-out></div>');
    function go() {
      var r = subnet(b.querySelector('[data-ip]').value);
      b.querySelector('[data-out]').innerHTML = r ? '<table><tr><td>Network</td><td>' + r.net + '/' + r.pre + '</td></tr><tr><td>Netmask</td><td>' + r.mask + '</td></tr><tr><td>Host awal</td><td>' + r.first + '</td></tr><tr><td>Host akhir</td><td>' + r.last + '</td></tr><tr><td>Broadcast</td><td>' + r.bc + '</td></tr><tr><td>Jumlah host</td><td>' + r.hosts + '</td></tr></table>' : '<p style="color:#b23a2e">Format salah. Contoh: 10.0.0.0/8</p>';
    }
    b.querySelector('[data-go]').addEventListener('click', go);
    b.querySelector('[data-ip]').addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
    go();
  }
  function note() {
    var b = win('📝 Notepad — catatan.txt', '<textarea class="spc__ta" aria-label="Catatan" placeholder="Tulis catatan pelajaran di sini… (tersimpan di perangkat ini)"></textarea>');
    var ta = b.querySelector('textarea'); ta.value = ls('vc_pc_note') || '';
    ta.addEventListener('input', function () { ls('vc_pc_note', ta.value.slice(0, 5000)); });
    setTimeout(function () { ta.focus(); }, 50);
  }
  function paint() {
    var PAL = ['#1f2430', '#ffffff', '#e74c3c', '#f39c12', '#f1c40f', '#2ecc71', '#3498db', '#9b59b6', '#e2ad84', '#8b5a2b'];
    var cells = []; try { cells = JSON.parse(ls('vc_pc_paint') || '[]'); } catch (e) { cells = []; }
    var b = win('🎨 Paint Pixel 16×16', '<div class="spc__pal" data-pal></div><div class="spc__paint" data-cv></div><button class="spc__btn" data-clr>🧽 Bersihkan</button>');
    var cur = PAL[0], pal = b.querySelector('[data-pal]'), cv = b.querySelector('[data-cv]'), down = false;
    pal.innerHTML = PAL.map(function (c, i) { return '<button style="background:' + c + '" data-c="' + c + '" aria-label="Warna ' + (i + 1) + '"' + (i === 0 ? ' class="on"' : '') + '></button>'; }).join('');
    pal.addEventListener('click', function (e) { var t = e.target.closest('[data-c]'); if (!t) return; cur = t.getAttribute('data-c'); pal.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === t); }); });
    var h = ''; for (var i = 0; i < 256; i++) h += '<i data-i="' + i + '" style="background:' + (cells[i] || '#ffffff') + '"></i>';
    cv.innerHTML = h;
    function put(e) { var el = document.elementFromPoint(e.clientX, e.clientY); if (!el || !el.hasAttribute || !el.hasAttribute('data-i')) return; el.style.background = cur; cells[+el.getAttribute('data-i')] = cur; ls('vc_pc_paint', JSON.stringify(cells)); }
    cv.addEventListener('pointerdown', function (e) { down = true; put(e); });
    cv.addEventListener('pointermove', function (e) { if (down) put(e); });
    window.addEventListener('pointerup', function () { down = false; });
    b.querySelector('[data-clr]').addEventListener('click', function () { cells = []; ls('vc_pc_paint', '[]'); cv.querySelectorAll('i').forEach(function (x) { x.style.background = '#ffffff'; }); });
  }
  function snake() {
    var b = win('🐍 Ular — panah / WASD / geser', '<p data-sc>Skor: 0 · Rekor: ' + (ls('vc_pc_snake') || 0) + '</p><canvas width="320" height="320" style="width:min(320px,70vw);image-rendering:pixelated;border:2px solid #1f2430;background:#0b1410;touch-action:none" data-cv></canvas><div><button class="spc__btn" data-d="up">▲</button><button class="spc__btn" data-d="left">◀</button><button class="spc__btn" data-d="down">▼</button><button class="spc__btn" data-d="right">▶</button></div>');
    var cv = b.querySelector('[data-cv]'), g = cv.getContext('2d'), N = 16, S = 20;
    var body = [[8, 8], [7, 8], [6, 8]], dir = [1, 0], nd = dir, food = [12, 8], score = 0, dead = false;
    function setDir(d) { var m = { up: [0, -1], down: [0, 1], left: [-1, 0], right: [1, 0] }[d]; if (m && (m[0] !== -dir[0] || m[1] !== -dir[1])) nd = m; }
    function keys(e) { var k = { ArrowUp: 'up', w: 'up', ArrowDown: 'down', s: 'down', ArrowLeft: 'left', a: 'left', ArrowRight: 'right', d: 'right' }[e.key]; if (k && root && !root.hidden) { setDir(k); e.preventDefault(); e.stopPropagation(); } }
    document.addEventListener('keydown', keys, true);
    b.querySelectorAll('[data-d]').forEach(function (x) { x.addEventListener('click', function () { setDir(x.getAttribute('data-d')); }); });
    var sx = 0, sy = 0; cv.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
    cv.addEventListener('touchend', function (e) { var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy; if (Math.max(Math.abs(dx), Math.abs(dy)) > 20) setDir(Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up')); });
    function draw() {
      g.fillStyle = '#0b1410'; g.fillRect(0, 0, 320, 320);
      g.fillStyle = '#ff6b6b'; g.fillRect(food[0] * S + 3, food[1] * S + 3, S - 6, S - 6);
      body.forEach(function (p, i) { g.fillStyle = i ? '#4ade80' : '#a3ff9f'; g.fillRect(p[0] * S + 1, p[1] * S + 1, S - 2, S - 2); });
      if (dead) { g.fillStyle = 'rgba(0,0,0,.6)'; g.fillRect(0, 130, 320, 60); g.fillStyle = '#fff'; g.font = '20px monospace'; g.textAlign = 'center'; g.fillText('GAME OVER — ketuk ▲', 160, 166); }
    }
    function step() {
      if (dead) return;
      dir = nd;
      var h = [body[0][0] + dir[0], body[0][1] + dir[1]];
      if (h[0] < 0 || h[1] < 0 || h[0] >= N || h[1] >= N || body.some(function (p) { return p[0] === h[0] && p[1] === h[1]; })) {
        dead = true; var best = +(ls('vc_pc_snake') || 0); if (score > best) ls('vc_pc_snake', String(score));
        draw(); setTimeout(function () { body = [[8, 8], [7, 8], [6, 8]]; dir = nd = [1, 0]; score = 0; dead = false; }, 1600); return;
      }
      body.unshift(h);
      if (h[0] === food[0] && h[1] === food[1]) { score++; do { food = [Math.floor(Math.random() * N), Math.floor(Math.random() * N)]; } while (body.some(function (p) { return p[0] === food[0] && p[1] === food[1]; })); }
      else body.pop();
      var sc = b.querySelector('[data-sc]'); if (sc) sc.textContent = 'Skor: ' + score + ' · Rekor: ' + Math.max(score, +(ls('vc_pc_snake') || 0));
      draw();
    }
    draw();
    snakeTimer = setInterval(function () { if (!document.body.contains(cv)) { clearInterval(snakeTimer); document.removeEventListener('keydown', keys, true); return; } step(); }, 140);
  }
  function link(url, title) {
    var b = win(title, '<p>Buka halaman ini di tab baru? Dunia Pixel tetap berjalan.</p><a class="spc__btn" style="display:inline-block;text-decoration:none;color:inherit" href="' + url + '" target="_blank" rel="noopener">↗ Buka ' + esc(title) + '</a>');
    return b;
  }

  function open(opt) {
    opt = opt || {};
    ctx.lab = opt.lab || ctx.lab; ctx.scr = opt.scr || ctx.scr;
    if (!root) {
      var s = document.createElement('style'); s.textContent = CSS; document.head.appendChild(s);
      root = document.createElement('div'); root.className = 'spc';
      document.body.appendChild(root);
      root.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-app],[data-off]') : null;
        if (e.target === root || (t && t.hasAttribute('data-off'))) { close(); return; }
        if (!t) return;
        var a = t.getAttribute('data-app');
        if (a === 'term') terminal(); else if (a === 'quiz') quiz(); else if (a === 'subnet') subnetApp(); else if (a === 'note') note();
        else if (a === 'paint') paint(); else if (a === 'snake') snake(); else if (a === 'tugas') link('/tugas', 'Tugas'); else if (a === 'jadwal') link('/jadwal', 'Jadwal');
      });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && root && !root.hidden) close(); });
      setInterval(tickClock, 20000);
    }
    shell();
    root.hidden = false;
    root.style.display = 'flex';
    ctx.engine = opt.engine || ctx.engine || null; // jeda game selama komputer dipakai (WASD tidak menggerakkan pemain)
    if (ctx.engine) ctx.engine.paused = true;
    if (window.vcDaily) window.vcDaily.report('pc', String(opt.ref || ctx.lab).replace(/[^A-Za-z0-9_-]/g, '').slice(0, 30) || 'lab');
    var first = root.querySelector('[data-app]'); if (first) setTimeout(function () { first.focus(); }, 60);
  }
  function close() {
    clearInterval(snakeTimer);
    if (root) { root.hidden = true; root.style.display = 'none'; }
    if (ctx.engine) ctx.engine.paused = false;
  }
  window.vcPC = { open: open, close: close, subnet: subnet };
})();
