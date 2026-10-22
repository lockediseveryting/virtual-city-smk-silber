<?php
// Learning Domain Service: Materials, Assignments, Submissions, Grading, Gradebook & Interactive In-Browser Quizzes.
declare(strict_types=1);

namespace VC\Domain;

use VC\Database;
use VC\Security\AuditLog;
use PDO;

final class Learning
{
    /* =========================================================================
     * 1. MATERIALS (Materi Pembelajaran)
     * ========================================================================= */

    public static function materialsForClass(int $classId, int $limit = 50): array
    {
        return self::getMaterialsByClass($classId);
    }

    public static function getMaterialsByClass(int $classId, ?int $subjectId = null): array
    {
        $pdo = Database::get();
        $sql = 'SELECT m.*, s.name AS subject_name, s.code AS subject_code, u.full_name AS teacher_name
                FROM materials m
                JOIN subjects s ON m.subject_id = s.id
                JOIN users u ON u.id = m.teacher_id
                WHERE m.class_id = ? AND m.deleted_at IS NULL';
        $params = [$classId];

        if ($subjectId !== null && $subjectId > 0) {
            $sql .= ' AND m.subject_id = ?';
            $params[] = $subjectId;
        }

        $sql .= ' ORDER BY m.id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createMaterial(
        $arg1,
        $arg2,
        int $classId,
        string $title,
        string $description,
        string $type = 'text',
        ?string $url = null,
        ?string $filePath = null
    ): int {
        $subjectId = (int)$arg1;
        $teacherId = (int)$arg2;
        // Handle argument swap if teacherId came first
        if ($subjectId > 100 && $teacherId < 50) {
            $tmp = $subjectId;
            $subjectId = $teacherId;
            $teacherId = $tmp;
        }

        $title = trim($title);
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO materials 
            (subject_id, teacher_id, class_id, title, description, content_type, content_url, file_path, published_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime("now"))');
        $stmt->execute([
            $subjectId,
            $teacherId,
            $classId,
            $title,
            trim($description),
            $type,
            $url,
            $filePath
        ]);
        $id = (int)$pdo->lastInsertId();
        AuditLog::log('material_create', ['material_id' => $id, 'title' => $title], $teacherId);
        return $id;
    }

    public static function deleteMaterial(int $materialId, int $userId, bool $isStaffAll = false): bool
    {
        if (!ClassroomAccess::teacherOwnsMaterial($materialId, $userId, $isStaffAll)) {
            return false;
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE materials SET deleted_at = datetime("now") WHERE id = ? AND deleted_at IS NULL');
        $ok = $stmt->execute([$materialId]);
        if ($ok && $stmt->rowCount() > 0) {
            AuditLog::log('material_delete', ['material_id' => $materialId], $userId);
            return true;
        }
        return false;
    }

    /* =========================================================================
     * 2. ASSIGNMENTS & SUBMISSIONS (Tugas & Pengumpulan)
     * ========================================================================= */

    public static function assignmentsForClass(int $classId, int $studentId = 0): array
    {
        return $studentId > 0 ? self::getAssignmentsForStudent($studentId, $classId) : self::getAssignmentsByClass($classId);
    }

    /**
     * Tugas yang diterima murid: tugas kelasnya + tugas yang dikirim guru ke server/room
     * game tempat murid menjadi anggota (gabung via kode).
     */
    public static function getAssignmentsForStudent(int $studentId, int $classId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT a.*, s.name AS subject_name, s.code AS subject_code, u.full_name AS teacher_name,
                    g.name AS server_name, g.invite_code AS server_code, g.world AS server_world
                FROM assignments a
                JOIN subjects s ON a.subject_id = s.id
                JOIN users u ON a.teacher_id = u.id
                LEFT JOIN game_servers g ON g.id = a.server_id
                WHERE a.deleted_at IS NULL
                  AND (a.class_id = ? OR a.server_id IN (SELECT server_id FROM game_server_members WHERE user_id = ?))
                ORDER BY a.deadline ASC');
        $stmt->execute([$classId, $studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Murid boleh membuka/mengumpulkan tugas: anggota kelas sasaran ATAU anggota server tujuan. */
    public static function studentCanAccess(int $studentId, array $assignment): bool
    {
        if (ClassroomAccess::studentInClass($studentId, (int)$assignment['class_id'])) {
            return true;
        }
        $sid = (int)($assignment['server_id'] ?? 0);
        if ($sid <= 0) {
            return false;
        }
        $st = Database::get()->prepare('SELECT 1 FROM game_server_members WHERE server_id = ? AND user_id = ?');
        $st->execute([$sid, $studentId]);
        return (bool)$st->fetchColumn();
    }

    public static function getAssignmentsByClass(int $classId, ?int $subjectId = null): array
    {
        $pdo = Database::get();
        $sql = 'SELECT a.*, s.name AS subject_name, s.code AS subject_code, u.full_name AS teacher_name,
                       g.name AS server_name, g.invite_code AS server_code, g.world AS server_world
                FROM assignments a
                JOIN subjects s ON a.subject_id = s.id
                JOIN users u ON a.teacher_id = u.id
                LEFT JOIN game_servers g ON g.id = a.server_id
                WHERE a.class_id = ? AND a.deleted_at IS NULL';
        $params = [$classId];

        if ($subjectId !== null && $subjectId > 0) {
            $sql .= ' AND a.subject_id = ?';
            $params[] = $subjectId;
        }

        $sql .= ' ORDER BY a.deadline ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAssignment(int $assignmentId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT a.*, s.name AS subject_name, s.code AS subject_code, u.full_name AS teacher_name, c.name AS class_name
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            JOIN users u ON a.teacher_id = u.id
            JOIN classes c ON a.class_id = c.id
            WHERE a.id = ? AND a.deleted_at IS NULL');
        $stmt->execute([$assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getStudentSubmission(int $assignmentId, int $studentId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT sub.*, u.username, u.full_name, u.nis, g.full_name AS grader_name
            FROM submissions sub
            JOIN users u ON sub.student_id = u.id
            LEFT JOIN users g ON sub.graded_by = g.id
            WHERE sub.assignment_id = ? AND sub.student_id = ?');
        $stmt->execute([$assignmentId, $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getTeacherAssignments(int $teacherId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT a.*, s.name AS subject_name, c.name AS class_name,
            g.name AS server_name, g.invite_code AS server_code, g.world AS server_world,
            (SELECT COUNT(*) FROM submissions sub WHERE sub.assignment_id = a.id) AS submission_count,
            (SELECT COUNT(*) FROM submissions sub WHERE sub.assignment_id = a.id AND sub.score IS NOT NULL) AS graded_count
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            JOIN classes c ON a.class_id = c.id
            LEFT JOIN game_servers g ON g.id = a.server_id
            WHERE a.teacher_id = ? AND a.deleted_at IS NULL
            ORDER BY a.deadline DESC');
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createAssignment(
        $arg1,
        $arg2,
        int $classId,
        string $title,
        string $instructions,
        string $deadline,
        int $maxScore = 100,
        bool $allowLate = true,
        int $latePenalty = 10,
        ?int $serverId = null,
        array $questions = []
    ): int {
        $subjectId = (int)$arg1;
        $teacherId = (int)$arg2;
        if ($subjectId > 100 && $teacherId < 50) {
            $tmp = $subjectId;
            $subjectId = $teacherId;
            $teacherId = $tmp;
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO assignments 
            (subject_id, teacher_id, class_id, title, instructions, deadline, max_score, allow_late, late_penalty, server_id, questions) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $subjectId,
            $teacherId,
            $classId,
            trim($title),
            trim($instructions),
            $deadline,
            $maxScore,
            $allowLate ? 1 : 0,
            $latePenalty,
            ($serverId !== null && $serverId > 0) ? $serverId : null,
            $questions !== [] ? json_encode($questions, JSON_UNESCAPED_UNICODE) : null
        ]);
        $id = (int)$pdo->lastInsertId();
        AuditLog::log('assignment_create', ['assignment_id' => $id, 'title' => $title], $teacherId);
        return $id;
    }

    public static function deleteAssignment(int $assignmentId, int $userId, bool $isStaffAll = false): bool
    {
        if (!ClassroomAccess::teacherOwnsAssignment($assignmentId, $userId, $isStaffAll)) {
            return false;
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE assignments SET deleted_at = datetime("now") WHERE id = ? AND deleted_at IS NULL');
        $ok = $stmt->execute([$assignmentId]);
        if ($ok && $stmt->rowCount() > 0) {
            AuditLog::log('assignment_delete', ['assignment_id' => $assignmentId], $userId);
            return true;
        }
        return false;
    }

    public static function submitAssignment(
        int $assignmentId,
        int $studentId,
        ?string $contentText = null,
        ?string $fileName = null,
        ?string $filePath = null
    ): array {
        $pdo = Database::get();
        $asgStmt = $pdo->prepare('SELECT deadline, allow_late FROM assignments WHERE id = ? AND deleted_at IS NULL');
        $asgStmt->execute([$assignmentId]);
        $asg = $asgStmt->fetch(PDO::FETCH_ASSOC);

        if (!$asg) {
            return ['status' => 'error', 'message' => 'Tugas tidak ditemukan.'];
        }

        // Tenggat diisi guru dalam waktu sekolah (WIB), server berjalan UTC → bandingkan waktu lokal.
        $now = Schedule::nowLocal('Y-m-d H:i:s');
        $isLate = ($now > $asg['deadline']) ? 1 : 0;

        if ($isLate && !(bool)$asg['allow_late']) {
            return ['status' => 'error', 'message' => 'Batas waktu tugas telah berakhir dan pengumpulan terlambat tidak diizinkan.'];
        }

        $stmt = $pdo->prepare('INSERT INTO submissions 
            (assignment_id, student_id, content_text, file_name, file_path, submitted_at, is_late)
            VALUES (?, ?, ?, ?, ?, datetime("now"), ?)
            ON CONFLICT(assignment_id, student_id) DO UPDATE SET
            content_text = excluded.content_text,
            file_name = excluded.file_name,
            file_path = excluded.file_path,
            submitted_at = datetime("now"),
            is_late = excluded.is_late');

        $stmt->execute([
            $assignmentId,
            $studentId,
            trim((string)$contentText),
            $fileName,
            $filePath,
            $isLate
        ]);

        AuditLog::log('assignment_submit', ['assignment_id' => $assignmentId, 'is_late' => (bool)$isLate], $studentId);

        return [
            'status' => 'success',
            'message' => $isLate ? 'Tugas berhasil dikirim (terlambat).' : 'Tugas berhasil dikirim tepat waktu.',
            'is_late' => (bool)$isLate
        ];
    }

    public static function deleteSubmission(int $assignmentId, int $studentId): bool
    {
        $pdo = Database::get();
        // Allow deleting/retracting submission only if not graded yet
        $stmt = $pdo->prepare('DELETE FROM submissions WHERE assignment_id = ? AND student_id = ? AND score IS NULL');
        $ok = $stmt->execute([$assignmentId, $studentId]);
        if ($ok && $stmt->rowCount() > 0) {
            AuditLog::log('submission_delete', ['assignment_id' => $assignmentId], $studentId);
            return true;
        }
        return false;
    }

    public static function gradeSubmission(
        int $submissionId,
        int $teacherId,
        int $score,
        string $feedback
    ): bool {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE submissions 
            SET score = ?, feedback = ?, graded_at = datetime("now"), graded_by = ? 
            WHERE id = ?');
        $ok = $stmt->execute([$score, trim($feedback), $teacherId, $submissionId]);
        if ($ok) {
            AuditLog::log('submission_grade', ['submission_id' => $submissionId, 'score' => $score], $teacherId);
        }
        return $ok;
    }

    public static function grade(int $submissionId, int $score, string $feedback, int $graderId): array
    {
        $ok = self::gradeSubmission($submissionId, $graderId, $score, $feedback);
        return $ok ? ['status' => 'success', 'message' => 'Nilai tersimpan.'] : ['status' => 'error', 'message' => 'Gagal menyimpan nilai.'];
    }

    public static function getSubmissionsForAssignment(int $assignmentId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT sub.*, u.username, u.full_name, u.nis
            FROM submissions sub
            JOIN users u ON sub.student_id = u.id
            WHERE sub.assignment_id = ?
            ORDER BY sub.submitted_at DESC');
        $stmt->execute([$assignmentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getStudentSubmissions(int $studentId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT sub.*, a.title AS assignment_title, a.deadline, a.max_score, s.name AS subject_name
            FROM submissions sub
            JOIN assignments a ON sub.assignment_id = a.id
            JOIN subjects s ON a.subject_id = s.id
            WHERE sub.student_id = ?
            ORDER BY sub.submitted_at DESC');
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function gradebook(int $classId): array
    {
        $pdo = Database::get();
        $aStmt = $pdo->prepare('SELECT id, title, max_score FROM assignments WHERE class_id = ? AND deleted_at IS NULL ORDER BY deadline ASC');
        $aStmt->execute([$classId]);
        $assignments = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        $sStmt = $pdo->prepare('SELECT id, full_name, username, nis FROM users WHERE class_id = ? AND role = "murid" AND deleted_at IS NULL ORDER BY full_name ASC');
        $sStmt->execute([$classId]);
        $students = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        $scores = [];
        foreach ($assignments as $a) {
            $scStmt = $pdo->prepare('SELECT student_id, score, is_late FROM submissions WHERE assignment_id = ?');
            $scStmt->execute([(int)$a['id']]);
            foreach ($scStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $scores[(int)$row['student_id']][(int)$a['id']] = $row;
            }
        }

        return ['assignments' => $assignments, 'students' => $students, 'scores' => $scores];
    }

    /* =========================================================================
     * 3. QUIZZES (Kuis Interaktif & Bank Soal Otomatis)
     * ========================================================================= */

    public static function createQuiz(
        $arg1,
        $arg2,
        int $classId,
        string $title,
        string $description,
        int $durationMinutes = 30,
        int $passingScore = 75
    ): int {
        $subjectId = (int)$arg1;
        $teacherId = (int)$arg2;
        if ($subjectId > 100 && $teacherId < 50) {
            $tmp = $subjectId;
            $subjectId = $teacherId;
            $teacherId = $tmp;
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO quizzes 
            (subject_id, teacher_id, class_id, title, description, duration_minutes, passing_score)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $subjectId,
            $teacherId,
            $classId,
            trim($title),
            trim($description),
            $durationMinutes,
            $passingScore
        ]);
        $id = (int)$pdo->lastInsertId();
        AuditLog::log('quiz_create', ['quiz_id' => $id, 'title' => $title], $teacherId);
        return $id;
    }

    public static function addQuestion(
        int $quizId,
        string $questionText,
        string $questionType,
        int $points,
        array $options
    ): int {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question_text, question_type, points) VALUES (?, ?, ?, ?)');
            $stmt->execute([$quizId, trim($questionText), $questionType, $points]);
            $questionId = (int)$pdo->lastInsertId();

            $optStmt = $pdo->prepare('INSERT INTO quiz_options (question_id, option_text, is_correct, order_num) VALUES (?, ?, ?, ?)');
            $order = 1;
            foreach ($options as $opt) {
                $optStmt->execute([
                    $questionId,
                    trim((string)($opt['text'] ?? '')),
                    !empty($opt['is_correct']) ? 1 : 0,
                    $order++
                ]);
            }
            $pdo->commit();
            return $questionId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function getQuiz(int $quizId, bool $withAnswers = false): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT q.*, s.name AS subject_name, u.full_name AS teacher_name 
            FROM quizzes q
            JOIN subjects s ON q.subject_id = s.id
            JOIN users u ON q.teacher_id = u.id
            WHERE q.id = ? AND q.deleted_at IS NULL');
        $stmt->execute([$quizId]);
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quiz) return null;

        $qStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY order_num, id ASC');
        $qStmt->execute([$quizId]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($questions as &$q) {
            $optSql = $withAnswers
                ? 'SELECT id, option_text, is_correct, order_num FROM quiz_options WHERE question_id = ? ORDER BY order_num, id ASC'
                : 'SELECT id, option_text, order_num FROM quiz_options WHERE question_id = ? ORDER BY order_num, id ASC';
            $optStmt = $pdo->prepare($optSql);
            $optStmt->execute([$q['id']]);
            $q['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($q);

        $quiz['questions'] = $questions;
        return $quiz;
    }

    public static function getQuizzesByClass(int $classId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT q.*, s.name AS subject_name, u.full_name AS teacher_name,
            (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) AS question_count
            FROM quizzes q
            JOIN subjects s ON q.subject_id = s.id
            JOIN users u ON q.teacher_id = u.id
            WHERE q.class_id = ? AND q.is_active = 1 AND q.deleted_at IS NULL
            ORDER BY q.created_at DESC');
        $stmt->execute([$classId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function startQuizAttempt(int $quizId, int $studentId): array
    {
        $pdo = Database::get();
        $quiz = self::getQuiz($quizId, false);
        if (!$quiz) {
            return ['status' => 'error', 'message' => 'Kuis tidak ditemukan.'];
        }

        $maxScore = 0;
        foreach ($quiz['questions'] as $q) {
            $maxScore += (int)$q['points'];
        }

        $stmt = $pdo->prepare('INSERT INTO quiz_attempts (quiz_id, student_id, started_at, max_score) VALUES (?, ?, datetime("now"), ?)');
        $stmt->execute([$quizId, $studentId, $maxScore]);
        $attemptId = (int)$pdo->lastInsertId();

        AuditLog::log('quiz_start', ['quiz_id' => $quizId, 'attempt_id' => $attemptId], $studentId);

        return [
            'status' => 'success',
            'attempt_id' => $attemptId,
            'quiz' => $quiz,
            'duration_minutes' => $quiz['duration_minutes']
        ];
    }

    public static function submitQuizAttempt(int $attemptId, int $studentId, array $answers): array
    {
        $pdo = Database::get();
        $attStmt = $pdo->prepare('SELECT a.*, q.passing_score, q.duration_minutes 
            FROM quiz_attempts a
            JOIN quizzes q ON a.quiz_id = q.id
            WHERE a.id = ? AND a.student_id = ? AND a.finished_at IS NULL');
        $attStmt->execute([$attemptId, $studentId]);
        $attempt = $attStmt->fetch(PDO::FETCH_ASSOC);

        if (!$attempt) {
            return ['status' => 'error', 'message' => 'Sesi kuis tidak valid atau sudah diserahkan sebelumnya.'];
        }

        $quizId = (int)$attempt['quiz_id'];
        $fullQuiz = self::getQuiz($quizId, true);
        if (!$fullQuiz) {
            return ['status' => 'error', 'message' => 'Kuis tidak ditemukan.'];
        }

        $totalScore = 0;
        $maxScore = (int)$attempt['max_score'];

        $pdo->beginTransaction();
        try {
            $ansStmt = $pdo->prepare('INSERT INTO quiz_answers 
                (attempt_id, question_id, selected_option_id, answer_text, is_correct, points_earned) 
                VALUES (?, ?, ?, ?, ?, ?)');

            foreach ($fullQuiz['questions'] as $q) {
                $qId = (int)$q['id'];
                $selectedOptId = isset($answers[$qId]) ? (int)$answers[$qId] : null;
                $points = (int)$q['points'];
                $isCorrect = 0;
                $earned = 0;

                foreach ($q['options'] as $opt) {
                    if ((int)$opt['id'] === $selectedOptId && (int)$opt['is_correct'] === 1) {
                        $isCorrect = 1;
                        $earned = $points;
                        break;
                    }
                }

                $totalScore += $earned;
                $ansStmt->execute([
                    $attemptId,
                    $qId,
                    $selectedOptId,
                    null,
                    $isCorrect,
                    $earned
                ]);
            }

            $passed = ($totalScore >= (int)$attempt['passing_score']) ? 1 : 0;
            $finStmt = $pdo->prepare('UPDATE quiz_attempts 
                SET finished_at = datetime("now"), score = ?, passed = ? 
                WHERE id = ?');
            $finStmt->execute([$totalScore, $passed, $attemptId]);

            $pdo->commit();

            AuditLog::log('quiz_submit', [
                'attempt_id' => $attemptId,
                'score' => $totalScore,
                'max_score' => $maxScore,
                'passed' => (bool)$passed
            ], $studentId);

            return [
                'status' => 'success',
                'score' => $totalScore,
                'max_score' => $maxScore,
                'passed' => (bool)$passed,
                'message' => $passed ? 'Selamat, Anda lulus kuis ini!' : 'Anda belum mencapai nilai kelulusan. Pelajari kembali materi ini.'
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
