<?php
/**
 * Clone-only database integration contract for attendance row_version.
 * This script is intentionally not run by this task. It creates uniquely
 * identified fixture users/attendance. Fixtures intentionally remain until
 * the disposable clone is recreated from its verified pre-test backup.
 * These direct SQL contract checks do not prove complete authenticated HTTP
 * workflows; endpoint-level regression requires a separate authenticated clone.
 * This runner does not load or modify the workspace includes/db.php.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/attendance_row_version_test_config.php';

const TEST_HOST = '127.0.0.1';
const TEST_PORT = 3307;
const FIXTURE_BUSINESS_DATE = '2098-10-03';

// A write-capable run must name its own disposable R5 attendance database.
// The shared runtime clone and all known evidence databases are forbidden.
$test_database = getenv('COREAXIS_TEST_DISPOSABLE_DATABASE');
if (!attendance_row_version_disposable_database_is_allowed($test_database)) {
    fwrite(STDERR, "BLOCKED: set COREAXIS_TEST_DISPOSABLE_DATABASE to a dedicated disposable R5 attendance database name.\n");
    exit(2);
}

require_once __DIR__ . '/../includes/attendance_time.php';

$required_gates = [
    'COREAXIS_TEST_TARGET_CONFIRMED' => $test_database,
    'COREAXIS_TEST_BACKUP_VERIFIED' => 'YES',
    'COREAXIS_TEST_ALLOW_WRITES' => 'YES'
];
foreach ($required_gates as $name => $expected) {
    if (getenv($name) !== $expected) {
        fwrite(STDERR, "BLOCKED: required operator gate {$name} is not satisfied.\n");
        exit(2);
    }
}

$db_user = getenv('COREAXIS_TEST_DB_USER');
$db_password = getenv('COREAXIS_TEST_DB_PASSWORD');
if (!is_string($db_user) || $db_user === '') {
    fwrite(STDERR, "BLOCKED: clone-only database credentials are not supplied in the process environment.\n");
    exit(2);
}
if ($db_password === false) {
    // Passwordless credentials are limited to the loopback disposable target
    // after the exact target, backup, and write-authorization gates pass.
    if (TEST_HOST !== '127.0.0.1' || TEST_PORT !== 3307
        || !attendance_row_version_disposable_database_is_allowed($test_database)) {
        fwrite(STDERR, "BLOCKED: an unset password is allowed only for an approved loopback disposable target.\n");
        exit(2);
    }
    $db_password = '';
} elseif (!is_string($db_password)) {
    fwrite(STDERR, "BLOCKED: clone-only database credentials are not supplied in the process environment.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = null;
$second_connection = null;
$primary_transaction_open = false;
$secondary_transaction_open = false;
$fixture_users = [];
$fixture_attendance_ids = [];
$baseline = [];
$users_baseline = null;
$attendance_baseline_rows = [];
$users_baseline_rows = [];
$overall_failure = false;

function testAssert($condition, $label)
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $label);
    }
    echo "PASS: {$label}\n";
}

function queryRows($connection, $sql)
{
    $result = mysqli_query($connection, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}

function scalarValue($connection, $sql)
{
    $result = mysqli_query($connection, $sql);
    $row = mysqli_fetch_row($result);
    mysqli_free_result($result);
    return $row ? $row[0] : null;
}

function tableFingerprint($connection, $table)
{
    $allowed = ['attendance', 'leaves', 'salaries', 'leave_attendance_audit', 'attendance_correction_audit'];
    if (!in_array($table, $allowed, true)) {
        throw new RuntimeException('Unexpected fingerprint table.');
    }
    $rows = queryRows($connection, 'SELECT * FROM `' . $table . '` ORDER BY `id`');
    return [count($rows), hash('sha256', serialize($rows))];
}

function tableRowsWithoutIds($connection, $table, $excluded_ids)
{
    $allowed = ['attendance', 'users'];
    if (!in_array($table, $allowed, true)) {
        throw new RuntimeException('Unexpected fixture comparison table.');
    }
    $rows = queryRows($connection, 'SELECT * FROM `' . $table . '` ORDER BY `id`');
    $excluded = array_fill_keys(array_map('intval', $excluded_ids), true);
    return array_values(array_filter($rows, static function ($row) use ($excluded) {
        return !isset($excluded[(int) $row['id']]);
    }));
}

function bindDynamic($statement, $types, &$values)
{
    $arguments = [$statement, $types];
    foreach ($values as &$value) {
        $arguments[] = &$value;
    }
    return call_user_func_array('mysqli_stmt_bind_param', $arguments);
}

function fixtureShiftTimestamps($business_date, $shift)
{
    $schedule = attendance_shift_schedule($business_date, $shift['in_time'], $shift['out_time']);
    if (!$schedule) {
        throw new RuntimeException('Selected active shift has an unsupported time definition.');
    }
    $start = $business_date . ' ' . $shift['in_time'];
    $end = $schedule['end']->format('Y-m-d H:i:s');
    $seconds = attendance_elapsed_seconds($start, $end);
    if (!is_int($seconds) || $seconds <= 0) {
        throw new RuntimeException('Selected shift produced an invalid fixture duration.');
    }
    return ['start' => $start, 'end' => $end, 'seconds' => $seconds];
}

function durationLabel($seconds)
{
    return floor($seconds / 3600) . ' hrs ' . floor(($seconds % 3600) / 60) . ' mins';
}

function beginTrackedTransaction($connection, &$transaction_open)
{
    // Mark before calling mysqli so finalization attempts rollback even if the
    // client reports an ambiguous begin failure.
    $transaction_open = true;
    mysqli_begin_transaction($connection);
}

function commitTrackedTransaction($connection, &$transaction_open)
{
    mysqli_commit($connection);
    $transaction_open = false;
}

function rollbackTrackedTransaction($connection, &$transaction_open)
{
    if (!$transaction_open) {
        return;
    }
    mysqli_rollback($connection);
    $transaction_open = false;
}

function rollbackOutstandingOrFail($connection, &$transaction_open, $connection_label)
{
    if (!$transaction_open) {
        return true;
    }
    try {
        rollbackTrackedTransaction($connection, $transaction_open);
        return true;
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL: rollback failed for {$connection_label} connection; clone state is uncertain; restore the verified clone backup.\n");
        return false;
    }
}

function insertFixtureUser($connection, $employee_id, $shift, $department_name)
{
    global $fixture_users;
    $columns = queryRows(
        $connection,
        "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
         ORDER BY ORDINAL_POSITION"
    );
    $values = [
        'employee_id' => $employee_id,
        'full_name' => 'R5 Attendance Fixture',
        'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        'department' => $department_name,
        'designation' => 'Test Fixture',
        'shift_time' => $shift['in_time'],
        'salary' => '0.00',
        'incentive' => '0.00',
        'joining_date' => date('Y-m-d'),
        'role' => 'Employee',
        'status' => 'Active',
        'phone' => null,
        'email' => strtolower($employee_id) . '@fixture.invalid',
        'shift_id' => (string) $shift['id']
    ];
    $usable = [];
    foreach ($columns as $column) {
        $name = $column['COLUMN_NAME'];
        if ($name === 'id' && strpos($column['EXTRA'], 'auto_increment') !== false) {
            continue;
        }
        if (array_key_exists($name, $values)) {
            $usable[$name] = $values[$name];
            continue;
        }
        if ($column['IS_NULLABLE'] === 'NO' && $column['COLUMN_DEFAULT'] === null) {
            throw new RuntimeException('Clone users schema has an unsupported required column; no fixture was inserted.');
        }
    }
    if (!isset($usable['employee_id'], $usable['shift_id'], $usable['role'], $usable['status'])) {
        throw new RuntimeException('Clone users schema is incompatible; no fixture was inserted.');
    }

    $names = array_keys($usable);
    $quoted = array_map(static function ($name) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) {
            throw new RuntimeException('Unexpected users column identifier.');
        }
        return '`' . $name . '`';
    }, $names);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $statement = mysqli_prepare(
        $connection,
        'INSERT INTO `users` (' . implode(',', $quoted) . ') VALUES (' . $placeholders . ')'
    );
    $params = array_values($usable);
    $types = str_repeat('s', count($params));
    bindDynamic($statement, $types, $params);
    try {
        mysqli_stmt_execute($statement);
    } catch (Throwable $error) {
        mysqli_stmt_close($statement);
        throw $error;
    }
    $id = (int) mysqli_insert_id($connection);
    // Track immediately after successful INSERT so later setup failures retain
    // an exact fixture inventory for reporting and clone restoration.
    $fixture_users[$employee_id] = $id;
    mysqli_stmt_close($statement);
    return $id;
}

function insertAttendanceFixture($connection, $employee_id, $date, $status, $check_in_at = null, $check_out_at = null, $scheduled_end_at = null, $working_hours = null)
{
    global $fixture_attendance_ids;
    $check_in = $check_in_at === null ? null : substr($check_in_at, 11, 8);
    $check_out = $check_out_at === null ? null : substr($check_out_at, 11, 8);
    $statement = mysqli_prepare(
        $connection,
        'INSERT INTO attendance
         (employee_id, attendance_date, check_in, check_out, check_in_at, check_out_at,
          scheduled_end_at, status, working_hours)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    mysqli_stmt_bind_param(
        $statement,
        'sssssssss',
        $employee_id,
        $date,
        $check_in,
        $check_out,
        $check_in_at,
        $check_out_at,
        $scheduled_end_at,
        $status,
        $working_hours
    );
    try {
        mysqli_stmt_execute($statement);
    } catch (Throwable $error) {
        mysqli_stmt_close($statement);
        throw $error;
    }
    $id = (int) mysqli_insert_id($connection);
    // Track immediately after successful INSERT; never clean up by broad query.
    $fixture_attendance_ids[] = $id;
    mysqli_stmt_close($statement);
    return $id;
}

function versionedCheckout($connection, $id, $expected_version, $checkout_at, $working_hours, $status)
{
    $legacy_time = substr($checkout_at, 11, 8);
    $statement = mysqli_prepare(
        $connection,
        'UPDATE attendance
         SET check_out = ?, check_out_at = ?, working_hours = ?, status = ?, row_version = row_version + 1
         WHERE id = ? AND row_version = ? AND check_out_at IS NULL'
    );
    mysqli_stmt_bind_param($statement, 'ssssii', $legacy_time, $checkout_at, $working_hours, $status, $id, $expected_version);
    mysqli_stmt_execute($statement);
    $affected = mysqli_stmt_affected_rows($statement);
    mysqli_stmt_close($statement);
    return $affected;
}

function readAttendance($connection, $id)
{
    $statement = mysqli_prepare($connection, 'SELECT * FROM attendance WHERE id = ?');
    mysqli_stmt_bind_param($statement, 'i', $id);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($statement);
    return $row;
}

try {
    $connection = new mysqli(TEST_HOST, $db_user, $db_password, $test_database, TEST_PORT);
    $connection->set_charset('utf8mb4');
    $server = queryRows($connection, 'SELECT DATABASE() AS db_name, @@port AS db_port, VERSION() AS version, @@version_comment AS version_comment');
    $identity = $server[0] ?? [];
    if (($identity['db_name'] ?? null) !== $test_database
        || (int) ($identity['db_port'] ?? 0) !== TEST_PORT
        || strpos((string) ($identity['version'] ?? ''), '10.4.') !== 0
        || stripos((string) ($identity['version_comment'] ?? ''), 'mariadb') === false) {
        throw new RuntimeException('Target identity check failed; no fixtures created.');
    }
    testAssert(true, 'connection identity is clone-only MariaDB 10.4 on port 3307');

    $required_tables = ['users', 'departments', 'shifts', 'attendance', 'leaves', 'salaries', 'leave_attendance_audit', 'attendance_correction_audit'];
    foreach ($required_tables as $table) {
        $escaped = mysqli_real_escape_string($connection, $table);
        if ((int) scalarValue($connection, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$escaped}'") !== 1) {
            throw new RuntimeException('A required clone table is missing; no fixtures created.');
        }
    }
    $version_column = queryRows(
        $connection,
        "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'row_version'"
    );
    if (count($version_column) !== 1
        || strtolower($version_column[0]['COLUMN_TYPE']) !== 'int(10) unsigned'
        || $version_column[0]['IS_NULLABLE'] !== 'NO'
        || (string) $version_column[0]['COLUMN_DEFAULT'] !== '1') {
        throw new RuntimeException('Expected attendance.row_version definition is absent; no fixtures created.');
    }
    $unique = queryRows(
        $connection,
        "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
           AND INDEX_NAME = 'uq_attendance_employee_date' AND NON_UNIQUE = 0
         GROUP BY INDEX_NAME"
    );
    if (($unique[0]['columns_in_index'] ?? null) !== 'employee_id,attendance_date') {
        throw new RuntimeException('Expected attendance employee/date unique index is absent; no fixtures created.');
    }

    $baseline = [];
    foreach (['attendance', 'leaves', 'salaries', 'leave_attendance_audit', 'attendance_correction_audit'] as $table) {
        $baseline[$table] = tableFingerprint($connection, $table);
    }
    $users_baseline = (int) scalarValue($connection, 'SELECT COUNT(*) FROM users');
    $attendance_baseline_rows = queryRows($connection, 'SELECT * FROM attendance ORDER BY id');
    $users_baseline_rows = queryRows($connection, 'SELECT * FROM users ORDER BY id');
    if ((int) scalarValue($connection, "SELECT COUNT(*) FROM users WHERE full_name = 'R5 Attendance Fixture' AND email LIKE '%@fixture.invalid'") !== 0) {
        throw new RuntimeException('Prior runner fixtures exist; restore the disposable clone before starting a new run.');
    }

    $shifts = queryRows(
        $connection,
        "SELECT id, shift_name, in_time, out_time, late_after
         FROM shifts WHERE status = 'Active' ORDER BY id"
    );
    $normal_shift = null;
    $overnight_shift = null;
    foreach ($shifts as $shift) {
        if ($shift['out_time'] > $shift['in_time'] && $normal_shift === null) {
            $normal_shift = $shift;
        }
        if ($shift['out_time'] <= $shift['in_time'] && $overnight_shift === null) {
            $overnight_shift = $shift;
        }
    }
    $department_name = scalarValue($connection, 'SELECT department_name FROM departments ORDER BY id LIMIT 1');
    if (!$normal_shift || !$overnight_shift || !is_string($department_name) || trim($department_name) === '') {
        throw new RuntimeException('Clone needs an active normal and overnight shift; no fixtures created.');
    }

    // Each run has fresh employee IDs. Employee IDs remain within the app's EMP + digits format.
    do {
        $employee_ids = [];
        foreach (['normal', 'overnight', 'auto', 'conflict', 'concurrency'] as $fixture_type) {
            do {
                $candidate_id = 'EMP' . (string) random_int(100000000000000, 999999999999999);
            } while (in_array($candidate_id, $employee_ids, true));
            $employee_ids[$fixture_type] = $candidate_id;
        }
        $quoted = array_map(static function ($id) use ($connection) {
            return "'" . mysqli_real_escape_string($connection, $id) . "'";
        }, array_values($employee_ids));
        $exists = (int) scalarValue($connection, 'SELECT COUNT(*) FROM users WHERE employee_id IN (' . implode(',', $quoted) . ')');
    } while ($exists !== 0);

    foreach ($employee_ids as $key => $employee_id) {
        $shift = $key === 'overnight' ? $overnight_shift : $normal_shift;
        $fixture_users[$employee_id] = insertFixtureUser($connection, $employee_id, $shift, $department_name);
    }

    // Fixed future business dates plus fresh employee IDs make fixtures
    // deterministic and independent of historical/application attendance.
    $test_date = FIXTURE_BUSINESS_DATE;
    $normal_times = fixtureShiftTimestamps($test_date, $normal_shift);
    $normal_id = insertAttendanceFixture($connection, $employee_ids['normal'], $test_date, 'Present', $normal_times['start'], null, $normal_times['end']);
    testAssert((int) readAttendance($connection, $normal_id)['row_version'] === 1, 'insert uses row_version default 1');

    $duration = attendance_elapsed_seconds($normal_times['start'], $normal_times['end']);
    testAssert($duration === $normal_times['seconds'], 'selected active normal shift produces its expected timestamp duration');
    testAssert(versionedCheckout($connection, $normal_id, 1, $normal_times['end'], durationLabel($duration), 'Present') === 1, 'successful checkout update affects one row');
    testAssert((int) readAttendance($connection, $normal_id)['row_version'] === 2, 'successful update increments version exactly once');
    testAssert(versionedCheckout($connection, $normal_id, 1, $normal_times['end'], durationLabel($duration), 'Present') === 0, 'stale version is rejected');
    testAssert(versionedCheckout($connection, $normal_id, 2, $normal_times['end'], durationLabel($duration), 'Present') === 0, 'duplicate checkout is rejected without another increment');

    $duplicate_rejected = false;
    try {
        insertAttendanceFixture($connection, $employee_ids['normal'], $test_date, 'Present');
    } catch (mysqli_sql_exception $error) {
        $duplicate_rejected = $error->getCode() === 1062;
    }
    testAssert($duplicate_rejected, 'duplicate employee/date check-in is rejected by the database unique key');

    $overnight_date = FIXTURE_BUSINESS_DATE;
    $overnight_times = fixtureShiftTimestamps($overnight_date, $overnight_shift);
    if (substr($overnight_times['end'], 0, 10) === $overnight_date) {
        throw new RuntimeException('Selected active overnight shift does not cross the business-date boundary.');
    }
    $overnight_row = insertAttendanceFixture($connection, $employee_ids['overnight'], $overnight_date, 'Present', $overnight_times['start'], null, $overnight_times['end']);
    testAssert(attendance_elapsed_seconds($overnight_times['start'], $overnight_times['end']) === $overnight_times['seconds'], 'selected active overnight shift produces its expected timestamp duration');
    testAssert(versionedCheckout($connection, $overnight_row, 1, $overnight_times['end'], durationLabel($overnight_times['seconds']), 'Present') === 1, 'overnight business-date row accepts next-day checkout');
    testAssert(readAttendance($connection, $overnight_row)['attendance_date'] === $overnight_date, 'overnight checkout preserves shift business date');

    $auto_date = FIXTURE_BUSINESS_DATE;
    $auto_times = fixtureShiftTimestamps($auto_date, $normal_shift);
    $auto_id = insertAttendanceFixture($connection, $employee_ids['auto'], $auto_date, 'Present', $auto_times['start'], null, $auto_times['end']);
    $auto_version = 1;
    $auto_statement = mysqli_prepare(
        $connection,
        "UPDATE attendance SET status = 'Half Day', working_hours = '0 hrs', row_version = row_version + 1
         WHERE id = ? AND row_version = ? AND check_in_at IS NOT NULL AND check_out_at IS NULL"
    );
    mysqli_stmt_bind_param($auto_statement, 'ii', $auto_id, $auto_version);
    mysqli_stmt_execute($auto_statement);
    testAssert(mysqli_stmt_affected_rows($auto_statement) === 1, 'auto-absence completion updates one current version');
    mysqli_stmt_close($auto_statement);
    testAssert((int) readAttendance($connection, $auto_id)['row_version'] === 2, 'auto-absence increments once');
    $no_op_row = readAttendance($connection, $auto_id);
    if ($no_op_row['status'] === 'Half Day' && $no_op_row['working_hours'] === '0 hrs') {
        // Match auto_absent.php: skip UPDATE for the exact no-op state.
        testAssert((int) $no_op_row['row_version'] === 2, 'repeated auto-absence no-op leaves version unchanged');
    } else {
        throw new RuntimeException('Auto-absence no-op state was not preserved.');
    }

    $conflict_date = FIXTURE_BUSINESS_DATE;
    $conflict_id = insertAttendanceFixture($connection, $employee_ids['conflict'], $conflict_date, 'Absent');
    $conflict_version = 1;
    $convert = mysqli_prepare(
        $connection,
        "UPDATE attendance SET status = 'Leave', row_version = row_version + 1
         WHERE id = ? AND employee_id = ? AND attendance_date = ? AND status = 'Absent'
           AND row_version = ? AND check_in IS NULL AND check_out IS NULL AND check_in_at IS NULL
           AND check_out_at IS NULL AND scheduled_end_at IS NULL"
    );
    mysqli_stmt_bind_param($convert, 'issi', $conflict_id, $employee_ids['conflict'], $conflict_date, $conflict_version);
    mysqli_stmt_execute($convert);
    testAssert(mysqli_stmt_affected_rows($convert) === 1, 'Absent-to-Leave conflict conversion applies once');
    mysqli_stmt_close($convert);
    testAssert((int) readAttendance($connection, $conflict_id)['row_version'] === 2, 'conflict conversion increments version once');

    $rollback_date = (new DateTimeImmutable(FIXTURE_BUSINESS_DATE))->modify('+1 day')->format('Y-m-d');
    $rollback_id = insertAttendanceFixture($connection, $employee_ids['normal'], $rollback_date, 'Present');
    beginTrackedTransaction($connection, $primary_transaction_open);
    $rollback_version = 1;
    $rollback_update = mysqli_prepare($connection, "UPDATE attendance SET status = 'Late', row_version = row_version + 1 WHERE id = ? AND row_version = ?");
    mysqli_stmt_bind_param($rollback_update, 'ii', $rollback_id, $rollback_version);
    mysqli_stmt_execute($rollback_update);
    mysqli_stmt_close($rollback_update);
    rollbackTrackedTransaction($connection, $primary_transaction_open);
    testAssert((int) readAttendance($connection, $rollback_id)['row_version'] === 1, 'rollback restores test row state and version');

    // Interleave two independent connections that both observed version 1.
    $concurrency_date = (new DateTimeImmutable(FIXTURE_BUSINESS_DATE))->modify('+2 days')->format('Y-m-d');
    $concurrency_times = fixtureShiftTimestamps($concurrency_date, $normal_shift);
    $concurrency_id = insertAttendanceFixture($connection, $employee_ids['concurrency'], $concurrency_date, 'Present', $concurrency_times['start'], null, $concurrency_times['end']);
    $second_connection = new mysqli(TEST_HOST, $db_user, $db_password, $test_database, TEST_PORT);
    $observed_a = (int) readAttendance($connection, $concurrency_id)['row_version'];
    $observed_b = (int) readAttendance($second_connection, $concurrency_id)['row_version'];
    testAssert($observed_a === 1 && $observed_b === 1, 'competing clients observe the same initial version');
    beginTrackedTransaction($connection, $primary_transaction_open);
    testAssert(versionedCheckout($connection, $concurrency_id, $observed_a, $concurrency_times['end'], durationLabel($concurrency_times['seconds']), 'Present') === 1, 'first concurrent writer succeeds');
    commitTrackedTransaction($connection, $primary_transaction_open);
    beginTrackedTransaction($second_connection, $secondary_transaction_open);
    testAssert(versionedCheckout($second_connection, $concurrency_id, $observed_b, $concurrency_times['end'], durationLabel($concurrency_times['seconds']), 'Present') === 0, 'second stale concurrent writer is rejected');
    rollbackTrackedTransaction($second_connection, $secondary_transaction_open);

    echo "PASS: all row-version database contract cases completed.\n";
    echo "INFO: fixture rows intentionally remain in the disposable clone; recreate it from the verified backup to clean up.\n";
} catch (Throwable $error) {
    // Never print SQL text, bound data, credentials, or driver diagnostics.
    $safe_message = $error instanceof RuntimeException
        ? $error->getMessage()
        : 'database operation failed; driver details suppressed';
    fwrite(STDERR, "FAIL: {$safe_message}\n");
    $overall_failure = true;
} finally {
    $rollbacks_ok = true;
    if ($connection instanceof mysqli) {
        $rollbacks_ok = rollbackOutstandingOrFail($connection, $primary_transaction_open, 'primary') && $rollbacks_ok;
    } elseif ($primary_transaction_open) {
        fwrite(STDERR, "FAIL: primary transaction state is open but its connection is unavailable; clone state is uncertain.\n");
        $rollbacks_ok = false;
    }
    if ($second_connection instanceof mysqli) {
        $rollbacks_ok = rollbackOutstandingOrFail($second_connection, $secondary_transaction_open, 'secondary') && $rollbacks_ok;
    } elseif ($secondary_transaction_open) {
        fwrite(STDERR, "FAIL: secondary transaction state is open but its connection is unavailable; clone state is uncertain.\n");
        $rollbacks_ok = false;
    }
    if (!$rollbacks_ok) {
        $overall_failure = true;
    }

    if ($connection instanceof mysqli && $baseline !== [] && $users_baseline !== null && $rollbacks_ok) {
        try {
            // Outstanding transactions on both connections have been rolled
            // back before any preservation fingerprint is read.
            $protected_after = [];
            foreach (['leaves', 'salaries', 'leave_attendance_audit', 'attendance_correction_audit'] as $table) {
                $protected_after[$table] = tableFingerprint($connection, $table);
            }
            $fixture_user_ids = array_values($fixture_users);
            $original_users_after = tableRowsWithoutIds($connection, 'users', $fixture_user_ids);
            $original_attendance_after = tableRowsWithoutIds($connection, 'attendance', $fixture_attendance_ids);
            $fixture_users_present = (int) scalarValue($connection, 'SELECT COUNT(*) FROM users WHERE id IN (' . implode(',', array_map('intval', $fixture_user_ids ?: [0])) . ')') === count($fixture_users);
            $fixture_attendance_present = (int) scalarValue($connection, 'SELECT COUNT(*) FROM attendance WHERE id IN (' . implode(',', array_map('intval', $fixture_attendance_ids ?: [0])) . ')') === count($fixture_attendance_ids);
            $counts_match = (int) scalarValue($connection, 'SELECT COUNT(*) FROM users') === $users_baseline + count($fixture_users)
                && (int) scalarValue($connection, 'SELECT COUNT(*) FROM attendance') === $baseline['attendance'][0] + count($fixture_attendance_ids);
            $protected_match = $protected_after === array_intersect_key($baseline, array_flip(['leaves', 'salaries', 'leave_attendance_audit', 'attendance_correction_audit']));
            $original_rows_match = hash('sha256', serialize($original_users_after)) === hash('sha256', serialize($users_baseline_rows))
                && hash('sha256', serialize($original_attendance_after)) === hash('sha256', serialize($attendance_baseline_rows));
            $fixtures_match = $fixture_users_present && $fixture_attendance_present;
            $verified = $counts_match && $protected_match && $original_rows_match && $fixtures_match;
            echo $verified
                ? "PASS: only expected clone fixtures remain; pre-existing attendance/user rows and protected payroll/leave/audit tables match baseline.\n"
                : "FAIL: clone state differs from expected fixture-only changes; preserve evidence and recreate the disposable clone from its verified pre-test backup.\n";
            if (!$verified) {
                $overall_failure = true;
            }
        } catch (Throwable $verification_error) {
            fwrite(STDERR, "FAIL: final preservation verification could not complete; preserve evidence and recreate the disposable clone from its verified pre-test backup.\n");
            $overall_failure = true;
        }
    }
    if (($primary_transaction_open || $secondary_transaction_open) && $rollbacks_ok) {
        fwrite(STDERR, "FAIL: transaction state remained open after rollback handling; final verification was not trusted.\n");
        $overall_failure = true;
    }
    if ($second_connection instanceof mysqli) {
        try {
            mysqli_close($second_connection);
            $second_connection = null;
        } catch (Throwable $close_error) {
            fwrite(STDERR, "FAIL: could not close secondary database connection cleanly.\n");
            $overall_failure = true;
        }
    }
    if ($connection instanceof mysqli) {
        try {
            mysqli_close($connection);
            $connection = null;
        } catch (Throwable $close_error) {
            fwrite(STDERR, "FAIL: could not close primary database connection cleanly.\n");
            $overall_failure = true;
        }
    }
    if ($fixture_users || $fixture_attendance_ids) {
        fwrite(STDERR, 'INFO: fixtures remain until clone restoration; employee IDs=' . implode(',', array_keys($fixture_users)) . '; attendance IDs=' . implode(',', $fixture_attendance_ids) . "\n");
    }
}

exit($overall_failure ? 1 : 0);
