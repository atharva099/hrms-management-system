<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll_database.php';

header('Cache-Control: no-store, private');
try {
    $conn = coreaxis_payroll_stage_connect_from_environment();
} catch (Throwable $error) {
    http_response_code(503);
    exit('Payroll preview is temporarily unavailable.');
}
auth_require_admin($conn);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET', true, 405);
    exit('Method Not Allowed');
}
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$preview = null;
$errorMessage = '';
$employeeId = isset($_GET['employee_id']) && is_string($_GET['employee_id']) ? trim($_GET['employee_id']) : '';
$periodInput = isset($_GET['period']) && is_string($_GET['period']) ? trim($_GET['period']) : '';
$period = preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $periodInput) ? $periodInput . '-01' : '';
if ($employeeId !== '' || $period !== '') {
    try {
        if (!preg_match('/^EMP[0-9]{3,17}$/iD', $employeeId)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-01$/D', $period)
            || !coreaxis_payroll_is_iso_date($period)) {
            coreaxis_payroll_db_fail('invalid_payroll_scope');
        }
        $preview = coreaxis_payroll_load_month($conn, $employeeId, $period);
    } catch (CoreAxisPayrollCalculationException $error) {
        $errorMessage = 'Preview is blocked: ' . $error->getReasonCode();
        if ($error->getReasonCode() === 'database_target_not_authorized') { http_response_code(503); }
    } catch (Throwable $error) {
        $errorMessage = 'Preview is temporarily unavailable.';
        http_response_code(503);
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Payroll Calculation Preview</title><link rel="stylesheet" href="css/style.css"></head>
<body><main class="main-container"><h1>Payroll calculation preview</h1>
<p>Read-only preview on the authorized payroll stage. This does not create a salary row, approve a calculation, or release payment.</p>
<form method="get"><label>Employee ID <input name="employee_id" pattern="EMP[0-9]{3,17}" maxlength="20" required value="<?php echo $escape($employeeId); ?>"></label><label>Payroll month <input name="period" type="month" required value="<?php echo $escape($periodInput); ?>"></label><button type="submit">Preview</button></form>
<?php if ($errorMessage !== ''): ?><p role="alert"><?php echo $escape($errorMessage); ?></p><?php endif; ?>
<?php if (is_array($preview)): ?>
<section><h2><?php echo $escape($preview['salary_period']); ?> — Draft preview</h2>
<dl><dt>Basic salary</dt><dd><?php echo $escape($preview['currency'] . ' ' . $preview['basic_salary']); ?></dd><dt>Incentive</dt><dd><?php echo $escape($preview['currency'] . ' ' . $preview['incentive']); ?></dd><dt>Total adjustments</dt><dd><?php echo $escape($preview['currency'] . ' ' . $preview['total_adjustment']); ?></dd><dt>Calculated amount</dt><dd><?php echo $escape($preview['currency'] . ' ' . $preview['final_salary']); ?></dd><dt>Policy version</dt><dd><?php echo (int) $preview['policy_version']; ?></dd><dt>Compensation version</dt><dd><?php echo (int) $preview['compensation_version']; ?></dd><dt>Roster versions</dt><dd><?php echo (int) count($preview['schedule_versions']); ?></dd><dt>Input fingerprint</dt><dd><code><?php echo $escape($preview['source_sha256']); ?></code></dd></dl>
<h3>Adjustment breakdown</h3><table><thead><tr><th>Rule</th><th>Events</th><th>Amount per event (minor units)</th><th>Total adjustment (minor units)</th></tr></thead><tbody>
<?php foreach ($preview['adjustments'] as $rule => $detail): ?><tr><th><?php echo $escape($rule); ?></th><td><?php echo (int) $detail['count']; ?></td><td><?php echo (int) $detail['adjustment_cents_each']; ?></td><td><?php echo (int) $detail['total_adjustment_cents']; ?></td></tr><?php endforeach; ?>
</tbody></table><p>Calculation is a preview only. Payroll generation and payment release remain disabled.</p></section>
<?php endif; ?></main></body></html>
