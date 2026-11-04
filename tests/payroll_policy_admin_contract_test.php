<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/payroll_policy.php');
$service = file_get_contents($root . '/includes/payroll_policy_admin.php');
$preview = file_get_contents($root . '/payroll_preview.php');
if (!is_string($page) || !is_string($service) || !is_string($preview)) {
    fwrite(STDERR, "FAIL: payroll policy admin sources unavailable\n");
    exit(1);
}
$checks = [
    'policy page requires active Admin before handling methods' => strpos($page, 'auth_require_admin($conn);') < strpos($page, 'if (($_SERVER[\'REQUEST_METHOD\'] ?? \'\') === \'POST\')'),
    'all mutations require session-bound CSRF' => strpos($page, 'csrf_validate_token($_POST[\'csrf_token\'] ?? null)') !== false
        && substr_count($page, 'csrf_field()') >= 4,
    'page rejects unsupported HTTP methods' => strpos($page, "header('Allow: GET, POST', true, 405)") !== false,
    'currency starts at INR but remains explicit and configurable' => strpos($service, "COREAXIS_PAYROLL_POLICY_CURRENCY_DEFAULT = 'INR'") !== false
        && strpos($page, '$defaultCurrency = COREAXIS_PAYROLL_POLICY_CURRENCY_DEFAULT;') !== false
        && strpos($page, 'name="comp_currency"') !== false,
    'compensation uses a distinct approval and no legacy salary fallback' => strpos($service, 'payroll_compensation_requires_distinct_admin_approval') !== false
        && strpos(file_get_contents($root . '/includes/payroll_database.php'), 'payroll_compensation_versions') !== false
        && strpos(file_get_contents($root . '/includes/payroll_database.php'), 'users.salary') === false,
    'rule amounts and leave classifications are mandatory input' => strpos($service, 'payroll_policy_rules_incomplete') !== false
        && strpos($service, 'payroll_leave_classification_required') !== false,
    'draft approval requires a different active Admin and an audit reason' => strpos($service, 'payroll_policy_requires_distinct_admin_approval') !== false
        && strpos($service, 'approval_reason') !== false,
    'approved effective-date overlap is rejected under serialized approval' => strpos($service, 'GET_LOCK') !== false
        && strpos($service, 'approved_payroll_policy_period_overlap') !== false,
    'schedule creation snapshots shift data from the database' => strpos($service, 'SELECT id,shift_name,in_time,out_time FROM shifts WHERE id=? AND status=\'Active\'') !== false,
    'schedule approval rejects another approved overlap and requires a second Admin' => strpos($service, 'payroll_schedule_period_overlap') !== false
        && strpos($service, 'payroll_schedule_requires_distinct_admin_approval') !== false,
    'page escapes dynamic output and remains restricted to stage adapter' => strpos($page, 'htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\')') !== false
        && strpos($service, 'coreaxis_payroll_db_assert_stage($conn)') !== false
        && strpos($page, "includes/db.php") === false
        && strpos($page, 'coreaxis_payroll_stage_connect_from_environment') !== false,
    'calculation preview is Admin-only, GET read-only, and uses the database adapter' => strpos($preview, 'auth_require_admin($conn);') !== false
        && strpos($preview, "header('Allow: GET', true, 405)") !== false
        && strpos($preview, 'coreaxis_payroll_load_month($conn') !== false
        && strpos($preview, "includes/db.php") === false
        && !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w|DELETE\s+FROM)\b/i', $preview),
    'salary generation and payment feature gate remains disabled' => strpos(file_get_contents($root . '/salary.php'), '$payroll_actions_enabled = false;') !== false,
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $name . "\n";
    if (!$passed) { $failed++; }
}
if ($failed > 0) { exit(1); }
echo 'PASS: payroll policy administration contracts; no database connection attempted.' . "\n";
