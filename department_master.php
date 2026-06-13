<?php

session_start();

if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
    exit();
}

include 'includes/db.php';

$employee_id = $_SESSION['employee_id'];

$user_query =
    "SELECT * FROM users
WHERE employee_id='$employee_id'";

$user_result =
    mysqli_query($conn, $user_query);

$user =
    mysqli_fetch_assoc($user_result);

if ($user['role'] != 'Admin') {
    header("Location: dashboard.php");
    exit();
}

/* Total Departments */

$total_department_query =
    "SELECT * FROM departments";

$total_department_result =
    mysqli_query($conn, $total_department_query);

$total_departments =
    mysqli_num_rows($total_department_result);


/* Active Departments */

$active_department_query =
    "SELECT * FROM departments
WHERE status='Active'";

$active_department_result =
    mysqli_query($conn, $active_department_query);

$total_active_departments =
    mysqli_num_rows($active_department_result);


/* Inactive Departments */

$inactive_department_query =
    "SELECT * FROM departments
WHERE status='Inactive'";

$inactive_department_result =
    mysqli_query($conn, $inactive_department_query);

$total_inactive_departments =
    mysqli_num_rows($inactive_department_result);

/* Department List */

$query =
    "SELECT * FROM departments
ORDER BY department_name ASC";

$result =
    mysqli_query($conn, $query);

?>
<!DOCTYPE html>
<html>

<head>

    <title>Department Master</title>

    <link rel="stylesheet" href="css/style.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

</head>

<body>

    <div class="main-container">

        <!-- SIDEBAR -->

        <div class="sidebar">

            <h2>CoreAxis CRM</h2>

            <a href="dashboard.php">
                <i class="fa-solid fa-chart-line"></i>
                Dashboard
            </a>

            <a href="attendance.php">
                <i class="fa-solid fa-calendar-check"></i>
                Attendance
            </a>

            <a href="employees.php">
                <i class="fa-solid fa-users"></i>
                Employees
            </a>

            <a href="department_master.php" class="active">
                <i class="fa-solid fa-building"></i>
                Department Master
            </a>

            <a href="attendance_report.php">
                <i class="fa-solid fa-chart-column"></i>
                Attendance Report
            </a>

            <a href="salary_report.php">
                <i class="fa-solid fa-money-bill-wave"></i>
                Salary Report
            </a>

            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                Logout
            </a>

        </div>

        <!-- CONTENT -->

        <div class="content">

            <?php

            if (isset($_SESSION['success'])) {

                ?>

                <div class="success-message">

                    <?php
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>

                </div>

                <?php

            }

            if (isset($_SESSION['error'])) {

                ?>

                <div class="error-message">

                    <?php
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>

                </div>

                <?php

            }

            ?>

            <div class="topbar">

                <h2>Department Master</h2>

            </div>

            <div class="welcome-box">

                <h1>Department Management</h1>

                <p>
                    Manage company departments.
                </p>

            </div>

            <div class="stats-box">

                <div class="card">

                    <h2>Total Departments</h2>

                    <p>
                        <?php echo $total_departments; ?>
                    </p>

                </div>

                <div class="card">

                    <h2>Active Departments</h2>

                    <p>
                        <?php echo $total_active_departments; ?>
                    </p>

                </div>

                <div class="card">

                    <h2>Inactive Departments</h2>

                    <p>
                        <?php echo $total_inactive_departments; ?>
                    </p>

                </div>

            </div>

            <div class="table-container">

                <h3>Add Department</h3>

                <form method="POST" action="add_department.php">

                    <input type="text" name="department_name" placeholder="Department Name" required>

                    <button type="submit" class="generate-btn">

                        Add Department

                    </button>

                </form>

            </div>

            <br>

            <div class="table-container">

                <table class="attendance-table">

                    <thead>

                        <tr>

                            <th>ID</th>
                            <th>Department Name</th>
                            <th>Status</th>
                            <th>Actions</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php

                        while (
                            $row =
                            mysqli_fetch_assoc($result)
                        ) {

                            ?>

                            <tr>

                                <td>
                                    <?php echo $row['id']; ?>
                                </td>

                                <td>
                                    <?php echo $row['department_name']; ?>
                                </td>

                                <td>

                                    <?php

                                    if ($row['status'] == "Active") {
                                        echo "<span style='color:#16a34a;font-weight:700;'>Active</span>";
                                    } else {
                                        echo "<span style='color:#dc2626;font-weight:700;'>Inactive</span>";
                                    }

                                    ?>

                                </td>

                                <td>

                                    <a href="edit_department.php?id=<?php echo $row['id']; ?>">
                                        Edit
                                    </a>

                                    |

                                    <?php if ($row['status'] == 'Active') { ?>

                                        <a href="deactivate_department.php?id=<?php echo $row['id']; ?>"
                                            onclick="return confirm('Deactivate this department?');">

                                            Deactivate

                                        </a>

                                    <?php } else { ?>

                                        <a href="activate_department.php?id=<?php echo $row['id']; ?>">

                                            Activate

                                        </a>

                                    <?php } ?>

                                </td>

                            </tr>

                            <?php

                        }

                        ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</body>

</html>