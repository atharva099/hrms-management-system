<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

$id = (int) $_GET['id'];

$query =
    "SELECT * FROM departments
     WHERE id='$id'";

$result =
    mysqli_query($conn, $query);

$department =
    mysqli_fetch_assoc($result);

if (!$department) {
    header("Location: department_master.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $department_name =
        trim($_POST['department_name']);

    $check =
        mysqli_query(
            $conn,
            "SELECT *
             FROM departments
             WHERE department_name='$department_name'
             AND id!='$id'"
        );

    if (mysqli_num_rows($check) > 0) {

        $_SESSION['error'] =
            "Department already exists.";

    } else {

        mysqli_query(
            $conn,
            "UPDATE departments
             SET department_name='$department_name'
             WHERE id='$id'"
        );

        $_SESSION['success'] =
            "Department updated successfully.";

        header("Location: department_master.php");
        exit();
    }
}

?>

<!DOCTYPE html>
<html>

<head>

    <title>Edit Department</title>

    <link rel="stylesheet" href="css/style.css">

</head>

<body>

    <div class="content">

        <h2>Edit Department</h2>

        <?php if (isset($_SESSION['error'])) { ?>

            <div class="error-message">

                <?php
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>

            </div>

        <?php } ?>

        <form method="POST">

            <input type="text" name="department_name"
                value="<?php echo htmlspecialchars($department['department_name']); ?>" required>

            <button type="submit">
                Update Department
            </button>

        </form>

        <br>

        <a href="department_master.php">
            Back
        </a>

    </div>

</body>

</html>
