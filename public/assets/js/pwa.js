/**
 * Virtual City SMK Silber - PWA Manager & Install Banner Experience
 * Handles Service Worker registration, custom install prompt (Android/iOS), updates, and game SFX.
 */
class PWAManager {
  constructor() {
    this.deferredPrompt = null;
    this.isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    this.audioCtx = null;
    this.soundEnabled = true;
  }

  init() {
    this.registerServiceWorker();
    this.setupInstallPrompt();
    this.createInstallBannerUI();
    this.bindInstallButtons();
    this.setupNetworkStatusListener();
    this.setupPushNotifications();
  }

  bindInstallButtons() {
    if (this.isStandalone) {
      document.querySelectorAll('[data-pwa-install], .btn-pwa-install, .topbar__install-btn').forEach(b => {
        b.style.display = 'none';
      });
      return;
    }
    document.querySelectorAll('[data-pwa-install], .btn-pwa-install').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        this.promptInstall();
      });
    });
  }

  registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    window.addEventListener('load', async () => {
      try {
        const reg = await navigator.serviceWorker.register('/service-worker.js');

        reg.addEventListener('updatefound', () => {
          const newWorker = reg.installing;
          if (!newWorker) return;
          newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              this.showUpdateToast(newWorker);
            }
          });
        });
      } catch (err) {
        console.warn('PWA: ServiceWorker registration error:', err);
      }
    });

    // Reload hanya saat SW lama diganti (update). Pada kunjungan pertama SW baru
    // memanggil clients.claim() → controllerchange juga terpicu; reload di sana
    // menghapus isian form (mis. kode room di /gabung) yang sedang diketik.
    const hadController = !!navigator.serviceWorker.controller;
    let refreshing = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (hadController && !refreshing) {
        refreshing = true;
        window.location.reload();
      }
    });
  }

  showUpdateToast(worker) {
    const toast = document.createElement('div');
    toast.style.cssText = `
      position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
      background: #131a29; border: 2px solid #22d3ee; border-radius: 12px;
      padding: 14px 20px; z-index: 9999; display: flex; align-items: center; gap: 14px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.8); color: #fff; font-size: 0.9rem;
    `;
    toast.innerHTML = `
      <span>🔄 Versi baru tersedia!</span>
      <button id="btnReloadPWA" style="background:#22d3ee;color:#000;border:none;padding:6px 14px;border-radius:6px;font-weight:bold;cursor:pointer">Muat Ulang</button>
    `;
    document.body.appendChild(toast);
    document.getElementById('btnReloadPWA').onclick = () => {
      worker.postMessage({ type: 'SKIP_WAITING' });
      toast.remove();
    };
  }

  isGamePage() {
    return window.location.pathname === '/dunia' || !!document.getElementById('viewport3D');
  }

  setupInstallPrompt() {
    // Android / Desktop Chrome & Edge
    window.addEventListener('beforeinstallprompt', e => {
      e.preventDefault();
      this.deferredPrompt = e;
      // Do not show automatically on game page or if recently dismissed
      if (this.isGamePage()) return;
      const lastDismissed = localStorage.getItem('pwa_dismissed_at');
      if (lastDismissed && Date.now() - parseInt(lastDismissed) < 3 * 86400 * 1000) {
        return;
      }
      this.showInstallBanner();
    });

    // App installed event
    window.addEventListener('appinstalled', () => {
      this.deferredPrompt = null;
      this.hideInstallBanner();
      this.playSfx('fanfare');
      if (window.UI) UI.toast('🎉 Virtual City SMK Silber berhasil dipasang di layar utama!', 'success', 3000);
      else alert('🎉 Selamat! Virtual City SMK Silber berhasil dipasang di layar utama perangkat Anda.');
    });

    // Popup "Pasang Aplikasi" — tampil otomatis beberapa detik setelah buka web,
    // TIDAK tampil di halaman game 3D agar kontrol tidak tertutup.
    setTimeout(() => {
      try {
        if (this.isStandalone || this.isGamePage()) return;
        const lastDismissed = localStorage.getItem('pwa_dismissed_at');
        if (lastDismissed && Date.now() - parseInt(lastDismissed) < 3 * 86400 * 1000) return;
        this.showInstallBanner();
      } catch (e) {
        if (!this.isGamePage()) this.showInstallBanner();
      }
    }, 4000);
  }

  createInstallBannerUI() {
    if (this.isStandalone || this.isGamePage()) return;
    if (document.getElementById('pwaInstallBanner')) return;

    const banner = document.createElement('div');
    banner.id = 'pwaInstallBanner';
    
    // Position banner above mobile tab bar so it never blocks navigation buttons
    const hasTabbar = !!document.querySelector('.tabbar');
    const bottomOffset = hasTabbar ? 'calc(58px + env(safe-area-inset-bottom, 0px))' : '0px';

    banner.style.cssText = `
      position: fixed; bottom: ${bottomOffset}; left: 0; right: 0;
      background: rgba(19, 26, 41, 0.96); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
      border-top: 2px solid #22d3ee; padding: 12px 18px;
      display: none; justify-content: space-between; align-items: center; gap: 10px;
      z-index: 85; box-shadow: 0 -8px 24px rgba(0,0,0,0.6);
      font-family: system-ui, sans-serif; pointer-events: auto; touch-action: manipulation;
    `;

    const isIos = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());

    if (isIos) {
      banner.innerHTML = `
        <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
          <img src="/assets/img/logo-silber.jpg" width="40" height="40" style="border-radius:10px;flex-shrink:0" alt="SMK Silber">
          <div style="min-width:0">
            <div style="font-weight:bold;color:#fff;font-size:0.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Pasang Aplikasi (iOS)</div>
            <div style="font-size:0.75rem;color:#94a3b8">Ketuk Bagikan ⎋ lalu "Tambah ke Layar Utama" ⊞</div>
          </div>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
          <button type="button" onclick="pwa.promptInstall()" style="background:#22d3ee;color:#000;border:none;padding:6px 12px;border-radius:6px;font-weight:bold;cursor:pointer;font-size:0.8rem">Panduan</button>
          <button type="button" onclick="pwa.dismissBanner()" style="background:rgba(255,255,255,0.12);color:#fff;border:none;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:0.8rem">✕</button>
        </div>
      `;
    } else {
      banner.innerHTML = `
        <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
          <img src="/assets/img/logo-silber.jpg" width="40" height="40" style="border-radius:10px;flex-shrink:0" alt="SMK Silber">
          <div style="min-width:0">
            <div style="font-weight:bold;color:#fff;font-size:0.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Pasang Virtual City</div>
            <div style="font-size:0.75rem;color:#94a3b8">Akses cepat, hemat kuota & layar penuh</div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
          <button type="button" onclick="pwa.dismissBanner()" style="background:transparent;color:#94a3b8;border:1px solid #334155;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:0.8rem">Nanti</button>
          <button type="button" onclick="pwa.promptInstall()" style="background:#22d3ee;color:#000;border:none;padding:6px 14px;border-radius:6px;font-weight:bold;cursor:pointer;font-size:0.8rem">Pasang</button>
        </div>
      `;
    }

    document.body.appendChild(banner);
  }

  showInstallBanner() {
    if (this.isGamePage()) return;
    const el = document.getElementById('pwaInstallBanner');
    if (el) el.style.display = 'flex';
  }

  hideInstallBanner() {
    const el = document.getElementById('pwaInstallBanner');
    if (el) el.style.display = 'none';
  }

  dismissBanner() {
    localStorage.setItem('pwa_dismissed_at', Date.now().toString());
    this.hideInstallBanner();
  }

  async promptInstall() {
    this.playSfx('click');
    if (this.isStandalone) {
      if (window.UI) UI.toast('Aplikasi Virtual City sudah terpasang dan aktif di perangkat Anda! 🚀', 'info', 3500);
      else alert('Aplikasi Virtual City sudah terpasang dan aktif di perangkat Anda! 🚀');
      return;
    }
    if (this.deferredPrompt) {
      try {
        this.deferredPrompt.prompt();
        const { outcome } = await this.deferredPrompt.userChoice;
        if (outcome === 'accepted') {
          this.playSfx('fanfare');
          if (window.UI) UI.toast('🎉 Memasang aplikasi Virtual City ke layar utama...', 'success', 3000);
        }
      } catch (e) {
        console.warn('Install prompt error:', e);
      }
      this.deferredPrompt = null;
      this.hideInstallBanner();
      return;
    }

    // Fallback: Show friendly interactive guide for browsers where deferredPrompt isn't available
    this.showInstallGuideModal();
  }

  showInstallGuideModal() {
    let modal = document.getElementById('pwaGuideModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'pwaGuideModal';
      modal.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        z-index: 10005; display: flex; align-items: center; justify-content: center; padding: 16px;
      `;
      document.body.appendChild(modal);
    }

    const isIos = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
    const isAndroid = /android/.test(window.navigator.userAgent.toLowerCase());

    let guideHtml = '';
    if (isIos) {
      guideHtml = `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
          <img src="/assets/img/logo-silber.jpg" width="48" height="48" style="border-radius:12px" alt="SMK Silber">
          <div>
            <h3 style="margin:0;font-size:1.1rem;color:#fff">Pasang di iPhone / iPad</h3>
            <span style="font-size:0.75rem;color:#22d3ee">Safari Browser</span>
          </div>
        </div>
        <ol style="margin:0 0 16px;padding-left:20px;color:#cbd5e1;line-height:1.8;font-size:0.9rem">
          <li>Ketuk ikon <b>Bagikan (Share) ⎋</b> di bilah bawah browser Safari.</li>
          <li>Gulir ke bawah dan pilih <b>"Tambahkan ke Layar Utama" ⊞</b>.</li>
          <li>Ketuk <b>"Tambah"</b> di sudut kanan atas layar.</li>
        </ol>
      `;
    } else if (isAndroid) {
      guideHtml = `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
          <img src="/assets/img/logo-silber.jpg" width="48" height="48" style="border-radius:12px" alt="SMK Silber">
          <div>
            <h3 style="margin:0;font-size:1.1rem;color:#fff">Pasang di HP Android</h3>
            <span style="font-size:0.75rem;color:#22d3ee">Chrome / Browser HP</span>
          </div>
        </div>
        <ol style="margin:0 0 16px;padding-left:20px;color:#cbd5e1;line-height:1.8;font-size:0.9rem">
          <li>Ketuk <b>menu titik tiga (⋮)</b> di pojok kanan atas browser.</li>
          <li>Pilih opsi <b>"Tambahkan ke Layar Utama"</b> atau <b>"Instal aplikasi"</b>.</li>
          <li>Konfirmasi dengan mengetuk <b>"Instal"</b>. Ikon game akan langsung muncul di menu HP Anda!</li>
        </ol>
      `;
    } else {
      guideHtml = `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
          <img src="/assets/img/logo-silber.jpg" width="48" height="48" style="border-radius:12px" alt="SMK Silber">
          <div>
            <h3 style="margin:0;font-size:1.1rem;color:#fff">Pasang di Komputer / Laptop</h3>
            <span style="font-size:0.75rem;color:#22d3ee">Desktop Browser</span>
          </div>
        </div>
        <ol style="margin:0 0 16px;padding-left:20px;color:#cbd5e1;line-height:1.8;font-size:0.9rem">
          <li>Klik ikon <b>Pasang Aplikasi ⊕</b> di bilah alamat browser (sebelah kanan URL).</li>
          <li>Atau buka menu titik tiga (⋮) browser → <b>"Instal Virtual City SMK Silber"</b>.</li>
          <li>Aplikasi akan berjalan sebagai jendela mandiri tanpa bilah browser.</li>
        </ol>
      `;
    }

    modal.innerHTML = `
      <div style="background:#131a29;border:1px solid #22d3ee;border-radius:16px;padding:22px;max-width:440px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,0.8)">
        ${guideHtml}
        <div style="display:flex;gap:8px;margin-top:14px">
          <a href="/download" style="flex:1;background:rgba(2,132,199,0.22);color:#38bdf8;border:1px solid #0284c7;padding:10px;border-radius:8px;font-weight:bold;text-decoration:none;text-align:center;font-size:0.84rem;display:inline-flex;align-items:center;justify-content:center" onclick="pwa.closeInstallGuide()">📲 Pusat Unduh File</a>
          <button type="button" onclick="pwa.closeInstallGuide()" style="flex:1;background:#22d3ee;color:#000;border:none;padding:10px;border-radius:8px;font-weight:bold;cursor:pointer;font-size:0.88rem">Siap, Mengerti 👍</button>
        </div>
      </div>
    `;
    modal.style.display = 'flex';
    this.playSfx('click');
  }

  closeInstallGuide() {
    const modal = document.getElementById('pwaGuideModal');
    if (modal) modal.style.display = 'none';
    this.dismissBanner();
    this.playSfx('click');
  }

  // Web Audio Synth for game feedback.
  playSfx(type = 'click') {
    if (window.gameAudio) { window.gameAudio.sfx(type); return; }
    if (!this.soundEnabled) return;
    try {
      if (!this.audioCtx) {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        this.audioCtx = new AudioContext();
      }
      const ctx = this.audioCtx;

      if (type === 'click') {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.setValueAtTime(600, ctx.currentTime);
        gain.gain.setValueAtTime(0.12, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.05);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.05);
      } else if (type === 'fanfare') {
        [523.25, 659.25, 783.99, 1046.50].forEach((freq, idx) => {
          setTimeout(() => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(freq, ctx.currentTime);
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
          }, idx * 100);
        });
      }
    } catch (e) {}
  }

  // Network online/offline status handling
  setupNetworkStatusListener() {
    let offlineBanner = null;

    const showOffline = () => {
      if (document.getElementById('pwaOfflineBanner')) return;
      offlineBanner = document.createElement('div');
      offlineBanner.id = 'pwaOfflineBanner';
      offlineBanner.style.cssText = `
        position: fixed; top: calc(12px + var(--safe-t, 0px)); left: 50%; transform: translateX(-50%);
        background: rgba(239, 68, 68, 0.95); backdrop-filter: blur(8px);
        color: #fff; padding: 8px 18px; border-radius: 999px; font-size: 0.85rem; font-weight: 600;
        z-index: 10000; box-shadow: 0 4px 16px rgba(239, 68, 68, 0.4); display: flex; align-items: center; gap: 8px;
        transition: opacity 0.3s;
      `;
      offlineBanner.innerHTML = '<span>⚡</span> <span>Anda sedang offline. Mode offline aktif.</span>';
      document.body.appendChild(offlineBanner);
    };

    const showOnline = () => {
      const existing = document.getElementById('pwaOfflineBanner');
      if (existing) existing.remove();

      const onlineBanner = document.createElement('div');
      onlineBanner.style.cssText = `
        position: fixed; top: calc(12px + var(--safe-t, 0px)); left: 50%; transform: translateX(-50%);
        background: rgba(16, 185, 129, 0.95); backdrop-filter: blur(8px);
        color: #fff; padding: 8px 18px; border-radius: 999px; font-size: 0.85rem; font-weight: 600;
        z-index: 10000; box-shadow: 0 4px 16px rgba(16, 185, 129, 0.4); display: flex; align-items: center; gap: 8px;
        transition: opacity 0.3s;
      `;
      onlineBanner.innerHTML = '<span>✅</span> <span>Kembali terhubung ke server.</span>';
      document.body.appendChild(onlineBanner);
      setTimeout(() => {
        onlineBanner.style.opacity = '0';
        setTimeout(() => onlineBanner.remove(), 300);
      }, 3000);
    };

    window.addEventListener('offline', showOffline);
    window.addEventListener('online', showOnline);

    if (!navigator.onLine) {
      showOffline();
    }
  }

  // Web Push notification subscription
  async setupPushNotifications() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
    if (Notification.permission === 'denied') return;

    try {
      const reg = await navigator.serviceWorker.ready;
      let sub = await reg.pushManager.getSubscription();
      if (!sub && Notification.permission === 'granted') {
        const keyRes = await fetch('/api/push/key');
        if (!keyRes.ok) return;
        const keyData = await keyRes.json();
        if (!keyData.public_key) return;

        const padding = '='.repeat((4 - (keyData.public_key.length % 4)) % 4);
        const base64 = (keyData.public_key + padding).replace(/\-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
          outputArray[i] = rawData.charCodeAt(i);
        }

        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: outputArray
        });

        const csrfMeta = document.querySelector('meta[name="csrf"]'); const csrf = (csrfMeta && csrfMeta.content) || '';
        await fetch('/api/push/subscribe', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
          body: JSON.stringify({
            subscription: sub.toJSON(),
            _csrf: csrf
          })
        });
      }
    } catch (e) {}
  }
}

window.pwa = new PWAManager();
window.addEventListener('DOMContentLoaded', () => window.pwa.init());
