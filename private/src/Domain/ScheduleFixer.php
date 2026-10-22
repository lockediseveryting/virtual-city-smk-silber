<?php
// ScheduleFixer: merapikan & memperbaiki otomatis berkas jadwal (CSV/Excel) SEBELUM divalidasi.
//  - Kolom dikenali dari judulnya (urutan bebas, sinonim: Hari/Day, Jam/Ke, Mulai/Start, Rombel/Kelas, …).
//  - Sel gabungan Excel (hari/kelas kosong di baris bawahnya) diisi dari baris sebelumnya.
//  - Hari salah ketik ("snin", "JUM'AT", "Kamis,"), tanggal dd/mm/yyyy, "15 Oktober 2026", serial Excel.
//  - Jam "7.30", "0730", "7:30:00", pecahan Excel (0.3125), rentang "07.30-08.15" di satu sel.
//  - "Jam ke" "Jam 1", "ke-1", rentang "1-2" (dipecah jadi 2 baris), kosong → lanjut dari baris sebelumnya.
//  - Selesai kosong → Mulai + 45 menit; Mulai > Selesai → ditukar. Baris duplikat persis → dibuang.
// Setiap perbaikan dicatat di 'fixes' agar guru tahu apa yang diubah.
declare(strict_types=1);

namespace VC\Domain;

final class ScheduleFixer
{
    private const COLS = ['day', 'period', 'start', 'end', 'class', 'subject', 'teacher', 'room', 'notes'];
    private const HEADER = [
        'day' => ['hari', 'tanggal', 'hari_atau_tanggal', 'day', 'date', 'tgl'],
        'period' => ['jam_ke', 'jamke', 'jam', 'ke', 'period', 'jp', 'jam_pelajaran', 'sesi'],
        'start' => ['mulai', 'start', 'jam_mulai', 'dari', 'waktu_mulai', 'waktu'],
        'end' => ['selesai', 'end', 'jam_selesai', 'sampai', 'akhir', 'waktu_selesai'],
        'class' => ['kelas', 'rombel', 'class', 'rombongan_belajar'],
        'subject' => ['mapel', 'mata_pelajaran', 'pelajaran', 'subject', 'matpel'],
        'teacher' => ['guru', 'pengajar', 'teacher', 'nama_guru', 'guru_pengampu'],
        'room' => ['ruang', 'ruangan', 'room', 'lokasi', 'tempat', 'kelas_ruang'],
        'notes' => ['keterangan', 'catatan', 'notes', 'ket', 'materi'],
    ];
    private const DAYS = ['senin' => 'Senin', 'selasa' => 'Selasa', 'rabu' => 'Rabu', 'kamis' => 'Kamis', 'jumat' => 'Jumat', 'sabtu' => 'Sabtu',
        'monday' => 'Senin', 'tuesday' => 'Selasa', 'wednesday' => 'Rabu', 'thursday' => 'Kamis', 'friday' => 'Jumat', 'saturday' => 'Sabtu'];
    private const MONTHS = ['januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12, 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6,
        'jul' => 7, 'agu' => 8, 'agt' => 8, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12, 'may' => 5];
    public const LESSON_MINUTES = 45;

    /** Kunci pembanding longgar: huruf kecil, tanpa spasi/tanda baca; 10/11/12 → x/xi/xii di awal. */
    public static function key(string $s): string
    {
        $s = strtolower(trim($s));
        if (preg_match('/^(10|11|12)(?=\D|$)/', $s, $m)) {
            $s = ['10' => 'x', '11' => 'xi', '12' => 'xii'][$m[1]] . substr($s, 2);
        }
        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }

    /** Nama guru tanpa sapaan & gelar ("Bu Sari Dewi, S.Pd." → "sari dewi"). */
    public static function personKey(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/,.*$/', '', $s) ?? $s;                       // buang gelar belakang
        $s = preg_replace('/\b(drs?|ir|hj?|pak|bu|ibu|bapak|mr|mrs|ms)\.?\s+/', '', $s) ?? $s;
        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }

    /**
     * Cari nilai di peta [kunci => id] dengan pencocokan longgar lalu salah ketik kecil (Levenshtein).
     * @param array<string,int> $map  kunci sudah dinormalisasi dengan $keyFn
     */
    public static function fuzzy(string $needle, array $map, callable $keyFn): ?int
    {
        $k = $keyFn($needle);
        if ($k === '') {
            return null;
        }
        if (isset($map[$k])) {
            return $map[$k];
        }
        $best = null;
        $bestD = PHP_INT_MAX;
        $limit = strlen($k) <= 4 ? 0 : (strlen($k) <= 8 ? 1 : 2);
        $digits = preg_replace('/\D/', '', $k);
        foreach ($map as $cand => $id) {
            $cand = (string)$cand;
            // angka harus sama persis: "X TJKT 5" tidak boleh jadi "X TJKT 1", "Ruang 2" ≠ "Ruang 3"
            if ($cand === '' || preg_replace('/\D/', '', $cand) !== $digits) {
                continue;
            }
            // awalan unik ("labtik1" ↔ "labtik1bawah") juga diterima
            if (strlen($k) >= 5 && (str_starts_with($cand, $k) || str_starts_with($k, $cand)) && abs(strlen($cand) - strlen($k)) <= 8) {
                $d = abs(strlen($cand) - strlen($k)) / 10;
            } else {
                $d = levenshtein($k, $cand);
            }
            if ($d < $bestD) {
                $bestD = $d;
                $best = $id;
            }
        }
        return $bestD <= $limit ? $best : null;
    }

    /**
     * @param array<int,array{line:int,data:array}> $rawRows hasil Schedule::parseCsv
     * @return array{rows:array<int,array{line:int,data:array}>,fixes:string[]}
     */
    /**
     * Format MATRIKS jadwal sekolah (seperti "JADWAL BLOK FULL DAY" SMK Muhammadiyah 2 Palembang):
     *   blok per hari: baris "JAM KE- | WAKTU (WIB) | SENIN", baris tingkat (X / XI / XII, sel gabungan),
     *   baris nama rombel (TJKT 1, PS 1, AK 2 …), lalu baris jam: "1 | 08.15 - 08.40 | 30 SEJ | 45 DPKT …".
     *   Sel = kode guru + kode mapel; legenda "Kode dan Nama Guru" & "Kode dan Nama Mata Pelajaran" di kanan.
     * Diubah menjadi baris standar (Hari, Jam_ke, Mulai, Selesai, Kelas, Mapel, Guru, Ruang, Keterangan).
     * @return array{0: array, 1: array}|null  [baris standar, perbaikan] atau null bila bukan format matriks
     */
    public static function fromMatrix(array $rawRows): ?array
    {
        $dayRe = '/^(senin|selasa|rabu|kamis|jum\W?at|sabtu|minggu)$/i';
        $isDayHeader = static function (array $d) use ($dayRe): ?array {
            $hasJam = false;
            foreach (array_slice($d, 0, 3) as $c) {
                if (preg_match('/\b(jam|waktu)\b/i', (string)$c)) {
                    $hasJam = true;
                }
            }
            if (!$hasJam) {
                return null;
            }
            foreach ($d as $i => $c) {
                if ($i >= 1 && preg_match($dayRe, trim((string)$c))) {
                    return [$i, trim((string)$c)];
                }
            }
            return null;
        };
        $blocks = 0;
        foreach ($rawRows as $r) {
            if ($isDayHeader($r['data'])) {
                $blocks++;
            }
        }
        if ($blocks === 0) {
            return null;
        }

        // Legenda: kode guru (angka) → nama; kode mapel (huruf) → nama. Ambil dari kolom setelah kolom kelas.
        $teachers = [];
        $subjects = [];
        $legendFrom = PHP_INT_MAX;
        $fixes = ['Format MATRIKS jadwal sekolah dikenali (' . $blocks . ' hari) — diubah otomatis menjadi daftar jadwal per kelas.'];
        $out = [['line' => 0, 'data' => ['Hari', 'Jam_ke', 'Mulai', 'Selesai', 'Kelas', 'Mapel', 'Guru', 'Ruang', 'Keterangan']]];
        $day = '';
        $dayCol = 2;
        $grades = [];
        $classes = [];
        $stage = 0;
        $skipped = ['pkl' => 0, 'pagi' => 0, 'istirahat' => 0, 'kosong' => 0];
        $alias = static function (string $grade, string $name): string {
            $n = strtoupper(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
            $n = preg_replace(['/^TKJ\b/', '/^AK\b(?!L)/', '/^PS\b/'], ['TJKT', 'AKL', 'BD'], $n) ?? $n;
            if (!preg_match('/\d$/', $n)) {
                $n .= ' 1';
            }
            return trim($grade . ' ' . $n);
        };

        // Kolom legenda = kolom paling kanan setelah kolom kelas; cari dulu batas kolom kelas dari baris rombel pertama.
        foreach ($rawRows as $idx => $r) {
            $d = $r['data'];
            $hdr = $isDayHeader($d);
            if ($hdr) {
                [$dayCol, $day] = $hdr;
                $day = ucfirst(strtolower(str_replace(["'", '’'], '', $day)));
                $stage = 1;
                $grades = [];
                $classes = [];
                continue;
            }
            if ($stage === 1) { // baris tingkat X / XI / XII (sel gabungan → isi ke kanan)
                $g = '';
                for ($c = $dayCol; $c < count($d); $c++) {
                    $v = strtoupper(trim((string)$d[$c]));
                    if (preg_match('/^(XII|XI|X)$/', $v)) {
                        $g = $v;
                    }
                    $grades[$c] = $g;
                }
                $stage = 2;
                continue;
            }
            if ($stage === 2) { // baris nama rombel
                for ($c = $dayCol; $c < count($d); $c++) {
                    $v = trim((string)$d[$c]);
                    if ($v === '') {
                        break; // kolom kelas berakhir di sel kosong pertama (sisanya legenda)
                    }
                    $classes[$c] = $alias($grades[$c] ?? '', $v);
                }
                if ($classes !== []) {
                    $legendFrom = min($legendFrom, max(array_keys($classes)) + 1);
                }
                $stage = 3;
                continue;
            }
            if ($stage !== 3) {
                continue;
            }
            $jam = trim((string)($d[0] ?? ''));
            $waktu = trim((string)($d[1] ?? ''));
            if (!preg_match('/^\d{1,2}$/', $jam)) {
                // baris tanpa nomor jam: istirahat / ISHOMA / kegiatan tambahan pagi / sholat ashar
                foreach ($classes as $c => $cn) {
                    if (trim((string)($d[$c] ?? '')) !== '') {
                        $skipped[preg_match('/istirahat|ishoma/i', (string)($d[$c] ?? '')) ? 'istirahat' : 'pagi']++;
                        break;
                    }
                }
                continue;
            }
            $period = (int)$jam;
            if ($period === 0) {
                $skipped['pagi']++;
                continue;
            }
            $start = $end = '';
            if (preg_match('/(\d{1,2})[.:](\d{2})\s*[-–]\s*(\d{1,2})[.:](\d{2})/', $waktu, $m)) {
                $start = sprintf('%02d:%02d', $m[1], $m[2]);
                $end = sprintf('%02d:%02d', $m[3], $m[4]);
            }
            foreach ($classes as $c => $cn) {
                $cell = trim((string)($d[$c] ?? ''));
                if ($cell === '') {
                    $skipped['kosong']++;
                    continue;
                }
                if (preg_match('/^pkl$/i', $cell)) {
                    $skipped['pkl']++;
                    continue;
                }
                if (!preg_match('/^(\d{1,3})\s+([A-Za-z][A-Za-z0-9\/.\-]*)(?:\s+([A-Za-z]{1,4}))?$/', $cell, $m)) {
                    $skipped['istirahat']++;
                    continue;
                }
                $out[] = ['line' => (int)$r['line'], 'data' => [$day, (string)$period, $start, $end, $cn, '@S:' . strtoupper($m[2]), '@T:' . $m[1], isset($m[3]) ? strtoupper($m[3]) : '', 'Kode: ' . $cell]];
            }
        }
        if (count($out) <= 1) {
            return null;
        }
        // Legenda (bisa di mana saja di kolom kanan)
        foreach ($rawRows as $r) {
            $d = array_values($r['data']);
            for ($c = $legendFrom === PHP_INT_MAX ? 0 : $legendFrom; $c < count($d) - 1; $c++) {
                $k = trim((string)$d[$c]);
                $v = trim((string)($d[$c + 1] ?? ''));
                if ($v === '' || preg_match('/^(kode|nama)/i', $v)) {
                    continue;
                }
                if (preg_match('/^\d{1,3}$/', $k) && preg_match('/[a-z]{3}/i', $v)) {
                    $teachers[(string)(int)$k] = $v;
                } elseif (preg_match('/^[A-Z][A-Z0-9\/]{1,9}$/', $k) && preg_match('/[a-z]{3}/', $v)) {
                    $subjects[$k] = $v;
                }
            }
        }
        // Kode umum yang sering tidak tercantum di legenda (perkiraan, ditandai di daftar perbaikan).
        // DPKT/DPAK/DPKD = mapel dasar program keahlian (dikonfirmasi sekolah 16 Okt 2026), terpisah dari DAKL/DDKV/DPS di legenda.
        $guess = ['DPKT' => 'Dasar-dasar Program Keahlian TJKT', 'DPAK' => 'Dasar-dasar Program Keahlian AKL', 'DPKD' => 'Dasar-dasar Program Keahlian DKV', 'DPBD' => 'Dasar-dasar Program Keahlian Bisnis Digital'];
        $guessed = [];
        $unknownS = [];
        foreach ($out as $i => &$row) {
            if ($i === 0) {
                continue;
            }
            $sc = substr($row['data'][5], 3);
            $tc = (string)(int)substr($row['data'][6], 3);
            if (!isset($subjects[$sc]) && isset($guess[$sc])) {
                $guessed[$sc] = $guess[$sc];
            }
            $row['data'][5] = $subjects[$sc] ?? $guess[$sc] ?? $sc;
            if (!isset($subjects[$sc]) && !isset($guess[$sc])) {
                $unknownS[$sc] = true;
            }
            $row['data'][6] = $teachers[$tc] ?? ('Kode guru ' . $tc);
            $row['data'][8] = 'Kode ' . $tc . ' ' . $sc;
        }
        unset($row);
        $fixes[] = 'Rombel diselaraskan dengan sistem: TKJ → TJKT, AK → AKL, PS → BD, nama tanpa nomor → nomor 1 (mis. "BD" → "BD 1").';
        $fixes[] = 'Kode guru (' . count($teachers) . ') & kode mapel (' . count($subjects) . ') dibaca dari legenda dan diganti nama lengkap.';
        if ($skipped['pkl'] > 0) {
            $fixes[] = $skipped['pkl'] . ' sel "PKL" (praktik kerja lapangan) tidak dijadwalkan sebagai pelajaran.';
        }
        if ($skipped['pagi'] > 0) {
            $fixes[] = 'Jam ke-0 (ISMUBA, literasi & sholat dhuha) dan kegiatan pagi tambahan dilewati.';
        }
        if ($skipped['istirahat'] > 0) {
            $fixes[] = 'Baris istirahat / ISHOMA / sholat dilewati.';
        }
        foreach ($guessed as $k => $v) {
            $fixes[] = 'Kode mapel "' . $k . '" (tidak ada di legenda) = "' . $v . '".';
        }
        if ($unknownS !== []) {
            $fixes[] = 'Kode mapel tanpa keterangan di legenda dipakai apa adanya: ' . implode(', ', array_keys($unknownS)) . '.';
        }
        return [$out, $fixes];
    }

    public static function normalize(array $rawRows): array
    {
        $fixes = [];
        $matrix = self::fromMatrix($rawRows);
        if ($matrix !== null) {
            [$rawRows, $fixes] = $matrix;
        }
        $map = range(0, 8); // urutan kolom standar
        $startIdx = 0;

        // 1. Kenali baris judul (di 5 baris pertama) & petakan kolom
        foreach (array_slice($rawRows, 0, 5, true) as $i => $r) {
            $found = self::mapHeader($r['data']);
            if ($found !== null) {
                $map = $found;
                $startIdx = $i + 1;
                if ($found !== range(0, 8)) {
                    $fixes[] = 'Urutan kolom dikenali otomatis dari judul kolom.';
                }
                if ($i > 0) {
                    $fixes[] = 'Baris judul/kop di atas tabel (' . $i . ' baris) dilewati.';
                }
                break;
            }
        }

        $out = [];
        $seen = [];
        $prev = ['day' => '', 'class' => '', 'period' => 0, 'end' => ''];
        foreach (array_slice($rawRows, $startIdx) as $r) {
            $line = (int)$r['line'];
            $c = [];
            foreach (self::COLS as $i => $name) {
                $idx = $map[$i];
                $v = $idx === null ? '' : (string)($r['data'][$idx] ?? '');
                $v = trim(preg_replace('/\s+/u', ' ', ltrim($v, "'")) ?? '');
                $c[$name] = $v;
            }
            if (implode('', $c) === '') {
                continue;
            }

            // 2. Sel gabungan: hari & kelas kosong → ikut baris sebelumnya
            foreach (['day', 'class'] as $f) {
                if ($c[$f] === '' && $prev[$f] !== '' && ($c['subject'] !== '' || $c['start'] !== '')) {
                    $c[$f] = $prev[$f];
                    $fixes[] = "Baris {$line}: kolom " . ($f === 'day' ? 'Hari' : 'Kelas') . " kosong (sel gabungan) → diisi \"{$prev[$f]}\".";
                }
            }

            // 3. Hari / tanggal
            $d = self::fixDay($c['day']);
            if ($d !== null && $d !== $c['day']) {
                $fixes[] = "Baris {$line}: hari/tanggal \"{$c['day']}\" → \"{$d}\".";
                $c['day'] = $d;
            }

            // 4. Jam mulai/selesai (termasuk rentang dalam satu sel)
            if ($c['end'] === '' && preg_match('/^(.+?)\s*(?:-|–|—|s\.?d\.?|sampai|to)\s*(.+)$/iu', $c['start'], $m) && self::fixTime($m[1]) !== null && self::fixTime($m[2]) !== null) {
                $c['start'] = $m[1];
                $c['end'] = $m[2];
                $fixes[] = "Baris {$line}: rentang jam dalam satu sel dipisah jadi Mulai & Selesai.";
            }
            foreach (['start', 'end'] as $f) {
                if ($c[$f] === '') {
                    continue;
                }
                $t = self::fixTime($c[$f]);
                if ($t !== null && $t !== $c[$f]) {
                    $fixes[] = "Baris {$line}: jam \"{$c[$f]}\" → \"{$t}\".";
                    $c[$f] = $t;
                }
            }
            if ($c['start'] === '' && $prev['end'] !== '' && $c['day'] === $prev['day'] && $c['class'] === $prev['class']) {
                $c['start'] = $prev['end'];
                $fixes[] = "Baris {$line}: jam mulai kosong → \"{$c['start']}\" (lanjut dari jam sebelumnya).";
            }
            if ($c['start'] !== '' && $c['end'] === '' && self::fixTime($c['start']) !== null) {
                $c['end'] = self::addMinutes($c['start'], self::LESSON_MINUTES);
                $fixes[] = "Baris {$line}: jam selesai kosong → \"{$c['end']}\" (" . self::LESSON_MINUTES . ' menit).';
            }
            if ($c['start'] !== '' && $c['end'] !== '' && strcmp($c['start'], $c['end']) > 0) {
                [$c['start'], $c['end']] = [$c['end'], $c['start']];
                $fixes[] = "Baris {$line}: jam mulai & selesai tertukar → dibalik.";
            }

            // 5. Jam ke (bisa rentang "1-2" → dipecah)
            $periods = self::fixPeriods($c['period']);
            $continuing = $c['day'] === $prev['day'] && $c['class'] === $prev['class'];
            if ($periods === [] && $continuing && $prev['period'] > 0) {
                $periods = [$prev['period'] + 1];
                $fixes[] = "Baris {$line}: jam ke- kosong/tidak terbaca → {$periods[0]} (urutan berikutnya).";
            } elseif ($periods === []) {
                $periods = [1];
                $fixes[] = "Baris {$line}: jam ke- kosong/tidak terbaca → 1.";
            } elseif (count($periods) > 1) {
                $fixes[] = "Baris {$line}: jam ke- \"{$c['period']}\" dipecah menjadi " . count($periods) . ' jam pelajaran.';
            } elseif ((string)$periods[0] !== $c['period']) {
                $fixes[] = "Baris {$line}: jam ke- \"{$c['period']}\" → {$periods[0]}.";
            }

            foreach ($periods as $n => $p) {
                $row = $c;
                $row['period'] = (string)$p;
                if (count($periods) > 1 && $row['start'] !== '' && self::fixTime($row['start']) !== null) {
                    $row['start'] = self::addMinutes($c['start'], $n * self::LESSON_MINUTES);
                    $row['end'] = self::addMinutes($row['start'], self::LESSON_MINUTES);
                }
                $sig = strtolower(implode('|', [$row['day'], $row['period'], $row['class'], $row['subject'], $row['teacher'], $row['room']]));
                if (isset($seen[$sig])) {
                    $fixes[] = "Baris {$line}: duplikat persis dari baris {$seen[$sig]} → dibuang.";
                    continue;
                }
                $seen[$sig] = $line;
                $out[] = ['line' => $line, 'data' => array_values($row)];
                $prev['period'] = $p;
                $prev['end'] = $row['end'];
            }
            $prev['day'] = $c['day'];
            $prev['class'] = $c['class'];
        }

        // Header standar agar Schedule::validate tetap melewati baris 1 seperti biasa
        array_unshift($out, ['line' => 1, 'data' => ['Hari_atau_Tanggal', 'Jam_ke', 'Mulai', 'Selesai', 'Kelas', 'Mapel', 'Guru', 'Ruang', 'Keterangan']]);
        foreach ($out as $i => $r) {
            if ($i > 0 && $r['line'] === 1) {
                $out[$i]['line'] = 2; // jangan sampai dianggap judul
            }
        }
        return ['rows' => $out, 'fixes' => $fixes];
    }

    /** @return array<int,int|null>|null indeks kolom per field standar, atau null bila bukan baris judul */
    private static function mapHeader(array $cells): ?array
    {
        $map = array_fill(0, 9, null);
        $hits = 0;
        foreach ($cells as $idx => $cell) {
            $k = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string)$cell)) ?? '', '_');
            if ($k === '') {
                continue;
            }
            foreach (self::COLS as $i => $name) {
                if ($map[$i] === null && in_array($k, self::HEADER[$name], true)) {
                    $map[$i] = $idx;
                    $hits++;
                    continue 2;
                }
            }
        }
        // minimal hari + kelas + mapel dikenali
        if ($hits < 3 || $map[0] === null || $map[4] === null || $map[5] === null) {
            return null;
        }
        return $map;
    }

    public static function fixDay(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        if (preg_match('/^\d{5}(\.0+)?$/', $v) && (int)$v > 30000 && (int)$v < 80000) { // serial tanggal Excel
            return gmdate('Y-m-d', ((int)$v - 25569) * 86400);
        }
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $v, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
            if (checkdate((int)$m[2], (int)$m[1], $y)) {
                return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
            }
        }
        $low = strtolower($v);
        if (preg_match('/(\d{1,2})\s+([a-z]+)\s+(\d{4})/', $low, $m) && isset(self::MONTHS[$m[2]]) && checkdate(self::MONTHS[$m[2]], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], self::MONTHS[$m[2]], (int)$m[1]);
        }
        $k = preg_replace('/[^a-z]/', '', $low) ?? '';
        if (isset(self::DAYS[$k])) {
            return self::DAYS[$k];
        }
        // salah ketik / singkatan: "snin", "jmt", "sen", "kam"
        $best = null;
        $bestD = 99;
        foreach (self::DAYS as $cand => $label) {
            $d = (strlen($k) >= 3 && str_starts_with($cand, $k)) ? 0 : levenshtein($k, $cand);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $label;
            }
        }
        return $bestD <= 2 ? $best : $v;
    }

    public static function fixTime(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (is_numeric($v) && (float)$v > 0 && (float)$v < 1) { // pecahan hari Excel
            $min = (int)round((float)$v * 1440);
            return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60);
        }
        if (preg_match('/^(\d{1,2})[:.,h](\d{2})(?::\d{2})?\s*(am|pm)?$/i', $v, $m)
            || preg_match('/^(\d{1,2})(\d{2})$/', $v, $m)) {
            $h = (int)$m[1];
            $i = (int)$m[2];
            if (isset($m[3]) && strtolower($m[3]) === 'pm' && $h < 12) {
                $h += 12;
            }
            if ($h < 24 && $i < 60) {
                return sprintf('%02d:%02d', $h, $i);
            }
        }
        if (preg_match('/^(\d{1,2})$/', $v, $m) && (int)$m[1] >= 6 && (int)$m[1] <= 18) {
            return sprintf('%02d:00', (int)$m[1]);
        }
        return null;
    }

    /** @return int[] */
    public static function fixPeriods(string $v): array
    {
        $v = strtolower(trim($v));
        if (preg_match('/(\d{1,2})\s*(?:-|–|s\.?d\.?|sampai|to)\s*(\d{1,2})/', $v, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            if ($a >= 1 && $b >= $a && $b <= 12 && $b - $a < 6) {
                return range($a, $b);
            }
        }
        if (preg_match('/(\d{1,2})/', $v, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 12) {
            return [(int)$m[1]];
        }
        return [];
    }

    private static function addMinutes(string $hhmm, int $min): string
    {
        $t = self::fixTime($hhmm) ?? '07:00';
        [$h, $i] = array_map('intval', explode(':', $t));
        $total = $h * 60 + $i + $min;
        return sprintf('%02d:%02d', intdiv($total, 60) % 24, $total % 60);
    }
}
