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

$error_message = '';
$employee_id = null;

if ($request_method === 'POST') {
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }

    $raw_employee_id = $_POST['employee_id'] ?? null;
} else {
    $raw_employee_id = $_GET['id'] ?? null;
}

if (
    !is_string($raw_employee_id)
    || strlen($raw_employee_id) > 20
    || !preg_match('/^EMP[0-9]{3,17}$/i', $raw_employee_id)
) {
    http_response_code(400);
    exit('Invalid employee ID.');
}
$employee_id = $raw_employee_id;

if ($request_method === 'POST') {
    if (!isset($_POST['update'])) {
        http_response_code(400);
        $error_message = 'Invalid employee update request.';
    } else {
        $read_text = static function ($key) {
            if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
                return null;
            }

            return trim($_POST[$key]);
        };
        $text_length = static function ($value) {
            if (preg_match_all('/./us', $value, $characters) === false) {
                return false;
            }

            return count($characters[0]);
        };

        $name = $read_text('full_name');
        $department = $read_text('department');
        $designation = $read_text('designation');
        $salary = $read_text('salary');
        $role = $read_text('role');
        $status = $read_text('status');
        $new_password = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password']
            : null;

        $valid = true;
        foreach ([[$name, 100], [$department, 50], [$designation, 50]] as [$value, $limit]) {
            $length = $value === null ? false : $text_length($value);
            if ($length === false || $length < 1 || $length > $limit) {
                $valid = false;
                break;
            }
        }

        if (
            $role === null
            || !in_array($role, ['Admin', 'Employee'], true)
            || $status === null
            || !in_array($status, ['Active', 'Inactive'], true)
            || $salary === null
            || !preg_match('/^\d+(?:\.\d{1,2})?$/', $salary)
            || !is_numeric($salary)
            || (float) $salary < 0
            || (float) $salary > 99999999.99
            || ($new_password !== null && $new_password !== '' && strlen($new_password) > 72)
        ) {
            $valid = false;
        }

        if (!$valid) {
            http_response_code(400);
            $error_message = 'Please provide valid values for all employee fields.';
        } else {
            if ($new_password !== null && $new_password !== '') {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                if ($password_hash === false) {
                    http_response_code(500);
                    $error_message = 'Employee could not be updated.';
                } else {
                    $update = mysqli_prepare(
                        $conn,
                        'UPDATE users SET full_name = ?, department = ?, designation = ?, salary = ?, role = ?, status = ?, password = ? WHERE employee_id = ?'
                    );
                    if ($update === false) {
                        http_response_code(500);
                        $error_message = 'Employee could not be updated.';
                    } else {
                        mysqli_stmt_bind_param(
                            $update,
                            'ssssssss',
                            $name,
                            $department,
                            $designation,
                            $salary,
                            $role,
                            $status,
                            $password_hash,
                            $employee_id
                        );
                        $updated = mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                        if ($updated) {
                            header('Location: employees.php');
                            exit();
                        }
                        http_response_code(500);
                        $error_message = 'Employee could not be updated.';
                    }
                }
            } else {
                $update = mysqli_prepare(
                    $conn,
                    'UPDATE users SET full_name = ?, department = ?, designation = ?, salary = ?, role = ?, status = ? WHERE employee_id = ?'
                );
                if ($update === false) {
                    http_response_code(500);
                    $error_message = 'Employee could not be updated.';
                } else {
                    mysqli_stmt_bind_param(
                        $update,
                        'sssssss',
                        $name,
                        $department,
                        $designation,
                        $salary,
                        $role,
                        $status,
                        $employee_id
                    );
                    $updated = mysqli_stmt_execute($update);
                    mysqli_stmt_close($update);
                    if ($updated) {
                        header('Location: employees.php');
                        exit();
                    }
                    http_response_code(500);
                    $error_message = 'Employee could not be updated.';
                }
            }
        }
    }
}

$select = mysqli_prepare(
    $conn,
    'SELECT employee_id, full_name, department, designation, salary, role, status FROM users WHERE employee_id = ? LIMIT 1'
);
if ($select === false) {
    http_response_code(500);
    exit('Employee could not be loaded.');
}
mysqli_stmt_bind_param($select, 's', $employee_id);
if (!mysqli_stmt_execute($select)) {
    mysqli_stmt_close($select);
    http_response_code(500);
    exit('Employee could not be loaded.');
}
$selected_employee_id = null;
$selected_name = null;
$selected_department = null;
$selected_designation = null;
$selected_salary = null;
$selected_role = null;
$selected_status = null;
mysqli_stmt_bind_result(
    $select,
    $selected_employee_id,
    $selected_name,
    $selected_department,
    $selected_designation,
    $selected_salary,
    $selected_role,
    $selected_status
);
$found = mysqli_stmt_fetch($select);
mysqli_stmt_close($select);
if (!$found) {
    http_response_code(404);
    exit('Employee not found.');
}
$row = [
    'employee_id' => $selected_employee_id,
    'full_name' => $selected_name,
    'department' => $selected_department,
    'designation' => $selected_designation,
    'salary' => $selected_salary,
    'role' => $selected_role,
    'status' => $selected_status
];

$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

?>

<!DOCTYPE html>
<html>

<head>

    <title>Edit Employee</title>

    <style>
        body {
            background: #f4f6f9;
            font-family: Arial;
        }

        .form-box {
            width: 500px;
            background: white;
            margin: 50px auto;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
        }

        .form-box h1 {
            margin-bottom: 20px;
        }

        input,
        select {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button {
            width: 100%;
            padding: 12px;
            background: #0d6efd;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #0b5ed7;
        }
    </style>

</head>

<body>

    <div class="form-box">

        <h1>Edit Employee</h1>
        <?php if ($error_message !== '') { ?>
            <p role="alert"><?php echo $escape_html($error_message); ?></p>
        <?php } ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="employee_id" value="<?php echo $escape_html($row['employee_id']); ?>">

            <input type="text" name="full_name" value="<?php echo $escape_html($row['full_name']); ?>" maxlength="100" required>

            <input type="text" name="department" value="<?php echo $escape_html($row['department']); ?>" maxlength="50" required>

            <input type="text" name="designation" value="<?php echo $escape_html($row['designation']); ?>" maxlength="50" required>

            <input type="number" name="salary" value="<?php echo $escape_html($row['salary']); ?>" min="0" max="99999999.99" step="0.01" required>

            <input type="password" name="password" placeholder="Leave blank to keep current password" maxlength="72" autocomplete="new-password">

            <select name="role" required>

                <option value="Admin" <?php if ($row['role'] === 'Admin') echo 'selected'; ?>>
                    Admin
                </option>

                <option value="Employee" <?php if ($row['role'] === 'Employee') echo 'selected'; ?>>
                    Employee
                </option>

            </select>

            <select name="status" required>

                <option value="Active" <?php if ($row['status'] === 'Active') echo 'selected'; ?>>
                    Active
                </option>

                <option value="Inactive" <?php if ($row['status'] === 'Inactive') echo 'selected'; ?>>
                    Inactive
                </option>

            </select>

            <button type="submit" name="update">
                Update Employee
            </button>

        </form>

    </div>

</body>

</html>
