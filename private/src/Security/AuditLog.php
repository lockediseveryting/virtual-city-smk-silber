<?php
// Append-only audit log with hash chain (each row contains prev row's hash).
declare(strict_types=1);

namespace VC\Security;

final class AuditLog
{
    public static function log(string $action, array $ctx = [], ?int $userId = null, string $result = 'ok'): void
    {
        $pdo = \VC\Database::get();
        $prev = $pdo->query("SELECT chain_hash FROM audit_log ORDER BY id DESC LIMIT 1")->fetchColumn();
        $row = [
            'ts' => date('c'),
            'user_id' => $userId ?? ($_SESSION['user_id'] ?? null),
            'ip' => Ip::bucket(),
            'action' => $action,
            'result' => $result,
            'ctx' => json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        $chain = hash('sha256', ($prev ?: 'genesis') . '|' . implode('|', [$row['ts'], $row['user_id'] ?? '', $row['ip'], $row['action'], $row['result'], $row['ctx']]));
        $stmt = $pdo->prepare('INSERT INTO audit_log (ts, user_id, ip, action, result, ctx, chain_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$row['ts'], $row['user_id'], $row['ip'], $row['action'], $row['result'], $row['ctx'], $chain]);
    }

    /** ROOT button: recompute chain; returns number of broken links. */
    public static function verify(): int
    {
        $pdo = \VC\Database::get();
        $rows = $pdo->query('SELECT * FROM audit_log ORDER BY id ASC')->fetchAll();
        $prev = 'genesis';
        $broken = 0;
        foreach ($rows as $r) {
            $expect = hash('sha256', $prev . '|' . implode('|', [$r['ts'], $r['user_id'], $r['ip'], $r['action'], $r['result'], $r['ctx']]));
            if (!hash_equals($expect, $r['chain_hash'])) {
                $broken++;
            }
            $prev = $r['chain_hash'];
        }
        return $broken;
    }
}
