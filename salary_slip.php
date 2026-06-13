<?php

session_start();
include("includes/db.php");

if (!isset($_SESSION['employee_id'])) {
    header("Location:index.php");
}

$id = $_GET['id'];

$query = mysqli_query(
    $conn,

    "SELECT salaries.*, users.full_name,
users.department,
users.designation,
users.email,
users.phone,
users.joining_date
FROM salaries

JOIN users
ON salaries.employee_id = users.employee_id

WHERE salaries.id='$id'"
);

$row = mysqli_fetch_assoc($query);

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

                $image_path =
                    "images/" .
                    $row['employee_id'] .
                    ".jpg";

                if (file_exists($image_path)) {

                    ?>

                    <img src="<?php echo $image_path; ?>" class="employee-profile-image">

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
                        <?php echo $row['employee_id']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Employee Name</strong></td>
                    <td>
                        <?php echo $row['full_name']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Department</strong></td>
                    <td>
                        <?php echo $row['department']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Designation</strong></td>
                    <td>
                        <?php echo $row['designation']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Email</strong></td>
                    <td>
                        <?php echo $row['email']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Phone</strong></td>
                    <td>
                        <?php echo $row['phone']; ?>
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
                        <?php echo $row['id']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Salary Month</strong></td>
                    <td>
                        <?php echo $row['salary_month']; ?>
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
                        <?php echo $row['basic_salary']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Incentive</strong></td>
                    <td>₹
                        <?php echo $row['incentive']; ?>
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
                        <?php echo $row['late_deduction']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Half Day Deduction</strong></td>
                    <td>₹
                        <?php echo $row['halfday_deduction']; ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Absent Deduction</strong></td>
                    <td>₹
                        <?php echo $row['absent_deduction'] ?? 0; ?>
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

                        echo $total_deduction;

                        ?>
                    </td>
                </tr>

                <td><strong>Final Salary</strong></td>
                <td>
                    <strong class="final-salary">
                        ₹
                        <?php echo $row['final_salary']; ?>
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
                        <?php echo $row['final_salary']; ?>
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