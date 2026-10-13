<?php

include 'includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance_time.php';
auth_require_admin($conn);
$employee_id = auth_current_employee_id();
$attendance_timestamp_schema_ready = attendance_has_datetime_columns($conn);

$user_statement = mysqli_prepare(
    $conn,
    'SELECT role FROM users WHERE employee_id = ? LIMIT 1'
);
mysqli_stmt_bind_param($user_statement, 's', $employee_id);
mysqli_stmt_execute($user_statement);
$user_result = mysqli_stmt_get_result($user_statement);
mysqli_stmt_close($user_statement);

$user = $user_result ? mysqli_fetch_assoc($user_result) : null;

if (!$user || $user['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

function attendance_report_is_valid_date($date)
{
    if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)) {
        return false;
    }

    $date_value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $date_errors = DateTimeImmutable::getLastErrors();

    return $date_value !== false
        && ($date_errors === false || ($date_errors['warning_count'] === 0 && $date_errors['error_count'] === 0))
        && $date_value->format('Y-m-d') === $date
        && (int) $date_value->format('Y') >= 1000;
}

$has_any_filter_parameter = isset($_GET['employee_id'])
    || isset($_GET['status'])
    || isset($_GET['from_date'])
    || isset($_GET['to_date']);

$filter_employee_id = $_GET['employee_id'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_from_date = $_GET['from_date'] ?? '';
$filter_to_date = $_GET['to_date'] ?? '';

foreach ([$filter_employee_id, $filter_status, $filter_from_date, $filter_to_date] as $filter_value) {
    if (!is_string($filter_value)) {
        http_response_code(400);
        exit('Invalid attendance filter.');
    }
}

if (
    $filter_employee_id !== ''
    && (
        !preg_match('/^EMP[0-9]{1,17}$/iD', $filter_employee_id)
        || preg_match('/^EMP0+$/iD', $filter_employee_id)
    )
) {
    http_response_code(400);
    exit('Invalid employee ID filter.');
}

$allowed_statuses = [
    'Present',
    'Absent',
    'Leave',
    'Late',
    'Half Day',
    'Late Half Day',
    'Sunday Working'
];
if ($filter_status !== '' && !in_array($filter_status, $allowed_statuses, true)) {
    http_response_code(400);
    exit('Invalid status filter.');
}

if (
    ($filter_from_date !== '' && !attendance_report_is_valid_date($filter_from_date))
    || ($filter_to_date !== '' && !attendance_report_is_valid_date($filter_to_date))
) {
    http_response_code(400);
    exit('Invalid date filter.');
}

if ($filter_from_date !== '' && $filter_to_date !== '' && $filter_from_date > $filter_to_date) {
    http_response_code(400);
    exit('Invalid date range.');
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
                        <?php echo htmlspecialchars($employee_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>

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

            $late_half_day_count = mysqli_num_rows(mysqli_query(
                $conn,
                "SELECT DISTINCT employee_id FROM attendance
    WHERE status='Late Half Day'
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
                    <h6>Late Half Day</h6>
                    <h3 class="text-warning">
                        <?php echo $late_half_day_count; ?>
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
                        value="<?php echo htmlspecialchars($filter_employee_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

                    <select name="status">

                        <option value="">All Status</option>

                        <option value="Present" <?php if ($filter_status === 'Present')
                            echo 'selected'; ?>>
                            Present
                        </option>

                        <option value="Absent" <?php if ($filter_status === 'Absent')
                            echo 'selected'; ?>>
                            Absent
                        </option>

                        <option value="Leave" <?php if ($filter_status === 'Leave')
                            echo 'selected'; ?>>
                            Leave
                        </option>

                        <option value="Late" <?php if ($filter_status === 'Late')
                            echo 'selected'; ?>>
                            Late
                        </option>

                        <option value="Half Day" <?php if ($filter_status === 'Half Day')
                            echo 'selected'; ?>>
                            Half Day
                        </option>

                        <option value="Late Half Day" <?php if ($filter_status === 'Late Half Day')
                            echo 'selected'; ?>>
                            Late Half Day
                        </option>

                        <option value="Sunday Working" <?php if ($filter_status === 'Sunday Working')
                            echo 'selected'; ?>>
                            Sunday Working
                        </option>

                    </select>

                    <input type="date" name="from_date"
                        value="<?php echo htmlspecialchars($filter_from_date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

                    <input type="date" name="to_date"
                        value="<?php echo htmlspecialchars($filter_to_date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

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

                        $query = 'SELECT * FROM attendance WHERE 1';
                        $parameter_types = '';
                        $query_parameters = [];

                        if (!$has_any_filter_parameter) {
                            $query .= ' AND attendance_date = ?';
                            $parameter_types .= 's';
                            $query_parameters[] = date('Y-m-d');
                        }

                        if ($filter_employee_id !== '') {
                            $query .= ' AND employee_id = ?';
                            $parameter_types .= 's';
                            $query_parameters[] = $filter_employee_id;
                        }

                        if ($filter_status !== '') {
                            $query .= ' AND status = ?';
                            $parameter_types .= 's';
                            $query_parameters[] = $filter_status;
                        }

                        if ($filter_from_date !== '') {
                            $query .= ' AND attendance_date >= ?';
                            $parameter_types .= 's';
                            $query_parameters[] = $filter_from_date;
                        }

                        if ($filter_to_date !== '') {
                            $query .= ' AND attendance_date <= ?';
                            $parameter_types .= 's';
                            $query_parameters[] = $filter_to_date;
                        }

                        $query .= ' ORDER BY attendance_date DESC';

                        $attendance_statement = mysqli_prepare($conn, $query);
                        if ($attendance_statement === false) {
                            http_response_code(500);
                            exit('Unable to load attendance report.');
                        }

                        if ($query_parameters) {
                            $bind_arguments = [$attendance_statement, $parameter_types];
                            foreach (array_keys($query_parameters) as $parameter_index) {
                                $bind_arguments[] = &$query_parameters[$parameter_index];
                            }

                            if (!call_user_func_array('mysqli_stmt_bind_param', $bind_arguments)) {
                                mysqli_stmt_close($attendance_statement);
                                http_response_code(500);
                                exit('Unable to load attendance report.');
                            }
                        }

                        if (!mysqli_stmt_execute($attendance_statement)) {
                            mysqli_stmt_close($attendance_statement);
                            http_response_code(500);
                            exit('Unable to load attendance report.');
                        }

                        $result = mysqli_stmt_get_result($attendance_statement);
                        mysqli_stmt_close($attendance_statement);
                        if ($result === false) {
                            http_response_code(500);
                            exit('Unable to load attendance report.');
                        }

                        while ($row = mysqli_fetch_assoc($result)) {

                            ?>
                            <tr>

                                <td>
                                    <?php echo htmlspecialchars((string) $row['employee_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars((string) $row['attendance_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars((string) (($attendance_timestamp_schema_ready && !empty($row['check_in_at'])) ? $row['check_in_at'] : $row['check_in']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars((string) (($attendance_timestamp_schema_ready && !empty($row['check_out_at'])) ? $row['check_out_at'] : $row['check_out']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </td>

                                <td>

                                    <?php

                                    $status = (string) ($row['status'] ?? '');

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

                                    } elseif ($status == 'Late Half Day') {

                                        echo "<span class='badge bg-warning text-dark'>Late Half Day</span>";

                                    } elseif ($status == 'Sunday Working') {

                                        echo "<span class='badge bg-info text-dark'>Sunday Working</span>";

                                    } else {

                                        echo "<span class='badge bg-dark'>" . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</span>";

                                    }

                                    ?>

                                </td>

                                <td>
                                    <?php echo htmlspecialchars((string) $row['working_hours'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
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
