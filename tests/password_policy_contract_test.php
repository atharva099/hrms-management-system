<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/password_policy.php';

$checks = 0;
$assert = static function ($condition, $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(!coreaxis_password_is_valid(str_repeat('x', 11)), '11-byte password is rejected');
$assert(coreaxis_password_is_valid(str_repeat('x', 12)), '12-byte password is accepted');
$assert(coreaxis_password_is_valid(str_repeat('x', 72)), '72-byte password is accepted');
$assert(!coreaxis_password_is_valid(str_repeat('x', 73)), '73-byte password is rejected');
$assert(coreaxis_password_is_valid(str_repeat('é', 6)), 'multibyte password is measured as 12 UTF-8 bytes');
$assert(!coreaxis_password_is_valid(str_repeat('é', 5)), 'multibyte password below 12 bytes is rejected');
$assert(coreaxis_password_is_valid(str_repeat('é', 36)), 'multibyte password at the 72-byte boundary is accepted');
$assert(!coreaxis_password_is_valid(str_repeat('é', 37)), 'multibyte password above the 72-byte boundary is rejected');
$assert(coreaxis_password_is_valid('           x'), 'password bytes are validated as supplied without trimming');
$assert(!coreaxis_password_is_valid(null), 'non-string password input is rejected');

$root = dirname(__DIR__);
$add_source = file_get_contents($root . '/add_employee.php');
$edit_source = file_get_contents($root . '/edit_employee.php');
$profile_source = file_get_contents($root . '/edit_profile.php');
$profile_helper_source = file_get_contents($root . '/includes/profile_security.php');
foreach ([
    'Admin creation source is readable' => is_string($add_source),
    'Admin edit source is readable' => is_string($edit_source),
    'self-service source is readable' => is_string($profile_source),
    'profile security helper source is readable' => is_string($profile_helper_source),
] as $message => $condition) {
    $assert($condition, $message);
}

$assert(
    strpos($add_source, "require_once __DIR__ . '/includes/password_policy.php';") !== false
        && strpos($add_source, 'coreaxis_password_is_valid($password_input)') !== false,
    'Admin employee creation uses the shared password policy'
);
$assert(
    strpos($edit_source, "require_once __DIR__ . '/includes/password_policy.php';") !== false
        && strpos($edit_source, 'coreaxis_password_is_valid($new_password)') !== false,
    'Admin employee password changes use the shared password policy'
);
$assert(
    strpos($profile_source, 'coreaxis_password_is_valid($new_password)') !== false
        && strpos($profile_source, "require_once __DIR__ . '/includes/profile_security.php';") !== false
        && strpos($profile_helper_source, 'return coreaxis_password_is_valid($password);') !== false,
    'self-service password changes use the shared password policy'
);
$assert(
    strpos($edit_source, '$new_password !== null && $new_password !== \'\'') !== false
        && strpos($edit_source, 'UPDATE users SET full_name = ?, department = ?, designation = ?, salary = ?, role = ?, status = ? WHERE employee_id = ?') !== false,
    'blank Admin edit password follows the update statement that preserves the current hash'
);
$assert(
    strpos($add_source, 'password_hash($password_input, PASSWORD_DEFAULT)') !== false
        && strpos($edit_source, 'password_hash($new_password, PASSWORD_DEFAULT)') !== false
        && strpos($profile_source, 'password_hash($new_password, PASSWORD_DEFAULT)') !== false,
    'all three workflows preserve PASSWORD_DEFAULT hashing'
);

echo "PASS: {$checks} password policy contract checks\n";
