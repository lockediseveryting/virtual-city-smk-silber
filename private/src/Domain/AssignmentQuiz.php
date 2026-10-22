<?php
// AssignmentQuiz: soal di dalam tugas (pilihan ganda + essay), disimpan JSON di assignments.questions.
// Format satu soal:
//   {"t":"pg","q":"Teks soal","opts":["A","B","C","D"],"ans":2,"pts":10}
//   {"t":"essay","q":"Teks soal","key":"kunci/rubrik (hanya guru)","pts":20}
// Pilihan ganda dinilai otomatis. Bila semua soal pilihan ganda → nilai langsung keluar saat murid
// mengumpulkan. Bila ada essay → guru memberi poin essay; nilai akhir = (poin PG + poin essay) /
// total poin × nilai maksimal tugas.
declare(strict_types=1);

namespace VC\Domain;

final class AssignmentQuiz
{
    public const MAX_QUESTIONS = 60;

    /** Validasi & rapikan daftar soal dari guru. @return array{0: array, 1: ?string} [soal, pesan error] */
    public static function sanitize($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || $raw === []) {
            return [[], null];
        }
        if (count($raw) > self::MAX_QUESTIONS) {
            return [[], 'Maksimal ' . self::MAX_QUESTIONS . ' soal per tugas.'];
        }
        $out = [];
        foreach (array_values($raw) as $i => $q) {
            $n = $i + 1;
            if (!is_array($q)) {
                return [[], 'Soal no. ' . $n . ' tidak valid.'];
            }
            $text = mb_substr(trim((string)($q['q'] ?? '')), 0, 2000);
            if ($text === '') {
                return [[], 'Soal no. ' . $n . ' masih kosong.'];
            }
            $pts = max(1, min(100, (int)($q['pts'] ?? 10)));
            if (($q['t'] ?? '') === 'essay') {
                $out[] = ['t' => 'essay', 'q' => $text, 'key' => mb_substr(trim((string)($q['key'] ?? '')), 0, 2000), 'pts' => $pts];
                continue;
            }
            $opts = [];
            foreach ((array)($q['opts'] ?? []) as $o) {
                $o = mb_substr(trim((string)$o), 0, 500);
                if ($o !== '') {
                    $opts[] = $o;
                }
            }
            if (count($opts) < 2 || count($opts) > 6) {
                return [[], 'Soal no. ' . $n . ': pilihan ganda butuh 2–6 pilihan jawaban.'];
            }
            $ans = (int)($q['ans'] ?? -1);
            if ($ans < 0 || $ans >= count($opts)) {
                return [[], 'Soal no. ' . $n . ': tandai jawaban yang benar.'];
            }
            $out[] = ['t' => 'pg', 'q' => $text, 'opts' => $opts, 'ans' => $ans, 'pts' => $pts];
        }
        return [$out, null];
    }

    public static function decode(?string $json): array
    {
        $q = $json ? json_decode($json, true) : null;
        return is_array($q) ? $q : [];
    }

    /** Untuk murid: tanpa kunci jawaban. */
    public static function publicView(array $questions): array
    {
        return array_map(static function (array $q): array {
            unset($q['ans'], $q['key']);
            return $q;
        }, $questions);
    }

    public static function totalPoints(array $questions): int
    {
        return (int)array_sum(array_map(static fn($q) => (int)$q['pts'], $questions));
    }

    public static function allMultipleChoice(array $questions): bool
    {
        foreach ($questions as $q) {
            if ($q['t'] !== 'pg') {
                return false;
            }
        }
        return $questions !== [];
    }

    /** Rapikan jawaban murid: index → int (PG) | teks (essay). */
    public static function cleanAnswers(array $questions, $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($questions as $i => $q) {
            $v = $raw[$i] ?? ($raw[(string)$i] ?? null);
            if ($q['t'] === 'pg') {
                $out[$i] = ($v === null || $v === '' || !is_numeric($v) || (int)$v < 0 || (int)$v >= count($q['opts'])) ? null : (int)$v;
            } else {
                $out[$i] = mb_substr(trim((string)($v ?? '')), 0, 5000);
            }
        }
        return $out;
    }

    /** Poin pilihan ganda yang benar. */
    public static function pgPoints(array $questions, array $answers): int
    {
        $sum = 0;
        foreach ($questions as $i => $q) {
            if ($q['t'] === 'pg' && ($answers[$i] ?? null) === (int)$q['ans']) {
                $sum += (int)$q['pts'];
            }
        }
        return $sum;
    }

    /** Skala poin → nilai tugas (0..max), dikurangi penalti terlambat bila perlu. */
    public static function scale(int $points, int $totalPoints, int $maxScore, bool $late = false, int $penalty = 0): int
    {
        if ($totalPoints <= 0) {
            return 0;
        }
        $score = $points / $totalPoints * $maxScore;
        if ($late && $penalty > 0) {
            $score *= (100 - min(100, $penalty)) / 100;
        }
        return (int)round(max(0, min($maxScore, $score)));
    }
}
