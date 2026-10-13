<?php

require_once __DIR__ . '/attendance_overnight_preservation_helpers.php';
require_once __DIR__ . '/attendance_forensic_compare_lib.php';

$assert = static function ($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};

$baseline = [
    ['id' => '53', 'status' => 'Present', 'check_out_at' => null],
    ['id' => '54', 'status' => 'Late', 'check_out_at' => '2026-10-04 06:00:00']
];
$same = [
    ['id' => 53, 'status' => 'Present', 'check_out_at' => null],
    ['id' => 54, 'status' => 'Late', 'check_out_at' => '2026-10-04 06:00:00']
];
$comparison = forensicCompareRows($baseline, $same, ['id']);
$assert($comparison === ['added' => [], 'missing' => [], 'changed' => []], 'same complete rows compare equal across int/string primary-key representations');

$current = [
    ['id' => '53', 'status' => 'Present', 'check_out_at' => null],
    ['id' => '54', 'status' => 'Late', 'check_out_at' => '2026-10-04 06:00:00'],
    ['id' => 56, 'status' => 'Sunday Working', 'check_out_at' => '2026-10-12 06:05:00']
];
$comparison = forensicCompareRows($baseline, $current, ['id']);
$assert($comparison['added'] === ['56'] && $comparison['missing'] === [] && $comparison['changed'] === [], 'added primary key is reported without row values');

$changed = [
    ['id' => '53', 'status' => 'Present', 'check_out_at' => null],
    ['id' => '54', 'status' => 'Absent', 'check_out_at' => '2026-10-04 06:00:00']
];
$comparison = forensicCompareRows($baseline, $changed, ['id']);
$assert($comparison['changed'] === ['54' => ['status']], 'changed column names are reported without field values');
$assert($comparison['missing'] === [], 'no missing keys reported for changed-row example');

$comparison = forensicCompareRows([$baseline[0]], [], ['id']);
$assert($comparison['missing'] === ['53'], 'missing primary key is reported');

$assert(forensicIntegerEquals(2, 2), 'expected integer accepts native integer representation');
$assert(forensicIntegerEquals('2', 2), 'expected integer accepts canonical mysqli string representation');
$assert(!forensicIntegerEquals('02', 2), 'expected integer rejects noncanonical numeric strings');
$assert(!forensicIntegerEquals('2.0', 2), 'expected integer rejects fractional representation');
$assert(!forensicIntegerEquals(-2, 2), 'expected integer rejects incorrect negative values');
$assert(!forensicIntegerEquals('2x', 2), 'expected integer rejects malformed values');

$expectedOvernightRow = [
    'id' => '56', 'employee_id' => 'EMP9806', 'attendance_date' => '2026-10-11',
    'check_in_at' => '2026-10-11 21:50:00', 'check_out_at' => '2026-10-12 06:05:00',
    'scheduled_start_at' => '2026-10-11 22:00:00', 'scheduled_end_at' => '2026-10-12 06:00:00',
    'working_hours' => '8 hrs 15 mins', 'status' => 'Sunday Working', 'row_version' => 2,
    'shift_id_snapshot' => '13', 'shift_name_snapshot' => 'Night Shift 2',
    'early_checkin_minutes_snapshot' => 15, 'late_checkin_cutoff_minutes_snapshot' => '120'
];
$assert(forensicOvernightRowMismatches($expectedOvernightRow, 1) === [], 'complete overnight row passes with mixed native/string integer representations');
$wrongCheckout = $expectedOvernightRow;
$wrongCheckout['check_out_at'] = '2026-10-12 06:04:59';
$assert(forensicOvernightRowMismatches($wrongCheckout, 1) === ['check_out_at'], 'overnight row diagnostics identify only the incorrect timestamp column');
$wrongSnapshot = $expectedOvernightRow;
$wrongSnapshot['early_checkin_minutes_snapshot'] = '15x';
$assert(forensicOvernightRowMismatches($wrongSnapshot, 1) === ['early_checkin_minutes_snapshot'], 'overnight row diagnostics reject malformed policy snapshot integers');

$baselineTableSchema = [
    'columns' => [['COLUMN_NAME' => 'employee_id', 'COLUMN_TYPE' => 'varchar(20)', 'IS_NULLABLE' => 'NO']],
    'indexes' => [['INDEX_NAME' => 'uq_employee', 'NON_UNIQUE' => '0', 'COLUMN_NAME' => 'employee_id']],
    'foreign_keys' => [[
        'CONSTRAINT_NAME' => 'fk_employee',
        'COLUMN_NAME' => 'employee_id',
        'ORDINAL_POSITION' => '1',
        'REFERENCED_TABLE_SCHEMA' => 'forensic_baseline',
        'REFERENCED_TABLE_NAME' => 'users',
        'REFERENCED_COLUMN_NAME' => 'employee_id',
        'UPDATE_RULE' => 'RESTRICT',
        'DELETE_RULE' => 'RESTRICT'
    ]]
];
$currentTableSchema = $baselineTableSchema;
$currentTableSchema['foreign_keys'][0]['REFERENCED_TABLE_SCHEMA'] = 'overnight_test';
$normalizedBaseline = forensicNormalizeLocalForeignKeySchema($baselineTableSchema, 'forensic_baseline');
$normalizedCurrent = forensicNormalizeLocalForeignKeySchema($currentTableSchema, 'overnight_test');
$assert($normalizedBaseline === $normalizedCurrent, 'equivalent local self-references compare equal across restored database names');

$differentTargetTable = $currentTableSchema;
$differentTargetTable['foreign_keys'][0]['REFERENCED_TABLE_NAME'] = 'departments';
$assert(
    forensicNormalizeLocalForeignKeySchema($baselineTableSchema, 'forensic_baseline')
        !== forensicNormalizeLocalForeignKeySchema($differentTargetTable, 'overnight_test'),
    'different referenced tables remain distinguishable'
);

$differentTargetColumn = $currentTableSchema;
$differentTargetColumn['foreign_keys'][0]['REFERENCED_COLUMN_NAME'] = 'id';
$assert(
    forensicNormalizeLocalForeignKeySchema($baselineTableSchema, 'forensic_baseline')
        !== forensicNormalizeLocalForeignKeySchema($differentTargetColumn, 'overnight_test'),
    'different referenced columns remain distinguishable'
);

$differentAction = $currentTableSchema;
$differentAction['foreign_keys'][0]['DELETE_RULE'] = 'CASCADE';
$assert(
    forensicNormalizeLocalForeignKeySchema($baselineTableSchema, 'forensic_baseline')
        !== forensicNormalizeLocalForeignKeySchema($differentAction, 'overnight_test'),
    'different referential actions remain distinguishable'
);

$baselineExternalReference = $baselineTableSchema;
$baselineExternalReference['foreign_keys'][0]['REFERENCED_TABLE_SCHEMA'] = 'shared_identity';
$currentExternalReference = $currentTableSchema;
$currentExternalReference['foreign_keys'][0]['REFERENCED_TABLE_SCHEMA'] = 'other_shared_identity';
$assert(
    forensicNormalizeLocalForeignKeySchema($baselineExternalReference, 'forensic_baseline')
        !== forensicNormalizeLocalForeignKeySchema($currentExternalReference, 'overnight_test'),
    'external cross-database references remain distinguishable'
);

$differentColumnDefinition = $currentTableSchema;
$differentColumnDefinition['columns'][0]['COLUMN_TYPE'] = 'varchar(30)';
$assert(
    forensicNormalizeLocalForeignKeySchema($baselineTableSchema, 'forensic_baseline')
        !== forensicNormalizeLocalForeignKeySchema($differentColumnDefinition, 'overnight_test'),
    'column and index metadata remains fully compared'
);

$comparatorSource = file_get_contents(__DIR__ . '/attendance_overnight_forensic_comparator.php');
$compareLibrarySource = file_get_contents(__DIR__ . '/attendance_forensic_compare_lib.php');
$assert($comparatorSource !== false, 'read-only comparator source is readable');
$assert($compareLibrarySource !== false, 'forensic comparison library is readable');
foreach ([
    "const FORENSIC_HOST = '127.0.0.1'",
    'const FORENSIC_PORT = 3307',
    "const FORENSIC_BASELINE = 'coreaxis_r5_overnight_forensic_baseline_20261011'",
    "const FORENSIC_CURRENT = 'coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c'",
    "'attendance', 'users', 'leaves', 'salaries'",
    'MYSQLI_TRANS_START_READ_ONLY',
    'forensicOvernightRowMismatches($testRow, count($testRowsForEmployeeDates))',
    "attendance ID 56 expected-field mismatches=",
    'MYSQLI_TRANS_START_READ_ONLY'
] as $required) {
    $assert(strpos($comparatorSource, $required) !== false, 'comparator safety contract present: ' . $required);
}
$assert(strpos($compareLibrarySource, "'employee_id' => (\$row['employee_id'] ?? null) === 'EMP9806'") !== false, 'complete overnight row identity remains exact');
$assert(strpos($compareLibrarySource, "'check_out_at' => (\$row['check_out_at'] ?? null) === '2026-10-12 06:05:00'") !== false, 'next-day checkout remains exact');
$assert(strpos($compareLibrarySource, "'working_hours' => (\$row['working_hours'] ?? null) === '8 hrs 15 mins'") !== false, 'working duration remains exact');
$assert(preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+`|DELETE\s+FROM|REPLACE\s+INTO|CREATE\s+TABLE|DROP\s+TABLE)\b/i', $comparatorSource) !== 1,
    'comparator contains no row or schema mutation statements');

echo "Database-free forensic comparator tests completed.\n";
