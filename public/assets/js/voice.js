/*
 * VoiceChat — voice chat ringan untuk ruang kelas 3D (WebRTC mesh, audio saja).
 *
 * - Satu RTCPeerConnection per pemain lain di room yang sama (WS memfilter per serverId).
 * - Koneksi dibuat sejak awal dengan transceiver audio sendrecv tanpa track, jadi
 *   kamu tetap bisa MENDENGAR walau mikrofonmu mati. Menyalakan mic hanya
 *   replaceTrack() → tidak perlu negosiasi ulang.
 * - Yang id-nya lebih kecil membuat offer (hindari tabrakan offer/glare).
 * - Signaling lewat pesan WS 'voice_signal' {targetId, signal:{sdp}|{candidate}}.
 * - Indikator bicara: AnalyserNode per stream → onSpeaking(id, bool).
 * - Cadangan RELAY: tanpa server TURN banyak pasangan (Wi-Fi sekolah 1 IP NAT, data seluler)
 *   gagal tersambung P2P. Selama ada pemain yang belum 'connected', mic juga dikirim lewat WS
 *   ('voice_pcm': 8 kHz µ-law, ±120 ms/paket). Penerima memutar relay HANYA dari pengirim yang
 *   jalur WebRTC-nya belum tersambung → tidak terdengar dobel.
 */
(function () {
  'use strict';
  var ICE = [{ urls: 'stun:stun.l.google.com:19302' }];
  var MAX_PEERS = 40;

  var selfId = 0;
  var send = null;            // function(obj) → kirim lewat WS
  var peers = new Map();      // id → { pc, sender, audio, meter }
  var micStream = null;
  var micOn = false;
  var actx = null;
  var selfMeter = null;
  var relay = null;           // { proc, src } — penangkap PCM untuk cadangan WS
  var playheads = {};         // fromId → waktu AudioContext antrean putar berikutnya
  var relayTalk = {};         // fromId → timer indikator bicara via relay

  var VoiceChat = {
    onSpeaking: null,          // function(id, isSpeaking) — id === selfId untuk diri sendiri
    onError: null,             // function(message)
    get micOn() { return micOn; },

    init: function (sendFn) { send = sendFn; },

    /** Panggil dengan setiap pesan WS yang sudah di-parse. */
    handleWs: function (data) {
      if (!data || !data.type) return;
      switch (data.type) {
        case 'init':
          selfId = Number(data.self && data.self.id) || 0;
          closeAll();
          (data.players || []).forEach(function (p) { ensurePeer(Number(p.id)); });
          break;
        case 'player_join':
          if (data.player) ensurePeer(Number(data.player.id));
          break;
        case 'player_leave':
          closePeer(Number(data.id));
          break;
        case 'voice_signal':
          onSignal(Number(data.fromId), data.signal || {});
          break;
        case 'voice_pcm':
          onRelay(Number(data.fromId), data.d);
          break;
      }
    },

    /** Nyalakan / matikan mikrofon. Mengembalikan Promise<boolean> status akhir. */
    setMic: function (on) {
      if (!on) {
        micOn = false;
        peers.forEach(function (p) { if (p.sender) p.sender.replaceTrack(null).catch(noop); });
        if (micStream) micStream.getTracks().forEach(function (t) { t.enabled = false; });
        return Promise.resolve(false);
      }
      return getMic().then(function (stream) {
        var track = stream.getAudioTracks()[0];
        track.enabled = true;
        micOn = true;
        peers.forEach(function (p) { if (p.sender) p.sender.replaceTrack(track).catch(noop); });
        startRelay(stream);
        return true;
      }).catch(function (e) {
        micOn = false;
        var n = e && e.name;
        if (n === 'InsecureContext' && /^http:$/.test(location.protocol)) {
          report('Mikrofon butuh alamat aman (https). Membuka versi https…');
          setTimeout(function () { location.href = 'https://' + location.host + location.pathname + location.search; }, 1500);
        } else {
          report(n === 'NotAllowedError' || n === 'SecurityError'
            ? 'Izin mikrofon ditolak. Ketuk ikon gembok di bilah alamat → Izinkan Mikrofon, lalu coba lagi.'
            : n === 'NotFoundError' ? 'Mikrofon tidak ditemukan di perangkat ini.'
            : n === 'NotReadableError' ? 'Mikrofon sedang dipakai aplikasi lain (tutup WA/rekaman), lalu coba lagi.'
            : 'Mikrofon tidak bisa dipakai di perangkat/browser ini.');
        }
        return false;
      });
    },

    /** Diagnosis: status koneksi suara per pemain (dipakai tes & debugging di HP). */
    stats: function () {
      var out = [];
      peers.forEach(function (p, id) {
        out.push({ id: id, state: p.pc.connectionState, ice: p.pc.iceConnectionState,
          hearing: !!(p.audio && p.audio.srcObject), sending: !!(p.sender && p.sender.track), p2p: isLinked(p) });
      });
      return out;
    },

    /** Panggil saat ada gesture pengguna agar audio peer boleh diputar (iOS/Chrome autoplay). */
    unlockAudio: function () {
      ensureCtx();
      peers.forEach(function (p) { if (p.audio) p.audio.play().catch(noop); });
    }
  };

  function noop() {}
  function report(msg) { if (typeof VoiceChat.onError === 'function') VoiceChat.onError(msg); }

  function ensureCtx() {
    if (!actx) {
      var C = window.AudioContext || window.webkitAudioContext;
      if (C) { try { actx = new C(); } catch (e) { actx = null; } }
    }
    if (actx && actx.state === 'suspended') actx.resume().catch(noop);
    return actx;
  }

  function getMic() {
    if (micStream) return Promise.resolve(micStream);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      // Halaman http:// bukan "secure context" → browser menyembunyikan mikrofon.
      return Promise.reject({ name: window.isSecureContext === false ? 'InsecureContext' : 'Unsupported' });
    }
    return navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      video: false
    }).then(function (s) {
      micStream = s;
      selfMeter = makeMeter(s, selfId, function () { return micOn; });
      return s;
    });
  }

  function sig(targetId, signal) {
    if (send) send({ type: 'voice_signal', targetId: targetId, signal: signal });
  }

  function ensurePeer(id) {
    if (!id || id === selfId || peers.has(id)) return peers.get(id);
    if (peers.size >= MAX_PEERS || typeof RTCPeerConnection === 'undefined') return null;

    var pc = new RTCPeerConnection({ iceServers: ICE });
    var rec = { pc: pc, sender: null, audio: null, meter: null };
    peers.set(id, rec);

    pc.onicecandidate = function (ev) { if (ev.candidate) sig(id, { candidate: ev.candidate }); };
    pc.ontrack = function (ev) {
      var stream = ev.streams && ev.streams[0] ? ev.streams[0] : new MediaStream([ev.track]);
      if (!rec.audio) {
        rec.audio = new Audio();
        rec.audio.autoplay = true;
        rec.audio.setAttribute('playsinline', '');
      }
      rec.audio.srcObject = stream;
      rec.audio.play().catch(noop); // diblokir autoplay → dicoba lagi di unlockAudio()
      if (rec.meter) rec.meter.stop();
      rec.meter = makeMeter(stream, id, null);
    };
    pc.onconnectionstatechange = function () {
      if (pc.connectionState === 'failed') closePeer(id);
    };

    if (selfId < id) {
      // Kita pemrakarsa: tambah transceiver audio dua arah lalu kirim offer.
      var tr = pc.addTransceiver('audio', { direction: 'sendrecv' });
      rec.sender = tr.sender;
      attachMic(rec);
      pc.createOffer()
        .then(function (o) { return pc.setLocalDescription(o); })
        .then(function () { sig(id, { sdp: pc.localDescription }); })
        .catch(function () { closePeer(id); });
    }
    return rec;
  }

  function attachMic(rec) {
    if (micOn && micStream && rec.sender) rec.sender.replaceTrack(micStream.getAudioTracks()[0]).catch(noop);
  }

  function onSignal(fromId, signal) {
    if (!fromId || fromId === selfId) return;
    var rec = peers.get(fromId) || ensurePeer(fromId);
    if (!rec) return;
    var pc = rec.pc;

    if (signal.sdp) {
      var desc = signal.sdp;
      pc.setRemoteDescription(desc).then(function () {
        if (desc.type !== 'offer') return;
        // Penjawab: pakai transceiver dari offer, jadikan dua arah agar mic kita bisa terkirim.
        var tr = pc.getTransceivers().find(function (t) { return t.receiver.track && t.receiver.track.kind === 'audio'; });
        if (tr) { tr.direction = 'sendrecv'; rec.sender = tr.sender; attachMic(rec); }
        return pc.createAnswer()
          .then(function (a) { return pc.setLocalDescription(a); })
          .then(function () { sig(fromId, { sdp: pc.localDescription }); });
      }).catch(noop);
    } else if (signal.candidate) {
      pc.addIceCandidate(signal.candidate).catch(noop);
    }
  }

  function isLinked(rec) {
    if (!rec || !rec.pc) return false;
    var c = rec.pc.connectionState, i = rec.pc.iceConnectionState;
    return (c === 'connected' || (!c && (i === 'connected' || i === 'completed'))) && !!(rec.audio && rec.audio.srcObject);
  }
  function relayNeeded() {
    var need = false;
    peers.forEach(function (p) { if (!isLinked(p)) need = true; });
    return need;
  }

  // ---- Cadangan RELAY lewat WS ----
  var MU = new Int16Array(256);
  (function () { for (var i = 0; i < 256; i++) { var u = ~i & 255, t = ((u & 15) << 3) + 132; t <<= (u & 112) >> 4; MU[i] = (u & 128) ? 132 - t : t - 132; } })();
  function muEnc(x) {
    var s = Math.max(-32635, Math.min(32635, Math.round(x * 32767)));
    var sign = s < 0 ? 128 : 0; if (sign) s = -s;
    s += 132;
    var exp = 7; for (var m = 0x4000; (s & m) === 0 && exp > 0; m >>= 1) exp--;
    return ~(sign | (exp << 4) | ((s >> (exp + 3)) & 15)) & 255;
  }
  function startRelay(stream) {
    var ctx = ensureCtx();
    if (relay || !ctx || !ctx.createScriptProcessor) return;
    try {
      var src = ctx.createMediaStreamSource(stream);
      var proc = ctx.createScriptProcessor(4096, 1, 1);
      var ratio = ctx.sampleRate / 8000, acc = 0, out = [];
      var mute = ctx.createGain(); mute.gain.value = 0; // proses jalan tanpa memutar mic sendiri
      proc.onaudioprocess = function (ev) {
        if (!micOn || !send || !relayNeeded()) { out.length = 0; return; }
        var inp = ev.inputBuffer.getChannelData(0);
        for (var i = 0; i < inp.length; i++) {
          acc += 1;
          if (acc >= ratio) { acc -= ratio; out.push(muEnc(inp[i])); }
        }
        while (out.length >= 960) {
          var chunk = out.splice(0, 960), bin = '';
          for (var k = 0; k < chunk.length; k++) bin += String.fromCharCode(chunk[k]);
          send({ type: 'voice_pcm', d: btoa(bin) });
        }
      };
      src.connect(proc); proc.connect(mute); mute.connect(ctx.destination);
      relay = { proc: proc, src: src };
    } catch (e) { relay = null; }
  }
  function onRelay(fromId, b64) {
    if (!fromId || fromId === selfId || typeof b64 !== 'string') return;
    if (isLinked(peers.get(fromId))) return; // sudah terdengar lewat WebRTC
    var ctx = ensureCtx();
    if (!ctx) return;
    var bin;
    try { bin = atob(b64); } catch (e) { return; }
    var n = bin.length, buf = ctx.createBuffer(1, n, 8000), ch = buf.getChannelData(0);
    for (var i = 0; i < n; i++) ch[i] = MU[bin.charCodeAt(i) & 255] / 32768;
    var node = ctx.createBufferSource();
    node.buffer = buf;
    node.connect(ctx.destination);
    var now = ctx.currentTime, at = playheads[fromId] || 0;
    if (at < now + 0.05 || at > now + 1.2) at = now + 0.18; // buffer jitter ±180 ms, buang antrean basi
    node.start(at);
    playheads[fromId] = at + buf.duration;
    if (typeof VoiceChat.onSpeaking === 'function') {
      if (!relayTalk[fromId]) VoiceChat.onSpeaking(fromId, true);
      clearTimeout(relayTalk[fromId]);
      relayTalk[fromId] = setTimeout(function () { relayTalk[fromId] = 0; VoiceChat.onSpeaking(fromId, false); }, 450);
    }
  }

  function closePeer(id) {
    var rec = peers.get(id);
    if (!rec) return;
    peers.delete(id);
    if (rec.meter) rec.meter.stop();
    if (rec.audio) { rec.audio.srcObject = null; }
    try { rec.pc.close(); } catch (e) {}
    if (typeof VoiceChat.onSpeaking === 'function') VoiceChat.onSpeaking(id, false);
  }

  function closeAll() { Array.from(peers.keys()).forEach(closePeer); }

  /** Pendeteksi bicara sederhana: RMS dari AnalyserNode, dicek 8x per detik. */
  function makeMeter(stream, id, gate) {
    var ctx = ensureCtx();
    if (!ctx) return { stop: noop };
    var an, src;
    try {
      src = ctx.createMediaStreamSource(stream);
      an = ctx.createAnalyser();
      an.fftSize = 512;
      src.connect(an);
    } catch (e) { return { stop: noop }; }
    var buf = new Uint8Array(an.fftSize);
    var speaking = false, quietTicks = 0;
    var timer = setInterval(function () {
      var level = 0;
      if (!gate || gate()) {
        an.getByteTimeDomainData(buf);
        var sum = 0;
        for (var i = 0; i < buf.length; i++) { var v = (buf[i] - 128) / 128; sum += v * v; }
        level = Math.sqrt(sum / buf.length);
      }
      var now = level > 0.04;
      if (now) quietTicks = 0; else quietTicks++;
      var next = now || (speaking && quietTicks < 4); // tahan ~0.5 dtk agar tidak berkedip
      if (next !== speaking) {
        speaking = next;
        if (typeof VoiceChat.onSpeaking === 'function') VoiceChat.onSpeaking(id || selfId, speaking);
      }
    }, 125);
    return { stop: function () { clearInterval(timer); try { src.disconnect(); } catch (e) {} } };
  }

  window.VoiceChat = VoiceChat;
})();
