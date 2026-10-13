<?php

require_once __DIR__ . '/includes/auth.php';
auth_start_session();

date_default_timezone_set("Asia/Kolkata");

include 'includes/db.php';
$employee_id = auth_require_authenticated($conn);
$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$user_query = mysqli_prepare($conn, 'SELECT * FROM users WHERE employee_id = ? LIMIT 1');
mysqli_stmt_bind_param($user_query, 's', $employee_id);
mysqli_stmt_execute($user_query);
$user_result = mysqli_stmt_get_result($user_query);
$user = $user_result ? mysqli_fetch_assoc($user_result) : null;
mysqli_stmt_close($user_query);

$total_employee_query =
    "SELECT COUNT(*) AS total FROM users";

$total_employee_result =
    mysqli_query($conn, $total_employee_query);

$total_employee =
    mysqli_fetch_assoc($total_employee_result);

$total_attendance_query =
    "SELECT COUNT(*) AS total FROM attendance
WHERE attendance_date = CURDATE()";

$total_attendance_result =
    mysqli_query($conn, $total_attendance_query);

$total_attendance =
    mysqli_fetch_assoc($total_attendance_result);

$total_leave_query =
    "SELECT COUNT(*) AS total
FROM attendance
WHERE attendance_date = CURDATE()
AND status='Leave'";

$total_leave_result =
    mysqli_query($conn, $total_leave_query);

$total_leave =
    mysqli_fetch_assoc($total_leave_result);

$total_late_query =
    "SELECT COUNT(*) AS total
FROM attendance
WHERE attendance_date = CURDATE()
AND status='Late'";

$total_late_result =
    mysqli_query($conn, $total_late_query);

$total_late =
    mysqli_fetch_assoc($total_late_result);

$active_nav = 'dashboard';

?>

<!DOCTYPE html>

<html>

<head>

    <title>Dashboard</title>

    <link rel="stylesheet" href="css/style.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">





</head>

<body>


    <div class="main-container">

        <?php include 'includes/navigation.php'; ?>

        <div class="content">

            <div class="topbar">

                <div class="topbar-left">

                    <h2>
                        Dashboard
                    </h2>

                    <input type="text" placeholder="Search here..." class="search-box">

                </div>

                <div class="topbar-right">

                    <a href="notifications.php" class="notification-bell">

                        <i class="fa-solid fa-bell"></i>

                        <span class="notification-count">

                            <?php

                            if ($user['role'] == 'Admin') {

                                $notification_query =
                                    "SELECT COUNT(*) AS total
        FROM leaves
        WHERE status='Pending'";

                            } else {

                                $notification_query =
                                    "SELECT COUNT(*) AS total
    FROM leaves
    WHERE employee_id='$employee_id'
    AND
    (
        status='Approved'
        OR
        status='Rejected'
    )
    AND employee_notification_status='Unread'";

                            }

                            $notification_result =
                                mysqli_query($conn, $notification_query);

                            $notification =
                                mysqli_fetch_assoc($notification_result);

                            echo $notification['total'];

                            ?>

                        </span>

                    </a>

                    <div class="profile-box">

                        Welcome,
                        <?php echo $escape_html($employee_id); ?>

                    </div>

                </div>

            </div>

            <div class="welcome-box">

                <h1>

                    <?php

                    $hour = date("H");

                    if ($hour < 12) {
                        echo "Good Morning";
                    } elseif ($hour < 17) {
                        echo "Good Afternoon";
                    } else {
                        echo "Good Evening";
                    }

                    ?>

                    ,
                    Welcome to CoreAxis CRM Dashboard

                </h1>

                <h3>
                    Logged In Employee ID :
                    <?php echo $escape_html($employee_id); ?>
                </h3>

                <p>
                    Login Successful
                </p>

                <div class="datetime-box">

                    <?php
                    echo date("d M Y");
                    ?>

                    |

                    <span id="live-time"></span>

                </div>

            </div>

            <h2 class="section-title">
                Analytics Overview
            </h2>

            <?php if ($user['role'] == 'Admin') { ?>

                <div class="stats-box">

                    <div class="card">

                        <h2>Total Employees</h2>

                        <p>
                            <?php echo $total_employee['total']; ?>
                        </p>

                    </div>

                    <div class="card">

                        <h2>Today's Attendance</h2>

                        <p>
                            <?php echo $total_attendance['total']; ?>
                        </p>

                    </div>

                    <div class="card">

                        <h2>Today's Leave</h2>

                        <p>
                            <?php echo $total_leave['total']; ?>
                        </p>

                    </div>

                    <div class="card">

                        <h2>Late Employees</h2>

                        <p>
                            <?php echo $total_late['total']; ?>
                        </p>

                    </div>

                </div>

            <?php } ?>

            <?php if ($user['role'] == 'Admin') { ?>

                <h2 class="section-title">
                    Recent Employees
                </h2>

                <div class="recent-table">

                    <table>

                        <tr>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>

                        <?php

                        $recent_query =
                            mysqli_query(
                                $conn,
                                "SELECT * FROM users
ORDER BY id DESC
LIMIT 5"
                            );

                        while (
                            $recent =
                            mysqli_fetch_assoc($recent_query)
                        ) {

                            ?>

                            <tr>

                                <td>
                                    <?php echo $escape_html($recent['employee_id']); ?>
                                </td>

                                <td>
                                    <?php echo $escape_html($recent['full_name']); ?>
                                </td>

                                <td>
                                    <?php echo $escape_html($recent['department']); ?>
                                </td>

                                <td>
                                    <?php echo $escape_html($recent['role']); ?>
                                </td>

                                <td>
                                    <?php echo $escape_html($recent['status']); ?>
                                </td>

                            </tr>

                        <?php } ?>

                    </table>

                </div>

            <?php } ?>

            <h2 class="section-title">
                Quick Actions & Employee Overview
            </h2>

            <div class="dashboard-grid">

                <div class="profile-card">

                    <?php

                    if ($user['role'] == 'Admin') {
                        $profile_image = "images/admin.png";
                    } else {
                        $profile_image = "images/employee.png";
                    }

                    ?>

                    <?php

                    $safe_session_employee_id = preg_match('/^EMP[0-9]{3,17}$/iD', $employee_id)
                        ? $employee_id
                        : '';
                    $image_path = $safe_session_employee_id !== '' ? "images/{$safe_session_employee_id}.jpg" : 'images/default.png';

                    if (!file_exists($image_path)) {

                        if ($user['role'] == 'Admin') {

                            $image_path = "images/admin.png";

                        } else {

                            $image_path = "images/employee.png";
                        }
                    }

                    ?>

                    <img src="<?php echo $escape_html($image_path); ?>" class="profile-image" alt="Profile photo">

                    <h2>
                        Employee Profile
                    </h2>

                    <div class="profile-item">
                        <b>Employee ID :</b>
                        <?php echo $escape_html($employee_id); ?>
                    </div>

                    <div class="profile-item">
                        <b>Full Name :</b>
                        <?php echo $escape_html($user['full_name']); ?>
                    </div>

                    <div class="profile-item">
                        <b>Phone :</b>
                        <?php echo $escape_html($user['phone']); ?>
                    </div>

                    <div class="profile-item">
                        <b>Email :</b>
                        <?php echo $escape_html($user['email']); ?>
                    </div>

                    <div class="profile-item">
                        <b>Status :</b>

                        <span style="
    padding:5px 12px;
    border-radius:20px;
    background:
    <?php
    if ($user['status'] == 'Active') {
        echo '#198754';
    } else {
        echo '#dc3545';
    }
    ?>;
    color:white;
    font-size:13px;
    ">

                            <?php echo $escape_html($user['status']); ?>

                        </span>

                    </div>

                    <div class="profile-item">
                        <b>Department :</b>
                        <?php echo $escape_html($user['department']); ?>
                    </div>

                    <br>

                    <strong>Joining Date :</strong>

                    <?php
                    echo date(
                        'd M Y',
                        strtotime($user['joining_date'])
                    );
                    ?>

                    <div class="profile-item">
                        <b>Role :</b>
                        <?php echo $escape_html($user['role']); ?>
                    </div>

                    <div class="profile-item">
                        <b>Designation :</b>
                        <?php echo $escape_html($user['designation']); ?>
                    </div>

                </div>

                <a href="attendance.php" class="quick-card">

                    <i class="fa-solid fa-calendar-check"></i>

                    <h3>Mark Attendance</h3>

                </a>

                <a href="leave.php" class="quick-card">

                    <i class="fa-solid fa-plane-departure"></i>

                    <h3>Leave Management</h3>

                </a>

                <?php if ($user['role'] != 'Admin') { ?>

                    <a href="view_assigned_shift.php" class="quick-card">

                        <i class="fa-solid fa-clock"></i>

                        <h3>My Shift</h3>

                    </a>

                <?php } ?>

                <?php if ($user['role'] == 'Admin') { ?>

                    <a href="add_employee.php" class="quick-card">

                        <i class="fa-solid fa-user-plus"></i>

                        <h3>Add Employee</h3>

                    </a>

                    <a href="salary.php" class="quick-card">

                        <i class="fa-solid fa-wallet"></i>

                        <h3>Manage Salary</h3>

                    </a>

                    <a href="attendance_report.php" class="quick-card">

                        <i class="fa-solid fa-chart-column"></i>

                        <h3>Attendance Report</h3>

                    </a>

                <?php } ?>

            </div>

            <div class="footer">

                © 2026 CoreAxis CRM System |
                Developed By Atharva Wadgaonkar

            </div>

        </div>

        <script>

            function updateClock() {

                let now = new Date();

                let time =
                    now.toLocaleTimeString();

                document.getElementById(
                    "live-time"
                ).innerHTML = time;
            }

            setInterval(updateClock, 1000);

            updateClock();

        </script>

</body>

</html>
