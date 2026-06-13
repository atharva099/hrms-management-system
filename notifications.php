<?php

session_start();

include 'includes/db.php';

$user_id = $_SESSION['employee_id'];

$user_query =
    "SELECT * FROM users
WHERE employee_id='$user_id'";

$user_result =
    mysqli_query($conn, $user_query);

$user =
    mysqli_fetch_assoc($user_result);


if ($user['role'] == 'Admin') {

    $query = "
    SELECT * FROM leaves
    WHERE status='Pending'
    ORDER BY applied_on DESC
    ";

} else {

    $query = "
    SELECT * FROM leaves
    WHERE employee_id='$user_id'
    AND
    (
        status='Approved'
        OR
        status='Rejected'
    )
    AND employee_notification_status='Unread'
    ORDER BY applied_on DESC
    ";

}

$result = mysqli_query($conn, $query);

?>

<!DOCTYPE html>

<html>

<head>

    <title>Notifications</title>
    <link rel="stylesheet" href="css/style.css">

    <style>
        h2 {
            margin-bottom: 25px;
        }
    </style>

</head>

<body class="crm-body">

    <div class="main-content">

        <div class="page-header">

            <h1 class="page-title">

                Notifications Center

            </h1>

        </div>

        <div class="table-container">

            <h2>

                <?php

                if ($user['role'] == 'Admin') {
                    echo "Pending Leave Requests";
                } else {
                    echo "Leave Status Notifications";
                }

                ?>

            </h2>

            <?php

            if (mysqli_num_rows($result) > 0) {

                ?>

                <table class="crm-table">

                    <tr>
                        <th>Employee ID</th>
                        <th>Leave Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>

                    <?php
                    while ($row = mysqli_fetch_assoc($result)) {
                        ?>

                        <tr>

                            <td>
                                <?php echo $row['employee_id']; ?>
                            </td>

                            <td>
                                <?php echo $row['leave_type']; ?>
                            </td>

                            <td>
                                <?php echo $row['from_date']; ?>
                            </td>

                            <td>
                                <?php echo $row['to_date']; ?>
                            </td>

                            <td>

                                <?php

                                if ($row['status'] == 'Approved') {

                                    echo "<span class='status-active'>Approved</span>";

                                } elseif ($row['status'] == 'Rejected') {

                                    echo "<span class='status-inactive'>Rejected</span>";

                                } else {

                                    echo "<span class='status-pending'>Pending</span>";

                                }

                                ?>

                            </td>

                            <td>

                                <?php

                                if ($user['role'] == 'Admin') {
                                    ?>

                                    <a href="manage_leave.php" class="view-btn">
                                        View Request
                                    </a>

                                    <?php
                                } else {
                                    ?>

                                    <span style="color:green; font-weight:bold;">
                                        Updated
                                    </span>

                                    <?php
                                }
                                ?>

                            </td>

                        </tr>

                        <?php
                    }
                    ?>

                </table>

                <?php

            } else {

                if ($user['role'] == 'Admin') {

                    echo "<h3 style='color:red; text-align:center; margin-top:30px;'>
        No Pending Leave Requests
        </h3>";

                } else {

                    echo "<h3 style='color:green; text-align:center; margin-top:30px;'>
        No New Leave Notifications
        </h3>";
                }
            }

            ?>

        </div>

        <div style="text-align:center; margin-top:40px;">

            <a href="dashboard.php" style="
        background:#0d6efd;
        color:white;
        padding:12px 25px;
        border-radius:8px;
        text-decoration:none;
        font-weight:bold;
    ">

                ← Back To Dashboard

            </a>

        </div>

        <?php

        if ($user['role'] != 'Admin') {

            $update_notification = "
    UPDATE leaves
    SET employee_notification_status='Read'
    WHERE employee_id='$user_id'
    AND employee_notification_status='Unread'
    ";

            mysqli_query($conn, $update_notification);
        }

        ?>

    </div>

</body>

</html>