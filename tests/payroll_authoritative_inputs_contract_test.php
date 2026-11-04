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
    strpos($source, '$payroll_actions_enabled = false;') !== false
        && strpos($handler, 'if (!$payroll_actions_enabled)') !== false
        && strpos($handler, 'http_response_code(503)') !== false
        && strpos($handler, '$payroll_blocked_message') !== false
        && strpos($handler, "csrf_validate_token(\$_POST['csrf_token'] ?? null)") < strpos($handler, 'if (!$payroll_actions_enabled)'),
    'generation fails closed after Admin/POST/CSRF checks until approved pay rules are available'
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
        && strpos($handler, 'coreaxis_payroll_period_from_label($salary_month)') !== false,
    'employee identifier and canonical payroll period input are validated'
);
payrollAuthorityAssert(
    strpos($handler, 'mysqli_stmt_bind_param($employee_statement, \'s\', $salary_employee_id)') !== false
        && strpos($handler, 'mysqli_stmt_bind_param($check_query, \'sss\', $salary_employee_id, $period_start, $salary_month)') !== false,
    'employee lookup and duplicate check use bound parameters'
);
payrollAuthorityAssert(
    strpos($handler, 'salary_period') !== false
        && strpos($handler, 'salary_month=?') !== false
        && strpos($handler, 'salary_period,') !== false,
    'generation uses canonical salary_period and writes the legacy salary_month label'
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
    strpos($handler, 'coreaxis_payroll_period_from_label($salary_month)') !== false
        && strpos($handler, 'salary_period,') !== false,
    'canonical date is derived from the validated legacy period label and persisted with it'
);

$process_start = strpos($source, "if (isset(\$_POST['process_payroll'])) {");
$process_end = strpos($source, "if (isset(\$_POST['generate_salary'])) {", $process_start === false ? 0 : $process_start);
$process_handler = $process_start !== false && $process_end !== false
    ? substr($source, $process_start, $process_end - $process_start)
    : '';
$process_csrf = strpos($process_handler, "csrf_validate_token(\$_POST['csrf_token'] ?? null)");
$process_gate = strpos($process_handler, 'if (!$payroll_actions_enabled)');
payrollAuthorityAssert(
    $process_handler !== '' && $process_csrf !== false && $process_gate !== false
        && $process_csrf < $process_gate && strpos($process_handler, 'http_response_code(503)') !== false,
    'payment release is rejected after Admin/POST/CSRF validation until an auditable payment workflow exists'
);
payrollAuthorityAssert(
    strpos($source, 'name="generate_salary" disabled') !== false
        && strpos($source, '$pending_release[\'total_pending_release\'] == 0 || !$payroll_actions_enabled') !== false,
    'payroll mutation controls are visibly disabled while server-side gates remain authoritative'
);
payrollAuthorityAssert(
    strpos($source, '$escape_salary_html($emp[\'full_name\'])') !== false
        && strpos($source, '$escape_salary_html($row[\'full_name\'])') !== false
        && strpos($source, 'data-month="<?php echo $escape_salary_html(') !== false,
    'employee-controlled payroll names and period filters are escaped in HTML contexts'
);

echo "PASS: database-free payroll authority contract completed; no DB or HTTP operations were performed.\n";
