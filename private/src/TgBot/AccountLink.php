<?php
// AccountLink: secure Telegram <-> app account linking via short-lived one-time codes.
// Telegram username is NEVER treated as proof of ownership: a user must be logged in
// in the web app to mint a code, then present that code from their own Telegram chat.
declare(strict_types=1);

namespace VC\TgBot;

use VC\Database;

final class AccountLink
{
    public const CODE_TTL = 600;   // 10 minutes
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous chars

    public static function ensureSchema(): void
    {
        $pdo = Database::get();
        $pdo->exec('CREATE TABLE IF NOT EXISTS telegram_link_codes (
            code_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tglc_user ON telegram_link_codes(user_id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS telegram_links (
            tg_user_id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            tg_username TEXT,
            linked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TEXT)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tgl_user ON telegram_links(user_id)');
    }

    /** Mint a one-time code for a logged-in user. Returns plaintext code (shown once). */
    public static function issueCode(int $userId): array
    {
        self::ensureSchema();
        $code = self::randomCode();
        $hash = hash('sha256', $code);
        $expires = time() + self::CODE_TTL;
        $pdo = Database::get();
        // Invalidate previous unused codes for this user.
        $pdo->prepare('UPDATE telegram_link_codes SET used_at = datetime("now") WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $pdo->prepare('INSERT INTO telegram_link_codes (code_hash, user_id, expires_at) VALUES (?, ?, ?)')
            ->execute([$hash, $userId, $expires]);
        return ['code' => $code, 'expires_at' => $expires, 'ttl' => self::CODE_TTL];
    }

    /**
     * Consume a code and link the Telegram user id to the app account.
     * Returns ['status' => 'ok'|'error', 'user' => array|null, 'message' => string].
     */
    public static function consume(string $code, int $tgUserId, ?string $tgUsername = null): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if (str_starts_with($code, 'LINK')) {
            $code = substr($code, 4);
        }
        if (str_starts_with($code, 'TELEGRAM')) {
            $code = substr($code, 8);
        }
        if (str_starts_with($code, 'TAUTKAN')) {
            $code = substr($code, 7);
        }
        if (strlen($code) < 6 || strlen($code) > 12) {
            return ['status' => 'error', 'user' => null, 'message' => 'Format kode tidak valid (harus 8 karakter).'];
        }
        $hash = hash('sha256', $code);
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM telegram_link_codes WHERE code_hash = ? AND used_at IS NULL AND expires_at > ?');
        $stmt->execute([$hash, time()]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['status' => 'error', 'user' => null, 'message' => 'Kode tidak ditemukan atau sudah kedaluwarsa.'];
        }

        $u = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND status = "active"');
        $u->execute([(int)$row['user_id']]);
        $user = $u->fetch();
        if (!$user) {
            return ['status' => 'error', 'user' => null, 'message' => 'Akun aplikasi tidak aktif. Hubungi admin.'];
        }

        $pdo->beginTransaction();
        try {
            // A user may hold only one active Telegram link: drop any previous one.
            $pdo->prepare('DELETE FROM telegram_links WHERE user_id = ?')->execute([(int)$user['id']]);
            $pdo->prepare('INSERT INTO telegram_links (tg_user_id, user_id, tg_username, linked_at, last_seen_at)
                VALUES (?, ?, ?, datetime("now"), datetime("now"))')
                ->execute([$tgUserId, (int)$user['id'], $tgUsername !== null ? mb_substr($tgUsername, 0, 64) : null]);
            $pdo->prepare('UPDATE telegram_link_codes SET used_at = datetime("now") WHERE code_hash = ?')->execute([$hash]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['status' => 'error', 'user' => null, 'message' => 'Gagal menyimpan tautan. Coba lagi.'];
        }

        \VC\Security\AuditLog::log('tg_account_linked', ['tg_user_id' => $tgUserId], (int)$user['id']);
        return ['status' => 'ok', 'user' => $user, 'message' => 'Akun berhasil ditautkan.'];
    }

    /** Resolve the active app user behind a Telegram id. Suspended/deleted users get null (access revoked). */
    public static function userForTg(int $tgUserId): ?array
    {
        self::ensureSchema();
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT u.* FROM telegram_links l JOIN users u ON u.id = l.user_id
            WHERE l.tg_user_id = ? AND u.deleted_at IS NULL AND u.status = "active"');
        $stmt->execute([$tgUserId]);
        $user = $stmt->fetch();
        if ($user) {
            $pdo->prepare('UPDATE telegram_links SET last_seen_at = datetime("now") WHERE tg_user_id = ?')->execute([$tgUserId]);
            return $user;
        }
        return null;
    }

    public static function isLinked(int $tgUserId): bool
    {
        return self::userForTg($tgUserId) !== null;
    }

    public static function linkForUser(int $userId): ?array
    {
        self::ensureSchema();
        $s = Database::get()->prepare('SELECT * FROM telegram_links WHERE user_id = ?');
        $s->execute([$userId]);
        $r = $s->fetch();
        return $r ?: null;
    }

    /** Unlink by Telegram id (user sends /unlink). */
    public static function unlink(int $tgUserId): bool
    {
        self::ensureSchema();
        $stmt = Database::get()->prepare('DELETE FROM telegram_links WHERE tg_user_id = ?');
        $stmt->execute([$tgUserId]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            \VC\Security\AuditLog::log('tg_account_unlinked', ['tg_user_id' => $tgUserId]);
        }
        return $ok;
    }

    /** Unlink by app user id (from the web UI). */
    public static function unlinkByUser(int $userId): bool
    {
        self::ensureSchema();
        $stmt = Database::get()->prepare('DELETE FROM telegram_links WHERE user_id = ?');
        $stmt->execute([$userId]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            \VC\Security\AuditLog::log('tg_account_unlinked', ['user_id' => $userId]);
        }
        return $ok;
    }

    private static function randomCode(): string
    {
        $len = strlen(self::ALPHABET);
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= self::ALPHABET[random_int(0, $len - 1)];
        }
        return $out;
    }
}
