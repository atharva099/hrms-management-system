<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

$shifts_query = mysqli_query(
       $conn,
       "SELECT id, shift_name FROM shifts ORDER BY shift_name ASC"
);
$departments_query = mysqli_query(
       $conn,
       "SELECT department_name
        FROM departments
        WHERE status='Active'
        ORDER BY department_name ASC"
);

$id_query = mysqli_query(
       $conn,
       "SELECT employee_id
     FROM users
     ORDER BY id DESC
     LIMIT 1"
);

if (mysqli_num_rows($id_query) > 0) {

       $last_employee = mysqli_fetch_assoc($id_query);

       $last_number = (int) preg_replace(
              '/[^0-9]/',
              '',
              $last_employee['employee_id']
       );

       if ($last_number < 1000) {

              $next_number = 1001;

       } else {

              $next_number = $last_number + 1;
       }

} else {

       $next_number = 1001;
}

$generated_employee_id = "EMP" . $next_number;

if (isset($_POST['add_employee'])) {

       $employee_id = $generated_employee_id;

       $full_name = $_POST['full_name'];

       $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

       $department = $_POST['department'];

       $designation = $_POST['designation'];

       $shift_id = !empty($_POST['shift_id'])
              ? $_POST['shift_id']
              : NULL;

       $salary = $_POST['salary'];

       $joining_date = $_POST['joining_date'];

       $email = trim($_POST['email']);

       $phone = trim($_POST['phone']);

       $role = $_POST['role'];

       if (!in_array($role, ['Admin', 'Employee'], true)) {
              die("Invalid role.");
       }

       $status = $_POST['status'];

       $email_check =
              mysqli_query(
                     $conn,
                     "SELECT id
     FROM users
     WHERE email='$email'"
              );

       if (mysqli_num_rows($email_check) > 0) {
              die("Email already exists.");
       }

       $phone_check =
              mysqli_query(
                     $conn,
                     "SELECT id
        FROM users
        WHERE phone='$phone'"
              );

       if (mysqli_num_rows($phone_check) > 0) {
              die("Phone number already exists.");
       }

       if ($salary < 0) {
              die("Salary cannot be negative.");
       }

       $id_check = mysqli_query(
              $conn,
              "SELECT id
     FROM users
     WHERE employee_id='$employee_id'"
       );

       if (mysqli_num_rows($id_check) > 0) {

              die("Employee ID already exists.");
       }

       $query = "INSERT INTO users

    (
    employee_id,
full_name,
password,
department,
designation,
shift_id,
salary,
joining_date,
role,
status,
phone,
email
 )

    VALUES

    (
    '$employee_id',
    '$full_name',
    '$password',
    '$department',
    '$designation',
    " . ($shift_id === NULL ? "NULL" : "'$shift_id'") . ",
'$salary',
'$joining_date',
    '$role',
    '$status',
    '$phone',
'$email'
    )";

       mysqli_query($conn, $query);

       echo "Employee Added Successfully";
}

?>

<!DOCTYPE html>

<html>

<head>

       <title>Add Employee</title>

</head>

<body>

       <h2>Add Employee</h2>

       <form method="POST">

              <label>Employee ID</label>

              <br>

              <input type="text" value="<?php echo $generated_employee_id; ?>" readonly>

              <br><br>

              <input type="text" name="full_name" placeholder="Full Name" required>

              <input type="email" name="email" placeholder="Email Address" required>

              <br><br>

              <input type="text" name="phone" placeholder="Phone Number" maxlength="15" required>

              <br><br>

              <br><br>

              <input type="password" name="password" placeholder="Password" required>

              <br><br>

              <select name="department" required>

                     <option value="">
                            Select Department
                     </option>

                     <?php while ($department = mysqli_fetch_assoc($departments_query)) { ?>

                            <option value="<?php echo $department['department_name']; ?>">

                                   <?php echo $department['department_name']; ?>

                            </option>

                     <?php } ?>

              </select>

              <br><br>

              <input type="text" name="designation" placeholder="Designation" required>

              <br><br>

              <select name="shift_id">

                     <option value="">
                            Select Shift
                     </option>

                     <?php while ($shift = mysqli_fetch_assoc($shifts_query)) { ?>

                            <option value="<?php echo $shift['id']; ?>">

                                   <?php echo $shift['shift_name']; ?>

                            </option>

                     <?php } ?>

              </select>

              <br><br>

              <input type="number" name="salary" placeholder="Salary" required>

              <br><br>

              <input type="date" name="joining_date" required>

              <br><br>

              <select name="role" required>

                     <option value="">Select Role</option>

                     <option value="Admin">Admin</option>

                     <option value="Employee">Employee</option>

              </select>

              <br><br>

              <select name="status" required>

                     <option value="">Select Status</option>

                     <option value="Active">Active</option>

                     <option value="Inactive">Inactive</option>

              </select>

              <br><br>

              <button type="submit" name="add_employee">

                     Add Employee

              </button>

       </form>

       <br><br>

       <a href="dashboard.php">
              Back To Dashboard
       </a>

</body>

</html>
