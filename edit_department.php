<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($request_method !== 'GET' && $request_method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

if ($request_method === 'POST') {
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }
    $raw_id = $_POST['department_id'] ?? null;
} else {
    $raw_id = $_GET['id'] ?? null;
}

$id = is_string($raw_id)
    ? filter_var($raw_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])
    : false;
if ($id === false) {
    http_response_code(400);
    exit('Invalid department ID.');
}

$valid_name = true;
if ($request_method === 'POST') {
    $raw_department_name = $_POST['department_name'] ?? null;
    $valid_name = is_string($raw_department_name)
        && preg_match('//u', $raw_department_name) === 1;

    if ($valid_name) {
        $department_name = preg_replace('/^\s+|\s+$/u', '', $raw_department_name);
        $valid_name = is_string($department_name) && $department_name !== '';
    }
    if ($valid_name) {
        preg_match_all('/./us', $department_name, $name_characters);
        $valid_name = count($name_characters[0]) <= 100;
    }

    if (!$valid_name) {
        http_response_code(400);
        $_SESSION['error'] = 'Please enter a valid department name (maximum 100 characters).';
    }
}

$select = mysqli_prepare(
    $conn,
    'SELECT id, department_name FROM departments WHERE id = ? LIMIT 1'
);
if ($select === false) {
    http_response_code(500);
    exit('Department could not be loaded.');
}
mysqli_stmt_bind_param($select, 'i', $id);
if (!mysqli_stmt_execute($select)) {
    mysqli_stmt_close($select);
    http_response_code(500);
    exit('Department could not be loaded.');
}
$department_id = null;
$selected_department_name = null;
mysqli_stmt_bind_result($select, $department_id, $selected_department_name);
$found = mysqli_stmt_fetch($select);
mysqli_stmt_close($select);

if (!$found) {
    header('Location: department_master.php');
    exit();
}
$department = [
    'id' => $department_id,
    'department_name' => $selected_department_name
];

if ($request_method === 'POST' && $valid_name) {
        $duplicate_check = mysqli_prepare(
            $conn,
            'SELECT id FROM departments WHERE department_name = ? AND id <> ? LIMIT 1'
        );
        if ($duplicate_check === false) {
            http_response_code(500);
            $_SESSION['error'] = 'Department update failed.';
        } else {
            mysqli_stmt_bind_param($duplicate_check, 'si', $department_name, $id);
            $duplicate_query_ok = mysqli_stmt_execute($duplicate_check)
                && mysqli_stmt_store_result($duplicate_check);
            $duplicate_exists = $duplicate_query_ok && mysqli_stmt_num_rows($duplicate_check) > 0;
            mysqli_stmt_close($duplicate_check);

            if (!$duplicate_query_ok) {
                http_response_code(500);
                $_SESSION['error'] = 'Department update failed.';
            } elseif ($duplicate_exists) {
                $_SESSION['error'] = 'Department already exists.';
            } else {
                $update = mysqli_prepare(
                    $conn,
                    'UPDATE departments SET department_name = ? WHERE id = ?'
                );
                if ($update === false) {
                    http_response_code(500);
                    $_SESSION['error'] = 'Department update failed.';
                } else {
                    mysqli_stmt_bind_param($update, 'si', $department_name, $id);
                    $updated = mysqli_stmt_execute($update);
                    mysqli_stmt_close($update);
                    if ($updated) {
                        $_SESSION['success'] = 'Department updated successfully.';
                        header('Location: department_master.php');
                        exit();
                    }
                    http_response_code(500);
                    $_SESSION['error'] = 'Department update failed.';
                }
            }
        }
}

$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$error_message = isset($_SESSION['error']) && is_string($_SESSION['error'])
    ? $_SESSION['error']
    : '';
unset($_SESSION['error']);

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

        <?php if ($error_message !== '') { ?>
            <div class="error-message">
                <?php echo $escape_html($error_message); ?>
            </div>
        <?php } ?>

        <form method="POST" action="edit_department.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="department_id" value="<?php echo $escape_html($department['id']); ?>">
            <input type="text" name="department_name" value="<?php echo $escape_html($department['department_name']); ?>" maxlength="100" required>

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
