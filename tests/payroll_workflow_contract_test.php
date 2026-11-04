<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/includes/payroll_workflow.php');
$salary = file_get_contents($root . '/salary.php');
$report = file_get_contents($root . '/salary_report.php');
$slip = file_get_contents($root . '/salary_slip.php');
if (!is_string($workflow) || !is_string($salary) || !is_string($report) || !is_string($slip)) {
    fwrite(STDERR, "FAIL: payroll workflow sources unavailable\n");
    exit(1);
}

$checks = [
    'draft persistence is transaction protected and stage schema gated' => strpos($workflow, 'coreaxis_payroll_db_assert_stage($conn)') !== false
        && strpos($workflow, 'mysqli_begin_transaction($conn)') !== false
        && strpos($workflow, 'mysqli_rollback($conn)') !== false
        && strpos($workflow, 'mysqli_commit($conn)') !== false
        && strpos($workflow, 'coreaxis_payroll_db_require_schema($conn)') !== false,
    'draft persistence uses database period uniqueness and prepared inserts' => strpos($workflow, 'duplicate_employee_period') !== false
        && strpos($workflow, 'mysqli_prepare(') !== false
        && strpos($workflow, 'uq_salaries_employee_period') === false,
    'draft calculation persists an input fingerprint and exact integer-cent snapshot' => strpos($workflow, 'source_sha256') !== false
        && strpos($workflow, 'input_snapshot') !== false
        && strpos($workflow, 'basicCents + $incentiveCents + $adjustmentCents !== $finalCents') !== false
        && strpos($workflow, 'coreaxis_payroll_signed_amount_to_cents') !== false
        && strpos($workflow, '(float)') === false
        && strpos($workflow, 'round(') === false,
    'snapshot INSERT uses the exact bind signature for all twelve parameters' => strpos($workflow, "'isssissiiiii'") !== false
        && strpos($workflow, 'isssis sssiiii') === false,
    'legacy salary row refuses adjustment categories it cannot represent' => strpos($workflow, 'legacy_salary_breakdown_incompatible') !== false
        && strpos($workflow, 'Pending') !== false,
    'active Admin identity is checked server-side for workflow actions' => strpos($workflow, "role'] !== 'Admin'") !== false
        && strpos($workflow, "status'] !== 'Active'") !== false
        && strpos($workflow, 'payroll_approval_not_independent_or_not_draft') !== false,
    'approval re-reads locked source and policy fingerprint before approval' => strpos($workflow, 'coreaxis_payroll_load_month($conn, $stored[\'employee_id\'], $stored[\'salary_period\'], true)') !== false
        && strpos($workflow, 'SELECT id,employee_id,salary_period,final_salary,payment_status FROM salaries WHERE id = ? FOR UPDATE') !== false
        && strpos($workflow, "payment_status'] !== 'Pending'") !== false
        && strpos($workflow, 'hash_equals($stored[\'source_sha256\'], $calculation[\'source_sha256\'])') !== false
        && strpos($workflow, "'Blocked'") !== false
        && strpos($workflow, "'Approved'") !== false,
    'approval and calculation history are appended through candidate event table' => strpos($workflow, 'payroll_calculation_events') !== false
        && strpos($workflow, 'draft_created') !== false
        && strpos($workflow, 'draft_approved') !== false,
    'public payroll mutations remain disabled until migration and runtime verification' => strpos($salary, '$payroll_actions_enabled = false;') !== false
        && strpos($salary, 'http_response_code(503)') !== false,
    'legacy reports are not falsely treated as integrated with stage snapshots' => strpos($report, 'payroll_calculation_snapshots') === false
        && strpos($slip, 'payroll_calculation_snapshots') === false,
    'workflow has no application DB configuration fallback or request entry point' => !preg_match('/^\s*(?:require|include)(_once)?\s*[\'"]includes\\/db\.php/m', $workflow)
        && strpos($workflow, '$_POST') === false
        && strpos($workflow, '$_GET') === false,
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $name . "\n";
    if (!$passed) { $failed++; }
}
if ($failed > 0) { exit(1); }
echo "PASS: payroll workflow contracts; no database connection attempted.\n";
