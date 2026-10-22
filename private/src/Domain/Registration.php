<?php
// Student registration flow: roster match → pending/approved, anti-abuse, idempotent.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\Auth;

final class Registration
{
    public const ERR_USERNAME_TAKEN = 'Username sudah dipakai. Coba yang lain.';
    public const ERR_WEAK_PASSWORD = 'Password terlalu lemah.';
    public const ERR_NIS_TAKEN = 'NIS/NISN ini sudah terdaftar. Hubungi wali kelas jika itu Anda.';

    /**
     * @return array{status: 'pending'|'approved'|'rejected', message: string}
     */
    public static function create(string $fullName, string $nis, int $classId, string $username, string $password, string $ip): array
    {
        $fullName = trim($fullName);
        $username = strtolower(trim($username));
        $nis = trim($nis);

        // Server-side validation (never trust client).
        if (strlen($fullName) < 3 || strlen($fullName) > 100) {
            return ['status' => 'error', 'message' => 'Nama lengkap harus 3–100 karakter.'];
        }
        if ($nis === '' || !ctype_digit($nis)) {
            return ['status' => 'error', 'message' => 'NIS/NISN harus berupa angka.'];
        }
        if (!preg_match('/^[a-z0-9._-]{4,32}$/', $username)) {
            return ['status' => 'error', 'message' => 'Username 4–32 karakter, hanya huruf kecil/angka/._-'];
        }
        // Reserved words blocklist.
        $reserved = ['admin', 'root', 'guru', 'coffelatte314', 'sugamilas', 'osmk', 'mod', 'owner'];
        foreach ($reserved as $r) {
            if (str_starts_with($username, $r)) {
                return ['status' => 'error', 'message' => 'Username tidak diperbolehkan. Pilih yang lain.'];
            }
        }
        $pwErrors = Auth::passwordErrors($password, $username, 'murid');
        if ($pwErrors !== []) {
            return ['status' => 'error', 'message' => $pwErrors[0]];
        }

        $pdo = Database::get();

        // Duplicate username / NIS anywhere (users + pending registrations).
        $check = $pdo->prepare('SELECT 1 FROM users WHERE username = ? UNION SELECT 1 FROM registrations WHERE username = ?');
        $check->execute([$username, $username]);
        if ($check->fetchColumn()) {
            return ['status' => 'error', 'message' => self::ERR_USERNAME_TAKEN];
        }
        $check = $pdo->prepare('SELECT 1 FROM registrations WHERE nis = ? AND status = "pending"');
        $check->execute([$nis]);
        if ($check->fetchColumn()) {
            return ['status' => 'error', 'message' => self::ERR_NIS_TAKEN];
        }
        $checkUsersNis = $pdo->prepare('SELECT 1 FROM users WHERE nis = ? AND deleted_at IS NULL');
        $checkUsersNis->execute([$nis]);
        if ($checkUsersNis->fetchColumn()) {
            return ['status' => 'error', 'message' => self::ERR_NIS_TAKEN];
        }

        // Roster matching decides auto-approve vs pending.
        $matchMode = \vc_env('REGISTRATION_MODE', 'auto_approve');
        $roster = $pdo->prepare('SELECT r.*, c.id AS class_exists FROM student_roster r LEFT JOIN classes c ON c.id = r.class_id WHERE r.nis = ?');
        $roster->execute([$nis]);
        $ro = $roster->fetch();

        if ($ro !== false && $ro['claimed_by_user_id'] !== null) {
            return ['status' => 'error', 'message' => self::ERR_NIS_TAKEN];
        }

        $autoApprove = ($matchMode === 'auto_approve')
            || ($matchMode === 'roster_match_auto_approve' && $ro !== false);
        $regUid = bin2hex(random_bytes(16));
        $finalClassId = ($ro !== false && !empty($ro['class_id'])) ? (int)$ro['class_id'] : ($classId > 0 ? $classId : null);

        // Always store initial record in registrations table with status "pending" so activateFromRegistration can claim it cleanly.
        $ins = $pdo->prepare('INSERT INTO registrations
            (reg_uid, full_name, nis, class_id, username, password_hash, status, created_at, ip)
            VALUES (?, ?, ?, ?, ?, ?, "pending", datetime("now"), ?)');
        $ins->execute([
            $regUid,
            $fullName,
            $nis,
            $finalClassId,
            $username,
            Auth::hash($password),
            $ip,
        ]);
        $regId = (int)$pdo->lastInsertId();

        self::activateFromRegistration($regId, null, 'Pendaftaran Mandiri Web (Langsung Aktif)');
        \VC\Security\AuditLog::log('registration_approved', ['username' => $username, 'nis' => substr($nis, 0, 4) . '***']);
        self::notifyTelegram($regId, $fullName, $nis, $username);
        return [
            'status' => 'approved',
            'message' => 'Registrasi berhasil! Akun Anda aktif dan langsung masuk ke sistem.',
            'username' => $username
        ];
    }

    /** Move an approved registration into the users table (idempotent). */
    public static function activateFromRegistration(int $regId, ?int $deciderId = null, string $reason = 'Disetujui'): bool
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        $g = $pdo->prepare('SELECT * FROM registrations WHERE id = ? AND status = "pending"');
        $g->execute([$regId]);
        $reg = $g->fetch();
        if (!$reg) {
            $pdo->rollBack();
            return false;
        }
        $uid = bin2hex(random_bytes(16));
        $i = $pdo->prepare('INSERT INTO users (uid, username, password_hash, role, full_name, nis, class_id, status, must_change_password, created_at)
            VALUES (?, ?, ?, "murid", ?, ?, ?, "active", 0, datetime("now"))');
        $i->execute([
            $uid,
            $reg['username'],
            $reg['password_hash'],
            $reg['full_name'],
            $reg['nis'],
            !empty($reg['class_id']) ? (int)$reg['class_id'] : null,
        ]);
        $newId = (int)$pdo->lastInsertId();

        if (!empty($reg['class_id'])) {
            $pdo->prepare('INSERT OR IGNORE INTO class_members (user_id, class_id) VALUES (?, ?)')
                ->execute([$newId, (int)$reg['class_id']]);
        }
        $pdo->prepare('UPDATE student_roster SET claimed_by_user_id = ? WHERE nis = ? AND claimed_by_user_id IS NULL')
            ->execute([$newId, $reg['nis']]);
        $u = $pdo->prepare('UPDATE registrations SET status = "approved", decided_by = ?, decided_at = datetime("now"), reason = ? WHERE id = ?');
        $u->execute([$deciderId, $reason, $regId]);

        // Berikan progres awal murid baru: level 1, saldo 100 koin, streak 1
        $pdo->prepare('INSERT OR IGNORE INTO user_progress (user_id, level, total_xp, coins, login_streak, last_login_date, titles)
            VALUES (?, 1, 0, 100, 1, date("now"), "Siswa Baru")')->execute([$newId]);

        $pdo->commit();

        // Mark that user must accept terms at first login if needed.
        $pdo->prepare('INSERT INTO terms_acceptances (user_id, terms_version) SELECT ?, "" WHERE 0')->execute([]);
        \VC\Security\AuditLog::log('registration_activated', [
            'username' => $reg['username'], 'approved_by' => $deciderId, 'reason' => $reason,
        ], $deciderId);
        return true;
    }

    /** Best-effort Telegram ping to ROOT informing about new student. */
    private static function notifyTelegram(int $regId, string $name, string $nis, string $username): void
    {
        $rootId = (int)\vc_env('TELEGRAM_ROOT_ID', '0');
        if ($rootId <= 0) {
            return;
        }
        try {
            $tg = new \VC\TgBot\Telegram();
            $n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            // Timeout pendek (2s) agar pendaftaran massal tidak tertahan antrean jaringan
            $tg->call('sendMessage', [
                'chat_id' => $rootId,
                'text' => "🎓 <b>Siswa Baru Terdaftar (Langsung Aktif)</b>\n\nNama: {$n}\nNIS: <code>" . htmlspecialchars($nis, ENT_QUOTES, 'UTF-8') .
                "</code>\nUsername: <code>" . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . "</code>\nStatus: <b>Aktif & Dapat Bermain</b>",
                'parse_mode' => 'HTML',
                'disable_notification' => true,
            ], 2);
        } catch (\Throwable $e) {
            \VC\Security\SecurityLog::write('tg_notify_fail', ['err' => substr($e->getMessage(), 0, 150)]);
        }
    }

    public static function reject(int $regId, int $deciderId, string $reason): bool
    {
        $pdo = Database::get();
        $u = $pdo->prepare('UPDATE registrations SET status = "rejected", decided_by = ?, decided_at = datetime("now"), reason = ? WHERE id = ? AND status = "pending"');
        $u->execute([$deciderId, $reason, $regId]);
        return $u->rowCount() > 0;
    }
}
