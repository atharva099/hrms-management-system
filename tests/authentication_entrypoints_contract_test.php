<?php

declare(strict_types=1);

$project_root = dirname(__DIR__);
$index_source = file_get_contents($project_root . '/index.php');
$login_source = file_get_contents($project_root . '/login.php');
$logout_source = file_get_contents($project_root . '/logout.php');
$auth_source = file_get_contents($project_root . '/includes/auth.php');

function authenticationEntrypointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

authenticationEntrypointAssert(
    is_string($index_source)
        && strpos($index_source, "require_once __DIR__ . '/includes/auth.php';") !== false
        && strpos($index_source, '<?php echo csrf_field(); ?>') !== false,
    'login form starts the shared session and renders a session-bound CSRF token'
);

$login_method_position = strpos($login_source, '($_SERVER[\'REQUEST_METHOD\'] ?? \'\') !== \'POST\'');
$login_csrf_position = strpos($login_source, 'csrf_validate_token(');
$login_db_position = strpos($login_source, "require __DIR__ . '/includes/db.php';");
authenticationEntrypointAssert(
    is_string($login_source)
        && $login_method_position !== false
        && $login_csrf_position !== false
        && $login_db_position !== false
        && $login_method_position < $login_csrf_position
        && $login_csrf_position < $login_db_position,
    'login rejects non-POST and invalid-CSRF requests before loading the database bootstrap'
);

authenticationEntrypointAssert(
    strpos($login_source, "http_response_code(405);") !== false
        && strpos($login_source, "http_response_code(400);") !== false
        && strpos($login_source, "exit('Invalid request.');") !== false,
    'login rejection responses are explicit and do not expose internal errors'
);

$logout_post_position = strpos($logout_source, 'if ($request_method === \'POST\') {');
$logout_csrf_position = strpos($logout_source, 'csrf_validate_token(');
$logout_mutation_position = strpos($logout_source, 'auth_logout();');
authenticationEntrypointAssert(
    is_string($logout_source)
        && $logout_post_position !== false
        && $logout_csrf_position !== false
        && $logout_mutation_position !== false
        && $logout_post_position < $logout_csrf_position
        && $logout_csrf_position < $logout_mutation_position,
    'logout mutates the session only after authenticated-session and POST CSRF checks'
);

authenticationEntrypointAssert(
    strpos($logout_source, 'if ($request_method !== \'GET\' && $request_method !== \'POST\')') !== false
        && strpos($logout_source, '<?php echo csrf_field(); ?>') !== false,
    'logout GET only renders a confirmation form and unsupported methods are rejected'
);

authenticationEntrypointAssert(
    strpos($auth_source, 'if (!session_regenerate_id(true))') !== false
        && strpos($auth_source, '$_SESSION[\'employee_id\'] = $employee_id;') !== false
        && strpos($auth_source, 'return true;') !== false,
    'login establishes authenticated identity only after session ID regeneration succeeds'
);

echo "PASS: authentication entrypoint contracts; no database or HTTP request was performed.\n";
