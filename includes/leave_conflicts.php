<?php

function leaveConflictParseDate($value)
{
    if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        || $date->format('Y-m-d') !== $value
        || (int) $date->format('Y') < 1000
    ) {
        return false;
    }

    return $date;
}

function leaveConflictTypeForStatus($status)
{
    $types = [
        'Present' => 'present_leave',
        'Late' => 'late_leave',
        'Half Day' => 'half_day_leave',
        'Late Half Day' => 'late_half_day_leave',
        'Absent' => 'absent_leave',
        'Sunday Working' => 'sunday_working_leave',
        'Leave' => 'leave_leave'
    ];

    return $types[$status] ?? 'other_attendance_leave';
}

function leaveConflictHasWorkEvidence($attendance)
{
    foreach (['check_in', 'check_out', 'check_in_at', 'check_out_at', 'scheduled_end_at'] as $field) {
        if (isset($attendance[$field]) && $attendance[$field] !== '') {
            return true;
        }
    }

    $working_hours = $attendance['working_hours'] ?? null;
    return $working_hours !== null
        && trim((string) $working_hours) !== ''
        && !preg_match('/^0\s*hrs?\s+0\s*mins?$/i', trim((string) $working_hours));
}

function leaveConflictAuditMatchesAttendance($event, $attendance)
{
    if (!$event || ($event['new_attendance_status'] ?? null) !== $attendance['status']) {
        return false;
    }

    if (!in_array($event['resolution_decision'] ?? '', ['approve_preserve_attendance', 'convert_absent_to_leave'], true)) {
        return false;
    }

    foreach ([
        'check_in' => 'previous_check_in',
        'check_out' => 'previous_check_out',
        'check_in_at' => 'previous_check_in_at',
        'check_out_at' => 'previous_check_out_at',
        'scheduled_end_at' => 'previous_scheduled_end_at',
        'working_hours' => 'previous_working_hours'
    ] as $current_field => $snapshot_field) {
        if (($event[$snapshot_field] ?? null) !== ($attendance[$current_field] ?? null)) {
            return false;
        }
    }

    return true;
}

function leaveConflictUuid()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function leaveConflictNonworkingReason($conn, $date)
{
    $parsed = leaveConflictParseDate($date);
    if ($parsed === false) {
        throw new DomainException('The attendance date is invalid.');
    }
    if ($parsed->format('N') === '7') {
        return 'weekly_off_leave';
    }

    $statement = mysqli_prepare($conn, 'SELECT id FROM holidays WHERE holiday_date = ? LIMIT 1');
    if ($statement === false) {
        throw new RuntimeException('Holiday calendar could not be checked.');
    }
    $date_value = $parsed->format('Y-m-d');
    mysqli_stmt_bind_param($statement, 's', $date_value);
    if (!mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        throw new RuntimeException('Holiday calendar could not be checked.');
    }
    $result = mysqli_stmt_get_result($statement);
    $holiday = $result && mysqli_fetch_assoc($result);
    mysqli_stmt_close($statement);
    return $holiday ? 'holiday_leave' : null;
}

function leaveConflictAuditAppend($conn, $event)
{
    $statement = mysqli_prepare(
        $conn,
        'INSERT INTO leave_attendance_audit (
            correlation_id, event_type, leave_id, employee_id, attendance_id,
            conflicting_leave_id, attendance_date, conflict_type, resolution_decision,
            previous_leave_status, new_leave_status, previous_attendance_status,
            new_attendance_status, previous_check_in, previous_check_out,
            previous_check_in_at, previous_check_out_at, previous_scheduled_end_at,
            previous_working_hours, actor_user_id, actor_employee_id, reason
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($statement === false) {
        throw new RuntimeException('Audit record could not be prepared.');
    }

    $correlation_id = $event['correlation_id'];
    $event_type = $event['event_type'];
    $leave_id = $event['leave_id'];
    $employee_id = $event['employee_id'];
    $attendance_id = $event['attendance_id'];
    $conflicting_leave_id = $event['conflicting_leave_id'];
    $attendance_date = $event['attendance_date'];
    $conflict_type = $event['conflict_type'];
    $resolution_decision = $event['resolution_decision'];
    $previous_leave_status = $event['previous_leave_status'];
    $new_leave_status = $event['new_leave_status'];
    $previous_attendance_status = $event['previous_attendance_status'];
    $new_attendance_status = $event['new_attendance_status'];
    $previous_check_in = $event['previous_check_in'];
    $previous_check_out = $event['previous_check_out'];
    $previous_check_in_at = $event['previous_check_in_at'];
    $previous_check_out_at = $event['previous_check_out_at'];
    $previous_scheduled_end_at = $event['previous_scheduled_end_at'];
    $previous_working_hours = $event['previous_working_hours'];
    $actor_user_id = $event['actor_user_id'];
    $actor_employee_id = $event['actor_employee_id'];
    $reason = $event['reason'];
    $types = 'ssisii' . str_repeat('s', 13) . 'iss';

    if (!mysqli_stmt_bind_param(
        $statement,
        $types,
        $correlation_id,
        $event_type,
        $leave_id,
        $employee_id,
        $attendance_id,
        $conflicting_leave_id,
        $attendance_date,
        $conflict_type,
        $resolution_decision,
        $previous_leave_status,
        $new_leave_status,
        $previous_attendance_status,
        $new_attendance_status,
        $previous_check_in,
        $previous_check_out,
        $previous_check_in_at,
        $previous_check_out_at,
        $previous_scheduled_end_at,
        $previous_working_hours,
        $actor_user_id,
        $actor_employee_id,
        $reason
    ) || !mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        throw new RuntimeException('Audit record could not be written.');
    }

    mysqli_stmt_close($statement);
    return mysqli_insert_id($conn);
}

function leaveConflictNewAuditEvent($correlation_id, $event_type, $leave, $date, $conflict_type, $decision, $actor_user_id, $actor_employee_id, $reason)
{
    return [
        'correlation_id' => $correlation_id,
        'event_type' => $event_type,
        'leave_id' => (int) $leave['id'],
        'employee_id' => $leave['employee_id'],
        'attendance_id' => null,
        'conflicting_leave_id' => null,
        'attendance_date' => $date,
        'conflict_type' => $conflict_type,
        'resolution_decision' => $decision,
        'previous_leave_status' => $leave['status'],
        'new_leave_status' => $leave['status'],
        'previous_attendance_status' => null,
        'new_attendance_status' => null,
        'previous_check_in' => null,
        'previous_check_out' => null,
        'previous_check_in_at' => null,
        'previous_check_out_at' => null,
        'previous_scheduled_end_at' => null,
        'previous_working_hours' => null,
        'actor_user_id' => $actor_user_id,
        'actor_employee_id' => $actor_employee_id,
        'reason' => $reason
    ];
}
