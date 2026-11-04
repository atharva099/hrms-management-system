<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

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
<html lang="en">

<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">


    <title>Department Master</title>

    <link rel="stylesheet" href="css/style.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<link rel="stylesheet" href="css/admin-pages.css?v=2">
</head>

<body>

    <div class="main-container">

        <!-- SIDEBAR -->

        <?php $active_nav = 'departments'; include __DIR__ . '/includes/navigation.php'; ?>

        <!-- CONTENT -->

        <div class="content admin-page" id="main-content">

            <?php

            if (isset($_SESSION['success'])) {

                ?>

                <div class="success-message">

                    <?php
                    echo $escape_html($_SESSION['success']);
                    unset($_SESSION['success']);
                    ?>

                </div>

                <?php

            }

            if (isset($_SESSION['error'])) {

                ?>

                <div class="error-message">

                    <?php
                    echo $escape_html($_SESSION['error']);
                    unset($_SESSION['error']);
                    ?>

                </div>

                <?php

            }

            ?>

            <div class="topbar"><a class="admin-dashboard-link" href="dashboard.php">Back to Dashboard</a>

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

                    <?php echo csrf_field(); ?>

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
                                    <?php echo (int) $row['id']; ?>
                                </td>

                                <td>
                                    <?php echo $escape_html($row['department_name']); ?>
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
                                    <div class="department-actions">
                                    <a class="department-action-link" href="edit_department.php?id=<?php echo (int) $row['id']; ?>">
                                        Edit
                                    </a>

                                    <?php if ($row['status'] == 'Active') { ?>

                                        <form class="department-action-form" method="POST" action="deactivate_department.php"
                                            onsubmit="return confirm('Deactivate this department?');">
                                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                            <?php echo csrf_field(); ?>
                                            <button class="department-action-button" type="submit">Deactivate</button>
                                        </form>

                                    <?php } else { ?>

                                        <form class="department-action-form" method="POST" action="activate_department.php">
                                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                            <?php echo csrf_field(); ?>
                                            <button class="department-action-button" type="submit">Activate</button>
                                        </form>

                                    <?php } ?>
                                    </div>
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
