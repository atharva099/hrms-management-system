<?php
/**
 * Clone-only integration test for the extracted production attendance workflow.
 *
 * This runner commits one check-in and one checkout to a fresh disposable clone.
 * It never deletes fixtures. Recreate the target from a verified, compatible
 * clone backup after each run. It does not load application includes/db.php and
 * does not exercise HTTP session/authentication/CSRF behavior.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "BLOCKED: CLI only.\n");
    exit(2);
}
date_default_timezone_set('Asia/Kolkata');

const OVERNIGHT_TEST_DATABASE = 'coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c';
const OVERNIGHT_TEST_HOST = '127.0.0.1';
const OVERNIGHT_TEST_PORT = 3307;
const OVERNIGHT_TEST_EMPLOYEE = 'EMP9806';
const OVERNIGHT_TEST_SHIFT_ID = 13;
const OVERNIGHT_TEST_CHECKIN = '2026-10-11 21:50:00';
const OVERNIGHT_TEST_CHECKOUT = '2026-10-12 06:05:00';

require_once __DIR__ . '/../includes/attendance_workflow.php';
require_once __DIR__ . '/attendance_overnight_preservation_helpers.php';

$required_gates = [
    'COREAXIS_OVERNIGHT_TARGET_CONFIRMED' => OVERNIGHT_TEST_DATABASE,
    'COREAXIS_OVERNIGHT_CLONE_RESTORED' => 'YES',
    'COREAXIS_OVERNIGHT_BACKUP_VERIFIED' => 'YES',
    'COREAXIS_OVERNIGHT_ALLOW_WRITES' => 'YES'
];
foreach ($required_gates as $name => $expected) {
    if (getenv($name) !== $expected) {
        fwrite(STDERR, "BLOCKED: required disposable-clone operator gate is not satisfied.\n");
        exit(2);
    }
}
$backup_path = getenv('COREAXIS_OVERNIGHT_BACKUP_PATH');
$backup_sha256 = getenv('COREAXIS_OVERNIGHT_BACKUP_SHA256');
if (!is_string($backup_path) || $backup_path === '' || !is_file($backup_path) || !is_readable($backup_path)
    || !is_string($backup_sha256) || !preg_match('/^[a-fA-F0-9]{64}$/D', $backup_sha256)) {
    fwrite(STDERR, "BLOCKED: readable verified clone backup path and SHA-256 are required.\n");
    exit(2);
}
$actual_backup_sha256 = hash_file('sha256', $backup_path);
if (!is_string($actual_backup_sha256) || !hash_equals(strtolower($backup_sha256), strtolower($actual_backup_sha256))) {
    fwrite(STDERR, "BLOCKED: clone backup checksum verification failed.\n");
    exit(2);
}
unset($actual_backup_sha256, $backup_sha256);

$db_user = getenv('COREAXIS_TEST_DB_USER');
$db_password = getenv('COREAXIS_TEST_DB_PASSWORD');
if (!is_string($db_user) || $db_user === '') {
    fwrite(STDERR, "BLOCKED: disposable-clone database credentials are not supplied in the process environment.\n");
    exit(2);
}
if ($db_password === false) {
    if (OVERNIGHT_TEST_HOST !== '127.0.0.1' || OVERNIGHT_TEST_PORT !== 3307
        || OVERNIGHT_TEST_DATABASE !== 'coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c') {
        fwrite(STDERR, "BLOCKED: an unset password is allowed only for the fixed local disposable target.\n");
        exit(2);
    }
    $db_password = '';
} elseif (!is_string($db_password)) {
    fwrite(STDERR, "BLOCKED: disposable-clone database credentials are invalid.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = null;
$baseline = [];
$baseline_attendance = [];
$protected_attendance = [];
$inserted_attendance_id = null;
$failure = false;
$test_started = false;

function overnightAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('assertion:' . $message);
    }
    echo "PASS: {$message}\n";
}

function overnightRows($connection, $sql, $types = '', ...$values)
{
    $statement = mysqli_prepare($connection, $sql);
    if (!$statement) {
        throw new RuntimeException('query:prepare');
    }
    if ($types !== '') {
        $bind = [$statement, $types];
        foreach ($values as $index => &$value) {
            $bind[] = &$value;
        }
        unset($value);
        if (!call_user_func_array('mysqli_stmt_bind_param', $bind)) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('query:bind');
        }
    }
    if (!mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        throw new RuntimeException('query:execute');
    }
    $result = mysqli_stmt_get_result($statement);
    if (!$result) {
        mysqli_stmt_close($statement);
        throw new RuntimeException('query:result');
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    mysqli_stmt_close($statement);
    return $rows;
}

function overnightScalar($connection, $sql, $types = '', ...$values)
{
    $rows = overnightRows($connection, $sql, $types, ...$values);
    if (count($rows) !== 1) {
        throw new RuntimeException('query:scalar');
    }
    $row = $rows[0];
    return reset($row);
}

function overnightTableRows($connection, $table)
{
    $allowed = ['users', 'attendance', 'leaves', 'salaries', 'leave_attendance_audit', 'attendance_correction_audit'];
    if (!in_array($table, $allowed, true)) {
        throw new RuntimeException('fingerprint:table');
    }
    return overnightRows($connection, 'SELECT * FROM `' . $table . '` ORDER BY `id`');
}

function overnightFingerprint($rows)
{
    return hash('sha256', serialize($rows));
}

function overnightAttendanceById($connection, $id)
{
    $rows = overnightRows($connection, 'SELECT * FROM attendance WHERE id = ?', 'i', $id);
    return $rows[0] ?? null;
}

function overnightPreparedSchemaReady($connection)
{
    $expected = [
        'users', 'shifts', 'attendance', 'leaves', 'salaries',
        'leave_attendance_audit', 'attendance_correction_audit'
    ];
    foreach ($expected as $table) {
        $count = (int) overnightScalar(
            $connection,
            'SELECT COUNT(*) AS object_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            's',
            $table
        );
        if ($count !== 1) {
            return false;
        }
    }
    $unique = overnightRows(
        $connection,
        "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
           AND INDEX_NAME = 'uq_attendance_employee_date' AND NON_UNIQUE = 0
         GROUP BY INDEX_NAME"
    );
    return count($unique) === 1
        && ($unique[0]['indexed_columns'] ?? null) === 'employee_id,attendance_date'
        && attendance_has_datetime_columns($connection)
        && attendance_has_row_version_column($connection)
        && attendance_has_checkin_policy_schema($connection);
}

try {
    if (OVERNIGHT_TEST_HOST !== '127.0.0.1' || OVERNIGHT_TEST_PORT !== 3307
        || OVERNIGHT_TEST_DATABASE !== 'coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c') {
        throw new RuntimeException('target:constant');
    }
    $connection = new mysqli(
        OVERNIGHT_TEST_HOST,
        $db_user,
        $db_password,
        OVERNIGHT_TEST_DATABASE,
        OVERNIGHT_TEST_PORT
    );
    $connection->set_charset('utf8mb4');

    $identity = overnightRows($connection, 'SELECT DATABASE() AS db_name, @@port AS db_port, VERSION() AS server_version, @@version_comment AS server_comment')[0] ?? [];
    if (($identity['db_name'] ?? null) !== OVERNIGHT_TEST_DATABASE
        || (int) ($identity['db_port'] ?? 0) !== OVERNIGHT_TEST_PORT
        || strpos((string) ($identity['server_version'] ?? ''), '10.4.') !== 0
        || stripos((string) ($identity['server_comment'] ?? ''), 'mariadb') === false) {
        throw new RuntimeException('target:identity');
    }
    overnightAssert(true, 'connected only to the fixed disposable MariaDB 10.4 target');

    if (!overnightPreparedSchemaReady($connection)) {
        throw new RuntimeException('preflight:schema');
    }
    overnightAssert(true, 'required R5 attendance and audit schema is present');

    $employee_rows = overnightRows(
        $connection,
        "SELECT employee_id, role, status, shift_id FROM users WHERE employee_id = ?",
        's',
        OVERNIGHT_TEST_EMPLOYEE
    );
    if (count($employee_rows) !== 1
        || $employee_rows[0]['role'] !== 'Employee'
        || $employee_rows[0]['status'] !== 'Active'
        || (int) $employee_rows[0]['shift_id'] !== OVERNIGHT_TEST_SHIFT_ID) {
        throw new RuntimeException('preflight:employee');
    }
    $shift_rows = overnightRows(
        $connection,
        "SELECT id, shift_name, in_time, out_time, late_after, status,
                early_checkin_minutes, late_checkin_cutoff_minutes
         FROM shifts WHERE id = ?",
        'i',
        OVERNIGHT_TEST_SHIFT_ID
    );
    if (count($shift_rows) !== 1
        || $shift_rows[0]['status'] !== 'Active'
        || substr((string) $shift_rows[0]['in_time'], 0, 8) !== '22:00:00'
        || substr((string) $shift_rows[0]['out_time'], 0, 8) !== '06:00:00'
        || attendance_checkin_policy_minutes($shift_rows[0]['early_checkin_minutes']) === false
        || attendance_checkin_policy_minutes($shift_rows[0]['late_checkin_cutoff_minutes']) === false) {
        throw new RuntimeException('preflight:shift');
    }
    overnightAssert(true, 'EMP9806 is active and assigned to active shift 13 (22:00–06:00)');

    $date_conflicts = (int) overnightScalar(
        $connection,
        'SELECT COUNT(*) AS row_count FROM attendance WHERE employee_id = ? AND attendance_date IN (?, ?)',
        'sss',
        OVERNIGHT_TEST_EMPLOYEE,
        '2026-10-11',
        '2026-10-12'
    );
    $candidate_conflicts = attendance_find_current_open_checkout_candidates(
        $connection,
        OVERNIGHT_TEST_EMPLOYEE,
        (new DateTimeImmutable(OVERNIGHT_TEST_CHECKOUT, new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s')
    );
    $approved_leave = (int) overnightScalar(
        $connection,
        "SELECT COUNT(*) AS row_count FROM leaves WHERE employee_id = ? AND status = 'Approved' AND ? BETWEEN from_date AND to_date",
        'ss',
        OVERNIGHT_TEST_EMPLOYEE,
        '2026-10-11'
    );
    if ($date_conflicts !== 0 || $candidate_conflicts === false || $candidate_conflicts !== [] || $approved_leave !== 0) {
        throw new RuntimeException('preflight:attendance_conflict');
    }
    overnightAssert(true, 'no target-date attendance, open checkout candidate, or approved-leave conflict exists');

    $baseline = [];
    foreach (['users', 'attendance', 'leaves', 'salaries', 'leave_attendance_audit', 'attendance_correction_audit'] as $table) {
        $baseline[$table] = overnightTableRows($connection, $table);
    }
    $baseline_attendance = $baseline['attendance'];
    $protected_ids = [53, 54, 55];
    foreach ($protected_ids as $protected_id) {
        $row = overnightAttendanceById($connection, $protected_id);
        if (!$row) {
            throw new RuntimeException('preflight:protected_attendance');
        }
        $protected_attendance[$protected_id] = $row;
    }
    $emp9802_open = array_filter($baseline_attendance, static function ($row) {
        return $row['employee_id'] === 'EMP9802' && $row['check_out_at'] === null;
    });
    if ($emp9802_open === []) {
        throw new RuntimeException('preflight:protected_open_employee');
    }
    $test_started = true;

    $checkin_at = new DateTimeImmutable(OVERNIGHT_TEST_CHECKIN, new DateTimeZone('Asia/Kolkata'));
    $checkin = attendance_workflow_checkin($connection, OVERNIGHT_TEST_EMPLOYEE, $checkin_at);
    if (empty($checkin['ok'])) {
        throw new RuntimeException('workflow:checkin_rejected');
    }
    $test_row = overnightRows(
        $connection,
        'SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?',
        'ss',
        OVERNIGHT_TEST_EMPLOYEE,
        '2026-10-11'
    );
    if (count($test_row) !== 1) {
        throw new RuntimeException('verify:checkin_row_count');
    }
    $test_row = $test_row[0];
    $inserted_attendance_id = (int) $test_row['id'];
    overnightAssert($test_row['attendance_date'] === '2026-10-11', 'check-in uses the correct shift business date');
    overnightAssert($test_row['status'] === 'Sunday Working', 'Sunday Working classification is preserved for the Sunday business date');
    overnightAssert($test_row['check_in_at'] === OVERNIGHT_TEST_CHECKIN, 'check-in timestamp matches the injected Asia/Kolkata time');
    overnightAssert($test_row['scheduled_start_at'] === '2026-10-11 22:00:00' && $test_row['scheduled_end_at'] === '2026-10-12 06:00:00', 'overnight schedule snapshots span the calendar boundary correctly');
    overnightAssert((int) $test_row['shift_id_snapshot'] === OVERNIGHT_TEST_SHIFT_ID && $test_row['shift_name_snapshot'] === $shift_rows[0]['shift_name'], 'assigned shift identity snapshots match shift 13');
    $expected_late_after = attendance_shift_late_threshold('2026-10-11', (string) $shift_rows[0]['late_after']);
    overnightAssert($expected_late_after && $test_row['late_after_at'] === $expected_late_after->format('Y-m-d H:i:s'), 'late threshold snapshot matches shift 13 on the business date');
    overnightAssert($test_row['early_checkin_minutes_snapshot'] !== null
        && $test_row['late_checkin_cutoff_minutes_snapshot'] !== null
        && (int) $test_row['early_checkin_minutes_snapshot'] === (int) $shift_rows[0]['early_checkin_minutes']
        && (int) $test_row['late_checkin_cutoff_minutes_snapshot'] === (int) $shift_rows[0]['late_checkin_cutoff_minutes'],
        'applied check-in policy snapshots match the assigned shift');
    overnightAssert((int) $test_row['row_version'] === 1, 'new attendance row starts at row_version 1');

    $duplicate_checkin = attendance_workflow_checkin($connection, OVERNIGHT_TEST_EMPLOYEE, $checkin_at);
    $after_duplicate_checkin = overnightAttendanceById($connection, $inserted_attendance_id);
    overnightAssert(empty($duplicate_checkin['ok']) && ($duplicate_checkin['message'] ?? null) === 'Attendance is already marked for this shift date.', 'duplicate check-in is rejected by the business-date duplicate guard');
    overnightAssert(count(overnightRows($connection, 'SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?', 'ss', OVERNIGHT_TEST_EMPLOYEE, '2026-10-11')) === 1, 'duplicate check-in creates no duplicate employee/date row');
    overnightAssert($after_duplicate_checkin === $test_row, 'rejected duplicate check-in leaves the original test row unchanged');

    $checkout_at = new DateTimeImmutable(OVERNIGHT_TEST_CHECKOUT, new DateTimeZone('Asia/Kolkata'));
    $checkout = attendance_workflow_checkout($connection, OVERNIGHT_TEST_EMPLOYEE, $checkout_at);
    if (empty($checkout['ok'])) {
        throw new RuntimeException('workflow:checkout_rejected');
    }
    $closed_row = overnightAttendanceById($connection, $inserted_attendance_id);
    if (!$closed_row) {
        throw new RuntimeException('verify:checkout_row_missing');
    }
    overnightAssert($closed_row['attendance_date'] === '2026-10-11', 'checkout updates the original business-date row');
    overnightAssert($closed_row['check_out_at'] === OVERNIGHT_TEST_CHECKOUT, 'checkout timestamp matches injected next-day time');
    overnightAssert($closed_row['status'] === 'Sunday Working', 'checkout preserves Sunday Working classification');
    overnightAssert($closed_row['working_hours'] === '8 hrs 15 mins', 'working duration is 8 hrs 15 mins');
    overnightAssert((int) $closed_row['row_version'] === 2, 'successful checkout increments row_version from 1 to 2');
    overnightAssert(count(overnightRows($connection, 'SELECT id FROM attendance WHERE employee_id = ? AND attendance_date IN (?, ?)', 'sss', OVERNIGHT_TEST_EMPLOYEE, '2026-10-11', '2026-10-12')) === 1, 'checkout creates no next-calendar-date attendance row');

    $duplicate_checkout = attendance_workflow_checkout($connection, OVERNIGHT_TEST_EMPLOYEE, $checkout_at);
    overnightAssert(empty($duplicate_checkout['ok']) && ($duplicate_checkout['message'] ?? null) === 'No valid open check-in record was found.', 'duplicate checkout is rejected because no open candidate remains');
    overnightAssert(overnightAttendanceById($connection, $inserted_attendance_id) === $closed_row, 'rejected duplicate checkout leaves row and version unchanged');

    echo 'INFO: test attendance ID=' . $inserted_attendance_id . "; committed in disposable database; recreate target from verified backup after the test.\n";
} catch (Throwable $error) {
    $category = 'unexpected';
    if (preg_match('/^(target|preflight|workflow|verify|assertion|query|fingerprint):/', $error->getMessage(), $matches)) {
        $category = $matches[1];
    }
    fwrite(STDERR, "FAIL: overnight workflow test stopped at category={$category}; no database or credential details are displayed.\n");
    if ($test_started) {
        fwrite(STDERR, "WARNING: a workflow commit may have occurred; preserve the disposable clone for inspection and recreate it from its verified backup before reuse.\n");
    }
    $failure = true;
} finally {
    if ($connection instanceof mysqli && $baseline !== []) {
        $preservation_checks = [
            'Existing attendance rows' => false,
            'Protected attendance IDs 53–55' => false,
            'Users' => false,
            'Leaves' => false,
            'Salaries' => false,
            'Leave attendance audit' => false,
            'Attendance correction audit' => false,
            'Newly inserted test row' => false
        ];
        try {
            $after = [];
            foreach (['users', 'attendance', 'leaves', 'salaries', 'leave_attendance_audit', 'attendance_correction_audit'] as $table) {
                $after[$table] = overnightTableRows($connection, $table);
            }
            $test_rows = array_values(array_filter($after['attendance'], static function ($row) {
                return $row['employee_id'] === OVERNIGHT_TEST_EMPLOYEE
                    && in_array($row['attendance_date'], ['2026-10-11', '2026-10-12'], true);
            }));
            $attendance_preserved = overnightFingerprint(overnightRowsWithoutInsertedTestAttendance(
                $after['attendance'],
                $inserted_attendance_id
            ))
                === overnightFingerprint($baseline_attendance);
            $preservation_checks['Existing attendance rows'] = $attendance_preserved;
            $protected_preserved = true;
            foreach ($protected_attendance as $protected_id => $before_row) {
                $after_row = overnightAttendanceById($connection, (int) $protected_id);
                if ($after_row !== $before_row) {
                    $protected_preserved = false;
                    break;
                }
            }
            $preservation_checks['Protected attendance IDs 53–55'] = $protected_preserved;

            $table_checks = [
                'Users' => 'users',
                'Leaves' => 'leaves',
                'Salaries' => 'salaries',
                'Leave attendance audit' => 'leave_attendance_audit',
                'Attendance correction audit' => 'attendance_correction_audit'
            ];
            foreach ($table_checks as $label => $table) {
                $preservation_checks[$label] = overnightFingerprint($after[$table])
                    === overnightFingerprint($baseline[$table]);
            }

            $test_rows_valid = $inserted_attendance_id === null
                ? count($test_rows) === 0
                : count($test_rows) === 1
                    && overnightNormalizePositiveId($test_rows[0]['id'] ?? null) === $inserted_attendance_id;
            $preservation_checks['Newly inserted test row'] = $test_rows_valid;
        } catch (Throwable $verification_error) {
            fwrite(STDERR, "FAIL: final disposable-clone preservation verification could not complete; recreate the clone from its verified backup before reuse.\n");
            $failure = true;
        }

        foreach ($preservation_checks as $label => $passed) {
            echo ($passed ? 'PASS: ' : 'FAIL: ') . $label . " preservation verification.\n";
            if (!$passed) {
                $failure = true;
            }
        }
        // Stable ASCII-keyed machine result. Keep the human-readable lines
        // above for operators, but let orchestration validate every category
        // without depending on Unicode punctuation or wording.
        $preservation_result = [
            'existing_attendance_rows' => $preservation_checks['Existing attendance rows'],
            'protected_attendance_53_55' => $preservation_checks['Protected attendance IDs 53–55'],
            'users' => $preservation_checks['Users'],
            'leaves' => $preservation_checks['Leaves'],
            'salaries' => $preservation_checks['Salaries'],
            'leave_attendance_audit' => $preservation_checks['Leave attendance audit'],
            'attendance_correction_audit' => $preservation_checks['Attendance correction audit'],
            'new_test_row' => $preservation_checks['Newly inserted test row']
        ];
        $preservation_json = json_encode($preservation_result, JSON_UNESCAPED_SLASHES);
        if (!is_string($preservation_json)) {
            fwrite(STDERR, "FAIL: machine-readable preservation result could not be encoded.\n");
            $failure = true;
        } else {
            echo 'PRESERVATION_RESULT=' . $preservation_json . "\n";
        }
    }
    if ($connection instanceof mysqli) {
        try {
            mysqli_close($connection);
        } catch (Throwable $close_error) {
            fwrite(STDERR, "FAIL: disposable clone connection could not be closed cleanly.\n");
            $failure = true;
        }
    }
}

exit($failure ? 1 : 0);
