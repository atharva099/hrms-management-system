<?php

session_start();

date_default_timezone_set("Asia/Kolkata");

if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
}

include 'includes/db.php';

$employee_id = $_SESSION['employee_id'];

$user_query = "SELECT * FROM users
WHERE employee_id='$employee_id'";

$user_result = mysqli_query($conn, $user_query);

$user = mysqli_fetch_assoc($user_result);

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

$employee_id = $_SESSION['employee_id'];

$user_query =
    "SELECT * FROM users
WHERE employee_id='$employee_id'";

$user_result =
    mysqli_query($conn, $user_query);

$user =
    mysqli_fetch_assoc($user_result);

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

        <div class="sidebar">

            <h2 style="color:white;">
                CoreAxis CRM
            </h2>

            <a href="dashboard.php" class="active">
                <i class="fa-solid fa-house"></i>
                Dashboard
            </a>

            <a href="attendance.php">
                <i class="fa-solid fa-calendar-check"></i>
                Attendance
            </a>

            <a href="holidays.php">
                <i class="fa-solid fa-plane"></i>
                Holidays
            </a>

            <a href="salary.php">
                <i class="fa-solid fa-money-bill"></i>
                Salary
            </a>

            <?php if ($user['role'] == 'Admin') { ?>

                <a href="employees.php">
                    <i class="fa-solid fa-users"></i>
                    Employees
                </a>

                <a href="add_employee.php">
                    <i class="fa-solid fa-user-plus"></i>
                    Add Employee
                </a>

                <a href="attendance_report.php">
                    <i class="fa-solid fa-chart-column"></i>
                    Attendance Report
                </a>

                <a href="salary_report.php">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    Salary Report
                </a>

                <a href="manage_shift.php">
                    <i class="fa-solid fa-clock"></i>
                    Manage Shift
                </a>

                <a href="assign_shift.php">
                    <i class="fa-solid fa-user-clock"></i>
                    Assign Shift
                </a>

                <a href="view_assigned_shift.php">
                    <i class="fa-solid fa-eye"></i>
                    View Assigned Shift
                </a>

                <a href="manage_leave.php">
                    Manage Leave
                </a>

            <?php } ?>

            <a href="edit_profile.php">
                <i class="fa fa-user-edit"></i> Edit Profile
            </a>

            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                Logout
            </a>

        </div>

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
                        <?php echo $_SESSION['employee_id']; ?>

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
                    <?php echo $_SESSION['employee_id']; ?>
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
                                    <?php echo $recent['employee_id']; ?>
                                </td>

                                <td>
                                    <?php echo $recent['full_name']; ?>
                                </td>

                                <td>
                                    <?php echo $recent['department']; ?>
                                </td>

                                <td>
                                    <?php echo $recent['role']; ?>
                                </td>

                                <td>
                                    <?php echo $recent['status']; ?>
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

                    $image_path =
                        "images/" .
                        $_SESSION['employee_id'] .
                        ".jpg";

                    if (!file_exists($image_path)) {

                        if ($user['role'] == 'Admin') {

                            $image_path = "images/admin.png";

                        } else {

                            $image_path = "images/employee.png";
                        }
                    }

                    ?>

                    <img src="<?php echo $image_path; ?>" class="profile-image">

                    <h2>
                        Employee Profile
                    </h2>

                    <div class="profile-item">
                        <b>Employee ID :</b>
                        <?php echo $_SESSION['employee_id']; ?>
                    </div>

                    <div class="profile-item">
                        <b>Full Name :</b>
                        <?php echo $user['full_name']; ?>
                    </div>

                    <div class="profile-item">
                        <b>Phone :</b>
                        <?php echo $user['phone']; ?>
                    </div>

                    <div class="profile-item">
                        <b>Email :</b>
                        <?php echo $user['email']; ?>
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

                            <?php echo $user['status']; ?>

                        </span>

                    </div>

                    <div class="profile-item">
                        <b>Department :</b>
                        <?php echo $user['department']; ?>
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
                        <?php echo $user['role']; ?>
                    </div>

                    <div class="profile-item">
                        <b>Designation :</b>
                        <?php echo $user['designation']; ?>
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