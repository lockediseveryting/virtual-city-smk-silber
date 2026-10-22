<?php
// Events & Organizations Domain Service: Extracurriculars, Competitions, Attendance & Digital Certificates.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;
use PDO;

final class Events
{
    /* =========================================================================
     * 1. ORGANIZATIONS & EXTRACURRICULARS (Ekskul, Organisasi, Komunitas)
     * ========================================================================= */

    public static function getOrganizations(): array
    {
        $pdo = Database::get();
        $stmt = $pdo->query('SELECT o.*, 
            (SELECT COUNT(*) FROM org_members WHERE org_id = o.id AND status = "approved") AS member_count
            FROM organizations o
            ORDER BY o.category, o.name ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getOrganization(string $slug): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT o.*, 
            (SELECT COUNT(*) FROM org_members WHERE org_id = o.id AND status = "approved") AS member_count
            FROM organizations o
            WHERE o.slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function joinOrganization(int $orgId, int $userId): array
    {
        $pdo = Database::get();
        $org = $pdo->prepare('SELECT id, name, quota, (SELECT COUNT(*) FROM org_members WHERE org_id = ? AND status = "approved") AS current_count FROM organizations WHERE id = ?');
        $org->execute([$orgId, $orgId]);
        $data = $org->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            return ['status' => 'error', 'message' => 'Organisasi / ekskul tidak ditemukan.'];
        }

        if ((int)$data['current_count'] >= (int)$data['quota']) {
            return ['status' => 'error', 'message' => 'Kuota pendaftaran organisasi telah penuh.'];
        }

        $stmt = $pdo->prepare('INSERT INTO org_members (org_id, user_id, role, status, joined_at)
            VALUES (?, ?, "anggota", "pending", datetime("now"))
            ON CONFLICT(org_id, user_id) DO NOTHING');
        $stmt->execute([$orgId, $userId]);

        AuditLog::log('org_join_request', ['org_id' => $orgId, 'org_name' => $data['name']], $userId);

        return [
            'status' => 'success',
            'message' => 'Permintaan bergabung berhasil dikirim. Menunggu persetujuan pembina.'
        ];
    }

    public static function approveMember(int $orgId, int $userId, int $approverId): bool
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE org_members SET status = "approved" WHERE org_id = ? AND user_id = ?');
        $ok = $stmt->execute([$orgId, $userId]);
        if ($ok) {
            AuditLog::log('org_approve_member', ['org_id' => $orgId, 'user_id' => $userId], $approverId);
        }
        return $ok;
    }

    public static function getOrgMembers(int $orgId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT m.*, u.username, u.full_name, u.role AS user_role, u.nis
            FROM org_members m
            JOIN users u ON m.user_id = u.id
            WHERE m.org_id = ?
            ORDER BY CASE m.role 
                WHEN "pembina" THEN 1 
                WHEN "ketua" THEN 2 
                WHEN "pengurus" THEN 3 
                ELSE 4 END, u.full_name ASC');
        $stmt->execute([$orgId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================================================================
     * 2. EVENTS, ATTENDANCE & DIGITAL CERTIFICATES (Event & Kehadiran)
     * ========================================================================= */

    public static function getUpcomingEvents(int $limit = 20): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT e.*, 
            (SELECT COUNT(*) FROM event_attendance WHERE event_id = e.id) AS attendee_count
            FROM events e
            WHERE e.status IN ("published", "running")
            ORDER BY e.start_time ASC
            LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAllEvents(int $limit = 50): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT e.*, 
            (SELECT COUNT(*) FROM event_attendance WHERE event_id = e.id) AS attendee_count,
            u.full_name AS creator_name
            FROM events e
            LEFT JOIN users u ON e.created_by = u.id
            ORDER BY e.start_time DESC
            LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function deleteEvent(int $eventId, int $userId): bool
    {
        $pdo = Database::get();
        $pdo->prepare('UPDATE certificates SET event_id = NULL WHERE event_id = ?')->execute([$eventId]);
        $pdo->prepare('DELETE FROM event_attendance WHERE event_id = ?')->execute([$eventId]);
        $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            AuditLog::log('event_delete', ['event_id' => $eventId], $userId);
        }
        return $ok;
    }

    public static function getById(int $eventId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT e.*, 
            (SELECT COUNT(*) FROM event_attendance WHERE event_id = e.id) AS attendee_count
            FROM events e
            WHERE e.id = ?');
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function createEvent(
        string $title,
        string $description,
        string $eventType,
        ?string $mapLocation,
        string $startTime,
        string $endTime,
        int $quota,
        int $xp,
        int $coins,
        int $userId
    ): int {
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO events 
            (title, description, event_type, map_location, start_time, end_time, quota, rewards_xp, rewards_coins, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "published", ?, datetime("now"))');
        $stmt->execute([
            trim($title),
            trim($description),
            $eventType,
            $mapLocation,
            $startTime,
            $endTime,
            $quota,
            $xp,
            $coins,
            $userId
        ]);
        $id = (int)$pdo->lastInsertId();
        AuditLog::log('event_create', ['event_id' => $id, 'title' => $title], $userId);
        return $id;
    }

    public static function recordAttendance(int $eventId, int $userId, string $verificationCode): array
    {
        $pdo = Database::get();
        $event = self::getById($eventId);
        if (!$event) {
            return ['status' => 'error', 'message' => 'Event tidak ditemukan.'];
        }

        // Attendance window check
        $now = date('Y-m-d H:i:s');
        if ($now > $event['end_time']) {
            return ['status' => 'error', 'message' => 'Event telah selesai. Presensi ditutup.'];
        }

        // Check if already attended
        $check = $pdo->prepare('SELECT 1 FROM event_attendance WHERE event_id = ? AND user_id = ?');
        $check->execute([$eventId, $userId]);
        if ($check->fetchColumn()) {
            return ['status' => 'error', 'message' => 'Anda sudah melakukan presensi pada event ini.'];
        }

        $stmt = $pdo->prepare('INSERT INTO event_attendance (event_id, user_id, attended_at, verification_code)
            VALUES (?, ?, datetime("now"), ?)');
        $stmt->execute([$eventId, $userId, trim($verificationCode)]);

        AuditLog::log('event_attendance', [
            'event_id' => $eventId,
            'xp_reward' => $event['rewards_xp'],
            'coin_reward' => $event['rewards_coins']
        ], $userId);

        return [
            'status' => 'success',
            'message' => 'Presensi berhasil dicatat!',
            'rewards' => [
                'xp' => (int)$event['rewards_xp'],
                'coins' => (int)$event['rewards_coins']
            ]
        ];
    }

    public static function issueCertificate(int $userId, int $eventId, string $title): array
    {
        $pdo = Database::get();
        $certNumber = 'CERT-SILBER-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('Y');
        $verifyHash = hash('sha256', $certNumber . '|' . $userId . '|' . $eventId);

        $stmt = $pdo->prepare('INSERT INTO certificates 
            (user_id, event_id, title, cert_number, verify_hash, issued_at)
            VALUES (?, ?, ?, ?, ?, datetime("now"))');
        $stmt->execute([$userId, $eventId, trim($title), $certNumber, $verifyHash]);

        AuditLog::log('certificate_issue', ['user_id' => $userId, 'cert_number' => $certNumber], $userId);

        return [
            'cert_number' => $certNumber,
            'verify_hash' => $verifyHash,
            'title' => $title
        ];
    }

    public static function verifyCertificate(string $certNumber): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT c.*, u.full_name AS recipient_name, u.nis, e.title AS event_title
            FROM certificates c
            JOIN users u ON c.user_id = u.id
            LEFT JOIN events e ON c.event_id = e.id
            WHERE c.cert_number = ?');
        $stmt->execute([trim($certNumber)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
