<?php

session_start();

if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
}

include 'includes/db.php';

$employee_id = $_SESSION['employee_id'];

$user_query = "SELECT * FROM users
WHERE employee_id='$employee_id'";

$user_result = mysqli_query($conn, $user_query);

$user = mysqli_fetch_assoc($user_result);

if ($user['role'] != 'Admin') {
    header("Location: dashboard.php");
}

?>

<!DOCTYPE html>

<html>

<head>

    <title>Attendance Report</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</head>

<body>

    <div class="main-container">

        <div class="sidebar">

            <h2 style="color:white;">
                CoreAxis CRM
            </h2>

            <a href="dashboard.php">
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

            <a href="employees.php">
                <i class="fa-solid fa-users"></i>
                Employees
            </a>

            <a href="add_employee.php">
                <i class="fa-solid fa-user-plus"></i>
                Add Employee
            </a>

            <a href="attendance_report.php" class="active">
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

            <a href="edit_profile.php">
                <i class="fa fa-user-edit"></i>
                Edit Profile
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
                        Attendance Report
                    </h2>

                    <input type="text" placeholder="Search here..." class="search-box">

                </div>

                <div class="topbar-right">

                    <div class="profile-box">

                        Welcome,
                        <?php echo $_SESSION['employee_id']; ?>

                    </div>

                </div>

            </div>

            <div class="welcome-box">

                <h1>
                    Attendance Analytics Dashboard
                </h1>

                <p>
                    Monitor employee attendance, leave and work tracking.
                </p>

            </div>

            <h2 class="section-title">
                Attendance Analytics Overview
            </h2>

            <?php

            $today = date('Y-m-d');

            $present_count = mysqli_num_rows(mysqli_query(
                $conn,
                "SELECT DISTINCT employee_id FROM attendance 
    WHERE status='Present'
    AND attendance_date='$today'"
            ));

            $absent_count = mysqli_num_rows(mysqli_query(
                $conn,
                "SELECT DISTINCT employee_id FROM attendance 
    WHERE status='Absent'
    AND attendance_date='$today'"
            ));

            $leave_count = mysqli_num_rows(mysqli_query(
                $conn,
                "SELECT DISTINCT employee_id FROM attendance 
    WHERE status='Leave'
    AND attendance_date='$today'"
            ));

            $late_count = mysqli_num_rows(mysqli_query(
                $conn,
                "SELECT DISTINCT employee_id FROM attendance 
    WHERE status='Late'
    AND attendance_date='$today'"
            ));

            $sunday_working_count = mysqli_num_rows(mysqli_query(
                $conn,
                "SELECT DISTINCT employee_id FROM attendance
    WHERE status='Sunday Working'
    AND attendance_date='$today'"
            ));

            $half_day_count = mysqli_num_rows(mysqli_query(
                $conn,
                "SELECT DISTINCT employee_id FROM attendance
    WHERE status='Half Day'
    AND attendance_date='$today'"
            ));

            ?>

            <div class="stats-box">

                <div class="card">
                    <h6>Total Present</h6>
                    <h3 class="text-success">
                        <?php echo $present_count; ?>
                    </h3>
                </div>

                <div class="card">
                    <h6>Total Absent</h6>
                    <h3 class="text-danger">
                        <?php echo $absent_count; ?>
                    </h3>
                </div>

                <div class="card">
                    <h6>Total Leave</h6>
                    <h3 class="text-primary">
                        <?php echo $leave_count; ?>
                    </h3>
                </div>

                <div class="card">
                    <h6>Late Employees</h6>
                    <h3 class="text-warning">
                        <?php echo $late_count; ?>
                    </h3>
                </div>

                <div class="card">
                    <h6>Half Day</h6>
                    <h3 class="text-secondary">
                        <?php echo $half_day_count; ?>
                    </h3>
                </div>

                <div class="card">
                    <h6>Sunday Working</h6>
                    <h3 class="text-info">
                        <?php echo $sunday_working_count; ?>
                    </h3>
                </div>

            </div>

            <div class="filter-box">

                <form method="GET" class="filter-form">

                    <input type="text" name="employee_id" placeholder="Employee ID"
                        value="<?php echo isset($_GET['employee_id']) ? $_GET['employee_id'] : ''; ?>">

                    <select name="status">

                        <option value="">All Status</option>

                        <option value="Present" <?php if (isset($_GET['status']) && $_GET['status'] == 'Present')
                            echo 'selected'; ?>>
                            Present
                        </option>

                        <option value="Absent" <?php if (isset($_GET['status']) && $_GET['status'] == 'Absent')
                            echo 'selected'; ?>>
                            Absent
                        </option>

                        <option value="Leave" <?php if (isset($_GET['status']) && $_GET['status'] == 'Leave')
                            echo 'selected'; ?>>
                            Leave
                        </option>

                        <option value="Late" <?php if (isset($_GET['status']) && $_GET['status'] == 'Late')
                            echo 'selected'; ?>>
                            Late
                        </option>

                        <option value="Half Day" <?php if (isset($_GET['status']) && $_GET['status'] == 'Half Day')
                            echo 'selected'; ?>>
                            Half Day
                        </option>

                        <option value="Sunday Working" <?php if (isset($_GET['status']) && $_GET['status'] == 'Sunday Working')
                            echo 'selected'; ?>>
                            Sunday Working
                        </option>

                    </select>

                    <input type="date" name="from_date"
                        value="<?php echo isset($_GET['from_date']) ? $_GET['from_date'] : ''; ?>">

                    <input type="date" name="to_date"
                        value="<?php echo isset($_GET['to_date']) ? $_GET['to_date'] : ''; ?>">

                    <button type="submit" class="filter-btn">
                        Filter
                    </button>

                    <a href="attendance_report.php" class="reset-btn">
                        Reset
                    </a>

                </form>

            </div>

            <div class="table-container">

                <div class="table-responsive">

                    <table class="attendance-table">

                        <tr>

                            <th>Employee ID</th>
                            <th>Date</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Status</th>
                            <th>Working Hours</th>

                        </tr>

                        <?php

                        $today = date('Y-m-d');

                        $query = "SELECT * FROM attendance WHERE 1";

                        if (
                            !isset($_GET['employee_id']) &&
                            !isset($_GET['status']) &&
                            !isset($_GET['from_date']) &&
                            !isset($_GET['to_date'])
                        ) {

                            $query .= " AND attendance_date='$today'";
                        }

                        if (isset($_GET['employee_id']) && $_GET['employee_id'] != '') {

                            $employee_id_filter = $_GET['employee_id'];

                            $query .= " AND employee_id='$employee_id_filter'";
                        }

                        if (isset($_GET['status']) && $_GET['status'] != '') {

                            $status_filter = $_GET['status'];

                            $query .= " AND status='$status_filter'";
                        }

                        if (isset($_GET['from_date']) && $_GET['from_date'] != '') {

                            $from_date = $_GET['from_date'];

                            $query .= " AND attendance_date >= '$from_date'";
                        }

                        if (isset($_GET['to_date']) && $_GET['to_date'] != '') {

                            $to_date = $_GET['to_date'];

                            $query .= " AND attendance_date <= '$to_date'";
                        }

                        $query .= " ORDER BY attendance_date DESC";

                        $result = mysqli_query($conn, $query);

                        while ($row = mysqli_fetch_assoc($result)) {

                            ?>
                            <tr>

                                <td>
                                    <?php echo $row['employee_id']; ?>
                                </td>

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

                                    <?php

                                    $status = $row['status'];

                                    if ($status == 'Present') {

                                        echo "<span class='badge bg-success'>Present</span>";

                                    } elseif ($status == 'Absent') {

                                        echo "<span class='badge bg-danger'>Absent</span>";

                                    } elseif ($status == 'Leave') {

                                        echo "<span class='badge bg-primary'>Leave</span>";

                                    } elseif ($status == 'Late') {

                                        echo "<span class='badge bg-warning text-dark'>Late</span>";

                                    } elseif ($status == 'Half Day') {

                                        echo "<span class='badge bg-secondary'>Half Day</span>";

                                    } elseif ($status == 'Sunday Working') {

                                        echo "<span class='badge bg-info text-dark'>Sunday Working</span>";

                                    } else {

                                        echo "<span class='badge bg-dark'>" . htmlspecialchars($status) . "</span>";

                                    }

                                    ?>

                                </td>

                                <td>
                                    <?php echo $row['working_hours']; ?>
                                </td>

                            </tr>

                        <?php } ?>

                    </table>

                </div>

            </div>

            <div class="footer">

                © 2026 CoreAxis CRM System |
                Developed By Atharva Wadgaonkar

            </div>

        </div>



    </div>
    </div>

</body>

</html>