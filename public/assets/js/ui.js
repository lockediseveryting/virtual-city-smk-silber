/**
 * Virtual City SMK Silber v5 — UI runtime (Phase 5)
 * Shared client helpers: i18n, toasts, sheets, theming, tab bar, confetti,
 * level-up burst, coin fly-ups, offline indicator, and global error safety.
 * Exposes window.UI. No frameworks, no CDN.
 */
(function () {
  'use strict';

  const STORAGE = {
    theme: 'vc_theme',
    motion: 'vc_motion',
    textsize: 'vc_textsize',
    sound: 'vc_sound'
  };

  const UI = {
    /* ---- i18n ---- */
    t(key, replace) {
      const dict = window.__I18N || {};
      let node = dict;
      for (const part of String(key).split('.')) {
        if (node == null || typeof node !== 'object' || !(part in node)) return key;
        node = node[part];
      }
      let text = typeof node === 'string' ? node : key;
      if (replace) {
        for (const k of Object.keys(replace)) {
          text = text.split('{' + k + '}').join(String(replace[k]));
        }
      }
      return text;
    },

    /* ---- Toasts ---- */
    toast(message, type = 'info', timeout = 4200) {
      let stack = document.querySelector('.toast-stack');
      if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        stack.setAttribute('role', 'status');
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
      }
      const el = document.createElement('div');
      el.className = 'toast' + (type && type !== 'info' ? ' toast--' + type : '');
      el.textContent = message;
      stack.appendChild(el);
      const kill = () => {
        el.classList.add('is-leaving');
        setTimeout(() => el.remove(), 260);
      };
      el.addEventListener('click', kill);
      setTimeout(kill, timeout);
      return el;
    },

    /* ---- Bottom sheet / modal ---- */
    openSheet(id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.add('is-open');
      el.setAttribute('aria-hidden', 'false');
      const first = el.querySelector('button, [href], input, select, textarea');
      if (first) first.focus();
    },
    closeSheet(id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('is-open');
      el.setAttribute('aria-hidden', 'true');
    },
    bindSheet(backdropId) {
      const el = document.getElementById(backdropId);
      if (!el) return;
      el.addEventListener('click', (e) => { if (e.target === el) UI.closeSheet(backdropId); });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && el.classList.contains('is-open')) UI.closeSheet(backdropId);
      });
    },

    /* ---- Theme / accessibility preferences (single official cyber dark theme) ---- */
    applyPrefs() {
      const root = document.documentElement;
      root.removeAttribute('data-theme');
      try { localStorage.removeItem(STORAGE.theme); } catch (e) {}
      const motion = localStorage.getItem(STORAGE.motion);
      if (motion) root.setAttribute('data-motion', motion);
      const size = localStorage.getItem(STORAGE.textsize);
      if (size) root.setAttribute('data-textsize', size);
    },
    setTheme(theme) {
      document.documentElement.removeAttribute('data-theme');
      try { localStorage.removeItem(STORAGE.theme); } catch (e) {}
    },
    setMotion(reduced) { const v = reduced ? 'reduced' : 'full'; localStorage.setItem(STORAGE.motion, v); document.documentElement.setAttribute('data-motion', v); },
    setTextSize(size) { localStorage.setItem(STORAGE.textsize, size); document.documentElement.setAttribute('data-textsize', size); },
    cycleTheme() {
      document.documentElement.removeAttribute('data-theme');
      try { localStorage.removeItem(STORAGE.theme); } catch (e) {}
      UI.toast('Tema: Cyber Dark Edition (Tema Resmi SMK Silber)', 'info', 2000);
      return 'dark';
    },

    /* ---- Tab bar / sidebar active highlighting ---- */
    markActiveTab() {
      const path = location.pathname.replace(/\/+$/, '') || '/';
      document.querySelectorAll('[data-nav-match]').forEach((a) => {
        const targets = (a.getAttribute('data-nav-match') || '').split(',');
        if (targets.includes(path)) a.classList.add('is-active');
      });
    },

    /* ---- Celebration effects (respect reduced motion) ---- */
    _reduced() {
      return document.documentElement.getAttribute('data-motion') === 'reduced' ||
             window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    },
    confetti(count = 34) {
      if (UI._reduced()) return;
      const colors = ['#22d3ee', '#14b8a6', '#f59e0b', '#a3e635', '#f472b6', '#6366f1'];
      for (let i = 0; i < count; i++) {
        const p = document.createElement('div');
        p.className = 'confetti-piece';
        p.style.background = colors[i % colors.length];
        p.style.left = Math.random() * 100 + 'vw';
        p.style.top = '-20px';
        p.style.opacity = '1';
        p.style.transform = 'translateY(0) rotate(0deg)';
        document.body.appendChild(p);
        const dx = (Math.random() - 0.5) * 220;
        const dy = window.innerHeight + 60;
        const rot = Math.random() * 720 - 360;
        requestAnimationFrame(() => {
          p.style.transition = 'transform 1600ms cubic-bezier(0.22,1,0.36,1), opacity 1600ms ease-in';
          p.style.transform = `translate(${dx}px, ${dy}px) rotate(${rot}deg)`;
          p.style.opacity = '0.1';
        });
        setTimeout(() => p.remove(), 1900);
      }
    },
    coinFly(fromEl, amount = 10) {
      if (UI._reduced()) { UI.toast('+' + amount + ' ' + UI.t('hud.coins'), 'gold', 1800); return; }
      const start = fromEl ? fromEl.getBoundingClientRect() : { left: innerWidth / 2, top: innerHeight / 2, width: 0, height: 0 };
      const target = document.querySelector('[data-coin-target]') || { getBoundingClientRect: () => ({ left: innerWidth - 70, top: 20, width: 0, height: 0 }) };
      const tRect = target.getBoundingClientRect();
      const el = document.createElement('div');
      el.className = 'coin-fly';
      el.textContent = '🪙';
      el.style.left = (start.left + start.width / 2) + 'px';
      el.style.top = (start.top + start.height / 2) + 'px';
      document.body.appendChild(el);
      requestAnimationFrame(() => {
        el.style.transition = 'transform 900ms cubic-bezier(0.22,1,0.36,1), opacity 900ms ease-in';
        el.style.transform = `translate(${tRect.left - start.left}px, ${tRect.top - start.top}px) scale(0.7)`;
        el.style.opacity = '0.2';
      });
      setTimeout(() => el.remove(), 1000);
    },
    levelUp() {
      if (UI._reduced()) { UI.toast('🎉 ' + UI.t('hud.level') + ' naik!', 'gold', 2600); return; }
      let el = document.querySelector('.levelup');
      if (!el) {
        el = document.createElement('div');
        el.className = 'levelup';
        el.innerHTML = '<div class="levelup__ring"></div>';
        document.body.appendChild(el);
      }
      el.classList.add('is-on');
      setTimeout(() => el.classList.remove('is-on'), 1150);
      UI.confetti(48);
    },

    /* ---- Online / offline indicator ---- */
    initNetworkIndicator() {
      const show = (offline) => {
        let bar = document.querySelector('.banner-offline');
        if (!bar) {
          bar = document.createElement('div');
          bar.className = 'banner-offline';
          bar.setAttribute('role', 'status');
          bar.textContent = UI.t('pwa.offline');
          document.body.appendChild(bar);
        }
        bar.classList.toggle('is-open', offline);
      };
      window.addEventListener('offline', () => show(true));
      window.addEventListener('online', () => { show(false); UI.toast('Kembali online.', 'success', 2000); });
      show(!navigator.onLine);
    },

    /* ---- Global error safety: friendly message, never a white screen ---- */
    initErrorGuards() {
      const report = (msg) => {
        try {
          const body = JSON.stringify({ message: String(msg).slice(0, 300), path: location.pathname });
          // Fire-and-forget, rate-limited server-side; never blocks the UI.
          if (navigator.sendBeacon) navigator.sendBeacon('/api/client-error', new Blob([body], { type: 'application/json' }));
        } catch (e) { /* ignore */ }
      };
      window.addEventListener('error', (e) => {
        console.warn('[VC] error:', e.message);
        report(e.message);
      });
      window.addEventListener('unhandledrejection', (e) => {
        console.warn('[VC] rejection:', e.reason);
        report(e.reason && e.reason.message ? e.reason.message : e.reason);
      });
    },

    /* ---- Offline action queue (background-sync friendly) ----
       Actions queued while offline are replayed automatically when the
       connection returns or the service worker fires a sync event. */
    _queueKey: 'vc_offline_queue',
    queueAction(action) {
      const q = UI._readQueue();
      q.push(action);
      try { localStorage.setItem(UI._queueKey, JSON.stringify(q.slice(-50))); } catch (e) { /* quota */ }
      UI.toast('Disimpan offline — akan dikirim otomatis.', 'gold', 2600);
    },
    _readQueue() {
      try { return JSON.parse(localStorage.getItem(UI._queueKey) || '[]'); } catch (e) { return []; }
    },
    async flushQueue() {
      const q = UI._readQueue();
      if (!q.length) return;
      const remaining = [];
      for (const action of q) {
        try {
          const res = await fetch(action.url, {
            method: action.method || 'POST',
            credentials: 'same-origin',
            headers: Object.assign({ 'Content-Type': 'application/json' }, action.headers || {}),
            body: action.body ? JSON.stringify(action.body) : undefined
          });
          if (!res.ok && res.status >= 500) remaining.push(action);
        } catch (e) {
          remaining.push(action);
        }
      }
      try { localStorage.setItem(UI._queueKey, JSON.stringify(remaining)); } catch (e) { /* ignore */ }
      if (q.length !== remaining.length) UI.toast('Aksi tertunda berhasil dikirim.', 'success', 2400);
    },
    initBackgroundSync() {
      window.addEventListener('online', () => UI.flushQueue());
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (e) => {
          if (e.data && e.data.type === 'FLUSH_QUEUE') UI.flushQueue();
        });
        navigator.serviceWorker.ready.then((reg) => {
          if (reg.sync) reg.sync.register('vc-sync-actions').catch(() => {});
        }).catch(() => {});
      }
    },

    async fetchJSON(url, options = {}, timeoutMs = 12000) {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      try {
        const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, options, { signal: controller.signal }));
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
      } finally {
        clearTimeout(timer);
      }
    },

    /* Hide the splash/loading overlay once the page is ready. */
    initSplash() {
      const splash = document.querySelector('.splash');
      if (!splash) return;
      const hide = () => {
        splash.style.opacity = '0';
        setTimeout(() => splash.remove(), 460);
      };
      if (document.readyState === 'complete') hide();
      else window.addEventListener('load', hide);
      setTimeout(hide, 2500); // never trap the user behind a stuck splash
    },

    /* ---- Player stats (level / XP / coins) → HUD elements, if present ---- */
    async refreshStats(silent = true) {
      if (!document.getElementById('hudCoins') && !document.getElementById('heroXp')) return;
      try {
        const { ok, data } = await UI.fetchJSON('/api/stats', {}, 8000);
        if (!ok || !data || data.status !== 'success') return;
        const set = (id, val) => {
          const el = document.getElementById(id);
          if (el) el.textContent = val;
        };
        const xp = Number(data.total_xp) || 0;
        const into = Number(data.xp_into_level) || 0;
        const need = Number(data.xp_to_next) || 0;
        const pct = (into + need) > 0 ? Math.round(into * 100 / (into + need)) : 100;
        const lvlTxt = UI.t('hud.level') + ' ' + (data.level || 1);

        set('hudCoins', (Number(data.coins) || 0).toLocaleString('id-ID'));
        set('hudXp', xp.toLocaleString('id-ID'));
        if (document.getElementById('hudLevel')) set('hudLevel', lvlTxt);
        const bar = document.getElementById('hudXpBar');
        if (bar) bar.style.width = pct + '%';

        set('heroXp', xp.toLocaleString('id-ID'));
        const hBar = document.getElementById('heroXpBar');
        if (hBar) hBar.style.width = pct + '%';
        const hPill = document.getElementById('heroLevel');
        if (hPill) hPill.textContent = '⭐ ' + lvlTxt;
        set('heroXpNeed', need > 0 ? need.toLocaleString('id-ID') + ' XP' : 'Maks');
        if (!silent && Number(data.leveled_up)) UI.levelUp();
      } catch (e) { /* stats are cosmetic — never break the page */ }
    },

    /* ---- CSP-safe binding of inline handler attributes --------------------
     * The app ships a nonce-based Content-Security-Policy
     * (script-src 'self' 'nonce-…'). Per the CSP spec a nonce disables
     * 'unsafe-inline', so browsers refuse to run inline event-handler
     * attributes (onclick/onsubmit/oninput/…) — every sheet, form and HUD
     * button would go dead. The markup stays as-is; this 'self'-trusted
     * runtime re-binds each attribute from script so behaviour, `this`,
     * `event` and "return false = cancel the default action" keep working
     * — without weakening the policy (no 'unsafe-inline', nonce untouched).
     * Also wires markup injected later by AJAX (MutationObserver).
     */
    INLINE_HANDLERS: {
      click: 'onclick',
      dblclick: 'ondblclick',
      input: 'oninput',
      change: 'onchange',
      submit: 'onsubmit',
      keydown: 'onkeydown',
      keyup: 'onkeyup',
      focus: 'onfocus',
      blur: 'onblur',
      mouseenter: 'onmouseenter',
      mouseleave: 'onmouseleave',
      touchstart: 'ontouchstart'
    },

    _inlineSelector() {
      if (!UI._inlineSel) {
        UI._inlineSel = Object.keys(UI.INLINE_HANDLERS)
          .map((ev) => '[' + UI.INLINE_HANDLERS[ev] + ']')
          .join(',');
      }
      return UI._inlineSel;
    },

    /** Bind every not-yet-bound inline handler inside `root` (element | document). */
    wireInline(root) {
      const scope = root || document;
      const sel = UI._inlineSelector();
      const list = [];
      try {
        if (scope.nodeType === 1 && scope.matches && scope.matches(sel)) list.push(scope);
        if (scope.querySelectorAll) {
          const found = scope.querySelectorAll(sel);
          for (let i = 0; i < found.length; i++) list.push(found[i]);
        }
      } catch (e) { return; }

      list.forEach((el) => {
        Object.keys(UI.INLINE_HANDLERS).forEach((ev) => {
          const attr = UI.INLINE_HANDLERS[ev];
          if (!el.hasAttribute(attr)) return;
          const bag = el.__vcInline || (el.__vcInline = {});
          if (bag[attr]) return;              // idempotent — never double-bind
          bag[attr] = true;
          const body = el.getAttribute(attr) || '';
          let fn;
          try {
            fn = new Function('event', body);
          } catch (err) {
            if (window.console) console.warn('[ui] inline handler tidak valid (' + attr + '):', err.message);
            return;
          }
          el.addEventListener(ev, function (event) {
            const out = fn.call(this, event);
            if (out === false && event && typeof event.preventDefault === 'function') {
              event.preventDefault();
              if (event.stopPropagation) event.stopPropagation();
            }
          });
        });
      });
    },

    initInlineHandlers() {
      UI.wireInline(document);
      if (UI._inlineObserver || !window.MutationObserver || !document.documentElement) return;
      UI._inlineObserver = new MutationObserver((muts) => {
        for (let i = 0; i < muts.length; i++) {
          const added = muts[i].addedNodes;
          if (!added) continue;
          for (let j = 0; j < added.length; j++) {
            if (added[j].nodeType === 1) UI.wireInline(added[j]);
          }
        }
      });
      UI._inlineObserver.observe(document.documentElement, { childList: true, subtree: true });
    },

    init() {
      UI.applyPrefs();
      UI.markActiveTab();
      UI.initNetworkIndicator();
      UI.initErrorGuards();
      UI.initSplash();
      UI.initBackgroundSync();
      UI.refreshStats();
      // Keep the HUD honest while the player earns XP/coins elsewhere.
      window.addEventListener('focus', () => UI.refreshStats());
      // Bind backdrops and Escape key for all sheets
      ['vcQuickSheet', 'vcSettingsSheet', 'vcNotifSheet', 'vcAiMascotSheet'].forEach((id) => UI.bindSheet(id));

      // Delegated click handler for sheets (CSP-safe, mobile touch-friendly)
      document.addEventListener('click', (e) => {
        const openEl = e.target.closest && e.target.closest('[data-open-sheet]');
        if (openEl) {
          const sid = openEl.getAttribute('data-open-sheet');
          if (sid && UI.openSheet) {
            e.preventDefault();
            UI.openSheet(sid);
            return;
          }
        }
        const closeEl = e.target.closest && e.target.closest('[data-close-sheet]');
        if (closeEl) {
          const sid = closeEl.getAttribute('data-close-sheet');
          if (sid && UI.closeSheet) {
            e.preventDefault();
            UI.closeSheet(sid);
            return;
          }
        }
      });

      const toggle = document.querySelector('[data-action="cycle-theme"]');
      if (toggle) toggle.addEventListener('click', UI.cycleTheme);
      if (window.gameAudio) window.gameAudio.attachGestureUnlock();
    }
  };

  window.UI = UI;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', UI.init);
  } else {
    UI.init();
  }
})();
