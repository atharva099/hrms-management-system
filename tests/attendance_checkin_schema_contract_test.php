<?php

require_once __DIR__ . '/../includes/attendance_time.php';

$assert = static function ($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};

$columns = [
    'shifts.early_checkin_minutes' => ['COLUMN_TYPE' => 'smallint(5) unsigned', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => '15', 'COLUMN_DEFAULT_IS_NULL' => '0'],
    'shifts.late_checkin_cutoff_minutes' => ['COLUMN_TYPE' => 'smallint(5) unsigned', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => '120', 'COLUMN_DEFAULT_IS_NULL' => '0'],
    'attendance.shift_id_snapshot' => ['COLUMN_TYPE' => 'int(11)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'COLUMN_DEFAULT_IS_NULL' => '1'],
    'attendance.shift_name_snapshot' => ['COLUMN_TYPE' => 'varchar(100)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'COLUMN_DEFAULT_IS_NULL' => '1'],
    'attendance.scheduled_start_at' => ['COLUMN_TYPE' => 'datetime', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'COLUMN_DEFAULT_IS_NULL' => '1'],
    'attendance.late_after_at' => ['COLUMN_TYPE' => 'datetime', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'COLUMN_DEFAULT_IS_NULL' => '1'],
    'attendance.early_checkin_minutes_snapshot' => ['COLUMN_TYPE' => 'smallint(5) unsigned', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'COLUMN_DEFAULT_IS_NULL' => '1'],
    'attendance.late_checkin_cutoff_minutes_snapshot' => ['COLUMN_TYPE' => 'smallint(5) unsigned', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'COLUMN_DEFAULT_IS_NULL' => '1'],
];

$assert(attendance_checkin_policy_columns_match($columns), 'valid migrated schema with string defaults passes');
$mariadb_104_null_defaults = $columns;
foreach ([
    'attendance.shift_id_snapshot',
    'attendance.shift_name_snapshot',
    'attendance.scheduled_start_at',
    'attendance.late_after_at',
    'attendance.early_checkin_minutes_snapshot',
    'attendance.late_checkin_cutoff_minutes_snapshot',
] as $nullable_column) {
    // Exact safe representation observed through mysqli on the MariaDB 10.4 clone.
    $mariadb_104_null_defaults[$nullable_column]['COLUMN_DEFAULT'] = 'NULL';
    $mariadb_104_null_defaults[$nullable_column]['COLUMN_DEFAULT_IS_NULL'] = '0';
}
$assert(
    attendance_checkin_policy_columns_match($mariadb_104_null_defaults),
    'MariaDB 10.4 unquoted NULL strings with false IS NULL flags pass for nullable snapshots'
);
$literal_null_default = $mariadb_104_null_defaults;
$literal_null_default['attendance.shift_name_snapshot']['COLUMN_DEFAULT'] = "'NULL'";
$assert(!attendance_checkin_policy_columns_match($literal_null_default), 'quoted literal NULL default on VARCHAR snapshot is rejected');
foreach (['', '0', 'arbitrary'] as $invalid_varchar_default) {
    $invalid_snapshot_default = $mariadb_104_null_defaults;
    $invalid_snapshot_default['attendance.shift_name_snapshot']['COLUMN_DEFAULT'] = $invalid_varchar_default;
    $assert(
        !attendance_checkin_policy_columns_match($invalid_snapshot_default),
        'invalid VARCHAR snapshot default is rejected: ' . var_export($invalid_varchar_default, true)
    );
}
$integer_defaults = $columns;
$integer_defaults['shifts.early_checkin_minutes']['COLUMN_DEFAULT'] = 15;
$integer_defaults['shifts.late_checkin_cutoff_minutes']['COLUMN_DEFAULT'] = 120;
$assert(attendance_checkin_policy_columns_match($integer_defaults), 'valid migrated schema with integer defaults passes');
$assert(attendance_checkin_policy_default_matches(null, null, '0'), 'PHP null representation is accepted for a nullable SQL NULL default');
$assert(attendance_checkin_policy_default_matches('NULL', null, '0'), 'observed MariaDB 10.4 unquoted NULL representation is accepted despite false IS NULL flag');
$assert(!attendance_checkin_policy_default_matches("'NULL'", null, '0'), 'quoted literal string NULL default is rejected');
foreach ([0, '0', '', 'garbage', 'NULL ', ' null'] as $invalid_nullable_default) {
    $assert(
        !attendance_checkin_policy_default_matches($invalid_nullable_default, null, '0'),
        'non-NULL nullable-column default is rejected: ' . var_export($invalid_nullable_default, true)
    );
}

$missing = $columns;
unset($missing['attendance.scheduled_start_at']);
$assert(!attendance_checkin_policy_columns_match($missing), 'missing required column fails closed');
$wrong_type = $columns;
$wrong_type['shifts.early_checkin_minutes']['COLUMN_TYPE'] = 'int(11)';
$assert(!attendance_checkin_policy_columns_match($wrong_type), 'invalid column type fails closed');
$wrong_nullability = $columns;
$wrong_nullability['attendance.shift_id_snapshot']['IS_NULLABLE'] = 'NO';
$wrong_nullability['attendance.shift_id_snapshot']['COLUMN_DEFAULT'] = 'NULL';
$wrong_nullability['attendance.shift_id_snapshot']['COLUMN_DEFAULT_IS_NULL'] = '0';
$assert(!attendance_checkin_policy_columns_match($wrong_nullability), 'invalid nullability fails closed');
$wrong_default = $columns;
$wrong_default['shifts.late_checkin_cutoff_minutes']['COLUMN_DEFAULT'] = '121';
$assert(!attendance_checkin_policy_columns_match($wrong_default), 'invalid policy default fails closed');
$wrong_default['shifts.late_checkin_cutoff_minutes']['COLUMN_DEFAULT_IS_NULL'] = '1';
$assert(!attendance_checkin_policy_columns_match($wrong_default), 'SQL NULL marker cannot validate a non-NULL policy default');
$wrong_default_format = $columns;
$wrong_default_format['shifts.early_checkin_minutes']['COLUMN_DEFAULT'] = '15.0';
$assert(!attendance_checkin_policy_columns_match($wrong_default_format), 'noncanonical numeric default fails closed');
$assert(!attendance_checkin_policy_columns_match([], false), 'metadata query failure fails closed');
$missing_null_marker = $columns;
unset($missing_null_marker['attendance.shift_id_snapshot']['COLUMN_DEFAULT_IS_NULL']);
$assert(!attendance_checkin_policy_columns_match($missing_null_marker), 'missing SQL NULL metadata field fails closed');

$helper_source = file_get_contents(__DIR__ . '/../includes/attendance_time.php');
$assert(
    strpos($helper_source, 'FROM information_schema.COLUMNS') !== false
        && strpos($helper_source, 'TABLE_SCHEMA = DATABASE()') !== false
        && strpos($helper_source, 'COLUMN_DEFAULT IS NULL AS COLUMN_DEFAULT_IS_NULL') !== false
        && strpos($helper_source, 'mysqli_stmt_get_result($statement)') !== false,
    'schema gate retains database-scoped prepared metadata query and result handling'
);
foreach (['if (!$statement || !mysqli_stmt_execute($statement))', 'if (!$result)'] as $failure_guard) {
    $assert(strpos($helper_source, $failure_guard) !== false, 'metadata-query failure guard remains: ' . $failure_guard);
}

echo "Database-free check-in schema gate tests completed.\n";
