<?php
// LearningController: Web UI for virtual classrooms, assignment submission, and in-browser quiz player.
declare(strict_types=1);

namespace VC\Controllers;

use VC\Database;
use VC\Domain\ClassroomAccess;
use VC\Domain\Events;
use VC\Domain\Learning;
use VC\Security\Auth;
use VC\Security\Csrf;
use VC\Security\Footer;
use VC\Security\Headers;
use VC\Security\Permissions;
use VC\Security\Pwa;

final class LearningController
{
    /** Render virtual classroom page (/kelas or /tugas) */
    public static function renderClassroomPage(): void
    {
        // If specific assignment requested, render detail & submission view
        if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
            self::renderAssignmentDetail((int)$_GET['id']);
            return;
        }

        $nonce = Headers::nonce();
        $user = Auth::user();
        $csrf = Csrf::token();
        $pdo = Database::get();

        $isTeacher = $user && in_array($user['role'] ?? '', ['guru', 'wali_kelas', 'kepala_program', 'kepala_sekolah', 'root'], true);
        $isStaffAll = $user && Permissions::can('user.view');

        // Determine active class
        $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : ($user ? (int)($user['class_id'] ?? 1) : 1);
        if ($classId <= 0) $classId = 1;

        $cStmt = $pdo->prepare('SELECT name FROM classes WHERE id = ?');
        $cStmt->execute([$classId]);
        $currentClassName = (string)($cStmt->fetchColumn() ?: 'Kelas #' . $classId);

        $materials = Learning::getMaterialsByClass($classId);
        // Murid: tugas kelasnya + tugas yang dikirim guru ke server/room yang ia ikuti.
        $assignments = ($user && !$isTeacher)
            ? Learning::getAssignmentsForStudent((int)$user['id'], $classId)
            : Learning::getAssignmentsByClass($classId);
        $quizzes = Learning::getQuizzesByClass($classId);

        // Student's own submissions
        $studentSubmissions = [];
        if ($user && !$isTeacher) {
            $studentSubmissions = Learning::getStudentSubmissions((int)$user['id']);
        }

        // Teacher's assignments
        $teacherAssignments = [];
        $teacherClasses = [];
        $allSubjects = [];
        if ($isTeacher) {
            $teacherAssignments = Learning::getTeacherAssignments((int)$user['id']);
            if ($isStaffAll) {
                $teacherClasses = $pdo->query('SELECT id, name FROM classes WHERE deleted_at IS NULL ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
            } else {
                $tcStmt = $pdo->prepare('SELECT DISTINCT c.id, c.name FROM schedule_entries se JOIN classes c ON se.class_id = c.id WHERE se.teacher_user_id = ? AND c.deleted_at IS NULL ORDER BY c.name');
                $tcStmt->execute([(int)$user['id']]);
                $teacherClasses = $tcStmt->fetchAll(\PDO::FETCH_ASSOC);
                if (empty($teacherClasses)) {
                    $teacherClasses = $pdo->query('SELECT id, name FROM classes WHERE deleted_at IS NULL ORDER BY name LIMIT 10')->fetchAll(\PDO::FETCH_ASSOC);
                }
            }
            $allSubjects = $pdo->query('SELECT id, code, name FROM subjects ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
        }

        // Map submissions by assignment_id for easy lookup by student
        $mySubMap = [];
        foreach ($studentSubmissions as $sub) {
            $mySubMap[(int)$sub['assignment_id']] = $sub;
        }

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo Pwa::headTags();
        echo '<title>Ruang Kelas Virtual & Tugas — SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">
:root { --bg: #090d16; --card: #131a29; --card-hover: #192237; --border: #1f293d; --accent: #22d3ee; --gold: #f59e0b; --green: #10b981; --red: #ef4444; }
body { font-family: system-ui, sans-serif; background: var(--bg); color: #e2e8f0; margin: 0; padding: 16px; }
@media (max-width: 600px) { body { padding: 10px; } }
.container { max-width: 1100px; margin: 0 auto; width: 100%; box-sizing: border-box; }
header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
h1 { font-size: clamp(1.15rem, 3.5vw, 1.4rem); color: #fff; margin: 4px 0; }
.tab-bar { display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px; overflow-x: auto; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
.tab-bar::-webkit-scrollbar { display: none; }
.tab-btn { background: var(--card); border: 1px solid var(--border); color: #94a3b8; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 600; white-space: nowrap; font-size: 0.85rem; }
.tab-btn.active { background: var(--accent); color: #000; border-color: var(--accent); font-weight: bold; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 12px; transition: transform 0.15s, border-color 0.15s; }
.card:hover { border-color: rgba(34,211,238,0.4); }
.badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 0.75rem; font-weight: bold; background: rgba(34,211,238,0.15); color: var(--accent); border: 1px solid rgba(34,211,238,0.3); }
.badge-gold { background: rgba(245,158,11,0.15); color: var(--gold); border-color: rgba(245,158,11,0.3); }
.badge-green { background: rgba(16,185,129,0.15); color: var(--green); border-color: rgba(16,185,129,0.3); }
.badge-red { background: rgba(239,68,68,0.15); color: var(--red); border-color: rgba(239,68,68,0.3); }
.btn { background: var(--accent); color: #000; padding: 8px 14px; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-block; border: none; cursor: pointer; font-size: 0.85rem; }
.btn-sec { background: #1e293b; color: #fff; border: 1px solid var(--border); }
.btn-sec:hover { background: #334155; }
.btn-gold { background: linear-gradient(135deg, #f59e0b, #d97706); color: #000; }
.form-input { width: 100%; box-sizing: border-box; background: #0c111c; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; color: #fff; font-size: 0.9rem; margin-top: 4px; }
.form-input:focus { outline: none; border-color: var(--accent); }
.form-label { font-size: 0.82rem; font-weight: 600; color: #94a3b8; margin-top: 10px; display: block; }
.table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; margin-top: 8px; }
table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 520px; }
th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
th { color: #94a3b8; font-weight: 600; background: #0c111c; }
</style></head><body>';
        echo '<div class="container">';
        echo '<header><div><a href="/" style="color:var(--accent);text-decoration:none;font-size:0.9rem">← Beranda</a><h1>📚 Ruang Kelas & Tugas Akademik</h1></div>';
        
        echo '<div style="display:flex;align-items:center;gap:8px">';
        if ($isTeacher) {
            echo '<span class="badge badge-gold">👨‍🏫 Portal Pengajar</span>';
            if (!empty($teacherClasses)) {
                echo '<select onchange="location.href=\'/kelas?class_id=\'+this.value" class="form-input" style="width:auto;margin:0;padding:6px 12px">';
                foreach ($teacherClasses as $tc) {
                    $sel = ((int)$tc['id'] === $classId) ? ' selected' : '';
                    echo '<option value="' . (int)$tc['id'] . '"' . $sel . '>Kelas ' . htmlspecialchars($tc['name'], ENT_QUOTES) . '</option>';
                }
                echo '</select>';
            }
        } else {
            echo '<span class="badge badge-green">Kelas: ' . htmlspecialchars($currentClassName, ENT_QUOTES) . '</span>';
        }
        echo '</div></header>';

        echo '<div class="tab-bar">';
        if ($isTeacher) {
            echo '<button class="tab-btn active" onclick="showTab(\'kelola_tugas\')">📝 Tugas Buatan Saya (' . count($teacherAssignments) . ')</button>';
            echo '<button class="tab-btn" onclick="showTab(\'buat_tugas\')">➕ Buat Tugas Baru</button>';
            echo '<button class="tab-btn" onclick="showTab(\'tugas\')">📋 Tugas Kelas ' . htmlspecialchars($currentClassName, ENT_QUOTES) . ' (' . count($assignments) . ')</button>';
            echo '<button class="tab-btn" onclick="showTab(\'materi\')">📖 Materi (' . count($materials) . ')</button>';
            echo '<button class="tab-btn" onclick="showTab(\'tambah_materi\')">➕ Tambah Materi</button>';
            echo '<button class="tab-btn" onclick="showTab(\'kuis\')">⏱️ Kuis (' . count($quizzes) . ')</button>';
            echo '<button class="tab-btn" onclick="showTab(\'server\')">🎮 Server Kelas 3D</button>';
        } else {
            echo '<button class="tab-btn active" onclick="showTab(\'tugas\')">📝 Tugas Kelas (' . count($assignments) . ')</button>';
            echo '<button class="tab-btn" onclick="showTab(\'tugas_saya\')">📤 Tugas Saya & Nilai (' . count($studentSubmissions) . ')</button>';
            echo '<button class="tab-btn" onclick="showTab(\'materi\')">📖 Materi (' . count($materials) . ')</button>';
            echo '<button class="tab-btn" onclick="showTab(\'kuis\')">⏱️ Kuis Interaktif (' . count($quizzes) . ')</button>';
            echo '<button class="tab-btn" onclick="showTab(\'server\')">🎮 Server Kelas 3D</button>';
        }
        echo '</div>';

        // TEACHER TAB: KELOLA TUGAS BUATAN SAYA
        if ($isTeacher) {
            echo '<div id="tab-kelola_tugas">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
            echo '<h2 style="font-size:1.1rem;color:#fff;margin:0">Daftar Tugas Yang Anda Terbitkan</h2>';
            echo '<button class="btn btn-gold" onclick="showTab(\'buat_tugas\')">➕ Terbitkan Tugas Baru</button>';
            echo '</div>';

            if (empty($teacherAssignments)) {
                echo '<div class="card" style="text-align:center;padding:32px 16px;color:#94a3b8">';
                echo '<div style="font-size:2rem;margin-bottom:8px">📝</div>';
                echo '<p style="margin:0 0 12px">Anda belum menerbitkan tugas apa pun.</p>';
                echo '<button class="btn" onclick="showTab(\'buat_tugas\')">Buat Tugas Pertama</button>';
                echo '</div>';
            } else {
                foreach ($teacherAssignments as $tasg) {
                    $subCount = (int)$tasg['submission_count'];
                    $gradCount = (int)$tasg['graded_count'];
                    echo '<div class="card">';
                    echo '<div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px">';
                    echo '<div>';
                    echo '<h3 style="color:#fff;margin:0 0 6px 0">' . htmlspecialchars($tasg['title'], ENT_QUOTES) . '</h3>';
                    echo '<span class="badge badge-gold">Kelas: ' . htmlspecialchars($tasg['class_name'], ENT_QUOTES) . '</span> ';
                    if (!empty($tasg['server_name'])) {
                        echo '<span class="badge">' . (($tasg['server_world'] ?? '3d') === 'pixel' ? '🧱' : '🚀') . ' Room: ' . htmlspecialchars((string)$tasg['server_name'], ENT_QUOTES) . ' · kode ' . htmlspecialchars((string)$tasg['server_code'], ENT_QUOTES) . '</span> ';
                    }
                    echo '<span class="badge">' . htmlspecialchars($tasg['subject_name'], ENT_QUOTES) . '</span> ';
                    echo '<span style="font-size:0.8rem;color:#94a3b8">Tenggat: ' . htmlspecialchars($tasg['deadline'], ENT_QUOTES) . '</span>';
                    echo '</div>';
                    echo '<div style="display:flex;gap:8px;align-items:center">';
                    echo '<span class="badge ' . ($gradCount === $subCount && $subCount > 0 ? 'badge-green' : 'badge-gold') . '">' . $subCount . ' Pengumpulan (' . $gradCount . ' Dinilai)</span>';
                    echo '<a href="/tugas?id=' . (int)$tasg['id'] . '" class="btn">Periksa & Nilai →</a>';
                    echo '<button type="button" class="btn btn-sec" onclick="vcDeleteTask(' . (int)$tasg['id'] . ')" style="color:#ef4444;border-color:rgba(239,68,68,0.4)" title="Hapus Tugas">🗑️ Hapus</button>';
                    echo '</div>';
                    echo '</div>';
                    echo '<p style="color:#cbd5e1;font-size:0.88rem;margin:10px 0 0">' . nl2br(htmlspecialchars($tasg['instructions'], ENT_QUOTES)) . '</p>';
                    echo '</div>';
                }
            }
            echo '</div>';

            // TEACHER TAB: BUAT TUGAS BARU
            echo '<div id="tab-buat_tugas" style="display:none">';
            echo '<div class="card" style="max-width:700px;margin:0 auto">';
            echo '<h2 style="color:#fff;font-size:1.2rem;margin-top:0">➕ Buat & Terbitkan Tugas Baru</h2>';
            echo '<p class="muted" style="font-size:0.85rem;color:#94a3b8">Tugas akan otomatis tampil di dashboard murid dan murid kelas terkait akan menerima notifikasi.</p>';
            echo '<form id="formCreateTask" onsubmit="vcSubmitCreateTask(event)">';
            echo '<input type="hidden" name="_csrf" value="' . $csrf . '">';

            // Kirim ke server/room game: kelas terisi otomatis dari server; anggota server ikut menerima tugas.
            $assignable = \VC\Domain\GameServers::assignableFor((int)$user['id'], $isStaffAll);
            echo '<label class="form-label" for="taskServer">Kirim ke Server / Room Game <span class="muted">(opsional)</span></label>';
            echo '<select name="server_id" id="taskServer" class="form-input">';
            echo '<option value="0" data-class="0">— Hanya kelas sasaran (tanpa server) —</option>';
            foreach ($assignable as $sv) {
                $lbl = (($sv['world'] ?? '3d') === 'pixel' ? '🧱 ' : '🚀 ') . $sv['name'] . ' · kode ' . ($sv['invite_code'] ?: '-')
                    . ($sv['class_name'] ? ' · ' . $sv['class_name'] : '') . ' · ' . (int)$sv['member_count'] . ' anggota';
                echo '<option value="' . (int)$sv['id'] . '" data-class="' . (int)($sv['class_id'] ?? 0) . '" data-class-name="' . htmlspecialchars((string)($sv['class_name'] ?? ''), ENT_QUOTES) . '">' . htmlspecialchars($lbl, ENT_QUOTES) . '</option>';
            }
            echo '</select>';
            echo '<p class="muted" id="taskServerHint" style="font-size:0.8rem;margin:2px 0 8px">Pilih room → kelas terisi otomatis, dan semua murid yang gabung room itu (pakai kode) ikut menerima tugas.</p>';

            echo '<label class="form-label">Kelas Sasaran</label>';
            echo '<select name="class_id" id="taskClass" class="form-input" required>';
            foreach ($teacherClasses as $tc) {
                $sel = ((int)$tc['id'] === $classId) ? ' selected' : '';
                echo '<option value="' . (int)$tc['id'] . '"' . $sel . '>' . htmlspecialchars($tc['name'], ENT_QUOTES) . '</option>';
            }
            echo '</select>';

            echo '<label class="form-label">Mata Pelajaran</label>';
            echo '<select name="subject_id" class="form-input" required>';
            foreach ($allSubjects as $sub) {
                echo '<option value="' . (int)$sub['id'] . '">' . htmlspecialchars($sub['code'] . ' — ' . $sub['name'], ENT_QUOTES) . '</option>';
            }
            echo '</select>';

            echo '<label class="form-label">Judul Tugas</label>';
            echo '<input type="text" name="title" class="form-input" placeholder="misal: Praktikum Jaringan VLAN Bab 2" maxlength="150" required>';

            echo '<label class="form-label">Jenis Tugas</label>';
            echo '<div class="tq-kind" role="radiogroup">'
                . '<label><input type="radio" name="task_kind" value="biasa" checked> 📝 Uraian / Upload berkas</label>'
                . '<label><input type="radio" name="task_kind" value="soal"> ✅ Soal: Pilihan Ganda &amp; Essay</label></div>';
            echo '<label class="form-label">Instruksi / Petunjuk</label>';
            echo '<textarea name="instructions" id="taskInstr" rows="4" class="form-input" placeholder="Tuliskan petunjuk pengerjaan, format laporan, kriteria penilaian..." required></textarea>';
            echo '<div id="tqBuilder" class="tq-builder" hidden></div>';

            $defDeadline = date('Y-m-d\TH:i', strtotime('+3 days 23:59'));
            echo '<label class="form-label">Batas Waktu Pengumpulan (Deadline)</label>';
            echo '<input type="datetime-local" name="deadline" class="form-input" value="' . $defDeadline . '" required>';

            echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">';
            echo '<div><label class="form-label">Nilai Maksimal</label><input type="number" name="max_score" value="100" min="1" max="1000" class="form-input"></div>';
            echo '<div><label class="form-label">Penalti Terlambat (%)</label><input type="number" name="late_penalty" value="10" min="0" max="100" class="form-input"></div>';
            echo '</div>';

            echo '<div style="margin-top:14px"><label style="display:flex;align-items:center;gap:8px;font-size:0.88rem;cursor:pointer">';
            echo '<input type="checkbox" name="allow_late" value="1" checked> Izinkan pengumpulan terlambat dengan penalti</label></div>';

            echo '<div style="margin-top:20px;display:flex;gap:10px">';
            echo '<button type="submit" id="btnCreateTask" class="btn btn-gold" style="padding:10px 20px">🚀 Terbitkan Tugas Sekarang</button>';
            echo '<button type="button" class="btn btn-sec" onclick="showTab(\'kelola_tugas\')">Batal</button>';
            echo '</div>';
            echo '</form>';
            echo '</div></div>';
        }

        // TAB TUGAS KELAS
        echo '<div id="tab-tugas"' . ($isTeacher ? ' style="display:none"' : '') . '>';
        if (empty($assignments)) {
            echo '<div class="card" style="text-align:center;padding:32px 16px;color:#94a3b8">Belum ada tugas aktif untuk kelas ini.</div>';
        }
        foreach ($assignments as $asg) {
            $mySub = $mySubMap[(int)$asg['id']] ?? null;
            $now = \VC\Domain\Schedule::nowLocal('Y-m-d H:i:s');
            $isOverdue = ($now > $asg['deadline']);

            echo '<div class="card">';
            echo '<div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:8px">';
            echo '<div><h3 style="color:#fff;margin:0 0 4px 0">' . htmlspecialchars($asg['title'], ENT_QUOTES) . '</h3>';
            echo '<span class="badge">' . htmlspecialchars($asg['subject_name'], ENT_QUOTES) . '</span> ';
            echo '<span class="badge badge-gold">Guru: ' . htmlspecialchars($asg['teacher_name'], ENT_QUOTES) . '</span> ';
            if (!empty($asg['server_name'])) {
                echo '<span class="badge">' . (($asg['server_world'] ?? '3d') === 'pixel' ? '🧱' : '🚀') . ' Room: ' . htmlspecialchars((string)$asg['server_name'], ENT_QUOTES) . '</span> ';
            }
            echo '<span style="font-size:0.8rem;color:' . ($isOverdue ? '#ef4444' : '#94a3b8') . '">⏰ Batas: ' . htmlspecialchars($asg['deadline'], ENT_QUOTES) . ($isOverdue ? ' (Batas Terlewat)' : '') . '</span></div>';

            echo '<div>';
            if ($mySub) {
                if ($mySub['score'] !== null) {
                    echo '<span class="badge badge-green" style="margin-right:8px">⭐ Nilai: ' . (int)$mySub['score'] . ' / ' . (int)$asg['max_score'] . '</span>';
                } else {
                    echo '<span class="badge badge-green" style="margin-right:8px">✅ Sudah Dikumpul</span>';
                }
            } else {
                echo '<span class="badge ' . ($isOverdue ? 'badge-red' : 'badge-gold') . '" style="margin-right:8px">' . ($isOverdue ? '⚠️ Terlambat' : '⏳ Belum Dikumpul') . '</span>';
            }
            echo '<a href="/tugas?id=' . (int)$asg['id'] . '" class="btn">' . ($mySub ? 'Lihat Jawaban & Nilai' : 'Kerjakan / Kumpul') . ' →</a>';
            if ($isTeacher && \VC\Domain\ClassroomAccess::teacherOwnsAssignment((int)$asg['id'], (int)$user['id'], $isStaffAll)) {
                echo '<button type="button" class="btn btn-sec" onclick="vcDeleteTask(' . (int)$asg['id'] . ')" style="color:#ef4444;border-color:rgba(239,68,68,0.4);margin-left:6px" title="Hapus Tugas">🗑️ Hapus</button>';
            }
            echo '</div>';

            echo '</div>';
            echo '<p style="color:#cbd5e1;font-size:0.9rem;margin-top:10px">' . nl2br(htmlspecialchars($asg['instructions'], ENT_QUOTES)) . '</p>';
            echo '</div>';
        }
        echo '</div>';

        // STUDENT TAB: TUGAS SAYA & NILAI
        if (!$isTeacher) {
            echo '<div id="tab-tugas_saya" style="display:none">';
            if (empty($studentSubmissions)) {
                echo '<div class="card" style="text-align:center;padding:32px 16px;color:#94a3b8">Anda belum mengumpulkan tugas apa pun. Buka tab <strong>Tugas Kelas</strong> untuk mulai mengerjakan.</div>';
            } else {
                echo '<div class="card"><div class="table-responsive"><table>';
                echo '<thead><tr><th>Mata Pelajaran</th><th>Judul Tugas</th><th>Waktu Dikirim</th><th>Status</th><th>Nilai & Umpan Balik</th><th>Aksi</th></tr></thead><tbody>';
                foreach ($studentSubmissions as $sub) {
                    echo '<tr>';
                    echo '<td><span class="badge">' . htmlspecialchars($sub['subject_name'] ?? 'Pelajaran', ENT_QUOTES) . '</span></td>';
                    echo '<td><strong>' . htmlspecialchars($sub['assignment_title'] ?? ('Tugas #' . $sub['assignment_id']), ENT_QUOTES) . '</strong></td>';
                    echo '<td style="font-size:0.8rem;color:#94a3b8">' . htmlspecialchars($sub['submitted_at'], ENT_QUOTES) . '</td>';
                    echo '<td>' . (!empty($sub['is_late']) ? '<span class="badge badge-red">Terlambat</span>' : '<span class="badge badge-green">Tepat Waktu</span>') . '</td>';
                    echo '<td>';
                    if ($sub['score'] !== null) {
                        echo '<strong style="color:var(--accent);font-size:1.1rem">' . (int)$sub['score'] . '</strong> <span style="font-size:0.8rem;color:#94a3b8">/ ' . (int)$sub['max_score'] . '</span>';
                        if (!empty($sub['feedback'])) {
                            echo '<div style="font-size:0.8rem;color:#cbd5e1;margin-top:2px">💬 <em>' . htmlspecialchars($sub['feedback'], ENT_QUOTES) . '</em></div>';
                        }
                    } else {
                        echo '<span class="badge badge-gold">⏳ Menunggu Penilaian</span>';
                    }
                    echo '</td>';
                    echo '<td>';
                    echo '<a href="/tugas?id=' . (int)$sub['assignment_id'] . '" class="btn btn-sec" style="padding:4px 10px;font-size:0.75rem">Detail</a>';
                    if ($sub['score'] === null) {
                        echo '<button type="button" class="btn btn-sec" onclick="vcDeleteSubmission(' . (int)$sub['assignment_id'] . ')" style="padding:4px 8px;font-size:0.75rem;color:#ef4444;border-color:rgba(239,68,68,0.4);margin-left:4px" title="Tarik / Batalkan Jawaban">🗑️ Tarik</button>';
                    }
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div></div>';
            }
            echo '</div>';
        }

        // TAB MATERI
        echo '<div id="tab-materi" style="display:none">';
        if ($isTeacher) {
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">';
            echo '<h2 style="font-size:1.1rem;color:#fff;margin:0">📖 Materi Pembelajaran Kelas ' . htmlspecialchars($currentClassName, ENT_QUOTES) . '</h2>';
            echo '<button class="btn btn-gold" onclick="showTab(\'tambah_materi\')">➕ Tambah Materi Baru</button>';
            echo '</div>';
        }
        if (empty($materials)) {
            echo '<div class="card" style="text-align:center;color:#94a3b8;padding:30px 16px">';
            echo '<div style="font-size:2rem;margin-bottom:8px">📖</div>';
            echo '<p style="margin:0 0 10px">Belum ada materi pembelajaran yang diunggah untuk kelas ini.</p>';
            if ($isTeacher) {
                echo '<button class="btn" onclick="showTab(\'tambah_materi\')">Tambah Materi Pertama</button>';
            }
            echo '</div>';
        }
        foreach ($materials as $m) {
            echo '<div class="card">';
            echo '<div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:8px">';
            echo '<div><h3 style="color:#fff;margin:0 0 4px 0">' . htmlspecialchars($m['title'], ENT_QUOTES) . '</h3>';
            echo '<span class="badge">' . htmlspecialchars($m['subject_name'], ENT_QUOTES) . '</span> ';
            echo '<span class="badge badge-gold">Tipe: ' . htmlspecialchars(strtoupper($m['content_type'] ?? 'TEXT'), ENT_QUOTES) . '</span> ';
            echo '<span style="font-size:0.8rem;color:#94a3b8">Guru: ' . htmlspecialchars($m['teacher_name'], ENT_QUOTES) . '</span></div>';
            
            echo '<div style="display:flex;gap:8px;align-items:center">';
            if (!empty($m['content_url'])) {
                echo '<a href="' . htmlspecialchars($m['content_url'], ENT_QUOTES) . '" target="_blank" rel="noopener" class="btn btn-sec">Buka Referensi / Tautan ↗</a>';
            }
            if ($isTeacher && \VC\Domain\ClassroomAccess::teacherOwnsMaterial((int)$m['id'], (int)$user['id'], $isStaffAll)) {
                echo '<button type="button" class="btn btn-sec" onclick="vcDeleteMaterial(' . (int)$m['id'] . ')" style="color:#ef4444;border-color:rgba(239,68,68,0.4)" title="Hapus Materi">🗑️ Hapus</button>';
            }
            echo '</div>';
            echo '</div>';
            echo '<p style="color:#cbd5e1;font-size:0.9rem;margin-top:10px">' . nl2br(htmlspecialchars($m['description'], ENT_QUOTES)) . '</p>';
            echo '</div>';
        }
        echo '</div>';

        // TEACHER TAB: TAMBAH MATERI BARU
        if ($isTeacher) {
            echo '<div id="tab-tambah_materi" style="display:none">';
            echo '<div class="card" style="max-width:700px;margin:0 auto">';
            echo '<h2 style="color:#fff;font-size:1.2rem;margin-top:0">➕ Tambah &amp; Terbitkan Materi Pembelajaran</h2>';
            echo '<p class="muted" style="font-size:0.85rem;color:#94a3b8">Materi pembelajaran ini akan langsung tersedia bagi siswa kelas sasaran.</p>';
            echo '<form id="formCreateMaterial" onsubmit="vcSubmitCreateMaterial(event)">';
            echo '<input type="hidden" name="_csrf" value="' . $csrf . '">';

            echo '<label class="form-label">Kelas Sasaran</label>';
            echo '<select name="class_id" class="form-input" required>';
            foreach ($teacherClasses as $tc) {
                $sel = ((int)$tc['id'] === $classId) ? ' selected' : '';
                echo '<option value="' . (int)$tc['id'] . '"' . $sel . '>' . htmlspecialchars($tc['name'], ENT_QUOTES) . '</option>';
            }
            echo '</select>';

            echo '<label class="form-label">Mata Pelajaran</label>';
            echo '<select name="subject_id" class="form-input" required>';
            foreach ($allSubjects as $sub) {
                echo '<option value="' . (int)$sub['id'] . '">' . htmlspecialchars($sub['code'] . ' — ' . $sub['name'], ENT_QUOTES) . '</option>';
            }
            echo '</select>';

            echo '<label class="form-label">Judul Materi</label>';
            echo '<input type="text" name="title" class="form-input" placeholder="misal: Modul 1 — Konfigurasi Jaringan VLAN Dasar" maxlength="150" required>';

            echo '<label class="form-label">Deskripsi / Ringkasan Materi</label>';
            echo '<textarea name="description" rows="4" class="form-input" placeholder="Tuliskan ringkasan materi, catatan pengantar, atau instruksi bacaan..." required></textarea>';

            echo '<label class="form-label">Tipe Konten</label>';
            echo '<select name="content_type" class="form-input">';
            echo '<option value="text">Teks / Modul Bacaan</option>';
            echo '<option value="link">Tautan Web / Referensi Belajar</option>';
            echo '<option value="pdf">Dokumen / PDF / E-Book</option>';
            echo '<option value="video">Video Pembelajaran (YouTube / Drive)</option>';
            echo '</select>';

            echo '<label class="form-label">Tautan URL / Link Dokumen (Opsional)</label>';
            echo '<input type="url" name="content_url" class="form-input" placeholder="https://...">';

            echo '<div style="margin-top:20px;display:flex;gap:10px">';
            echo '<button type="submit" id="btnCreateMaterial" class="btn btn-gold" style="padding:10px 20px">🚀 Terbitkan Materi Pembelajaran</button>';
            echo '<button type="button" class="btn btn-sec" onclick="showTab(\'materi\')">Batal</button>';
            echo '</div>';
            echo '</form>';
            echo '</div></div>';
        }
        echo '</div>';

        // TAB KUIS
        echo '<div id="tab-kuis" style="display:none">';
        if (empty($quizzes)) {
            echo '<div class="card" style="text-align:center;color:#94a3b8">Belum ada kuis yang tersedia.</div>';
        }
        foreach ($quizzes as $q) {
            echo '<div class="card">';
            echo '<div style="display:flex;justify-content:space-between;align-items:start">';
            echo '<div><h3 style="color:#fff;margin:0 0 4px 0">' . htmlspecialchars($q['title'], ENT_QUOTES) . '</h3>';
            echo '<span class="badge">' . htmlspecialchars($q['subject_name'], ENT_QUOTES) . '</span> ';
            echo '<span style="font-size:0.8rem;color:#94a3b8">Durasi: ' . (int)$q['duration_minutes'] . ' Menit • ' . (int)$q['question_count'] . ' Soal</span></div>';
            echo '<a href="/kuis?id=' . (int)$q['id'] . '" class="btn">Mulai Kuis ⏱️</a>';
            echo '</div>';
            echo '<p style="color:#cbd5e1;font-size:0.9rem;margin-top:8px">' . htmlspecialchars($q['description'], ENT_QUOTES) . '</p>';
            echo '</div>';
        }
        echo '</div>';

        // TAB SERVER KELAS 3D & MULTIPLAYER
        echo '<div id="tab-server" style="display:none">';
        echo '<div class="card" style="padding:24px;border:2px solid var(--accent);text-align:center">';
        echo '<div style="font-size:2.8rem;margin-bottom:10px">🎮</div>';
        echo '<h2 style="color:#fff;margin:0 0 8px">Server Ruang Kelas 3D Live — ' . htmlspecialchars($currentClassName, ENT_QUOTES) . '</h2>';
        echo '<p style="color:#94a3b8;max-width:540px;margin:0 auto 18px;font-size:0.9rem">Masuk ke ruang kelas virtual 3D untuk mengikuti sesi pembelajaran interaktif, presentasi langsung guru, dan interaksi avatar sesama siswa.</p>';
        echo '<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">';
        echo '<a href="/dunia?room=kelas_' . $classId . '" class="btn btn-gold" style="padding:12px 24px;font-size:1rem">🚀 Masuk Kelas 3D Sekarang</a>';
        echo '<a href="/pixel" class="btn btn-sec" style="padding:12px 20px;font-size:1rem">👾 Mode Pixel 2D</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo Footer::html();
        echo '</div>';
        echo '<link rel="stylesheet" href="/assets/css/task-quiz.css?v=7.5.0"><script src="/assets/js/task-quiz.js?v=7.5.0" defer></script>';
        echo '<script nonce="' . $nonce . '">
function showTab(name) {
  var tabs = ["kelola_tugas", "buat_tugas", "tugas", "tugas_saya", "materi", "tambah_materi", "kuis", "server"];
  tabs.forEach(function(t) {
    var el = document.getElementById("tab-" + t);
    if (el) el.style.display = (t === name) ? "block" : "none";
  });
  document.querySelectorAll(".tab-btn").forEach(function(b) { b.classList.remove("active"); });
  if (event && event.target && event.target.classList) {
    event.target.classList.add("active");
  }
}

document.addEventListener("DOMContentLoaded", function () {
  var sv = document.getElementById("taskServer"), cl = document.getElementById("taskClass");
  if (!sv || !cl) return;
  sv.addEventListener("change", function () {
    var opt = sv.options[sv.selectedIndex];
    var cid = opt ? opt.getAttribute("data-class") : "0";
    var hint = document.getElementById("taskServerHint");
    if (cid && cid !== "0") {
      if (!cl.querySelector("option[value=\"" + cid + "\"]")) { var o = document.createElement("option"); o.value = cid; o.textContent = opt.getAttribute("data-class-name") || ("Kelas #" + cid); cl.appendChild(o); }
      cl.value = cid;
      if (hint) hint.textContent = "✅ Kelas diisi otomatis dari server. Anggota room ikut menerima tugas.";
    } else if (hint) {
      hint.textContent = sv.value !== "0" ? "Room ini tidak terhubung ke rombel — pilih kelas sasaran untuk rekap nilai. Anggota room tetap menerima tugas." : "Pilih room → kelas terisi otomatis, dan semua murid yang gabung room itu (pakai kode) ikut menerima tugas.";
    }
  });
});

async function vcSubmitCreateTask(e) {
  e.preventDefault();
  var form = document.getElementById("formCreateTask");
  var btn = document.getElementById("btnCreateTask");

  var fd = new FormData(form);
  var body = {};
  fd.forEach(function(val, key) { body[key] = val; });
  if (body.task_kind === "soal") {
    var qs = window.vcTaskQuiz ? window.vcTaskQuiz.collect() : [];
    if (!qs.length) { alert("Tambahkan minimal satu soal."); return; }
    body.questions = qs;
  }
  btn.disabled = true;
  btn.textContent = "Menerbitkan tugas…";

  try {
    var r = await fetch("/api/task/create", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify(body)
    });
    var d = await r.json();
    if (r.ok && d.status === "success") {
      alert("✅ " + (d.message || "Tugas berhasil diterbitkan!"));
      location.reload();
    } else {
      alert("❌ " + (d.message || "Gagal menerbitkan tugas."));
      btn.disabled = false;
      btn.textContent = "🚀 Terbitkan Tugas Sekarang";
    }
  } catch(err) {
    alert("❌ Terjadi kesalahan jaringan.");
    btn.disabled = false;
    btn.textContent = "🚀 Terbitkan Tugas Sekarang";
  }
}

async function vcSubmitCreateMaterial(e) {
  e.preventDefault();
  var form = document.getElementById("formCreateMaterial");
  var btn = document.getElementById("btnCreateMaterial");
  btn.disabled = true;
  btn.textContent = "Menerbitkan materi…";

  var fd = new FormData(form);
  var body = {};
  fd.forEach(function(val, key) { body[key] = val; });

  try {
    var r = await fetch("/api/material/create", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify(body)
    });
    var d = await r.json();
    if (r.ok && d.status === "success") {
      alert("✅ " + (d.message || "Materi berhasil diterbitkan!"));
      location.reload();
    } else {
      alert("❌ " + (d.message || "Gagal menerbitkan materi."));
      btn.disabled = false;
      btn.textContent = "🚀 Terbitkan Materi Pembelajaran";
    }
  } catch(err) {
    alert("❌ Terjadi kesalahan jaringan.");
    btn.disabled = false;
    btn.textContent = "🚀 Terbitkan Materi Pembelajaran";
  }
}

async function vcDeleteMaterial(id) {
  if (!confirm("Apakah Anda yakin ingin menghapus materi pembelajaran ini?")) return;
  try {
    var r = await fetch("/api/material/delete", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ material_id: id, _csrf: "' . $csrf . '" })
    });
    var d = await r.json();
    if (r.ok && d.status === "success") {
      alert("✅ " + (d.message || "Materi berhasil dihapus."));
      location.reload();
    } else {
      alert("❌ " + (d.message || "Gagal menghapus materi."));
    }
  } catch(err) {
    alert("❌ Terjadi kesalahan jaringan.");
  }
}

async function vcDeleteTask(id, redirectAfter) {
  if (!confirm("Apakah Anda yakin ingin menghapus tugas ini? Seluruh data tugas akan dihapus.")) return;
  try {
    var r = await fetch("/api/task/delete", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ assignment_id: id, _csrf: "' . $csrf . '" })
    });
    var d = await r.json();
    if (r.ok && d.status === "success") {
      alert("✅ " + (d.message || "Tugas berhasil dihapus."));
      if (redirectAfter) {
        location.href = "/kelas";
      } else {
        location.reload();
      }
    } else {
      alert("❌ " + (d.message || "Gagal menghapus tugas."));
    }
  } catch(err) {
    alert("❌ Terjadi kesalahan jaringan.");
  }
}

async function vcDeleteSubmission(assignmentId) {
  if (!confirm("Apakah Anda yakin ingin menarik / membatalkan pengumpulan tugas ini? Jawaban Anda yang belum dinilai akan ditarik sehingga Anda dapat mengumpulkan ulang.")) return;
  try {
    var r = await fetch("/api/task/submission_delete", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ assignment_id: assignmentId, _csrf: "' . $csrf . '" })
    });
    var d = await r.json();
    if (r.ok && d.status === "success") {
      alert("✅ " + (d.message || "Pengumpulan berhasil ditarik."));
      location.reload();
    } else {
      alert("❌ " + (d.message || "Gagal membatalkan pengumpulan tugas."));
    }
  } catch(err) {
    alert("❌ Terjadi kesalahan jaringan.");
  }
}
</script></body></html>';
    }

    /** Render assignment detail, submission form, and grading interface (/tugas?id=X) */
    public static function renderAssignmentDetail(int $assignmentId): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();
        $csrf = Csrf::token();
        $pdo = Database::get();

        if ($user === null) {
            header('Location: /login?redirect=' . urlencode('/tugas?id=' . $assignmentId));
            exit;
        }

        $asg = Learning::getAssignment($assignmentId);
        if (!$asg) {
            http_response_code(404);
            echo '<!doctype html><html lang="id"><head><title>404 — Tugas Tidak Ditemukan</title>';
            echo '<style nonce="' . $nonce . '">body{background:#090d16;color:#fff;font-family:sans-serif;padding:30px;text-align:center}</style></head><body>';
            echo '<h1>Tugas Tidak Ditemukan</h1><p style="color:#94a3b8">Tugas telah dihapus atau ID tidak valid.</p><a href="/kelas" style="color:#22d3ee">Kembali ke Ruang Kelas</a></body></html>';
            return;
        }

        $isStaffAll = Permissions::can('user.view');
        $isTeacher = in_array($user['role'] ?? '', ['guru', 'wali_kelas', 'kepala_program', 'kepala_sekolah', 'root'], true);
        $canGrade = ClassroomAccess::teacherOwnsAssignment($assignmentId, (int)$user['id'], $isStaffAll);

        // Student validation: must belong to the class
        if (!$isTeacher && !Learning::studentCanAccess((int)$user['id'], $asg)) { // kelas sasaran ATAU anggota server tujuan
            http_response_code(403);
            echo '<!doctype html><html lang="id"><head><title>403 — Akses Ditolak</title>';
            echo '<style nonce="' . $nonce . '">body{background:#090d16;color:#ef4444;font-family:sans-serif;padding:30px;text-align:center}</style></head><body>';
            echo '<h1>🚫 403 Akses Ditolak</h1><p style="color:#cbd5e1">Tugas ini ditujukan untuk kelas <strong>' . htmlspecialchars($asg['class_name'], ENT_QUOTES) . '</strong>, bukan kelas Anda.</p><a href="/kelas" style="color:#22d3ee">Kembali ke Kelas Anda</a></body></html>';
            return;
        }

        $now = \VC\Domain\Schedule::nowLocal('Y-m-d H:i:s');
        $isOverdue = ($now > $asg['deadline']);
        $mySub = !$isTeacher ? Learning::getStudentSubmission($assignmentId, (int)$user['id']) : null;
        $submissions = $canGrade ? Learning::getSubmissionsForAssignment($assignmentId) : [];
        $quizQs = \VC\Domain\AssignmentQuiz::decode($asg['questions'] ?? null);
        $quizTotal = \VC\Domain\AssignmentQuiz::totalPoints($quizQs);
        $qFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo Pwa::headTags();
        echo '<title>' . htmlspecialchars($asg['title'], ENT_QUOTES) . ' — Virtual City SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">
:root { --bg: #090d16; --card: #131a29; --border: #1f293d; --accent: #22d3ee; --gold: #f59e0b; --green: #10b981; --red: #ef4444; }
body { font-family: system-ui, sans-serif; background: var(--bg); color: #e2e8f0; margin: 0; padding: 16px; }
.container { max-width: 860px; margin: 0 auto; }
header { margin-bottom: 20px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: bold; background: rgba(34,211,238,0.15); color: var(--accent); border: 1px solid rgba(34,211,238,0.3); }
.badge-gold { background: rgba(245,158,11,0.15); color: var(--gold); border-color: rgba(245,158,11,0.3); }
.badge-green { background: rgba(16,185,129,0.15); color: var(--green); border-color: rgba(16,185,129,0.3); }
.badge-red { background: rgba(239,68,68,0.15); color: var(--red); border-color: rgba(239,68,68,0.3); }
.btn { background: var(--accent); color: #000; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-block; border: none; cursor: pointer; font-size: 0.9rem; }
.btn-gold { background: linear-gradient(135deg, #f59e0b, #d97706); color: #000; }
.btn-sec { background: #1e293b; color: #fff; border: 1px solid var(--border); }
.form-input { width: 100%; box-sizing: border-box; background: #0c111c; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; color: #fff; font-size: 0.9rem; margin-top: 4px; }
.form-input:focus { outline: none; border-color: var(--accent); }
.form-label { font-size: 0.82rem; font-weight: 600; color: #94a3b8; margin-top: 10px; display: block; }
table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
th { color: #94a3b8; font-weight: 600; }
</style></head><body>';
        echo '<div class="container">';
        echo '<header><a href="/kelas" style="color:var(--accent);text-decoration:none;font-size:0.9rem">← Kembali ke Ruang Kelas</a></header>';

        // 1. ASSIGNMENT OVERVIEW CARD
        echo '<div class="card">';
        echo '<div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:8px;margin-bottom:10px">';
        echo '<div><h1 style="margin:0 0 6px 0;font-size:1.4rem">' . htmlspecialchars($asg['title'], ENT_QUOTES) . '</h1>';
        echo '<span class="badge">' . htmlspecialchars($asg['subject_name'], ENT_QUOTES) . '</span> ';
        echo '<span class="badge badge-gold">Kelas ' . htmlspecialchars($asg['class_name'], ENT_QUOTES) . '</span> ';
        echo '<span style="font-size:0.85rem;color:#94a3b8">Guru: ' . htmlspecialchars($asg['teacher_name'], ENT_QUOTES) . '</span></div>';
        echo '<div style="display:flex;gap:8px;align-items:center">';
        echo '<span class="badge ' . ($isOverdue ? 'badge-red' : 'badge-green') . '">⏰ Batas: ' . htmlspecialchars($asg['deadline'], ENT_QUOTES) . '</span>';
        if ($canGrade) {
            echo '<button type="button" class="btn btn-sec" onclick="vcDeleteTask(' . $assignmentId . ', true)" style="color:#ef4444;border-color:rgba(239,68,68,0.4);padding:6px 12px;font-size:0.8rem">🗑️ Hapus Tugas</button>';
        }
        echo '</div>';
        echo '</div>';

        echo '<div style="display:flex;gap:14px;background:#0c111c;padding:12px;border-radius:8px;margin-bottom:14px;font-size:0.82rem;color:#94a3b8">';
        echo '<div>Skor Maksimal: <strong style="color:#fff">' . (int)$asg['max_score'] . ' Poin</strong></div>';
        echo '<div>Pengumpulan Terlambat: <strong style="color:' . (!empty($asg['allow_late']) ? 'var(--gold)' : 'var(--red)') . '">' . (!empty($asg['allow_late']) ? 'Diizinkan (Penalti ' . (int)$asg['late_penalty'] . '%)' : 'Tidak Diizinkan') . '</strong></div>';
        echo '</div>';

        if ($quizQs !== []) {
            $nPg = count(array_filter($quizQs, static fn($q) => $q['t'] === 'pg'));
            echo '<div class="tq-badges"><span class="badge">✅ ' . $nPg . ' pilihan ganda</span> <span class="badge badge-gold">✍️ ' . (count($quizQs) - $nPg) . ' essay</span> <span class="badge badge-green">' . $quizTotal . ' poin total</span></div>';
        }
        echo '<h3 style="color:#fff;font-size:1rem;margin:12px 0 6px">Petunjuk Pengerjaan:</h3>';
        echo '<p style="color:#cbd5e1;font-size:0.92rem;line-height:1.6;white-space:pre-wrap">' . htmlspecialchars($asg['instructions'], ENT_QUOTES) . '</p>';
        echo '</div>';

        // 2. STUDENT VIEW: SUBMISSION STATUS & SUBMIT FORM
        if (!$isTeacher) {
            if ($mySub !== null) {
                echo '<div class="card" style="border:2px solid ' . ($mySub['score'] !== null ? 'var(--green)' : 'var(--accent)') . '">';
                echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">';
                echo '<h2 style="color:#fff;font-size:1.15rem;margin:0">📤 Status Pengumpulan Tugas Anda</h2>';
                if ($mySub['score'] !== null) {
                    echo '<span class="badge badge-green" style="font-size:0.9rem">⭐ Nilai: ' . (int)$mySub['score'] . ' / ' . (int)$asg['max_score'] . '</span>';
                } else {
                    echo '<span class="badge badge-green">✅ Sudah Dikumpulkan (Menunggu Penilaian)</span>';
                }
                echo '</div>';

                echo '<div style="font-size:0.85rem;color:#94a3b8;margin-bottom:10px">';
                echo 'Waktu Pengumpulan: <strong style="color:#fff">' . htmlspecialchars($mySub['submitted_at'], ENT_QUOTES) . '</strong> ';
                echo (!empty($mySub['is_late']) ? '<span class="badge badge-red">Terlambat</span>' : '<span class="badge badge-green">Tepat Waktu</span>');
                echo '</div>';

                if ($quizQs !== [] && !empty($mySub['answers'])) {
                    $myAns = json_decode((string)$mySub['answers'], true) ?: [];
                    echo '<div class="form-label">Jawaban kamu' . ($mySub['auto_score'] !== null ? ' — pilihan ganda benar: <b style="color:#fff">' . (int)$mySub['auto_score'] . ' poin</b>' : '') . '</div><ol class="tq-review">';
                    foreach ($quizQs as $i => $q) {
                        $a = $myAns[$i] ?? null;
                        if ($q['t'] === 'pg') {
                            $ok = $a !== null && (int)$a === (int)$q['ans'];
                            echo '<li><span>' . htmlspecialchars($q['q'], ENT_QUOTES) . '</span><em class="' . ($ok ? 'ok' : 'no') . '">' . ($a === null ? '— tidak dijawab' : chr(65 + (int)$a) . '. ' . htmlspecialchars((string)($q['opts'][(int)$a] ?? ''), ENT_QUOTES)) . ' ' . ($ok ? '✅' : '❌') . '</em></li>';
                        } else {
                            echo '<li><span>' . htmlspecialchars($q['q'], ENT_QUOTES) . '</span><em>' . nl2br(htmlspecialchars((string)$a, ENT_QUOTES)) . '</em></li>';
                        }
                    }
                    echo '</ol>';
                }
                if (!empty($mySub['content_text'])) {
                    echo '<div class="form-label">Jawaban / Laporan Anda:</div>';
                    echo '<div style="background:#0c111c;padding:12px;border-radius:8px;color:#e2e8f0;font-size:0.9rem;white-space:pre-wrap;margin-top:4px">' . htmlspecialchars($mySub['content_text'], ENT_QUOTES) . '</div>';
                }

                if (!empty($mySub['file_name'])) {
                    echo '<div style="margin-top:12px">';
                    echo '<span class="form-label">Berkas Lampiran:</span> ';
                    echo '<a href="/api/task/file?id=' . (int)$mySub['id'] . '" target="_blank" class="btn btn-sec" style="margin-top:4px;display:inline-flex;align-items:center;gap:6px">📎 Unduh ' . htmlspecialchars($mySub['file_name'], ENT_QUOTES) . '</a>';
                    echo '</div>';
                }

                if ($mySub['score'] !== null) {
                    echo '<div style="margin-top:16px;background:rgba(16,185,129,0.1);border:1px solid var(--green);border-radius:8px;padding:14px">';
                    echo '<div style="font-size:0.85rem;color:#6ee7b7;font-weight:bold">Ulasan Guru Pengajar:</div>';
                    echo '<p style="color:#fff;margin:6px 0 0;font-size:0.95rem">"' . htmlspecialchars((string)($mySub['feedback'] ?: 'Tugas diterima dengan baik.'), ENT_QUOTES) . '"</p>';
                    echo '<div style="font-size:0.75rem;color:#94a3b8;margin-top:6px">Dinilai oleh ' . htmlspecialchars((string)$mySub['grader_name'], ENT_QUOTES) . ' pada ' . htmlspecialchars((string)$mySub['graded_at'], ENT_QUOTES) . '</div>';
                    echo '</div>';
                }

                echo '<div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">';
                if ($mySub['score'] === null) {
                    echo '<button type="button" class="btn btn-sec" onclick="vcDeleteSubmission(' . (int)$asg['id'] . ')" style="font-size:0.8rem;color:#ef4444;border-color:rgba(239,68,68,0.4)">🗑️ Tarik / Batalkan Pengumpulan</button>';
                }
                if ($quizQs === [] || $mySub['score'] === null) {
                    echo '<button class="btn btn-sec" onclick="toggleResubmitForm()" style="font-size:0.8rem">✏️ ' . ($quizQs !== [] ? 'Ubah Jawaban' : 'Perbarui / Kirim Revisi Tugas') . '</button>';
                }
                echo '</div>';
                echo '</div>';
            }

            // FORM SUBMIT / REVISE
            $formHidden = ($mySub !== null) ? 'style="display:none"' : '';
            $quizLocked = $quizQs !== [] && $mySub !== null && $mySub['score'] !== null;
            echo '<div class="card" id="boxSubmitForm" ' . $formHidden . '>';
            echo '<h2 style="color:#fff;font-size:1.15rem;margin-top:0">' . ($mySub ? '✏️ Formulir Revisi Tugas' : '✍️ Kumpulkan Jawaban Tugas') . '</h2>';
            if ($quizLocked) {
                echo '<p style="color:#94a3b8">Jawaban sudah dinilai dan terkunci.</p>';
            } elseif ($isOverdue && empty($asg['allow_late'])) {
                echo '<div style="background:rgba(239,68,68,0.15);border:1px solid #ef4444;border-radius:8px;padding:12px;color:#fca5a5;font-size:0.9rem">';
                echo '⚠️ Batas waktu pengumpulan telah berakhir dan guru tidak mengizinkan pengumpulan terlambat.';
                echo '</div>';
            } else {
                if ($isOverdue) {
                    echo '<div style="background:rgba(245,158,11,0.15);border:1px solid #f59e0b;border-radius:8px;padding:10px;color:#fcd34d;font-size:0.85rem;margin-bottom:12px">';
                    echo '⚠️ Pengumpulan saat ini melewati batas waktu. Nilai akan dikenakan penalti ' . (int)$asg['late_penalty'] . '%.';
                    echo '</div>';
                }
                echo '<form id="formSubmitTask" onsubmit="vcSubmitTask(event)">';
                echo '<input type="hidden" name="_csrf" value="' . $csrf . '">';
                echo '<input type="hidden" name="assignment_id" value="' . $assignmentId . '">';

                if ($quizQs !== []) {
                    echo '<input type="hidden" name="answers" id="tqAnswers" value="">';
                    echo '<div id="tqTake" class="tq-take" data-q="' . htmlspecialchars(json_encode(\VC\Domain\AssignmentQuiz::publicView($quizQs), $qFlags), ENT_QUOTES) . '" data-a="' . htmlspecialchars((string)($mySub['answers'] ?? '{}'), ENT_QUOTES) . '"></div>';
                    echo '<details style="margin-top:10px"><summary style="cursor:pointer;color:#94a3b8">📎 Catatan / lampiran tambahan (opsional)</summary>';
                }
                echo '<label class="form-label">' . ($quizQs !== [] ? 'Catatan untuk guru' : 'Jawaban Teks / Catatan Laporan') . '</label>';
                echo '<textarea name="content_text" rows="' . ($quizQs !== [] ? 2 : 5) . '" class="form-input" placeholder="Tuliskan jawaban tugas Anda di sini (atau deskripsi berkas yang dilampirkan)...">' . htmlspecialchars((string)($mySub['content_text'] ?? ''), ENT_QUOTES) . '</textarea>';

                echo '<label class="form-label">Lampiran Berkas (Opsional - PDF, Dokumen, Gambar, ZIP maks 10MB)</label>';
                echo '<input type="file" name="file" class="form-input" style="padding:8px">';
                if ($quizQs !== []) {
                    echo '</details>';
                }

                echo '<div style="margin-top:16px">';
                echo '<button type="submit" id="btnSubmitTask" class="btn btn-gold" style="padding:12px 24px">📤 ' . ($mySub ? 'Kirim Revisi Jawaban' : 'Kirim Jawaban Tugas Sekarang') . '</button>';
                echo '</div>';
                echo '</form>';
            }
            echo '</div>';
        }

        // 3. TEACHER VIEW: SUBMISSIONS LIST & INLINE GRADING
        if ($canGrade) {
            echo '<div class="card">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">';
            echo '<h2 style="color:#fff;font-size:1.15rem;margin:0">📋 Pengumpulan Siswa (' . count($submissions) . ')</h2>';
            echo '<a href="/api/gradebook?class_id=' . (int)$asg['class_id'] . '" target="_blank" class="btn btn-sec" style="font-size:0.8rem">📊 Rekap Buku Nilai</a>';
            echo '</div>';

            if (empty($submissions)) {
                echo '<div style="text-align:center;padding:30px 10px;color:#94a3b8">Belum ada siswa dari kelas ' . htmlspecialchars($asg['class_name'], ENT_QUOTES) . ' yang mengumpulkan tugas ini.</div>';
            } else {
                echo '<div class="table-responsive"><table>';
                echo '<thead><tr><th>Siswa</th><th>Waktu</th><th>Jawaban & Berkas</th><th>Nilai & Feedback</th><th>Aksi</th></tr></thead><tbody>';
                foreach ($submissions as $sub) {
                    $sid = (int)$sub['id'];
                    echo '<tr>';
                    echo '<td><strong>' . htmlspecialchars($sub['full_name'], ENT_QUOTES) . '</strong><div style="font-size:0.75rem;color:#94a3b8">NIS: ' . htmlspecialchars((string)($sub['nis'] ?: $sub['username']), ENT_QUOTES) . '</div></td>';
                    echo '<td style="font-size:0.8rem;color:#94a3b8">' . htmlspecialchars($sub['submitted_at'], ENT_QUOTES) . '<br>' . (!empty($sub['is_late']) ? '<span class="badge badge-red">Terlambat</span>' : '<span class="badge badge-green">Tepat Waktu</span>') . '</td>';
                    echo '<td style="max-width:320px">';
                    if ($quizQs !== [] && !empty($sub['answers'])) {
                        $ans = json_decode((string)$sub['answers'], true) ?: [];
                        $pg = (int)($sub['auto_score'] ?? 0);
                        echo '<details class="tq-grade" data-sub="' . $sid . '" data-pg="' . $pg . '" data-total="' . $quizTotal . '" data-max="' . (int)$asg['max_score'] . '"><summary>📋 Lihat jawaban · PG ' . $pg . ' poin</summary><ol>';
                        foreach ($quizQs as $i => $q) {
                            $a = $ans[$i] ?? null;
                            if ($q['t'] === 'pg') {
                                $ok = $a !== null && (int)$a === (int)$q['ans'];
                                echo '<li>' . ($ok ? '✅' : '❌') . ' ' . ($a === null ? '—' : chr(65 + (int)$a)) . ' <small>(kunci ' . chr(65 + (int)$q['ans']) . ')</small></li>';
                            } else {
                                echo '<li>✍️ <span class="tq-essay">' . nl2br(htmlspecialchars((string)$a, ENT_QUOTES)) . '</span>'
                                    . ($q['key'] !== '' ? '<small class="tq-keytxt">Kunci: ' . htmlspecialchars($q['key'], ENT_QUOTES) . '</small>' : '')
                                    . '<label>Poin essay <input type="number" class="form-input tq-ep" data-essay min="0" max="' . (int)$q['pts'] . '" placeholder="0-' . (int)$q['pts'] . '"></label></li>';
                            }
                        }
                        echo '</ol><p class="tq-calc"></p></details>';
                    }
                    if (!empty($sub['content_text'])) {
                        echo '<div style="font-size:0.82rem;color:#cbd5e1;max-height:60px;overflow-y:auto;background:#0c111c;padding:6px;border-radius:4px">' . nl2br(htmlspecialchars($sub['content_text'], ENT_QUOTES)) . '</div>';
                    }
                    if (!empty($sub['file_name'])) {
                        echo '<div style="margin-top:4px"><a href="/api/task/file?id=' . $sid . '" target="_blank" class="badge" style="text-decoration:none">📎 ' . htmlspecialchars($sub['file_name'], ENT_QUOTES) . '</a></div>';
                    }
                    echo '</td>';

                    echo '<td>';
                    echo '<div style="display:flex;gap:6px;align-items:center">';
                    echo '<input type="number" id="gradeScore_' . $sid . '" value="' . ($sub['score'] !== null ? (int)$sub['score'] : '') . '" min="0" max="' . (int)$asg['max_score'] . '" placeholder="0-' . (int)$asg['max_score'] . '" class="form-input" style="width:75px;padding:6px;text-align:center;font-weight:bold">';
                    echo '<span style="font-size:0.8rem;color:#94a3b8">/ ' . (int)$asg['max_score'] . '</span>';
                    echo '</div>';
                    echo '<input type="text" id="gradeFeedback_' . $sid . '" value="' . htmlspecialchars((string)($sub['feedback'] ?? ''), ENT_QUOTES) . '" placeholder="Catatan umpan balik..." class="form-input" style="font-size:0.78rem;padding:4px 8px;margin-top:4px">';
                    echo '</td>';

                    echo '<td>';
                    echo '<button class="btn btn-gold" style="padding:6px 12px;font-size:0.8rem" onclick="vcSaveGrade(' . $sid . ')">Simpan</button>';
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div>';
        }

        echo Footer::html();
        echo '</div>';
        echo '<link rel="stylesheet" href="/assets/css/task-quiz.css?v=7.5.0"><script src="/assets/js/task-quiz.js?v=7.5.0" defer></script>';

        echo '<script nonce="' . $nonce . '">
function toggleResubmitForm() {
  var el = document.getElementById("boxSubmitForm");
  if (el) el.style.display = (el.style.display === "none") ? "block" : "none";
}

async function vcSubmitTask(e) {
  e.preventDefault();
  var form = document.getElementById("formSubmitTask");
  var btn = document.getElementById("btnSubmitTask");
  btn.disabled = true;
  btn.textContent = "Mengunggah jawaban…";

  var fd = new FormData(form);
  try {
    var r = await fetch("/api/task/submit", {
      method: "POST",
      headers: { "X-CSRF": "' . $csrf . '" },
      body: fd
    });
    var d = await r.json();
    if (r.ok && d.status === "success") {
      alert("✅ " + (d.message || "Tugas berhasil dikumpulkan!"));
      location.reload();
    } else {
      alert("❌ " + (d.message || "Gagal mengumpulkan tugas."));
      btn.disabled = false;
      btn.textContent = "Kirim Jawaban Tugas";
    }
  } catch(err) {
    alert("❌ Terjadi kesalahan jaringan.");
    btn.disabled = false;
    btn.textContent = "Kirim Jawaban Tugas";
  }
}

async function vcSaveGrade(subId) {
  var scoreEl = document.getElementById("gradeScore_" + subId);
  var feedEl = document.getElementById("gradeFeedback_" + subId);
  var score = scoreEl ? parseInt(scoreEl.value, 10) : null;
  var feedback = feedEl ? feedEl.value : "";

  if (isNaN(score) || score < 0) {
    alert("Masukkan angka nilai yang valid.");
    return;
  }

  try {
    var r = await fetch("/api/task/grade", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({
        _csrf: "' . $csrf . '",
        submission_id: subId,
        score: score,
        feedback: feedback
      })
    });
    var d = await r.json();
    if (r.ok && d.status === "success") {
      alert("✅ Nilai berhasil disimpan dan siswa dinotifikasi!");
      location.reload();
    } else {
      alert("❌ " + (d.message || "Gagal menyimpan nilai."));
    }
  } catch(err) {
    alert("❌ Terjadi kesalahan jaringan saat menyimpan nilai.");
  }
}

async function vcDeleteTask(id, redirectAfter) {
  if (!confirm("Apakah Anda yakin ingin menghapus tugas ini? Seluruh data tugas dan nilai siswa akan dihapus.")) return;
  try {
    var r = await fetch("/api/task/delete", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ assignment_id: id, _csrf: "' . $csrf . '" })
    });
    var d = await r.json();
    if (r.ok && d.status === "success") {
      alert("✅ " + (d.message || "Tugas berhasil dihapus."));
      location.href = "/kelas";
    } else {
      alert("❌ " + (d.message || "Gagal menghapus tugas."));
    }
  } catch(err) {
    alert("❌ Terjadi kesalahan jaringan.");
  }
}

async function vcDeleteSubmission(assignmentId) {
  if (!confirm("Apakah Anda yakin ingin menarik / membatalkan pengumpulan tugas ini? Jawaban Anda yang belum dinilai akan ditarik sehingga Anda dapat mengumpulkan ulang.")) return;
  try {
    var r = await fetch("/api/task/submission_delete", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF": "' . $csrf . '" },
      body: JSON.stringify({ assignment_id: assignmentId, _csrf: "' . $csrf . '" })
    });
    var d = await r.json();
    if (r.ok && d.status === "success") {
      alert("✅ " + (d.message || "Pengumpulan berhasil ditarik."));
      location.reload();
    } else {
      alert("❌ " + (d.message || "Gagal membatalkan pengumpulan tugas."));
    }
  } catch(err) {
    alert("❌ Terjadi kesalahan jaringan.");
  }
}
</script></body></html>';
    }

    /** Render in-browser quiz player (/kuis?id=X) */
    public static function renderQuizPlayer(int $quizId): void
    {
        $nonce = Headers::nonce();
        $user = Auth::user();
        $quiz = Learning::getQuiz($quizId, false);

        if (!$quiz) {
            http_response_code(404);
            echo '<!doctype html><title>404</title><h1>Kuis tidak ditemukan</h1><a href="/kelas">Kembali</a>';
            return;
        }

        $csrf = Csrf::token();

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo Pwa::headTags();
        echo '<title>' . htmlspecialchars($quiz['title'], ENT_QUOTES) . ' — Kuis Virtual City</title>';
        echo '<style nonce="' . $nonce . '">
:root { --bg: #090d16; --card: #131a29; --border: #1f293d; --accent: #22d3ee; }
body { font-family: system-ui, sans-serif; background: var(--bg); color: #e2e8f0; margin: 0; padding: 16px; }
.container { max-width: 720px; margin: 0 auto; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.timer { background: #ef4444; color: #fff; padding: 6px 14px; border-radius: 999px; font-weight: bold; font-family: monospace; font-size: 1rem; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 18px; margin-bottom: 16px; }
.q-title { font-size: 1.05rem; color: #fff; margin-bottom: 12px; font-weight: 600; }
.option-label { display: flex; align-items: center; gap: 10px; padding: 10px; background: #0d121d; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 8px; cursor: pointer; transition: border-color 0.15s; }
.option-label:hover { border-color: var(--accent); }
.btn-submit { width: 100%; background: var(--accent); color: #000; border: none; padding: 14px; border-radius: 10px; font-size: 1rem; font-weight: bold; cursor: pointer; }
.result-box { display: none; background: #131a29; border: 2px solid var(--accent); border-radius: 12px; padding: 24px; text-align: center; }
</style></head><body>';
        echo '<div class="container">';
        echo '<div class="header"><div><a href="/kelas" style="color:var(--accent);text-decoration:none;font-size:0.9rem">← Kembali ke Kelas</a><h1 style="font-size:1.3rem;margin:4px 0">' . htmlspecialchars($quiz['title'], ENT_QUOTES) . '</h1></div>';
        echo '<div class="timer" id="timerBox">' . (int)$quiz['duration_minutes'] . ':00</div></div>';

        echo '<form id="quizForm" onsubmit="return false">';
        echo '<input type="hidden" name="quiz_id" value="' . $quizId . '">';
        echo '<input type="hidden" name="_csrf" value="' . $csrf . '">';

        $num = 1;
        foreach ($quiz['questions'] as $q) {
            echo '<div class="card">';
            echo '<div class="q-title">' . $num . '. ' . htmlspecialchars($q['question_text'], ENT_QUOTES) . ' <span style="font-size:0.8rem;color:#94a3b8">(' . (int)$q['points'] . ' Poin)</span></div>';
            foreach ($q['options'] as $opt) {
                echo '<label class="option-label">';
                echo '<input type="radio" name="ans[' . (int)$q['id'] . ']" value="' . (int)$opt['id'] . '" required>';
                echo '<span>' . htmlspecialchars($opt['option_text'], ENT_QUOTES) . '</span>';
                echo '</label>';
            }
            echo '</div>';
            $num++;
        }

        echo '<button class="btn-submit" id="btnSubmitQuiz" onclick="submitQuiz()">Selesaikan & Kirim Jawaban</button>';
        echo '</form>';

        echo '<div id="resultBox" class="result-box">';
        echo '<h2 id="resTitle" style="color:#fff;margin-bottom:8px">Hasil Kuis</h2>';
        echo '<p id="resScore" style="font-size:2rem;font-weight:bold;color:var(--accent);margin-bottom:12px">0</p>';
        echo '<p id="resMsg" style="color:#cbd5e1;margin-bottom:16px"></p>';
        echo '<a href="/kelas" class="btn-submit" style="display:inline-block;text-decoration:none">Kembali ke Kelas</a>';
        echo '</div>';

        echo Footer::html();
        echo '</div>';

        $durSec = (int)$quiz['duration_minutes'] * 60;
        echo '<script nonce="' . $nonce . '">
let timeLeft = ' . $durSec . ';
let attemptId = null;

async function startAttempt() {
  try {
    const r = await fetch("/api/quiz/start", {
      method: "POST",
      headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
      body: JSON.stringify({quiz_id: ' . $quizId . '})
    });
    const d = await r.json();
    if (d.status === "success") {
      attemptId = d.attempt_id;
    }
  } catch(e) {}
}
startAttempt();

const timerEl = document.getElementById("timerBox");
const timerInterval = setInterval(() => {
  timeLeft--;
  if (timeLeft <= 0) {
    clearInterval(timerInterval);
    alert("Waktu pengerjaan habis! Jawaban Anda akan dikirim otomatis.");
    submitQuiz();
    return;
  }
  const m = Math.floor(timeLeft / 60);
  const s = timeLeft % 60;
  timerEl.textContent = m + ":" + (s < 10 ? "0" : "") + s;
}, 1000);

async function submitQuiz() {
  clearInterval(timerInterval);
  const btn = document.getElementById("btnSubmitQuiz");
  btn.disabled = true; btn.textContent = "Menilai jawaban…";

  const form = document.getElementById("quizForm");
  const formData = new FormData(form);
  const answers = {};
  for (let [key, val] of formData.entries()) {
    if (key.startsWith("ans[")) {
      const qId = key.substring(4, key.length - 1);
      answers[qId] = val;
    }
  }

  try {
    const r = await fetch("/api/quiz/submit", {
      method: "POST",
      headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
      body: JSON.stringify({attempt_id: attemptId, answers: answers})
    });
    const d = await r.json();
    if (d.status === "success") {
      form.style.display = "none";
      timerEl.style.display = "none";
      const res = document.getElementById("resultBox");
      res.style.display = "block";
      document.getElementById("resScore").textContent = d.score + " / " + d.max_score;
      document.getElementById("resMsg").textContent = d.message;
    } else {
      alert(d.message || "Gagal mengirim kuis.");
      btn.disabled = false;
    }
  } catch(e) {
    alert("Terjadi kesalahan koneksi.");
    btn.disabled = false;
  }
}
</script></body></html>';
    }

    /** Render events and extracurriculars page (/event) */
    public static function renderEventsPage(): void
    {
        $nonce = Headers::nonce();
        $events = Events::getUpcomingEvents();
        $orgs = Events::getOrganizations();
        $csrf = Csrf::token();

        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo Pwa::headTags();
        echo '<title>Event & Organisasi Siswa — SMK Silber</title>';
        echo '<style nonce="' . $nonce . '">
:root { --bg: #090d16; --card: #131a29; --border: #1f293d; --accent: #22d3ee; }
body { font-family: system-ui, sans-serif; background: var(--bg); color: #e2e8f0; margin: 0; padding: 16px; }
.container { max-width: 900px; margin: 0 auto; }
header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
h1 { font-size: 1.4rem; color: #fff; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; margin-bottom: 24px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: bold; background: rgba(34,211,238,0.15); color: var(--accent); }
.badge-gold { background: rgba(245,158,11,0.2); color: #f59e0b; }
.btn { background: var(--accent); color: #000; padding: 8px 14px; border-radius: 8px; font-weight: bold; border: none; cursor: pointer; text-decoration: none; display: inline-block; }
</style></head><body>';
        echo '<div class="container">';
        echo '<header><div><a href="/" style="color:var(--accent);text-decoration:none;font-size:0.9rem">← Beranda</a><h1>🏆 Agenda Event & Organisasi Sekolah</h1></div></header>';

        echo '<h2 style="font-size:1.15rem;color:#fff;margin-bottom:12px">📅 Event & Kompetisi Mendatang</h2>';
        echo '<div class="grid">';
        foreach ($events as $ev) {
            echo '<div class="card">';
            echo '<span class="badge">' . htmlspecialchars(strtoupper($ev['event_type']), ENT_QUOTES) . '</span> ';
            echo '<span class="badge-badge badge-gold">+' . (int)$ev['rewards_xp'] . ' XP</span>';
            echo '<h3 style="color:#fff;margin:8px 0 4px 0">' . htmlspecialchars($ev['title'], ENT_QUOTES) . '</h3>';
            echo '<p style="color:#94a3b8;font-size:0.85rem">📍 Lokasi: ' . htmlspecialchars($ev['map_location'] ?? 'Online', ENT_QUOTES) . '<br>⏰ ' . htmlspecialchars($ev['start_time'], ENT_QUOTES) . '</p>';
            echo '<p style="color:#cbd5e1;font-size:0.85rem">' . htmlspecialchars($ev['description'], ENT_QUOTES) . '</p>';
            echo '<button class="btn" onclick="presensi(' . (int)$ev['id'] . ')">Hadir / Presensi</button>';
            echo '</div>';
        }
        echo '</div>';

        echo '<h2 style="font-size:1.15rem;color:#fff;margin-bottom:12px">🏛️ Organisasi & Ekstrakurikuler</h2>';
        echo '<div class="grid">';
        foreach ($orgs as $o) {
            echo '<div class="card">';
            echo '<span class="badge">' . htmlspecialchars(strtoupper($o['category']), ENT_QUOTES) . '</span>';
            echo '<h3 style="color:#fff;margin:8px 0 4px 0">' . htmlspecialchars($o['name'], ENT_QUOTES) . '</h3>';
            echo '<p style="color:#94a3b8;font-size:0.85rem">📍 ' . htmlspecialchars($o['room_location'] ?? 'Sekolah', ENT_QUOTES) . ' • ' . htmlspecialchars($o['regular_schedule'] ?? '-', ENT_QUOTES) . '</p>';
            echo '<p style="color:#cbd5e1;font-size:0.85rem">' . htmlspecialchars($o['description'], ENT_QUOTES) . '</p>';
            echo '<button class="btn" style="background:#1f293d;color:#fff" onclick="gabungOrg(' . (int)$o['id'] . ')">Gabung (' . (int)$o['member_count'] . '/' . (int)$o['quota'] . ')</button>';
            echo '</div>';
        }
        echo '</div>';

        echo Footer::html();
        echo '</div>';
        echo '<script nonce="' . $nonce . '">
async function gabungOrg(id) {
  const r = await fetch("/api/org/join", {
    method: "POST",
    headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
    body: JSON.stringify({org_id: id})
  });
  const d = await r.json();
  alert(d.message || "Permintaan dikirim.");
}
async function presensi(id) {
  const code = prompt("Masukkan kode kehadiran event:");
  if (!code) return;
  const r = await fetch("/api/event/attend", {
    method: "POST",
    headers: {"Content-Type":"application/json", "X-CSRF":"' . $csrf . '"},
    body: JSON.stringify({event_id: id, verification_code: code})
  });
  const d = await r.json();
  alert(d.message || "Presensi diproses.");
}
</script></body></html>';
    }
}
