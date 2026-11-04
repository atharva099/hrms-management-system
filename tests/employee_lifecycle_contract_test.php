<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$deactivation = file_get_contents($root . '/deactivate_employee.php');
$employees = file_get_contents($root . '/employees.php');
$workflow = file_get_contents($root . '/includes/attendance_workflow.php');

function employeeLifecycleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$assert = static function ($condition, $message): void {
    employeeLifecycleAssert((bool) $condition, $message);
};

$assert(
    strpos($deactivation, 'auth_require_admin($conn)') !== false
        && strpos($deactivation, "(\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'") !== false
        && strpos($deactivation, 'csrf_validate_token($_POST[\'csrf_token\'] ?? null)') !== false,
    'deactivation remains Admin-only, POST-only, and CSRF protected'
);

$transaction = strpos($deactivation, 'mysqli_begin_transaction($conn)');
$parent_lock = strpos($deactivation, 'FOR UPDATE');
$open_check = strpos($deactivation, 'AND check_in IS NOT NULL AND check_out IS NULL');
$status_update = strpos($deactivation, "UPDATE users SET status = 'Inactive'");
$assert(
    $transaction !== false && $parent_lock !== false && $open_check !== false
        && $status_update !== false && $transaction < $parent_lock
        && $parent_lock < $open_check && $open_check < $status_update,
    'deactivation serializes on the employee row and checks open attendance before changing status'
);

$assert(
    strpos($deactivation, 'mysqli_stmt_bind_param($open_attendance, \'s\', $id)') !== false
        && strpos($deactivation, 'LIMIT 1 FOR UPDATE') !== false
        && strpos($deactivation, "header('Location: employees.php?error=open_attendance')") !== false,
    'open attendance lookup is bound and rejects deactivation with a safe user message'
);

$open_branch = strpos($deactivation, 'if ($has_open_attendance) {');
$rollback_before_redirect = strpos($deactivation, 'mysqli_rollback($conn);', $open_branch ?: 0);
$redirect_before_update = strpos($deactivation, "header('Location: employees.php?error=open_attendance')", $open_branch ?: 0);
$assert(
    $open_branch !== false && $rollback_before_redirect !== false
        && $redirect_before_update !== false && $redirect_before_update < $status_update,
    'a rejected deactivation rolls back before returning and cannot reach the status update'
);

$assert(
    strpos($deactivation, "WHERE employee_id = ? AND status = 'Active'") !== false
        && strpos($deactivation, 'mysqli_stmt_affected_rows($update_statement) !== 1') !== false
        && strpos($deactivation, 'mysqli_rollback($conn);') !== false,
    'status mutation is parameterized, requires an active row, and fails closed on errors'
);

$assert(
    strpos($employees, 'This employee has an open check-in.') !== false
        && strpos($workflow, 'attendance_workflow_checkin($conn, $employee_id, DateTimeImmutable $now)') !== false,
    'the employee screen explains the blocked transition and the workflow accepts the caller identity explicitly'
);

echo "PASS: employee lifecycle contracts; no database or HTTP operations were performed.\n";
