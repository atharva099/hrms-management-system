<?php

require_once __DIR__ . '/../includes/attendance_time.php';
require_once __DIR__ . '/attendance_row_version_test_config.php';

$assert = static function ($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};

$assert(attendance_row_version_value(['row_version' => '1']) === 1, 'version 1 is accepted');
$assert(attendance_row_version_value(['row_version' => '0']) === false, 'zero version is rejected');
$assert(attendance_row_version_value(['row_version' => '01']) === false, 'noncanonical version is rejected');
$assert(attendance_row_version_value(['row_version' => '4294967295']) === 4294967295, 'unsigned INT maximum is accepted');
$assert(attendance_row_version_value(['row_version' => '4294967296']) === false, 'unsigned INT overflow is rejected');
$assert(attendance_row_version_disposable_database_is_allowed('coreaxis_r5_attendance_row_version_test_20261013_ab12cd34'), 'unique R5 disposable target name is accepted');
foreach (attendance_row_version_protected_databases() as $protected_database) {
    $assert(!attendance_row_version_disposable_database_is_allowed($protected_database), 'protected database is rejected: ' . $protected_database);
}
$assert(!attendance_row_version_disposable_database_is_allowed('coreaxis_crm'), 'production target is rejected');
$assert(!attendance_row_version_disposable_database_is_allowed('coreaxis_crm_runtime_test'), 'shared runtime clone is rejected');
$assert(!attendance_row_version_disposable_database_is_allowed('coreaxis_r5_test'), 'database outside dedicated attendance namespace is rejected');
$assert(!attendance_row_version_disposable_database_is_allowed('coreaxis_r5_attendance_bad-name'), 'invalid database identifier is rejected');

$overnight = attendance_shift_schedule('2026-10-03', '22:00:00', '06:00:00');
$assert(
    $overnight && $overnight['end']->format('Y-m-d H:i:s') === '2026-10-04 06:00:00',
    'overnight schedule remains next-day aware'
);
$open_row = [
    'attendance_date' => '2026-10-03',
    'check_in_at' => '2026-10-03 22:00:00',
    'scheduled_end_at' => '2026-10-04 06:00:00',
    'check_out_at' => null
];
$assert(
    attendance_is_current_open_checkout_candidate($open_row, '2026-10-04 05:59:00'),
    'current overnight record remains a checkout candidate'
);
$assert(
    !attendance_is_current_open_checkout_candidate($open_row, '2026-10-10 10:00:00'),
    'stale historical open record is excluded'
);

$root = dirname(__DIR__);
$contracts = [
    'includes/attendance_workflow.php' => [
        'UPDATE attendance SET check_out = ?, check_out_at = ?, working_hours = ?, status = ?, row_version = row_version + 1',
        'WHERE id = ? AND row_version = ? AND check_out_at IS NULL',
        'attendance_workflow_checkin($conn, $employee_id, DateTimeImmutable $now)',
        'attendance_workflow_checkout($conn, $employee_id, DateTimeImmutable $now)',
        'INSERT INTO attendance'
    ],
    'attendance.php' => [
        'isset($_POST[\'checkout\']) && !$row_version_schema_ready',
        'auth_require_authenticated($conn)',
        'csrf_validate_token($_POST[\'csrf_token\'] ?? null)',
        'attendance_workflow_checkout($conn, $employee_id, $application_now)'
    ],
    'auto_absent.php' => [
        "UPDATE attendance SET status = 'Half Day', working_hours = '0 hrs', row_version = row_version + 1",
        'WHERE id = ? AND row_version = ? AND check_in_at IS NOT NULL AND check_out_at IS NULL',
        'attendance_has_row_version_column($conn)',
        'auth_require_admin($conn)',
        'csrf_validate_token($_POST[\'csrf_token\'] ?? null)',
        "'SELECT id, check_in_at, check_out_at, scheduled_end_at, check_in, check_out, status, working_hours, row_version FROM attendance"
    ],
    'resolve_leave_conflict.php' => [
        "UPDATE attendance SET status = 'Leave', row_version = row_version + 1",
        'AND row_version = ?',
        'decision === \'convert_absent_to_leave\' && !attendance_has_row_version_column($conn)',
        'auth_require_admin($conn)',
        'csrf_validate_token($_POST[\'csrf_token\'] ?? null)',
        'FOR UPDATE'
    ]
];

foreach ($contracts as $file => $needles) {
    $source = file_get_contents($root . DIRECTORY_SEPARATOR . $file);
    $assert($source !== false, "{$file} source can be read");
    foreach ($needles as $needle) {
        $assert(strpos($source, $needle) !== false, "{$file} contains required version contract: {$needle}");
    }
}

$attendance_source = file_get_contents($root . DIRECTORY_SEPARATOR . 'attendance.php');
$workflow_source = file_get_contents($root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'attendance_workflow.php');
$assert(
    strpos($workflow_source, 'early_checkin_minutes, s.late_checkin_cutoff_minutes') !== false
        && strpos($workflow_source, "s.status = 'Active'") !== false,
    'check-in loads policy from the employee assigned active shift'
);
$assert(
    strpos($workflow_source, 'attendance_checkin_eligibility(') !== false
        && strpos($workflow_source, '$business_date = $checkin_decision[\'business_date\'];') !== false,
    'POST check-in uses the shared eligibility decision and its business date'
);
$assert(
    strpos($attendance_source, 'attendance_has_checkin_policy_schema($conn)') !== false
        && strpos($attendance_source, 'Check-in policy migration is required') !== false,
    'check-in fails closed when required policy schema is unavailable'
);
$assert(
    strpos($workflow_source, 'scheduled_start_at, scheduled_end_at') !== false
        && strpos($workflow_source, 'late_after_at, shift_id_snapshot, shift_name_snapshot') !== false
        && strpos($workflow_source, 'early_checkin_minutes_snapshot') !== false
        && strpos($workflow_source, 'late_checkin_cutoff_minutes_snapshot') !== false,
    'new attendance check-ins store schedule, shift and applied policy snapshots'
);
$assert(
    strpos($workflow_source, 'WHERE employee_id = ? AND attendance_date = ? LIMIT 1 FOR UPDATE') !== false
        && strpos($workflow_source, 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)') !== false,
    'check-in uses the selected business date for locked duplicate prevention and insertion'
);
$assert(
    strpos($workflow_source, 'mysqli_stmt_bind_param($leave_statement, \'ss\', $employee_id, $business_date)') !== false
        && strpos($workflow_source, "format('l') === 'Sunday'") !== false,
    'approved-leave and Sunday classification use the selected business date'
);
$assert(
    strpos($workflow_source, "'sssssssisiis'") !== false,
    'prepared check-in INSERT binds all twelve snapshot and attendance fields'
);
$assert(
    strpos($workflow_source, 'if (!$inserted || $inserted_rows !== 1)') !== false
        && strpos($workflow_source, 'if (!$inserted && $insert_error === 1062)') !== false,
    'insert failure and concurrent duplicate cannot report success'
);
$assert(
    strpos($attendance_source, 'attendance_checkin_policy_message($checkin_decision)') !== false
        && strpos($workflow_source, 'empty($checkin_decision[\'eligible\'])') !== false,
    'UI uses the shared eligibility decision and displays a safe rejection message'
);
$checkout_start = strpos($workflow_source, '$open_candidates = attendance_find_current_open_checkout_candidates');
$checkout_end = strpos($workflow_source, 'if (!mysqli_commit', $checkout_start === false ? 0 : $checkout_start);
$checkout_source = ($checkout_start !== false && $checkout_end !== false)
    ? substr($workflow_source, $checkout_start, $checkout_end - $checkout_start)
    : '';
$assert(
    $checkout_source !== '' && strpos($checkout_source, 'attendance_checkin_eligibility') === false
        && strpos($checkout_source, 'checkin_policy_schema_ready') === false,
    'checkout stays independent of the check-in eligibility window and policy gate'
);

$time_source = file_get_contents($root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'attendance_time.php');
$assert(
    strpos($time_source, 'function attendance_has_checkin_policy_schema') !== false
        && strpos($time_source, "'shifts.early_checkin_minutes' => ['smallint(5) unsigned', 'NO', '15']") !== false
        && strpos($time_source, "'attendance.early_checkin_minutes_snapshot' => ['smallint(5) unsigned', 'YES', null]") !== false,
    'schema gate validates deployed policy and nullable snapshot definitions'
);

$approve_source = file_get_contents($root . DIRECTORY_SEPARATOR . 'approve_leave.php');
$assert($approve_source !== false && strpos($approve_source, 'INSERT INTO attendance') !== false, 'leave approval writer remains insert-only');
$assert($approve_source !== false && strpos($approve_source, 'row_version') === false, 'leave attendance insert relies on row_version default 1');
$reject_source = file_get_contents($root . DIRECTORY_SEPARATOR . 'reject_leave.php');
$assert($reject_source !== false && strpos($reject_source, 'UPDATE attendance') === false, 'leave rejection does not mutate attendance');
$assert(
    strpos(file_get_contents($root . DIRECTORY_SEPARATOR . 'auto_absent.php'), '$already_half_day = $attendance[\'status\'] === \'Half Day\'') !== false,
    'auto-absence avoids incrementing version for its exact no-op state'
);

echo "Database-free attendance row-version contract checks completed.\n";

$integration_path = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'attendance_row_version_integration.php';
$integration_source = file_get_contents($integration_path);
$assert($integration_source !== false, 'clone integration suite source can be read');
$config_source = file_get_contents($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'attendance_row_version_test_config.php');
$assert($config_source !== false, 'pure disposable-target guard helper is readable');
foreach ([
    "'coreaxis_crm'",
    "'coreaxis_crm_runtime_test'",
    "'coreaxis_crm_overnight_workflow_test'",
    "'coreaxis_r5_overnight_forensic_baseline_20261011'",
    "'coreaxis_r5_attendance_overnight_rerun_20261012_7f3a91c2'",
    "'coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c'"
] as $protected_name) {
    $assert(strpos($config_source, $protected_name) !== false, 'target helper explicitly blocks protected database: ' . $protected_name);
}

foreach ([
    "getenv('COREAXIS_TEST_DISPOSABLE_DATABASE')",
    'attendance_row_version_disposable_database_is_allowed($test_database)',
    '$test_database)',
    'attendance_row_version_disposable_database_is_allowed($test_database)',
    '\'COREAXIS_TEST_TARGET_CONFIRMED\' => $test_database',
    "'COREAXIS_TEST_BACKUP_VERIFIED' => 'YES'",
    "'COREAXIS_TEST_ALLOW_WRITES' => 'YES'",
    "const TEST_HOST = '127.0.0.1'",
    "require_once __DIR__ . '/attendance_row_version_test_config.php'",
    'const TEST_PORT = 3307',
    '$db_user = getenv(\'COREAXIS_TEST_DB_USER\');',
    '$db_password = getenv(\'COREAXIS_TEST_DB_PASSWORD\');',
    'if (!is_string($db_user) || $db_user ===',
    'if ($db_password === false)',
    '$db_password = \'\';',
    "const FIXTURE_BUSINESS_DATE = '2098-10-03'",
    '$primary_transaction_open = false;',
    '$secondary_transaction_open = false;',
    'function beginTrackedTransaction($connection, &$transaction_open)',
    'function commitTrackedTransaction($connection, &$transaction_open)',
    'function rollbackTrackedTransaction($connection, &$transaction_open)',
    'function rollbackOutstandingOrFail($connection, &$transaction_open, $connection_label)',
    'database operation failed; driver details suppressed',
    'clone state is uncertain; restore the verified clone backup.',
    'function fixtureShiftTimestamps($business_date, $shift)',
    'attendance_shift_schedule($business_date, $shift[\'in_time\'], $shift[\'out_time\'])',
    '$fixture_users[$employee_id] = $id;',
    '$fixture_attendance_ids[] = $id;',
    'new mysqli(TEST_HOST, $db_user, $db_password, $test_database, TEST_PORT)',
    'pre-existing attendance/user rows and protected payroll/leave/audit tables match baseline',
    'recreate it from the verified backup to clean up',
    'final preservation verification could not complete',
    'catch (Throwable $verification_error)'
] as $required_contract) {
    $assert(strpos($integration_source, $required_contract) !== false, 'integration suite includes safety contract: ' . $required_contract);
}
$assert(
    preg_match('/\\b(?:require|include)(?:_once)?\\s*\\(?[^;\\r\\n]*includes\\/db\\.php/i', $integration_source) !== 1,
    'integration suite does not load workspace DB configuration'
);
$assert(
    preg_match('/\\b(?:exec|shell_exec|system|passthru|proc_open|popen)\\s*\\(/i', $integration_source) !== 1,
    'integration runner does not launch external commands'
);
$assert(strpos($config_source, "'coreaxis_crm'") !== false, 'integration suite explicitly refuses the production database');
$assert(strpos($integration_source, 'DELETE FROM') === false, 'integration suite never deletes database rows');
$assert(
    strpos($integration_source, 'COREAXIS_TEST_DISPOSABLE_DATABASE') !== false
        && strpos($integration_source, 'COREAXIS_TEST_TARGET_CONFIRMED') !== false,
    'integration suite requires an explicit disposable target and matching operator confirmation'
);
$target_guard_position = strpos($integration_source, "\$test_database = getenv('COREAXIS_TEST_DISPOSABLE_DATABASE')");
$gate_position = strpos($integration_source, '$required_gates = [');
$connection_position = strpos($integration_source, 'new mysqli(TEST_HOST');
$assert(
    $target_guard_position !== false && $gate_position > $target_guard_position
        && $connection_position > $gate_position,
    'target validation and all operator gates run before database connection'
);
$assert(
    preg_match('/\\b(?:require|include)(?:_once)?\\s*\\(?[^;\\r\\n]*attendance_row_version_integration\\.php/i', file_get_contents(__FILE__)) !== 1,
    'database-free contract reads runner text without loading or executing it'
);
$finally_position = strpos($integration_source, '} finally {');
$primary_rollback_position = strpos($integration_source, 'rollbackOutstandingOrFail($connection, $primary_transaction_open');
$secondary_rollback_position = strpos($integration_source, 'rollbackOutstandingOrFail($second_connection, $secondary_transaction_open');
$final_fingerprint_position = strpos($integration_source, '$protected_after = []');
$assert(
    $finally_position !== false && $primary_rollback_position > $finally_position
        && $secondary_rollback_position > $primary_rollback_position
        && $final_fingerprint_position > $secondary_rollback_position,
    'both connection rollbacks precede final preservation verification'
);
$assert(substr_count($integration_source, 'mysqli_begin_transaction(') === 1, 'all transaction begins use tracked helper');
$assert(substr_count($integration_source, 'mysqli_commit(') === 1, 'all commits use tracked helper');
$assert(substr_count($integration_source, 'mysqli_rollback(') === 1, 'all rollbacks use tracked helper');
$assert(
    preg_match('/\\b(?:require|include)(?:_once)?\\s*\\(?[^;\\r\\n]*includes\\/db\\.php/i', $integration_source) !== 1,
    'runner does not depend on production includes/db.php'
);
$assert(strpos($integration_source, "' 09:00:00'") === false && strpos($integration_source, "' 22:00:00'") === false, 'fixture timestamps are derived from active shift definitions');
