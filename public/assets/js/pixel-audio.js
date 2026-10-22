/**
 * Virtual City SMK Silber — Pixel Audio (WebAudio prosedural, tanpa file audio).
 * Volume master/musik/SFX terpisah, mute, batas pemutaran simultan, unlock gesture pertama.
 */
const PixelAudio = {
  ctx: null, master: null, music: null, sfxBus: null, // sfxBus: node volume efek (dulu "sfx" menimpa method sfx())
  muted: false, vol: { master: 0.8, music: 0.4, sfx: 0.7 },
  active: 0, musicTimer: null, birdTimer: null, started: false,

  init() {
    try {
      const s = localStorage.getItem('pixAudio');
      if (s) { const d = JSON.parse(s); this.vol = Object.assign(this.vol, d.vol || {}); this.muted = !!d.muted; }
    } catch (e) { /* abaikan */ }
    const unlock = () => {
      if (this.started) return;
      this.started = true;
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      this.ctx = new AC();
      this.master = this.ctx.createGain();
      this.music = this.ctx.createGain();
      this.sfxBus = this.ctx.createGain();
      this.music.connect(this.master); this.sfxBus.connect(this.master);
      this.master.connect(this.ctx.destination);
      this.applyVol();
      this.startAmbient();
    };
    window.addEventListener('pointerdown', unlock, { once: true });
    window.addEventListener('keydown', unlock, { once: true });
  },

  applyVol() {
    if (!this.ctx) return;
    const m = this.muted ? 0 : this.vol.master;
    this.master.gain.value = m;
    this.music.gain.value = this.vol.music;
    this.sfxBus.gain.value = this.vol.sfx;
  },

  save() {
    try { localStorage.setItem('pixAudio', JSON.stringify({ vol: this.vol, muted: this.muted })); } catch (e) { /* abaikan */ }
  },

  setVolume(kind, v) {
    this.vol[kind] = Math.max(0, Math.min(1, v));
    this.applyVol(); this.save();
  },

  toggleMute() {
    this.muted = !this.muted;
    this.applyVol(); this.save();
    return this.muted;
  },

  sfx(name, opt) {
    if (!this.ctx || this.muted || this.active > 8) return;
    this.active++;
    setTimeout(() => { this.active--; }, 300);
    const t = this.ctx.currentTime;
    const g = this.ctx.createGain();
    g.connect(this.sfxBus);
    const beep = (freq, dur, type, vol, delay, slide) => {
      delay = delay || 0; // tanpa ini t + undefined = NaN → setValueAtTime melempar error
      const o = this.ctx.createOscillator();
      const gg = this.ctx.createGain();
      o.type = type || 'square';
      o.frequency.setValueAtTime(freq, t + delay);
      if (slide) o.frequency.exponentialRampToValueAtTime(slide, t + delay + dur);
      gg.gain.setValueAtTime(vol || 0.12, t + delay);
      gg.gain.exponentialRampToValueAtTime(0.001, t + delay + dur);
      o.connect(gg); gg.connect(g);
      o.start(t + delay); o.stop(t + delay + dur + 0.02);
    };
    const noise = (dur, filterHz, vol, delay) => {
      const len = Math.floor(this.ctx.sampleRate * dur);
      const buf = this.ctx.createBuffer(1, len, this.ctx.sampleRate);
      const data = buf.getChannelData(0);
      for (let i = 0; i < len; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / len);
      const src = this.ctx.createBufferSource();
      src.buffer = buf;
      const f = this.ctx.createBiquadFilter();
      f.type = 'lowpass'; f.frequency.value = filterHz;
      const gg = this.ctx.createGain();
      gg.gain.value = vol || 0.15;
      src.connect(f); f.connect(gg); gg.connect(g);
      src.start(t + (delay || 0));
    };
    switch (name) {
      case 'step': {
        const surf = opt || 'path';
        if (surf === 'grass') noise(0.07, 420, 0.08);
        else if (surf === 'court') { noise(0.05, 1600, 0.1); beep(180, 0.04, 'triangle', 0.05, 0); }
        else noise(0.05, 900, 0.1);
        break;
      }
      case 'click': beep(660, 0.06, 'square', 0.08); break;
      case 'pop': beep(520, 0.08, 'triangle', 0.1, 0, 780); break;
      case 'dialog': beep(880, 0.05, 'sine', 0.08); beep(660, 0.05, 'sine', 0.08, 0.06); break;
      case 'door': noise(0.18, 600, 0.16); beep(140, 0.1, 'triangle', 0.1, 0.14); break;
      case 'pickup': beep(660, 0.09, 'square', 0.1); beep(990, 0.12, 'square', 0.1, 0.08); break;
      case 'mission': beep(523, 0.12, 'square', 0.11); beep(659, 0.12, 'square', 0.11, 0.1); beep(784, 0.12, 'square', 0.11, 0.2); beep(1047, 0.22, 'square', 0.12, 0.3); break;
      case 'error': beep(220, 0.16, 'sawtooth', 0.09, 0, 160); break;
      case 'chat': beep(740, 0.06, 'sine', 0.07); break;
      case 'join': beep(440, 0.08, 'sine', 0.07); beep(660, 0.1, 'sine', 0.07, 0.07); break;
      case 'leave': beep(500, 0.09, 'sine', 0.06, 0, 320); break;
    }
  },

  startAmbient() {
    const chordLoop = () => {
      if (this.muted || !this.ctx) { this.musicTimer = setTimeout(chordLoop, 8000); return; }
      const t = this.ctx.currentTime;
      const chords = [[261.6, 329.6, 392], [220, 277.2, 329.6], [196, 246.9, 293.7], [233.1, 293.7, 349.2]];
      const ch = chords[Math.floor(Math.random() * chords.length)];
      for (const freq of ch) {
        const o = this.ctx.createOscillator();
        const g = this.ctx.createGain();
        o.type = 'triangle';
        o.frequency.value = freq;
        g.gain.setValueAtTime(0, t);
        g.gain.linearRampToValueAtTime(0.028, t + 1.2);
        g.gain.linearRampToValueAtTime(0, t + 7);
        o.connect(g); g.connect(this.music);
        o.start(t); o.stop(t + 7.2);
      }
      this.musicTimer = setTimeout(chordLoop, 8000);
    };
    chordLoop();
    const birds = () => {
      if (this.ctx && !this.muted) {
        const n = 1 + Math.floor(Math.random() * 3);
        for (let i = 0; i < n; i++) {
          const t = this.ctx.currentTime + i * 0.3;
          const o = this.ctx.createOscillator();
          const g = this.ctx.createGain();
          o.type = 'sine';
          const base = 1800 + Math.random() * 1400;
          o.frequency.setValueAtTime(base, t);
          o.frequency.exponentialRampToValueAtTime(base * 1.5, t + 0.08);
          o.frequency.exponentialRampToValueAtTime(base * 0.9, t + 0.16);
          g.gain.setValueAtTime(0.015, t);
          g.gain.exponentialRampToValueAtTime(0.001, t + 0.2);
          o.connect(g); g.connect(this.music);
          o.start(t); o.stop(t + 0.25);
        }
      }
      this.birdTimer = setTimeout(birds, 5000 + Math.random() * 9000);
    };
    birds();
  }
};

PixelAudio.init();
window.PixelAudio = PixelAudio;
