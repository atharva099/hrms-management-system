<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/includes/payroll_database.php');
$calculator = file_get_contents($root . '/includes/payroll_calculation.php');
if (!is_string($adapter) || !is_string($calculator)) {
    fwrite(STDERR, "FAIL: payroll adapter sources unavailable\n");
    exit(1);
}
$checks = [
    'adapter pins stage schema and MariaDB port' => strpos($adapter, "COREAXIS_PAYROLL_STAGE_DATABASE = 'coreaxis_r5_payroll_period_stage_20261013'") !== false
        && strpos($adapter, 'COREAXIS_PAYROLL_STAGE_PORT = 3307') !== false
        && strpos($adapter, "mysqli_real_connect(\$conn, '127.0.0.1'") !== false,
    'adapter validates database and loopback connection identity' => strpos($adapter, 'DATABASE() AS db_name, @@port AS server_port') !== false
        && strpos($adapter, "strpos(\$conn->host_info, '127.0.0.1') === false") !== false,
    'adapter uses a read-only consistent snapshot' => strpos($adapter, 'MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT') !== false,
    'adapter requires InnoDB tables and payroll period database constraints' => strpos($adapter, "!== 'InnoDB'") !== false
        && strpos($adapter, 'uq_salaries_employee_period') !== false
        && strpos($adapter, "indexed_columns'] ?? null) !== 'employee_id,salary_period'") !== false
        && strpos($adapter, 'chk_salaries_period_contract') !== false,
    'adapter verifies all 17 payroll foreign-key targets and RESTRICT actions' => substr_count($adapter, "=> ['") >= 17
        && strpos($adapter, 'fk_payroll_policy_creator') !== false
        && strpos($adapter, 'fk_payroll_policy_approver') !== false
        && strpos($adapter, "REFERENCED_TABLE_SCHEMA'] !== COREAXIS_PAYROLL_STAGE_DATABASE") !== false
        && strpos($adapter, "UPDATE_RULE'] !== 'RESTRICT'") !== false
        && strpos($adapter, "DELETE_RULE'] !== 'RESTRICT'") !== false
        && strpos($adapter, 'payrollTriggerCount !== count($requiredTriggers)') !== false
        && strpos($adapter, 'payrollForeignKeyCount !== count($requiredForeignKeys)') !== false,
    'adapter binds values through prepared statements' => strpos($adapter, 'mysqli_prepare($conn, $sql)') !== false
        && strpos($adapter, 'mysqli_stmt_bind_param') !== false,
    'adapter reads effective-dated compensation instead of mutable users salary fields' => strpos($adapter, 'payroll_compensation_versions') !== false
        && strpos($adapter, 'basic_salary_cents,incentive_cents,currency') !== false,
    'adapter uses approved versioned payroll policy and explicit rules' => strpos($adapter, "policy_status = 'Approved'") !== false
        && strpos($adapter, 'payroll_policy_rules') !== false
        && strpos($adapter, 'payroll_leave_classifications') !== false,
    'adapter requires complete approved effective work roster' => strpos($adapter, 'payroll_work_schedule_versions') !== false
        && strpos($adapter, 'work_schedule_missing_or_ambiguous') !== false,
    'adapter blocks unresolved scheduled attendance instead of guessing absence' => strpos($adapter, 'scheduled_day_attendance_not_finalized') !== false,
    'adapter assigns each attendance status before snapshot and checkout validation' => strpos($adapter, "\$status = \$attendance['status'] ?? null;") !== false
        && strpos($adapter, "\$status = \$attendance['status'] ?? null;")
            < strpos($adapter, "if (\$attendance && \$status !== 'Leave')")
        && strpos($adapter, "if (\$attendance && in_array(\$status, ['Present','Late','Half Day','Late Half Day','Sunday Working','Holiday Working'], true)") !== false
        && strpos($adapter, "empty(\$attendance['check_out_at'])") !== false,
    'adapter attendance query is valid PHP and appends row locking outside the SQL literal' => strpos($adapter, "ORDER BY attendance_date, id' . \$lockSuffix") !== false
        && strpos($adapter, "ORDER BY attendance_date, id\" . \$lockSuffix") === false,
    'adapter consumes attendance, leave, and holiday records' => strpos($adapter, 'FROM attendance WHERE employee_id = ?') !== false
        && strpos($adapter, 'FROM leaves') !== false && strpos($adapter, 'FROM holidays') !== false,
    'adapter contains no application config include or database mutations' => !preg_match('/^\s*(?:require|include)(_once)?\s*[\'\"]includes\\/db\.php/m', $adapter)
        && !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w|DELETE\s+FROM|CREATE\s+TABLE|DROP\s+TABLE)\b/i', $adapter),
    'calculator fingerprints explicit policy version and normalized dates' => strpos($calculator, '\'policy_version_id\' => isset($policy[\'id\'])') !== false,
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $name . "\n";
    if (!$passed) { $failed++; }
}
if ($failed > 0) { exit(1); }
echo 'PASS: payroll database adapter contracts; no database connection attempted.' . "\n";
