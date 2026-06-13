<?php

include 'includes/db.php';

date_default_timezone_set("Asia/Kolkata");

$date = date("Y-m-d");

$current_time = date("H:i:s");

$day_of_week = date("w");

if ($day_of_week == 0) {
    die("Today is Sunday. Auto absent skipped.");
}

if ($current_time < "23:00:00") {

    die("Auto Absent can run only after 11:00 PM");

}

$holiday_check = mysqli_query(
    $conn,
    "SELECT id FROM holidays WHERE holiday_date='$date' LIMIT 1"
);

if (mysqli_num_rows($holiday_check) > 0) {
    die("Today is a holiday. Auto absent skipped.");
}



$users_query = "
SELECT *
FROM users
WHERE role != 'Admin'
AND status='Active'
AND joining_date <= '$date'
";

$users_result = mysqli_query($conn, $users_query);



while ($user = mysqli_fetch_assoc($users_result)) {
    $employee_id = $user['employee_id'];



    $check_query = "SELECT * FROM attendance

    WHERE employee_id='$employee_id'

    AND attendance_date='$date'";



    $check_result = mysqli_query($conn, $check_query);



    $leave_query = "
SELECT * FROM leaves

WHERE employee_id='$employee_id'

AND status='Approved'

AND '$date' BETWEEN from_date AND to_date
";

    $leave_result = mysqli_query($conn, $leave_query);

    $is_on_leave = mysqli_num_rows($leave_result) > 0;

    if (mysqli_num_rows($check_result) == 0) {

        if ($is_on_leave) {

            $status = "Leave";

        } else {

            $status = "Absent";

        }

        $insert = "INSERT INTO attendance

(employee_id, attendance_date, status)

VALUES

('$employee_id', '$date', '$status')";

        mysqli_query($conn, $insert);

    } else {

        $attendance = mysqli_fetch_assoc($check_result);

        if (
            $attendance['check_in'] != NULL
            &&
            (
                $attendance['check_out'] == NULL
                ||
                $attendance['check_out'] == ''
            )
        ) {

            $update = "UPDATE attendance

        SET status='Half Day',
        working_hours='0 hrs'

        WHERE employee_id='$employee_id'

        AND attendance_date='$date'";

            mysqli_query($conn, $update);
        }
    }
}



echo "Absent Check Completed";

?>