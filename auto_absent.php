<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance_time.php';
include __DIR__ . '/includes/db.php';
auth_require_admin($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST', true, 405);
    exit('Method Not Allowed');
}

if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Kolkata');

if (!attendance_has_datetime_columns($conn)) {
    http_response_code(503);
    exit('Attendance timestamp migration is required before auto-absence can run.');
}
if (!attendance_has_row_version_column($conn)) {
    http_response_code(503);
    exit('Attendance versioning migration is required before auto-absence can run.');
}

$now = new DateTimeImmutable('now');
if ($now->format('H:i:s') < '23:00:00') {
    exit('Auto Absent can run only after 11:00 PM');
}
$today = $now->format('Y-m-d');
$candidate_dates = [$today, $now->modify('-1 day')->format('Y-m-d')];
$processed = 0;

$users_statement = mysqli_prepare(
    $conn,
    "SELECT u.employee_id, u.joining_date, s.in_time, s.out_time
     FROM users u INNER JOIN shifts s ON s.id = u.shift_id
     WHERE u.role <> 'Admin' AND u.status = 'Active' AND s.status = 'Active'"
);
if (!$users_statement || !mysqli_stmt_execute($users_statement)) {
    http_response_code(500);
    exit('Auto-absence could not be processed.');
}
$users_result = mysqli_stmt_get_result($users_statement);
$users = [];
while ($users_result && ($user = mysqli_fetch_assoc($users_result))) {
    $users[] = $user;
}
mysqli_stmt_close($users_statement);

foreach ($users as $user) {
    foreach ($candidate_dates as $business_date) {
        if ($user['joining_date'] && $user['joining_date'] > $business_date) {
            continue;
        }
        // Weekly-off and holidays belong to the shift's business/start date.
        if ((new DateTimeImmutable($business_date))->format('w') === '0') {
            continue;
        }
        $schedule = attendance_shift_schedule($business_date, $user['in_time'], $user['out_time']);
        if (!$schedule) {
            continue;
        }
        if (!mysqli_begin_transaction($conn)) {
            continue;
        }
        try {
            // Shared parent lock serializes this writer with check-in/leave approval.
            $lock = mysqli_prepare(
                $conn,
                "SELECT u.id, s.in_time, s.out_time FROM users u
                 INNER JOIN shifts s ON s.id = u.shift_id
                 WHERE u.employee_id = ? AND u.status = 'Active' AND s.status = 'Active'
                 FOR UPDATE"
            );
            mysqli_stmt_bind_param($lock, 's', $user['employee_id']);
            if (!mysqli_stmt_execute($lock)) {
                throw new RuntimeException('user lock failed');
            }
            $lock_result = mysqli_stmt_get_result($lock);
            $locked_user = $lock_result ? mysqli_fetch_assoc($lock_result) : null;
            mysqli_stmt_close($lock);
            if (!$locked_user) {
                mysqli_commit($conn);
                continue;
            }
            $locked_schedule = attendance_shift_schedule($business_date, $locked_user['in_time'], $locked_user['out_time']);
            if (!$locked_schedule) {
                mysqli_commit($conn);
                continue;
            }

            $holiday = mysqli_prepare($conn, 'SELECT id FROM holidays WHERE holiday_date = ? LIMIT 1');
            mysqli_stmt_bind_param($holiday, 's', $business_date);
            if (!mysqli_stmt_execute($holiday)) {
                throw new RuntimeException('holiday lookup failed');
            }
            $holiday_result = mysqli_stmt_get_result($holiday);
            $is_holiday = $holiday_result && mysqli_num_rows($holiday_result) > 0;
            mysqli_stmt_close($holiday);
            if ($is_holiday) {
                mysqli_commit($conn);
                continue;
            }

            $find = mysqli_prepare($conn, 'SELECT id, check_in_at, check_out_at, scheduled_end_at, check_in, check_out, status, working_hours, row_version FROM attendance WHERE employee_id = ? AND attendance_date = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            mysqli_stmt_bind_param($find, 'ss', $user['employee_id'], $business_date);
            if (!mysqli_stmt_execute($find)) {
                throw new RuntimeException('attendance lookup failed');
            }
            $find_result = mysqli_stmt_get_result($find);
            $attendance = $find_result ? mysqli_fetch_assoc($find_result) : null;
            mysqli_stmt_close($find);

            if (!$attendance) {
                if ($locked_schedule['end'] > $now) {
                    mysqli_commit($conn);
                    continue;
                }
                $leave = mysqli_prepare($conn, "SELECT id FROM leaves WHERE employee_id = ? AND status = 'Approved' AND ? BETWEEN from_date AND to_date LIMIT 1");
                mysqli_stmt_bind_param($leave, 'ss', $user['employee_id'], $business_date);
                if (!mysqli_stmt_execute($leave)) {
                    throw new RuntimeException('leave lookup failed');
                }
                $leave_result = mysqli_stmt_get_result($leave);
                $status = ($leave_result && mysqli_num_rows($leave_result) > 0) ? 'Leave' : 'Absent';
                mysqli_stmt_close($leave);
                $insert = mysqli_prepare($conn, 'INSERT INTO attendance (employee_id, attendance_date, status) VALUES (?, ?, ?)');
                mysqli_stmt_bind_param($insert, 'sss', $user['employee_id'], $business_date, $status);
                if (!mysqli_stmt_execute($insert)) {
                    throw new RuntimeException('attendance insert failed');
                }
                mysqli_stmt_close($insert);
            } elseif (!empty($attendance['check_in_at']) && empty($attendance['check_out_at'])) {
                // Legacy open rows have no trustworthy stored shift snapshot;
                // leave them open rather than infer history from today's shift.
                if (!attendance_schedule_completed($attendance['scheduled_end_at'], $now->format('Y-m-d H:i:s'))) {
                    mysqli_commit($conn);
                    continue;
                }
                $expected_version = attendance_row_version_value($attendance);
                if ($expected_version === false) {
                    throw new RuntimeException('attendance version is invalid');
                }
                $already_half_day = $attendance['status'] === 'Half Day'
                    && $attendance['working_hours'] === '0 hrs';
                if (!$already_half_day) {
                    $update = mysqli_prepare($conn, "UPDATE attendance SET status = 'Half Day', working_hours = '0 hrs', row_version = row_version + 1 WHERE id = ? AND row_version = ? AND check_in_at IS NOT NULL AND check_out_at IS NULL");
                    if ($update === false) {
                        throw new RuntimeException('incomplete attendance update could not be prepared');
                    }
                    mysqli_stmt_bind_param($update, 'ii', $attendance['id'], $expected_version);
                    if (!mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== 1) {
                        mysqli_stmt_close($update);
                        throw new RuntimeException('incomplete attendance update conflicted');
                    }
                    mysqli_stmt_close($update);
                }
            }
            if (!mysqli_commit($conn)) {
                throw new RuntimeException('transaction commit failed');
            }
            $processed++;
        } catch (Throwable $error) {
            mysqli_rollback($conn);
            error_log('Auto-absence transaction failed for an attendance business date.');
        }
    }
}

echo 'Absent Check Completed (' . $processed . ' shift dates processed)';
