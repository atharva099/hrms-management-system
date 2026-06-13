<?php
session_start();

date_default_timezone_set("Asia/Kolkata");

if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
}

include 'includes/db.php';

$employee_id = $_SESSION['employee_id'];

$date = date("Y-m-d");

$day = date("l");

$time = date("H:i:s");

if (isset($_POST['checkout'])) {

    $get_time_query = "SELECT check_in
FROM attendance
WHERE employee_id='$employee_id'
AND attendance_date='$date'";

    $get_time_result = mysqli_query($conn, $get_time_query);

    $row = mysqli_fetch_assoc($get_time_result);

    if (!$row || !$row['check_in']) {

        die("Invalid Check-In Record");

    }

    $check_in = $row['check_in'];

    $check_in_time = strtotime($check_in);

    $check_out_time = strtotime($time);

    $total_seconds = $check_out_time - $check_in_time;

    $total_hours = floor($total_seconds / 3600);

    $total_minutes = floor(($total_seconds % 3600) / 60);

    $working_hours = $total_hours . " hrs " . $total_minutes . " mins";

    $current_query = "
SELECT status
FROM attendance
WHERE employee_id='$employee_id'
AND attendance_date='$date'
";

    $current_result = mysqli_query($conn, $current_query);

    $current_attendance = mysqli_fetch_assoc($current_result);

    $current_status = $current_attendance['status'];

    if ($current_status == 'Sunday Working') {

        $status = "Sunday Working";

    } elseif ($total_hours < 4) {

        $status = "Absent";

    } elseif ($total_hours < 8) {

        if ($current_status == 'Late') {

            $status = "Late Half Day";

        } else {

            $status = "Half Day";

        }

    } else {

        if ($current_status == 'Late') {

            $status = "Late";

        } else {

            $status = "Present";

        }

    }
    $query = "UPDATE attendance

SET check_out='$time',
working_hours='$working_hours',
status='$status'

WHERE employee_id='$employee_id'

AND attendance_date='$date'";
    mysqli_query($conn, $query);
    echo "Check-Out Successful";
}
if
(isset($_POST['checkin'])) {
    $leave_check_query = "SELECT * FROM attendance
WHERE employee_id='$employee_id'
AND attendance_date='$date'
AND status='Leave'";

    $leave_check_result =
        mysqli_query($conn, $leave_check_query);

    if (mysqli_num_rows($leave_check_result) > 0) {

        echo "
    <script>
    alert('You are already on approved leave today');
    </script>
    ";

    } else {
        $check_query = "SELECT * FROM attendance
    WHERE employee_id='$employee_id'
    AND attendance_date='$date'";
        $check_result = mysqli_query($conn, $check_query);
        if (
            mysqli_num_rows($check_result) >
            0
        ) {

            echo "Attendance Already Marked Today";

        } else {

            $shift_query = "SELECT shifts.late_after

FROM users

INNER JOIN shifts

ON users.shift_id = shifts.id

WHERE users.employee_id='$employee_id'";

            $shift_result = mysqli_query($conn, $shift_query);

            $shift = mysqli_fetch_assoc($shift_result);

            if ($shift) {

                $shift_time = $shift['late_after'];

            } else {

                echo "
    <script>
        alert('Shift not assigned. Please contact HR.');
        window.location='attendance.php';
    </script>
    ";

                exit();
            }

            if ($day == "Sunday") {

                $status = "Sunday Working";

            } else {

                if ($time > $shift_time) {

                    $status = "Late";

                } else {

                    $status = "Present";
                }
            }

            $query = "INSERT INTO attendance
    (employee_id, attendance_date, check_in, status)

    VALUES

    ('$employee_id','$date','$time','$status')";

            mysqli_query($conn, $query);

            echo "Check-In Successful";
        }

    }
}
$today_query = "SELECT * FROM attendance
WHERE employee_id='$employee_id'
AND attendance_date='$date'";

$today_result = mysqli_query($conn, $today_query);

$today_attendance = mysqli_fetch_assoc($today_result);

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

                    $query = "SELECT * FROM attendance
            WHERE employee_id='$employee_id'
            ORDER BY id DESC";

                    $result = mysqli_query($conn, $query);

                    while ($row = mysqli_fetch_assoc($result)) {

                        ?>

                        <tr>

                            <td>
                                <?php echo $row['attendance_date']; ?>
                            </td>

                            <td>
                                <?php echo $row['check_in']; ?>
                            </td>

                            <td>
                                <?php echo $row['check_out']; ?>
                            </td>

                            <td>
                                <?php echo $row['status']; ?>
                            </td>

                            <td>
                                <?php echo $row['working_hours']; ?>
                            </td>

                        </tr>

                    <?php } ?>

                </table>

            </div>

            <div class="attendance-buttons">

                <form method="POST">

                    <?php

                    if (!$today_attendance) {

                        ?>

                        <button type="submit" name="checkin" class="checkin-btn">

                            Check In

                        </button>

                        <?php

                    } else if (
                        $today_attendance['status'] == 'Leave'
                    ) {

                        ?>

                            <button type="button" class="completed-btn">

                                Approved Leave

                            </button>

                        <?php

                    } else if (
                        $today_attendance['check_out'] == NULL
                        || $today_attendance['check_out'] == ''
                    ) {

                        ?>

                                <button type="submit" name="checkout" class="checkout-btn">

                                    Check Out

                                </button>

                        <?php

                    } else {

                        ?>

                                <button type="button" class="completed-btn">

                                    Attendance Completed

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