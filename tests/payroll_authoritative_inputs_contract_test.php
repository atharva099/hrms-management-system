<?php

declare(strict_types=1);

function payrollAuthorityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$source = file_get_contents($root . '/salary.php');
payrollAuthorityAssert(is_string($source), 'committed salary implementation is readable');
$source = str_replace(["\r\n", "\r"], "\n", $source);

$start = strpos($source, "if (isset(\$_POST['generate_salary'])) {");
$end = strpos($source, "\n?>", $start === false ? 0 : $start);
payrollAuthorityAssert($start !== false && $end !== false, 'salary generation handler boundaries exist');
$handler = substr($source, $start, $end - $start);

$admin_offset = strpos($handler, 'auth_require_admin($conn);');
$csrf_offset = strpos($handler, "csrf_validate_token(\$_POST['csrf_token'] ?? null)");
$transaction_offset = strpos($handler, 'mysqli_begin_transaction');
payrollAuthorityAssert(
    $admin_offset !== false && $csrf_offset !== false && $transaction_offset !== false
        && $admin_offset < $csrf_offset && $csrf_offset < $transaction_offset,
    'Admin authorization, POST, and CSRF checks precede payroll database work'
);
payrollAuthorityAssert(
    strpos($handler, "\$_POST['basic_salary']") === false && strpos($handler, "\$_POST['incentive']") === false,
    'forged posted salary and incentive values are ignored'
);
payrollAuthorityAssert(
    strpos($handler, 'SELECT salary, incentive FROM users') !== false
        && strpos($handler, "role = 'Employee' AND status = 'Active'") !== false
        && strpos($handler, 'FOR UPDATE') !== false,
    'amounts come from the locked active Employee record'
);
payrollAuthorityAssert(
    strpos($handler, "preg_match('/^EMP[0-9]{3,17}$/iD'") !== false
        && strpos($handler, 'valid_month') !== false,
    'employee identifier and Month YYYY input are validated'
);
payrollAuthorityAssert(
    strpos($handler, 'mysqli_stmt_bind_param($employee_statement, \'s\', $salary_employee_id)') !== false
        && strpos($handler, 'mysqli_stmt_bind_param($check_query, \'ss\', $salary_employee_id, $salary_month)') !== false,
    'employee lookup and duplicate check use bound parameters'
);
payrollAuthorityAssert(
    strpos($handler, "WHERE employee_id=?\n\nAND salary_month=?") !== false,
    'duplicate lookup remains compatible with salaries.salary_month'
);
payrollAuthorityAssert(
    strpos($handler, 'mysqli_rollback($conn)') !== false
        && strpos($handler, 'mysqli_commit($conn)') !== false,
    'duplicate and failed generation paths roll back while successful insert commits'
);
$lock_offset = strpos($handler, 'FOR UPDATE');
$duplicate_offset = strpos($handler, 'SELECT *' . "\n\nFROM salaries");
$insert_offset = strpos($handler, 'INSERT INTO salaries');
payrollAuthorityAssert(
    $lock_offset !== false && $duplicate_offset !== false && $insert_offset !== false
        && $lock_offset < $duplicate_offset && $duplicate_offset < $insert_offset,
    'employee row lock is held across duplicate check and insert'
);
payrollAuthorityAssert(
    strpos($handler, "salary_period") === false,
    'security fix does not depend on the unfinished salary_period refactor'
);

echo "PASS: database-free payroll authority contract completed; no DB or HTTP operations were performed.\n";
