<?php

include 'includes/db.php';
require_once 'includes/auth.php';

$current_employee_id = auth_require_authenticated($conn);
$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$salary_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($salary_id === false || $salary_id === null) {
    header('Location: salary.php');
    exit();
}

$query = 'SELECT salaries.*, users.full_name,
users.department,
users.designation,
users.email,
users.phone,
users.joining_date
FROM salaries

JOIN users
ON salaries.employee_id = users.employee_id

WHERE salaries.id = ?';

if (auth_current_role() === 'Admin') {
    $statement = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($statement, 'i', $salary_id);
} else {
    $query .= ' AND salaries.employee_id = ?';
    $statement = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($statement, 'is', $salary_id, $current_employee_id);
}

mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($statement);

if (!$row) {
    header('Location: salary.php');
    exit();
}

?>

<!DOCTYPE html>
<html>

<head>

    <title>Salary Slip</title>

    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">

</head>

<body style="background:#f3f4f6;">

    <a href="dashboard.php" class="back-btn">
        ← Back to Dashboard
    </a>

    <div class="salary-slip-container">
        <div class="salary-slip-card">

            <div class="salary-slip-header">

                <img src="images/logo.png.jpeg" class="company-logo">

                <div>

                    <h1 class="company-title">
                        CoreAxis CRM
                    </h1>

                    <h3 class="slip-subtitle">
                        Employee Salary Slip
                    </h3>

                </div>

            </div>

            <div class="employee-profile-box">

                <?php

                $safe_employee_id = is_string($row['employee_id'])
                    && preg_match('/^EMP[0-9]{3,17}$/iD', $row['employee_id'])
                    ? $row['employee_id']
                    : '';
                $image_path = $safe_employee_id !== '' ? "images/{$safe_employee_id}.jpg" : 'images/default.png';

                if (file_exists($image_path)) {

                    ?>

                    <img src="<?php echo $escape_html($image_path); ?>" class="employee-profile-image" alt="Employee photo">

                    <?php

                } else {

                    ?>

                    <img src="images/default.png" class="employee-profile-image">

                    <?php

                }

                ?>

            </div>

            <table class="salary-slip-table">

                <tr>
                    <td><strong>Employee ID</strong></td>
                    <td>
                        <?php echo $escape_html($row['employee_id']); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Employee Name</strong></td>
                    <td>
                        <?php echo $escape_html($row['full_name']); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Department</strong></td>
                    <td>
                        <?php echo $escape_html($row['department']); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Designation</strong></td>
                    <td>
                        <?php echo $escape_html($row['designation']); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Email</strong></td>
                    <td>
                        <?php echo $escape_html($row['email']); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Phone</strong></td>
                    <td>
                        <?php echo $escape_html($row['phone']); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Joining Date</strong></td>

                    <td>
                        <?php
                        echo date(
                            'd M Y',
                            strtotime($row['joining_date'])
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Payment Date</strong></td>
                    <td>
                        <?php echo date('d M Y'); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Salary Slip ID</strong></td>

                    <td>
                        SLIP-
                        <?php echo (int) $row['id']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Salary Month</strong></td>
                    <td>
                        <?php echo $escape_html($row['salary_month']); ?>
                    </td>
                </tr>

                <tr class="section-heading">
                    <td colspan="2">
                        Earnings
                    </td>
                </tr>

                <tr>
                    <td><strong>Basic Salary</strong></td>
                    <td>₹
                        <?php echo $escape_html($row['basic_salary']); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Incentive</strong></td>
                    <td>₹
                        <?php echo $escape_html($row['incentive']); ?>
                    </td>
                </tr>

                <tr class="section-heading deduction-heading">
                    <td colspan="2">
                        Deductions
                    </td>
                </tr>

                <tr>
                    <td><strong>Late Deduction</strong></td>
                    <td>₹
                        <?php echo $escape_html($row['late_deduction']); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Half Day Deduction</strong></td>
                    <td>₹
                        <?php echo $escape_html($row['halfday_deduction']); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Absent Deduction</strong></td>
                    <td>₹
                        <?php echo $escape_html($row['absent_deduction'] ?? 0); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Total Deduction</strong></td>

                    <td>
                        ₹
                        <?php

                        $total_deduction =
                            $row['late_deduction']
                            +
                            $row['halfday_deduction']
                            +
                            $row['absent_deduction'];

                        echo $escape_html($total_deduction);

                        ?>
                    </td>
                </tr>

                <td><strong>Final Salary</strong></td>
                <td>
                    <strong class="final-salary">
                        ₹
                        <?php echo $escape_html($row['final_salary']); ?>
                    </strong>
                </td>
                </tr>

            </table>

            <div class="salary-summary-box">

                <div class="summary-card">

                    <h4>Gross Salary</h4>

                    <p>
                        ₹
                        <?php
                        echo
                            $row['basic_salary']
                            +
                            $row['incentive'];
                        ?>
                    </p>

                </div>

                <div class="summary-card net-salary-card">

                    <h4>Net Salary</h4>

                    <p>
                        ₹
                        <?php echo $escape_html($row['final_salary']); ?>
                    </p>

                </div>

            </div>

            <div class="signature-box">

                <p>
                    Authorized Signature
                </p>

                <br>

                ____________________

            </div>

            <button onclick="window.print()" class="print-btn">

                Download / Print Salary Slip

            </button>

        </div>
    </div>

</body>

</html>
