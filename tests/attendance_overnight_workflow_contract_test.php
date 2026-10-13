<?php

$root = dirname(__DIR__);
$assert = static function ($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};
$runner = file_get_contents($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'attendance_overnight_workflow_integration.php');
$controller = file_get_contents($root . DIRECTORY_SEPARATOR . 'attendance.php');
$workflow = file_get_contents($root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'attendance_workflow.php');
$preservationHelpers = file_get_contents($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'attendance_overnight_preservation_helpers.php');
$assert($runner !== false && $controller !== false && $workflow !== false && $preservationHelpers !== false, 'runner, preservation helpers, and production workflow sources are readable');

foreach ([
    "if (PHP_SAPI !== 'cli')",
    "const OVERNIGHT_TEST_DATABASE = 'coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c'",
    "const OVERNIGHT_TEST_HOST = '127.0.0.1'",
    'const OVERNIGHT_TEST_PORT = 3307',
    "const OVERNIGHT_TEST_EMPLOYEE = 'EMP9806'",
    'const OVERNIGHT_TEST_SHIFT_ID = 13',
    "const OVERNIGHT_TEST_CHECKIN = '2026-10-11 21:50:00'",
    "const OVERNIGHT_TEST_CHECKOUT = '2026-10-12 06:05:00'",
    'attendance_workflow_checkin($connection, OVERNIGHT_TEST_EMPLOYEE, $checkin_at)',
    'attendance_workflow_checkout($connection, OVERNIGHT_TEST_EMPLOYEE, $checkout_at)',
    'COREAXIS_OVERNIGHT_BACKUP_VERIFIED',
    'COREAXIS_OVERNIGHT_CLONE_RESTORED',
    'COREAXIS_OVERNIGHT_BACKUP_PATH',
    'COREAXIS_OVERNIGHT_BACKUP_SHA256',
    'COREAXIS_OVERNIGHT_ALLOW_WRITES',
    "hash_file('sha256', \$backup_path)",
    'attendance_has_checkin_policy_schema($connection)',
    'row_version',
    'Protected attendance IDs 53–55',
    'leave_attendance_audit',
    'attendance_correction_audit',
    'overnightRowsWithoutInsertedTestAttendance',
    'Protected attendance IDs 53–55',
    'Newly inserted test row',
    'PRESERVATION_RESULT=',
    "'protected_attendance_53_55' => \$preservation_checks['Protected attendance IDs 53–55']",
    "'new_test_row' => \$preservation_checks['Newly inserted test row']"
] as $required) {
    $assert(strpos($runner, $required) !== false, 'runner safety/integration contract present: ' . $required);
}
$assert(preg_match('/\b(?:require|include)(?:_once)?\s*\(?[^;\r\n]*includes\/db\.php/i', $runner) !== 1, 'runner never loads application database configuration');
$assert(strpos($runner, 'coreaxis_crm_runtime_test') === false, 'runner cannot target the shared active runtime clone');
$assert(strpos($runner, 'coreaxis_crm_overnight_workflow_test') === false, 'runner cannot target the preserved failed-test evidence database');
$assert(strpos($runner, 'coreaxis_crm\'') === false, 'runner has no production database target');
$assert(strpos($runner, 'DELETE FROM') === false, 'runner never deletes fixtures or historical rows');
$assert(strpos($runner, 'new DateTimeImmutable(OVERNIGHT_TEST_CHECKIN, new DateTimeZone(\'Asia/Kolkata\'))') !== false
    && strpos($runner, 'new DateTimeImmutable(OVERNIGHT_TEST_CHECKOUT, new DateTimeZone(\'Asia/Kolkata\'))') !== false,
    'runner injects explicit Asia/Kolkata immutable timestamps');
$assert(strpos($workflow, 'DateTimeImmutable $now') !== false, 'same production workflow accepts immutable injected time');
$assert(strpos($controller, "new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'))") !== false,
    'production controller supplies actual local server time');
$assert(strpos($controller, '$_GET[\'time\']') === false && strpos($controller, '$_POST[\'time\']') === false
    && strpos($controller, '$_COOKIE[\'time\']') === false,
    'HTTP controller has no request time override');

echo "Static overnight workflow integration contract checks completed; no DB or HTTP operations performed.\n";
