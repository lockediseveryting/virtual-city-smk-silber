/*
 * vcShare — jendela "Bagikan" yang sama untuk MABAR dan SERVER/ROOM:
 *   link (salin), kode, QR (VcQR, lokal), tombol WhatsApp & bagikan bawaan HP (navigator.share).
 *   vcShare.open({ title, url, code, text, note, onClose })
 * Butuh qr.js (VcQR) dimuat lebih dulu; tanpa itu QR disembunyikan. ES2017 tanpa ?. / ??.
 */
(function () {
  'use strict';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function abs(u) { return /^https?:/i.test(u) ? u : location.origin + (u.charAt(0) === '/' ? u : '/' + u); }
  var CSS = '.vsh{position:fixed;inset:0;z-index:10050;background:rgba(10,6,3,.72);display:flex;align-items:center;justify-content:center;padding:16px}'
    + '.vsh__box{background:#fff4dc;color:#2a1a0c;border:3px solid #20130a;box-shadow:0 6px 0 #20130a;border-radius:6px;max-width:380px;width:100%;max-height:92vh;overflow:auto;padding:16px;text-align:center;font-family:inherit}'
    + '.vsh__box h2{margin:0 0 4px;font-size:1.15rem}.vsh__box p{margin:4px 0 10px;font-size:.88rem;line-height:1.4}'
    + '.vsh__qr{display:inline-block;background:#fff;padding:6px;border:2px solid #20130a;border-radius:4px;line-height:0}.vsh__qr svg{width:min(220px,62vw);height:auto}'
    + '.vsh__code{font-size:1.4rem;letter-spacing:.18em;font-weight:800;margin:8px 0 2px}'
    + '.vsh__link{display:flex;gap:6px;margin:10px 0}.vsh__link input{flex:1;min-width:0;font-size:16px;padding:8px;border:2px solid #20130a;border-radius:4px;background:#fff;color:#20130a}'
    + '.vsh__btns{display:flex;flex-wrap:wrap;gap:6px;justify-content:center}.vsh__btns a,.vsh__btns button{flex:1 1 30%;min-height:44px;padding:8px 10px;font-weight:700;font-size:.9rem;border:2px solid #20130a;border-radius:4px;background:#ffcf4a;color:#20130a;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}'
    + '.vsh__btns .is-dark{background:#3b2414;color:#fff4dc}.vsh__btns .is-wa{background:#25d366;color:#05230f}'
    + '.vsh__note{font-size:.78rem;color:#6b4a2a}';

  function open(o) {
    o = o || {};
    if (!document.getElementById('vshCss')) { var st = document.createElement('style'); st.id = 'vshCss'; st.textContent = CSS; document.head.appendChild(st); }
    var url = abs(o.url || location.href);
    var text = (o.text || 'Ayo gabung di Virtual City SMK Silber!') + (o.code ? ' Kode: ' + o.code : '');
    var qr = '';
    try { if (window.VcQR) qr = '<div class="vsh__qr">' + window.VcQR.svg(url, 220) + '</div>'; } catch (e) { qr = ''; }
    var el = document.createElement('div');
    el.className = 'vsh';
    el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true'); el.setAttribute('aria-label', 'Bagikan');
    el.innerHTML = '<div class="vsh__box px-panel"><h2>🔗 ' + esc(o.title || 'Bagikan') + '</h2>'
      + '<p>Scan QR pakai kamera HP, atau kirim link di bawah ke teman.</p>' + qr
      + (o.code ? '<div class="vsh__code">' + esc(o.code) + '</div>' : '')
      + '<div class="vsh__link"><input readonly value="' + esc(url) + '" aria-label="Link"><button type="button" class="px-btn" data-s="copy">📋 Salin</button></div>'
      + '<div class="vsh__btns">'
      + (navigator.share ? '<button type="button" data-s="native">📤 Bagikan</button>' : '')
      + '<a class="is-wa" href="https://wa.me/?text=' + encodeURIComponent(text + ' ' + url) + '" target="_blank" rel="noopener noreferrer">WhatsApp</a>'
      + '<button type="button" class="is-dark" data-s="close">Tutup</button></div>'
      + (o.note ? '<p class="vsh__note">' + esc(o.note) + '</p>' : '') + '</div>';
    document.body.appendChild(el);
    function close() { if (el.parentNode) el.parentNode.removeChild(el); document.removeEventListener('keydown', onKey); if (o.onClose) o.onClose(); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    el.addEventListener('click', function (e) {
      if (e.target === el) { close(); return; }
      var a = e.target.getAttribute && e.target.getAttribute('data-s');
      if (a === 'close') close();
      else if (a === 'copy') {
        var inp = el.querySelector('input');
        var done = function () { e.target.textContent = '✅ Tersalin'; };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text + ' ' + url).then(done, function () { inp.select(); try { document.execCommand('copy'); done(); } catch (x) {} });
        else { inp.select(); try { document.execCommand('copy'); done(); } catch (x) {} }
      } else if (a === 'native') {
        navigator.share({ title: o.title || 'Virtual City', text: text, url: url }).catch(function () {});
      }
    });
    var b = el.querySelector('[data-s="copy"]'); if (b) b.focus();
    return el;
  }
  window.vcShare = { open: open };
})();
