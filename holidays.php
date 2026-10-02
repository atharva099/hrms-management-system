<?php

include 'includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$role = auth_require_admin($conn);
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($request_method !== 'GET' && $request_method !== 'POST') {
    header('Allow: GET, POST', true, 405);
    exit('Method Not Allowed');
}

if ($request_method === 'POST') {
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Forbidden');
    }

    $holiday_name = $_POST['holiday_name'] ?? null;
    if (!is_string($holiday_name)) {
        http_response_code(400);
        exit('Invalid holiday name');
    }

    $holiday_name = trim($holiday_name);
    $holiday_name_length = preg_match_all('/./us', $holiday_name, $holiday_name_characters);
    if (
        $holiday_name === ''
        || preg_match('/^\s*$/u', $holiday_name) === 1
        || $holiday_name_length === false
        || $holiday_name_length > 100
    ) {
        http_response_code(400);
        exit('Invalid holiday name');
    }

    $holiday_date = $_POST['holiday_date'] ?? null;
    if (!is_string($holiday_date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $holiday_date)) {
        http_response_code(400);
        exit('Invalid holiday date');
    }

    $holiday_date_value = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday_date);
    $date_errors = DateTimeImmutable::getLastErrors();
    if (
        $holiday_date_value === false
        || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
        || $holiday_date_value->format('Y-m-d') !== $holiday_date
        || (int) $holiday_date_value->format('Y') < 1000
    ) {
        http_response_code(400);
        exit('Invalid holiday date');
    }

    $insert_statement = mysqli_prepare(
        $conn,
        'INSERT INTO holidays (holiday_name, holiday_date) VALUES (?, ?)'
    );
    if ($insert_statement === false) {
        http_response_code(500);
        exit('Unable to add holiday');
    }

    mysqli_stmt_bind_param($insert_statement, 'ss', $holiday_name, $holiday_date);
    $insert_succeeded = mysqli_stmt_execute($insert_statement);
    mysqli_stmt_close($insert_statement);

    if (!$insert_succeeded) {
        http_response_code(500);
        exit('Unable to add holiday');
    }

    $_SESSION['holiday_success'] = true;
    header('Location: holidays.php');
    exit();
}

$show_success = !empty($_SESSION['holiday_success']);
unset($_SESSION['holiday_success']);

$current_date = date('Y-m-d');
$upcoming_statement = mysqli_prepare(
    $conn,
    'SELECT * FROM holidays WHERE holiday_date >= ? ORDER BY holiday_date ASC'
);
if ($upcoming_statement === false) {
    http_response_code(500);
    exit('Unable to load holidays');
}

mysqli_stmt_bind_param($upcoming_statement, 's', $current_date);
if (!mysqli_stmt_execute($upcoming_statement)) {
    mysqli_stmt_close($upcoming_statement);
    http_response_code(500);
    exit('Unable to load holidays');
}

$upcoming = mysqli_stmt_get_result($upcoming_statement);
mysqli_stmt_close($upcoming_statement);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Holidays</title>

    <link rel="stylesheet" type="text/css" href="css/style.css">
</head>

<body>
    <?php if ($show_success) { ?>
        <script>alert('Holiday Added Successfully');</script>
    <?php } ?>

    <div class="container">

        <div class="back-btn-box">

            <a href="dashboard.php" class="back-dashboard-btn">

                ⬅ Back to Dashboard

            </a>

        </div>

        <div class="add-holiday-box">

            <h2>Add Holiday</h2>

            <form method="POST" class="add-holiday-form">

                <?php echo csrf_field(); ?>

                <input type="text" name="holiday_name" maxlength="100" placeholder="Holiday Name" required>

                <input type="date" name="holiday_date" required>

                <button type="submit" name="add_holiday">
                    Add Holiday
                </button>

            </form>

        </div>

        <div class="holiday-container"></div>

        <h2>Upcoming Holidays</h2>

        <table class="holiday-table">

            <tr>
                <th>Holiday</th>
                <th>Date</th>
            </tr>

            <?php while ($row = mysqli_fetch_assoc($upcoming)) { ?>

                <tr>

                    <td>
                        <?php echo htmlspecialchars((string) $row['holiday_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </td>

                    <td>
                        <?php echo htmlspecialchars((string) $row['holiday_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </td>

                </tr>

            <?php } ?>

        </table>

    </div>
    </div>
</body>

</html>
