<?php

include 'includes/db.php';
require_once 'includes/auth.php';
require_once __DIR__ . '/includes/password_policy.php';

auth_require_admin($conn);

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($request_method !== 'GET' && $request_method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$error_message = '';
$success_message = '';

if ($request_method === 'POST') {
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }

    if (!isset($_POST['add_employee'])) {
        http_response_code(400);
        $error_message = 'Invalid employee creation request.';
    } else {
        $max_length = static function ($value) {
            if (preg_match_all('/./us', $value, $characters) === false) {
                return false;
            }

            return count($characters[0]);
        };
        $read_text = static function ($key) {
            if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
                return null;
            }

            return trim($_POST[$key]);
        };

        $full_name = $read_text('full_name');
        $password_input = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password']
            : null;
        $department = $read_text('department');
        $designation = $read_text('designation');
        $salary = $read_text('salary');
        $joining_date = $read_text('joining_date');
        $email = $read_text('email');
        $phone = $read_text('phone');
        $role = $read_text('role');
        $status = $read_text('status');
        $raw_shift_id = $read_text('shift_id');
        $password_valid = coreaxis_password_is_valid($password_input);

        $valid = true;
        $lengths = [
            [$full_name, 100],
            [$department, 50],
            [$designation, 50],
            [$email, 100],
            [$phone, 15],
            [$role, 20],
            [$status, 20]
        ];

        foreach ($lengths as [$value, $limit]) {
            $length = $value === null ? false : $max_length($value);
            if ($length === false || $length < 1 || $length > $limit) {
                $valid = false;
                break;
            }
        }

        if (
            !$password_valid
            || $joining_date === null
            || $email === null
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $phone === null
            || !preg_match('/^[0-9+(). -]+$/', $phone)
            || !preg_match('/\d/', $phone)
            || $role === null
            || !in_array($role, ['Admin', 'Employee'], true)
            || $status === null
            || !in_array($status, ['Active', 'Inactive'], true)
            || $department === null
            || $designation === null
            || $full_name === null
            || !preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $salary)
            || !is_numeric($salary)
            || (float) $salary < 0
            || (float) $salary > 99999999.99
            || !is_string($joining_date)
        ) {
            $valid = false;
        }

        $date = $joining_date === null
            ? false
            : DateTime::createFromFormat('!Y-m-d', $joining_date);
        $date_errors = DateTime::getLastErrors();
        if (
            $date === false
            || $date->format('Y-m-d') !== $joining_date
            || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
        ) {
            $valid = false;
        }

        $shift_id = null;
        if ($raw_shift_id !== null && $raw_shift_id !== '') {
            $validated_shift_id = filter_var(
                $raw_shift_id,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
            );
            if ($validated_shift_id === false) {
                $valid = false;
            } else {
                $shift_id = $validated_shift_id;
            }
        }

        if (!$valid) {
            http_response_code(400);
            $error_message = $password_valid
                ? 'Please provide valid values for all employee fields.'
                : 'Password must be between 12 and 72 bytes.';
        } else {
            $employee_id = '';
            $id_query = mysqli_query(
                $conn,
                'SELECT employee_id FROM users ORDER BY id DESC LIMIT 1'
            );

            if ($id_query === false) {
                http_response_code(500);
                $error_message = 'Employee could not be created.';
            } else {
                if (mysqli_num_rows($id_query) > 0) {
                    $last_employee = mysqli_fetch_assoc($id_query);
                    $last_number = (int) preg_replace('/[^0-9]/', '', $last_employee['employee_id']);
                    $next_number = $last_number < 1000 ? 1001 : $last_number + 1;
                } else {
                    $next_number = 1001;
                }

                $employee_id = 'EMP' . $next_number;
                $generated_employee_id = $employee_id;

                $department_check = mysqli_prepare(
                    $conn,
                    "SELECT department_name FROM departments WHERE department_name = ? AND status = 'Active' LIMIT 1"
                );
                $shift_valid = true;
                if ($department_check === false) {
                    $shift_valid = false;
                } else {
                    mysqli_stmt_bind_param($department_check, 's', $department);
                    $department_executed = mysqli_stmt_execute($department_check);
                    mysqli_stmt_store_result($department_check);
                    $department_valid = $department_executed && mysqli_stmt_num_rows($department_check) === 1;
                    mysqli_stmt_close($department_check);
                    if (!$department_valid) {
                        $shift_valid = false;
                    }
                }

                if ($shift_valid && $shift_id !== null) {
                    $shift_check = mysqli_prepare(
                        $conn,
                        "SELECT id FROM shifts WHERE id = ? AND status = 'Active' LIMIT 1"
                    );
                    if ($shift_check === false) {
                        $shift_valid = false;
                    } else {
                        mysqli_stmt_bind_param($shift_check, 'i', $shift_id);
                        $shift_executed = mysqli_stmt_execute($shift_check);
                        mysqli_stmt_store_result($shift_check);
                        $shift_valid = $shift_executed && mysqli_stmt_num_rows($shift_check) === 1;
                        mysqli_stmt_close($shift_check);
                    }
                }

                $email_check = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1');
                $phone_check = mysqli_prepare($conn, 'SELECT id FROM users WHERE phone = ? LIMIT 1');
                $id_check = mysqli_prepare($conn, 'SELECT id FROM users WHERE employee_id = ? LIMIT 1');

                if (!$shift_valid || $email_check === false || $phone_check === false || $id_check === false) {
                    if ($email_check !== false) mysqli_stmt_close($email_check);
                    if ($phone_check !== false) mysqli_stmt_close($phone_check);
                    if ($id_check !== false) mysqli_stmt_close($id_check);
                    http_response_code($shift_valid ? 500 : 400);
                    $error_message = $shift_valid
                        ? 'Employee could not be created.'
                        : 'Please select an active department and a valid shift.';
                } else {
                    mysqli_stmt_bind_param($email_check, 's', $email);
                    $email_query_ok = mysqli_stmt_execute($email_check)
                        && mysqli_stmt_store_result($email_check);
                    $email_exists = $email_query_ok && mysqli_stmt_num_rows($email_check) > 0;
                    mysqli_stmt_close($email_check);

                    mysqli_stmt_bind_param($phone_check, 's', $phone);
                    $phone_query_ok = mysqli_stmt_execute($phone_check)
                        && mysqli_stmt_store_result($phone_check);
                    $phone_exists = $phone_query_ok && mysqli_stmt_num_rows($phone_check) > 0;
                    mysqli_stmt_close($phone_check);

                    mysqli_stmt_bind_param($id_check, 's', $employee_id);
                    $id_query_ok = mysqli_stmt_execute($id_check)
                        && mysqli_stmt_store_result($id_check);
                    $id_exists = $id_query_ok && mysqli_stmt_num_rows($id_check) > 0;
                    mysqli_stmt_close($id_check);

                    if (!$email_query_ok || !$phone_query_ok || !$id_query_ok) {
                        http_response_code(500);
                        $error_message = 'Employee could not be created.';
                    } elseif ($email_exists || $phone_exists || $id_exists) {
                        http_response_code(400);
                        $error_message = $email_exists
                            ? 'Email already exists.'
                            : ($phone_exists ? 'Phone number already exists.' : 'Employee ID already exists.');
                    } else {
                        $password_hash = password_hash($password_input, PASSWORD_DEFAULT);
                        if ($password_hash === false) {
                            http_response_code(500);
                            $error_message = 'Employee could not be created.';
                        } else {
                            $shift_transaction_started = false;
                            if ($shift_id !== null) {
                                if (!mysqli_begin_transaction($conn)) {
                                    http_response_code(500);
                                    $error_message = 'Employee could not be created.';
                                } else {
                                    $shift_transaction_started = true;
                                    // Keep the same shift-first lock used by
                                    // assignment and deletion through the
                                    // employee insert, preventing a selected
                                    // shift from disappearing concurrently.
                                    $shift_insert_lock = mysqli_prepare(
                                        $conn,
                                        'SELECT status FROM shifts WHERE id = ? LIMIT 1 FOR UPDATE'
                                    );
                                    if ($shift_insert_lock === false
                                        || !mysqli_stmt_bind_param($shift_insert_lock, 'i', $shift_id)
                                        || !mysqli_stmt_execute($shift_insert_lock)
                                        || !mysqli_stmt_bind_result($shift_insert_lock, $locked_shift_status)) {
                                        if ($shift_insert_lock instanceof mysqli_stmt) {
                                            mysqli_stmt_close($shift_insert_lock);
                                        }
                                        mysqli_rollback($conn);
                                        $shift_transaction_started = false;
                                        http_response_code(500);
                                        $error_message = 'Employee could not be created.';
                                    } else {
                                        $active_shift_found = mysqli_stmt_fetch($shift_insert_lock);
                                        mysqli_stmt_close($shift_insert_lock);
                                        if (!$active_shift_found || $locked_shift_status !== 'Active') {
                                            mysqli_rollback($conn);
                                            $shift_transaction_started = false;
                                            http_response_code(400);
                                            $error_message = 'Please select an active department and a valid shift.';
                                        }
                                    }
                                }
                            }

                            if ($error_message !== '') {
                                // A failed shift lock or revalidation must not
                                // continue into the employee insert.
                            } else {
                                $insert = mysqli_prepare(
                                    $conn,
                                    'INSERT INTO users (employee_id, full_name, password, department, designation, shift_id, salary, joining_date, role, status, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                                );

                                if ($insert === false) {
                                    if ($shift_transaction_started) {
                                        mysqli_rollback($conn);
                                        $shift_transaction_started = false;
                                    }
                                    http_response_code(500);
                                    $error_message = 'Employee could not be created.';
                                } else {
                                    $bound = mysqli_stmt_bind_param(
                                        $insert,
                                        'sssssissssss',
                                        $employee_id,
                                        $full_name,
                                        $password_hash,
                                        $department,
                                        $designation,
                                        $shift_id,
                                        $salary,
                                        $joining_date,
                                        $role,
                                        $status,
                                        $phone,
                                        $email
                                    );
                                    if ($bound && mysqli_stmt_execute($insert)) {
                                        if ($shift_transaction_started && !mysqli_commit($conn)) {
                                            mysqli_rollback($conn);
                                            $shift_transaction_started = false;
                                            http_response_code(500);
                                            $error_message = 'Employee could not be created.';
                                        } else {
                                            $shift_transaction_started = false;
                                            $success_message = 'Employee Added Successfully';
                                        }
                                    } else {
                                        if ($shift_transaction_started) {
                                            mysqli_rollback($conn);
                                            $shift_transaction_started = false;
                                        }
                                        http_response_code(500);
                                        $error_message = 'Employee could not be created.';
                                    }
                                    mysqli_stmt_close($insert);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$shifts_query = mysqli_query(
    $conn,
    "SELECT id, shift_name FROM shifts WHERE status = 'Active' ORDER BY shift_name ASC"
);
$departments_query = mysqli_query(
    $conn,
    "SELECT department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC"
);

if (!isset($generated_employee_id)) {
    $id_query = mysqli_query(
        $conn,
        'SELECT employee_id FROM users ORDER BY id DESC LIMIT 1'
    );
    if ($id_query === false) {
        http_response_code(500);
        exit('Employee form could not be loaded.');
    }

    if (mysqli_num_rows($id_query) > 0) {
        $last_employee = mysqli_fetch_assoc($id_query);
        $last_number = (int) preg_replace('/[^0-9]/', '', $last_employee['employee_id']);
        $next_number = $last_number < 1000 ? 1001 : $last_number + 1;
    } else {
        $next_number = 1001;
    }
    $generated_employee_id = 'EMP' . $next_number;
}

$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>

<!DOCTYPE html>

<html lang="en">

<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">

       <title>Add Employee</title>

<link rel="stylesheet" href="css/style.css"><link rel="stylesheet" href="css/employee-forms.css?v=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body><div class="main-container">
<?php $active_nav = 'add_employee'; include __DIR__ . '/includes/navigation.php'; ?>
<main class="content employee-form-page"><header class="topbar"><h1>Add Employee</h1><a href="dashboard.php">Back to Dashboard</a></header>

       <section class="employee-form-card"><h2>Employee details</h2>

       <?php if ($error_message !== '') { ?>
              <p role="alert"><?php echo $escape_html($error_message); ?></p>
       <?php } ?>
       <?php if ($success_message !== '') { ?>
              <p class="employee-success" role="status"><?php echo $escape_html($success_message); ?></p>
       <?php } ?>

       <form method="POST">
              <?php echo csrf_field(); ?>

              <label for="employee_id_display">Employee ID</label>



              <input id="employee_id_display" type="text" value="<?php echo $escape_html($generated_employee_id); ?>" readonly>



              <label for="full_name">Full name</label>
<input type="text" id="full_name" name="full_name" placeholder="Full Name" maxlength="100" required>

              <label for="email">Email address</label>
<input type="email" id="email" name="email" placeholder="Email Address" maxlength="100" required>



              <label for="phone">Phone number</label>
<input type="text" id="phone" name="phone" placeholder="Phone Number" maxlength="15" required>





              <label for="password">Password</label>
<input type="password" id="password" name="password" placeholder="Password" required>



              <label for="department">Department</label>
<select id="department" name="department" required>

                     <option value="">
                            Select Department
                     </option>

                     <?php if ($departments_query !== false) { while ($department_row = mysqli_fetch_assoc($departments_query)) { ?>

                            <option value="<?php echo $escape_html($department_row['department_name']); ?>">

                                   <?php echo $escape_html($department_row['department_name']); ?>

                            </option>

                     <?php } } ?>

              </select>



              <label for="designation">Designation</label>
<input type="text" id="designation" name="designation" placeholder="Designation" maxlength="50" required>



              <label for="shift_id">Shift (optional)</label>
<select id="shift_id" name="shift_id">

                     <option value="">
                            Select Shift
                     </option>

                     <?php if ($shifts_query !== false) { while ($shift = mysqli_fetch_assoc($shifts_query)) { ?>

                            <option value="<?php echo $escape_html($shift['id']); ?>">

                                   <?php echo $escape_html($shift['shift_name']); ?>

                            </option>

                     <?php } } ?>

              </select>



              <label for="salary">Salary</label>
<input type="number" id="salary" name="salary" placeholder="Salary" min="0" max="99999999.99" step="0.01" required>



              <label for="joining_date">Joining date</label>
<input type="date" id="joining_date" name="joining_date" required>



              <label for="role">Role</label>
<select id="role" name="role" required>

                     <option value="">Select Role</option>

                     <option value="Admin">Admin</option>

                     <option value="Employee">Employee</option>

              </select>



              <label for="status">Status</label>
<select id="status" name="status" required>

                     <option value="">Select Status</option>

                     <option value="Active">Active</option>

                     <option value="Inactive">Inactive</option>

              </select>



              <button type="submit" name="add_employee">

                     Add Employee

              </button>

       </form>





</section></main></div></body>

</html>
