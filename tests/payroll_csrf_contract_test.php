<?php

declare(strict_types=1);

ob_start();

function payrollContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

function payrollContractBlock(string $source, string $start, string $end): string
{
    $start_offset = strpos($source, $start);
    if ($start_offset === false) {
        throw new RuntimeException('Required payroll action block was not found.');
    }

    $end_offset = strpos($source, $end, $start_offset + strlen($start));
    if ($end_offset === false) {
        throw new RuntimeException('Payroll action block boundary was not found.');
    }

    return substr($source, $start_offset, $end_offset - $start_offset);
}

$project_root = dirname(__DIR__);
$salary_path = getenv('COREAXIS_SALARY_CONTRACT_SOURCE');
if (!is_string($salary_path) || $salary_path === '') {
    $salary_path = $project_root . '/salary.php';
}
$salary_source = file_get_contents($salary_path);
$auth_source = file_get_contents($project_root . '/includes/auth.php');
payrollContractAssert(is_string($salary_source) && is_string($auth_source), 'salary and auth sources are readable');

$process_block = payrollContractBlock(
    $salary_source,
    "if (isset(\$_POST['process_payroll'])) {",
    "isset(\$_POST['generate_salary'])) {"
);
$generate_block = payrollContractBlock(
    $salary_source,
    "isset(\$_POST['generate_salary'])) {",
    'if ($is_admin) {'
);

foreach (['process_payroll' => $process_block, 'generate_salary' => $generate_block] as $action => $block) {
    $admin_offset = strpos($block, 'auth_require_admin($conn);');
    $post_offset = strpos($block, "(\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'");
    $csrf_offset = strpos($block, "csrf_validate_token(\$_POST['csrf_token'] ?? null)");
    $mutation_offset = $action === 'process_payroll'
        ? strpos($block, 'UPDATE salaries')
        : strpos($block, 'INSERT INTO salaries');

    payrollContractAssert(
        $admin_offset !== false && $post_offset !== false && $csrf_offset !== false
            && $mutation_offset !== false && $admin_offset < $post_offset
            && $post_offset < $csrf_offset && $csrf_offset < $mutation_offset,
        "{$action} requires Admin, POST, and CSRF before salary mutation"
    );
}

payrollContractAssert(
    preg_match('/<form method="POST" class="salary-form">\s*<\?php echo csrf_field\(\); \?>/s', $salary_source) === 1,
    'salary generation form submits a session-bound CSRF token'
);
payrollContractAssert(
    preg_match('/<form method="POST">\s*<\?php echo csrf_field\(\); \?>/s', $salary_source) === 1,
    'payroll processing form submits a session-bound CSRF token'
);

$salary_mutations = preg_match_all('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+salaries\b/i', $salary_source);
payrollContractAssert($salary_mutations === 2, 'salary.php has only the two reviewed salary mutation statements');
payrollContractAssert(
    strpos($auth_source, "\$_SESSION['csrf_token'] = bin2hex(random_bytes(32));") !== false
        && strpos($auth_source, 'hash_equals($_SESSION[\'csrf_token\'], $submitted_token)') !== false,
    'CSRF token is generated with secure randomness and compared in constant time'
);
payrollContractAssert(
    strpos($auth_source, 'function auth_require_admin($conn)') !== false
        && strpos($auth_source, "return auth_require_role(\$conn, 'Admin');") !== false,
    'Admin authorization is enforced by the shared server-side role guard'
);
payrollContractAssert(
    strpos($auth_source, 'if (!in_array(auth_current_role(), $roles, true))') !== false,
    'Employee role and direct unauthorized requests fail the Admin role guard'
);

// Exercise the real shared CSRF helper in CLI with isolated temporary session IDs.
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
session_save_path(sys_get_temp_dir());
session_id('payrollcsrf' . bin2hex(random_bytes(12)));
require_once $project_root . '/includes/auth.php';

auth_start_session();
$valid_token = csrf_get_token();
payrollContractAssert(preg_match('/^[a-f0-9]{64}$/D', $valid_token) === 1, 'Admin-form token has the expected secure random representation');
payrollContractAssert(csrf_validate_token($valid_token), 'valid session-bound Admin POST token is accepted');
payrollContractAssert(!csrf_validate_token(null), 'missing CSRF token is rejected');
payrollContractAssert(!csrf_validate_token('invalid-token'), 'invalid CSRF token is rejected');

$expired_token = $valid_token;
session_destroy();
session_id('payrollexpired' . bin2hex(random_bytes(12)));
auth_start_session();
payrollContractAssert(!csrf_validate_token($expired_token), 'token from an expired/replaced session is rejected');

$_SESSION = [];
session_destroy();

$other_php_files = glob($project_root . '/*.php') ?: [];
$other_mutation_files = [];
foreach ($other_php_files as $path) {
    if (basename($path) === 'salary.php') {
        continue;
    }
    $source = file_get_contents($path);
    if (is_string($source) && preg_match('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+salaries\b/i', $source)) {
        $other_mutation_files[] = basename($path);
    }
}
payrollContractAssert($other_mutation_files === [], 'no other top-level PHP endpoint mutates salary records');

echo "Static payroll CSRF contract tests completed; no database or HTTP requests were performed.\n";
ob_end_flush();
