<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit("Not found.\n");
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);
mysqli_report(MYSQLI_REPORT_OFF);

function bootstrap_fail(string $message): void
{
    global $conn, $transaction_started, $lock_acquired, $lock_name;
    if (($transaction_started ?? false) && isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $transaction_started = false;
    }
    if (($lock_acquired ?? false) && isset($conn) && $conn instanceof mysqli && is_string($lock_name ?? null)) {
        $release_statement = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release_statement) {
            $release_statement->bind_param('s', $lock_name);
            $release_statement->execute();
            $release_statement->close();
        }
        $lock_acquired = false;
    }
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    fwrite(STDERR, "FAIL [admin_bootstrap] {$message}\n");
    exit(1);
}

$database_mode = getenv('COREAXIS_DB_MODE');
if (is_string($database_mode) && $database_mode !== '') {
    bootstrap_fail('database_mode_must_be_empty');
}

$project_root = realpath(dirname(__DIR__));
$config_path = getenv('COREAXIS_DB_CONFIG_FILE');
if ($project_root === false) {
    bootstrap_fail('project_path_unavailable');
}
if (is_string($config_path) && trim($config_path) !== '') {
    $location_path = $config_path;
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|\\\\\\\\|/)~', $location_path)) {
        $location_path = getcwd() . DIRECTORY_SEPARATOR . $location_path;
    }
    $normalized_location = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $location_path);
    $normalized_root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $project_root), DIRECTORY_SEPARATOR);
    $normalized_root_prefix = $normalized_root . DIRECTORY_SEPARATOR;
    $lexically_inside_project = strcasecmp($normalized_location, $normalized_root) === 0
        || strncasecmp($normalized_location, $normalized_root_prefix, strlen($normalized_root_prefix)) === 0;
    if ($lexically_inside_project) {
        bootstrap_fail('database_configuration_must_be_outside_project');
    }
    $resolved_config = realpath($config_path);
    if ($resolved_config === false || !is_file($resolved_config) || !is_readable($resolved_config)) {
        bootstrap_fail('external_database_configuration_unavailable');
    }
    $root_prefix = rtrim($project_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $inside_project = strcasecmp($resolved_config, $project_root) === 0
        || strncasecmp($resolved_config, $root_prefix, strlen($root_prefix)) === 0;
    if ($inside_project) {
        bootstrap_fail('database_configuration_must_be_outside_project');
    }
    $config_source = file_get_contents($resolved_config);
    if (!is_string($config_source) || preg_match('/[\'\"]mode[\'\"]\s*=>/i', $config_source) === 1) {
        bootstrap_fail('database_configuration_mode_must_be_empty');
    }
    unset($config_source);
}
unset($database_mode, $config_path, $resolved_config, $root_prefix, $inside_project);

$payload_line = fgets(STDIN);
if (!is_string($payload_line) || strlen($payload_line) > 16384) {
    bootstrap_fail('input_unavailable');
}
$payload = json_decode($payload_line, true);
if (!is_array($payload)) {
    bootstrap_fail('input_invalid');
}

$required_fields = ['database', 'database_port', 'employee_id', 'full_name', 'department', 'designation', 'joining_date', 'phone', 'email', 'password'];
foreach ($required_fields as $field) {
    if (!isset($payload[$field]) || !is_string($payload[$field])) {
        bootstrap_fail('required_profile_input_invalid');
    }
}
$database_confirmation = $payload['database'];
$port_confirmation = $payload['database_port'];
$employee_id = strtoupper(trim($payload['employee_id']));
$full_name = trim($payload['full_name']);
$department = trim($payload['department']);
$designation = trim($payload['designation']);
$joining_date = trim($payload['joining_date']);
$phone = trim($payload['phone']);
$email = trim($payload['email']);
$password = $payload['password'];

require_once dirname(__DIR__) . '/includes/password_policy.php';
if (!coreaxis_password_is_valid($password)) {
    bootstrap_fail('password_must_be_12_to_72_bytes');
}
if ($database_confirmation === '' || preg_match('/^[A-Za-z0-9_]+$/D', $database_confirmation) !== 1
    || preg_match('/^[0-9]{1,5}$/D', $port_confirmation) !== 1 || (int) $port_confirmation < 1 || (int) $port_confirmation > 65535
    || preg_match('/^EMP[0-9]{1,17}$/D', $employee_id) !== 1
    || $full_name === '' || strlen($full_name) > 100
    || $department === '' || strlen($department) > 50
    || $designation === '' || strlen($designation) > 50
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 100
    || strlen($phone) < 7 || strlen($phone) > 15
    || preg_match('/^[0-9+(). -]+$/D', $phone) !== 1 || preg_match('/[0-9]/', $phone) !== 1) {
    bootstrap_fail('profile_validation_failed');
}
$date = DateTime::createFromFormat('!Y-m-d', $joining_date);
$date_errors = DateTime::getLastErrors();
if ($date === false || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
    || $date->format('Y-m-d') !== $joining_date) {
    bootstrap_fail('joining_date_invalid');
}

require dirname(__DIR__) . '/includes/db.php';
if (!isset($conn) || !$conn instanceof mysqli) {
    bootstrap_fail('database_connection_unavailable');
}
$conn->set_charset('utf8mb4');

$lock_acquired = false;
$transaction_started = false;
$insert_statement = null;
$lock_name = null;
$conn = null;
try {
    $identity_result = $conn->query('SELECT DATABASE(), @@port, VERSION(), @@version_comment, CURRENT_USER()');
    $identity = $identity_result instanceof mysqli_result ? $identity_result->fetch_row() : false;
    if ($identity_result instanceof mysqli_result) {
        $identity_result->free();
    }
    if (!is_array($identity) || ($identity[0] ?? null) !== $database_confirmation
        || (string) ($identity[1] ?? '') !== (string) (int) $port_confirmation
        || !preg_match('/^8\.[0-9]+\.[0-9]+(?:-|$)/', (string) ($identity[2] ?? ''))
        || version_compare((string) ($identity[2] ?? ''), '8.0.16', '<')
        || stripos((string) ($identity[3] ?? ''), 'mariadb') !== false
        || !is_string($identity[4] ?? null)
        || strcasecmp((string) strtok($identity[4], '@'), 'root') === 0) {
        bootstrap_fail('mysql8_database_identity_mismatch');
    }

    $expected_tables = [
        'attendance', 'attendance_correction_audit', 'departments', 'holidays', 'leave_attendance_audit',
        'leaves', 'salaries', 'shifts', 'users',
    ];
    $table_result = $conn->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    if (!$table_result instanceof mysqli_result) {
        bootstrap_fail('schema_validation_failed');
    }
    $tables = [];
    while ($row = $table_result->fetch_assoc()) {
        $tables[(string) $row['TABLE_NAME']] = (string) $row['ENGINE'];
    }
    $table_result->free();
    foreach ($expected_tables as $table) {
        if (($tables[$table] ?? null) !== 'InnoDB') {
            bootstrap_fail('schema_validation_failed');
        }
    }

    $required_foreign_keys = [
        'fk_users_shift_id_shifts', 'fk_attendance_employee', 'fk_leaves_employee', 'fk_salaries_employee',
        'fk_laa_actor', 'fk_laa_attendance', 'fk_laa_conflicting_leave', 'fk_laa_employee', 'fk_laa_leave',
        'fk_aca_attendance', 'fk_aca_employee_user', 'fk_aca_actor_user', 'fk_aca_salary',
    ];
    $foreign_key_result = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    if (!$foreign_key_result instanceof mysqli_result) {
        bootstrap_fail('schema_validation_failed');
    }
    $foreign_keys = [];
    while ($row = $foreign_key_result->fetch_assoc()) {
        $foreign_keys[(string) $row['CONSTRAINT_NAME']] = true;
    }
    $foreign_key_result->free();
    foreach ($required_foreign_keys as $foreign_key) {
        if (!isset($foreign_keys[$foreign_key])) {
            bootstrap_fail('schema_validation_failed');
        }
    }

    $required_checks = [
        'chk_shifts_early_checkin_range', 'chk_shifts_late_cutoff_range', 'chk_shifts_checkin_windows_do_not_overlap',
        'chk_users_role', 'chk_users_status', 'chk_attendance_row_version', 'chk_attendance_policy_snapshot_pair_window',
        'chk_leaves_date_order', 'chk_salaries_period_contract', 'chk_salaries_payment_status',
        'chk_aca_version_increases', 'chk_aca_reason_nonblank_length', 'chk_aca_evidence_type_nonblank',
        'chk_aca_evidence_reference_nonblank', 'chk_aca_payroll_reconciliation_ref',
    ];
    $check_result = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'");
    if (!$check_result instanceof mysqli_result) {
        bootstrap_fail('schema_validation_failed');
    }
    $checks = [];
    while ($row = $check_result->fetch_assoc()) {
        $checks[(string) $row['CONSTRAINT_NAME']] = true;
    }
    $check_result->free();
    foreach ($required_checks as $check) {
        if (!isset($checks[$check])) {
            bootstrap_fail('schema_validation_failed');
        }
    }

    $required_columns = [
        'id' => ['int', 'NO', 'auto_increment'],
        'employee_id' => ['varchar(20)', 'NO', ''],
        'full_name' => ['varchar(100)', 'NO', ''],
        'password' => ['varchar(255)', 'NO', ''],
        'department' => ['varchar(50)', 'NO', ''],
        'designation' => ['varchar(50)', 'NO', ''],
        'salary' => ['decimal(10,2)', 'NO', ''],
        'incentive' => ['decimal(10,2)', 'NO', ''],
        'joining_date' => ['date', 'NO', ''],
        'role' => ['varchar(20)', 'NO', ''],
        'status' => ['varchar(20)', 'NO', ''],
        'phone' => ['varchar(15)', 'NO', ''],
        'email' => ['varchar(100)', 'NO', ''],
        'shift_id' => ['int', 'YES', ''],
    ];
    $column_result = $conn->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
    if (!$column_result instanceof mysqli_result) {
        bootstrap_fail('schema_validation_failed');
    }
    $columns = [];
    while ($row = $column_result->fetch_assoc()) {
        $columns[(string) $row['COLUMN_NAME']] = [strtolower((string) $row['COLUMN_TYPE']), (string) $row['IS_NULLABLE'], strtolower((string) $row['EXTRA'])];
    }
    $column_result->free();
    foreach ($required_columns as $column => $expected) {
        $actual = $columns[$column] ?? null;
        if (!is_array($actual) || $actual[0] !== $expected[0] || $actual[1] !== $expected[1]
            || ($expected[2] !== '' && strpos($actual[2], $expected[2]) === false)) {
            bootstrap_fail('schema_validation_failed');
        }
    }

    $unique_result = $conn->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns, MIN(NON_UNIQUE) AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' GROUP BY INDEX_NAME");
    if (!$unique_result instanceof mysqli_result) {
        bootstrap_fail('schema_validation_failed');
    }
    $unique_columns = [];
    while ($row = $unique_result->fetch_assoc()) {
        if ((int) $row['non_unique'] === 0) {
            $unique_columns[(string) $row['indexed_columns']] = true;
        }
    }
    $unique_result->free();
    if (!isset($unique_columns['employee_id'], $unique_columns['email'])) {
        bootstrap_fail('schema_validation_failed');
    }

    $required_triggers = [
        'leave_attendance_audit_no_update', 'leave_attendance_audit_no_delete',
        'trg_aca_no_update', 'trg_aca_no_delete',
    ];
    $trigger_result = $conn->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
    if (!$trigger_result instanceof mysqli_result) {
        bootstrap_fail('schema_validation_failed');
    }
    $triggers = [];
    while ($row = $trigger_result->fetch_assoc()) {
        $triggers[(string) $row['TRIGGER_NAME']] = true;
    }
    $trigger_result->free();
    foreach ($required_triggers as $trigger) {
        if (!isset($triggers[$trigger])) {
            bootstrap_fail('schema_validation_failed');
        }
    }

    $lock_name = 'coreaxis_admin_bootstrap_' . substr(hash('sha256', $database_confirmation), 0, 32);
    $lock_statement = $conn->prepare('SELECT GET_LOCK(?, 10)');
    if (!$lock_statement) {
        bootstrap_fail('bootstrap_lock_unavailable');
    }
    $lock_statement->bind_param('s', $lock_name);
    $lock_statement->execute();
    $lock_statement->bind_result($lock_result);
    $lock_statement->fetch();
    $lock_statement->close();
    if ((int) $lock_result !== 1) {
        bootstrap_fail('bootstrap_lock_unavailable');
    }
    $lock_acquired = true;

    if (!$conn->begin_transaction()) {
        bootstrap_fail('database_transaction_unavailable');
    }
    $transaction_started = true;
    $existing_user_result = $conn->query('SELECT id FROM users LIMIT 1 FOR UPDATE');
    if (!$existing_user_result instanceof mysqli_result) {
        bootstrap_fail('existing_user_check_failed');
    }
    $has_existing_user = $existing_user_result->num_rows > 0;
    $existing_user_result->free();
    if ($has_existing_user) {
        bootstrap_fail('users_table_not_empty');
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($password_hash)) {
        bootstrap_fail('password_hash_failed');
    }
    $insert_statement = $conn->prepare("INSERT INTO users (employee_id, full_name, password, department, designation, shift_time, salary, incentive, joining_date, role, status, phone, email, shift_id) VALUES (?, ?, ?, ?, ?, NULL, 0.00, 0.00, ?, 'Admin', 'Active', ?, ?, NULL)");
    if (!$insert_statement) {
        bootstrap_fail('admin_insert_unavailable');
    }
    $insert_statement->bind_param('ssssssss', $employee_id, $full_name, $password_hash, $department, $designation, $joining_date, $phone, $email);
    if (!$insert_statement->execute() || $insert_statement->affected_rows !== 1) {
        bootstrap_fail('admin_insert_failed');
    }
    $new_user_id = $insert_statement->insert_id;
    $insert_statement->close();
    $insert_statement = null;
    $verify_statement = $conn->prepare('SELECT employee_id, role, status, password FROM users WHERE id = ?');
    if (!$verify_statement) {
        bootstrap_fail('admin_insert_verification_failed');
    }
    $verify_statement->bind_param('i', $new_user_id);
    if (!$verify_statement->execute()) {
        $verify_statement->close();
        bootstrap_fail('admin_insert_verification_failed');
    }
    $verify_statement->bind_result($stored_employee_id, $stored_role, $stored_status, $stored_password_hash);
    $verified_row = $verify_statement->fetch();
    $verify_statement->close();
    if ($verified_row !== true || $stored_employee_id !== $employee_id || $stored_role !== 'Admin'
        || $stored_status !== 'Active' || !password_verify($password, (string) $stored_password_hash)) {
        bootstrap_fail('admin_insert_verification_failed');
    }
    if (!$conn->commit()) {
        bootstrap_fail('database_commit_failed');
    }
    $transaction_started = false;
    fwrite(STDOUT, "PASS [admin_bootstrap] one_active_admin_created\n");
} catch (Throwable $error) {
    if ($insert_statement instanceof mysqli_stmt) {
        $insert_statement->close();
    }
    bootstrap_fail('bootstrap_operation_failed');
} finally {
    if ($insert_statement instanceof mysqli_stmt) {
        $insert_statement->close();
    }
    if ($transaction_started) {
        $conn->rollback();
    }
    if ($lock_acquired && is_string($lock_name)) {
        $release_statement = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release_statement) {
            $release_statement->bind_param('s', $lock_name);
            $release_statement->execute();
            $release_statement->close();
        }
    }
    if ($conn instanceof mysqli) {
        $conn->close();
    }
}
