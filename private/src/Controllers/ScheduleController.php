<?php
// Schedule controller & UI view for student timetable, teacher tracking, and admin import/rollback.
declare(strict_types=1);

namespace VC\Controllers;

use VC\Database;
use VC\Domain\Schedule;
use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Footer;
use VC\Security\Headers;
use VC\Security\Permissions;

final class ScheduleController
{
    /** Render web page for /jadwal */
    public static function renderPage(): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();
        $pdo = Database::get();

        $classes = $pdo->query('SELECT id, name, grade FROM classes WHERE deleted_at IS NULL ORDER BY name')->fetchAll();
        $teachers = $pdo->query('SELECT id, username, full_name FROM users WHERE role IN ("guru", "wali_kelas", "kepala_program", "root") AND deleted_at IS NULL ORDER BY full_name, username')->fetchAll();
        $subjects = $pdo->query('SELECT id, code, name FROM subjects ORDER BY name')->fetchAll();
        $rooms = $pdo->query('SELECT id, code, name, floor, building FROM rooms_physical ORDER BY name')->fetchAll();
        $timeSlots = $pdo->query('SELECT period_number, start_time, end_time, is_break, label FROM time_slots WHERE day_of_week = 1 AND is_break = 0 ORDER BY period_number ASC')->fetchAll();

        $canManage = Permissions::can('schedule.import');
        $csrfToken = Csrf::token();

        // Default to first class if available
        $defaultClassId = !empty($classes) ? (int)$classes[0]['id'] : 0;
        $todayDate = date('Y-m-d');
        $todayDayOfWeek = (int)date('N');
        $todayName = Schedule::DAYS[$todayDayOfWeek] ?? 'Hari Ini';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?= \VC\Security\Pwa::headTags() ?>
<title>Jadwal Pelajaran — Virtual City SMK Silber</title>
<style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES) ?>">
:root {
  --bg: #090d16;
  --card: #131a29;
  --card-border: #1f293d;
  --accent: #22d3ee;
  --accent-gold: #f59e0b;
  --text: #e2e8f0;
  --muted: #94a3b8;
  --success: #10b981;
  --danger: #ef4444;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: system-ui, -apple-system, sans-serif;
  background: var(--bg);
  color: var(--text);
  line-height: 1.5;
  padding: 16px;
  min-height: 100vh;
}
.container { max-width: 960px; margin: 0 auto; }
header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--card-border);
}
h1 { font-size: 1.5rem; color: #fff; display: flex; align-items: center; gap: 8px; }
.badge-gold { background: rgba(245, 158, 11, 0.15); color: var(--accent-gold); padding: 4px 10px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; border: 1px solid rgba(245, 158, 11, 0.3); }
.badge-override { background: rgba(239, 68, 68, 0.15); color: #f87171; padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; border: 1px solid rgba(239, 68, 68, 0.3); }
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  font-size: 0.9rem;
  border: none;
  transition: opacity 0.2s;
}
.btn:hover { opacity: 0.9; }
.btn-primary { background: var(--accent); color: #000; }
.btn-secondary { background: var(--card-border); color: var(--text); }
.btn-danger { background: var(--danger); color: #fff; }
.grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
.up-steps { display: flex; gap: 8px; flex-wrap: wrap; list-style: none; padding: 0; margin: 0 0 12px; counter-reset: s; }
.up-steps li { counter-increment: s; flex: 1; min-width: 120px; padding: 8px 10px; background: #0b111e; border: 1px solid rgba(34,211,238,0.25); border-radius: 8px; font-size: .85rem; color: #cbd5e1; }
.up-steps li::before { content: counter(s); display: inline-grid; place-items: center; width: 22px; height: 22px; margin-right: 6px; border-radius: 50%; background: #22d3ee; color: #04121a; font-weight: 800; }
.up-drop { display: flex; flex-direction: column; align-items: center; gap: 6px; text-align: center; padding: 26px 16px; margin-bottom: 12px; border: 3px dashed rgba(34,211,238,0.55); border-radius: 12px; background: rgba(34,211,238,0.06); cursor: pointer; transition: background .15s, border-color .15s; }
.up-drop.is-over, .up-drop:hover { background: rgba(34,211,238,0.14); border-color: #22d3ee; }
.up-drop input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.up-drop__icon { font-size: 2.6rem; }
.up-drop b { font-size: 1.02rem; color: #fff; }
.up-drop small { color: var(--muted); max-width: 520px; line-height: 1.5; }
.up-more { margin-bottom: 12px; font-size: .9rem; } .up-more summary { cursor: pointer; color: #38bdf8; }
@media (min-width: 768px) { .grid-2 { grid-template-columns: 2fr 1fr; } }
.card {
  background: var(--card);
  border: 1px solid var(--card-border);
  border-radius: 12px;
  padding: 20px;
}
.card h2 { font-size: 1.15rem; margin-bottom: 16px; color: #fff; display: flex; justify-content: space-between; align-items: center; }
.select-group { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
select, input[type="text"], input[type="date"] {
  background: #0d121d;
  color: #fff;
  border: 1px solid var(--card-border);
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 16px;
  flex: 1;
}
select:focus, input:focus {
  outline: none;
  border-color: var(--accent);
}
.slot-list { display: flex; flex-direction: column; gap: 10px; }
.slot-item {
  background: #0d1320;
  border: 1px solid var(--card-border);
  border-radius: 8px;
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
@media (max-width: 560px) {
  .slot-item {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 12px;
  }
  .slot-time { min-width: 0; font-size: 0.8rem; }
  .slot-title { font-size: 0.9rem; }
}
.slot-item.is-break { background: rgba(30, 41, 59, 0.4); border-style: dashed; }
.slot-item.is-now { border-color: var(--accent); box-shadow: 0 0 12px rgba(34, 211, 238, 0.2); }
.slot-time { font-size: 0.85rem; color: var(--muted); min-width: 105px; }
.slot-body { flex: 1; }
.slot-title { font-weight: 600; color: #fff; font-size: 0.95rem; }
.slot-meta { font-size: 0.8rem; color: var(--muted); margin-top: 2px; }
.preview-table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 0.85rem; }
.preview-table th, .preview-table td { padding: 8px 10px; border: 1px solid var(--card-border); text-align: left; }
.preview-table th { background: #0d121d; color: var(--muted); }
.err-box { background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); border-radius: 8px; padding: 12px; margin-top: 12px; font-size: 0.85rem; color: #fca5a5; }
.succ-box { background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); border-radius: 8px; padding: 12px; margin-top: 12px; font-size: 0.85rem; color: #6ee7b7; }

/* Fixed Modal Overlay (#adminSection preserved) */
#adminSection {
  position: fixed;
  inset: 0;
  background: rgba(5, 8, 16, 0.88);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  z-index: 99999;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
  overflow-y: auto;
}
.modal-dialog {
  background: #0f172a;
  border: 1.5px solid var(--accent);
  border-radius: 16px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.85), 0 0 30px rgba(34, 211, 238, 0.2);
  width: 100%;
  max-width: 680px;
  max-height: 90vh;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  padding: 22px;
  position: relative;
}
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--card-border);
}
.modal-title {
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--accent);
  display: flex;
  align-items: center;
  gap: 8px;
}
.modal-close-btn {
  background: rgba(255,255,255,0.08);
  border: 1px solid var(--card-border);
  color: #fff;
  border-radius: 8px;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 1rem;
  transition: all 0.2s;
}
.modal-close-btn:hover {
  background: var(--danger);
  border-color: var(--danger);
}
.modal-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 18px;
  border-bottom: 1px solid var(--card-border);
  padding-bottom: 10px;
  overflow-x: auto;
}
.modal-tab-btn {
  background: rgba(13, 18, 29, 0.7);
  border: 1px solid var(--card-border);
  color: var(--muted);
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.2s;
}
.modal-tab-btn.active {
  background: rgba(34, 211, 238, 0.15);
  border-color: var(--accent);
  color: var(--accent);
}
.form-group {
  margin-bottom: 14px;
}
.form-label {
  display: block;
  font-size: 0.85rem;
  color: var(--muted);
  margin-bottom: 6px;
  font-weight: 500;
}
.form-control {
  background: #0d121d;
  color: #fff;
  border: 1px solid var(--card-border);
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 16px;
  width: 100%;
}
.form-control:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 8px rgba(34, 211, 238, 0.25);
}
.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
@media (min-width: 580px) {
  .form-grid-2 { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<div class="container">
<header>
  <div>
    <a href="/" style="color:var(--accent);text-decoration:none;font-size:0.9rem">← Kembali ke Beranda</a>
    <h1>📅 Jadwal Pelajaran SMK Silber <span class="badge-gold"><?= htmlspecialchars($todayName, ENT_QUOTES) ?></span></h1>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <span style="font-size:0.85rem;color:var(--muted)"><?= htmlspecialchars($todayDate, ENT_QUOTES) ?></span>
    <button type="button" onclick="openScheduleModal()" class="btn btn-primary" style="font-weight:700">➕ Tambah Jadwal</button>
  </div>
</header>

<div class="grid grid-2">

  <!-- Left Column: Class Timetable -->
  <div>
    <div class="card">
      <h2><span>Jadwal Harian Siswa</span><span id="liveClock" style="font-size:0.9rem;font-weight:normal;color:var(--muted)"></span></h2>
      <div class="select-group">
        <select id="classSelector" onchange="loadClassSchedule()">
          <?php foreach ($classes as $c): ?>
            <?php $sel = ((int)$c['id'] === $defaultClassId) ? 'selected' : ''; ?>
            <option value="<?= (int)$c['id'] ?>" <?= $sel ?>><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="scheduleSlots" class="slot-list">
        <div style="color:var(--muted);padding:16px;text-align:center">Memuat jadwal…</div>
      </div>
    </div>
  </div>

  <!-- Right Column: Teacher Locator & Tools -->
  <div>
    <div class="card">
      <h2>🔍 Guru Sedang di Mana?</h2>
      <p style="font-size:0.85rem;color:var(--muted);margin-bottom:12px">Cari lokasi ruang kelas guru saat ini berdasarkan jadwal.</p>
      <div class="select-group" style="flex-direction:column">
        <select id="teacherSelector" onchange="locateTeacher()">
          <option value="0">— Pilih Guru —</option>
          <?php foreach ($teachers as $t): ?>
            <?php $name = $t['full_name'] ?: $t['username']; ?>
            <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="teacherResult" style="padding:14px;background:#0d121d;border:1px solid var(--card-border);border-radius:8px;font-size:0.9rem;color:var(--muted);min-height:70px">Pilih guru untuk melihat ruangan saat ini.</div>
    </div>

    <div class="card" style="margin-top:20px">
      <h2>📥 Unduh &amp; Tambah Jadwal</h2>
      <p style="font-size:0.85rem;color:var(--muted);margin-bottom:12px">Kelola jadwal kelas secara langsung per slot pelajaran, atau unggah template format resmi Excel (.xlsx / CSV).</p>
      <button type="button" onclick="openScheduleModal('excel')" class="btn btn-primary" style="width:100%;justify-content:center;margin-bottom:10px;font-weight:700">📤 Unggah Jadwal dari Excel (otomatis dirapikan)</button>
      <button type="button" onclick="openScheduleModal()" class="btn btn-secondary" style="width:100%;justify-content:center;margin-bottom:10px">➕ Tambah / Ubah Satu Jadwal</button>
      <a href="/api/schedule/template" class="btn btn-secondary" style="width:100%;justify-content:center">⬇️ Unduh Template Format Jadwal (CSV)</a>
    </div>
  </div>

</div> <!-- End Grid -->

<!-- Modal Overlay (#adminSection preserved) -->
<div id="adminSection" style="display:<?= isset($_GET['manage']) ? 'flex' : 'none' ?>;">
  <div class="modal-dialog">
    <?php if ($canManage): ?>
      <!-- Authorized User Modal Interface -->
      <div class="modal-header">
        <div class="modal-title"><span>📅 Kelola &amp; Tambah Jadwal</span></div>
        <button type="button" onclick="closeScheduleModal()" class="modal-close-btn" title="Tutup">✕</button>
      </div>

      <div class="modal-tabs">
        <button type="button" id="tabBtnManual" class="modal-tab-btn active" onclick="switchModalTab('manual')">➕ Input Cepat (Manual)</button>
        <button type="button" id="tabBtnExcel" class="modal-tab-btn" onclick="switchModalTab('excel')">📤 Unggah Excel / CSV</button>
        <button type="button" id="tabBtnHistory" class="modal-tab-btn" onclick="switchModalTab('history')">📜 Riwayat &amp; Rollback</button>
      </div>

      <!-- Tab 1: Manual Input -->
      <div id="tabPaneManual">
        <div style="background:#0b111e;border:1px solid rgba(34,211,238,0.25);border-radius:8px;padding:12px;margin-bottom:16px;font-size:0.85rem;color:#cbd5e1">
          Tambah atau ubah satu slot mata pelajaran langsung ke sistem jadwal kelas.
        </div>

        <form id="manualSlotForm" onsubmit="event.preventDefault(); saveManualSlot();">
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Tipe Jadwal:</label>
              <select id="manualMode" class="form-control" onchange="toggleManualMode(this.value)">
                <option value="weekly">Jadwal Mingguan Reguler</option>
                <option value="override">Jadwal Pengganti (Harian / Tanggal Tertentu)</option>
              </select>
            </div>

            <div class="form-group" id="manualDayGroup">
              <label class="form-label">Hari Pelajaran:</label>
              <select id="manualDay" class="form-control">
                <?php foreach (Schedule::DAYS as $dNum => $dName): ?>
                  <?php $selD = ($dNum === $todayDayOfWeek) ? 'selected' : ''; ?>
                  <option value="<?= $dNum ?>" <?= $selD ?>><?= htmlspecialchars($dName, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group" id="manualDateGroup" style="display:none">
              <label class="form-label">Tanggal Pengganti:</label>
              <input type="date" id="manualDate" class="form-control" value="<?= htmlspecialchars($todayDate, ENT_QUOTES) ?>">
            </div>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Jam Pelajaran:</label>
              <select id="manualPeriod" class="form-control">
                <?php foreach ($timeSlots as $ts): ?>
                  <?php $lbl = 'Jam Ke-' . $ts['period_number'] . ' (' . $ts['start_time'] . ' – ' . $ts['end_time'] . ')'; ?>
                  <option value="<?= (int)$ts['period_number'] ?>"><?= htmlspecialchars($lbl, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Kelas Siswa:</label>
              <select id="manualClass" class="form-control">
                <?php foreach ($classes as $c): ?>
                  <?php $sel = ((int)$c['id'] === $defaultClassId) ? 'selected' : ''; ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $sel ?>><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Mata Pelajaran (Mapel):</label>
              <select id="manualSubject" class="form-control" onchange="toggleCustomSubject(this.value)">
                <option value="0">— Pilih Mata Pelajaran —</option>
                <?php foreach ($subjects as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name'], ENT_QUOTES) ?> (<?= htmlspecialchars($s['code'], ENT_QUOTES) ?>)</option>
                <?php endforeach; ?>
                <option value="__new__">➕ Tambah / Ketik Mapel Baru…</option>
              </select>
              <input type="text" id="manualCustomSubject" class="form-control" placeholder="Ketik nama mapel baru…" style="display:none;margin-top:8px">
            </div>

            <div class="form-group">
              <label class="form-label">Guru Pengajar:</label>
              <select id="manualTeacher" class="form-control">
                <option value="0">— Belum Ditentukan / Guru Umum —</option>
                <?php foreach ($teachers as $t): ?>
                  <?php $name = $t['full_name'] ?: $t['username']; ?>
                  <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Ruangan Kelas:</label>
              <select id="manualRoom" class="form-control">
                <option value="0">— Ruang Standar Kelas —</option>
                <?php foreach ($rooms as $r): ?>
                  <?php $rLabel = $r['name'] . ' (' . $r['code'] . ' - Lt.' . $r['floor'] . ')'; ?>
                  <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($rLabel, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Keterangan / Catatan (Opsional):</label>
              <input type="text" id="manualNotes" class="form-control" placeholder="Misal: Teori Bab 3, Praktik Lab, dll.">
            </div>
          </div>

          <div style="margin-top:16px">
            <button type="submit" id="btnSaveManual" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;font-weight:700">💾 Simpan Jadwal Pelajaran</button>
          </div>
          <div id="manualSlotResult"></div>
        </form>
      </div>

      <!-- Tab 2: Excel / CSV Upload -->
      <div id="tabPaneExcel" style="display:none">
        <ol class="up-steps"><li><b>Pilih file</b> Excel / CSV</li><li><b>Cek pratinjau</b> (otomatis)</li><li><b>Terapkan</b></li></ol>
        <label class="up-drop" id="upDrop" for="scheduleFile">
          <input type="file" id="scheduleFile" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" onchange="previewUpload()">
          <span class="up-drop__icon" aria-hidden="true">📂</span>
          <b id="upDropTitle">Tarik file Excel ke sini, atau ketuk untuk memilih</b>
          <small>.xlsx atau .csv · tidak perlu rapi — urutan kolom acak, ada kop judul, sel digabung, salah ketik hari/jam, "jam ke 1-2" semuanya dirapikan otomatis</small>
        </label>
        <details class="up-more"><summary>⚙️ Pengaturan &amp; contoh format (opsional)</summary>
          <label class="form-label" for="importMode">Jenis jadwal yang diunggah:</label>
          <select id="importMode" class="form-control">
            <option value="weekly">Jadwal mingguan tetap (Senin–Sabtu)</option>
            <option value="override">Perubahan untuk tanggal tertentu (guru pengganti, dll.)</option>
          </select>
          <p style="font-size:.82rem;color:var(--muted);margin:8px 0 0">Kolom yang dikenali (nama kolom bebas, mis. "Hari", "Jam Ke", "Mulai", "Selesai", "Kelas", "Mapel", "Guru", "Ruang", "Ket"). Contoh: <a href="/api/schedule/template">⬇️ unduh template CSV</a>.</p>
        </details>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <button type="button" id="btnPreview" onclick="previewUpload()" class="btn btn-secondary" style="display:none">🔁 Periksa ulang</button>
          <button type="button" id="btnApply" onclick="applySchedule()" class="btn btn-secondary" disabled style="display:none;background:#10b981;color:#fff;font-weight:700;font-size:1rem;padding:12px 20px">✅ Terapkan Jadwal Baru</button>
        </div>

        <div id="previewResult" style="margin-top:16px"></div>
      </div>

      <!-- Tab 3: History & Rollback -->
      <div id="tabPaneHistory" style="display:none">
        <p style="font-size:0.85rem;color:var(--muted);margin-bottom:12px">Daftar riwayat versi jadwal yang diterapkan. Anda dapat membatalkan (rollback) kapan saja bila terjadi kesalahan input.</p>
        <div id="versionList" style="font-size:0.85rem;color:var(--muted)">Memuat riwayat versi…</div>
      </div>

    <?php else: ?>
      <!-- Guest / Unauthorized Prompt -->
      <div class="modal-header">
        <div class="modal-title"><span>🔒 Akses Tambah Jadwal</span></div>
        <button type="button" onclick="closeScheduleModal()" class="modal-close-btn" title="Tutup">✕</button>
      </div>

      <div style="text-align:center;padding:24px 12px">
        <div style="font-size:3.2rem;margin-bottom:14px">🔒</div>
        <h3 style="font-size:1.2rem;color:#fff;margin-bottom:10px">Akses Kelola Jadwal Diperlukan</h3>
        <p style="font-size:0.9rem;color:var(--muted);margin-bottom:24px;line-height:1.6;max-width:440px;margin-left:auto;margin-right:auto">
          Untuk menambah, mengubah, atau mengunggah jadwal pelajaran sekolah, silakan login dengan akun <strong>Guru</strong>, <strong>Wali Kelas</strong>, atau <strong>Administrator</strong>.
        </p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
          <a href="/login?redirect=/jadwal" class="btn btn-primary" style="font-weight:700;padding:10px 22px">🔑 Masuk / Login Sekarang</a>
          <button type="button" onclick="closeScheduleModal()" class="btn btn-secondary" style="padding:10px 20px">Tutup</button>
        </div>
      </div>
    <?php endif; ?>

  </div> <!-- End modal-dialog -->
</div> <!-- End adminSection -->

<?= Footer::html() ?>
</div> <!-- End container -->

<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES) ?>">
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
let validatedData = null;

function updateClock() {
  const now = new Date();
  const timeStr = now.toLocaleTimeString("id-ID", {hour: "2-digit", minute:"2-digit"});
  const el = document.getElementById("liveClock");
  if (el) el.textContent = "Waktu sekarang: " + timeStr + " WIB";
}
setInterval(updateClock, 1000);
updateClock();

function openScheduleModal(tab = "manual") {
  const el = document.getElementById("adminSection");
  if (!el) return;
  el.style.display = "flex";
  document.body.style.overflow = "hidden";
  if (CAN_MANAGE) {
    switchModalTab(tab);
    // Sync class selector to current active class
    const mainClass = document.getElementById("classSelector");
    const manualClass = document.getElementById("manualClass");
    if (mainClass && manualClass && !manualClass.dataset.userTouched) {
      manualClass.value = mainClass.value;
    }
  }
}

function closeScheduleModal() {
  const el = document.getElementById("adminSection");
  if (!el) return;
  el.style.display = "none";
  document.body.style.overflow = "";
}

// Preserve existing function name for backward compatibility
function toggleUploadModal(tab = "manual") {
  const el = document.getElementById("adminSection");
  if (!el) return;
  if (el.style.display === "none" || el.style.display === "") {
    openScheduleModal(tab);
  } else {
    closeScheduleModal();
  }
}

// Close on backdrop click & ESC key
document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("adminSection");
  if (modal) {
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeScheduleModal();
    });
  }
});
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") closeScheduleModal();
});

function switchModalTab(tab) {
  const tabs = ["manual", "excel", "history"];
  tabs.forEach(t => {
    const btn = document.getElementById("tabBtn" + t.charAt(0).toUpperCase() + t.slice(1));
    const pane = document.getElementById("tabPane" + t.charAt(0).toUpperCase() + t.slice(1));
    if (btn) btn.classList.toggle("active", t === tab);
    if (pane) pane.style.display = (t === tab) ? "block" : "none";
  });
  if (tab === "history") loadVersions();
}

function toggleManualMode(val) {
  const dayGroup = document.getElementById("manualDayGroup");
  const dateGroup = document.getElementById("manualDateGroup");
  if (dayGroup && dateGroup) {
    if (val === "override") {
      dayGroup.style.display = "none";
      dateGroup.style.display = "block";
    } else {
      dayGroup.style.display = "block";
      dateGroup.style.display = "none";
    }
  }
}

function toggleCustomSubject(val) {
  const customInput = document.getElementById("manualCustomSubject");
  if (!customInput) return;
  if (val === "__new__") {
    customInput.style.display = "block";
    customInput.focus();
  } else {
    customInput.style.display = "none";
    customInput.value = "";
  }
}

async function saveManualSlot() {
  const btn = document.getElementById("btnSaveManual");
  const resBox = document.getElementById("manualSlotResult");
  const mode = document.getElementById("manualMode").value;
  const day = document.getElementById("manualDay").value;
  const date = document.getElementById("manualDate").value;
  const period = document.getElementById("manualPeriod").value;
  const classId = document.getElementById("manualClass").value;
  const subjVal = document.getElementById("manualSubject").value;
  const customSubj = document.getElementById("manualCustomSubject").value.trim();
  const teacherId = document.getElementById("manualTeacher").value;
  const roomId = document.getElementById("manualRoom").value;
  const notes = document.getElementById("manualNotes").value.trim();

  if (subjVal === "0" || (subjVal === "__new__" && customSubj === "")) {
    resBox.innerHTML = "<div class=\"err-box\">Pilih mata pelajaran atau ketik nama mapel baru.</div>";
    return;
  }

  btn.disabled = true;
  btn.textContent = "Menyimpan…";
  resBox.innerHTML = "<div style=\"color:var(--muted);padding:8px\">Sedang memvalidasi bentrok &amp; menyimpan…</div>";

  try {
    const res = await fetch("/api/schedule/slot/save", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": CSRF_TOKEN },
      body: JSON.stringify({
        mode: mode,
        day_of_week: day,
        target_date: date,
        period_number: period,
        class_id: classId,
        subject_id: (subjVal === "__new__") ? 0 : subjVal,
        subject_name: customSubj,
        teacher_user_id: teacherId,
        room_id: roomId,
        notes: notes,
        _csrf: CSRF_TOKEN
      })
    });
    const data = await res.json();
    if (data.status === "success") {
      resBox.innerHTML = "<div class=\"succ-box\">✅ " + data.message + "</div>";
      loadClassSchedule();
      setTimeout(() => {
        closeScheduleModal();
        resBox.innerHTML = "";
      }, 1200);
    } else {
      resBox.innerHTML = "<div class=\"err-box\">❌ " + (data.message || "Gagal menyimpan jadwal.") + "</div>";
    }
  } catch(e) {
    resBox.innerHTML = "<div class=\"err-box\">Terjadi kesalahan koneksi ke server.</div>";
  }
  btn.disabled = false;
  btn.textContent = "💾 Simpan Jadwal Pelajaran";
}

function quickAddSlot(period, dayOfWeek, classId, date) {
  openScheduleModal("manual");
  if (period) document.getElementById("manualPeriod").value = period;
  if (classId) document.getElementById("manualClass").value = classId;
  if (dayOfWeek) {
    document.getElementById("manualDay").value = dayOfWeek;
    document.getElementById("manualMode").value = "weekly";
    toggleManualMode("weekly");
  }
  if (date) document.getElementById("manualDate").value = date;
}

function quickEditSlot(period, dayOfWeek, classId, subjectId, teacherId, roomId, notes, isOverride, date) {
  openScheduleModal("manual");
  document.getElementById("manualPeriod").value = period;
  document.getElementById("manualClass").value = classId;
  if (isOverride) {
    document.getElementById("manualMode").value = "override";
    toggleManualMode("override");
    if (date) document.getElementById("manualDate").value = date;
  } else {
    document.getElementById("manualMode").value = "weekly";
    toggleManualMode("weekly");
    if (dayOfWeek) document.getElementById("manualDay").value = dayOfWeek;
  }
  if (subjectId) {
    document.getElementById("manualSubject").value = subjectId;
    toggleCustomSubject(subjectId);
  }
  if (teacherId) document.getElementById("manualTeacher").value = teacherId;
  if (roomId) document.getElementById("manualRoom").value = roomId;
  document.getElementById("manualNotes").value = notes || "";
}

async function quickDeleteSlot(period, dayOfWeek, classId, isOverride, date) {
  const typeStr = isOverride ? "jadwal pengganti" : "jadwal mingguan";
  if (!confirm("Yakin ingin menghapus " + typeStr + " jam ke-" + period + " untuk kelas ini?")) return;
  try {
    const res = await fetch("/api/schedule/slot/delete", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": CSRF_TOKEN },
      body: JSON.stringify({
        mode: isOverride ? "override" : "weekly",
        period_number: period,
        class_id: classId,
        day_of_week: dayOfWeek,
        target_date: date,
        _csrf: CSRF_TOKEN
      })
    });
    const data = await res.json();
    alert(data.message || "Slot berhasil dihapus.");
    loadClassSchedule();
  } catch(e) {
    alert("Gagal menghapus slot jadwal.");
  }
}

async function loadClassSchedule() {
  const classId = document.getElementById("classSelector").value;
  const container = document.getElementById("scheduleSlots");
  container.innerHTML = "<div style=\"color:var(--muted);padding:16px;text-align:center\">Memuat jadwal…</div>";
  try {
    const res = await fetch("/api/schedule/today?class_id=" + classId);
    const data = await res.json();
    if (!data.schedule || data.schedule.length === 0) {
      container.innerHTML = "<div style=\"color:var(--muted);padding:16px;text-align:center\">Belum ada jadwal untuk kelas ini.</div>";
      return;
    }
    const nowTime = new Date().toLocaleTimeString("id-ID", {hour:"2-digit", minute:"2-digit"});
    let html = "";
    data.schedule.forEach(slot => {
      const isNow = (slot.is_current !== undefined) ? !!slot.is_current : (nowTime >= slot.start_time && nowTime <= slot.end_time);
      const isBreak = slot.is_break;
      let badge = "";
      if (isNow) badge = "<span style=\"color:var(--accent);font-size:0.75rem;font-weight:bold;background:rgba(34,211,238,0.15);padding:2px 6px;border-radius:4px;border:1px solid rgba(34,211,238,0.4)\">● SEDANG BERLANGSUNG</span>";
      if (slot.entry && slot.entry.is_override) badge += " <span class=\"badge-override\">PENGGANTI</span>";

      html += "<div class=\"slot-item " + (isBreak ? "is-break " : "") + (isNow ? "is-now " : "") + "\">";
      html += "<div class=\"slot-time\"><strong>" + slot.label + "</strong><br>" + slot.start_time + " – " + slot.end_time + "</div>";
      html += "<div class=\"slot-body\">";
      if (isBreak) {
        html += "<div class=\"slot-title\" style=\"color:var(--muted)\">" + slot.label + "</div>";
      } else if (slot.entry) {
        html += "<div class=\"slot-title\">" + slot.entry.subject_name + " " + badge + "</div>";
        html += "<div class=\"slot-meta\">👤 Guru: " + (slot.entry.teacher_name || slot.entry.teacher_username || "Belum ditentukan") + " • 📍 " + (slot.entry.room_name || "Ruang Kelas") + (slot.entry.notes ? " • <i>" + slot.entry.notes + "</i>" : "") + "</div>";
      } else {
        html += "<div class=\"slot-title\" style=\"color:var(--muted);font-weight:normal\">Kosong / Belum Diisi</div>";
      }
      html += "</div>";

      // Quick actions for teachers/admins
      if (CAN_MANAGE && !isBreak) {
        if (slot.entry) {
          const notesSafe = (slot.entry.notes || "").replace(/'/g, "\\'");
          html += "<div style=\"display:flex;gap:6px;align-items:center;margin-left:8px\">";
          html += "<button type=\"button\" onclick=\"quickEditSlot(" + slot.period_number + ", " + (data.day_of_week || 1) + ", " + classId + ", " + slot.entry.subject_id + ", " + (slot.entry.teacher_user_id || 0) + ", " + (slot.entry.room_id || 0) + ", '" + notesSafe + "', " + (slot.entry.is_override ? "true" : "false") + ", '" + (data.date || "") + "')\" class=\"btn btn-secondary\" style=\"padding:4px 8px;font-size:0.75rem\" title=\"Ubah slot ini\">✏️ Edit</button>";
          html += "<button type=\"button\" onclick=\"quickDeleteSlot(" + slot.period_number + ", " + (data.day_of_week || 1) + ", " + classId + ", " + (slot.entry.is_override ? "true" : "false") + ", '" + (data.date || "") + "')\" class=\"btn btn-danger\" style=\"padding:4px 8px;font-size:0.75rem\" title=\"Hapus slot ini\">🗑️</button>";
          html += "</div>";
        } else {
          html += "<div style=\"margin-left:8px\">";
          html += "<button type=\"button\" onclick=\"quickAddSlot(" + slot.period_number + ", " + (data.day_of_week || 1) + ", " + classId + ", '" + (data.date || "") + "')\" class=\"btn btn-primary\" style=\"padding:4px 10px;font-size:0.75rem;font-weight:600\">➕ Isi Jadwal</button>";
          html += "</div>";
        }
      }

      html += "</div>";
    });
    container.innerHTML = html;
  } catch(e) {
    container.innerHTML = "<div style=\"color:var(--danger);padding:16px;text-align:center\">Gagal memuat jadwal.</div>";
  }
}

async function locateTeacher() {
  const teacherId = document.getElementById("teacherSelector").value;
  const resEl = document.getElementById("teacherResult");
  if (teacherId === "0") {
    resEl.innerHTML = "Pilih guru untuk melihat ruangan saat ini.";
    return;
  }
  resEl.innerHTML = "Mengecek lokasi…";
  try {
    const res = await fetch("/api/schedule/whereis?teacher_id=" + teacherId);
    const data = await res.json();
    if (data.status === "in_class") {
      resEl.innerHTML = "<strong style=\"color:#fff\">Sedang Mengajar:</strong><br>" +
        "📚 Mapel: " + data.subject_name + "<br>" +
        "👥 Kelas: " + data.class_name + "<br>" +
        "📍 <strong>" + data.room_name + "</strong> (Lantai " + data.room_floor + ")<br>" +
        "<span style=\"color:var(--accent);font-size:0.8rem\">Jam ke-" + data.period + " (" + data.current_time + " WIB)</span>";
    } else {
      resEl.innerHTML = "<strong>Status:</strong> " + data.message;
    }
  } catch(e) {
    resEl.innerHTML = "Gagal memuat status guru.";
  }
}

(function () {
  const drop = document.getElementById("upDrop"), inp = document.getElementById("scheduleFile");
  if (!drop || !inp) return;
  ["dragenter", "dragover"].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add("is-over"); }));
  ["dragleave", "drop"].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove("is-over"); }));
  drop.addEventListener("drop", e => {
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (!f) return;
    if (!/\.(xlsx|csv)$/i.test(f.name)) { alert("Format belum didukung. Simpan ulang sebagai .xlsx atau .csv (Excel: File → Save As)."); return; }
    const dt = new DataTransfer(); dt.items.add(f); inp.files = dt.files;
    previewUpload();
  });
})();

async function previewUpload() {
  const fileInput = document.getElementById("scheduleFile");
  if (!fileInput.files || fileInput.files.length === 0) {
    alert("Pilih berkas CSV atau Excel (.xlsx) jadwal terlebih dahulu.");
    return;
  }
  const t = document.getElementById("upDropTitle");
  if (t) t.textContent = "📄 " + fileInput.files[0].name + " — ketuk untuk ganti file";
  const rp = document.getElementById("btnPreview"); if (rp) rp.style.display = "inline-flex";
  const mode = document.getElementById("importMode").value;
  const formData = new FormData();
  formData.append("file", fileInput.files[0]);
  formData.append("mode", mode);
  formData.append("_csrf", CSRF_TOKEN);

  const previewBox = document.getElementById("previewResult");
  const btnApply = document.getElementById("btnApply");
  previewBox.innerHTML = "Memeriksa validitas data &amp; bentrok jadwal…";

  try {
    const res = await fetch("/api/schedule/preview", { method: "POST", body: formData });
    const data = await res.json();
    const esc = (v) => String(v == null ? "" : v).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#39;" }[c]));
    if (data.status === "error") {
      previewBox.innerHTML = "<div class=\"err-box\">" + esc(data.message) + "</div>";
      btnApply.style.display = "none";
      return;
    }

    validatedData = data;
    let out = "<div style=\"background:#0d121d;border:1px solid var(--card-border);border-radius:8px;padding:12px\">";
    out += "<strong>Ringkasan Validasi:</strong><br>";
    out += "• Total baris jadwal: " + data.valid_count + " siap dipakai<br>";
    out += "• Diperbaiki otomatis: <span style=\"color:#38bdf8;font-weight:bold\">" + (data.fix_count || 0) + "</span> perbaikan<br>";
    out += "• Baris error: <span style=\"color:" + (data.error_count > 0 ? "var(--danger)" : "var(--success)") + "\">" + data.error_count + "</span><br>";
    out += "• Bentrok jadwal terdeteksi: <strong style=\"color:" + (data.conflict_count > 0 ? "var(--danger)" : "var(--success)") + "\">" + data.conflict_count + "</strong>";
    if (data.error_count === 0) out += "<br><strong style=\"color:var(--success)\">✅ 100% baris berhasil dibaca.</strong>";
    out += "</div>";

    if (data.fixes && data.fixes.length > 0) {
      out += "<details style=\"margin-top:10px;background:#0d121d;border:1px solid #1e3a5f;border-radius:8px;padding:10px\"><summary style=\"cursor:pointer;color:#38bdf8;font-weight:bold\">🔧 Lihat " + data.fixes.length + " perbaikan otomatis</summary><div style=\"max-height:260px;overflow:auto;font-size:.85rem;margin-top:6px\">";
      data.fixes.forEach(f => { out += "• " + esc(f) + "<br>"; });
      out += "</div></details>";
    }

    if (data.conflicts && data.conflicts.length > 0) {
      out += "<div class=\"err-box\"><strong>⚠️ Peringatan Bentrok Jadwal:</strong><br>";
      data.conflicts.forEach(c => { out += "• " + esc(c) + "<br>"; });
      out += "</div>";
    }

    if (data.errors && data.errors.length > 0) {
      out += "<div class=\"err-box\"><strong>❌ Kesalahan Baris (Akan dilewati):</strong><br>";
      data.errors.forEach(e => { out += "• Baris " + esc(e.line) + ": " + esc(e.errors.join(", ")) + "<br>"; });
      out += "</div>";
    }

    previewBox.innerHTML = out;
    if (data.valid_count > 0) {
      btnApply.disabled = false;
      btnApply.style.display = "inline-flex";
      btnApply.textContent = "✅ Terapkan " + data.valid_count + " jadwal";
      btnApply.scrollIntoView({ behavior: "smooth", block: "center" });
    }
  } catch(e) {
    previewBox.innerHTML = "<div class=\"err-box\">Gagal melakukan validasi file jadwal.</div>";
  }
}

async function applySchedule() {
  if (!validatedData || !validatedData.valid_rows) return;
  const btnApply = document.getElementById("btnApply");
  btnApply.disabled = true;
  btnApply.textContent = "Menerapkan…";

  const mode = document.getElementById("importMode").value;
  const fileInput = document.getElementById("scheduleFile");
  const filename = fileInput.files[0] ? fileInput.files[0].name : "jadwal.csv";

  try {
    const res = await fetch("/api/schedule/apply", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": CSRF_TOKEN },
      body: JSON.stringify({
        mode: mode,
        filename: filename,
        valid_rows: validatedData.valid_rows,
        _csrf: CSRF_TOKEN
      })
    });
    const result = await res.json();
    alert(result.message || "Jadwal berhasil diterapkan.");
    if (result.status === "success") {
      loadClassSchedule();
      loadVersions();
      document.getElementById("previewResult").innerHTML = "";
      btnApply.style.display = "none";
      closeScheduleModal();
    }
  } catch(e) {
    alert("Gagal menerapkan jadwal.");
  }
  btnApply.disabled = false;
  btnApply.textContent = "✅ Terapkan Jadwal Baru";
}

async function loadVersions() {
  const container = document.getElementById("versionList");
  if (!container) return;
  try {
    const res = await fetch("/api/schedule/versions");
    const list = await res.json();
    if (!list || list.length === 0) {
      container.innerHTML = "Belum ada riwayat unggahan jadwal.";
      return;
    }
    let html = "<table class=\"preview-table\"><tr><th>ID</th><th>Waktu</th><th>Mode</th><th>Baris</th><th>Status</th><th>Aksi</th></tr>";
    list.forEach(v => {
      const isRollback = (v.status === "rolled_back");
      html += "<tr>";
      html += "<td>#" + v.id + "</td>";
      html += "<td>" + v.created_at + "</td>";
      html += "<td>" + v.mode + "</td>";
      html += "<td>" + v.valid_rows + "</td>";
      html += "<td>" + (isRollback ? "<span style=\"color:var(--danger)\">Di-rollback</span>" : "<span style=\"color:var(--success)\">Aktif</span>") + "</td>";
      html += "<td>" + (isRollback ? "—" : "<button type=\"button\" onclick=\"rollbackVersion(" + v.id + ")\" class=\"btn btn-danger\" style=\"padding:2px 8px;font-size:0.75rem\">Rollback</button>") + "</td>";
      html += "</tr>";
    });
    html += "</table>";
    container.innerHTML = html;
  } catch(e) {
    container.innerHTML = "Gagal memuat riwayat versi.";
  }
}

async function rollbackVersion(versionId) {
  if (!confirm("Yakin ingin membatalkan (rollback) versi jadwal #" + versionId + "?")) return;
  try {
    const res = await fetch("/api/schedule/rollback", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": CSRF_TOKEN },
      body: JSON.stringify({ version_id: versionId, _csrf: CSRF_TOKEN })
    });
    const data = await res.json();
    alert(data.message || "Rollback selesai.");
    loadClassSchedule();
    loadVersions();
  } catch(e) {
    alert("Gagal melakukan rollback.");
  }
}

// Initial load & auto-refresh every 60s
loadClassSchedule();
setInterval(loadClassSchedule, 60000);
</script>
</body>
</html>
<?php
    }
}
