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

$is_admin = ($user['role'] == 'Admin');

if (
    $is_admin &&
    isset($_POST['process_payroll'])
) {

    $process_month =
        $_POST['process_month'];

    $check_pending_query = "
    SELECT COUNT(*) AS total
    FROM salaries
    WHERE salary_month='$process_month'
    AND payment_status='Pending'
    ";

    $check_pending_result =
        mysqli_query(
            $conn,
            $check_pending_query
        );

    $check_pending =
        mysqli_fetch_assoc(
            $check_pending_result
        );

    if ($check_pending['total'] > 0) {

        mysqli_query(
            $conn,
            "UPDATE salaries
            SET payment_status='Paid',
                payment_date=CURDATE()
            WHERE salary_month='$process_month'
            AND payment_status='Pending'"
        );

        header(
            "Location: salary.php?success=payroll_processed"
        );

        exit();

    } else {

        $error_message =
            "No pending payroll found for selected month.";

    }
}


if (isset($_POST['generate_salary'])) {

    $employee_id = $_POST['employee_id'];

    $salary_month = $_POST['salary_month'];

    $basic_salary = $_POST['basic_salary'];

    $incentive = $_POST['incentive'];

    $month_number = date('m', strtotime($salary_month));
    $year_number = date('Y');

    $month_filter = $year_number . "-" . $month_number;

    $late_query = "SELECT COUNT(*) AS total_late

FROM attendance

WHERE employee_id='$employee_id'

AND status='Late'

AND attendance_date LIKE '$month_filter%'";

    $late_result = mysqli_query($conn, $late_query);

    $late_row = mysqli_fetch_assoc($late_result);

    $total_late = $late_row['total_late'];



    $halfday_query = "SELECT COUNT(*) AS total_halfday

FROM attendance

WHERE employee_id='$employee_id'

AND status='Half Day'

AND attendance_date LIKE '$month_filter%'";

    $halfday_result = mysqli_query($conn, $halfday_query);

    $halfday_row = mysqli_fetch_assoc($halfday_result);

    $total_halfday = $halfday_row['total_halfday'];



    $absent_query = "SELECT COUNT(*) AS total_absent

FROM attendance

WHERE employee_id='$employee_id'

AND status='Absent'

AND attendance_date LIKE '$month_filter%'";

    $absent_result = mysqli_query($conn, $absent_query);

    $absent_row = mysqli_fetch_assoc($absent_result);

    $total_absent = $absent_row['total_absent'];



    $late_deduction = $total_late * 100;

    $halfday_deduction = $total_halfday * 500;

    $absent_deduction = $total_absent * 1000;

    $final_salary =
        ($basic_salary + $incentive)

        - (

            $late_deduction

            + $halfday_deduction

            + $absent_deduction

        );

    $check_query = "

SELECT *

FROM salaries

WHERE employee_id='$employee_id'

AND salary_month='$salary_month'

";

    $check_result = mysqli_query($conn, $check_query);

    if (mysqli_num_rows($check_result) > 0) {

        $error_message =
            "Salary already generated for this employee and month";

    } else {

        $query = "INSERT INTO salaries
(
employee_id,
salary_month,
basic_salary,
incentive,
late_deduction,
halfday_deduction,
absent_deduction,
final_salary,
payment_status
)
VALUES
(
'$employee_id',
'$salary_month',
'$basic_salary',
'$incentive',
'$late_deduction',
'$halfday_deduction',
'$absent_deduction',
'$final_salary',
'Pending'
)";

        mysqli_query($conn, $query);

        echo "
    <div class='salary-success-alert'>
    Salary Generated Successfully
    </div>";
    }

}

?>

<!DOCTYPE html>
<html>

<head>

    <title>Salary Management</title>

    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">

</head>

<?php

if ($is_admin) {

    $query = "

    SELECT salaries.*,
    users.full_name

    FROM salaries

    LEFT JOIN users
    ON salaries.employee_id = users.employee_id

    ORDER BY salaries.id DESC

    ";

} else {

    $query = "

    SELECT salaries.*,
    users.full_name

    FROM salaries

    LEFT JOIN users
    ON salaries.employee_id = users.employee_id

    WHERE salaries.employee_id='$employee_id'

    ORDER BY salaries.id DESC

    ";
}

$result = mysqli_query($conn, $query);

$total_records = mysqli_num_rows($result);

/* Total Generated Salaries */

$total_salary_query = "
SELECT COUNT(*) AS total_salaries
FROM salaries
";

$total_salary_result =
    mysqli_query($conn, $total_salary_query);

$total_salary =
    mysqli_fetch_assoc($total_salary_result);




/* Total Payroll Processed */

$payroll_query = "
SELECT SUM(final_salary) AS total_payroll
FROM salaries
";

$payroll_result =
    mysqli_query($conn, $payroll_query);

$payroll =
    mysqli_fetch_assoc($payroll_result);




/* Employees Paid */

$paid_query = "
SELECT COUNT(DISTINCT employee_id)
AS total_employees
FROM salaries
";

$paid_result =
    mysqli_query($conn, $paid_query);

$paid =
    mysqli_fetch_assoc($paid_result);

/* Pending Payments */

$pending_query = "
SELECT COUNT(*) AS total_pending
FROM salaries
WHERE payment_status='Pending'
";

$pending_result =
    mysqli_query($conn, $pending_query);

$pending =
    mysqli_fetch_assoc($pending_result);



/* Current Month Payroll */

$current_month = date('F Y');

$current_month_query = "
SELECT SUM(final_salary) AS month_payroll
FROM salaries
WHERE salary_month='$current_month'
";

$current_month_result =
    mysqli_query($conn, $current_month_query);

$current_month_payroll =
    mysqli_fetch_assoc($current_month_result);

?>

<body style="background:#f3f4f6;">

    <?php if (isset($error_message)) { ?>

        <div style="
background:#fee2e2;
color:#991b1b;
padding:12px;
margin:20px;
border-radius:8px;
font-weight:600;
text-align:center;
">

            <?php echo $error_message; ?>

        </div>

    <?php } ?>

    <?php if (isset($_GET['success'])) { ?>

        <div style="
background:#dcfce7;
color:#166534;
padding:12px;
margin:20px;
border-radius:8px;
font-weight:600;
text-align:center;
">

            <?php

            if ($_GET['success'] == "payroll_processed") {
                echo "✓ Payroll processed successfully";
            }

            ?>

        </div>

    <?php } ?>

    <div class="salary-container">

        <div class="back-btn-box">
            <a href="dashboard.php" class="back-dashboard-btn">
                ← Back to Dashboard
            </a>
        </div>

        <div class="salary-hero">

            <h1>
                <?php
                echo $is_admin
                    ? "Payroll Management Dashboard"
                    : "My Salary Dashboard";
                ?>
            </h1>

            <p>
                <?php
                echo $is_admin
                    ? "Manage payroll processing, employee salary generation, payments and salary slips."
                    : "View your salary records, payment history and download salary slips.";
                ?>
            </p>

        </div>

        <div class="salary-dashboard-cards">

            <?php if ($is_admin) { ?>

                <div class="salary-stat-card">

                    <h4>Generated Salaries</h4>

                    <h2>
                        <?php echo $total_salary['total_salaries']; ?>
                    </h2>

                </div>



                <div class="salary-stat-card">

                    <h4>Payroll Processed</h4>

                    <h2>

                        ₹
                        <?php

                        echo number_format(
                            $payroll['total_payroll']
                        );

                        ?>

                    </h2>

                </div>



                <div class="salary-stat-card">

                    <h4>Employees Paid</h4>

                    <h2>

                        <?php
                        echo $paid['total_employees'];
                        ?>

                    </h2>

                </div>

                <div class="salary-stat-card">

                    <h4>Pending Payments</h4>

                    <h2>

                        <?php
                        echo $pending['total_pending'];
                        ?>

                    </h2>

                </div>

                <div class="salary-stat-card">

                    <h4>Current Month Payroll</h4>

                    <h2>

                        ₹
                        <?php
                        echo number_format(
                            $current_month_payroll['month_payroll']
                            ?? 0
                        );
                        ?>

                    </h2>

                </div>

            <?php } ?>

            <?php if ($is_admin) { ?>



            <?php } ?>

        </div>

        <?php if ($is_admin) { ?>

            <h2 class="salary-section-title">
                Payroll Processing
            </h2>

            <div class="salary-card payroll-form-card">

                <h2 style="margin-bottom:25px;">
                    Generate Salary
                </h2>

                <form method="POST" class="salary-form">

                    <select name="employee_id" id="employee_id" required>

                        <option value="">
                            Select Employee
                        </option>

                        <?php

                        $emp_query =
                            mysqli_query(
                                $conn,
                                "SELECT * FROM users
                 WHERE role='Employee'
                 AND status='Active'"
                            );

                        while (
                            $emp =
                            mysqli_fetch_assoc($emp_query)
                        ) {

                            ?>

                            <option value="<?php echo $emp['employee_id']; ?>">

                                <?php
                                echo $emp['employee_id']
                                    . " - "
                                    . $emp['full_name'];
                                ?>

                            </option>

                        <?php } ?>

                    </select>

                    <select name="salary_month" required>

                        <option value="">
                            Select Month
                        </option>

                        <option value="January <?php echo date('Y'); ?>">January
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="February <?php echo date('Y'); ?>">February
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="March <?php echo date('Y'); ?>">March
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="April <?php echo date('Y'); ?>">April
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="May <?php echo date('Y'); ?>">May
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="June <?php echo date('Y'); ?>">June
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="July <?php echo date('Y'); ?>">July
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="August <?php echo date('Y'); ?>">August
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="September <?php echo date('Y'); ?>">September
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="October <?php echo date('Y'); ?>">October
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="November <?php echo date('Y'); ?>">November
                            <?php echo date('Y'); ?>
                        </option>
                        <option value="December <?php echo date('Y'); ?>">December
                            <?php echo date('Y'); ?>
                        </option>

                    </select>

                    <input type="number" id="basic_salary" name="basic_salary" placeholder="Basic Salary" readonly>

                    <input type="number" id="incentive" name="incentive" placeholder="Incentive" readonly>

                    <button type="submit" name="generate_salary">

                        Generate Salary

                    </button>

                </form>

            </div>

            <div class="salary-card payroll-form-card">

                <?php

                $pending_release_query = "
SELECT COUNT(*) AS total_pending_release
FROM salaries
WHERE payment_status='Pending'
";

                $pending_release_result =
                    mysqli_query(
                        $conn,
                        $pending_release_query
                    );

                $pending_release =
                    mysqli_fetch_assoc(
                        $pending_release_result
                    );

                ?>

                <h2 style="margin-bottom:15px;">
                    Payroll Release
                </h2>

                <p style="
margin-bottom:20px;
font-size:15px;
font-weight:600;
color:#dc2626;
">

                    Pending Payroll Records :
                    <?php
                    echo $pending_release['total_pending_release'];
                    ?>

                </p>


                <form method="POST">

                    <label style="
display:block;
margin-bottom:10px;
font-weight:600;
color:#374151;
">

                        Select Payroll Month

                    </label>

                    <select name="process_month" class="salary-month-release" required>

                        <option value="">
                            Select Month
                        </option>

                        <?php

                        $month_query =
                            mysqli_query(
                                $conn,
                                "SELECT DISTINCT salary_month
                     FROM salaries
                     ORDER BY id DESC"
                            );

                        while (
                            $month =
                            mysqli_fetch_assoc($month_query)
                        ) {

                            ?>

                            <option value="<?php echo $month['salary_month']; ?>">

                                <?php echo $month['salary_month']; ?>

                            </option>

                        <?php } ?>

                    </select>

                    <button type="submit" name="process_payroll" <?php
                    if ($pending_release['total_pending_release'] == 0) {
                        echo "disabled";
                    }
                    ?> onclick="return confirm('Process payroll for selected month?');">

                        <?php

                        if ($pending_release['total_pending_release'] == 0) {
                            echo "No Pending Payroll";
                        } else {
                            echo "Process Payroll";
                        }

                        ?>

                    </button>

                </form>

            </div>
        <?php } ?>

        <div class="salary-card">

            <div class="salary-filters">

                <div>

                    <h2 class="salary-section-title">

                        <?php
                        echo $is_admin
                            ? "Generated Salary Records"
                            : "My Salary Records";
                        ?>

                        <span id="recordCounter">

                            (<?php echo $total_records; ?> Records)

                        </span>

                    </h2>

                </div>

                <div class="salary-filter-right">

                    <?php if ($is_admin) { ?>

                        <input type="text" id="salarySearch" placeholder="Search Employee ID or Name..."
                            class="salary-search">

                    <?php } ?>

                    <select id="monthFilter" class="salary-month-filter">

                        <option value="">All Months</option>

                        <?php

                        $month_query =
                            "SELECT DISTINCT salary_month
         FROM salaries
         ORDER BY id DESC";

                        $month_result =
                            mysqli_query($conn, $month_query);

                        while (
                            $month_row =
                            mysqli_fetch_assoc($month_result)
                        ) {

                            ?>

                            <option value="<?php echo $month_row['salary_month']; ?>">

                                <?php echo $month_row['salary_month']; ?>

                            </option>

                        <?php } ?>

                    </select>

                </div>

            </div>

        </div>

        <table class="salary-table">

            <tr>

                <th>Salary ID</th>

                <?php if ($is_admin) { ?>
                    <th>Employee ID</th>
                    <th>Employee Name</th>
                <?php } ?>

                <th>Month</th>

                <th>Generated Date</th>

                <th>Payment Date</th>

                <th>Final Salary</th>

                <th>Status</th>

                <th>Salary Slip</th>

            </tr>

            <tr id="noDataRow" style="display:none;">

                <td colspan="8" style="
text-align:center;
padding:25px;
color:#6b7280;
font-weight:500;
">

                    No salary records found

                </td>

            </tr>

            <?php while ($row = mysqli_fetch_assoc($result)) { ?>

                <tr class="salary-row" data-name="<?php echo strtolower($row['full_name']); ?>"
                    data-id="<?php echo strtolower($row['employee_id']); ?>"
                    data-month="<?php echo strtolower($row['salary_month']); ?>">

                    <td>

                        SAL-

                        <?php
                        echo str_pad(
                            $row['id'],
                            4,
                            '0',
                            STR_PAD_LEFT
                        );
                        ?>

                    </td>

                    <?php if ($is_admin) { ?>
                        <td><?php echo $row['employee_id']; ?></td>

                        <td><?php echo $row['full_name']; ?></td>
                    <?php } ?>

                    <td>
                        <?php echo $row['salary_month']; ?>
                    </td>

                    <td>

                        <?php

                        echo date(
                            "d-M-Y",
                            strtotime($row['created_at'])
                        );

                        ?>

                    </td>

                    <td>

                        <?php

                        if (
                            $row['payment_status'] == 'Paid'
                            &&
                            !empty($row['payment_date'])
                        ) {

                            echo date(
                                "d-M-Y",
                                strtotime($row['payment_date'])
                            );

                        } else {

                            echo "-";

                        }

                        ?>

                    </td>

                    <td>

                        <strong style="color:#16a34a;">

                            ₹ <?php echo number_format($row['final_salary']); ?>

                        </strong>

                    </td>

                    <td>

                        <?php
                        if ($row['payment_status'] == 'Paid') {
                            ?>

                            <span class="status-paid">
                                Paid
                            </span>

                            <?php
                        } else {
                            ?>

                            <span class="status-pending">
                                Pending
                            </span>

                            <?php if ($is_admin) { ?>

                            <?php } ?>

                            <?php
                        }
                        ?>

                    </td>

                    <td>

                        <a class="view-slip-btn" href="salary_slip.php?id=<?php echo $row['id']; ?>">

                            View Slip

                        </a>

                    </td>

                </tr>

            <?php } ?>

        </table>

    </div>

    </div>

    <script>

        document
            .getElementById("employee_id")
            .addEventListener("change", function () {

                let employeeId = this.value;

                if (employeeId === "") {
                    return;
                }

                fetch(
                    "get_employee_salary.php?employee_id="
                    + employeeId
                )

                    .then(response => response.json())

                    .then(data => {

                        document
                            .getElementById("basic_salary")
                            .value = data.salary;

                        document
                            .getElementById("incentive")
                            .value = data.incentive;

                    });

            });

    </script>

    <script>

        const searchInput =
            document.getElementById("salarySearch");

        const monthFilter =
            document.getElementById("monthFilter");

        if (searchInput && monthFilter) {

            function filterSalaryTable() {
                let search =
                    searchInput.value.toLowerCase();

                let month =
                    monthFilter.value.toLowerCase();

                let rows =
                    document.querySelectorAll(".salary-row");

                let visibleCount = 0;

                rows.forEach(row => {

                    let empId = row.dataset.id;
                    let empName = row.dataset.name;
                    let salaryMonth = row.dataset.month;

                    let searchMatch =
                        empId.includes(search)
                        ||
                        empName.includes(search);

                    let monthMatch =
                        month === ""
                        ||
                        salaryMonth === month;

                    if (searchMatch && monthMatch) {
                        row.style.display = "";
                        visibleCount++;
                    }
                    else {
                        row.style.display = "none";
                    }

                });

                document.getElementById("recordCounter").innerText =
                    "(" + visibleCount + " Records)";

                document.getElementById("noDataRow").style.display =
                    visibleCount === 0 ? "" : "none";

            }

            searchInput.addEventListener(
                "keyup",
                filterSalaryTable
            );

            monthFilter.addEventListener(
                "change",
                filterSalaryTable
            );

        }

    </script>

</body>

</html>