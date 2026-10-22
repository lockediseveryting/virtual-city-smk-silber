/*
 * VC Sound — musik latar chiptune buatan sendiri (Web Audio, tanpa file/hak cipta) + suara UI.
 *   VcMusic.play('dashboard' | 'login' | 'game'), VcMusic.stop(), VcMusic.toggle()
 *   Lagu otomatis: body.px-auth → 'login', dashboard (#pxd) → 'dashboard'. /dunia memanggil 'game'.
 *   Suara UI: arahkan kursor ke tombol → "blip" + tombol membesar (CSS pixel-theme), klik → "klik".
 *   Pengaturan disimpan per perangkat: vc_music_on, vc_sfx_on (default nyala).
 *   Autoplay browser: bunyi baru mulai setelah ketukan/klik/tombol pertama.
 *   Lagu pilihan PEMILIK (/media, GET /api/media/config): login · dashboard · admin · game (3D) · pixel.
 *   Bila diisi, berkas audio itu diputar berulang menggantikan chiptune / musik ambient Pixel.
 *   Login dengan "suara video" aktif → musik login diam (suara dari video latar).
 * ES2017 tanpa ?. / ??.
 */
(function () {
  'use strict';
  if (window.VcMusic) return;
  var AC = window.AudioContext || window.webkitAudioContext;
  function pref(k, def) { try { var v = localStorage.getItem(k); return v === null ? def : v === '1'; } catch (e) { return def; } }
  function setPref(k, on) { try { localStorage.setItem(k, on ? '1' : '0'); } catch (e) {} }

  var ctx = null, out = null, musicBus = null, sfxBus = null, noiseBuf = null;
  var musicOn = pref('vc_music_on', true), sfxOn = pref('vc_sfx_on', true);
  var unlocked = false, wanted = null, track = null, timer = null, step = 0, nextT = 0;

  function ensure() {
    if (ctx || !AC) return ctx;
    try {
      ctx = new AC();
      out = ctx.createDynamicsCompressor();
      out.connect(ctx.destination);
      musicBus = ctx.createGain(); musicBus.gain.value = musicOn ? 0.32 : 0; musicBus.connect(out);
      sfxBus = ctx.createGain(); sfxBus.gain.value = 0.5; sfxBus.connect(out);
      noiseBuf = ctx.createBuffer(1, ctx.sampleRate * 0.25, ctx.sampleRate);
      var d = noiseBuf.getChannelData(0);
      for (var i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    } catch (e) { ctx = null; }
    return ctx;
  }
  function hz(m) { return 440 * Math.pow(2, (m - 69) / 12); }

  // ---------- Instrumen ----------
  function tone(bus, type, midi, t, dur, vol, slide) {
    var o = ctx.createOscillator(), g = ctx.createGain();
    o.type = type;
    o.frequency.setValueAtTime(hz(midi), t);
    if (slide) o.frequency.exponentialRampToValueAtTime(hz(midi + slide), t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol, t + 0.008);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(bus);
    o.start(t); o.stop(t + dur + 0.02);
  }
  function kick(t, v) {
    var o = ctx.createOscillator(), g = ctx.createGain();
    o.frequency.setValueAtTime(150, t); o.frequency.exponentialRampToValueAtTime(45, t + 0.12);
    g.gain.setValueAtTime(v, t); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.16);
    o.connect(g); g.connect(musicBus); o.start(t); o.stop(t + 0.18);
  }
  function noise(t, dur, v, hp) {
    var s = ctx.createBufferSource(), f = ctx.createBiquadFilter(), g = ctx.createGain();
    s.buffer = noiseBuf; f.type = 'highpass'; f.frequency.value = hp;
    g.gain.setValueAtTime(v, t); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    s.connect(f); f.connect(g); g.connect(musicBus); s.start(t); s.stop(t + dur + 0.02);
  }

  // ---------- Lagu (16 langkah per bar; null = diam) ----------
  var _ = null;
  var TRACKS = {
    // Semangat: C – G – Am – F, 132 BPM
    dashboard: {
      bpm: 132, lead: 'square', leadVol: 0.07, chords: [[48, 52, 55], [43, 47, 50], [45, 48, 52], [41, 45, 48]],
      melody: [
        [72, _, 76, _, 79, _, 76, _, 77, _, 76, _, 74, _, 72, _], [71, _, 74, _, 79, _, 74, _, 76, _, 74, _, 71, _, 67, _],
        [69, _, 72, _, 76, _, 72, _, 74, _, 76, _, 77, _, 76, _], [77, _, 76, _, 74, _, 72, _, 74, _, _, _, 72, _, _, _],
        [79, 79, _, 79, _, 76, _, 72, _, 74, _, 76, _, _, 72, _], [74, 74, _, 74, _, 71, _, 67, _, 69, _, 71, _, _, 74, _],
        [76, 76, _, 76, _, 72, _, 69, _, 71, _, 72, _, 74, _, _], [77, _, 76, _, 74, _, 72, _, 72, _, _, _, _, _, _, _]
      ],
      kick: [0, 8, 11], snare: [4, 12], hat: 2, stab: [2, 6, 10, 14], bass: 'octave'
    },
    // Petualangan santai untuk layar login: Am – F – C – G, 100 BPM
    login: {
      bpm: 100, lead: 'triangle', leadVol: 0.11, chords: [[45, 48, 52], [41, 45, 48], [48, 52, 55], [43, 47, 50]],
      melody: [
        [76, _, _, _, 74, _, 72, _, 71, _, _, _, 72, _, _, _], [69, _, _, _, _, _, 72, _, 74, _, _, _, 76, _, _, _],
        [79, _, _, _, 76, _, _, _, 74, _, 72, _, 74, _, _, _], [71, _, _, _, _, _, _, _, 74, _, _, _, 71, _, _, _]
      ],
      kick: [0, 10], snare: [8], hat: 4, arp: true, bass: 'root'
    },
    // Santai di dalam dunia 3D: F – G – Em – Am, 108 BPM (pelan agar tidak mengganggu voice chat)
    game: {
      bpm: 108, lead: 'triangle', leadVol: 0.07, chords: [[41, 45, 48], [43, 47, 50], [40, 43, 47], [45, 48, 52]],
      melody: [
        [72, _, _, _, 69, _, _, _, 72, _, 74, _, _, _, _, _], [74, _, _, _, 71, _, _, _, 67, _, _, _, _, _, _, _],
        [71, _, _, _, 67, _, 71, _, 76, _, _, _, 74, _, _, _], [72, _, _, _, _, _, _, _, 69, _, _, _, _, _, _, _]
      ],
      kick: [0], snare: [], hat: 8, arp: true, bass: 'root', quiet: true
    }
  };

  function schedule() {
    if (!ctx || !track) return;
    var T = TRACKS[track], sixteenth = 60 / T.bpm / 4;
    while (nextT < ctx.currentTime + 0.15) {
      var bar = Math.floor(step / 16), s = step % 16;
      var ch = T.chords[bar % T.chords.length], mel = T.melody[bar % T.melody.length];
      var t = nextT, q = T.quiet ? 0.6 : 1;
      if (mel[s] !== null && mel[s] !== undefined) tone(musicBus, T.lead, mel[s], t, sixteenth * 1.8, T.leadVol);
      if (s % 2 === 0) {
        var root = ch[0] - 12;
        var b = T.bass === 'octave' && (s / 2) % 2 === 1 ? root + 12 : root;
        if (T.bass === 'octave' || s % 4 === 0) tone(musicBus, 'triangle', b, t, sixteenth * 1.7, 0.16 * q);
      }
      if (T.arp) tone(musicBus, 'square', ch[s % 3] + 12 + (s % 8 >= 6 ? 12 : 0), t, sixteenth * 0.9, 0.022 * q);
      if (T.stab && T.stab.indexOf(s) >= 0) ch.forEach(function (n) { tone(musicBus, 'square', n + 12, t, sixteenth * 0.8, 0.018); });
      if (T.kick.indexOf(s) >= 0) kick(t, 0.5 * q);
      if (T.snare.indexOf(s) >= 0) noise(t, 0.12, 0.16 * q, 1500);
      if (T.hat && s % T.hat === 0) noise(t, 0.03, 0.05 * q, 7000);
      nextT += sixteenth;
      step++;
    }
  }
  // ---------- Lagu pilihan pemilik ----------
  var custom = {}, cfgLoaded = false, cust = null, custUrl = '';
  var cfgP = (function () {
    try { var c = JSON.parse(sessionStorage.getItem('vc_media_cfg') || 'null'); if (c && Date.now() - c.t < 120000) { custom = c.music || {}; cfgLoaded = true; return Promise.resolve(); } } catch (e) {}
    if (!window.fetch) { cfgLoaded = true; return Promise.resolve(); }
    return fetch('/api/media/config', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      custom = (d && d.music) || {};
      try { sessionStorage.setItem('vc_media_cfg', JSON.stringify({ t: Date.now(), music: custom })); } catch (e) {}
    }).catch(function () {}).then(function () { cfgLoaded = true; });
  })();
  function slotOf(name) { return name === 'dashboard' && location.pathname.indexOf('/admin') === 0 ? 'admin' : name; }
  function stopCustom() { if (cust) { try { cust.pause(); } catch (e) {} } }
  function playCustom(url, quiet) {
    if (!cust || custUrl !== url) {
      stopCustom();
      cust = new Audio(url); cust.loop = true; cust.preload = 'auto'; custUrl = url;
    }
    cust.volume = quiet ? 0.28 : 0.45;
    var p = cust.play(); if (p && p.catch) p.catch(function () {});
    // musik ambient bawaan Dunia Pixel ikut diam
    if (window.PixelAudio && window.PixelAudio.vol) { window.PixelAudio.vol.music = 0; if (window.PixelAudio.music) window.PixelAudio.music.gain.value = 0; }
  }
  function start(name) {
    wanted = name;
    if (!cfgLoaded) { cfgP.then(function () { if (wanted === name) start(name); }); return; }
    var lv = document.getElementById('pxLoginVideo');
    if (name === 'login' && lv && lv.hasAttribute('data-audio')) { stopLoop(); stopCustom(); return; } // suara dari video latar
    var cu = custom[slotOf(name)];
    if (cu) {
      if (!musicOn || !unlocked) return;
      stopLoop();
      playCustom(cu, name === 'game' || name === 'pixel');
      return;
    }
    stopCustom();
    if (!TRACKS[name]) return; // mis. "pixel" tanpa lagu pilihan → musik ambient Pixel bawaan
    if (!musicOn || !unlocked || !ensure()) return;
    if (ctx.state === 'suspended') ctx.resume();
    if (track === name && timer) return;
    stopLoop();
    track = name; step = 0; nextT = ctx.currentTime + 0.08;
    musicBus.gain.cancelScheduledValues(ctx.currentTime);
    musicBus.gain.setValueAtTime(0.0001, ctx.currentTime);
    musicBus.gain.exponentialRampToValueAtTime(TRACKS[name].quiet ? 0.22 : 0.32, ctx.currentTime + 1.2);
    timer = setInterval(schedule, 40);
    schedule();
  }
  function stopLoop() { if (timer) { clearInterval(timer); timer = null; } track = null; }

  // ---------- Suara UI ----------
  var lastHover = 0;
  function blip() {
    if (!sfxOn || !unlocked || !ensure()) return;
    var now = Date.now(); if (now - lastHover < 70) return; lastHover = now;
    tone(sfxBus, 'square', 84, ctx.currentTime, 0.05, 0.05);
  }
  function click() {
    if (!sfxOn || !ensure()) return;
    if (ctx.state === 'suspended') ctx.resume();
    var t = ctx.currentTime;
    tone(sfxBus, 'square', 76, t, 0.06, 0.08);
    tone(sfxBus, 'square', 83, t + 0.05, 0.08, 0.07);
  }
  window.VcSfx = { blip: blip, click: click };

  var SEL = 'button, a.px-btn, .px-btn, .pxd-btn, .pxd-icon, .pxd-extra, .btn, [role="button"], [role="tab"], input[type="submit"], input[type="button"], .chip, .au-tab, .vcm-toggle';
  function target(e) {
    var t = e.target && e.target.closest ? e.target.closest(SEL) : null;
    if (!t || t.disabled || t.hasAttribute('data-no-sfx')) return null;
    // Halaman game: hanya tombol di lobi / dialog, bukan kontrol HUD (joystick, lompat, dst.)
    if (document.body.classList.contains('vc-game') && !t.closest('#worldLobby, .lobby, [role="dialog"], .modal')) return null;
    return t;
  }
  document.addEventListener('mouseover', function (e) {
    var t = target(e);
    if (!t || (e.relatedTarget && t.contains(e.relatedTarget))) return;
    blip();
  }, true);
  document.addEventListener('click', function (e) { if (target(e)) click(); }, true);

  // ---------- Buka kunci audio pada gestur pertama ----------
  function unlock() {
    if (unlocked) return;
    unlocked = true;
    if (ensure() && ctx.state === 'suspended') ctx.resume();
    if (wanted) start(wanted);
    ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) { document.removeEventListener(ev, unlock, true); });
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) { document.addEventListener(ev, unlock, true); });
  document.addEventListener('visibilitychange', function () {
    if (cust && custUrl) { if (document.hidden) stopCustom(); else if (unlocked && musicOn && wanted && custom[slotOf(wanted)]) { var p = cust.play(); if (p && p.catch) p.catch(function () {}); } }
    if (!ctx) return;
    if (document.hidden) ctx.suspend(); else if (unlocked) ctx.resume();
  });

  // ---------- Tombol musik ----------
  function label() { return musicOn ? '🎵' : '🔇'; }
  function syncButtons() {
    var b = document.querySelectorAll('.vcm-toggle');
    for (var i = 0; i < b.length; i++) { b[i].textContent = label(); b[i].setAttribute('aria-pressed', String(musicOn)); b[i].title = musicOn ? 'Matikan musik' : 'Nyalakan musik'; }
  }
  function toggle() {
    musicOn = !musicOn; setPref('vc_music_on', musicOn);
    if (musicOn) { if (wanted) { stopLoop(); start(wanted); } }
    else { stopLoop(); stopCustom(); if (musicBus) musicBus.gain.value = 0; }
    syncButtons();
    return musicOn;
  }
  function addButton() {
    var host = document.querySelector('.pxd-icons');
    var b = document.createElement('button');
    b.type = 'button';
    b.className = host ? 'pxd-icon px-plain vcm-toggle' : 'vcm-toggle vcm-toggle--float';
    b.setAttribute('aria-label', 'Musik latar');
    b.addEventListener('click', toggle);
    if (host) host.insertBefore(b, host.children[1] || null); else document.body.appendChild(b);
    syncButtons();
  }

  window.VcMusic = {
    play: start,
    stop: function () { wanted = null; stopLoop(); },
    toggle: toggle,
    isOn: function () { return musicOn; },
    setSfx: function (on) { sfxOn = !!on; setPref('vc_sfx_on', sfxOn); },
    sfxOn: function () { return sfxOn; }
  };

  function auto() {
    var b = document.body;
    if (!b) return;
    var name = b.classList.contains('px-auth') ? 'login' : (document.getElementById('pxd') ? 'dashboard' : null);
    if (name) { addButton(); start(name); return; }
    // Dunia Pixel: hanya bila pemilik memasang lagu khusus (selain itu musik ambient bawaan engine)
    if (location.pathname.indexOf('/pixel') === 0) cfgP.then(function () { if (custom.pixel) start('pixel'); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto); else auto();
})();
