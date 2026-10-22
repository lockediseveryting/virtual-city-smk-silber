/**
 * Virtual City SMK Silber v5 — Game Audio manager (Phase 5)
 * Area music loops, UI SFX, master/SFX/music sliders, mute, and
 * autoplay-policy safety (everything starts only after the first user gesture).
 * Uses the Web Audio API with synthesized tones — no external audio files required.
 * Exposes window.gameAudio.
 */
(function () {
  'use strict';

  const STORAGE = { master: 'vc_vol_master', sfx: 'vc_vol_sfx', music: 'vc_vol_music', muted: 'vc_muted' };

  class GameAudio {
    constructor() {
      this.ctx = null;
      this.unlocked = false;
      this.master = parseFloat(localStorage.getItem(STORAGE.master) || '0.8');
      this.sfxVol = parseFloat(localStorage.getItem(STORAGE.sfx) || '0.9');
      this.musicVol = parseFloat(localStorage.getItem(STORAGE.music) || '0.5');
      this.muted = localStorage.getItem(STORAGE.muted) === '1';
      this.musicTimer = null;
      this.currentArea = null;
    }

    /* The AudioContext may only start after a user gesture (iOS/Chrome policy). */
    attachGestureUnlock() {
      if (this.unlocked) return;
      const unlock = () => {
        try {
          this._ctx();
          if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume();
          this.unlocked = true;
        } catch (e) { /* ignore */ }
        window.removeEventListener('pointerdown', unlock);
        window.removeEventListener('keydown', unlock);
        window.removeEventListener('touchstart', unlock);
      };
      window.addEventListener('pointerdown', unlock, { once: false });
      window.addEventListener('keydown', unlock, { once: false });
      window.addEventListener('touchstart', unlock, { once: false });
    }

    _ctx() {
      if (!this.ctx) {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return null;
        this.ctx = new Ctx();
      }
      return this.ctx;
    }

    setMaster(v) { this.master = Number(v); localStorage.setItem(STORAGE.master, String(this.master)); }
    setSfx(v) { this.sfxVol = Number(v); localStorage.setItem(STORAGE.sfx, String(this.sfxVol)); }
    setMusic(v) {
      this.musicVol = Number(v);
      localStorage.setItem(STORAGE.music, String(this.musicVol));
      if (this.currentArea) this.playAreaMusic(this.currentArea, true);
    }
    setMuted(m) { this.muted = !!m; localStorage.setItem(STORAGE.muted, this.muted ? '1' : '0'); if (this.muted) this.stopMusic(); }

    /* UI SFX: short synthesized blips. */
    sfx(name = 'click') {
      if (this.muted || this.sfxVol <= 0) return;
      const ctx = this._ctx();
      if (!ctx) return;
      const gain = ctx.createGain();
      gain.gain.value = this.master * this.sfxVol;
      gain.connect(ctx.destination);
      const tone = (freq, dur, type = 'sine', delay = 0) => {
        const osc = ctx.createOscillator();
        const g = ctx.createGain();
        osc.type = type;
        osc.frequency.setValueAtTime(freq, ctx.currentTime + delay);
        g.gain.setValueAtTime(0.22, ctx.currentTime + delay);
        g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + delay + dur);
        osc.connect(g); g.connect(gain);
        osc.start(ctx.currentTime + delay);
        osc.stop(ctx.currentTime + delay + dur);
      };
      switch (name) {
        case 'click': tone(620, 0.06); break;
        case 'success': tone(660, 0.09); tone(880, 0.12, 'sine', 0.08); break;
        case 'error': tone(200, 0.16, 'square'); break;
        case 'coin': tone(988, 0.06); tone(1319, 0.09, 'sine', 0.05); break;
        case 'levelup': [523, 659, 784, 1046].forEach((f, i) => tone(f, 0.22, 'triangle', i * 0.1)); break;
        case 'whoosh': tone(320, 0.18, 'sawtooth'); break;
        default: tone(520, 0.07);
      }
    }

    /* Very light area music: a soft repeating arpeggio, adapts per area. */
    playAreaMusic(areaName, restart = false) {
      if (this.muted || this.musicVol <= 0) return;
      if (!restart && this.currentArea === areaName && this.musicTimer) return;
      this.stopMusic();
      this.currentArea = areaName;
      const ctx = this._ctx();
      if (!ctx) return;
      const scales = {
        luar: [261.6, 329.6, 392.0, 523.3],
        kelas: [293.7, 349.2, 440.0, 587.3],
        lab: [220.0, 277.2, 329.6, 440.0],
        kantin: [329.6, 392.0, 493.9, 659.3],
        default: [261.6, 329.6, 392.0, 523.3]
      };
      const notes = scales[areaName] || scales.default;
      let i = 0;
      this.musicTimer = setInterval(() => {
        if (this.muted || this.musicVol <= 0 || ctx.state !== 'running') return;
        const osc = ctx.createOscillator();
        const g = ctx.createGain();
        osc.type = 'sine';
        const f = notes[i % notes.length];
        osc.frequency.setValueAtTime(f, ctx.currentTime);
        g.gain.setValueAtTime(0.0001, ctx.currentTime);
        g.gain.exponentialRampToValueAtTime(0.06 * this.master * this.musicVol, ctx.currentTime + 0.15);
        g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 1.4);
        osc.connect(g); g.connect(ctx.destination);
        osc.start(); osc.stop(ctx.currentTime + 1.5);
        i++;
      }, 1500);
    }

    stopMusic() {
      if (this.musicTimer) { clearInterval(this.musicTimer); this.musicTimer = null; }
      this.currentArea = null;
    }
  }

  window.gameAudio = new GameAudio();
})();
