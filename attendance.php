<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance_time.php';
require_once __DIR__ . '/includes/attendance_workflow.php';
auth_start_session();

date_default_timezone_set("Asia/Kolkata");

include 'includes/db.php';
$employee_id = auth_require_authenticated($conn);


$application_now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
$date = $application_now->format('Y-m-d');
$time = $application_now->format('H:i:s');
$now = $application_now->format('Y-m-d H:i:s');
$day = $application_now->format('l');
$message = '';
$timestamp_schema_ready = attendance_has_datetime_columns($conn);
$row_version_schema_ready = attendance_has_row_version_column($conn);
$checkin_policy_schema_ready = attendance_has_checkin_policy_schema($conn);

if (isset($_POST['checkin']) || isset($_POST['checkout'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden');
    }
    if (!$timestamp_schema_ready) {
        http_response_code(503);
        exit('Attendance timestamp migration is required before attendance actions are available.');
    }
    if (isset($_POST['checkin']) && !$checkin_policy_schema_ready) {
        http_response_code(503);
        exit('Check-in policy migration is required before check-in is available.');
    }
    if (isset($_POST['checkout']) && !$row_version_schema_ready) {
        http_response_code(503);
        exit('Attendance versioning migration is required before checkout is available.');
    }

    $workflow_result = isset($_POST['checkin'])
        ? attendance_workflow_checkin($conn, $employee_id, $application_now)
        : attendance_workflow_checkout($conn, $employee_id, $application_now);
    if (isset($workflow_result['http_status'])) {
        http_response_code($workflow_result['http_status']);
        exit($workflow_result['message']);
    }
    $message = $workflow_result['message'];
}

$checkin_decision = null;
$checkin_policy_message = '';
$attendance_business_date = $date;
if (!$checkin_policy_schema_ready) {
    $checkin_policy_message = 'Check-in is temporarily unavailable because the shift policy migration is missing or invalid.';
} else {
    $ui_shift_lookup = attendance_load_active_checkin_shift($conn, $employee_id, false);
    if (!$ui_shift_lookup['ok']) {
        $checkin_policy_message = 'Check-in is temporarily unavailable because the assigned shift policy could not be verified.';
    } elseif (!$ui_shift_lookup['shift']) {
        $checkin_policy_message = 'An active shift is not assigned. Please contact HR.';
    } else {
        $ui_shift = $ui_shift_lookup['shift'];
        $checkin_decision = attendance_checkin_eligibility(
            $now,
            (string) $ui_shift['in_time'],
            (string) $ui_shift['out_time'],
            $ui_shift['early_checkin_minutes'],
            $ui_shift['late_checkin_cutoff_minutes']
        );
        $ui_shift_id = filter_var((string) $ui_shift['shift_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (
            !empty($checkin_decision['eligible'])
            && (!$ui_shift_id || trim((string) $ui_shift['shift_name']) === ''
                || !attendance_shift_late_threshold($checkin_decision['business_date'], (string) $ui_shift['late_after']))
        ) {
            $checkin_decision = attendance_checkin_decision(false, 'invalid_schedule');
        }
        if (!empty($checkin_decision['business_date'])) {
            $attendance_business_date = $checkin_decision['business_date'];
        }
        if (empty($checkin_decision['eligible'])) {
            $checkin_policy_message = attendance_checkin_policy_message($checkin_decision);
        }
    }
}

$today_statement = mysqli_prepare($conn, 'SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? ORDER BY id DESC LIMIT 1');
mysqli_stmt_bind_param($today_statement, 'ss', $employee_id, $attendance_business_date);
mysqli_stmt_execute($today_statement);
$today_result = mysqli_stmt_get_result($today_statement);
$today_attendance = $today_result ? mysqli_fetch_assoc($today_result) : null;
mysqli_stmt_close($today_statement);
$open_attendance = null;
$open_attendance_issue = false;
if ($timestamp_schema_ready) {
    $open_candidates = attendance_find_current_open_checkout_candidates($conn, $employee_id, $now);
    if ($open_candidates === false || count($open_candidates) > 1) {
        $open_attendance_issue = true;
    } else {
        $open_attendance = $open_candidates[0] ?? null;
    }
}

?>

<!DOCTYPE html>
<html>

<head>

    <title>Attendance</title>

    <link rel="stylesheet" href="css/style.css">

</head>

<body>

    <div class="main-content">
        <div class="attendance-wrapper">


            <div class="page-header">
                <h1 class="attendance-title">
                    Attendance Records
                </h1>
            </div>

            <?php if ($message !== '') { ?>
                <p><?php echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
            <?php } ?>
            <?php if (!$timestamp_schema_ready) { ?>
                <p>Attendance actions are temporarily unavailable until the timestamp migration is applied. Existing records remain viewable.</p>
            <?php } ?>
            <?php if ($checkin_policy_message !== '') { ?>
                <p><?php echo htmlspecialchars($checkin_policy_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
            <?php } ?>
            <?php if ($timestamp_schema_ready && !$row_version_schema_ready) { ?>
                <p>Checkout is temporarily unavailable until attendance versioning is deployed. Existing records remain viewable.</p>
            <?php } ?>

            <div class="table-container">

                <table class="attendance-table">

                    <tr>
                        <th>Date</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Status</th>
                        <th>Working Hours</th>
                    </tr>

                    <?php

                    $query = 'SELECT * FROM attendance WHERE employee_id = ? ORDER BY id DESC';
                    $statement = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($statement, 's', $employee_id);
                    mysqli_stmt_execute($statement);
                    $result = mysqli_stmt_get_result($statement);

                    while ($row = mysqli_fetch_assoc($result)) {

                        ?>

                        <tr>

                            <td>
                                <?php echo htmlspecialchars((string) $row['attendance_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars((string) (($timestamp_schema_ready && !empty($row['check_in_at'])) ? $row['check_in_at'] : $row['check_in']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars((string) (($timestamp_schema_ready && !empty($row['check_out_at'])) ? $row['check_out_at'] : $row['check_out']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars((string) $row['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars((string) $row['working_hours'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </td>

                        </tr>

                    <?php } mysqli_stmt_close($statement); ?>

                </table>

            </div>

            <div class="attendance-buttons">

                <form method="POST">

                    <?php echo csrf_field(); ?>

                    <?php

                    if (!$timestamp_schema_ready) {
                        ?>
                        <button type="button" class="completed-btn" disabled>Migration Required</button>
                        <?php
                    } else if ($open_attendance_issue) {
                        ?>
                        <button type="button" class="completed-btn" disabled>Attendance Needs Review</button>
                        <?php
                    } else if ($open_attendance) {
                        ?>
                        <?php if (!$row_version_schema_ready) { ?>
                        <button type="button" class="completed-btn" disabled>Versioning Migration Required</button>
                        <?php } else { ?>
                        <button type="submit" name="checkout" class="checkout-btn">Check Out</button>
                        <?php } ?>
                        <?php
                    } else if ($today_attendance && $today_attendance['status'] == 'Leave') {

                        ?>
                            <button type="button" class="completed-btn">Approved Leave</button>
                        <?php

                    } else if ($today_attendance) {

                        ?>
                            <button type="button" class="completed-btn">Attendance Completed</button>
                        <?php

                    } else if (!$checkin_policy_schema_ready || !$checkin_decision || empty($checkin_decision['eligible'])) {

                        ?>
                            <button type="button" class="completed-btn" disabled>Check-in Unavailable</button>
                        <?php

                    } else if (!$today_attendance) {

                        ?>

                        <button type="submit" name="checkin" class="checkin-btn">

                            Check In

                        </button>

                        <?php

                    }

                    ?>

                </form>

            </div>

            <br>

            <div class="back-link">
                <a href="dashboard.php">← Back To Dashboard</a>
            </div>

        </div>
    </div>

</body>

</html>
