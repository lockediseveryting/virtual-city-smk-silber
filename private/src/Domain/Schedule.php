<?php
// Comprehensive schedule management: CSV/Excel import, validation, conflict detection,
// versioning, 1-click rollback, and live timetable lookup (class/teacher/room).
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;

final class Schedule
{
    public const MAX_ROWS = 20000;
    public const MAX_BYTES = 5242880; // 5 MB

    public const DAYS = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ];

    public const DAY_NAMES = [
        'senin' => 1,
        'selasa' => 2,
        'rabu' => 3,
        'kamis' => 4,
        'jumat' => 5,
        'sabtu' => 6,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    ];

    /**
     * Waktu lokal sekolah (jam pelajaran ditulis dalam waktu setempat; server berjalan di UTC).
     * Zona dari env SCHOOL_TZ, bawaan Asia/Jakarta (WIB).
     */
    public static function nowLocal(string $format): string
    {
        static $tz = null;
        if ($tz === null) {
            try {
                $tz = new \DateTimeZone((string)(function_exists('vc_env') ? \vc_env('SCHOOL_TZ', 'Asia/Jakarta') : 'Asia/Jakarta'));
            } catch (\Throwable $e) {
                $tz = new \DateTimeZone('Asia/Jakarta');
            }
        }
        return (new \DateTimeImmutable('now', $tz))->format($format);
    }

    /** Generate standard CSV template */
    public static function getTemplateCsv(): string
    {
        $out = fopen('php://temp', 'r+b');
        fputcsv($out, ['Hari_atau_Tanggal', 'Jam_ke', 'Mulai', 'Selesai', 'Kelas', 'Mapel', 'Guru', 'Ruang', 'Keterangan']);
        // Sample rows: Weekly base
        fputcsv($out, ['Senin', '1', '07:30', '08:15', 'X TJKT 1', 'Dasar Jaringan Komputer & Telekomunikasi', 'guru_tjkt', 'Lab. TIK 1 (Bawah)', 'Pengenalan Topologi']);
        fputcsv($out, ['Senin', '2', '08:15', '09:00', 'X TJKT 1', 'Dasar Jaringan Komputer & Telekomunikasi', 'guru_tjkt', 'Lab. TIK 1 (Bawah)', 'Kabel UTP']);
        fputcsv($out, ['Senin', '1', '07:30', '08:15', 'X AKL 1', 'Dasar-Dasar Akuntansi & Keuangan Lembaga', 'guru_akl', 'Ruang 1', 'Jurnal Umum']);
        // Sample row: Daily override example
        fputcsv($out, ['2026-10-15', '3', '09:00', '09:45', 'X TJKT 1', 'Keamanan Jaringan & Cyber Security Dasar', 'guru_cyber', 'Lab. TIK 2 (Atas)', 'Guru Pengganti (Override)']);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return (string)$csv;
    }

    /**
     * Parse raw CSV content safely.
     * Guards against formula injection and massive files.
     */
    public static function parseCsv(string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Ukuran file jadwal melebihi batas 5 MB.');
        }

        // Detect delimiter dari 10 baris pertama (baris 1 sering berisi judul/kop tanpa pemisah)
        $sample = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $content) ?: [], 0, 10));
        $counts = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];
        arsort($counts);
        $delimiter = (string)array_key_first($counts);
        if ($counts[$delimiter] === 0) {
            $delimiter = ',';
        }

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        $lineNum = 0;
        while (($data = fgetcsv($stream, 0, $delimiter)) !== false) {
            $lineNum++;
            if ($lineNum > self::MAX_ROWS) {
                break;
            }
            // Strip UTF-8 BOM on first column of first line
            if ($lineNum === 1 && isset($data[0])) {
                $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
            }
            // Clean up cell values & defuse formula injection
            $cleanRow = [];
            foreach ($data as $cell) {
                $c = trim((string)$cell);
                // Formula injection protection: prefix dangerous starts
                if ($c !== '' && in_array($c[0], ['=', '+', '-', '@'], true)) {
                    $c = "'" . $c;
                }
                $cleanRow[] = $c;
            }
            // Skip totally empty rows
            if (count(array_filter($cleanRow)) === 0) {
                continue;
            }
            $rows[] = ['line' => $lineNum, 'data' => $cleanRow];
        }
        fclose($stream);

        return $rows;
    }

    /**
     * Validate parsed rows and detect double-bookings.
     */
    public static function validate(array $rawRows, string $mode = 'weekly', ?string $targetDate = null): array
    {
        $pdo = Database::get();

        // Rapikan & perbaiki otomatis dulu (kolom, sel gabungan, hari, jam, duplikat …).
        $fixed = ScheduleFixer::normalize($rawRows);
        $rawRows = $fixed['rows'];
        $fixes = $fixed['fixes'];
        $K = [ScheduleFixer::class, 'key'];
        $P = [ScheduleFixer::class, 'personKey'];

        // Preload caches (kunci longgar: tanpa spasi/tanda baca, 10/11/12 = X/XI/XII)
        $classes = [];
        foreach ($pdo->query('SELECT id, name FROM classes WHERE deleted_at IS NULL')->fetchAll() as $c) {
            $classes[ScheduleFixer::key((string)$c['name'])] = (int)$c['id'];
        }
        $classNames = [];
        foreach ($pdo->query('SELECT id, name FROM classes WHERE deleted_at IS NULL')->fetchAll() as $c) {
            $classNames[(int)$c['id']] = (string)$c['name'];
        }

        // Kode mapel hanya dicocokkan persis; nama boleh mirip. (Dulu kode "DASARDAS" menjadi awalan
        // semua "Dasar-dasar AKL/DKV/PS" sehingga semuanya tergabung ke "Dasar-dasar TJKT".)
        $subjects = [];
        $subjectCodes = [];
        foreach ($pdo->query('SELECT id, code, name FROM subjects')->fetchAll() as $s) {
            $subjectCodes[ScheduleFixer::key((string)$s['code'])] = (int)$s['id'];
            $subjects[ScheduleFixer::key((string)$s['name'])] = (int)$s['id'];
        }

        $rooms = [];
        $roomNames = [];
        foreach ($pdo->query('SELECT id, code, name FROM rooms_physical')->fetchAll() as $r) {
            $rooms[ScheduleFixer::key((string)$r['code'])] = (int)$r['id'];
            $rooms[ScheduleFixer::key((string)$r['name'])] = (int)$r['id'];
            $roomNames[(int)$r['id']] = (string)$r['name'];
        }

        $users = [];
        foreach ($pdo->query('SELECT id, username, full_name, role FROM users WHERE deleted_at IS NULL AND role NOT IN ("murid", "murid_akses", "tamu")')->fetchAll() as $u) {
            $users[ScheduleFixer::personKey((string)$u['username'])] = (int)$u['id'];
            if ($u['full_name'] !== '') {
                $users[ScheduleFixer::personKey((string)$u['full_name'])] = (int)$u['id'];
            }
        }

        $valid = [];
        $errors = [];
        $conflicts = [];

        // Conflict tracking matrices: [day_or_date][period] => id
        $teacherSlot = [];
        $roomSlot = [];
        $classSlot = [];

        foreach ($rawRows as $idx => $item) {
            $line = $item['line'];
            $cols = $item['data'];

            // Skip header row if line 1 contains "hari" or "tanggal" or "kelas"
            if ($line === 1 && (
                stripos($cols[0] ?? '', 'hari') !== false ||
                stripos($cols[0] ?? '', 'tanggal') !== false ||
                stripos($cols[4] ?? '', 'kelas') !== false
            )) {
                continue;
            }

            $dayOrDate = trim($cols[0] ?? '');
            $period = (int)trim($cols[1] ?? '0');
            $start = trim($cols[2] ?? '');
            $end = trim($cols[3] ?? '');
            $className = trim($cols[4] ?? '');
            $subjName = trim($cols[5] ?? '');
            $teacherName = trim($cols[6] ?? '');
            $roomName = trim($cols[7] ?? '');
            $notes = trim($cols[8] ?? '');

            $rowErrors = [];

            // 1. Day / Date validation
            $dayOfWeek = 0;
            $overrideDate = null;

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayOrDate)) {
                $overrideDate = $dayOrDate;
                $timestamp = strtotime($overrideDate);
                if ($timestamp === false) {
                    $rowErrors[] = "Format tanggal tidak valid: {$dayOrDate}";
                } else {
                    $dayOfWeek = (int)date('N', $timestamp); // 1 (Mon) - 7 (Sun)
                }
            } else {
                $lowDay = strtolower($dayOrDate);
                if (isset(self::DAY_NAMES[$lowDay])) {
                    $dayOfWeek = self::DAY_NAMES[$lowDay];
                } else {
                    $rowErrors[] = "Hari tidak dikenal: {$dayOrDate}. Gunakan Senin, Selasa, Rabu, Kamis, Jumat, atau format YYYY-MM-DD.";
                }
            }

            // 2. Period validation (1 - 12; sudah dirapikan ScheduleFixer)
            if ($period < 1 || $period > 12) {
                $rowErrors[] = "Jam ke-{$period} tidak valid (harus 1 s.d. 12).";
            }

            // 3. Class lookup & auto-creation if missing
            $classId = null;
            if ($className === '') {
                $rowErrors[] = "Nama kelas wajib diisi.";
            } else {
                $lowClass = ScheduleFixer::key($className);
                $hit = ScheduleFixer::fuzzy($className, $classes, $K);
                if ($hit !== null) {
                    $classId = $hit;
                    if (isset($classNames[$hit]) && $classNames[$hit] !== $className) {
                        $fixes[] = "Baris {$line}: kelas \"{$className}\" → \"{$classNames[$hit]}\".";
                        $className = $classNames[$hit];
                    }
                } else {
                    // Kelas TIDAK dibuat otomatis (dulu kop surat / nama guru ikut jadi "kelas" palsu).
                    $rowErrors[] = "Kelas \"{$className}\" tidak ada di daftar rombel sekolah.";
                }
            }

            // 4. Subject lookup
            $subjectId = null;
            if ($subjName === '') {
                $rowErrors[] = "Mata pelajaran (Mapel) wajib diisi.";
            } else {
                $lowSubj = ScheduleFixer::key($subjName);
                $hit = $subjectCodes[$lowSubj] ?? ScheduleFixer::fuzzy($subjName, $subjects, $K);
                if ($hit !== null) {
                    $subjectId = $hit;
                } else {
                    // Mapel baru: TIDAK ditulis saat pratinjau — dibuat ketika jadwal diterapkan (apply).
                    $subjectId = null;
                    $fixes[] = "Mapel baru \"{$subjName}\" akan ditambahkan saat jadwal diterapkan.";
                }
            }

            // 5. Teacher lookup (optional, can be null / pending assignment)
            $teacherId = null;
            if ($teacherName !== '') {
                $hit = ScheduleFixer::fuzzy($teacherName, $users, $P);
                if ($hit !== null) {
                    $teacherId = $hit;
                } else {
                    // Create teacher user if not exists or mark note
                    $notes = ($notes !== '' ? $notes . ' | ' : '') . "Guru: {$teacherName}";
                }
            }

            // 6. Room lookup
            $roomId = null;
            if ($roomName !== '') {
                $hit = ScheduleFixer::fuzzy($roomName, $rooms, $K);
                if ($hit !== null) {
                    $roomId = $hit;
                    if (isset($roomNames[$hit]) && ScheduleFixer::key($roomNames[$hit]) !== ScheduleFixer::key($roomName)) {
                        $fixes[] = "Baris {$line}: ruang \"{$roomName}\" → \"{$roomNames[$hit]}\".";
                        $roomName = $roomNames[$hit];
                    }
                } else {
                    // Ruang belum terdaftar: jadwal tetap masuk, nama ruang disimpan di keterangan.
                    $notes = ($notes !== '' ? $notes . ' | ' : '') . "Ruang: {$roomName}";
                    $fixes[] = "Baris {$line}: ruang \"{$roomName}\" belum terdaftar → disimpan di keterangan.";
                }
            }

            // If there are input errors for this row, collect and continue
            if ($rowErrors !== []) {
                $errors[] = [
                    'line' => $line,
                    'errors' => $rowErrors,
                    'raw' => $cols,
                ];
                continue;
            }

            // Conflict detection key
            $timeKey = ($overrideDate ?: ('DAY_' . $dayOfWeek)) . '_P' . $period;

            // Check class collision
            if ($classId !== null) {
                if (isset($classSlot[$timeKey][$classId])) {
                    $prevLine = $classSlot[$timeKey][$classId];
                    $conflicts[] = "Baris {$line}: Kelas '{$className}' bentrok di jam ke-{$period} (sudah ada di baris {$prevLine}).";
                } else {
                    $classSlot[$timeKey][$classId] = $line;
                }
            }

            // Check teacher collision
            if ($teacherId !== null) {
                if (isset($teacherSlot[$timeKey][$teacherId])) {
                    $prevLine = $teacherSlot[$timeKey][$teacherId];
                    $conflicts[] = "Baris {$line}: Guru '{$teacherName}' bentrok jadwal mengajar di jam ke-{$period} (sudah mengajar kelas lain di baris {$prevLine}).";
                } else {
                    $teacherSlot[$timeKey][$teacherId] = $line;
                }
            }

            // Check room collision
            if ($roomId !== null) {
                if (isset($roomSlot[$timeKey][$roomId])) {
                    $prevLine = $roomSlot[$timeKey][$roomId];
                    $conflicts[] = "Baris {$line}: Ruangan '{$roomName}' bentrok di jam ke-{$period} (sudah dipakai kelas lain di baris {$prevLine}).";
                } else {
                    $roomSlot[$timeKey][$roomId] = $line;
                }
            }

            $valid[] = [
                'line' => $line,
                'day_of_week' => $dayOfWeek,
                'override_date' => $overrideDate,
                'period_number' => $period,
                'start_time' => $start,
                'end_time' => $end,
                'class_id' => $classId,
                'class_name' => $className,
                'subject_id' => $subjectId,
                'subject_name' => $subjName,
                'teacher_user_id' => $teacherId,
                'teacher_name' => $teacherName,
                'room_id' => $roomId,
                'room_name' => $roomName,
                'notes' => $notes,
            ];
        }

        return [
            'total_rows' => count($rawRows),
            'valid_count' => count($valid),
            'error_count' => count($errors),
            'conflict_count' => count($conflicts),
            'valid_rows' => $valid,
            'errors' => $errors,
            'conflicts' => $conflicts,
            'fixes' => array_values(array_unique($fixes)),
            'fix_count' => count(array_unique($fixes)),
        ];
    }

    /**
     * Atomically apply a validated schedule version.
     */
    /**
     * Pastikan baris dari klien aman sebelum disimpan: kelas harus rombel aktif, mapel dicari / dibuat
     * (mapel baru dari pratinjau), id numerik. null = baris dilewati.
     */
    private static function resolveRow(\PDO $pdo, array $r): ?array
    {
        static $classOk = null, $subjByName = [];
        if ($classOk === null) {
            $classOk = array_flip(array_map('intval', $pdo->query('SELECT id FROM classes WHERE deleted_at IS NULL')->fetchAll(\PDO::FETCH_COLUMN)));
        }
        $r['class_id'] = (int)($r['class_id'] ?? 0);
        if (!isset($classOk[$r['class_id']])) {
            return null;
        }
        $r['day_of_week'] = max(0, min(7, (int)($r['day_of_week'] ?? 0)));
        $r['override_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r['override_date'] ?? '')) ? (string)$r['override_date'] : null;
        $r['period_number'] = max(0, min(12, (int)($r['period_number'] ?? 0)));
        $sid = (int)($r['subject_id'] ?? 0);
        if ($sid <= 0) {
            $name = mb_substr(trim((string)($r['subject_name'] ?? '')), 0, 120);
            if ($name === '') {
                return null;
            }
            $key = ScheduleFixer::key($name);
            if (!isset($subjByName[$key])) {
                $st = $pdo->prepare('SELECT id FROM subjects WHERE lower(name) = lower(?) LIMIT 1');
                $st->execute([$name]);
                $found = (int)$st->fetchColumn();
                if ($found <= 0) {
                    // Kode mapel: dari kode di file ("Kode 32 KBD") atau inisial nama ("Seni Tari" → ST).
                    $code = preg_match('/Kode\s+\d+\s+([A-Za-z0-9\/]+)/', (string)($r['notes'] ?? ''), $cm) ? $cm[1] : '';
                    if ($code === '') {
                        foreach (preg_split('/\s+/', $name) ?: [] as $w) {
                            $code .= mb_substr($w, 0, 1);
                        }
                    }
                    $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $code) ?: 'MP', 0, 10));
                    $exists = $pdo->prepare('SELECT 1 FROM subjects WHERE code = ?');
                    for ($n = 2, $try = $code; ; $n++) {
                        $exists->execute([$try]);
                        if (!$exists->fetchColumn()) {
                            break;
                        }
                        $try = $code . $n;
                    }
                    $pdo->prepare('INSERT INTO subjects (code, name, created_at) VALUES (?, ?, datetime("now"))')->execute([$try, $name]);
                    $found = (int)$pdo->lastInsertId();
                }
                $subjByName[$key] = $found;
            }
            $sid = $subjByName[$key];
        }
        $r['subject_id'] = $sid;
        $r['teacher_user_id'] = !empty($r['teacher_user_id']) ? (int)$r['teacher_user_id'] : null;
        $r['room_id'] = !empty($r['room_id']) ? (int)$r['room_id'] : null;
        $r['notes'] = mb_substr((string)($r['notes'] ?? ''), 0, 300);
        return $r;
    }

    /**
     * Jam pelajaran mengikuti file jadwal sekolah: untuk tiap hari di file, time_slots disusun ulang —
     * jam ke-N memakai waktu dari file, celah di antaranya menjadi slot istirahat (≥ 40 menit = ISHOMA),
     * dan satu slot kegiatan pagi 30 menit sebelum jam ke-1. Hari tanpa jam di file tidak diubah.
     */
    private static function syncTimeSlots(\PDO $pdo, array $rows): void
    {
        $byDay = [];
        foreach ($rows as $r) {
            $d = (int)($r['day_of_week'] ?? 0);
            $p = (int)($r['period_number'] ?? 0);
            $st = (string)($r['start_time'] ?? '');
            $en = (string)($r['end_time'] ?? '');
            if ($d < 1 || $d > 7 || $p < 1 || !preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $en) || $en <= $st) {
                continue;
            }
            $byDay[$d][$p] = [$st, $en];
        }
        $toMin = static fn(string $t): int => (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2);
        $fmt = static fn(int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $del = $pdo->prepare('DELETE FROM time_slots WHERE day_of_week = ?');
        $ins = $pdo->prepare('INSERT INTO time_slots (day_of_week, period_number, start_time, end_time, is_break, label) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($byDay as $d => $periods) {
            if (count($periods) < 3) {
                continue; // terlalu sedikit untuk dianggap jam sekolah lengkap
            }
            ksort($periods);
            $del->execute([$d]);
            $first = reset($periods);
            $ins->execute([$d, 0, $fmt(max(0, $toMin($first[0]) - 30)), $first[0], 1, $d === 1 ? 'Upacara / ISMUBA & Literasi' : 'ISMUBA, Literasi & Sholat Dhuha']);
            $prevEnd = null;
            $brk = 100;
            foreach ($periods as $p => [$st, $en]) {
                if ($prevEnd !== null && $toMin($st) - $toMin($prevEnd) >= 10) {
                    $gap = $toMin($st) - $toMin($prevEnd);
                    $ins->execute([$d, $brk++, $prevEnd, $st, 1, $gap >= 40 ? 'ISHOMA (Sholat Dzuhur & Makan)' : 'Istirahat']);
                }
                $ins->execute([$d, $p, $st, $en, 0, 'Jam Ke-' . $p]);
                $prevEnd = $en;
            }
        }
    }

    public static function apply(
        array $validRows,
        string $mode,
        ?string $targetDate,
        string $filename,
        string $fileHash,
        int $userId
    ): array {
        if ($validRows === []) {
            return ['status' => 'error', 'message' => 'Tidak ada baris valid untuk diterapkan.'];
        }

        $pdo = Database::get();
        $pdo->beginTransaction();

        try {
            $versionUid = bin2hex(random_bytes(16));

            $insVer = $pdo->prepare('INSERT INTO schedule_versions
                (version_uid, mode, target_date, filename, file_hash, total_rows, valid_rows, created_by, created_at, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime("now"), "active")');
            $insVer->execute([
                $versionUid,
                $mode,
                $targetDate,
                $filename,
                $fileHash,
                count($validRows),
                count($validRows),
                $userId,
            ]);
            $versionId = (int)$pdo->lastInsertId();

            if ($mode === 'weekly') {
                // If weekly mode, archive/replace active entries for this version
                $insEntry = $pdo->prepare('INSERT INTO schedule_entries
                    (version_id, day_of_week, period_number, class_id, subject_id, teacher_user_id, room_id, notes, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime("now"))');

                foreach ($validRows as $r) {
                    $r = self::resolveRow($pdo, (array)$r);
                    if ($r === null) {
                        continue;
                    }
                    $insEntry->execute([
                        $versionId,
                        $r['day_of_week'],
                        $r['period_number'],
                        $r['class_id'],
                        $r['subject_id'],
                        $r['teacher_user_id'],
                        $r['room_id'],
                        $r['notes'] ?: null,
                    ]);
                }
                self::syncTimeSlots($pdo, $validRows);
            } else {
                // Override mode: inserts into schedule_overrides
                $insOv = $pdo->prepare('INSERT INTO schedule_overrides
                    (version_id, date, period_number, class_id, action, subject_id, teacher_user_id, room_id, notes, created_at, created_by)
                    VALUES (?, ?, ?, ?, "substitute", ?, ?, ?, ?, datetime("now"), ?)');

                foreach ($validRows as $r) {
                    $r = self::resolveRow($pdo, (array)$r);
                    if ($r === null) {
                        continue;
                    }
                    $insOv->execute([
                        $versionId,
                        $r['override_date'] ?: ($targetDate ?: self::nowLocal('Y-m-d')),
                        $r['period_number'],
                        $r['class_id'],
                        $r['subject_id'],
                        $r['teacher_user_id'],
                        $r['room_id'],
                        $r['notes'] ?: null,
                        $userId,
                    ]);
                }
            }

            $pdo->commit();

            AuditLog::log('schedule_import', [
                'version_id' => $versionId,
                'version_uid' => $versionUid,
                'mode' => $mode,
                'rows' => count($validRows),
                'filename' => $filename,
            ], $userId);

            return [
                'status' => 'success',
                'message' => "Jadwal berhasil diterapkan ({$mode}, " . count($validRows) . " baris).",
                'version_id' => $versionId,
                'version_uid' => $versionUid,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log("[Schedule::apply] " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
            return ['status' => 'error', 'message' => 'Gagal menyimpan jadwal: ' . $e->getMessage()];
        }
    }

    /**
     * 1-Click Rollback to deactivate a schedule version.
     */
    public static function rollback(int $versionId, int $userId): array
    {
        $pdo = Database::get();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM schedule_versions WHERE id = ? AND status = "active"');
            $stmt->execute([$versionId]);
            $ver = $stmt->fetch();
            if (!$ver) {
                $pdo->rollBack();
                return ['status' => 'error', 'message' => 'Versi jadwal tidak ditemukan atau sudah di-rollback.'];
            }

            // Mark version as rolled_back
            $up = $pdo->prepare('UPDATE schedule_versions SET status = "rolled_back", rollback_at = datetime("now"), rollback_by = ? WHERE id = ?');
            $up->execute([$userId, $versionId]);

            // If it was an override version, delete or deactivate entries from schedule_overrides
            if ($ver['mode'] === 'override') {
                $pdo->prepare('DELETE FROM schedule_overrides WHERE version_id = ?')->execute([$versionId]);
            }

            $pdo->commit();

            AuditLog::log('schedule_rollback', [
                'version_id' => $versionId,
                'version_uid' => $ver['version_uid'],
            ], $userId);

            return [
                'status' => 'success',
                'message' => "Versi jadwal #{$versionId} berhasil di-rollback.",
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['status' => 'error', 'message' => 'Gagal melakukan rollback: ' . $e->getMessage()];
        }
    }

    /** List recent schedule versions */
    public static function getVersions(int $limit = 20): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT v.*, u.username AS author_name
            FROM schedule_versions v
            LEFT JOIN users u ON u.id = v.created_by
            ORDER BY v.id DESC LIMIT ?');
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get active today schedule for a specific class.
     * Merges weekly base with date-specific overrides.
     */
    public static function getTodayScheduleForClass(int $classId, ?string $date = null): array
    {
        $pdo = Database::get();
        $date = $date ?: self::nowLocal('Y-m-d');
        $dayOfWeek = (int)date('N', strtotime($date));

        // Latest active weekly version
        $activeWeeklyVerId = (int)$pdo->query('SELECT id FROM schedule_versions WHERE mode = "weekly" AND status = "active" ORDER BY id DESC LIMIT 1')->fetchColumn();

        // 1. Fetch weekly base schedule
        $baseEntries = [];
        if ($activeWeeklyVerId > 0) {
            $stmt = $pdo->prepare('SELECT e.*, s.name AS subject_name, s.code AS subject_code,
                    r.name AS room_name, r.code AS room_code, r.building AS room_building, r.floor AS room_floor,
                    u.full_name AS teacher_name, u.username AS teacher_username
                FROM schedule_entries e
                JOIN subjects s ON s.id = e.subject_id
                LEFT JOIN rooms_physical r ON r.id = e.room_id
                LEFT JOIN users u ON u.id = e.teacher_user_id
                WHERE e.version_id = ? AND e.class_id = ? AND e.day_of_week = ?
                ORDER BY e.period_number ASC');
            $stmt->execute([$activeWeeklyVerId, $classId, $dayOfWeek]);
            foreach ($stmt->fetchAll() as $row) {
                $baseEntries[(int)$row['period_number']] = $row;
            }
        }

        // 2. Fetch daily overrides for this date
        $ovStmt = $pdo->prepare('SELECT o.*, s.name AS subject_name, s.code AS subject_code,
                r.name AS room_name, r.code AS room_code, r.building AS room_building, r.floor AS room_floor,
                u.full_name AS teacher_name, u.username AS teacher_username
            FROM schedule_overrides o
            JOIN subjects s ON s.id = o.subject_id
            LEFT JOIN rooms_physical r ON r.id = o.room_id
            LEFT JOIN users u ON u.id = o.teacher_user_id
            WHERE o.class_id = ? AND o.date = ?
            ORDER BY o.period_number ASC');
        $ovStmt->execute([$classId, $date]);
        foreach ($ovStmt->fetchAll() as $ov) {
            $period = (int)$ov['period_number'];
            if ($ov['action'] === 'cancel') {
                unset($baseEntries[$period]);
            } else {
                $ov['is_override'] = true;
                $baseEntries[$period] = $ov;
            }
        }

        // 3. Merge with standard time slots
        $slotStmt = $pdo->prepare('SELECT * FROM time_slots WHERE day_of_week = ? ORDER BY start_time ASC, period_number ASC');
        $slotStmt->execute([$dayOfWeek]);
        $slots = $slotStmt->fetchAll();

        $nowTime = self::nowLocal('H:i');
        $isToday = ($date === self::nowLocal('Y-m-d'));

        $schedule = [];
        foreach ($slots as $sl) {
            $period = (int)$sl['period_number'];
            $entry = $baseEntries[$period] ?? null;
            $isCurrent = $isToday && ($nowTime >= $sl['start_time'] && $nowTime <= $sl['end_time']);
            $schedule[] = [
                'period_number' => $period,
                'label' => $sl['label'],
                'start_time' => $sl['start_time'],
                'end_time' => $sl['end_time'],
                'is_break' => (bool)$sl['is_break'],
                'is_current' => $isCurrent,
                'entry' => $entry,
            ];
        }

        return [
            'date' => $date,
            'day_of_week' => $dayOfWeek,
            'day_name' => self::DAYS[$dayOfWeek] ?? 'Hari Ini',
            'class_id' => $classId,
            'current_time' => $nowTime,
            'schedule' => $schedule,
        ];
    }

    /**
     * Get active today schedule for a teacher.
     */
    public static function getTodayScheduleForTeacher(int $teacherUserId, ?string $date = null): array
    {
        $pdo = Database::get();
        $date = $date ?: self::nowLocal('Y-m-d');
        $dayOfWeek = (int)date('N', strtotime($date));

        $activeWeeklyVerId = (int)$pdo->query('SELECT id FROM schedule_versions WHERE mode = "weekly" AND status = "active" ORDER BY id DESC LIMIT 1')->fetchColumn();

        $base = [];
        if ($activeWeeklyVerId > 0) {
            $stmt = $pdo->prepare('SELECT e.*, c.name AS class_name, s.name AS subject_name,
                    r.name AS room_name, r.code AS room_code, r.floor AS room_floor
                FROM schedule_entries e
                JOIN classes c ON c.id = e.class_id
                JOIN subjects s ON s.id = e.subject_id
                LEFT JOIN rooms_physical r ON r.id = e.room_id
                WHERE e.version_id = ? AND e.teacher_user_id = ? AND e.day_of_week = ?
                ORDER BY e.period_number ASC');
            $stmt->execute([$activeWeeklyVerId, $teacherUserId, $dayOfWeek]);
            foreach ($stmt->fetchAll() as $row) {
                $base[(int)$row['period_number']] = $row;
            }
        }

        // Apply overrides
        $ovStmt = $pdo->prepare('SELECT o.*, c.name AS class_name, s.name AS subject_name,
                r.name AS room_name, r.code AS room_code, r.floor AS room_floor
            FROM schedule_overrides o
            JOIN classes c ON c.id = o.class_id
            JOIN subjects s ON s.id = o.subject_id
            LEFT JOIN rooms_physical r ON r.id = o.room_id
            WHERE o.teacher_user_id = ? AND o.date = ?
            ORDER BY o.period_number ASC');
        $ovStmt->execute([$teacherUserId, $date]);
        foreach ($ovStmt->fetchAll() as $ov) {
            $p = (int)$ov['period_number'];
            if ($ov['action'] === 'cancel') {
                unset($base[$p]);
            } else {
                $ov['is_override'] = true;
                $base[$p] = $ov;
            }
        }

        ksort($base);
        $nowTime = self::nowLocal('H:i');
        $isToday = ($date === self::nowLocal('Y-m-d'));
        foreach ($base as &$bEntry) {
            $bEntry['is_current'] = false;
            if ($isToday && !empty($bEntry['start_time']) && !empty($bEntry['end_time'])) {
                $bEntry['is_current'] = ($nowTime >= $bEntry['start_time'] && $nowTime <= $bEntry['end_time']);
            }
        }
        unset($bEntry);

        return [
            'date' => $date,
            'day_name' => self::DAYS[$dayOfWeek] ?? 'Hari Ini',
            'teacher_user_id' => $teacherUserId,
            'current_time' => $nowTime,
            'entries' => array_values($base),
        ];
    }

    /**
     * Find teacher current location based on current time.
     * Answers: "Guru X sedang di mana?"
     */
    public static function findTeacherLocation(int $teacherUserId, ?string $currentTime = null, ?string $date = null): ?array
    {
        $pdo = Database::get();
        $date = $date ?: self::nowLocal('Y-m-d');
        $currentTime = $currentTime ?: self::nowLocal('H:i');
        $dayOfWeek = (int)date('N', strtotime($date));

        // Find which slot matches current time
        $slotStmt = $pdo->prepare('SELECT * FROM time_slots WHERE day_of_week = ? AND start_time <= ? AND end_time >= ? LIMIT 1');
        $slotStmt->execute([$dayOfWeek, $currentTime, $currentTime]);
        $slot = $slotStmt->fetch();

        if (!$slot) {
            return [
                'status' => 'outside_hours',
                'message' => 'Di luar jam kegiatan belajar mengajar.',
                'current_time' => $currentTime,
            ];
        }

        if ((int)$slot['is_break'] === 1) {
            return [
                'status' => 'break',
                'message' => "Sedang waktu istirahat: {$slot['label']}",
                'current_time' => $currentTime,
            ];
        }

        $today = self::getTodayScheduleForTeacher($teacherUserId, $date);
        $period = (int)$slot['period_number'];
        foreach ($today['entries'] as $e) {
            if ((int)$e['period_number'] === $period) {
                return [
                    'status' => 'in_class',
                    'class_name' => $e['class_name'],
                    'subject_name' => $e['subject_name'],
                    'room_name' => $e['room_name'] ?? 'Ruang Kelas',
                    'room_code' => $e['room_code'] ?? '',
                    'room_floor' => $e['room_floor'] ?? 1,
                    'period' => $period,
                    'current_time' => $currentTime,
                ];
            }
        }

        return [
            'status' => 'free',
            'message' => 'Tidak ada jadwal mengajar pada jam ini (mungkin di Ruang Guru/Kantor).',
            'current_time' => $currentTime,
        ];
    }

    /**
     * Save a single schedule slot (manual entry) into weekly base or daily override.
     */
    public static function saveSingleSlot(array $data, int $userId): array
    {
        $pdo = Database::get();
        $mode = ($data['mode'] ?? 'weekly') === 'override' ? 'override' : 'weekly';
        $period = (int)($data['period_number'] ?? 1);
        if ($period < 1 || $period > 10) {
            return ['status' => 'error', 'message' => 'Jam ke-' . $period . ' tidak valid (1 s.d. 10).'];
        }

        $classId = (int)($data['class_id'] ?? 0);
        if ($classId <= 0) {
            return ['status' => 'error', 'message' => 'Kelas harus dipilih.'];
        }

        // Subject resolution
        $subjectId = (int)($data['subject_id'] ?? 0);
        $subjectName = trim((string)($data['subject_name'] ?? ''));
        if ($subjectId <= 0 && $subjectName !== '') {
            $st = $pdo->prepare('SELECT id FROM subjects WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $st->execute([$subjectName]);
            $existingSubjId = $st->fetchColumn();
            if ($existingSubjId) {
                $subjectId = (int)$existingSubjId;
            } else {
                $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $subjectName), 0, 8)) ?: 'MP_' . bin2hex(random_bytes(3));
                $ins = $pdo->prepare('INSERT INTO subjects (code, name, created_at) VALUES (?, ?, datetime("now"))');
                try {
                    $ins->execute([$code, $subjectName]);
                    $subjectId = (int)$pdo->lastInsertId();
                } catch (\Throwable $e) {
                    $ins->execute(['MP_' . bin2hex(random_bytes(4)), $subjectName]);
                    $subjectId = (int)$pdo->lastInsertId();
                }
            }
        }

        if ($subjectId <= 0) {
            return ['status' => 'error', 'message' => 'Mata pelajaran harus dipilih atau diisi.'];
        }

        $teacherId = !empty($data['teacher_user_id']) ? (int)$data['teacher_user_id'] : null;
        $roomId = !empty($data['room_id']) ? (int)$data['room_id'] : null;
        $notes = trim((string)($data['notes'] ?? ''));

        if ($mode === 'override') {
            $targetDate = trim((string)($data['target_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                $targetDate = self::nowLocal('Y-m-d');
            }

            // Conflict check for override:
            if ($teacherId !== null) {
                $chk = $pdo->prepare('SELECT c.name FROM schedule_overrides o
                    JOIN classes c ON c.id = o.class_id
                    WHERE o.date = ? AND o.period_number = ? AND o.teacher_user_id = ? AND o.class_id != ? LIMIT 1');
                $chk->execute([$targetDate, $period, $teacherId, $classId]);
                $cName = $chk->fetchColumn();
                if ($cName) {
                    return ['status' => 'conflict', 'message' => "Bentrok: Guru tersebut sudah terjadwal mengajar di kelas {$cName} pada tanggal {$targetDate} jam ke-{$period}."];
                }
            }
            if ($roomId !== null) {
                $chk = $pdo->prepare('SELECT c.name FROM schedule_overrides o
                    JOIN classes c ON c.id = o.class_id
                    WHERE o.date = ? AND o.period_number = ? AND o.room_id = ? AND o.class_id != ? LIMIT 1');
                $chk->execute([$targetDate, $period, $roomId, $classId]);
                $cName = $chk->fetchColumn();
                if ($cName) {
                    return ['status' => 'conflict', 'message' => "Bentrok: Ruangan tersebut sudah digunakan oleh kelas {$cName} pada tanggal {$targetDate} jam ke-{$period}."];
                }
            }

            $pdo->beginTransaction();
            try {
                $versionUid = bin2hex(random_bytes(16));
                $insVer = $pdo->prepare('INSERT INTO schedule_versions
                    (version_uid, mode, target_date, filename, file_hash, total_rows, valid_rows, created_by, created_at, status)
                    VALUES (?, "override", ?, "manual_entry", "manual", 1, 1, ?, datetime("now"), "active")');
                $insVer->execute([$versionUid, $targetDate, $userId]);
                $verId = (int)$pdo->lastInsertId();

                $pdo->prepare('DELETE FROM schedule_overrides WHERE class_id = ? AND date = ? AND period_number = ?')
                    ->execute([$classId, $targetDate, $period]);

                $ins = $pdo->prepare('INSERT INTO schedule_overrides
                    (version_id, date, period_number, class_id, action, subject_id, teacher_user_id, room_id, notes, created_at, created_by)
                    VALUES (?, ?, ?, ?, "substitute", ?, ?, ?, ?, datetime("now"), ?)');
                $ins->execute([$verId, $targetDate, $period, $classId, $subjectId, $teacherId, $roomId, $notes ?: null, $userId]);

                $pdo->commit();
                AuditLog::log('schedule_slot_override', ['date' => $targetDate, 'period' => $period, 'class_id' => $classId], $userId);

                return ['status' => 'success', 'message' => "Jadwal pengganti berhasil disimpan untuk tanggal {$targetDate} (Jam ke-{$period}).", 'version_id' => $verId];
            } catch (\Throwable $e) {
                $pdo->rollBack();
                error_log("[Schedule::apply] " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
            return ['status' => 'error', 'message' => 'Gagal menyimpan jadwal: ' . $e->getMessage()];
            }
        } else {
            // Mode weekly
            $dayOfWeek = (int)($data['day_of_week'] ?? 1);
            if ($dayOfWeek < 1 || $dayOfWeek > 6) {
                $dayOfWeek = 1;
            }

            $activeWeeklyVerId = (int)$pdo->query('SELECT id FROM schedule_versions WHERE mode = "weekly" AND status = "active" ORDER BY id DESC LIMIT 1')->fetchColumn();

            if ($activeWeeklyVerId > 0) {
                if ($teacherId !== null) {
                    $chk = $pdo->prepare('SELECT c.name FROM schedule_entries e
                        JOIN classes c ON c.id = e.class_id
                        WHERE e.version_id = ? AND e.day_of_week = ? AND e.period_number = ? AND e.teacher_user_id = ? AND e.class_id != ? LIMIT 1');
                    $chk->execute([$activeWeeklyVerId, $dayOfWeek, $period, $teacherId, $classId]);
                    $cName = $chk->fetchColumn();
                    if ($cName) {
                        $dayName = self::DAYS[$dayOfWeek] ?? 'hari tersebut';
                        return ['status' => 'conflict', 'message' => "Bentrok: Guru tersebut sudah terjadwal mengajar di kelas {$cName} pada {$dayName} jam ke-{$period}."];
                    }
                }
                if ($roomId !== null) {
                    $chk = $pdo->prepare('SELECT c.name FROM schedule_entries e
                        JOIN classes c ON c.id = e.class_id
                        WHERE e.version_id = ? AND e.day_of_week = ? AND e.period_number = ? AND e.room_id = ? AND e.class_id != ? LIMIT 1');
                    $chk->execute([$activeWeeklyVerId, $dayOfWeek, $period, $roomId, $classId]);
                    $cName = $chk->fetchColumn();
                    if ($cName) {
                        $dayName = self::DAYS[$dayOfWeek] ?? 'hari tersebut';
                        return ['status' => 'conflict', 'message' => "Bentrok: Ruangan tersebut sudah digunakan oleh kelas {$cName} pada {$dayName} jam ke-{$period}."];
                    }
                }
            }

            $pdo->beginTransaction();
            try {
                $existingEntries = [];
                if ($activeWeeklyVerId > 0) {
                    $st = $pdo->prepare('SELECT day_of_week, period_number, class_id, subject_id, teacher_user_id, room_id, notes
                        FROM schedule_entries WHERE version_id = ?');
                    $st->execute([$activeWeeklyVerId]);
                    $existingEntries = $st->fetchAll(\PDO::FETCH_ASSOC);
                }

                $versionUid = bin2hex(random_bytes(16));
                $insVer = $pdo->prepare('INSERT INTO schedule_versions
                    (version_uid, mode, target_date, filename, file_hash, total_rows, valid_rows, created_by, created_at, status)
                    VALUES (?, "weekly", NULL, "manual_entry", "manual", ?, ?, ?, datetime("now"), "active")');
                $insVer->execute([$versionUid, count($existingEntries) + 1, count($existingEntries) + 1, $userId]);
                $newVerId = (int)$pdo->lastInsertId();

                $insEntry = $pdo->prepare('INSERT INTO schedule_entries
                    (version_id, day_of_week, period_number, class_id, subject_id, teacher_user_id, room_id, notes, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime("now"))');

                $replaced = false;
                foreach ($existingEntries as $entry) {
                    if ((int)$entry['class_id'] === $classId && (int)$entry['day_of_week'] === $dayOfWeek && (int)$entry['period_number'] === $period) {
                        $insEntry->execute([$newVerId, $dayOfWeek, $period, $classId, $subjectId, $teacherId, $roomId, $notes ?: null]);
                        $replaced = true;
                    } else {
                        $insEntry->execute([
                            $newVerId,
                            (int)$entry['day_of_week'],
                            (int)$entry['period_number'],
                            (int)$entry['class_id'],
                            (int)$entry['subject_id'],
                            $entry['teacher_user_id'] ? (int)$entry['teacher_user_id'] : null,
                            $entry['room_id'] ? (int)$entry['room_id'] : null,
                            $entry['notes'],
                        ]);
                    }
                }

                if (!$replaced) {
                    $insEntry->execute([$newVerId, $dayOfWeek, $period, $classId, $subjectId, $teacherId, $roomId, $notes ?: null]);
                }

                $pdo->commit();
                $dayName = self::DAYS[$dayOfWeek] ?? 'Hari Ini';
                AuditLog::log('schedule_slot_save', ['day' => $dayOfWeek, 'period' => $period, 'class_id' => $classId], $userId);

                return ['status' => 'success', 'message' => "Jadwal mingguan berhasil disimpan untuk {$dayName} (Jam ke-{$period}).", 'version_id' => $newVerId];
            } catch (\Throwable $e) {
                $pdo->rollBack();
                error_log("[Schedule::apply] " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
            return ['status' => 'error', 'message' => 'Gagal menyimpan jadwal: ' . $e->getMessage()];
            }
        }
    }

    /**
     * Delete or clear a single slot.
     */
    public static function deleteSingleSlot(array $data, int $userId): array
    {
        $pdo = Database::get();
        $mode = ($data['mode'] ?? 'weekly') === 'override' ? 'override' : 'weekly';
        $period = (int)($data['period_number'] ?? 0);
        $classId = (int)($data['class_id'] ?? 0);

        if ($period < 1 || $period > 10 || $classId <= 0) {
            return ['status' => 'error', 'message' => 'Parameter slot tidak valid.'];
        }

        if ($mode === 'override') {
            $targetDate = trim((string)($data['target_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                $targetDate = self::nowLocal('Y-m-d');
            }
            $pdo->prepare('DELETE FROM schedule_overrides WHERE class_id = ? AND date = ? AND period_number = ?')
                ->execute([$classId, $targetDate, $period]);
            AuditLog::log('schedule_slot_override_delete', ['date' => $targetDate, 'period' => $period, 'class_id' => $classId], $userId);
            return ['status' => 'success', 'message' => "Jadwal pengganti pada tanggal {$targetDate} (Jam ke-{$period}) berhasil dihapus."];
        } else {
            $dayOfWeek = (int)($data['day_of_week'] ?? 1);
            $activeWeeklyVerId = (int)$pdo->query('SELECT id FROM schedule_versions WHERE mode = "weekly" AND status = "active" ORDER BY id DESC LIMIT 1')->fetchColumn();
            if ($activeWeeklyVerId <= 0) {
                return ['status' => 'success', 'message' => 'Tidak ada jadwal aktif untuk dihapus.'];
            }

            $st = $pdo->prepare('SELECT day_of_week, period_number, class_id, subject_id, teacher_user_id, room_id, notes
                FROM schedule_entries WHERE version_id = ?');
            $st->execute([$activeWeeklyVerId]);
            $existingEntries = $st->fetchAll(\PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            try {
                $versionUid = bin2hex(random_bytes(16));
                $insVer = $pdo->prepare('INSERT INTO schedule_versions
                    (version_uid, mode, target_date, filename, file_hash, total_rows, valid_rows, created_by, created_at, status)
                    VALUES (?, "weekly", NULL, "manual_delete", "manual", ?, ?, ?, datetime("now"), "active")');
                $insVer->execute([$versionUid, max(0, count($existingEntries) - 1), max(0, count($existingEntries) - 1), $userId]);
                $newVerId = (int)$pdo->lastInsertId();

                $insEntry = $pdo->prepare('INSERT INTO schedule_entries
                    (version_id, day_of_week, period_number, class_id, subject_id, teacher_user_id, room_id, notes, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime("now"))');

                foreach ($existingEntries as $entry) {
                    if ((int)$entry['class_id'] === $classId && (int)$entry['day_of_week'] === $dayOfWeek && (int)$entry['period_number'] === $period) {
                        continue;
                    }
                    $insEntry->execute([
                        $newVerId,
                        (int)$entry['day_of_week'],
                        (int)$entry['period_number'],
                        (int)$entry['class_id'],
                        (int)$entry['subject_id'],
                        $entry['teacher_user_id'] ? (int)$entry['teacher_user_id'] : null,
                        $entry['room_id'] ? (int)$entry['room_id'] : null,
                        $entry['notes'],
                    ]);
                }

                $pdo->commit();
                AuditLog::log('schedule_slot_delete', ['day' => $dayOfWeek, 'period' => $period, 'class_id' => $classId], $userId);
                return ['status' => 'success', 'message' => "Jadwal jam ke-{$period} berhasil dihapus dari jadwal mingguan.", 'version_id' => $newVerId];
            } catch (\Throwable $e) {
                $pdo->rollBack();
                return ['status' => 'error', 'message' => 'Gagal menghapus jadwal: ' . $e->getMessage()];
            }
        }
    }
}
