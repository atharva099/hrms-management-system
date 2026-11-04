<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll_policy_admin.php';

header('Cache-Control: no-store, private');
try {
    $conn = coreaxis_payroll_stage_connect_from_environment();
} catch (Throwable $error) {
    http_response_code(503);
    exit('Payroll policy tools are temporarily unavailable.');
}
auth_require_admin($conn);
$employeeId = auth_current_employee_id();
$message = isset($_SESSION['payroll_policy_notice']) && is_string($_SESSION['payroll_policy_notice'])
    ? $_SESSION['payroll_policy_notice'] : '';
unset($_SESSION['payroll_policy_notice']);
$errorMessage = '';
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$defaultCurrency = COREAXIS_PAYROLL_POLICY_CURRENCY_DEFAULT;

try {
    coreaxis_payroll_db_assert_stage($conn);
    coreaxis_payroll_db_require_schema($conn);
    $actorRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
        $conn, "SELECT id FROM users WHERE employee_id = ? AND role='Admin' AND status='Active'", 's', [$employeeId]
    ));
    if (count($actorRows) !== 1) { coreaxis_payroll_db_fail('active_admin_identity_required'); }
    $actorId = (int) $actorRows[0]['id'];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            exit('Request could not be verified.');
        }
        $action = $_POST['action'] ?? null;
        if ($action === 'create_draft') {
            $flag = static function ($value): ?bool {
                if ($value === '1') { return true; }
                if ($value === '0') { return false; }
                return null;
            };
            $leaveTypes = $_POST['leave_type'] ?? [];
            $classifications = [];
            if (is_array($leaveTypes)) {
                foreach ($leaveTypes as $type => $classification) { $classifications[(string) $type] = $classification; }
            }
            $input = [
                'policy_code' => $_POST['policy_code'] ?? null,
                'version_no' => $_POST['version_no'] ?? null,
                'effective_from' => $_POST['effective_from'] ?? null,
                'effective_through' => $_POST['effective_through'] ?? null,
                'currency' => $_POST['currency'] ?? null,
                'leave_counts_on_holidays' => $flag($_POST['leave_counts_on_holidays'] ?? null),
                'leave_counts_on_weekly_offs' => $flag($_POST['leave_counts_on_weekly_offs'] ?? null),
                'adjustment_amounts' => is_array($_POST['adjustment_amounts'] ?? null) ? $_POST['adjustment_amounts'] : [],
                'leave_classifications' => $classifications,
            ];
            $newId = coreaxis_payroll_policy_create_draft($conn, $actorId, $input);
            $_SESSION['payroll_policy_notice'] = 'Draft policy version created. A different active Admin must approve it.';
            header('Location: payroll_policy.php'); exit;
        } elseif ($action === 'approve_draft') {
            $policyId = filter_var($_POST['policy_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($policyId === false || !is_string($_POST['approval_reason'] ?? null)) {
                coreaxis_payroll_db_fail('payroll_policy_approval_input_invalid');
            }
            coreaxis_payroll_policy_approve($conn, $actorId, $policyId, $_POST['approval_reason']);
            $_SESSION['payroll_policy_notice'] = 'Policy version approved and made immutable.';
            header('Location: payroll_policy.php'); exit;
        } elseif ($action === 'create_schedule_draft') {
            $newScheduleId = coreaxis_payroll_schedule_create_draft($conn, $actorId, [
                'employee_id' => $_POST['employee_id'] ?? null,
                'version_no' => $_POST['schedule_version_no'] ?? null,
                'shift_id' => $_POST['shift_id'] ?? null,
                'effective_from' => $_POST['schedule_effective_from'] ?? null,
                'effective_through' => $_POST['schedule_effective_through'] ?? null,
                'weekdays' => is_array($_POST['weekdays'] ?? null) ? $_POST['weekdays'] : [],
            ]);
            $_SESSION['payroll_policy_notice'] = 'Draft work schedule created. A different active Admin must approve it.';
            header('Location: payroll_policy.php'); exit;
        } elseif ($action === 'approve_schedule') {
            $scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($scheduleId === false || !is_string($_POST['schedule_approval_reason'] ?? null)) {
                coreaxis_payroll_db_fail('payroll_schedule_approval_input_invalid');
            }
            coreaxis_payroll_schedule_approve($conn, $actorId, $scheduleId, $_POST['schedule_approval_reason']);
            $_SESSION['payroll_policy_notice'] = 'Work schedule approved and made immutable.';
            header('Location: payroll_policy.php'); exit;
        } elseif ($action === 'create_compensation_draft') {
            coreaxis_payroll_compensation_create_draft($conn, $actorId, [
                'employee_id' => $_POST['comp_employee_id'] ?? null,
                'version_no' => $_POST['comp_version_no'] ?? null,
                'effective_from' => $_POST['comp_effective_from'] ?? null,
                'effective_through' => $_POST['comp_effective_through'] ?? null,
                'basic_salary' => $_POST['comp_basic_salary'] ?? null,
                'incentive' => $_POST['comp_incentive'] ?? null,
                'currency' => $_POST['comp_currency'] ?? null,
            ]);
            $_SESSION['payroll_policy_notice'] = 'Draft compensation created. A different active Admin must approve it.';
            header('Location: payroll_policy.php'); exit;
        } elseif ($action === 'approve_compensation') {
            $compensationId = filter_var($_POST['compensation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($compensationId === false || !is_string($_POST['comp_approval_reason'] ?? null)) {
                coreaxis_payroll_db_fail('payroll_compensation_approval_input_invalid');
            }
            coreaxis_payroll_compensation_approve($conn, $actorId, $compensationId, $_POST['comp_approval_reason']);
            $_SESSION['payroll_policy_notice'] = 'Compensation version approved and made immutable.';
            header('Location: payroll_policy.php'); exit;
        } else {
            http_response_code(400);
            $errorMessage = 'Unsupported policy action.';
        }
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET, POST', true, 405);
        exit('Method Not Allowed');
    }

    $leaveTypes = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
        $conn,
        "SELECT DISTINCT leave_type FROM leaves WHERE status IN ('Pending','Approved')
         AND leave_type IS NOT NULL AND TRIM(leave_type) <> '' ORDER BY leave_type"
    ));
    $drafts = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
        $conn,
        "SELECT id,policy_code,version_no,effective_from,effective_through,currency,created_by_user_id
         FROM payroll_policy_versions WHERE policy_status='Draft' ORDER BY effective_from,id"
    ));
    $scheduleDrafts = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
        $conn,
        "SELECT id,employee_user_id,version_no,effective_from,effective_through,shift_id,created_by_user_id
         FROM payroll_work_schedule_versions WHERE schedule_status='Draft' ORDER BY effective_from,id"
    ));
    $activeEmployees = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
        $conn, "SELECT employee_id FROM users WHERE role='Employee' AND status='Active' ORDER BY employee_id"
    ));
    $activeShifts = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
        $conn, "SELECT id,shift_name,in_time,out_time FROM shifts WHERE status='Active' ORDER BY shift_name"
    ));
    $compensationDrafts = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
        $conn,
        "SELECT c.id,c.employee_user_id,u.employee_id,c.version_no,c.effective_from,c.effective_through,c.currency,c.created_by_user_id
         FROM payroll_compensation_versions c JOIN users u ON u.id=c.employee_user_id
         WHERE c.compensation_status='Draft' ORDER BY c.effective_from,c.id"
    ));
} catch (CoreAxisPayrollCalculationException $error) {
    $errorMessage = 'Payroll policy tools are unavailable: ' . $error->getReasonCode();
    if ($error->getReasonCode() === 'database_target_not_authorized') { http_response_code(503); }
} catch (Throwable $error) {
    $errorMessage = 'Payroll policy tools are temporarily unavailable.';
    http_response_code(503);
}

$ruleLabels = [
    'late' => 'Late attendance adjustment (negative deduction)',
    'half_day' => 'Half-day adjustment (negative deduction)',
    'absent' => 'Absence adjustment (negative deduction)',
    'paid_leave' => 'Paid-leave adjustment (non-negative)',
    'unpaid_leave' => 'Unpaid-leave adjustment (negative deduction)',
    'holiday_work' => 'Holiday work adjustment (non-negative)',
    'weekly_off_work' => 'Weekly-off work adjustment (non-negative)',
    'holiday_nonworkday' => 'Holiday nonworkday adjustment',
    'weekly_off_nonworkday' => 'Weekly-off nonworkday adjustment',
];
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Payroll Policy Configuration</title><link rel="stylesheet" href="css/style.css"></head>
<body>
<main class="main-container">
    <h1>Payroll policy configuration</h1>
    <p><a href="payroll_preview.php">Open read-only calculation preview</a></p>
    <p>Draft and approval are limited to the authorized payroll stage. Salary generation and payment release remain disabled.</p>
    <?php if ($message !== ''): ?><p role="status"><?php echo $escape($message); ?></p><?php endif; ?>
    <?php if ($errorMessage !== ''): ?><p role="alert"><?php echo $escape($errorMessage); ?></p><?php endif; ?>
    <?php if (empty($errorMessage) || isset($leaveTypes)): ?>
    <section>
        <h2>Create draft policy</h2>
        <p>All amounts and calendar treatment must be approved by Payroll/HR. INR is only the initial selectable currency; no employee currency or rate is inferred.</p>
        <form method="post">
            <?php echo csrf_field(); ?><input type="hidden" name="action" value="create_draft">
            <label>Policy code <input name="policy_code" maxlength="64" pattern="[A-Z0-9][A-Z0-9_-]{0,63}" required></label>
            <label>Version <input type="number" name="version_no" min="1" required></label>
            <label>Effective from <input type="date" name="effective_from" required></label>
            <label>Effective through <input type="date" name="effective_through" required></label>
            <label>Currency <select name="currency" required><option value="<?php echo $escape($defaultCurrency); ?>" selected><?php echo $escape($defaultCurrency); ?></option><option value="USD">USD</option><option value="EUR">EUR</option><option value="GBP">GBP</option></select></label>
            <label>Approved leave counts on holidays? <select name="leave_counts_on_holidays" required><option value="" selected disabled>Select</option><option value="1">Yes</option><option value="0">No</option></select></label>
            <label>Approved leave counts on weekly offs? <select name="leave_counts_on_weekly_offs" required><option value="" selected disabled>Select</option><option value="1">Yes</option><option value="0">No</option></select></label>
            <h3>Explicit adjustments per payroll event</h3>
            <?php foreach ($ruleLabels as $code => $label): ?>
                <label><?php echo $escape($label); ?> <input type="number" name="adjustment_amounts[<?php echo $escape($code); ?>]" step="0.01" min="-99999999.99" max="99999999.99" required></label>
            <?php endforeach; ?>
            <h3>Leave classifications</h3>
            <?php foreach (($leaveTypes ?? []) as $leave): ?>
                <label><?php echo $escape($leave['leave_type']); ?>
                    <select name="leave_type[<?php echo $escape($leave['leave_type']); ?>]" required>
                        <option value="" selected disabled>Select Paid or Unpaid</option><option value="Paid">Paid</option><option value="Unpaid">Unpaid</option>
                    </select>
                </label>
            <?php endforeach; ?>
            <button type="submit">Save draft policy</button>
        </form>
    </section>
    <section>
        <h2>Create draft compensation version</h2>
        <p>These explicitly approved, effective-dated values are the payroll source. Existing users.salary and users.incentive are not historical payroll inputs. INR is preselected only as a configurable currency choice.</p>
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="create_compensation_draft">
            <label>Active employee <select name="comp_employee_id" required><option value="" selected disabled>Select employee</option><?php foreach (($activeEmployees ?? []) as $employee): ?><option value="<?php echo $escape($employee['employee_id']); ?>"><?php echo $escape($employee['employee_id']); ?></option><?php endforeach; ?></select></label>
            <label>Compensation version <input type="number" name="comp_version_no" min="1" required></label>
            <label>Effective from <input type="date" name="comp_effective_from" required></label><label>Effective through <input type="date" name="comp_effective_through" required></label>
            <label>Basic salary <input type="number" name="comp_basic_salary" min="0" max="99999999.99" step="0.01" required></label>
            <label>Incentive <input type="number" name="comp_incentive" min="0" max="99999999.99" step="0.01" required></label>
            <label>Currency <select name="comp_currency" required><option value="<?php echo $escape($defaultCurrency); ?>" selected><?php echo $escape($defaultCurrency); ?></option><option value="USD">USD</option><option value="EUR">EUR</option><option value="GBP">GBP</option></select></label>
            <button type="submit">Save draft compensation</button>
        </form>
    </section>
    <section>
        <h2>Approve draft compensation</h2>
        <?php foreach (($compensationDrafts ?? []) as $draft): ?>
            <?php if ((int) $draft['created_by_user_id'] !== ($actorId ?? 0)): ?>
                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_compensation"><input type="hidden" name="compensation_id" value="<?php echo (int) $draft['id']; ?>"><p><?php echo $escape($draft['employee_id']); ?> v<?php echo (int) $draft['version_no']; ?>, <?php echo $escape($draft['effective_from']); ?> through <?php echo $escape($draft['effective_through']); ?>, <?php echo $escape($draft['currency']); ?></p><label>Approval reason <input name="comp_approval_reason" maxlength="500" required></label><button type="submit">Approve compensation</button></form>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
    <section>
        <h2>Create draft employee work schedule</h2>
        <p>Weekdays are explicit (Monday to Sunday). This is the payroll roster source; the current users.shift_id alone is not treated as historical schedule evidence.</p>
        <form method="post">
            <?php echo csrf_field(); ?><input type="hidden" name="action" value="create_schedule_draft">
            <label>Active employee <select name="employee_id" required><option value="" selected disabled>Select employee</option><?php foreach (($activeEmployees ?? []) as $employee): ?><option value="<?php echo $escape($employee['employee_id']); ?>"><?php echo $escape($employee['employee_id']); ?></option><?php endforeach; ?></select></label>
            <label>Schedule version <input type="number" name="schedule_version_no" min="1" required></label>
            <label>Active shift <select name="shift_id" required><option value="" selected disabled>Select shift</option><?php foreach (($activeShifts ?? []) as $shift): ?><option value="<?php echo (int) $shift['id']; ?>"><?php echo $escape($shift['shift_name'] . ' ' . $shift['in_time'] . '–' . $shift['out_time']); ?></option><?php endforeach; ?></select></label>
            <label>Effective from <input type="date" name="schedule_effective_from" required></label><label>Effective through <input type="date" name="schedule_effective_through" required></label>
            <fieldset><legend>Scheduled weekdays</legend><?php foreach ([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $number=>$dayLabel): ?><label><input type="checkbox" name="weekdays[]" value="<?php echo $number; ?>"><?php echo $escape($dayLabel); ?></label><?php endforeach; ?></fieldset>
            <button type="submit">Save draft schedule</button>
        </form>
    </section>
    <section>
        <h2>Approve draft work schedule</h2>
        <?php foreach (($scheduleDrafts ?? []) as $draft): ?>
            <?php if ((int) $draft['created_by_user_id'] !== ($actorId ?? 0)): ?>
                <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_schedule"><input type="hidden" name="schedule_id" value="<?php echo (int) $draft['id']; ?>"><p>Employee record <?php echo (int) $draft['employee_user_id']; ?>, version <?php echo (int) $draft['version_no']; ?>, shift ID <?php echo (int) $draft['shift_id']; ?>, <?php echo $escape($draft['effective_from']); ?> through <?php echo $escape($draft['effective_through']); ?></p><label>Approval reason <input name="schedule_approval_reason" maxlength="500" required></label><button type="submit">Approve schedule</button></form>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
    <section>
        <h2>Approve draft</h2>
        <?php foreach (($drafts ?? []) as $draft): ?>
            <?php if ((int) $draft['created_by_user_id'] !== ($actorId ?? 0)): ?>
                <form method="post">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="approve_draft"><input type="hidden" name="policy_id" value="<?php echo (int) $draft['id']; ?>">
                    <p><?php echo $escape($draft['policy_code']); ?> v<?php echo (int) $draft['version_no']; ?>, <?php echo $escape($draft['effective_from']); ?> through <?php echo $escape($draft['effective_through']); ?>, <?php echo $escape($draft['currency']); ?></p>
                    <label>Approval reason <input name="approval_reason" maxlength="500" required></label><button type="submit">Approve policy</button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</main>
</body></html>
