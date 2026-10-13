<?php

/** Return true only after the additive timestamp migration is present. */
function attendance_has_datetime_columns($conn)
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $statement = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
           AND COLUMN_NAME IN ('check_in_at', 'check_out_at', 'scheduled_end_at')"
    );
    if (!$statement || !mysqli_stmt_execute($statement)) {
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        return $available = false;
    }
    mysqli_stmt_bind_result($statement, $count);
    mysqli_stmt_fetch($statement);
    mysqli_stmt_close($statement);
    return $available = ((int) $count === 3);
}

/** Return true only when the additive correction-version column is deployed. */
function attendance_has_row_version_column($conn)
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $statement = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
           AND COLUMN_NAME = 'row_version'
           AND DATA_TYPE = 'int' AND COLUMN_TYPE = 'int(10) unsigned'
           AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT = '1'"
    );
    if (!$statement || !mysqli_stmt_execute($statement)) {
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        return $available = false;
    }
    mysqli_stmt_bind_result($statement, $count);
    mysqli_stmt_fetch($statement);
    mysqli_stmt_close($statement);
    return $available = ((int) $count === 1);
}

/**
 * Compare INFORMATION_SCHEMA defaults while preserving MariaDB's quoted-literal
 * representation. MariaDB 10.4 can return the unquoted string "NULL" for a
 * nullable SQL-NULL default with COLUMN_DEFAULT IS NULL = 0; a literal string
 * default is returned quoted (for example, "'NULL'"). Column type and
 * nullability are checked by attendance_checkin_policy_columns_match().
 */
function attendance_checkin_policy_default_matches($actual, $expected, $actual_is_sql_null = false)
{
    if ($expected === null) {
        // For the known nullable snapshot columns, unquoted NULL is MariaDB's
        // metadata sentinel. Quoted 'NULL' is a literal string default and is
        // intentionally rejected. Other server representations may be PHP null.
        return $actual === null || $actual === 'NULL';
    }
    if (!is_string($expected) || !preg_match('/^(0|[1-9][0-9]*)$/D', $expected)) {
        return false;
    }
    $is_null_flag = $actual_is_sql_null === true
        || $actual_is_sql_null === 1
        || $actual_is_sql_null === '1';
    if ($is_null_flag) {
        return false;
    }
    if (is_int($actual)) {
        return $actual >= 0 && (string) $actual === $expected;
    }
    return is_string($actual)
        && preg_match('/^(0|[1-9][0-9]*)$/D', $actual)
        && $actual === $expected;
}

/** Validate the complete metadata set; partial/query-failure input fails closed. */
function attendance_checkin_policy_columns_match($columns, $query_succeeded = true)
{
    if (!$query_succeeded || !is_array($columns)) {
        return false;
    }
    $required = [
        'shifts.early_checkin_minutes' => ['smallint(5) unsigned', 'NO', '15'],
        'shifts.late_checkin_cutoff_minutes' => ['smallint(5) unsigned', 'NO', '120'],
        'attendance.shift_id_snapshot' => ['int(11)', 'YES', null],
        'attendance.shift_name_snapshot' => ['varchar(100)', 'YES', null],
        'attendance.scheduled_start_at' => ['datetime', 'YES', null],
        'attendance.late_after_at' => ['datetime', 'YES', null],
        'attendance.early_checkin_minutes_snapshot' => ['smallint(5) unsigned', 'YES', null],
        'attendance.late_checkin_cutoff_minutes_snapshot' => ['smallint(5) unsigned', 'YES', null],
    ];
    if (count($columns) !== count($required)) {
        return false;
    }
    foreach ($required as $key => [$type, $nullable, $default]) {
        if (!isset($columns[$key]) || !is_array($columns[$key])) {
            return false;
        }
        $column = $columns[$key];
        if (
            !isset($column['COLUMN_TYPE'], $column['IS_NULLABLE'])
            || strtolower((string) $column['COLUMN_TYPE']) !== $type
            || $column['IS_NULLABLE'] !== $nullable
            || !array_key_exists('COLUMN_DEFAULT', $column)
            || !array_key_exists('COLUMN_DEFAULT_IS_NULL', $column)
            || !attendance_checkin_policy_default_matches(
                $column['COLUMN_DEFAULT'],
                $default,
                $column['COLUMN_DEFAULT_IS_NULL']
            )
        ) {
            return false;
        }
    }
    return true;
}

/**
 * Check every database field required by the check-in policy cutover.
 * A false result means check-in must fail closed; checkout is independent.
 */
function attendance_has_checkin_policy_schema($conn)
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $statement = mysqli_prepare(
        $conn,
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                COLUMN_DEFAULT IS NULL AS COLUMN_DEFAULT_IS_NULL
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND ((TABLE_NAME = 'shifts' AND COLUMN_NAME IN ('early_checkin_minutes', 'late_checkin_cutoff_minutes'))
             OR (TABLE_NAME = 'attendance' AND COLUMN_NAME IN (
                 'shift_id_snapshot', 'shift_name_snapshot', 'scheduled_start_at', 'late_after_at',
                 'early_checkin_minutes_snapshot', 'late_checkin_cutoff_minutes_snapshot'
             )))"
    );
    if (!$statement || !mysqli_stmt_execute($statement)) {
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        return $available = attendance_checkin_policy_columns_match([], false);
    }
    $result = mysqli_stmt_get_result($statement);
    if (!$result) {
        mysqli_stmt_close($statement);
        return $available = attendance_checkin_policy_columns_match([], false);
    }
    $columns = [];
    while ($column = mysqli_fetch_assoc($result)) {
        $key = $column['TABLE_NAME'] . '.' . $column['COLUMN_NAME'];
        $columns[$key] = $column;
    }
    mysqli_stmt_close($statement);
    return $available = attendance_checkin_policy_columns_match($columns);
}

/** Validate and construct the shift's late threshold on its business date. */
function attendance_shift_late_threshold($business_date, $late_after, $timezone = null)
{
    if (
        !attendance_checkin_valid_business_date($business_date, $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone(date_default_timezone_get()))
        || !is_string($late_after)
        || !preg_match('/^\\d{2}:\\d{2}(?::\\d{2})?$/D', $late_after)
    ) {
        return false;
    }
    $timezone = $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone(date_default_timezone_get());
    $time = strlen($late_after) === 5 ? $late_after . ':00' : $late_after;
    $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $business_date . ' ' . $time, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$value || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $value->format('Y-m-d H:i:s') !== $business_date . ' ' . $time) {
        return false;
    }
    return $value;
}

/** Accept only a deployed positive row version from a locked attendance row. */
function attendance_row_version_value($row)
{
    if (!is_array($row) || !isset($row['row_version'])) {
        return false;
    }
    $value = (string) $row['row_version'];
    if (!preg_match('/^[1-9][0-9]*$/D', $value) || strlen($value) > 10) {
        return false;
    }
    $version = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $version === false || $version > 4294967295 ? false : (int) $version;
}

/** Build local scheduled datetimes; out_time <= in_time means next calendar day. */
function attendance_shift_schedule($business_date, $in_time, $out_time, $timezone = null)
{
    if ($timezone !== null && !$timezone instanceof DateTimeZone) {
        return false;
    }
    if (
        !is_string($business_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $business_date)
        || !is_string($in_time) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D', $in_time)
        || !is_string($out_time) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D', $out_time)
    ) {
        return false;
    }
    $date_check = DateTimeImmutable::createFromFormat('!Y-m-d', $business_date, $timezone);
    $date_errors = DateTimeImmutable::getLastErrors();
    if (!$date_check || ($date_errors && ($date_errors['warning_count'] || $date_errors['error_count'])) || $date_check->format('Y-m-d') !== $business_date) {
        return false;
    }
    $in_value = strlen($in_time) === 5 ? $in_time . ':00' : $in_time;
    $end_time = strlen($out_time) === 5 ? $out_time . ':00' : $out_time;
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $business_date . ' ' . $in_value, $timezone);
    $start_errors = DateTimeImmutable::getLastErrors();
    $validated_end_time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $business_date . ' ' . $end_time, $timezone);
    $end_time_errors = DateTimeImmutable::getLastErrors();
    if (
        !$start || !$validated_end_time
        || ($start_errors && ($start_errors['warning_count'] || $start_errors['error_count']))
        || ($end_time_errors && ($end_time_errors['warning_count'] || $end_time_errors['error_count']))
        || $start->format('H:i:s') !== $in_value
        || $validated_end_time->format('H:i:s') !== $end_time
    ) {
        return false;
    }
    $end_date = $business_date;
    if ($end_time <= $in_value) {
        $end_date = (new DateTimeImmutable($business_date, $timezone))->modify('+1 day')->format('Y-m-d');
    }
    $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $end_date . ' ' . $end_time, $timezone);
    $end_errors = DateTimeImmutable::getLastErrors();
    if (!$end || ($end_errors && ($end_errors['warning_count'] || $end_errors['error_count']))) {
        return false;
    }
    if ($start->format('Y-m-d') !== $business_date || $end <= $start) {
        return false;
    }
    return ['start' => $start, 'end' => $end];
}

/**
 * Strictly parse an attendance timestamp without consulting the system clock.
 * String timestamps are interpreted in the supplied application timezone.
 */
function attendance_checkin_parse_now($now, DateTimeZone $timezone)
{
    if ($now instanceof DateTimeInterface) {
        $parsed = DateTimeImmutable::createFromInterface($now)->setTimezone($timezone);
        return ((int) $parsed->format('Y') >= 1000 && (int) $parsed->format('Y') <= 9999) ? $parsed : false;
    }
    if (!is_string($now) || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/D', $now)) {
        return false;
    }
    $normalized = str_replace('T', ' ', $now);
    if (strlen($normalized) === 16) {
        $normalized .= ':00';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$parsed
        || ($errors && ($errors['warning_count'] || $errors['error_count']))
        || $parsed->format('Y-m-d H:i:s') !== $normalized
        || (int) $parsed->format('Y') < 1000
    ) {
        return false;
    }
    return $parsed;
}

/** Strictly validate a calendar business date. */
function attendance_checkin_valid_business_date($value, DateTimeZone $timezone)
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))
        && $date->format('Y-m-d') === $value
        && (int) $date->format('Y') >= 1000;
}

/** Return a bounded, validated set of business dates to evaluate. */
function attendance_checkin_candidate_business_dates($context, DateTimeImmutable $now)
{
    if ($context === null) {
        return [
            $now->modify('-1 day')->format('Y-m-d'),
            $now->format('Y-m-d'),
            $now->modify('+1 day')->format('Y-m-d')
        ];
    }
    if (is_string($context)) {
        return [$context];
    }
    if (!is_array($context)) {
        return false;
    }
    if (array_key_exists('business_date', $context)) {
        return is_string($context['business_date']) ? [$context['business_date']] : false;
    }
    if (!isset($context['candidate_business_dates']) || !is_array($context['candidate_business_dates'])) {
        return false;
    }
    $dates = $context['candidate_business_dates'];
    if (!$dates || count($dates) > 7) {
        return false;
    }
    foreach ($dates as $date) {
        if (!is_string($date)) {
            return false;
        }
    }
    // Do not silently deduplicate: repeated or overlapping context candidates
    // are surfaced as ambiguous by the resolver below.
    return array_values($dates);
}

function attendance_checkin_policy_minutes($value)
{
    if (is_int($value)) {
        return ($value >= 0 && $value <= 1439) ? $value : false;
    }
    if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
        return false;
    }
    $minutes = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1439]]);
    return $minutes === false ? false : (int) $minutes;
}

function attendance_checkin_decision($eligible, $reason, $business_date = null, $schedule = null, $window = null, $candidates = [])
{
    return [
        'eligible' => (bool) $eligible,
        'business_date' => $business_date,
        'scheduled_start' => $schedule ? $schedule['start']->format('Y-m-d H:i:s') : null,
        'scheduled_end' => $schedule ? $schedule['end']->format('Y-m-d H:i:s') : null,
        'permitted_window' => $window ? [
            'start' => $window['start']->format('Y-m-d H:i:s'),
            'end' => $window['end']->format('Y-m-d H:i:s')
        ] : null,
        'rejection_reason' => $eligible ? null : $reason,
        'candidate_business_dates' => array_values($candidates)
    ];
}

/**
 * Resolve check-in eligibility for one configured shift.
 *
 * $business_date_context may be null (derive yesterday/today/tomorrow around
 * the explicit $now), one YYYY-MM-DD date, or ['business_date' => date] to
 * evaluate one known shift date. A broader context may provide a bounded
 * ['candidate_business_dates' => [...]] list; multiple matching windows fail
 * closed. An optional 'timezone' value accepts DateTimeZone or a timezone ID
 * and must accompany a business_date or candidate_business_dates selector.
 *
 * The early and late values are minutes relative to scheduled shift START.
 * Window endpoints are inclusive. This function never reads the current clock
 * and does not classify Sunday/holiday attendance or affect checkout.
 */
function attendance_checkin_eligibility($now, $in_time, $out_time, $early_checkin_minutes, $late_checkin_cutoff_minutes, $business_date_context = null)
{
    $timezone = new DateTimeZone(date_default_timezone_get());
    if (is_array($business_date_context) && array_key_exists('timezone', $business_date_context)) {
        try {
            $timezone_value = $business_date_context['timezone'];
            $timezone = $timezone_value instanceof DateTimeZone
                ? $timezone_value
                : (is_string($timezone_value) ? new DateTimeZone($timezone_value) : null);
        } catch (Throwable $error) {
            $timezone = null;
        }
        if (!$timezone instanceof DateTimeZone) {
            return attendance_checkin_decision(false, 'invalid_business_date_context');
        }
    }

    $now_value = attendance_checkin_parse_now($now, $timezone);
    if (!$now_value) {
        return attendance_checkin_decision(false, 'invalid_timestamp');
    }
    $early = attendance_checkin_policy_minutes($early_checkin_minutes);
    $late = attendance_checkin_policy_minutes($late_checkin_cutoff_minutes);
    if ($early === false || $late === false) {
        return attendance_checkin_decision(false, 'invalid_policy');
    }
    // Adjacent daily business-date windows must not overlap (or touch).
    if (($early + $late) >= 1440) {
        return attendance_checkin_decision(false, 'overlapping_business_date_windows');
    }
    if (
        !is_string($in_time) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D', $in_time)
        || !is_string($out_time) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D', $out_time)
    ) {
        return attendance_checkin_decision(false, 'invalid_schedule');
    }
    $normalized_in = strlen($in_time) === 5 ? $in_time . ':00' : $in_time;
    $normalized_out = strlen($out_time) === 5 ? $out_time . ':00' : $out_time;
    if ($normalized_in === $normalized_out) {
        return attendance_checkin_decision(false, 'ambiguous_shift_duration');
    }

    $business_dates = attendance_checkin_candidate_business_dates($business_date_context, $now_value);
    if (!$business_dates) {
        return attendance_checkin_decision(false, 'invalid_business_date_context');
    }
    foreach ($business_dates as $business_date) {
        if (!attendance_checkin_valid_business_date($business_date, $timezone)) {
            return attendance_checkin_decision(false, 'invalid_business_date_context');
        }
    }

    $windows = [];
    foreach ($business_dates as $business_date) {
        $schedule = attendance_shift_schedule($business_date, $normalized_in, $normalized_out, $timezone);
        if (!$schedule) {
            return attendance_checkin_decision(false, 'invalid_schedule');
        }
        try {
            $window_start = $schedule['start']->modify('-' . $early . ' minutes');
            $window_end = $schedule['start']->modify('+' . $late . ' minutes');
        } catch (Throwable $error) {
            return attendance_checkin_decision(false, 'invalid_schedule');
        }
        if (!$window_start || !$window_end || $window_end < $window_start) {
            return attendance_checkin_decision(false, 'invalid_schedule');
        }
        $windows[] = [
            'business_date' => $business_date,
            'schedule' => [
                'start' => $schedule['start'],
                'end' => $schedule['end']
            ],
            'window' => ['start' => $window_start, 'end' => $window_end]
        ];
    }

    $matching = [];
    foreach ($windows as $candidate) {
        if ($now_value >= $candidate['window']['start'] && $now_value <= $candidate['window']['end']) {
            $matching[] = $candidate;
        }
    }
    if (count($matching) > 1) {
        return attendance_checkin_decision(false, 'ambiguous_business_date', null, null, null, array_column($matching, 'business_date'));
    }
    if (count($matching) === 1) {
        $candidate = $matching[0];
        return attendance_checkin_decision(
            true,
            null,
            $candidate['business_date'],
            $candidate['schedule'],
            $candidate['window'],
            array_column($matching, 'business_date')
        );
    }

    $nearest = null;
    $nearest_distance = PHP_INT_MAX;
    $nearest_ties = [];
    foreach ($windows as $candidate) {
        if ($now_value < $candidate['window']['start']) {
            $distance = $candidate['window']['start']->getTimestamp() - $now_value->getTimestamp();
        } else {
            $distance = $now_value->getTimestamp() - $candidate['window']['end']->getTimestamp();
        }
        if ($distance < $nearest_distance) {
            $nearest = $candidate;
            $nearest_distance = $distance;
            $nearest_ties = [$candidate];
        } elseif ($distance === $nearest_distance) {
            $nearest_ties[] = $candidate;
        }
    }
    if (count($nearest_ties) > 1) {
        return attendance_checkin_decision(false, 'ambiguous_business_date', null, null, null, array_column($nearest_ties, 'business_date'));
    }
    $reason = $now_value < $nearest['window']['start'] ? 'before_early_checkin_window' : 'after_late_checkin_cutoff';
    return attendance_checkin_decision(
        false,
        $reason,
        $nearest['business_date'],
        $nearest['schedule'],
        $nearest['window'],
        [$nearest['business_date']]
    );
}

function attendance_business_date_for_checkin($now, $in_time, $out_time)
{
    $now_value = new DateTimeImmutable($now);
    $today = $now_value->format('Y-m-d');
    $yesterday = $now_value->modify('-1 day')->format('Y-m-d');
    $previous_schedule = attendance_shift_schedule($yesterday, $in_time, $out_time);
    if ($previous_schedule && $now_value >= $previous_schedule['start'] && $now_value <= $previous_schedule['end']) {
        return $yesterday;
    }
    return $today;
}

function attendance_is_late($business_date, $now, $late_after)
{
    if (!is_string($late_after) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/D', $late_after)) {
        return false;
    }
    $late_time = strlen($late_after) === 5 ? $late_after . ':00' : $late_after;
    $threshold = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $business_date . ' ' . $late_time);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$threshold || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $threshold->format('H:i:s') !== $late_time) {
        return false;
    }
    return new DateTimeImmutable($now) > $threshold;
}

function attendance_format_datetime($value)
{
    if (!is_string($value) || $value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    return $date ? $date->format('Y-m-d H:i:s') : '';
}

function attendance_elapsed_seconds($check_in_at, $check_out_at)
{
    $check_in = attendance_format_datetime($check_in_at);
    $check_out = attendance_format_datetime($check_out_at);
    if ($check_in === '' || $check_out === '') {
        return false;
    }
    $seconds = (new DateTimeImmutable($check_out))->getTimestamp() - (new DateTimeImmutable($check_in))->getTimestamp();
    return $seconds > 0 ? $seconds : false;
}

function attendance_schedule_completed($scheduled_end_at, $now)
{
    $scheduled_end = attendance_format_datetime($scheduled_end_at);
    if ($scheduled_end === '') {
        return false;
    }
    return new DateTimeImmutable($now) >= new DateTimeImmutable($scheduled_end);
}

/**
 * A checkout candidate must belong to today's business date or to yesterday's
 * overnight shift ending today. Historical/null-snapshot rows are excluded.
 */
function attendance_is_current_open_checkout_candidate($row, $now)
{
    if (!is_array($row) || empty($row['attendance_date']) || !empty($row['check_out_at'])) {
        return false;
    }
    $now_value = new DateTimeImmutable($now);
    $today = $now_value->format('Y-m-d');
    $yesterday = $now_value->modify('-1 day')->format('Y-m-d');
    $tomorrow = $now_value->modify('+1 day')->format('Y-m-d');
    $business_date = (string) $row['attendance_date'];
    if ($business_date !== $today && $business_date !== $yesterday) {
        return false;
    }

    $check_in_at = attendance_format_datetime($row['check_in_at'] ?? null);
    $scheduled_end_at = attendance_format_datetime($row['scheduled_end_at'] ?? null);
    if ($check_in_at === '' || $scheduled_end_at === '') {
        return false;
    }
    $check_in = new DateTimeImmutable($check_in_at);
    $scheduled_end = new DateTimeImmutable($scheduled_end_at);
    if ($check_in > $now_value || $scheduled_end < $check_in) {
        return false;
    }

    $check_in_date = $check_in->format('Y-m-d');
    $next_business_date = (new DateTimeImmutable($business_date))->modify('+1 day')->format('Y-m-d');
    if ($check_in_date !== $business_date && $check_in_date !== $next_business_date) {
        return false;
    }

    $end_date = $scheduled_end->format('Y-m-d');
    if ($business_date === $today) {
        return $end_date === $today || $end_date === $tomorrow;
    }
    return $end_date === $today;
}

/** Return every eligible candidate; callers must reject ambiguity, not pick one. */
function attendance_find_current_open_checkout_candidates($conn, $employee_id, $now, $for_update = false)
{
    $now_value = new DateTimeImmutable($now);
    $today = $now_value->format('Y-m-d');
    $yesterday = $now_value->modify('-1 day')->format('Y-m-d');
    $end_exclusive = $now_value->modify('+2 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $version_column = attendance_has_row_version_column($conn) ? ', row_version' : '';
    $sql = 'SELECT id, attendance_date, check_in_at, check_out_at, scheduled_end_at, status' . $version_column . '
            FROM attendance
            WHERE employee_id = ?
              AND attendance_date BETWEEN ? AND ?
              AND check_in_at IS NOT NULL
              AND check_in_at <= ?
              AND check_out_at IS NULL
              AND scheduled_end_at IS NOT NULL
              AND scheduled_end_at >= check_in_at
              AND scheduled_end_at < ?';
    if ($for_update) {
        $sql .= ' FOR UPDATE';
    }
    $statement = mysqli_prepare($conn, $sql);
    if (!$statement) {
        return false;
    }
    mysqli_stmt_bind_param($statement, 'sssss', $employee_id, $yesterday, $today, $now, $end_exclusive);
    if (!mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        return false;
    }
    $result = mysqli_stmt_get_result($statement);
    $candidates = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        if (attendance_is_current_open_checkout_candidate($row, $now)) {
            $candidates[] = $row;
        }
    }
    mysqli_stmt_close($statement);
    return $candidates;
}

function attendance_status_after_checkout($current_status, $total_seconds)
{
    $total_hours = (int) floor($total_seconds / 3600);
    if ($current_status === 'Sunday Working') {
        return 'Sunday Working';
    }
    if ($total_hours < 4) {
        return 'Absent';
    }
    if ($total_hours < 8) {
        return $current_status === 'Late' ? 'Late Half Day' : 'Half Day';
    }
    return $current_status === 'Late' ? 'Late' : 'Present';
}
