/*
 * Tugas bentuk soal (pilihan ganda + essay).
 *  - Guru (form Buat Tugas, #tqBuilder): tambah soal PG/essay, tandai jawaban benar, poin per soal,
 *    atau TEMPEL CEPAT dari Word/WhatsApp:
 *        1. Ibu kota Indonesia?
 *        A. Bandung
 *        *B. Jakarta        ← tanda * = jawaban benar
 *        C. Surabaya
 *        2. Jelaskan fungsi router!   ← tanpa pilihan = essay
 *  - Murid (halaman tugas, #tqTake): kerjakan soal; jawaban dikirim sebagai JSON "answers".
 *  - Guru (penilaian, .tq-grade): poin essay → nilai akhir dihitung otomatis.
 * ES2017.
 */
(function () {
  'use strict';
  var L = 'ABCDEF';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  // ================= Guru: pembuat soal =================
  var box = document.getElementById('tqBuilder');
  if (box) {
    var qs = [];
    var instr = document.getElementById('taskInstr');
    document.querySelectorAll('input[name=task_kind]').forEach(function (r) {
      r.addEventListener('change', function () {
        var on = r.value === 'soal' && r.checked;
        if (r.checked) {
          box.hidden = !on;
          if (instr) { instr.required = !on; instr.placeholder = on ? 'Opsional: petunjuk singkat, mis. "Kerjakan dalam 30 menit, tanpa membuka buku."' : 'Tuliskan petunjuk pengerjaan, format laporan, kriteria penilaian...'; }
          if (on && !qs.length) { qs.push(blank('pg')); render(); }
        }
      });
    });

    function blank(t) { return t === 'pg' ? { t: 'pg', q: '', opts: ['', '', '', ''], ans: -1, pts: 10 } : { t: 'essay', q: '', key: '', pts: 20 }; }
    function total() { return qs.reduce(function (a, q) { return a + (parseInt(q.pts, 10) || 0); }, 0); }

    function render() {
      var h = '<div class="tq-tools"><button type="button" class="btn btn-sec" data-add="pg">➕ Pilihan Ganda</button>'
        + '<button type="button" class="btn btn-sec" data-add="essay">➕ Essay</button>'
        + '<button type="button" class="btn btn-sec" data-paste>📋 Tempel soal dari Word</button>'
        + '<span class="tq-sum">' + qs.length + ' soal · ' + total() + ' poin</span></div>';
      qs.forEach(function (q, i) {
        h += '<div class="tq-card" data-i="' + i + '"><div class="tq-card__head"><b>' + (i + 1) + '. ' + (q.t === 'pg' ? '✅ Pilihan Ganda' : '✍️ Essay') + '</b>'
          + '<label class="tq-pts">Poin <input type="number" min="1" max="100" data-f="pts" value="' + esc(q.pts) + '"></label>'
          + '<button type="button" class="tq-x" data-up title="Naikkan">▲</button><button type="button" class="tq-x" data-del title="Hapus soal">🗑️</button></div>'
          + '<textarea rows="2" data-f="q" placeholder="Tulis pertanyaan…">' + esc(q.q) + '</textarea>';
        if (q.t === 'pg') {
          h += '<div class="tq-opts">';
          q.opts.forEach(function (o, k) {
            h += '<label class="tq-opt' + (q.ans === k ? ' is-ans' : '') + '"><input type="radio" name="tqans' + i + '" data-ans="' + k + '"' + (q.ans === k ? ' checked' : '') + ' title="Tandai jawaban benar">'
              + '<b>' + L[k] + '</b><input type="text" data-opt="' + k + '" value="' + esc(o) + '" placeholder="Pilihan ' + L[k] + '"></label>';
          });
          h += '</div><div class="tq-mini">' + (q.opts.length < 6 ? '<button type="button" class="tq-link" data-addopt>+ pilihan</button>' : '') + (q.opts.length > 2 ? '<button type="button" class="tq-link" data-delopt>− pilihan</button>' : '')
            + '<span>' + (q.ans < 0 ? '⚠️ Klik bulatan di samping jawaban yang benar' : '✔ Jawaban benar: ' + L[q.ans]) + '</span></div>';
        } else {
          h += '<textarea rows="2" data-f="key" class="tq-key" placeholder="Kunci / rubrik jawaban (opsional, hanya terlihat guru)">' + esc(q.key) + '</textarea>';
        }
        h += '</div>';
      });
      if (qs.length) h += '<div class="tq-tools"><button type="button" class="btn btn-sec" data-add="pg">➕ Pilihan Ganda</button><button type="button" class="btn btn-sec" data-add="essay">➕ Essay</button></div>';
      h += '<p class="tq-help">Murid langsung dapat nilai bila semua soal pilihan ganda. Bila ada essay, kamu memberi poin essay di halaman tugas → nilai akhir dihitung otomatis.</p>';
      box.innerHTML = h;
    }

    box.addEventListener('input', function (e) {
      var card = e.target.closest('.tq-card'); if (!card) return;
      var q = qs[+card.getAttribute('data-i')], f = e.target.getAttribute('data-f'), o = e.target.getAttribute('data-opt');
      if (f) q[f] = f === 'pts' ? e.target.value : e.target.value;
      if (o !== null) q.opts[+o] = e.target.value;
      if (f === 'pts') { var s = box.querySelector('.tq-sum'); if (s) s.textContent = qs.length + ' soal · ' + total() + ' poin'; }
    });
    box.addEventListener('change', function (e) {
      var a = e.target.getAttribute('data-ans'); if (a === null) return;
      var card = e.target.closest('.tq-card'); qs[+card.getAttribute('data-i')].ans = +a; render();
    });
    box.addEventListener('click', function (e) {
      var t = e.target, card = t.closest('.tq-card'), i = card ? +card.getAttribute('data-i') : -1;
      if (t.hasAttribute('data-add')) { qs.push(blank(t.getAttribute('data-add'))); render(); var c = box.querySelectorAll('.tq-card'); var last = c[c.length - 1]; if (last) last.querySelector('textarea').focus(); }
      else if (t.hasAttribute('data-del')) { if (qs[i].q === '' || confirm('Hapus soal no. ' + (i + 1) + '?')) { qs.splice(i, 1); render(); } }
      else if (t.hasAttribute('data-up') && i > 0) { var tmp = qs[i - 1]; qs[i - 1] = qs[i]; qs[i] = tmp; render(); }
      else if (t.hasAttribute('data-addopt')) { qs[i].opts.push(''); render(); }
      else if (t.hasAttribute('data-delopt')) { qs[i].opts.pop(); if (qs[i].ans >= qs[i].opts.length) qs[i].ans = -1; render(); }
      else if (t.hasAttribute('data-paste')) paste();
    });

    function paste() {
      var txt = prompt('Tempel soal (format: nomor soal, lalu pilihan A. B. C. D., beri tanda * di depan jawaban benar; soal tanpa pilihan = essay):', '');
      if (!txt) return;
      var parsed = parse(txt);
      if (!parsed.length) { alert('Format tidak dikenali. Contoh:\n1. Ibu kota Indonesia?\nA. Bandung\n*B. Jakarta\n2. Jelaskan fungsi router!'); return; }
      if (qs.length === 1 && !qs[0].q) qs = [];
      qs = qs.concat(parsed);
      render();
      var noAns = parsed.filter(function (q) { return q.t === 'pg' && q.ans < 0; }).length;
      alert('✅ ' + parsed.length + ' soal ditambahkan.' + (noAns ? '\n⚠️ ' + noAns + ' soal PG belum ada tanda jawaban benar (*) — klik bulatannya.' : ''));
    }
    function parse(txt) {
      var out = [], cur = null;
      txt.replace(/\r/g, '').split('\n').forEach(function (raw) {
        var line = raw.trim();
        if (!line) return;
        var mq = /^(\d{1,3})\s*[.)]\s*(.+)$/.exec(line);
        var mo = /^(\*)?\s*([A-Fa-f])\s*[.)]\s*(.+?)\s*(\*|\(benar\)|✓)?$/.exec(line);
        if (mq) { cur = { t: 'essay', q: mq[2], key: '', pts: 10, opts: [], ans: -1 }; out.push(cur); }
        else if (mo && cur) { if (mo[1] || mo[4]) cur.ans = cur.opts.length; cur.opts.push(mo[3]); }
        else if (/^(kunci|jawaban)\s*[:=]\s*([A-Fa-f])\b/i.test(line) && cur) { cur.ans = L.indexOf(/^(?:kunci|jawaban)\s*[:=]\s*([A-Fa-f])/i.exec(line)[1].toUpperCase()); }
        else if (cur) cur.q += '\n' + line;
      });
      return out.map(function (q) {
        if (q.opts.length >= 2) return { t: 'pg', q: q.q, opts: q.opts.slice(0, 6), ans: q.ans < q.opts.length ? q.ans : -1, pts: 10 };
        return { t: 'essay', q: q.q, key: '', pts: 20 };
      });
    }

    window.vcTaskQuiz = {
      collect: function () {
        for (var i = 0; i < qs.length; i++) {
          var q = qs[i];
          if (!String(q.q).trim()) { alert('Soal no. ' + (i + 1) + ' masih kosong.'); return []; }
          if (q.t === 'pg') {
            var filled = q.opts.filter(function (o) { return String(o).trim(); }).length;
            if (filled < 2) { alert('Soal no. ' + (i + 1) + ': isi minimal 2 pilihan.'); return []; }
            if (q.ans < 0 || !String(q.opts[q.ans] || '').trim()) { alert('Soal no. ' + (i + 1) + ': tandai jawaban yang benar.'); return []; }
          }
        }
        return qs.map(function (q) {
          if (q.t === 'essay') return { t: 'essay', q: q.q, key: q.key, pts: parseInt(q.pts, 10) || 20 };
          // buang pilihan kosong sambil menjaga indeks jawaban benar
          var opts = [], ans = -1;
          q.opts.forEach(function (o, k) { if (String(o).trim()) { if (k === q.ans) ans = opts.length; opts.push(o); } });
          return { t: 'pg', q: q.q, opts: opts, ans: ans, pts: parseInt(q.pts, 10) || 10 };
        });
      }
    };
  }

  // ================= Murid: mengerjakan soal =================
  var take = document.getElementById('tqTake');
  if (take) {
    var data = JSON.parse(take.getAttribute('data-q') || '[]');
    var prev = JSON.parse(take.getAttribute('data-a') || '{}') || {};
    var hidden = document.getElementById('tqAnswers');
    var h = '';
    data.forEach(function (q, i) {
      h += '<fieldset class="tq-q"><legend><b>' + (i + 1) + '.</b> <span>' + esc(q.q).replace(/\n/g, '<br>') + '</span> <small>(' + q.pts + ' poin)</small></legend>';
      if (q.t === 'pg') {
        q.opts.forEach(function (o, k) {
          h += '<label class="tq-choice"><input type="radio" name="tq' + i + '" value="' + k + '"' + (prev[i] === k ? ' checked' : '') + '><b>' + L[k] + '.</b> <span>' + esc(o) + '</span></label>';
        });
      } else {
        h += '<textarea rows="4" class="form-input" name="tq' + i + '" placeholder="Tulis jawabanmu…">' + esc(prev[i] || '') + '</textarea>';
      }
      h += '</fieldset>';
    });
    h += '<p class="tq-progress" id="tqProgress"></p>';
    take.innerHTML = h;
    function sync() {
      var ans = {}, done = 0;
      data.forEach(function (q, i) {
        if (q.t === 'pg') { var c = take.querySelector('input[name=tq' + i + ']:checked'); ans[i] = c ? +c.value : null; if (c) done++; }
        else { var t = take.querySelector('textarea[name=tq' + i + ']'); ans[i] = t ? t.value : ''; if (t && t.value.trim()) done++; }
      });
      if (hidden) hidden.value = JSON.stringify(ans);
      var p = document.getElementById('tqProgress'); if (p) p.textContent = 'Terjawab ' + done + ' dari ' + data.length + ' soal.';
    }
    take.addEventListener('input', sync);
    take.addEventListener('change', sync);
    sync();
  }

  // ================= Guru: menilai essay =================
  document.querySelectorAll('.tq-grade').forEach(function (g) {
    var sid = g.getAttribute('data-sub'), pg = +g.getAttribute('data-pg'), totalPts = +g.getAttribute('data-total'), max = +g.getAttribute('data-max');
    function calc(initial) {
      var essay = 0;
      g.querySelectorAll('[data-essay]').forEach(function (inp) { essay += Math.max(0, Math.min(+inp.max || 0, parseInt(inp.value, 10) || 0)); });
      var score = totalPts > 0 ? Math.round((pg + essay) / totalPts * max) : 0;
      var out = g.querySelector('.tq-calc'); if (out) out.textContent = 'PG ' + pg + ' + essay ' + essay + ' = ' + (pg + essay) + '/' + totalPts + ' poin → nilai ' + score;
      var target = document.getElementById('gradeScore_' + sid);
      if (target && (!initial || target.value === '')) target.value = score;
    }
    g.addEventListener('input', function () { calc(false); });
    calc(true);
  });
})();
