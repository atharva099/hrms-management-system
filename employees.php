<?php

include 'includes/db.php';
require_once 'includes/auth.php';

$employee_id = auth_require_admin($conn);

$search = "";

if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search = $_GET['search'];

    $query =
        "SELECT * FROM users
    WHERE employee_id LIKE '%$search%'
    OR full_name LIKE '%$search%'
    OR department LIKE '%$search%'";
} else {
    $query = "SELECT * FROM users";
}

$total_query = "SELECT * FROM users";
$total_result = mysqli_query($conn, $total_query);
$total_employees = mysqli_num_rows($total_result);

/* Active Employees */

$active_query =
"SELECT * FROM users
WHERE status='Active'";

$active_result =
mysqli_query($conn,$active_query);

$total_active =
mysqli_num_rows($active_result);

/* Inactive Employees */

$inactive_query =
"SELECT * FROM users
WHERE status='Inactive'";

$inactive_result =
mysqli_query($conn,$inactive_query);

$total_inactive =
mysqli_num_rows($inactive_result);

$result = mysqli_query($conn, $query);

$user_query = "SELECT * FROM users
WHERE employee_id='$employee_id'";

$user_result = mysqli_query($conn, $user_query);

$user = mysqli_fetch_assoc($user_result);

$success_message = "";

if (isset($_GET['success'])) {

    if ($_GET['success'] == "deactivated") {

        $success_message =
        "Employee deactivated successfully.";

    }

    elseif ($_GET['success'] == "activated") {

        $success_message =
        "Employee activated successfully.";

    }
}

if (isset($_GET['error'])) {
    if ($_GET['error'] == "self_deactivate") {
        $success_message =
            "You cannot deactivate your own account.";
    }
}

?>

<!DOCTYPE html>

<html>

<head>

    <title>Employees</title>

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

            <a href="employees.php" class="active">
    <i class="fa-solid fa-users"></i>
    Employees
</a>

<a href="department_master.php">
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

            <!-- TOPBAR -->

            <div class="topbar">

                <div class="topbar-left">
                    <h2>Employees</h2>
                </div>

                <div class="topbar-right">

                    <form method="GET" style="display:flex; gap:10px; align-items:center;">

                        <input type="text" name="search" class="search-box" placeholder="Search employee..."
                            value="<?php echo $search; ?>">

                        <button type="submit" style="width:auto; padding:10px 20px; margin-top:0;">

                            <i class="fa-solid fa-magnifying-glass"></i>

                        </button>

                        <a href="employees.php" style="
background:#ef4444;
color:white;
padding:10px 18px;
border-radius:10px;
text-decoration:none;
font-weight:600;">

                            <i class="fa-solid fa-rotate-left"></i>

                        </a>

                    </form>

                    <div class="profile-box">
                        Welcome,
                        <?php echo $user['employee_id']; ?>
                    </div>

                </div>

            </div>

            <!-- PAGE HEADER -->

            <?php if(!empty($success_message)){ ?>

<div style="
background:#dcfce7;
color:#166534;
padding:12px;
margin-bottom:20px;
border-radius:8px;
font-weight:600;
">

<?php echo $success_message; ?>

</div>

<?php } ?>
            
            <div class="welcome-box">

                <h1>Employee Management</h1>

                <p>
                    Manage employee records, profiles and roles.
                </p>

            </div>

            <!-- ANALYTICS -->

            <div class="stats-box">

                <a href="add_employee.php" class="generate-btn" style="
text-decoration:none;
display:inline-block;
margin-bottom:25px;
width:auto;
padding:14px 24px;">

                    <i class="fa-solid fa-user-plus"></i>
                    Add Employee

                </a>

                <div class="card">
                    <h2>Total Employees</h2>
                    <p>
                        <?php echo $total_employees; ?>
                    </p>
                </div>

                <div class="card">
    <h2>Active Employees</h2>
    <p>
        <?php echo $total_active; ?>
    </p>
</div>

<div class="card">
    <h2>Inactive Employees</h2>
    <p>
        <?php echo $total_inactive; ?>
    </p>
</div>

            </div>

            <!-- EMPLOYEE TABLE -->

            <div class="table-container">

                <table class="attendance-table">

                    <thead>

                        <tr>

                            <th>Image</th>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Designation</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php

                        mysqli_data_seek($result, 0);

                        while ($row = mysqli_fetch_assoc($result)) {

                            $imagePath = "images/" . $row['employee_id'] . ".jpg";

                            if (!file_exists($imagePath)) {
                                $imagePath = "images/default.png";
                            }

                            ?>

                            <tr>

                                <td>

                                    <img src="<?php echo $imagePath; ?>" width="55" height="55" style="
                                 border-radius:50%;
                                 object-fit:cover;
                                 border:3px solid #2563eb;">

                                </td>

                                <td>
                                    <?php echo $row['employee_id']; ?>
                                </td>

                                <td>
                                    <?php echo $row['full_name']; ?>
                                </td>

                                <td>
                                    <?php echo $row['department']; ?>
                                </td>

                                <td>
                                    <?php echo $row['designation']; ?>
                                </td>

                                <td>

                                    <?php
                                    if ($row['role'] == 'Admin') {
                                        echo "<span style='color:#2563eb;font-weight:700;'>Admin</span>";
                                    } else {
                                        echo "<span style='color:#16a34a;font-weight:700;'>Employee</span>";
                                    }
                                    ?>

                                </td>

                                <td>

                                    <?php

                                    if ($row['status'] == 'Active') {
                                        echo "<span style='color:#16a34a;font-weight:700;'>Active</span>";
                                    } else {
                                        echo "<span style='color:#dc2626;font-weight:700;'>Inactive</span>";
                                    }

                                    ?>

                                </td>

                                <td>

                                    <a href="employee_profile.php?id=<?php echo $row['employee_id']; ?>"
                                        class="view-slip-btn">

                                        <i class="fa-solid fa-eye"></i>

                                    </a>

                                    <a href="edit_employee.php?id=<?php echo $row['employee_id']; ?>" class="filter-btn"
                                        style="text-decoration:none;">

                                        <i class="fa-solid fa-pen"></i>

                                    </a>

                                    <?php

if($row['status'] == 'Active')
{

?>

<a href="deactivate_employee.php?id=<?php echo $row['employee_id']; ?>"
class="reset-btn"
style="text-decoration:none;"
onclick="
return confirm(
'Deactivate this employee?\n\nEmployee data, salary records and attendance history will be preserved.'
)
">

<i class="fa-solid fa-user-slash"></i>

</a>

<?php

}
else
{

?>

<a href="activate_employee.php?id=<?php echo $row['employee_id']; ?>"
class="view-slip-btn"
style="text-decoration:none;"
onclick="
return confirm(
'Activate this employee account?'
)
">

<i class="fa-solid fa-user-check"></i>

</a>

<?php

}

?>

                                </td>

                            </tr>

                            <?php

                        }

                        ?>

                    </tbody>

                </table>

            </div>

            <!-- FOOTER -->

            <div class="footer">

                © 2026 CoreAxis CRM System |
                Developed By Atharva Wadgaonkar

            </div>

        </div>

    </div>

</body>

</html>
