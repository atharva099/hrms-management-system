<?php

include 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/payroll_presentation.php';

auth_require_admin($conn);
date_default_timezone_set('Asia/Kolkata');

$escape_report_html = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$salary_periods_result = mysqli_query(
    $conn,
    "SELECT DISTINCT salary_period,
            DATE_FORMAT(salary_period, '%M %Y') AS salary_period_label
     FROM salaries
     WHERE salary_period IS NOT NULL
     ORDER BY salary_period DESC"
);

?>

<!DOCTYPE html>

<html lang="en">

<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Salary Report</title>

    <link rel="stylesheet" href="css/style.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="css/admin-pages.css?v=1">
</head>

<body>

    <div class="main-container">

        <?php $active_nav = 'salary_report'; include __DIR__ . '/includes/navigation.php'; ?>

        <div class="content admin-page" id="main-content">

            <div class="topbar"><a class="admin-dashboard-link" href="dashboard.php">Back to Dashboard</a>

                <div class="topbar-left">

                    <h2>
                        Salary Report
                    </h2>

                </div>

                <div class="topbar-right">

                    <div class="profile-box">

                        Welcome,
                        <?php echo $escape_report_html($_SESSION['employee_id'] ?? ''); ?>

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
                "SELECT COALESCE(SUM(final_salary), 0) AS total_salary,
                        COUNT(DISTINCT employee_id) AS total_employees,
                        COALESCE(SUM(CASE WHEN payment_status='Paid' THEN final_salary ELSE 0 END), 0) AS paid_salary,
                        COUNT(DISTINCT CASE WHEN payment_status='Paid' THEN employee_id END) AS paid_employees,
                        COALESCE(SUM(CASE WHEN payment_status='Pending' THEN final_salary ELSE 0 END), 0) AS pending_salary
                 FROM salaries";

            $total_salary_result =
                mysqli_query($conn, $total_salary_query);

            $total_salary_row =
                mysqli_fetch_assoc($total_salary_result);

            $total_salary =
                $total_salary_row['total_salary'];

            $total_employee_row = $total_salary_row;
            $total_employees = (int) ($total_employee_row['total_employees'] ?? 0);
            $paid_employees = (int) ($total_employee_row['paid_employees'] ?? 0);
            $paid_salary = $total_employee_row['paid_salary'] ?? '0.00';
            $pending_salary = $total_employee_row['pending_salary'] ?? '0.00';

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
COALESCE(
    SUM(
        COALESCE(late_deduction, 0)
        + COALESCE(halfday_deduction, 0)
        + COALESCE(absent_deduction, 0)
    ),
    0
) AS total_deduction
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
                        Employees with Payroll Records
                    </span>

                    <div class="card-value">
                        <?php echo $total_employees; ?>
                    </div>
                </div>

                <div class="card">
                    <span class="card-title">Paid Payroll</span>
                    <div class="card-value">₹ <?php echo number_format($paid_salary, 2); ?></div>
                    <small><?php echo $paid_employees; ?> employee(s) marked paid</small>
                </div>

                <div class="card">
                    <span class="card-title">Pending Payroll</span>
                    <div class="card-value">₹ <?php echo number_format($pending_salary, 2); ?></div>
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
                        <?php while ($salary_period = mysqli_fetch_assoc($salary_periods_result)) { ?>
                            <?php $escaped_salary_period = $escape_report_html($salary_period['salary_period']); ?>
                            <?php $escaped_salary_period_label = $escape_report_html($salary_period['salary_period_label']); ?>
                            <option value="<?php echo $escaped_salary_period; ?>"><?php echo $escaped_salary_period_label; ?></option>
                        <?php } ?>
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
                    <?php echo $escape_report_html($_SESSION['employee_id'] ?? ''); ?>
                </p>

                <hr>

                <p>
                    <strong>Total Payroll :</strong>

                    ₹ <?php echo number_format($total_salary); ?>
                </p>

                <p>
                    <strong>Employees with payroll records :</strong>

                    <?php echo $total_employees; ?>
                </p>

                <hr>

            </div>

            <div class="recent-table">

                <div class="admin-table-scroll" role="region" aria-label="Salary report" tabindex="0"><table class="report-table">

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
                            <th>Payment Status</th>
                            <th>Payment Date</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php

                        $query = "SELECT salaries.*,
        DATE_FORMAT(salaries.salary_period, '%M %Y') AS salary_period_label
FROM salaries
ORDER BY salaries.id DESC";

                        $result = mysqli_query($conn, $query);

                        while ($row = mysqli_fetch_assoc($result)) {
                            $payment_presentation = coreaxis_payroll_payment_presentation(
                                $row['payment_status'] ?? null,
                                $row['payment_date'] ?? null
                            );

                            ?>

                            <tr data-salary-period="<?php echo $escape_report_html($row['salary_period']); ?>">

                                <td>
                                    <?php echo $escape_report_html($row['employee_id']); ?>
                                </td>

                                <td>
                                    <?php echo $escape_report_html($row['salary_period_label']); ?>
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

                                <td><?php echo $escape_report_html($payment_presentation['status']); ?></td>
                                <td><?php echo $escape_report_html($payment_presentation['date']); ?></td>

                            </tr>

                        <?php } ?>

                    </tbody>

                </table></div>

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

                let selectedPeriod =
                    document.getElementById("monthFilter")
                        .value;

                let rows =
                    document.querySelectorAll(
                        ".report-table tbody tr"
                    );

                rows.forEach(function (row) {

                    let period = row.dataset.salaryPeriod;

                    if (
                        selectedPeriod === ""
                        ||
                        period === selectedPeriod
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
