<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/profile_security.php';

$checks = 0;
$assert = static function ($condition, $description) use (&$checks) {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
};

$profile_source = file_get_contents(__DIR__ . '/../edit_profile.php');
$assert(is_string($profile_source), 'profile controller source is readable');
$assert(strpos($profile_source, 'auth_require_authenticated($conn)') !== false, 'active authenticated session is required');
$assert(strpos($profile_source, 'auth_current_employee_id') === false, 'profile identity is obtained through the shared authenticated guard return value');
$assert(strpos($profile_source, '$_GET') === false && strpos($profile_source, '$_REQUEST') === false, 'request cannot select another profile identity');
$assert(strpos($profile_source, '$_POST[\'employee_id\']') === false, 'POST cannot select another profile identity');

$method_guard = strpos($profile_source, 'if ($request_method !== \'GET\' && $request_method !== \'POST\')');
$csrf_guard = strpos($profile_source, 'if (!csrf_validate_token($_POST[\'csrf_token\'] ?? null))');
$mutation_dispatch = strpos($profile_source, 'if ($action === \'delete_image\')');
$assert($method_guard !== false && $csrf_guard !== false && $mutation_dispatch !== false, 'controller has method, CSRF, and action gates');
$assert($method_guard < $csrf_guard && $csrf_guard < $mutation_dispatch, 'method and CSRF checks occur before mutation dispatch');
$assert(substr_count($profile_source, 'csrf_field()') === 2, 'both update and image deletion forms include CSRF fields');
$assert(substr_count($profile_source, '<form method="POST"') === 2, 'both state-changing forms submit POST');

$assert(strpos($profile_source, 'SELECT * FROM users') === false, 'profile query does not select password or protected fields');
$assert(strpos($profile_source, 'UPDATE users SET full_name = ?, phone = ?, email = ? WHERE employee_id = ?') !== false, 'profile update uses bound parameters and session identity');
$assert(strpos($profile_source, 'UPDATE users SET full_name = ?, phone = ?, email = ?, password = ? WHERE employee_id = ?') !== false, 'password update uses a separate bound-parameter statement');
$assert(strpos($profile_source, 'SET role') === false && strpos($profile_source, 'SET status') === false && strpos($profile_source, 'SET salary') === false, 'self-service update excludes role, status, and salary');
$assert(strpos($profile_source, 'password_verify($current_password, $stored_password)') !== false, 'password change requires current-password verification');
$assert(strpos($profile_source, 'password_hash($new_password, PASSWORD_DEFAULT)') !== false, 'new password is hashed with the application mechanism');
$assert(strpos($profile_source, 'name="password"') === false && strpos($profile_source, 'value="<?php echo $user[\'password\']') === false, 'password or hash is never rendered or prefilled');
$assert(strpos($profile_source, 'if ($password_change_requested)') !== false, 'blank new password follows the no-password-update statement path');

$assert(strpos($profile_source, 'profile_stage_uploaded_image($upload)') !== false, 'uploads are content-validated and staged before installation');
$assert(strpos($profile_source, 'profile_install_staged_image($staged_image, $employee_id, $images_directory)') !== false, 'image destination is derived from the authenticated employee ID');
$assert(strpos($profile_source, 'profile_delete_owned_image($employee_id, $images_directory)') !== false, 'deletion targets only the authenticated employee image');
$assert(strpos($profile_source, 'move_uploaded_file') === false, 'unvalidated client filename is never moved directly');
$assert(strpos($profile_source, 'profile_escape($form_values[') !== false, 'database/form values are escaped in HTML attributes');
$assert(strpos($profile_source, 'profile_escape($profile_image)') !== false, 'image URL is escaped in its HTML attribute');

$valid = profile_validate_fields('O\'Neil Example', '555-010-2040', 'person@example.test');
$assert(is_array($valid), 'ordinary profile fields pass validation');
$sql_payload = "Robert'); UPDATE users SET role='Admin' WHERE ('1'='1";
$sql_result = profile_validate_fields($sql_payload, '', '');
$assert(is_array($sql_result) && $sql_result['full_name'] === $sql_payload, 'SQL-like input remains data for prepared statements, not executable SQL');
$xss_payload = '<img src=x onerror=alert(1)>';
$xss_result = profile_validate_fields($xss_payload, '', '');
$assert(is_array($xss_result), 'XSS-shaped profile text can be stored as ordinary data');
$assert(profile_escape($xss_payload) === '&lt;img src=x onerror=alert(1)&gt;', 'HTML text/attribute escaping encodes an XSS payload');
$assert(profile_validate_fields(str_repeat('x', 101), '', '') === false, 'overlong name is rejected');
$assert(profile_validate_fields('Valid Name', 'call-me', '') === false, 'invalid phone characters are rejected');
$assert(profile_validate_fields('Valid Name', '', 'not-an-email') === false, 'invalid email is rejected');
$assert(profile_valid_employee_id('EMP9801'), 'existing employee ID format is accepted');
$assert(!profile_valid_employee_id('../EMP9801') && !profile_valid_employee_id('EMP1'), 'malformed and path-traversal employee IDs are rejected');
$assert(!profile_validate_new_password('short') && profile_validate_new_password(str_repeat('P', 16)), 'new password length policy is enforced');

$invalid_image = tempnam(sys_get_temp_dir(), 'profile-invalid-');
$assert($invalid_image !== false, 'temporary invalid image fixture can be created');
file_put_contents($invalid_image, 'not an image');
$assert(profile_inspect_image_file($invalid_image) === false, 'non-image content is rejected despite being a readable file');
unlink($invalid_image);
$assert(!profile_delete_owned_image('../outside', sys_get_temp_dir()), 'unauthorized/path-traversal image deletion is refused');

if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
}
auth_start_session();
$_SESSION['csrf_token'] = str_repeat('a', 64);
$assert(csrf_validate_token(str_repeat('a', 64)), 'valid session-bound CSRF token is accepted');
$assert(!csrf_validate_token(null), 'missing CSRF is rejected');
$assert(!csrf_validate_token(str_repeat('b', 64)), 'invalid CSRF is rejected');
unset($_SESSION['csrf_token']);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

echo "PASS: {$checks} edit-profile security contract checks\n";
