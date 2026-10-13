<?php

require_once __DIR__ . '/attendance_overnight_preservation_helpers.php';

$assert = static function ($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};

$assert(overnightNormalizePositiveId(56) === 56, 'integer 56 normalizes safely');
$assert(overnightNormalizePositiveId('56') === 56, 'string ID 56 normalizes safely');

foreach ([null, '', '0', 0, -1, '-56', '56.0', 56.0, '56e0', ' 56', '056', '9223372036854775808', true, []] as $invalid) {
    $assert(overnightNormalizePositiveId($invalid) === null, 'invalid ID representation is rejected');
}

$baselineRow = ['id' => '55', 'employee_id' => 'EMP9802', 'attendance_date' => '2026-10-04'];
$testRow = ['id' => 56, 'employee_id' => 'EMP9806', 'attendance_date' => '2026-10-11'];
$sameBusinessKeyDifferentId = ['id' => '57', 'employee_id' => 'EMP9806', 'attendance_date' => '2026-10-11'];
$filtered = overnightRowsWithoutInsertedTestAttendance(
    [$baselineRow, $testRow, $sameBusinessKeyDifferentId],
    56
);
$assert($filtered === [$baselineRow, $sameBusinessKeyDifferentId], 'only the inserted ID 56 test row is excluded');
$assert(overnightRowsWithoutInsertedTestAttendance([$testRow], null) === [$testRow], 'no row is excluded before a valid inserted ID is known');

echo "Database-free overnight preservation helper tests completed.\n";
