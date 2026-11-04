<?php

include 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/payroll_period.php';

$employee_id = auth_require_authenticated($conn);
$is_admin = auth_current_role() === 'Admin';
$active_nav = 'salary';
$payroll_actions_enabled = false;
$payroll_blocked_message = 'Payroll generation and payment release are unavailable until approved deduction rules and an auditable payment workflow are configured.';
$escape_salary_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

function salaryScopedResult($conn, $query, $is_admin, $employee_id)
{
    if ($is_admin) {
        return mysqli_query($conn, $query);
    }

    $statement = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($statement, 's', $employee_id);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    mysqli_stmt_close($statement);

    return $result;
}

if (isset($_POST['process_payroll'])) {
    auth_require_admin($conn);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST', true, 405);
        exit('Method Not Allowed');
    }

    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }

    // Do not mark calculation rows as paid until an approved payment
    // workflow records the actual payment and its audit evidence.
    if (!$payroll_actions_enabled) {
        http_response_code(503);
        exit($payroll_blocked_message);
    }

    $process_month = isset($_POST['process_month']) && is_string($_POST['process_month'])
        ? trim($_POST['process_month'])
        : '';
    $process_period = coreaxis_payroll_period_from_label($process_month);

    if ($process_period === false) {
        http_response_code(400);
        $error_message = 'Please select a valid payroll month.';
    } else {
        $check_pending_query = mysqli_prepare($conn, "
            SELECT COUNT(*) AS total
            FROM salaries
            WHERE (salary_period=? OR (salary_period IS NULL AND salary_month=?))
              AND payment_status='Pending'
        ");

        if ($check_pending_query === false
            || !mysqli_stmt_bind_param($check_pending_query, 'ss', $process_period['date'], $process_month)
            || !mysqli_stmt_execute($check_pending_query)) {
            if ($check_pending_query instanceof mysqli_stmt) { mysqli_stmt_close($check_pending_query); }
            $error_message = 'Payroll could not be processed. Please try again.';
        } else {
            $check_pending_result = mysqli_stmt_get_result($check_pending_query);
            mysqli_stmt_close($check_pending_query);
            $check_pending = $check_pending_result ? mysqli_fetch_assoc($check_pending_result) : null;

            if ($check_pending && (int) $check_pending['total'] > 0) {

                $process_statement = mysqli_prepare($conn, "
                    UPDATE salaries
                    SET payment_status='Paid', payment_date=CURDATE()
                    WHERE (salary_period=? OR (salary_period IS NULL AND salary_month=?))
                      AND payment_status='Pending'
                ");
                if ($process_statement === false
                    || !mysqli_stmt_bind_param($process_statement, 'ss', $process_period['date'], $process_month)
                    || !mysqli_stmt_execute($process_statement)) {
                    if ($process_statement instanceof mysqli_stmt) { mysqli_stmt_close($process_statement); }
                    $error_message = 'Payroll could not be processed. Please try again.';
                } else {
                    mysqli_stmt_close($process_statement);
                    header("Location: salary.php?success=payroll_processed");
                    exit();
                }

            } else {
                $error_message = 'No pending payroll found for selected month.';
            }
        }
    }
}


if (isset($_POST['generate_salary'])) {
    auth_require_admin($conn);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST', true, 405);
        exit('Method Not Allowed');
    }

    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }

    // The existing fixed per-event deductions are not an approved payroll
    // policy. Fail closed until versioned, approved rules are implemented.
    if (!$payroll_actions_enabled) {
        http_response_code(503);
        exit($payroll_blocked_message);
    }

    $salary_employee_id = isset($_POST['employee_id']) && is_string($_POST['employee_id'])
        ? trim($_POST['employee_id'])
        : '';
    $salary_month = isset($_POST['salary_month']) && is_string($_POST['salary_month'])
        ? trim($_POST['salary_month'])
        : '';
    $valid_employee_id = preg_match('/^EMP[0-9]{3,17}$/iD', $salary_employee_id) === 1;
    $salary_period = coreaxis_payroll_period_from_label($salary_month);
    if (!$valid_employee_id || $salary_period === false) {
        http_response_code(400);
        $error_message = 'Please select a valid employee and payroll month.';
    } else {
        $period_start = $salary_period['date'];
        $period_end = (new DateTimeImmutable($period_start))->modify('+1 month')->format('Y-m-d');
        $transaction_started = false;

        try {
            if (!mysqli_begin_transaction($conn)) {
                throw new RuntimeException('transaction_start');
            }
            $transaction_started = true;

            // Keep this lock for compatibility with other generation paths.
            // The R5.1 database migration adds the final employee/period unique key.
            $employee_statement = mysqli_prepare(
                $conn,
                "SELECT salary, incentive FROM users
                 WHERE employee_id = ? AND role = 'Employee' AND status = 'Active'
                 LIMIT 1 FOR UPDATE"
            );
            if ($employee_statement === false) {
                throw new RuntimeException('employee_query');
            }
            if (!mysqli_stmt_bind_param($employee_statement, 's', $salary_employee_id)
                || !mysqli_stmt_execute($employee_statement)) {
                mysqli_stmt_close($employee_statement);
                throw new RuntimeException('employee_query');
            }
            $employee_result = mysqli_stmt_get_result($employee_statement);
            $employee_row = $employee_result ? mysqli_fetch_assoc($employee_result) : null;
            mysqli_stmt_close($employee_statement);

            if (!$employee_row) {
                throw new DomainException('inactive_employee');
            }
            $basic_salary_cents = coreaxis_payroll_amount_to_cents($employee_row['salary']);
            $incentive_cents = coreaxis_payroll_amount_to_cents($employee_row['incentive']);
            if ($basic_salary_cents === false || $incentive_cents === false) {
                throw new DomainException('invalid_employee_pay');
            }
            $basic_salary = coreaxis_payroll_cents_to_decimal($basic_salary_cents);
            $incentive = coreaxis_payroll_cents_to_decimal($incentive_cents);

    $late_query = mysqli_prepare($conn, "SELECT COUNT(*) AS total_late

FROM attendance

WHERE employee_id=?

AND status='Late'

AND attendance_date >= ? AND attendance_date < ?");

    if ($late_query === false
        || !mysqli_stmt_bind_param($late_query, 'sss', $salary_employee_id, $period_start, $period_end)
        || !mysqli_stmt_execute($late_query)) {
        if ($late_query instanceof mysqli_stmt) { mysqli_stmt_close($late_query); }
        throw new RuntimeException('attendance_query');
    }
    $late_result = mysqli_stmt_get_result($late_query);
    mysqli_stmt_close($late_query);

    $late_row = mysqli_fetch_assoc($late_result);

    $total_late = $late_row['total_late'];



    $halfday_query = mysqli_prepare($conn, "SELECT COUNT(*) AS total_halfday

FROM attendance

WHERE employee_id=?

AND status='Half Day'

AND attendance_date >= ? AND attendance_date < ?");

    if ($halfday_query === false
        || !mysqli_stmt_bind_param($halfday_query, 'sss', $salary_employee_id, $period_start, $period_end)
        || !mysqli_stmt_execute($halfday_query)) {
        if ($halfday_query instanceof mysqli_stmt) { mysqli_stmt_close($halfday_query); }
        throw new RuntimeException('attendance_query');
    }
    $halfday_result = mysqli_stmt_get_result($halfday_query);
    mysqli_stmt_close($halfday_query);

    $halfday_row = mysqli_fetch_assoc($halfday_result);

    $total_halfday = $halfday_row['total_halfday'];



    $absent_query = mysqli_prepare($conn, "SELECT COUNT(*) AS total_absent

FROM attendance

WHERE employee_id=?

AND status='Absent'

AND attendance_date >= ? AND attendance_date < ?");

    if ($absent_query === false
        || !mysqli_stmt_bind_param($absent_query, 'sss', $salary_employee_id, $period_start, $period_end)
        || !mysqli_stmt_execute($absent_query)) {
        if ($absent_query instanceof mysqli_stmt) { mysqli_stmt_close($absent_query); }
        throw new RuntimeException('attendance_query');
    }
    $absent_result = mysqli_stmt_get_result($absent_query);
    mysqli_stmt_close($absent_query);

    $absent_row = mysqli_fetch_assoc($absent_result);

    $total_absent = $absent_row['total_absent'];



    $late_deduction_cents = (int) $total_late * 10000;
    $halfday_deduction_cents = (int) $total_halfday * 50000;
    $absent_deduction_cents = (int) $total_absent * 100000;
    $late_deduction = coreaxis_payroll_cents_to_decimal($late_deduction_cents);
    $halfday_deduction = coreaxis_payroll_cents_to_decimal($halfday_deduction_cents);
    $absent_deduction = coreaxis_payroll_cents_to_decimal($absent_deduction_cents);
    $final_salary = coreaxis_payroll_cents_to_decimal(
        $basic_salary_cents + $incentive_cents
        - $late_deduction_cents - $halfday_deduction_cents - $absent_deduction_cents
    );

    $check_query = mysqli_prepare($conn, '

SELECT *

FROM salaries

WHERE employee_id=?

AND (salary_period=? OR salary_month=?)

LIMIT 1

FOR UPDATE

');

    if ($check_query === false
        || !mysqli_stmt_bind_param($check_query, 'sss', $salary_employee_id, $period_start, $salary_month)
        || !mysqli_stmt_execute($check_query)) {
        if ($check_query instanceof mysqli_stmt) { mysqli_stmt_close($check_query); }
        throw new RuntimeException('duplicate_query');
    }
    $check_result = mysqli_stmt_get_result($check_query);
    mysqli_stmt_close($check_query);

    if (mysqli_num_rows($check_result) > 0) {
        mysqli_rollback($conn);
        $transaction_started = false;
        $error_message =
            "Salary already generated for this employee and month";

    } else {

        $query = mysqli_prepare($conn, "INSERT INTO salaries
(
employee_id,
salary_month,
salary_period,
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
?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending'
 )");

        if ($query === false || !mysqli_stmt_bind_param(
            $query,
            'sssssssss',
            $salary_employee_id,
            $salary_month,
            $period_start,
            $basic_salary,
            $incentive,
            $late_deduction,
            $halfday_deduction,
            $absent_deduction,
            $final_salary
        ) || !mysqli_stmt_execute($query)) {
            if ($query instanceof mysqli_stmt) { mysqli_stmt_close($query); }
            throw new RuntimeException('salary_insert');
        }
        mysqli_stmt_close($query);
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('salary_commit');
        }
        $transaction_started = false;

        echo "
    <div class='salary-success-alert'>
    Salary Generated Successfully
    </div>";
    }
        } catch (DomainException $exception) {
            if ($transaction_started) { mysqli_rollback($conn); $transaction_started = false; }
            $error_message = $exception->getMessage() === 'inactive_employee'
                ? 'Please select an active employee.'
                : 'Employee salary details are incomplete or invalid.';
        } catch (Throwable $exception) {
            if ($transaction_started) { mysqli_rollback($conn); $transaction_started = false; }
            $error_message = 'Salary could not be generated. Please try again.';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <title>Salary Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/operations-pages.css?v=2">

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

    WHERE salaries.employee_id=?

    ORDER BY salaries.id DESC

    ";
}

if ($is_admin) {
    $result = mysqli_query($conn, $query);
} else {
    $salary_list_statement = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($salary_list_statement, 's', $employee_id);
    mysqli_stmt_execute($salary_list_statement);
    $result = mysqli_stmt_get_result($salary_list_statement);
}

$total_records = mysqli_num_rows($result);

/* Total Generated Salaries */

$total_salary_query = $is_admin ? "
SELECT COUNT(*) AS total_salaries
FROM salaries
" : 'SELECT COUNT(*) AS total_salaries FROM salaries WHERE employee_id=?';

$total_salary_result = salaryScopedResult($conn, $total_salary_query, $is_admin, $employee_id);

$total_salary =
    mysqli_fetch_assoc($total_salary_result);




/* Total Payroll Processed */

$payroll_query = $is_admin ? "
SELECT SUM(final_salary) AS total_payroll
FROM salaries
" : 'SELECT SUM(final_salary) AS total_payroll FROM salaries WHERE employee_id=?';

$payroll_result = salaryScopedResult($conn, $payroll_query, $is_admin, $employee_id);

$payroll =
    mysqli_fetch_assoc($payroll_result);




/* Employees Paid */

$paid_query = $is_admin ? "
SELECT COUNT(DISTINCT employee_id)
AS total_employees
FROM salaries
WHERE payment_status='Paid'
" : "SELECT COUNT(DISTINCT employee_id) AS total_employees FROM salaries WHERE employee_id=? AND payment_status='Paid'";

$paid_result = salaryScopedResult($conn, $paid_query, $is_admin, $employee_id);

$paid =
    mysqli_fetch_assoc($paid_result);

/* Pending Payments */

$pending_query = $is_admin ? "
SELECT COUNT(*) AS total_pending
FROM salaries
WHERE payment_status='Pending'
" : "SELECT COUNT(*) AS total_pending FROM salaries WHERE payment_status='Pending' AND employee_id=?";

$pending_result = salaryScopedResult($conn, $pending_query, $is_admin, $employee_id);

$pending =
    mysqli_fetch_assoc($pending_result);



/* Current Month Payroll */

$current_month = date('F Y');

$current_month_query = $is_admin ? "
SELECT SUM(final_salary) AS month_payroll
FROM salaries
WHERE salary_month=?
" : 'SELECT SUM(final_salary) AS month_payroll FROM salaries WHERE salary_month=? AND employee_id=?';

if ($is_admin) {
    $current_month_statement = mysqli_prepare($conn, $current_month_query);
    mysqli_stmt_bind_param($current_month_statement, 's', $current_month);
} else {
    $current_month_statement = mysqli_prepare($conn, $current_month_query);
    mysqli_stmt_bind_param($current_month_statement, 'ss', $current_month, $employee_id);
}
mysqli_stmt_execute($current_month_statement);
$current_month_result = mysqli_stmt_get_result($current_month_statement);
mysqli_stmt_close($current_month_statement);

$current_month_payroll =
    mysqli_fetch_assoc($current_month_result);

?>

<body style="background:#f3f4f6;">

    <div class="main-container">
        <?php include __DIR__ . '/includes/navigation.php'; ?>
        <main class="content operation-page salary-page" id="main-content">

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

            <?php echo $escape_salary_html($error_message); ?>

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

        <header class="topbar">
            <h1>
                <?php echo $is_admin ? "Payroll Management Dashboard" : "My Salary Dashboard"; ?>
            </h1>
            <a href="dashboard.php">Back to Dashboard</a>
        </header>

        <div class="salary-hero">
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

            <?php if (!$payroll_actions_enabled) { ?>
                <p class="salary-error-alert" role="status">
                    <?php echo $escape_salary_html($payroll_blocked_message); ?>
                </p>
            <?php } ?>

            <div class="salary-card payroll-form-card">

                <h2 style="margin-bottom:25px;">
                    Generate Salary
                </h2>

                <form method="POST" class="salary-form">

                    <?php echo csrf_field(); ?>

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

                            <option value="<?php echo $escape_salary_html($emp['employee_id']); ?>">

                                <?php
                                echo $escape_salary_html($emp['employee_id'])
                                    . " - "
                                    . $escape_salary_html($emp['full_name']);
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

                    <button type="submit" name="generate_salary" disabled>

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

                    <?php echo csrf_field(); ?>

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
                                "SELECT salary_month,
                                        MAX(COALESCE(
                                            salary_period,
                                            STR_TO_DATE(CONCAT('01 ', salary_month), '%d %M %Y')
                                        )) AS salary_month_order
                     FROM salaries
                     GROUP BY salary_month
                     ORDER BY salary_month_order DESC, salary_month DESC"
                            );

                        if ($month_query instanceof mysqli_result) {
                            $salary_month_options = mysqli_fetch_all($month_query, MYSQLI_ASSOC);
                            mysqli_free_result($month_query);
                        } else {
                            $salary_month_options = [];
                            echo '<option value="" disabled>Payroll months are temporarily unavailable.</option>';
                        }

                        foreach ($salary_month_options as $month) {

                            ?>

                            <option value="<?php echo $escape_salary_html($month['salary_month']); ?>">

                                <?php echo $escape_salary_html($month['salary_month']); ?>

                            </option>

                        <?php } ?>

                    </select>

                    <button type="submit" name="process_payroll" <?php
                    if ($pending_release['total_pending_release'] == 0 || !$payroll_actions_enabled) {
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

                        $month_filter_sql = "SELECT salary_month,
                                MAX(COALESCE(
                                    salary_period,
                                    STR_TO_DATE(CONCAT('01 ', salary_month), '%d %M %Y')
                                )) AS salary_month_order
                            FROM salaries
                            WHERE salary_month IS NOT NULL AND salary_month <> ''
                            " . ($is_admin ? '' : 'AND employee_id = ?') . "
                            GROUP BY salary_month
                            ORDER BY salary_month_order DESC, salary_month DESC";

                        if ($is_admin) {
                            $month_result = mysqli_query($conn, $month_filter_sql);
                        } else {
                            $month_statement = mysqli_prepare($conn, $month_filter_sql);
                            if ($month_statement instanceof mysqli_stmt) {
                                mysqli_stmt_bind_param($month_statement, 's', $employee_id);
                                $month_executed = mysqli_stmt_execute($month_statement);
                                $month_result = $month_executed
                                    ? mysqli_stmt_get_result($month_statement)
                                    : false;
                                mysqli_stmt_close($month_statement);
                            } else {
                                $month_result = false;
                            }
                        }

                        if ($month_result instanceof mysqli_result) {
                            $month_filter_options = mysqli_fetch_all($month_result, MYSQLI_ASSOC);
                            mysqli_free_result($month_result);
                        } else {
                            $month_filter_options = [];
                            echo '<option value="" disabled>Payroll months are temporarily unavailable.</option>';
                        }

                        foreach ($month_filter_options as $month_row) {

                            ?>

                            <option value="<?php echo $escape_salary_html($month_row['salary_month']); ?>">

                                <?php echo $escape_salary_html($month_row['salary_month']); ?>

                            </option>

                        <?php } ?>

                    </select>

                </div>

            </div>

        </div>

        <div class="operations-table-scroll salary-table-scroll" role="region" aria-label="Salary records" tabindex="0">
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

                <tr class="salary-row" data-name="<?php echo $escape_salary_html(strtolower((string) $row['full_name'])); ?>"
                    data-id="<?php echo $escape_salary_html(strtolower((string) $row['employee_id'])); ?>"
                    data-month="<?php echo $escape_salary_html(strtolower((string) $row['salary_month'])); ?>">

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
                        <td><?php echo $escape_salary_html($row['employee_id']); ?></td>

                        <td><?php echo $escape_salary_html($row['full_name']); ?></td>
                    <?php } ?>

                    <td>
                        <?php echo $escape_salary_html($row['salary_month']); ?>
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

                        <a class="view-slip-btn" href="salary_slip.php?id=<?php echo (int) $row['id']; ?>">

                            View Slip

                        </a>

                    </td>

                </tr>

            <?php } ?>

        </table>
        </div>

    </div>
        </main>
    </div>

    <script>

        const employeeSelect = document.getElementById("employee_id");

        if (employeeSelect) {
            employeeSelect.addEventListener("change", function () {

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
        }

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
