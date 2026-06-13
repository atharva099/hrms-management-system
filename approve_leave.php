<?php

session_start();

include 'includes/db.php';

if ($_SESSION['role'] != 'Admin') {
    header("Location: dashboard.php");
}

$id = $_GET['id'];

$get_leave_query = "SELECT * FROM leaves WHERE id='$id'";

$get_leave_result = mysqli_query($conn, $get_leave_query);

$leave = mysqli_fetch_assoc($get_leave_result);

$employee_id = $leave['employee_id'];

$from_date = $leave['from_date'];

$to_date = $leave['to_date'];

$query =
    "UPDATE leaves
SET status='Approved',
employee_notification_status='Unread'
WHERE id='$id'";

mysqli_query($conn, $query);

$current_date = strtotime($from_date);

$end_date = strtotime($to_date);

while ($current_date <= $end_date) {

    $attendance_date = date("Y-m-d", $current_date);

    $check_query = "SELECT * FROM attendance
    WHERE employee_id='$employee_id'
    AND attendance_date='$attendance_date'";

    $check_result = mysqli_query($conn, $check_query);

    if (mysqli_num_rows($check_result) == 0) {

        $insert_query = "INSERT INTO attendance
        (employee_id, attendance_date, status)

        VALUES

        ('$employee_id','$attendance_date','Leave')";

        mysqli_query($conn, $insert_query);

    }

    $current_date = strtotime("+1 day", $current_date);

}

header("Location: manage_leave.php");

?>