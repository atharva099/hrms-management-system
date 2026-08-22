<?php

function auth_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
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

function auth_login($employee_id, $role)
{
    auth_start_session();
    session_regenerate_id(true);

    $_SESSION['employee_id'] = $employee_id;
    $_SESSION['role'] = $role;
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
        'SELECT status FROM users WHERE employee_id = ? LIMIT 1'
    );

    if ($statement === false) {
        return false;
    }

    mysqli_stmt_bind_param($statement, 's', $employee_id);

    if (!mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        return false;
    }

    mysqli_stmt_bind_result($statement, $status);
    $found = mysqli_stmt_fetch($statement);
    mysqli_stmt_close($statement);

    return $found && hash_equals('Active', (string) $status);
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
