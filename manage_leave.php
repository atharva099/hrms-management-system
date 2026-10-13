<?php

include 'includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/leave_conflicts.php';
auth_require_admin($conn);
$current_employee_id = auth_current_employee_id();

$identity_statement = mysqli_prepare(
    $conn,
    "SELECT role FROM users WHERE employee_id = ? AND status = 'Active' LIMIT 1"
);
if ($identity_statement === false) {
    http_response_code(500);
    exit('Leave requests could not be loaded.');
}
mysqli_stmt_bind_param($identity_statement, 's', $current_employee_id);
if (!mysqli_stmt_execute($identity_statement)) {
    mysqli_stmt_close($identity_statement);
    http_response_code(500);
    exit('Leave requests could not be loaded.');
}
$identity_result = mysqli_stmt_get_result($identity_statement);
$user = $identity_result ? mysqli_fetch_assoc($identity_result) : null;
mysqli_stmt_close($identity_statement);

if (!$user || $user['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

$management_messages = [
    'approved' => 'Leave approved successfully.',
    'rejected' => 'Leave rejected successfully.',
    'attendance_conflict' => 'Approval was not completed because attendance already exists for one or more requested dates. The leave remains pending for review.',
    'leave_overlap_conflict' => 'Approval was not completed because another pending or approved leave overlaps these dates. Review the conflict below.',
    'conflict_resolved' => 'The attendance conflict decision was recorded. Other unresolved conflicts must be reviewed before this leave can be approved.',
    'conflict_approved' => 'All conflicts were resolved and the leave was approved.',
    'conflict_stale' => 'The conflict changed or is no longer eligible. Reload the page and review the current records.',
    'resolution_failed' => 'Conflict resolution could not be completed. Please try again.',
    'decision_unavailable' => 'This leave request is no longer pending or could not be found. No further decision was made.',
    'decision_failed' => 'The leave decision could not be completed. Please try again.'
];
$result_key = $_GET['result'] ?? '';
$management_message = is_string($result_key) ? ($management_messages[$result_key] ?? '') : '';

$query =
    "SELECT l.*, u.full_name AS employee_name
     FROM leaves l
     LEFT JOIN users u ON u.employee_id = l.employee_id
     ORDER BY l.id DESC";

$result =
    mysqli_query($conn, $query);

$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

?>

<!DOCTYPE html>

<html>

<head>

    <title>
        Manage Leave
    </title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        body {
            margin: 0;
            background: #f4f6f9;
            font-family: Arial;
        }

        .main-container {
            display: flex;
        }

        .sidebar {
            width: 250px;
            background: #007bff;
            min-height: 100vh;
            padding: 25px 20px;
            box-sizing: border-box;
        }

        .sidebar a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 12px;
            margin-top: 10px;
            border-radius: 8px;
        }

        .sidebar a:hover,
        .sidebar .active {
            background: white;
            color: #007bff;
        }

        .content {
            flex: 1;
            padding: 40px;
        }

        table {
            width: 100%;
            background: white;
            border-collapse: collapse;
            margin-top: 20px;
            border-radius: 10px;
            overflow: hidden;
        }

        table th {
            background: #007bff;
            color: white;
            padding: 15px;
        }

        table td {
            padding: 15px;
            border-bottom: 1px solid #ddd;
        }

        .approve-btn {
            background: green;
            color: white;
            padding: 8px 12px;
            text-decoration: none;
            border-radius: 5px;
        }

        .reject-btn {
            background: red;
            color: white;
            padding: 8px 12px;
            text-decoration: none;
            border-radius: 5px;
        }
    </style>

</head>

<body>

    <div class="main-container">

        <div class="sidebar">

            <h2 style="color:white;">
                CoreAxis CRM
            </h2>

            <a href="dashboard.php">
                Dashboard
            </a>

            <a href="manage_leave.php" class="active">
                Manage Leave
            </a>

            <a href="logout.php">
                Logout
            </a>

        </div>

        <div class="content">

            <h1>
                Leave Requests
            </h1>

            <?php if ($management_message !== '') { ?>
                <p role="status" style="padding:12px; background:#eef4ff; border-radius:6px;">
                    <?php echo htmlspecialchars($management_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </p>
            <?php } ?>

            <table style="width:100%; border-collapse:collapse; text-align:center;">

                <tr>

                    <th style="padding:15px;">Employee</th>
                    <th style="padding:15px;">Leave Type</th>
                    <th style="padding:15px;">From</th>
                    <th style="padding:15px;">To</th>
                    <th style="padding:15px;">Status</th>
                    <th style="padding:15px;">Action</th>

                </tr>

                <?php

                while ($row = mysqli_fetch_assoc($result)) {

                    ?>

                    <tr>

                        <td style="padding:15px; text-align:center;">
                            <strong><?php echo $escape_html($row['employee_name'] ?? 'Unknown employee'); ?></strong><br>
                            <?php echo $escape_html($row['employee_id']); ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $escape_html($row['leave_type']); ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $escape_html($row['from_date']); ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $escape_html($row['to_date']); ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $escape_html($row['status']); ?>
                        </td>

                        <td style="padding:15px; text-align:center;">

                            <?php

                            if ($row['status'] == 'Pending') {
                                $conflict_attendance = [];
                                $conflict_overlaps = [];
                                $attendance_query = mysqli_prepare(
                                    $conn,
                                    'SELECT id, attendance_date, check_in, check_out, check_in_at,
                                            check_out_at, scheduled_end_at, status, working_hours
                                     FROM attendance
                                     WHERE employee_id = ? AND attendance_date >= ? AND attendance_date <= ?
                                     ORDER BY attendance_date, id'
                                );
                                if ($attendance_query) {
                                    mysqli_stmt_bind_param($attendance_query, 'sss', $row['employee_id'], $row['from_date'], $row['to_date']);
                                    if (mysqli_stmt_execute($attendance_query)) {
                                        $attendance_result = mysqli_stmt_get_result($attendance_query);
                                        while ($attendance_result && ($attendance_row = mysqli_fetch_assoc($attendance_result))) {
                                            $conflict_attendance[] = $attendance_row;
                                        }
                                    }
                                    mysqli_stmt_close($attendance_query);
                                }

                                $overlap_query = mysqli_prepare(
                                    $conn,
                                    "SELECT id, from_date, to_date, status FROM leaves
                                     WHERE employee_id = ? AND id <> ?
                                       AND status IN ('Pending', 'Approved')
                                       AND from_date <= ? AND to_date >= ?
                                     ORDER BY from_date, id"
                                );
                                if ($overlap_query) {
                                    mysqli_stmt_bind_param($overlap_query, 'siss', $row['employee_id'], $row['id'], $row['to_date'], $row['from_date']);
                                    if (mysqli_stmt_execute($overlap_query)) {
                                        $overlap_result = mysqli_stmt_get_result($overlap_query);
                                        while ($overlap_result && ($overlap_row = mysqli_fetch_assoc($overlap_result))) {
                                            $conflict_overlaps[] = $overlap_row;
                                        }
                                    }
                                    mysqli_stmt_close($overlap_query);
                                }

                                $has_conflicts = count($conflict_attendance) > 0 || count($conflict_overlaps) > 0;
                                if ($has_conflicts) {
                                    ?>
                                    <div style="text-align:left; min-width:330px; padding:12px; background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; margin-bottom:12px;">
                                        <strong>Conflict review required</strong>
                                        <p>Preserving attendance keeps its stored status and timestamps. Salary calculations are not changed or recalculated by this action.</p>
                                        <p>Sundays and configured holidays are approved in the leave date range, but are not written as Leave attendance rows.</p>
                                        <?php foreach ($conflict_attendance as $attendance_row) {
                                            $row_has_leave_overlap = false;
                                            foreach ($conflict_overlaps as $overlap_row) {
                                                if ($overlap_row['from_date'] <= $attendance_row['attendance_date'] && $overlap_row['to_date'] >= $attendance_row['attendance_date']) {
                                                    $row_has_leave_overlap = true;
                                                    break;
                                                }
                                            }
                                            $conflict_type = $row_has_leave_overlap || $attendance_row['status'] === 'Leave'
                                                ? 'leave_leave'
                                                : leaveConflictTypeForStatus($attendance_row['status']);
                                            $history_query = mysqli_prepare(
                                                $conn,
                                                "SELECT * FROM leave_attendance_audit
                                                 WHERE leave_id = ? AND attendance_id = ? AND event_type = 'conflict_resolved'
                                                 AND resolution_decision IN ('approve_preserve_attendance', 'convert_absent_to_leave')
                                                 ORDER BY id DESC LIMIT 1"
                                            );
                                            $prior_resolution = null;
                                            if ($history_query) {
                                                mysqli_stmt_bind_param($history_query, 'ii', $row['id'], $attendance_row['id']);
                                                if (mysqli_stmt_execute($history_query)) {
                                                    $history_result = mysqli_stmt_get_result($history_query);
                                                    $prior_resolution = $history_result ? mysqli_fetch_assoc($history_result) : null;
                                                }
                                                mysqli_stmt_close($history_query);
                                            }
                                            $display_check_in = $attendance_row['check_in_at'] ?: $attendance_row['check_in'];
                                            $display_check_out = $attendance_row['check_out_at'] ?: $attendance_row['check_out'];
                                            $prior_resolution_matches = leaveConflictAuditMatchesAttendance($prior_resolution, $attendance_row);
                                            ?>
                                            <div style="padding:10px 0; border-top:1px solid #fed7aa;">
                                                <div><strong><?php echo $escape_html($attendance_row['attendance_date']); ?></strong> — <?php echo $escape_html($attendance_row['status']); ?> (<?php echo $escape_html($conflict_type); ?>)</div>
                                                <div>Check-in: <?php echo $escape_html($display_check_in ?: '—'); ?>; check-out: <?php echo $escape_html($display_check_out ?: '—'); ?>; working hours: <?php echo $escape_html($attendance_row['working_hours'] ?: '—'); ?></div>
                                                <?php if ($prior_resolution_matches) { ?>
                                                    <p>Recorded decision: <?php echo $escape_html($prior_resolution['resolution_decision']); ?> — <?php echo $escape_html($prior_resolution['reason']); ?></p>
                                                <?php } else { ?>
                                                    <?php if ($prior_resolution) { ?>
                                                        <p>Attendance has changed since the earlier review. A new explicit decision is required.</p>
                                                    <?php } ?>
                                                    <form method="POST" action="resolve_leave_conflict.php">
                                                        <input type="hidden" name="leave_id" value="<?php echo (int) $row['id']; ?>">
                                                        <input type="hidden" name="attendance_id" value="<?php echo (int) $attendance_row['id']; ?>">
                                                        <?php echo csrf_field(); ?>
                                                        <label>Decision
                                                            <select name="decision" required>
                                                                <option value="reject_leave">Reject this leave request</option>
                                                                <?php if (!$row_has_leave_overlap && $attendance_row['status'] !== 'Leave') { ?>
                                                                    <option value="approve_preserve_attendance">Approve leave and preserve attendance</option>
                                                                <?php } ?>
                                                                <?php if (!$row_has_leave_overlap && $attendance_row['status'] === 'Absent' && !leaveConflictHasWorkEvidence($attendance_row)) { ?>
                                                                    <option value="convert_absent_to_leave">Convert evidence-free Absent to Leave</option>
                                                                <?php } ?>
                                                            </select>
                                                        </label>
                                                        <label style="display:block; margin-top:8px;">Required reason
                                                            <textarea name="reason" rows="2" maxlength="4000" required></textarea>
                                                        </label>
                                                        <button type="submit" style="margin-top:8px;">Record resolution</button>
                                                    </form>
                                                <?php } ?>
                                            </div>
                                        <?php } ?>
                                        <?php foreach ($conflict_overlaps as $overlap_row) { ?>
                                            <div style="padding:10px 0; border-top:1px solid #fed7aa;">
                                                <strong>Overlapping leave</strong>: #<?php echo (int) $overlap_row['id']; ?>
                                                (<?php echo $escape_html($overlap_row['from_date']); ?> to <?php echo $escape_html($overlap_row['to_date']); ?>, <?php echo $escape_html($overlap_row['status']); ?>).
                                                Approval remains blocked while the overlap exists.
                                            </div>
                                        <?php } ?>
                                        <?php if (count($conflict_attendance) === 0 && count($conflict_overlaps) > 0) { ?>
                                            <form method="POST" action="resolve_leave_conflict.php">
                                                <input type="hidden" name="leave_id" value="<?php echo (int) $row['id']; ?>">
                                                <input type="hidden" name="decision" value="reject_leave">
                                                <?php echo csrf_field(); ?>
                                                <label style="display:block; margin-top:8px;">Required reason
                                                    <textarea name="reason" rows="2" maxlength="4000" required></textarea>
                                                </label>
                                                <button type="submit" style="margin-top:8px;">Reject this leave request</button>
                                            </form>
                                        <?php } ?>
                                    </div>
                                    <?php
                                }

                                if (!$has_conflicts) {
                                ?>

                                <p style="font-size:12px;">Sundays and configured holidays are not written as Leave attendance rows.</p>

                                <form method="POST" action="approve_leave.php" style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" style="background:green;color:white;padding:8px 15px;border-radius:5px;border:0;cursor:pointer;">
                                        Approve
                                    </button>
                                </form>

                                <form method="POST" action="reject_leave.php" style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" style="background:red;color:white;padding:8px 15px;border-radius:5px;border:0;cursor:pointer;">
                                        Reject
                                    </button>
                                </form>

                                <?php
                                }
                            } elseif ($row['status'] == 'Approved') {
                                echo "<span style='color:green;font-weight:bold;'>Approved</span>";
                            } else {
                                echo "<span style='color:red;font-weight:bold;'>Rejected</span>";
                            }

                            ?>

                            <?php
                            $audit_history_query = mysqli_prepare(
                                $conn,
                                'SELECT created_at, event_type, conflict_type, resolution_decision,
                                        previous_attendance_status, new_attendance_status,
                                        actor_employee_id, reason
                                 FROM leave_attendance_audit WHERE leave_id = ? ORDER BY id DESC LIMIT 10'
                            );
                            if ($audit_history_query) {
                                mysqli_stmt_bind_param($audit_history_query, 'i', $row['id']);
                                if (mysqli_stmt_execute($audit_history_query)) {
                                    $audit_history_result = mysqli_stmt_get_result($audit_history_query);
                                    if ($audit_history_result && mysqli_num_rows($audit_history_result) > 0) {
                                        echo '<details style="text-align:left; margin-top:8px;"><summary>Decision history</summary>';
                                        while ($audit_row = mysqli_fetch_assoc($audit_history_result)) {
                                            echo '<p>' . $escape_html($audit_row['created_at']) . ' — '
                                                . $escape_html($audit_row['resolution_decision']) . ' / '
                                                . $escape_html($audit_row['conflict_type']) . '; '
                                                . $escape_html($audit_row['previous_attendance_status'] ?: '—') . ' → '
                                                . $escape_html($audit_row['new_attendance_status'] ?: '—') . '; Admin '
                                                . $escape_html($audit_row['actor_employee_id']) . '<br>Reason: '
                                                . $escape_html($audit_row['reason'] ?: '—') . '</p>';
                                        }
                                        echo '</details>';
                                    }
                                }
                                mysqli_stmt_close($audit_history_query);
                            }
                            ?>

                        </td>

                    </tr>

                    <?php

                }

                ?>

            </table>

        </div>

    </div>

</body>

</html>
