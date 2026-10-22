<?php
// Web Push protocol implementation with VAPID (RFC 8292).
// Stores user subscriptions and dispatches notifications.
declare(strict_types=1);

namespace VC\Security;

use VC\Database;

final class WebPush
{
    /**
     * Get the VAPID public key (base64url uncompressed P-256 EC point).
     */
    public static function getPublicKey(): ?string
    {
        return \vc_env('VAPID_PUBLIC_KEY');
    }

    /**
     * Save or update a push subscription for a user.
     */
    public static function saveSubscription(int $userId, string $endpoint, string $p256dh, string $auth): bool
    {
        $endpoint = trim($endpoint);
        $p256dh = trim($p256dh);
        $auth = trim($auth);

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return false;
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at)
            VALUES (?, ?, ?, ?, datetime("now"))
            ON CONFLICT(endpoint) DO UPDATE SET
                user_id = excluded.user_id,
                p256dh = excluded.p256dh,
                auth = excluded.auth,
                created_at = datetime("now")');
        return $stmt->execute([$userId, $endpoint, $p256dh, $auth]);
    }

    /**
     * Remove a push subscription.
     */
    public static function removeSubscription(int $userId, string $endpoint): bool
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
        return $stmt->execute([$userId, trim($endpoint)]);
    }

    /**
     * Get all active subscriptions for a user.
     */
    public static function getSubscriptions(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Helper to base64url encode.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
