<?php
// BugReport: aduan / lapor bug dari pengguna → DM (percakapan langsung) khusus akun ROOT aktif.
// Satu percakapan per pelapor ("🐞 Laporan: Nama"), anggotanya pelapor + semua ROOT, sehingga
// ROOT bisa membalas di /obrolan dan pelapor melihat balasannya. ROOT juga mendapat notifikasi.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;
use VC\Security\RateLimiter;

final class BugReport
{
    public const KINDS = ['bug' => '🐞 Bug / Error', 'aduan' => '📣 Aduan', 'saran' => '💡 Saran Fitur'];

    /** @return array{status:string,message:string,conversation_id?:int} */
    public static function submit(array $user, array $in): array
    {
        $uid = (int)$user['id'];
        $kind = (string)($in['kind'] ?? 'bug');
        if (!isset(self::KINDS[$kind])) {
            $kind = 'bug';
        }
        $name = trim((string)($in['name'] ?? ''));
        $class = trim((string)($in['class_name'] ?? ''));
        $what = trim((string)($in['what'] ?? ''));
        $suggest = trim((string)($in['suggestion'] ?? ''));
        $page = mb_substr(trim((string)($in['page'] ?? '')), 0, 120);
        $device = mb_substr(trim((string)($in['device'] ?? '')), 0, 160);

        if ($name === '' || $class === '' || $what === '') {
            return ['status' => 'error', 'message' => 'Nama, kelas, dan apa yang terjadi wajib diisi.'];
        }
        if (mb_strlen($name) > 80 || mb_strlen($class) > 40 || mb_strlen($what) > 1500 || mb_strlen($suggest) > 1000) {
            return ['status' => 'error', 'message' => 'Isian terlalu panjang (kejadian maks. 1500, saran maks. 1000 karakter).'];
        }
        if (RateLimiter::hit('report_user', (string)$uid) > 0) {
            return ['status' => 'error', 'message' => 'Kamu sudah mengirim beberapa laporan. Tunggu sebentar ya.'];
        }

        $pdo = Database::get();
        $roots = $pdo->query('SELECT id FROM users WHERE role = "root" AND status = "active"')->fetchAll(\PDO::FETCH_COLUMN);
        if (!$roots) {
            return ['status' => 'error', 'message' => 'Admin ROOT belum tersedia. Coba lagi nanti.'];
        }

        $text = self::KINDS[$kind] . "\n"
            . 'Nama: ' . $name . "\n"
            . 'Kelas: ' . $class . "\n"
            . 'Apa yang terjadi: ' . $what . "\n"
            . 'Saran: ' . ($suggest !== '' ? $suggest : '-') . "\n"
            . ($page !== '' ? 'Halaman: ' . $page . "\n" : '')
            . ($device !== '' ? 'Perangkat: ' . $device . "\n" : '')
            . 'Akun: ' . (string)($user['username'] ?? '') . ' (' . (string)($user['role'] ?? '') . ')';

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id FROM conversations WHERE type = "direct" AND channel_tag = ? LIMIT 1');
            $tag = 'bugreport:' . $uid;
            $st->execute([$tag]);
            $convId = (int)$st->fetchColumn();
            if ($convId === 0) {
                $pdo->prepare('INSERT INTO conversations (type, name, description, channel_tag, created_by) VALUES ("direct", ?, ?, ?, ?)')
                    ->execute(['🐞 Laporan: ' . mb_substr($name, 0, 60), 'Aduan & laporan bug — hanya ROOT', $tag, $uid]);
                $convId = (int)$pdo->lastInsertId();
            }
            $mem = $pdo->prepare('INSERT OR IGNORE INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, ?)');
            $mem->execute([$convId, $uid, 'owner']);
            foreach ($roots as $rid) {
                $mem->execute([$convId, (int)$rid, 'admin']);
            }
            $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, message_text, created_at) VALUES (?, ?, ?, datetime("now"))')
                ->execute([$convId, $uid, $text]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['status' => 'error', 'message' => 'Laporan gagal disimpan. Coba lagi.'];
        }

        Notification::pushMany(array_map('intval', $roots), 'sistem', self::KINDS[$kind] . ' dari ' . $name . ' (' . $class . ')',
            mb_substr($what, 0, 200), '/obrolan?conv=' . $convId);
        AuditLog::log('bug_report', ['conversation_id' => $convId, 'kind' => $kind], $uid);
        return ['status' => 'ok', 'message' => 'Terima kasih! Laporan terkirim langsung ke admin ROOT.', 'conversation_id' => $convId];
    }
}
