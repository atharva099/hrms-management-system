<?php

session_start();

date_default_timezone_set('Asia/Kolkata');

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
    <title>Salary Report</title>

    <link rel="stylesheet" href="css/style.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>

    <div class="main-container">

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

            <a href="attendance_report.php">
                <i class="fa-solid fa-chart-column"></i>
                Attendance Report
            </a>

            <a href="salary_report.php" class="active">
                <i class="fa-solid fa-money-bill-wave"></i>
                Salary Report
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
                        Salary Report
                    </h2>

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
                    Payroll Analytics Dashboard
                </h1>

                <p>
                    Monitor payroll processing,
                    employee salaries,
                    incentives and deductions.
                </p>

            </div>

            <?php

            $total_salary_query =
                "SELECT SUM(final_salary) AS total_salary
FROM salaries";

            $total_salary_result =
                mysqli_query($conn, $total_salary_query);

            $total_salary_row =
                mysqli_fetch_assoc($total_salary_result);

            $total_salary =
                $total_salary_row['total_salary'];

            $total_employee_query =
                "SELECT COUNT(DISTINCT employee_id) AS total_employees
FROM salaries";

            $total_employee_result =
                mysqli_query($conn, $total_employee_query);

            $total_employee_row =
                mysqli_fetch_assoc($total_employee_result);

            $total_employees =
                $total_employee_row['total_employees'];

            $total_incentive_query =
                "SELECT SUM(incentive)
AS total_incentive
FROM salaries";

            $total_incentive_result =
                mysqli_query(
                    $conn,
                    $total_incentive_query
                );

            $total_incentive_row =
                mysqli_fetch_assoc(
                    $total_incentive_result
                );

            $total_incentive =
                $total_incentive_row['total_incentive'];

            $total_deduction_query =
                "SELECT
SUM(
late_deduction
+
halfday_deduction
+
absent_deduction
)
AS total_deduction
FROM salaries";

            $total_deduction_result =
                mysqli_query(
                    $conn,
                    $total_deduction_query
                );

            $total_deduction_row =
                mysqli_fetch_assoc(
                    $total_deduction_result
                );

            $total_deduction =
                $total_deduction_row['total_deduction'];

            ?>

            <h2 class="section-title">
                Payroll Analytics Overview
            </h2>

            <div class="stats-box">

                <div class="card">
                    <span class="card-title">
                        Total Payroll
                    </span>

                    <div class="card-value">
                        ₹ <?php echo number_format($total_salary); ?>
                    </div>
                </div>

                <div class="card">
                    <span class="card-title">
                        Total Employees
                    </span>

                    <div class="card-value">
                        <?php echo $total_employees; ?>
                    </div>
                </div>

                <div class="card">

                    <span class="card-title">
                        Total Incentives
                    </span>

                    <div class="card-value">

                        ₹
                        <?php echo number_format($total_incentive); ?>

                    </div>

                </div>

                <div class="card">

                    <span class="card-title">
                        Total Deductions
                    </span>

                    <div class="card-value">

                        ₹
                        <?php echo number_format($total_deduction); ?>

                    </div>

                </div>

            </div>

            <div class="filter-box">

                <div class="filter-form">

                    <input type="text" id="searchInput" placeholder="Search Employee ID">

                    <select id="monthFilter" onchange="filterMonth()">
                        <option value="">All Months</option>
                        <option value="April 2026">April 2026</option>
                        <option value="May 2026">May 2026</option>
                    </select>

                    <button type="button" onclick="window.print()" class="filter-btn">

                        <i class="fa fa-print"></i>
                        Print Report

                    </button>

                </div>

            </div>

            <div class="print-header">

                <h1>CoreAxis CRM</h1>

                <h2>Payroll Report</h2>

                <p>
                    Generated On :
                    <?php echo date("d M Y, h:i A"); ?>
                </p>

                <p>
                    Generated By :
                    <?php echo $_SESSION['employee_id']; ?>
                </p>

                <hr>

                <p>
                    <strong>Total Payroll :</strong>

                    ₹ <?php echo number_format($total_salary); ?>
                </p>

                <p>
                    <strong>Employees Paid :</strong>

                    <?php echo $total_employees; ?>
                </p>

                <hr>

            </div>

            <div class="recent-table">

                <table class="report-table">

                    <thead>

                        <tr>

                            <th>Employee ID</th>
                            <th>Month</th>
                            <th>Basic Salary</th>
                            <th>Incentive</th>
                            <th>Late Deduction</th>
                            <th>Half Day Deduction</th>
                            <th>Absent Deduction</th>
                            <th>Final Salary</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php

                        $query = "SELECT * FROM salaries
ORDER BY id DESC";

                        $result = mysqli_query($conn, $query);

                        while ($row = mysqli_fetch_assoc($result)) {

                            ?>

                            <tr>

                                <td>
                                    <?php echo $row['employee_id']; ?>
                                </td>

                                <td>
                                    <?php echo $row['salary_month']; ?>
                                </td>

                                <td>
                                    ₹ <?php echo number_format($row['basic_salary'], 2); ?>
                                </td>

                                <td>
                                    ₹ <?php echo number_format($row['incentive'], 2); ?>
                                </td>

                                <td>
                                    <?php echo number_format($row['late_deduction'], 2); ?>
                                </td>

                                <td>
                                    <?php echo number_format($row['halfday_deduction'], 2); ?>
                                </td>

                                <td>
                                    <?php echo number_format($row['absent_deduction'], 2); ?>
                                </td>

                                <td>
                                    <?php echo number_format($row['final_salary'], 2); ?>
                                </td>

                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>

            <br><br>

        </div>

        <script>

            document.getElementById("searchInput")
                .addEventListener("keyup", function () {

                    let value = this.value.toLowerCase();

                    let rows =
                        document.querySelectorAll(
                            ".report-table tbody tr"
                        );

                    rows.forEach(function (row) {

                        row.style.display =
                            row.innerText
                                .toLowerCase()
                                .includes(value)
                                ? ""
                                : "none";

                    });

                });

            function filterMonth() {

                let selectedMonth =
                    document.getElementById("monthFilter")
                        .value
                        .toLowerCase();

                let rows =
                    document.querySelectorAll(
                        ".report-table tbody tr"
                    );

                rows.forEach(function (row) {

                    let month =
                        row.cells[1]
                            .innerText
                            .toLowerCase();

                    if (
                        selectedMonth === ""
                        ||
                        month === selectedMonth
                    ) {
                        row.style.display = "";
                    }
                    else {
                        row.style.display = "none";
                    }

                });

            }

        </script>

        <div class="print-footer">

            ©
            <?php echo date("Y"); ?>

            CoreAxis CRM

        </div>

</body>

</html>