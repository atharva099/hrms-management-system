<?php

include("includes/db.php");
require_once "includes/auth.php";

auth_require_admin($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST', true, 405);
    exit('Method Not Allowed');
}

if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Forbidden');
}

$id = $_POST['employee_id'] ?? null;
if (
    !is_string($id)
    || !preg_match('/^EMP([0-9]+)$/iD', $id, $matches)
    || ltrim($matches[1], '0') === ''
    || filter_var(ltrim($matches[1], '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
) {
    http_response_code(400);
    exit('Invalid employee ID');
}

$current_admin =
    auth_current_employee_id();

if (is_string($current_admin) && strcasecmp($id, $current_admin) === 0) {
    header("Location: employees.php?error=self_deactivate");
    exit();
}

if (!mysqli_begin_transaction($conn)) {
    http_response_code(500);
    exit('Employee status could not be updated.');
}

try {
    // Check-in takes this same parent-row lock. It prevents a new open
    // attendance row from racing with employee deactivation.
    $employee_lock = mysqli_prepare(
        $conn,
        'SELECT id, status FROM users WHERE employee_id = ? LIMIT 1 FOR UPDATE'
    );
    if ($employee_lock === false || !mysqli_stmt_bind_param($employee_lock, 's', $id)
        || !mysqli_stmt_execute($employee_lock)) {
        if ($employee_lock instanceof mysqli_stmt) {
            mysqli_stmt_close($employee_lock);
        }
        throw new RuntimeException('employee lock failed');
    }
    if (!mysqli_stmt_bind_result($employee_lock, $locked_user_id, $locked_status)) {
        mysqli_stmt_close($employee_lock);
        throw new RuntimeException('employee lock result binding failed');
    }
    $employee_found = mysqli_stmt_fetch($employee_lock);
    mysqli_stmt_close($employee_lock);
    if (!$employee_found || $locked_status !== 'Active') {
        throw new DomainException('employee is no longer active');
    }

    // Keep employees with an unclosed check-in active so they can use the
    // authenticated checkout flow. These legacy time fields are present in
    // both the original and timestamp-migrated attendance schemas.
    $open_attendance = mysqli_prepare(
        $conn,
        'SELECT id FROM attendance
         WHERE employee_id = ?
           AND check_in IS NOT NULL AND check_out IS NULL
         LIMIT 1 FOR UPDATE'
    );
    if ($open_attendance === false || !mysqli_stmt_bind_param($open_attendance, 's', $id)
        || !mysqli_stmt_execute($open_attendance) || !mysqli_stmt_store_result($open_attendance)) {
        if ($open_attendance instanceof mysqli_stmt) {
            mysqli_stmt_close($open_attendance);
        }
        throw new RuntimeException('open attendance check failed');
    }
    $has_open_attendance = mysqli_stmt_num_rows($open_attendance) > 0;
    mysqli_stmt_close($open_attendance);
    if ($has_open_attendance) {
        mysqli_rollback($conn);
        header('Location: employees.php?error=open_attendance');
        exit();
    }

    $update_statement = mysqli_prepare(
        $conn,
        "UPDATE users SET status = 'Inactive' WHERE employee_id = ? AND status = 'Active'"
    );
    if ($update_statement === false || !mysqli_stmt_bind_param($update_statement, 's', $id)
        || !mysqli_stmt_execute($update_statement) || mysqli_stmt_affected_rows($update_statement) !== 1) {
        if ($update_statement instanceof mysqli_stmt) {
            mysqli_stmt_close($update_statement);
        }
        throw new RuntimeException('employee status update failed');
    }
    mysqli_stmt_close($update_statement);

    if (!mysqli_commit($conn)) {
        throw new RuntimeException('employee deactivation commit failed');
    }
} catch (Throwable $error) {
    mysqli_rollback($conn);
    http_response_code(500);
    exit('Employee status could not be updated.');
}

header("Location: employees.php?success=deactivated");

exit();

?>
