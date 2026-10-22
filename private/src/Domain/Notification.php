<?php
// Notification center: categorized in-app notifications, per-category preferences, dedupe.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;

final class Notification
{
    public const CATEGORIES = ['tugas', 'jadwal', 'event', 'chat', 'sosial', 'keamanan', 'sistem'];

    /**
     * Create a notification for one user.
     * Honors per-category preferences; dedupe_key makes repeated events idempotent.
     */
    public static function push(
        int $userId,
        string $category,
        string $title,
        string $body = '',
        ?string $link = null,
        ?string $dedupeKey = null
    ): bool {
        if ($userId <= 0) {
            return false;
        }
        $category = in_array($category, self::CATEGORIES, true) ? $category : 'sistem';
        if (!self::categoryEnabled($userId, $category)) {
            return false;
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO notifications (user_id, category, title, body, link, dedupe_key)
            VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $category, mb_substr($title, 0, 160), mb_substr($body, 0, 500), $link, $dedupeKey]);
        return $stmt->rowCount() > 0;
    }

    /** Push the same notification to many users (e.g. a whole class). */
    public static function pushMany(array $userIds, string $category, string $title, string $body = '', ?string $link = null, ?string $dedupeKey = null): int
    {
        $sent = 0;
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            // Scope the dedupe key per user so the unique index stays meaningful.
            $key = $dedupeKey !== null ? $uid . ':' . $dedupeKey : null;
            if (self::push($uid, $category, $title, $body, $link, $key)) {
                $sent++;
            }
        }
        return $sent;
    }

    public static function forUser(int $userId, int $limit = 30, bool $unreadOnly = false): array
    {
        $sql = 'SELECT id, category, title, body, link, is_read, created_at FROM notifications WHERE user_id = ?';
        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(100, $limit));
        $stmt = Database::get()->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function unreadCount(int $userId): int
    {
        $stmt = Database::get()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** Mark specific notifications read (only the caller's own rows). */
    public static function markRead(int $userId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::get()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$userId], $ids));
        return $stmt->rowCount();
    }

    public static function markAllRead(int $userId): int
    {
        $stmt = Database::get()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public static function categoryEnabled(int $userId, string $category): bool
    {
        // Security alerts cannot be muted.
        if ($category === 'keamanan') {
            return true;
        }
        $stmt = Database::get()->prepare('SELECT enabled FROM notification_prefs WHERE user_id = ? AND category = ?');
        $stmt->execute([$userId, $category]);
        $v = $stmt->fetchColumn();
        return $v === false ? true : ((int)$v === 1);
    }

    public static function prefs(int $userId): array
    {
        $stmt = Database::get()->prepare('SELECT category, enabled FROM notification_prefs WHERE user_id = ?');
        $stmt->execute([$userId]);
        $out = [];
        foreach (self::CATEGORIES as $c) {
            $out[$c] = true;
        }
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['category']] = ((int)$row['enabled'] === 1);
        }
        return $out;
    }

    public static function setPref(int $userId, string $category, bool $enabled): void
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            return;
        }
        $stmt = Database::get()->prepare('INSERT INTO notification_prefs (user_id, category, enabled) VALUES (?, ?, ?)
            ON CONFLICT(user_id, category) DO UPDATE SET enabled = excluded.enabled');
        $stmt->execute([$userId, $category, $enabled ? 1 : 0]);
    }
}
