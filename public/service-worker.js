/**
 * Virtual City SMK Silber v5 — Production PWA Service Worker (Phase 5)
 * Versioned precache of the app shell + core 3D assets, smart runtime caching,
 * an offline fallback page, and a background-sync queue for offline actions.
 */
const CACHE_VERSION = 'v8.2.4-anti-crawler-waf';
const CACHE_NAME = 'vc-silber-' + CACHE_VERSION;
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [
  '/',
  '/login',
  '/daftar',
  '/download',
  '/tutorial',
  '/jadwal',
  '/dunia',
  '/peta',
  '/pixel',
  '/health',
  '/offline.html',
  '/manifest.json',
  // Design system + runtime + self-hosted fonts
  '/assets/css/app.css',
  '/assets/fonts/baloo2.woff2',
  '/assets/fonts/inter.woff2',
  '/assets/js/ui.js',
  '/assets/js/audio.js',
  '/assets/js/pwa.js',
  // Tema pixel-game (font, tombol kayu, layar penuh HP, dashboard)
  '/assets/css/pixel-theme.css',
  '/assets/css/pixel-dashboard.css',
  '/assets/css/auth-pixel.css',
  '/assets/fonts/pixelify-sans.woff2',
  '/assets/js/pixel-shell.js',
  '/assets/js/pixel-dashboard.js',
  '/assets/img/pixel/dashboard-kota.jpg?v=2',
  '/assets/img/pixel/dashboard-kota-siang.jpg?v=2',
  '/assets/img/pixel/login-poster.jpg',
  // Core 3D assets
  '/assets/vendor/three.min.js',
  '/assets/js/world3d.js',
  '/assets/js/map2d.js',
  '/assets/js/pixel-avatar.js',
  '/assets/js/pixel-side.js',
  '/assets/js/pixel-side-art.js',
  '/assets/js/pixel-side-map.js',
  '/assets/js/pixel-net.js',
  '/assets/js/pixel-audio.js',
  // Mode offline Dunia Pixel (pixel-offline di WorldController): skrip ikut tersimpan
  '/assets/js/pixel-pc.js',
  '/assets/js/world-life.js',
  '/assets/js/anomaly.js',
  '/assets/js/vc-ui-toggle.js',
  '/assets/js/inventory.js',
  '/assets/map/pixel-side.json',
  '/assets/map/campus.json',
  '/assets/map/locations.json',
  // Icons + templates
  '/assets/img/logo-silber.jpg',
  '/assets/icons/icon.svg',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/templates/template_jadwal.csv'
];

// Install: precache the app shell. One missing URL must not break the whole install.
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      Promise.allSettled(PRECACHE_URLS.map((url) => cache.add(url)))
    )
  );
});

// Activate: drop old cache versions, take over open pages.
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.map((key) => (key !== CACHE_NAME ? caches.delete(key) : null))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Only same-origin GET requests are handled here.
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;

  // 1. Never cache private/write APIs or CSRF tokens.
  if (url.pathname.startsWith('/api/')) {
    // "Today's schedule" is the only API with an offline fallback (last known schedule).
    if (url.pathname === '/api/schedule/today') {
      event.respondWith(
        fetch(req).then((res) => {
          if (res.status === 200) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then((c) => c.put(req, clone));
          }
          return res;
        }).catch(() => caches.match(req).then((cached) => cached || new Response('{"status":"offline"}', { headers: { 'Content-Type': 'application/json' } })))
      );
    }
    return; // everything else hits the network
  }

  // Video (Range request) langsung ke jaringan: cache-first memutus pemutaran di Safari/iOS.
  if (url.pathname.startsWith('/assets/video/')) return;

  // 2. Static assets: cache-first (fast, offline-ready).
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(req).then((cached) => cached || fetch(req).then((res) => {
        if (res.status === 200) {
          const clone = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, clone));
        }
        return res;
      }))
    );
    return;
  }

  // 3. HTML navigation: network-first, then cache, then offline page.
  event.respondWith(
    fetch(req).then((res) => {
      if (res.status === 200) {
        const clone = res.clone();
        caches.open(CACHE_NAME).then((c) => c.put(req, clone));
      }
      return res;
    }).catch(async () => {
      if (req.mode === 'navigate') {
        // Dunia Pixel bisa dimainkan offline: pakai salinan /pixel terakhir (abaikan ?server= / ?offline=).
        if (url.pathname === '/pixel') {
          const pix = await caches.match(req, { ignoreSearch: true });
          if (pix) return pix;
        }
        return (await caches.match(req)) || caches.match(OFFLINE_URL) || caches.match('/');
      }
      return (await caches.match(req)) || Response.error();
    })
  );
});

// Background sync: tell clients to flush their offline action queue.
self.addEventListener('sync', (event) => {
  if (event.tag === 'vc-sync-actions') {
    event.waitUntil(
      self.clients.matchAll({ includeUncontrolled: true }).then((clients) => {
        clients.forEach((c) => c.postMessage({ type: 'FLUSH_QUEUE' }));
      })
    );
  }
});

// Update flow: only skipWaiting when the user consents from the in-app prompt.
self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SKIP_WAITING') self.skipWaiting();
});

// Web Push Notifications
self.addEventListener('push', (event) => {
  let data = { title: 'SMK Silber', body: 'Pemberitahuan baru dari sekolah.' };
  try {
    if (event.data) {
      data = event.data.json();
    }
  } catch (e) {
    if (event.data) {
      data.body = event.data.text();
    }
  }

  const options = {
    body: data.body || '',
    icon: data.icon || '/assets/img/logo-silber.jpg',
    badge: data.badge || '/assets/icons/icon-192.png',
    vibrate: [100, 50, 100],
    data: {
      url: data.url || '/'
    }
  };

  event.waitUntil(
    self.registration.showNotification(data.title || 'SMK Silber', options)
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const urlToOpen = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      for (let client of windowClients) {
        if (client.url === urlToOpen && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(urlToOpen);
      }
    })
  );
});
