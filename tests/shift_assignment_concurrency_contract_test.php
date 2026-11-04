<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assign = file_get_contents($root . '/assign_shift.php');
$delete = file_get_contents($root . '/delete_shift.php');
$create_employee = file_get_contents($root . '/add_employee.php');

function shiftContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

shiftContractAssert(
    is_string($assign) && is_string($delete) && is_string($create_employee),
    'shift and employee mutation endpoints are readable'
);

foreach (['assign_shift.php' => $assign, 'delete_shift.php' => $delete] as $name => $source) {
    shiftContractAssert(
        strpos($source, 'auth_require_admin($conn)') !== false,
        "{$name} remains Admin restricted"
    );
    shiftContractAssert(
        strpos($source, 'csrf_validate_token($_POST[\'csrf_token\'] ?? null)') !== false,
        "{$name} validates a session-bound CSRF token"
    );
    shiftContractAssert(
        strpos($source, 'mysqli_begin_transaction($conn)') !== false
            && strpos($source, 'mysqli_commit($conn)') !== false
            && strpos($source, 'mysqli_rollback($conn)') !== false,
        "{$name} commits successful changes and rolls back failures"
    );
    shiftContractAssert(
        strpos($source, 'WHERE id=\'$id\'') === false
            && strpos($source, 'WHERE shift_id=\'$id\'') === false,
        "{$name} does not interpolate request IDs into SQL"
    );
}

shiftContractAssert(
    strpos($assign, '$request_method === \'POST\'') !== false,
    'assignment mutation is reachable only through its POST branch'
);
shiftContractAssert(
    strpos($delete, '($_SERVER[\'REQUEST_METHOD\'] ?? \'\') !== \'POST\'') !== false,
    'shift deletion requires POST'
);

$assign_shift_lock = strpos($assign, 'SELECT status FROM shifts WHERE id = ? LIMIT 1 FOR UPDATE');
$assign_user_lock = strpos($assign, 'SELECT status, shift_id FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
$assign_update = strpos($assign, 'UPDATE users SET shift_id = ? WHERE id = ? AND status = \'Active\'');
shiftContractAssert(
    $assign_shift_lock !== false && $assign_user_lock !== false && $assign_update !== false
        && $assign_shift_lock < $assign_user_lock && $assign_user_lock < $assign_update,
    'assignment locks the shift then active employee before updating'
);

$delete_shift_lock = strpos($delete, 'SELECT id FROM shifts WHERE id = ? LIMIT 1 FOR UPDATE');
$delete_user_check = strpos($delete, 'SELECT id FROM users WHERE shift_id = ? LIMIT 1 FOR UPDATE');
$delete_statement = strpos($delete, 'DELETE FROM shifts WHERE id = ?');
shiftContractAssert(
    $delete_shift_lock !== false && $delete_user_check !== false && $delete_statement !== false
        && $delete_shift_lock < $delete_user_check && $delete_user_check < $delete_statement,
    'deletion uses the same shift lock before checking assignments and deleting'
);
shiftContractAssert(
    strpos($delete, 'mysqli_stmt_affected_rows($delete) !== 1') !== false
        && strpos($delete, "header('Location: manage_shift.php?success=shift_deleted')") > $delete_statement,
    'deletion reports success only after exactly one row is deleted and committed'
);

$create_shift_lock = strpos($create_employee, 'SELECT status FROM shifts WHERE id = ? LIMIT 1 FOR UPDATE');
$create_insert = strpos($create_employee, 'INSERT INTO users (employee_id, full_name, password');
$create_commit = strpos($create_employee, 'mysqli_commit($conn)');
$create_rollback = strpos($create_employee, 'mysqli_rollback($conn)');
shiftContractAssert(
    $create_shift_lock !== false && $create_insert !== false && $create_commit !== false
        && $create_shift_lock < $create_insert && $create_insert < $create_commit
        && $create_rollback !== false,
    'employee creation locks and revalidates the selected shift through the insert transaction'
);
shiftContractAssert(
    strpos($create_employee, 'shift_id = \'$shift_id\'') === false,
    'employee creation does not interpolate shift identifiers into SQL'
);

echo "PASS: shift assignment/deletion concurrency contracts; no database or HTTP operations were performed.\n";
