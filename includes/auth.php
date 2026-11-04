<?php

function auth_session_idle_expired($last_activity, $now, $timeout_seconds = 1800)
{
    if (
        !is_int($last_activity)
        || !is_int($now)
        || !is_int($timeout_seconds)
        || $last_activity < 0
        || $now < $last_activity
        || $timeout_seconds < 1
    ) {
        return true;
    }

    return ($now - $last_activity) > $timeout_seconds;
}

function auth_start_session()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Reject attacker-supplied/uninitialized session IDs and prevent
        // session identifiers from being accepted or propagated through URLs.
        foreach ([
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
        ] as $setting => $value) {
            if (ini_set($setting, $value) === false) {
                throw new RuntimeException('Secure session configuration is unavailable.');
            }
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        session_start();
    }

    $now = time();
    $has_authenticated_identity = isset($_SESSION['employee_id']) || isset($_SESSION['role']);
    if (
        $has_authenticated_identity
        && auth_session_idle_expired($_SESSION['auth_last_activity_at'] ?? null, $now)
    ) {
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Secure session renewal is unavailable.');
        }
    }
    $_SESSION['auth_last_activity_at'] = $now;
}

function auth_login($employee_id, $role)
{
    auth_start_session();
    if (!session_regenerate_id(true)) {
        return false;
    }
    unset($_SESSION['csrf_token']);

    $_SESSION['employee_id'] = $employee_id;
    $_SESSION['role'] = $role;

    return true;
}

function csrf_get_token()
{
    auth_start_session();

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_validate_token($submitted_token)
{
    auth_start_session();

    if (
        !is_string($submitted_token)
        || $submitted_token === ''
        || !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || $_SESSION['csrf_token'] === ''
    ) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $submitted_token);
}

function csrf_field()
{
    $token = htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8');

    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function auth_current_employee_id()
{
    if (!isset($_SESSION['employee_id']) || !is_string($_SESSION['employee_id'])) {
        return null;
    }

    $employee_id = trim($_SESSION['employee_id']);

    return $employee_id === '' ? null : $employee_id;
}

function auth_current_role()
{
    if (!isset($_SESSION['role']) || !is_string($_SESSION['role'])) {
        return null;
    }

    $role = trim($_SESSION['role']);

    return $role === '' ? null : $role;
}

function auth_is_current_user_active($conn)
{
    $employee_id = auth_current_employee_id();

    if ($employee_id === null || !($conn instanceof mysqli) || mysqli_connect_errno()) {
        return false;
    }

    $statement = mysqli_prepare(
        $conn,
        'SELECT status, role FROM users WHERE employee_id = ? LIMIT 1'
    );

    if ($statement === false) {
        return false;
    }

    mysqli_stmt_bind_param($statement, 's', $employee_id);

    if (!mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        return false;
    }

    mysqli_stmt_bind_result($statement, $status, $role);
    $found = mysqli_stmt_fetch($statement);
    mysqli_stmt_close($statement);

    if (!$found || !hash_equals('Active', (string) $status)) {
        return false;
    }

    $_SESSION['role'] = (string) $role;

    return true;
}

function auth_require_authenticated($conn)
{
    auth_start_session();

    if (!auth_is_current_user_active($conn)) {
        auth_logout();
        header('Location: index.php');
        exit();
    }

    return auth_current_employee_id();
}

function auth_require_role($conn, $required_role)
{
    auth_require_authenticated($conn);

    $roles = is_array($required_role) ? $required_role : [$required_role];

    if (!in_array(auth_current_role(), $roles, true)) {
        header('Location: dashboard.php');
        exit();
    }

    return auth_current_role();
}

function auth_require_admin($conn)
{
    return auth_require_role($conn, 'Admin');
}

function auth_logout()
{
    auth_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookie['path'],
            $cookie['domain'],
            $cookie['secure'],
            $cookie['httponly']
        );
    }

    session_destroy();
}
