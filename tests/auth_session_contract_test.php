<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
$authSource = file_get_contents(dirname(__DIR__) . '/includes/auth.php');

function authSessionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

authSessionAssert(
    !auth_session_idle_expired(1000, 2800),
    'session remains valid at the configured 30-minute idle boundary'
);
authSessionAssert(
    auth_session_idle_expired(1000, 2801),
    'session expires after more than 30 minutes idle'
);
authSessionAssert(
    auth_session_idle_expired(null, 2800),
    'authenticated session without activity metadata fails closed'
);
authSessionAssert(
    auth_session_idle_expired(2801, 2800),
    'future activity timestamps fail closed'
);
authSessionAssert(
    auth_session_idle_expired(1000, 2800, 0),
    'invalid timeout configuration fails closed'
);
authSessionAssert(
    is_string($authSource)
        && strpos($authSource, 'if (session_status() !== PHP_SESSION_ACTIVE)') !== false
        && strpos($authSource, '$_SESSION[\'auth_last_activity_at\'] ?? null') !== false,
    'idle expiry is checked even when a session was started by an earlier include'
);

echo "PASS: idle session contract; no session or database was started.\n";
