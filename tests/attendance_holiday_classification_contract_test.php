<?php
$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/includes/attendance_workflow.php');
$time = file_get_contents($root . '/includes/attendance_time.php');
$attendance = file_get_contents($root . '/attendance.php');
$report = file_get_contents($root . '/attendance_report.php');
require_once $root . '/includes/attendance_time.php';
$failures = 0;
$assert = static function ($condition, $message) use (&$failures) {
    if ($condition) {
        echo "PASS: $message\n";
    } else {
        ++$failures;
        fwrite(STDERR, "FAIL: $message\n");
    }
};

$assert(
    strpos($workflow, 'function attendance_status_for_checkin(') !== false
        && strpos($workflow, 'SELECT 1 FROM holidays WHERE holiday_date = ? LIMIT 1') !== false,
    'check-in classifies holiday dates through a prepared date lookup'
);
$holidayPosition = strpos($workflow, "return 'Holiday Working';");
$sundayPosition = strpos($workflow, "return 'Sunday Working';");
$assert($holidayPosition !== false && $sundayPosition !== false && $holidayPosition < $sundayPosition, 'holiday classification takes precedence over Sunday classification');
$assert(
    strpos($workflow, '$status = attendance_status_for_checkin($conn, $business_date, $now_sql') !== false
        && strpos($workflow, 'if (!is_string($status))') !== false,
    'attendance check-in uses the classifier and fails closed when holiday lookup fails'
);
$assert(
    strpos($time, '$current_status === \'Sunday Working\' || $current_status === \'Holiday Working\'') !== false
        && strpos($time, 'return $current_status;') !== false,
    'checkout preserves the Holiday Working classification'
);
$assert(
    strpos($report, "'Holiday Working'") !== false
        && strpos($report, 'Holiday Working</h6>') !== false,
    'Admin attendance report accepts and summarizes Holiday Working records'
);
$assert(
    strpos($report, 'if ($filter_status === \'Holiday Working\')') !== false
        && strpos($report, "<span class='badge bg-info text-dark'>Holiday Working</span>") !== false,
    'Admin attendance report can filter and visibly label Holiday Working records'
);
$assert(
    attendance_status_after_checkout('Holiday Working', 3 * 3600) === 'Holiday Working',
    'Holiday Working status is preserved after a short checkout'
);
$assert(
    attendance_status_after_checkout('Sunday Working', 3 * 3600) === 'Sunday Working',
    'Sunday Working status is preserved after a short checkout'
);
$assert(
    attendance_status_after_checkout('Present', 3 * 3600) === 'Absent',
    'ordinary short attendance still follows the existing checkout rule'
);

if ($failures > 0) {
    exit(1);
}
echo "INFO: Database-backed Holiday check-in integration was not executed against the protected database.\n";
echo "Attendance holiday classification contracts completed.\n";
