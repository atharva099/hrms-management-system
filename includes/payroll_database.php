<?php

declare(strict_types=1);

require_once __DIR__ . '/payroll_calculation.php';
require_once __DIR__ . '/attendance_time.php';

/**
 * Payroll database adapter for the R5.1c disposable stage only.
 * It never loads includes/db.php. Preview reads use a read-only snapshot;
 * workflow callers may request locking reads inside their own transaction.
 */
const COREAXIS_PAYROLL_STAGE_DATABASE = 'coreaxis_r5_payroll_period_stage_20261013';
const COREAXIS_PAYROLL_STAGE_PORT = 3307;

/** Open a dedicated connection without loading the application's DB config. */
function coreaxis_payroll_stage_connect_from_environment(): mysqli
{
    $database = getenv('COREAXIS_PAYROLL_TARGET_DATABASE');
    $user = getenv('COREAXIS_PAYROLL_DB_USER');
    $password = getenv('COREAXIS_PAYROLL_DB_PASSWORD');
    if ($database !== COREAXIS_PAYROLL_STAGE_DATABASE || !is_string($user) || $user === ''
        || !is_string($password) || $password === '') {
        coreaxis_payroll_db_fail('stage_connection_configuration_missing');
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = mysqli_init();
    if (!$conn) { coreaxis_payroll_db_fail('stage_connection_unavailable'); }
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    if (!@mysqli_real_connect($conn, '127.0.0.1', $user, $password, COREAXIS_PAYROLL_STAGE_DATABASE, COREAXIS_PAYROLL_STAGE_PORT)) {
        mysqli_close($conn);
        coreaxis_payroll_db_fail('stage_connection_unavailable');
    }
    try {
        coreaxis_payroll_db_assert_stage($conn);
    } catch (Throwable $error) {
        mysqli_close($conn);
        throw $error;
    }
    return $conn;
}

function coreaxis_payroll_db_fail(string $reason): void
{
    throw new CoreAxisPayrollCalculationException($reason);
}

function coreaxis_payroll_db_assert_stage(mysqli $conn): void
{
    $result = mysqli_query($conn, 'SELECT DATABASE() AS db_name, @@port AS server_port');
    if (!$result) {
        coreaxis_payroll_db_fail('database_identity_unavailable');
    }
    $identity = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    if (!is_array($identity)
        || ($identity['db_name'] ?? null) !== COREAXIS_PAYROLL_STAGE_DATABASE
        || (int) ($identity['server_port'] ?? 0) !== COREAXIS_PAYROLL_STAGE_PORT
        || !is_string($conn->host_info)
        || strpos($conn->host_info, '127.0.0.1') === false) {
        coreaxis_payroll_db_fail('database_target_not_authorized');
    }
}

function coreaxis_payroll_db_require_schema(mysqli $conn): void
{
    // A present CHECK object is not an enforcement gate if MariaDB's session
    // switch has disabled CHECK expression evaluation.
    $checkMode = mysqli_query($conn, 'SELECT @@check_constraint_checks AS enabled');
    $checkModeRow = $checkMode ? mysqli_fetch_assoc($checkMode) : null;
    if ($checkMode) { mysqli_free_result($checkMode); }
    if (!is_array($checkModeRow) || (int) ($checkModeRow['enabled'] ?? 0) !== 1) {
        coreaxis_payroll_db_fail('payroll_schema_check_constraints_disabled');
    }

    $required = [
        'users' => ['id','employee_id','role','status'],
        'salaries' => ['id','employee_id','salary_period','payment_status'],
        'shifts' => ['id','shift_name','in_time','out_time','status'],
        'attendance' => ['id','employee_id','attendance_date','status','row_version','shift_id_snapshot','shift_name_snapshot','check_in_at','check_out_at','working_hours','scheduled_start_at','scheduled_end_at','early_checkin_minutes_snapshot','late_checkin_cutoff_minutes_snapshot'],
        'leaves' => ['id','employee_id','leave_type','from_date','to_date','status'],
        'holidays' => ['id','holiday_date'],
        'payroll_policy_versions' => ['id','version_no','effective_from','effective_through','currency','policy_status','leave_counts_on_holidays','leave_counts_on_weekly_offs'],
        'payroll_compensation_versions' => ['id','employee_user_id','version_no','effective_from','effective_through','basic_salary_cents','incentive_cents','currency','compensation_status'],
        'payroll_policy_rules' => ['policy_version_id','rule_code','adjustment_cents'],
        'payroll_leave_classifications' => ['policy_version_id','leave_type','pay_classification'],
        'payroll_work_schedule_versions' => ['id','employee_user_id','version_no','effective_from','effective_through','shift_id','weekdays_mask','schedule_status','shift_name_snapshot','in_time_snapshot','out_time_snapshot'],
    ];
    $stmt = mysqli_prepare($conn, 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
    if (!$stmt || !mysqli_stmt_execute($stmt)) {
        if ($stmt) { mysqli_stmt_close($stmt); }
        coreaxis_payroll_db_fail('payroll_schema_metadata_unavailable');
    }
    $result = mysqli_stmt_get_result($stmt);
    $present = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $present[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
    }
    mysqli_free_result($result);
    mysqli_stmt_close($stmt);
    foreach ($required as $table => $columns) {
        foreach ($columns as $column) {
            if (empty($present[$table][$column])) {
                coreaxis_payroll_db_fail('payroll_schema_missing_required_object');
            }
        }
    }
    $requiredTables = array_keys($required);
    $tableResult = mysqli_query($conn, "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'");
    if (!$tableResult) { coreaxis_payroll_db_fail('payroll_schema_metadata_unavailable'); }
    $engines = [];
    while ($row = mysqli_fetch_assoc($tableResult)) { $engines[$row['TABLE_NAME']] = $row['ENGINE']; }
    mysqli_free_result($tableResult);
    foreach ($requiredTables as $table) {
        if (($engines[$table] ?? null) !== 'InnoDB') { coreaxis_payroll_db_fail('payroll_schema_engine_invalid'); }
    }
    $uniqueIndex = mysqli_query($conn, "SELECT COUNT(*) AS total, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='salaries' AND INDEX_NAME='uq_salaries_employee_period' AND NON_UNIQUE=0");
    $uniqueRow = $uniqueIndex ? mysqli_fetch_assoc($uniqueIndex) : null;
    if ($uniqueIndex) { mysqli_free_result($uniqueIndex); }
    if (!is_array($uniqueRow) || (int) ($uniqueRow['total'] ?? 0) !== 2
        || ($uniqueRow['indexed_columns'] ?? null) !== 'employee_id,salary_period') {
        coreaxis_payroll_db_fail('payroll_schema_missing_required_object');
    }
    $checkResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='salaries' AND CONSTRAINT_NAME='chk_salaries_period_contract' AND CONSTRAINT_TYPE='CHECK'");
    $checkRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
    if ($checkResult) { mysqli_free_result($checkResult); }
    if (!is_array($checkRow) || (int) ($checkRow['total'] ?? 0) !== 1) {
        coreaxis_payroll_db_fail('payroll_schema_missing_required_object');
    }
    $requiredTriggers = [
        'payroll_schedule_insert_gate' => ['payroll_work_schedule_versions','BEFORE','INSERT'],
        'payroll_schedule_approval_gate' => ['payroll_work_schedule_versions','BEFORE','UPDATE'],
        'payroll_schedule_no_delete' => ['payroll_work_schedule_versions','BEFORE','DELETE'],
        'payroll_compensation_insert_gate' => ['payroll_compensation_versions','BEFORE','INSERT'],
        'payroll_compensation_approval_gate' => ['payroll_compensation_versions','BEFORE','UPDATE'],
        'payroll_compensation_no_delete' => ['payroll_compensation_versions','BEFORE','DELETE'],
        'payroll_policy_insert_gate' => ['payroll_policy_versions','BEFORE','INSERT'],
        'payroll_policy_approval_gate' => ['payroll_policy_versions','BEFORE','UPDATE'],
        'payroll_policy_no_delete' => ['payroll_policy_versions','BEFORE','DELETE'],
        'payroll_policy_rules_approved_no_insert' => ['payroll_policy_rules','BEFORE','INSERT'],
        'payroll_policy_rules_approved_no_update' => ['payroll_policy_rules','BEFORE','UPDATE'],
        'payroll_policy_rules_approved_no_delete' => ['payroll_policy_rules','BEFORE','DELETE'],
        'payroll_leave_class_approved_no_insert' => ['payroll_leave_classifications','BEFORE','INSERT'],
        'payroll_leave_class_approved_no_update' => ['payroll_leave_classifications','BEFORE','UPDATE'],
        'payroll_leave_class_approved_no_delete' => ['payroll_leave_classifications','BEFORE','DELETE'],
        'payroll_snapshot_update_gate' => ['payroll_calculation_snapshots','BEFORE','UPDATE'],
        'payroll_snapshot_insert_gate' => ['payroll_calculation_snapshots','BEFORE','INSERT'],
        'payroll_snapshot_no_delete' => ['payroll_calculation_snapshots','BEFORE','DELETE'],
        'payroll_calculation_event_insert_gate' => ['payroll_calculation_events','BEFORE','INSERT'],
        'payroll_calculation_events_no_update' => ['payroll_calculation_events','BEFORE','UPDATE'],
        'payroll_calculation_events_no_delete' => ['payroll_calculation_events','BEFORE','DELETE'],
        'payroll_payment_events_no_update' => ['payroll_payment_events','BEFORE','UPDATE'],
        'payroll_payment_events_no_delete' => ['payroll_payment_events','BEFORE','DELETE'],
        'payroll_payment_transactions_no_update' => ['payroll_payment_transactions','BEFORE','UPDATE'],
        'payroll_payment_transactions_no_delete' => ['payroll_payment_transactions','BEFORE','DELETE'],
        'payroll_payment_transaction_approval_gate' => ['payroll_payment_transactions','BEFORE','INSERT'],
        'payroll_payment_event_transition_gate' => ['payroll_payment_events','BEFORE','INSERT'],
    ];
    $triggerResult = mysqli_query($conn, "SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()");
    if (!$triggerResult) { coreaxis_payroll_db_fail('payroll_schema_metadata_unavailable'); }
    $triggers = [];
    while ($row = mysqli_fetch_assoc($triggerResult)) { $triggers[$row['TRIGGER_NAME']] = [$row['EVENT_OBJECT_TABLE'], $row['ACTION_TIMING'], $row['EVENT_MANIPULATION']]; }
    mysqli_free_result($triggerResult);
    foreach ($requiredTriggers as $trigger => $expected) {
        if (($triggers[$trigger] ?? null) !== $expected) { coreaxis_payroll_db_fail('payroll_schema_missing_required_object'); }
    }
    $payrollTriggerCount = 0;
    foreach (array_keys($triggers) as $triggerName) {
        if (strpos($triggerName, 'payroll_') === 0) { $payrollTriggerCount++; }
    }
    if ($payrollTriggerCount !== count($requiredTriggers)) {
        coreaxis_payroll_db_fail('payroll_schema_unexpected_trigger');
    }
    $requiredForeignKeys = [
        'fk_payroll_schedule_employee' => ['payroll_work_schedule_versions','employee_user_id','users','id'],
        'fk_payroll_schedule_shift' => ['payroll_work_schedule_versions','shift_id','shifts','id'],
        'fk_payroll_schedule_creator' => ['payroll_work_schedule_versions','created_by_user_id','users','id'],
        'fk_payroll_schedule_approver' => ['payroll_work_schedule_versions','approved_by_user_id','users','id'],
        'fk_payroll_comp_employee' => ['payroll_compensation_versions','employee_user_id','users','id'],
        'fk_payroll_comp_creator' => ['payroll_compensation_versions','created_by_user_id','users','id'],
        'fk_payroll_comp_approver' => ['payroll_compensation_versions','approved_by_user_id','users','id'],
        'fk_payroll_policy_creator' => ['payroll_policy_versions','created_by_user_id','users','id'],
        'fk_payroll_policy_approver' => ['payroll_policy_versions','approved_by_user_id','users','id'],
        'fk_payroll_rule_policy' => ['payroll_policy_rules','policy_version_id','payroll_policy_versions','id'],
        'fk_payroll_leave_policy' => ['payroll_leave_classifications','policy_version_id','payroll_policy_versions','id'],
        'fk_payroll_snapshot_salary' => ['payroll_calculation_snapshots','salary_id','salaries','id'],
        'fk_payroll_snapshot_policy' => ['payroll_calculation_snapshots','policy_version_id','payroll_policy_versions','id'],
        'fk_payroll_calculation_event' => ['payroll_calculation_events','calculation_id','payroll_calculation_snapshots','id'],
        'fk_payroll_payment_transaction_salary' => ['payroll_payment_transactions','salary_id','salaries','id'],
        'fk_payroll_payment_event_transaction' => ['payroll_payment_events','payment_transaction_id','payroll_payment_transactions','id'],
        'fk_payroll_payment_previous_event' => ['payroll_payment_events','previous_event_id','payroll_payment_events','id'],
    ];
    $foreignKeyResult = mysqli_query($conn, "SELECT kcu.CONSTRAINT_NAME, kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_SCHEMA, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.UPDATE_RULE, rc.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE AS kcu INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc ON rc.CONSTRAINT_SCHEMA=kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME=kcu.CONSTRAINT_NAME AND rc.TABLE_NAME=kcu.TABLE_NAME WHERE kcu.CONSTRAINT_SCHEMA=DATABASE() AND kcu.REFERENCED_TABLE_NAME IS NOT NULL");
    if (!$foreignKeyResult) { coreaxis_payroll_db_fail('payroll_schema_metadata_unavailable'); }
    $foreignKeys = [];
    while ($row = mysqli_fetch_assoc($foreignKeyResult)) { $foreignKeys[$row['CONSTRAINT_NAME']] = $row; }
    mysqli_free_result($foreignKeyResult);
    foreach ($requiredForeignKeys as $foreignKey => $expected) {
        $actual = $foreignKeys[$foreignKey] ?? null;
        if (!is_array($actual)
            || $actual['TABLE_NAME'] !== $expected[0]
            || $actual['COLUMN_NAME'] !== $expected[1]
            || $actual['REFERENCED_TABLE_SCHEMA'] !== COREAXIS_PAYROLL_STAGE_DATABASE
            || $actual['REFERENCED_TABLE_NAME'] !== $expected[2]
            || $actual['REFERENCED_COLUMN_NAME'] !== $expected[3]
            || $actual['UPDATE_RULE'] !== 'RESTRICT'
            || $actual['DELETE_RULE'] !== 'RESTRICT') {
            coreaxis_payroll_db_fail('payroll_schema_missing_required_object');
        }
    }
    $payrollForeignKeyCount = 0;
    foreach (array_keys($foreignKeys) as $foreignKeyName) {
        if (strpos($foreignKeyName, 'fk_payroll_') === 0) { $payrollForeignKeyCount++; }
    }
    if ($payrollForeignKeyCount !== count($requiredForeignKeys)) {
        coreaxis_payroll_db_fail('payroll_schema_unexpected_foreign_key');
    }
}

function coreaxis_payroll_db_execute(mysqli $conn, string $sql, string $types = '', array $values = array()): mysqli_result
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        coreaxis_payroll_db_fail('payroll_read_query_unavailable');
    }
    if ($types !== '') {
        $arguments = [$stmt, $types];
        foreach ($values as $index => &$value) { $arguments[] = &$value; }
        unset($value);
        if (!call_user_func_array('mysqli_stmt_bind_param', $arguments)) {
            mysqli_stmt_close($stmt);
            coreaxis_payroll_db_fail('payroll_read_query_unavailable');
        }
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        coreaxis_payroll_db_fail('payroll_read_query_unavailable');
    }
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    if (!$result instanceof mysqli_result) {
        coreaxis_payroll_db_fail('payroll_read_query_unavailable');
    }
    return $result;
}

function coreaxis_payroll_db_rows(mysqli_result $result): array
{
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) { $rows[] = $row; }
    mysqli_free_result($result);
    return $rows;
}

/**
 * Assemble a calculator-ready month from explicitly approved database inputs.
 * Missing/overlapping schedules, unprocessed absences, pending/overlapping
 * leave, attendance conflicts, or policy ambiguity fail closed.
 */
function coreaxis_payroll_load_month(mysqli $conn, string $employeeId, string $period, bool $lockForUpdate = false): array
{
    if (!preg_match('/^EMP[0-9]{3,17}$/iD', $employeeId)
        || !preg_match('/^[0-9]{4}-[0-9]{2}-01$/D', $period)
        || !coreaxis_payroll_is_iso_date($period)) {
        coreaxis_payroll_db_fail('invalid_payroll_scope');
    }
    coreaxis_payroll_db_assert_stage($conn);
    if (!$lockForUpdate
        && !mysqli_begin_transaction($conn, MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
        coreaxis_payroll_db_fail('payroll_read_snapshot_unavailable');
    }
    $lockSuffix = $lockForUpdate ? ' FOR UPDATE' : '';
    try {
        coreaxis_payroll_db_require_schema($conn);
        $end = (new DateTimeImmutable($period))->modify('+1 month')->format('Y-m-d');
        $employeeRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id, employee_id, role, status
             FROM users WHERE employee_id = ?" . $lockSuffix,
            's', [$employeeId]
        ));
        if (count($employeeRows) !== 1) { coreaxis_payroll_db_fail('employee_identity_ambiguous_or_missing'); }
        $employee = $employeeRows[0];
        $compensationRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id,version_no,effective_from,effective_through,basic_salary_cents,incentive_cents,currency
             FROM payroll_compensation_versions
             WHERE employee_user_id = ? AND compensation_status='Approved'
               AND effective_from <= ? AND effective_through >= ?" . $lockSuffix,
            'iss', [(int) $employee['id'], $period, (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d')]
        ));
        if (count($compensationRows) !== 1) { coreaxis_payroll_db_fail('approved_compensation_missing_or_ambiguous'); }
        $compensation = $compensationRows[0];
        $employee['salary'] = coreaxis_payroll_cents_to_decimal((int) $compensation['basic_salary_cents']);
        $employee['incentive'] = coreaxis_payroll_cents_to_decimal((int) $compensation['incentive_cents']);
        $employee['currency'] = $compensation['currency'];
        $employee['compensation_version_id'] = (int) $compensation['id'];

        $policyRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id, version_no, effective_from, effective_through, currency, policy_status,
                    leave_counts_on_holidays, leave_counts_on_weekly_offs
             FROM payroll_policy_versions
             WHERE policy_status = 'Approved' AND effective_from <= ? AND effective_through >= ?" . $lockSuffix,
            'ss', [$period, (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d')]
        ));
        if (count($policyRows) !== 1) { coreaxis_payroll_db_fail('approved_policy_missing_or_ambiguous'); }
        $policyRow = $policyRows[0];
        $policyId = (int) $policyRow['id'];
        $ruleRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, 'SELECT rule_code, adjustment_cents FROM payroll_policy_rules WHERE policy_version_id = ?' . $lockSuffix, 'i', [$policyId]
        ));
        $rules = [];
        foreach ($ruleRows as $rule) {
            if (isset($rules[$rule['rule_code']])) { coreaxis_payroll_db_fail('payroll_rule_ambiguous'); }
            $rules[$rule['rule_code']] = (int) $rule['adjustment_cents'];
        }
        $classRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, 'SELECT leave_type, pay_classification FROM payroll_leave_classifications WHERE policy_version_id = ?' . $lockSuffix, 'i', [$policyId]
        ));
        $classes = [];
        foreach ($classRows as $classification) {
            if (array_key_exists($classification['leave_type'], $classes)) { coreaxis_payroll_db_fail('leave_classification_ambiguous'); }
            $classes[$classification['leave_type']] = $classification['pay_classification'];
        }
        $policy = [
            'id' => $policyId,
            'status' => $policyRow['policy_status'], 'effective_policy_count' => 1,
            'version' => (int) $policyRow['version_no'], 'effective_from' => $policyRow['effective_from'],
            'effective_through' => $policyRow['effective_through'], 'currency' => $policyRow['currency'],
            'leave_counts_on_holidays' => (bool) $policyRow['leave_counts_on_holidays'],
            'leave_counts_on_weekly_offs' => (bool) $policyRow['leave_counts_on_weekly_offs'],
            'adjustment_cents' => $rules, 'leave_classifications' => $classes,
        ];

        $userId = (int) $employee['id'];
        $scheduleRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id, version_no, effective_from, effective_through, shift_id, weekdays_mask,
                    schedule_status, shift_name_snapshot, in_time_snapshot, out_time_snapshot
             FROM payroll_work_schedule_versions
             WHERE employee_user_id = ? AND schedule_status = 'Approved'
               AND effective_from < ? AND effective_through >= ? ORDER BY effective_from, version_no" . $lockSuffix,
            'iss', [$userId, $end, $period]
        ));
        $holidayRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, 'SELECT holiday_date FROM holidays WHERE holiday_date >= ? AND holiday_date < ?' . $lockSuffix, 'ss', [$period, $end]
        ));
        $holidayDates = [];
        foreach ($holidayRows as $row) { $holidayDates[$row['holiday_date']] = true; }
        $attendanceRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            'SELECT id, attendance_date, status, check_in_at, check_out_at, working_hours,
                    scheduled_start_at, scheduled_end_at, row_version,
                    shift_id_snapshot, shift_name_snapshot,
                    early_checkin_minutes_snapshot, late_checkin_cutoff_minutes_snapshot
             FROM attendance WHERE employee_id = ? AND attendance_date >= ? AND attendance_date < ? ORDER BY attendance_date, id' . $lockSuffix,
            'sss', [$employeeId, $period, $end]
        ));
        $attendanceByDate = [];
        foreach ($attendanceRows as $row) {
            if (isset($attendanceByDate[$row['attendance_date']])) { coreaxis_payroll_db_fail('attendance_conflict'); }
            $attendanceByDate[$row['attendance_date']] = $row;
        }
        $leaveRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id, leave_type, from_date, to_date, status FROM leaves
             WHERE employee_id = ? AND from_date < ? AND to_date >= ? ORDER BY id" . $lockSuffix,
            'sss', [$employeeId, $end, $period]
        ));
        $leaveByDate = [];
        foreach ($leaveRows as $leave) {
            if ($leave['status'] === 'Rejected') { continue; }
            if (!in_array($leave['status'], ['Pending','Approved'], true)
                || !coreaxis_payroll_is_iso_date($leave['from_date'])
                || !coreaxis_payroll_is_iso_date($leave['to_date'])
                || $leave['from_date'] > $leave['to_date']) {
                coreaxis_payroll_db_fail('leave_input_invalid');
            }
            $from = max($period, $leave['from_date']);
            $through = min((new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d'), $leave['to_date']);
            for ($cursor = new DateTimeImmutable($from); $cursor->format('Y-m-d') <= $through; $cursor = $cursor->modify('+1 day')) {
                $date = $cursor->format('Y-m-d');
                $leaveByDate[$date][] = $leave;
            }
        }
        $days = [];
        for ($cursor = new DateTimeImmutable($period); $cursor->format('Y-m-d') < $end; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $covering = array_values(array_filter($scheduleRows, static function ($schedule) use ($date) {
                return $schedule['effective_from'] <= $date && $schedule['effective_through'] >= $date;
            }));
            if (count($covering) !== 1) { coreaxis_payroll_db_fail('work_schedule_missing_or_ambiguous'); }
            $schedule = $covering[0];
            $weekday = (int) $cursor->format('N') - 1;
            $mask = (int) $schedule['weekdays_mask'];
            $scheduled = (($mask >> $weekday) & 1) === 1;
            $attendance = $attendanceByDate[$date] ?? null;
            $status = $attendance['status'] ?? null;
            if ($attendance && ((int) $attendance['shift_id_snapshot'] !== (int) $schedule['shift_id']
                || $attendance['shift_name_snapshot'] !== $schedule['shift_name_snapshot'])) {
                coreaxis_payroll_db_fail('attendance_schedule_snapshot_conflict');
            }
            if ($attendance && $status !== 'Leave') {
                $window = attendance_shift_schedule($date, $schedule['in_time_snapshot'], $schedule['out_time_snapshot']);
                if ($window === false
                    || $attendance['scheduled_start_at'] !== $window['start']->format('Y-m-d H:i:s')
                    || $attendance['scheduled_end_at'] !== $window['end']->format('Y-m-d H:i:s')) {
                    coreaxis_payroll_db_fail('attendance_schedule_snapshot_conflict');
                }
            }
            if ($attendance && in_array($status, ['Present','Late','Half Day','Late Half Day','Sunday Working','Holiday Working'], true)
                && (empty($attendance['check_in_at']) || empty($attendance['check_out_at'])
                    || (int) $attendance['row_version'] < 1)) {
                coreaxis_payroll_db_fail('attendance_not_finalized');
            }
            $leaveForDate = $leaveByDate[$date] ?? [];
            if ($scheduled && $status === null && count($leaveForDate) === 0) {
                coreaxis_payroll_db_fail('scheduled_day_attendance_not_finalized');
            }
            $days[] = [
                'date' => $date, 'scheduled_workday' => $scheduled,
                'holiday' => isset($holidayDates[$date]), 'weekly_off' => !$scheduled,
                'attendance_conflict' => false, 'attendance_status' => $status,
                'attendance_record_id' => $attendance ? (int) $attendance['id'] : null,
                'attendance_row_version' => $attendance ? (int) $attendance['row_version'] : null,
                'check_in_at' => $attendance['check_in_at'] ?? null,
                'check_out_at' => $attendance['check_out_at'] ?? null,
                'working_hours_snapshot' => $attendance['working_hours'] ?? null,
                'scheduled_start_at' => $attendance['scheduled_start_at'] ?? null,
                'scheduled_end_at' => $attendance['scheduled_end_at'] ?? null,
                'early_checkin_minutes_snapshot' => $attendance['early_checkin_minutes_snapshot'] ?? null,
                'late_checkin_cutoff_minutes_snapshot' => $attendance['late_checkin_cutoff_minutes_snapshot'] ?? null,
                'active_leave_count' => count($leaveForDate),
                'leave_status' => count($leaveForDate) === 1 ? $leaveForDate[0]['status'] : null,
                'leave_type' => count($leaveForDate) === 1 ? $leaveForDate[0]['leave_type'] : null,
                'shift_status' => $schedule['schedule_status'] === 'Approved' ? 'Active' : null,
                'schedule_version' => (int) $schedule['version_no'],
                'schedule_id' => (int) $schedule['id'], 'shift_id' => (int) $schedule['shift_id'],
            ];
        }
        $calculation = coreaxis_payroll_calculate_month($employee, $period, $days, $policy);
        $calculation['policy_version_id'] = $policyId;
        $calculation['compensation_version_id'] = (int) $compensation['id'];
        $calculation['compensation_version'] = (int) $compensation['version_no'];
        $calculation['schedule_versions'] = array_values(array_unique(array_column($days, 'schedule_version')));
        if (!$lockForUpdate) { mysqli_rollback($conn); }
        return $calculation;
    } catch (Throwable $error) {
        if (!$lockForUpdate) { mysqli_rollback($conn); }
        throw $error;
    }
}
