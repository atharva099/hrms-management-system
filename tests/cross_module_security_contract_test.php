<?php

/** Database-free source contracts for the R5 cross-module security fixes. */
$root = dirname(__DIR__);
$checks = 0;
$assert = static function ($condition, $message) use (&$checks) {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};
$read = static function ($file) use ($root, $assert) {
    $source = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
    $assert(is_string($source), "{$file} is readable");
    return $source;
};
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$auth = $read('includes/auth.php');
$session_start_offset = strpos($auth, 'session_start();');
$assert($session_start_offset !== false, 'shared authentication helper starts PHP sessions');
foreach ([
    "'session.use_strict_mode' => '1'",
    "'session.use_only_cookies' => '1'",
    "'session.use_trans_sid' => '0'",
] as $session_setting) {
    $assert(strpos($auth, $session_setting) !== false, 'session security setting is enforced: ' . $session_setting);
    $assert(strpos($auth, $session_setting) < $session_start_offset, 'session security setting is applied before session_start');
}
$assert(strpos($auth, 'Secure session configuration is unavailable.') !== false, 'session setup fails closed if required protections cannot be applied');

$payload = '<img src=x onerror=alert(1)>';
$escaped_payload = $escape($payload);
$assert($escaped_payload === '&lt;img src=x onerror=alert(1)&gt;', 'HTML text and attribute escaping converts markup to inert text');
$assert(strpos($escaped_payload, '<') === false && strpos($escaped_payload, '>') === false, 'escaped employee-controlled markup cannot form an HTML element');

$employees = $read('employees.php');
$assert(strpos($employees, 'auth_require_admin($conn)') !== false, 'employee search view requires Admin authorization');
$assert(strpos($employees, 'employee_id LIKE ? OR full_name LIKE ? OR department LIKE ?') !== false, 'employee search uses bound SQL parameters');
$assert(strpos($employees, 'LIKE \'%$search%\'') === false, 'employee search contains no request interpolation in SQL');
$assert(strpos($employees, 'mysqli_stmt_bind_param($search_statement, \'sss\'') !== false, 'all employee search terms are bound');
$assert(strpos($employees, '$escape_html($search)') !== false, 'reflected employee search is escaped in its HTML attribute');
foreach (['employee_id', 'full_name', 'department', 'designation'] as $field) {
    $assert(strpos($employees, '$escape_html($row[\'' . $field . '\'])') !== false, "employee {$field} output is escaped");
}
$assert(strpos($employees, 'rawurlencode($row[\'employee_id\'])') !== false, 'employee IDs in profile/edit links are URL encoded');

$profile = $read('employee_profile.php');
$assert(strpos($profile, 'auth_require_authenticated($conn)') !== false, 'profile page requires active authenticated account');
$assert(strpos($profile, 'SELECT * FROM users WHERE employee_id = ? LIMIT 1') !== false, 'profile lookup binds its employee ID');
$assert(strpos($profile, 'http_response_code(403)') !== false && strpos($profile, 'http_response_code(404)') !== false, 'cross-user and nonexistent profile identifiers are rejected server-side');
$assert(strpos($profile, 'strcasecmp($requested_id, $current_employee_id)') !== false, 'Employee profile access is constrained to session ownership');
$assert(strpos($profile, '/^EMP([0-9]{3,17})$/iD') !== false, 'profile IDs use strict employee identifier validation');
$assert(strpos($profile, 'htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\')') !== false, 'profile output uses context-safe HTML escaping');

$department_add = $read('add_department.php');
$assert(strpos($department_add, 'FROM departments WHERE department_name = ?') !== false, 'department duplicate lookup is parameterized');
$assert(strpos($department_add, 'INSERT INTO departments (department_name) VALUES (?)') !== false, 'department insert is parameterized');
$assert(strpos($department_add, 'department_name=\'$department_name\'') === false, 'department operations contain no request interpolation in SQL');
$assert(strpos($department_add, 'preg_match_all(\'/./us\', $department_name') !== false && strpos($department_add, '$department_character_count > 100') !== false, 'department name follows the schema character-length bound');
$assert(strpos($department_add, 'auth_require_admin($conn)') !== false && strpos($department_add, 'csrf_validate_token(') !== false, 'department insert is authenticated and CSRF protected');

$department_page = $read('department_master.php');
$assert(strpos($department_page, '$escape_html($row[\'department_name\'])') !== false, 'department names are escaped when rendered');
$assert(strpos($department_page, '$escape_html($_SESSION[\'success\'])') !== false && strpos($department_page, '$escape_html($_SESSION[\'error\'])') !== false, 'department flash messages are escaped');

$leave_history = $read('leave_history.php');
$assert(strpos($leave_history, 'SELECT * FROM leaves WHERE employee_id = ? ORDER BY id DESC') !== false, 'leave history is scoped with a bound session employee ID');
$assert(strpos($leave_history, '$escape_html($row[\'reason\'])') !== false, 'stored leave reason is escaped before rendering');

$notifications = $read('notifications.php');
$post_start = strpos($notifications, 'if (isset($_POST[\'mark_notifications_read\']))');
$csrf_pos = strpos($notifications, 'csrf_validate_token(', $post_start === false ? 0 : $post_start);
$mutation_pos = strpos($notifications, 'UPDATE leaves', $post_start === false ? 0 : $post_start);
$assert($post_start !== false && $csrf_pos !== false && $mutation_pos > $csrf_pos, 'notification acknowledgement mutation occurs only after POST and CSRF validation');
$assert(strpos($notifications, 'WHERE employee_id = ?') !== false && strpos($notifications, '$user_id') !== false, 'notification acknowledgement is scoped to the authenticated employee');
$assert(strpos($notifications, 'csrf_field()') !== false, 'notification acknowledgement form includes a CSRF token');
$assert(strpos($notifications, '$_POST[\'employee_id\']') === false && strpos($notifications, '$_GET[\'employee_id\']') === false, 'notification endpoint accepts no browser-selected employee identity');
$assert(strpos($notifications, '$update_notification') === false, 'opening notifications does not execute the former GET acknowledgement path');

$salary = $read('salary.php');
$generation_start = strpos($salary, 'isset($_POST[\'generate_salary\'])');
$html_start = strpos($salary, '?>', $generation_start === false ? 0 : $generation_start);
$generation_block = ($generation_start !== false && $html_start !== false)
    ? substr($salary, $generation_start, $html_start - $generation_start)
    : '';
$generation_contract = preg_replace('/\s+/', ' ', $generation_block);
$assert($generation_block !== '', 'salary generation handler can be isolated for review');
$assert(strpos($generation_contract, '$_POST[\'basic_salary\']') === false && strpos($generation_contract, '$_POST[\'incentive\']') === false, 'payroll handler never trusts posted salary or incentive values');
$assert(strpos($generation_contract, 'salary, incentive FROM users') !== false, 'payroll amounts come from the server-side employee record');
$assert(strpos($generation_contract, "role = 'Employee'") !== false && strpos($generation_contract, "status = 'Active'") !== false, 'payroll source is restricted to an active Employee account');
$assert(strpos($generation_contract, '$employee_row[\'salary\']') !== false || strpos($generation_contract, '$amount_row[\'salary\']') !== false || strpos($generation_contract, '$selected_employee[\'salary\']') !== false || strpos($generation_contract, '$salary_value') !== false, 'basic salary uses the authoritative database value');
$assert(strpos($generation_contract, '$employee_row[\'incentive\']') !== false || strpos($generation_contract, '$amount_row[\'incentive\']') !== false || strpos($generation_contract, '$selected_employee[\'incentive\']') !== false || strpos($generation_contract, '$incentive_value') !== false, 'incentive uses the authoritative database value');

$salary_slip = $read('salary_slip.php');
$assert(strpos($salary_slip, 'htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\')') !== false, 'salary slip defines HTML escaping for sensitive profile and payroll output');
$assert(strpos($salary_slip, '$escape_html($row[\'full_name\'])') !== false, 'salary slip escapes employee-controlled name');

$salary_page = $read('salary.php');
$assert(strpos($salary_page, '$escape_salary_html($emp[\'full_name\'])') !== false, 'salary generation options escape employee-controlled names');
$assert(strpos($salary_page, '$escape_salary_html($row[\'full_name\'])') !== false, 'salary table escapes employee-controlled names');
$assert(strpos($salary_page, 'data-month="<?php echo $escape_salary_html(') !== false, 'salary table escapes user-controlled filter attributes');
$assert(strpos($salary_page, 'name="generate_salary" disabled') !== false, 'unsafe policy-dependent payroll generation is visibly disabled');

$dashboard = $read('dashboard.php');
$assert(strpos($dashboard, '$escape_html($recent[\'full_name\'])') !== false && strpos($dashboard, '$escape_html($user[\'department\'])') !== false, 'dashboard escapes employee names and departments');
$assigned_shift = $read('view_assigned_shift.php');
$assert(strpos($assigned_shift, '$escape_html($row[\'full_name\'])') !== false && strpos($assigned_shift, '$escape_html($row[\'department\'])') !== false, 'assigned-shift view escapes names and departments');

foreach (['activate_employee.php', 'deactivate_employee.php'] as $lifecycle_endpoint) {
    $lifecycle = $read($lifecycle_endpoint);
    $assert(strpos($lifecycle, 'auth_require_admin($conn)') !== false, "{$lifecycle_endpoint} requires an active Admin");
    $assert(strpos($lifecycle, "['REQUEST_METHOD']") !== false && strpos($lifecycle, "!== 'POST'") !== false, "{$lifecycle_endpoint} restricts mutation to POST");
    $assert(strpos($lifecycle, 'csrf_validate_token($_POST[\'csrf_token\'] ?? null)') !== false, "{$lifecycle_endpoint} validates CSRF before mutation");
    $assert(strpos($lifecycle, 'WHERE employee_id = ?') !== false && strpos($lifecycle, 'mysqli_stmt_bind_param($update_statement, \'s\', $id)') !== false, "{$lifecycle_endpoint} binds the validated employee ID");
    $assert(strpos($lifecycle, 'WHERE employee_id=\'$id\'') === false, "{$lifecycle_endpoint} does not interpolate request data into SQL");
    $assert(strpos($lifecycle, 'Employee status could not be updated.') !== false, "{$lifecycle_endpoint} does not report success on query failure");
}

echo "Completed {$checks} cross-module security contract checks. No database or HTTP operations were performed.\n";
