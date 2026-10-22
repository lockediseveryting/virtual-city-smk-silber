<?php
// One-line parseable security log for fail2ban + root dashboard feed.
declare(strict_types=1);

namespace VC\Security;

final class SecurityLog
{
    public static function write(string $event, array $ctx = []): void
    {
        $line = sprintf(
            "%s event=%s ip=%s user=%s %s\n",
            date('Y-m-d\TH:i:sP'),
            $event,
            Ip::bucket(),
            $_SESSION['username'] ?? '-',
            implode(' ', array_map(
                static fn($k, $v) => $k . '="' . str_replace('"', "'", substr((string)$v, 0, 200)) . '"',
                array_keys($ctx), array_values($ctx)
            ))
        );
        @file_put_contents(\VC_PRIVATE . '/logs/security.log', $line, FILE_APPEND | LOCK_EX);
    }
}
