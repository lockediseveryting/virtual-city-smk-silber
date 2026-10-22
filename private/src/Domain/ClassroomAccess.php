<?php
// ClassroomAccess: authorization helpers that keep every task/grade action scoped.
// Teachers may only act on their own classes; staff with user.view may act school-wide.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;

final class ClassroomAccess
{
    /** True when $userId may manage the given assignment (owner teacher or privileged staff). */
    public static function teacherOwnsAssignment(int $assignmentId, int $userId, bool $isStaffAll = false): bool
    {
        if ($isStaffAll) {
            return true;
        }
        $stmt = Database::get()->prepare('SELECT 1 FROM assignments WHERE id = ? AND teacher_id = ? AND deleted_at IS NULL');
        $stmt->execute([$assignmentId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /** True when $userId may manage the given learning material (owner teacher or privileged staff). */
    public static function teacherOwnsMaterial(int $materialId, int $userId, bool $isStaffAll = false): bool
    {
        if ($isStaffAll) {
            return true;
        }
        $stmt = Database::get()->prepare('SELECT 1 FROM materials WHERE id = ? AND teacher_id = ? AND deleted_at IS NULL');
        $stmt->execute([$materialId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /** True when the teacher is actually scheduled to teach the class (or is privileged staff). */
    public static function teacherTeachesClass(int $userId, int $classId, bool $isStaffAll = false): bool
    {
        if ($isStaffAll) {
            return true;
        }
        $stmt = Database::get()->prepare('SELECT 1 FROM schedule_entries WHERE teacher_user_id = ? AND class_id = ? LIMIT 1');
        $stmt->execute([$userId, $classId]);
        return (bool)$stmt->fetchColumn();
    }

    /** True when a student belongs to the class (class_members or users.class_id). */
    public static function studentInClass(int $studentId, int $classId): bool
    {
        $stmt = Database::get()->prepare('SELECT 1 FROM users WHERE id = ? AND class_id = ? AND deleted_at IS NULL
            UNION SELECT 1 FROM class_members WHERE user_id = ? AND class_id = ? LIMIT 1');
        $stmt->execute([$studentId, $classId, $studentId, $classId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Students of a class, for class-wide notifications. */
    public static function studentsOfClass(int $classId): array
    {
        $stmt = Database::get()->prepare('SELECT user_id FROM class_members WHERE class_id = ?
            UNION
            SELECT id FROM users WHERE class_id = ? AND role = "murid" AND status = "active" AND deleted_at IS NULL');
        $stmt->execute([$classId, $classId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** True when the caller may read the gradebook of $classId (own class, or staff). */
    public static function canViewGradebook(int $classId, int $userId, int $userClassId, bool $isStaff): bool
    {
        return $isStaff || ($classId > 0 && $classId === $userClassId);
    }
}
