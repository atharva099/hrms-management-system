<?php

include 'includes/db.php';
require_once __DIR__ . '/includes/auth.php';
$user_id = auth_require_authenticated($conn);
$user_role = auth_current_role();

if (isset($_POST['mark_notifications_read'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST', true, 405);
        exit('Method Not Allowed');
    }

    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden');
    }

    if ($user_role !== 'Employee') {
        http_response_code(403);
        exit('Forbidden');
    }

    $mark_read_statement = mysqli_prepare(
        $conn,
        "UPDATE leaves
         SET employee_notification_status = 'Read'
         WHERE employee_id = ?
           AND status IN ('Approved', 'Rejected')
           AND employee_notification_status = 'Unread'"
    );
    if ($mark_read_statement === false) {
        http_response_code(500);
        exit('Notifications could not be updated.');
    }
    mysqli_stmt_bind_param($mark_read_statement, 's', $user_id);
    if (!mysqli_stmt_execute($mark_read_statement)) {
        mysqli_stmt_close($mark_read_statement);
        http_response_code(500);
        exit('Notifications could not be updated.');
    }
    mysqli_stmt_close($mark_read_statement);
    header('Location: notifications.php?read=1');
    exit();
}

if ($user_role === 'Admin') {

    $query = "
    SELECT * FROM leaves
    WHERE status='Pending'
    ORDER BY applied_on DESC
    ";

} else {

    $query = mysqli_prepare(
        $conn,
        "SELECT * FROM leaves
         WHERE employee_id = ?
           AND status IN ('Approved', 'Rejected')
           AND employee_notification_status = 'Unread'
         ORDER BY applied_on DESC"
    );
    if ($query === false) {
        http_response_code(500);
        exit('Notifications could not be loaded.');
    }
    mysqli_stmt_bind_param($query, 's', $user_id);
    if (!mysqli_stmt_execute($query)) {
        mysqli_stmt_close($query);
        http_response_code(500);
        exit('Notifications could not be loaded.');
    }
    $result = mysqli_stmt_get_result($query);
    mysqli_stmt_close($query);

}

if ($user_role === 'Admin') {
    $result = mysqli_query($conn, $query);
}
$has_notifications = $result && mysqli_num_rows($result) > 0;
$read_confirmation = isset($_GET['read']) && $_GET['read'] === '1';

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

                if ($user_role === 'Admin') {
                    echo "Pending Leave Requests";
                } else {
                    echo "Leave Status Notifications";
                }

                ?>

            </h2>

            <?php

            if ($has_notifications) {

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
                                <?php echo htmlspecialchars((string) $row['employee_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars((string) $row['leave_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars((string) $row['from_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars((string) $row['to_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </td>

                            <td>

                                <?php

                                if ($row['status'] === 'Approved') {

                                    echo "<span class='status-active'>Approved</span>";

                                } elseif ($row['status'] === 'Rejected') {

                                    echo "<span class='status-inactive'>Rejected</span>";

                                } else {

                                    echo "<span class='status-pending'>Pending</span>";

                                }

                                ?>

                            </td>

                            <td>

                                <?php

                                if ($user_role === 'Admin') {
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

                if ($user_role === 'Admin') {

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

        <?php if ($read_confirmation && $user_role !== 'Admin') { ?>
            <p role="status" style="text-align:center;">Notifications marked as read.</p>
        <?php } ?>

        <?php if ($user_role !== 'Admin' && $has_notifications) { ?>
            <form method="POST" style="text-align:center; margin-top:20px;">
                <?php echo csrf_field(); ?>
                <button type="submit" name="mark_notifications_read" value="1">Mark notifications as read</button>
            </form>
        <?php } ?>

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

        ?>

    </div>

</body>

</html>
