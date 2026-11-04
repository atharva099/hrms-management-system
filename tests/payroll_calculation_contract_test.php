<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/payroll_calculation.php';

function payrollCalculationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

function payrollExpectBlocked(callable $callback, string $reason, string $message): void
{
    try {
        $callback();
    } catch (CoreAxisPayrollCalculationException $error) {
        payrollCalculationAssert($error->getReasonCode() === $reason, $message);
        return;
    }
    payrollCalculationAssert(false, $message);
}

function payrollTestDays(string $period): array
{
    $start = new DateTimeImmutable($period);
    $end = $start->modify('+1 month');
    $days = [];
    for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
        $days[] = [
            'date' => $day->format('Y-m-d'),
            'scheduled_workday' => false,
            'holiday' => false,
            'weekly_off' => false,
            'shift_status' => 'None',
            'attendance_status' => null,
            'leave_status' => null,
            'leave_type' => null,
            'active_leave_count' => 0,
            'attendance_conflict' => false,
        ];
    }
    return $days;
}

function payrollDay(array &$days, int $day): array
{
    foreach ($days as $index => $entry) {
        if ((int) substr($entry['date'], 8, 2) === $day) {
            return $days[$index];
        }
    }
    throw new RuntimeException('Test day not found.');
}

function payrollSetDay(array &$days, int $day, array $updates): void
{
    foreach ($days as $index => $entry) {
        if ((int) substr($entry['date'], 8, 2) === $day) {
            $days[$index] = array_merge($entry, $updates);
            return;
        }
    }
    throw new RuntimeException('Test day not found.');
}

$employee = [
    'employee_id' => 'EMP9801', 'role' => 'Employee', 'status' => 'Active',
    'salary' => '1000.10', 'incentive' => '100.05', 'currency' => 'INR',
];
$policy = [
    'status' => 'Approved', 'version' => 3,
    'effective_policy_count' => 1,
    'currency' => 'INR',
    'effective_from' => '2026-03-01', 'effective_through' => '2026-03-31',
    'leave_counts_on_holidays' => false, 'leave_counts_on_weekly_offs' => false,
    'leave_classifications' => ['Paid Leave' => 'Paid', 'Unpaid Leave' => 'Unpaid'],
    'adjustment_cents' => [
        'late' => -100, 'half_day' => -500, 'absent' => -1000,
        'paid_leave' => 0, 'unpaid_leave' => -250,
        'holiday_work' => 300, 'weekly_off_work' => 400,
        'holiday_nonworkday' => 100, 'weekly_off_nonworkday' => 200,
    ],
];
$days = payrollTestDays('2026-03-01');
payrollSetDay($days, 2, ['scheduled_workday' => true, 'shift_status' => 'Active', 'attendance_status' => 'Late Half Day']);
payrollSetDay($days, 3, ['scheduled_workday' => true, 'shift_status' => 'Active', 'attendance_status' => 'Absent']);
payrollSetDay($days, 4, [
    'scheduled_workday' => true, 'shift_status' => 'Active', 'attendance_status' => 'Leave',
    'leave_status' => 'Approved', 'leave_type' => 'Paid Leave', 'active_leave_count' => 1,
]);
payrollSetDay($days, 5, [
    'scheduled_workday' => true, 'shift_status' => 'Active', 'attendance_status' => 'Leave',
    'leave_status' => 'Approved', 'leave_type' => 'Unpaid Leave', 'active_leave_count' => 1,
]);
payrollSetDay($days, 8, ['holiday' => true, 'shift_status' => 'Active', 'attendance_status' => 'Present']);
payrollSetDay($days, 9, ['weekly_off' => true, 'shift_status' => 'Active', 'attendance_status' => 'Sunday Working']);
payrollSetDay($days, 10, ['scheduled_workday' => true, 'holiday' => true, 'shift_status' => 'Active']);
payrollSetDay($days, 11, ['weekly_off' => true]);

$result = coreaxis_payroll_calculate_month($employee, '2026-03-01', $days, $policy);
payrollCalculationAssert($result['final_salary'] === '1091.65', 'exact cents include configured late, half-day, absence, leave and calendar rules');
payrollCalculationAssert($result['adjustments']['late']['count'] === 1 && $result['adjustments']['half_day']['count'] === 1, 'Late Half Day records both explicitly configured events');
payrollCalculationAssert($result['adjustments']['paid_leave']['count'] === 1 && $result['adjustments']['unpaid_leave']['count'] === 1, 'approved paid and unpaid leave are classified separately');
payrollCalculationAssert($result['adjustments']['holiday_work']['count'] === 1 && $result['adjustments']['weekly_off_work']['count'] === 1, 'worked holiday and weekly-off days use separate rules');
payrollCalculationAssert($result['adjustments']['holiday_nonworkday']['count'] === 1 && $result['adjustments']['weekly_off_nonworkday']['count'] === 1, 'nonworking holiday and weekly-off treatment is explicit and separately configurable');
payrollCalculationAssert($result['adjustments']['absent']['count'] === 1, 'only explicitly recorded absence on a scheduled nonholiday day counts');
payrollCalculationAssert($result['source_sha256'] === hash('sha256', $result['input_fingerprint']), 'calculation returns a reproducible source fingerprint');

$shuffled = $days;
shuffle($shuffled);
$policyReordered = $policy;
$policyReordered['adjustment_cents'] = array_reverse($policyReordered['adjustment_cents'], true);
$same = coreaxis_payroll_calculate_month($employee, '2026-03-01', $shuffled, $policyReordered);
payrollCalculationAssert($same['source_sha256'] === $result['source_sha256'], 'input order does not alter deterministic calculation fingerprint');
$leaveOnHoliday = $days;
payrollSetDay($leaveOnHoliday, 10, [
    'scheduled_workday' => true, 'holiday' => true, 'shift_status' => 'Active',
    'attendance_status' => 'Leave', 'leave_status' => 'Approved', 'leave_type' => 'Paid Leave', 'active_leave_count' => 1,
]);
$holidayLeaveResult = coreaxis_payroll_calculate_month($employee, '2026-03-01', $leaveOnHoliday, $policy);
payrollCalculationAssert(
    $holidayLeaveResult['adjustments']['paid_leave']['count'] === 1
        && $holidayLeaveResult['adjustments']['holiday_nonworkday']['count'] === 1,
    'leave on holidays follows the explicit configured leave-counting flag'
);
$holidayLeavePolicy = $policy;
$holidayLeavePolicy['leave_counts_on_holidays'] = true;
$countedHolidayLeave = coreaxis_payroll_calculate_month($employee, '2026-03-01', $leaveOnHoliday, $holidayLeavePolicy);
payrollCalculationAssert(
    $countedHolidayLeave['adjustments']['paid_leave']['count'] === 2
        && $countedHolidayLeave['adjustments']['holiday_nonworkday']['count'] === 0,
    'approved holiday leave uses the configured classification when chargeable'
);

payrollExpectBlocked(
    static function () use ($employee, $days) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $days, []); },
    'approved_policy_missing_or_not_effective_for_full_period',
    'missing or unapproved mandatory policy blocks calculation'
);
$badPolicy = $policy;
unset($badPolicy['adjustment_cents']['absent']);
payrollExpectBlocked(static function () use ($employee, $days, $badPolicy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $days, $badPolicy); }, 'payroll_rule_missing_or_invalid_absent', 'missing Absent rule blocks calculation');
$ambiguousPolicy = $policy;
$ambiguousPolicy['effective_policy_count'] = 2;
payrollExpectBlocked(static function () use ($employee, $days, $ambiguousPolicy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $days, $ambiguousPolicy); }, 'approved_policy_missing_or_not_effective_for_full_period', 'overlapping approved policy versions block calculation');
$badPolicy = $policy;
$badPolicy['adjustment_cents']['late'] = 100;
payrollExpectBlocked(static function () use ($employee, $days, $badPolicy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $days, $badPolicy); }, 'deduction_rule_has_earning_sign_late', 'positive late deduction is rejected');
$badPolicy = $policy;
$badPolicy['adjustment_cents']['paid_leave'] = -1;
payrollExpectBlocked(static function () use ($employee, $days, $badPolicy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $days, $badPolicy); }, 'paid_leave_rule_cannot_deduct', 'paid-leave classification cannot silently deduct pay');
payrollExpectBlocked(static function () use ($employee, $days, $policy) { coreaxis_payroll_calculate_month(array_merge($employee, ['status' => 'Inactive']), '2026-03-01', $days, $policy); }, 'inactive_or_unauthorized_employee', 'inactive employee payroll is blocked');
payrollExpectBlocked(static function () use ($employee, $days, $policy) {
    $invalid = $days;
    payrollSetDay($invalid, 10, ['scheduled_workday' => true, 'holiday' => true, 'shift_status' => 'Active', 'attendance_status' => 'Absent']);
    coreaxis_payroll_calculate_month($employee, '2026-03-01', $invalid, $policy);
}, 'absence_record_conflicts_with_nonworkday', 'an Absent record on a holiday blocks payroll instead of creating a deduction');
payrollExpectBlocked(static function () use ($employee, $days, $policy) { coreaxis_payroll_calculate_month($employee, '2026-03-02', $days, $policy); }, 'invalid_period', 'non-first-of-month payroll period is rejected');
$missingShift = $days;
payrollSetDay($missingShift, 2, ['scheduled_workday' => true, 'shift_status' => 'None', 'attendance_status' => 'Present']);
payrollExpectBlocked(static function () use ($employee, $missingShift, $policy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $missingShift, $policy); }, 'missing_or_inactive_shift', 'missing assigned shift blocks calculation');
$negativeNetPolicy = $policy;
$negativeNetPolicy['adjustment_cents']['absent'] = -9999999999;
payrollExpectBlocked(static function () use ($employee, $days, $negativeNetPolicy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $days, $negativeNetPolicy); }, 'calculation_would_produce_negative_net_pay', 'negative calculated net pay fails closed');
$conflict = $days;
payrollSetDay($conflict, 2, ['scheduled_workday' => true, 'shift_status' => 'Active', 'attendance_status' => 'Present', 'attendance_conflict' => true]);
payrollExpectBlocked(static function () use ($employee, $conflict, $policy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $conflict, $policy); }, 'attendance_conflict', 'conflicting attendance blocks calculation');
$pendingLeave = $days;
payrollSetDay($pendingLeave, 4, ['scheduled_workday' => true, 'shift_status' => 'Active', 'leave_status' => 'Pending', 'leave_type' => 'Paid Leave', 'active_leave_count' => 1]);
payrollExpectBlocked(static function () use ($employee, $pendingLeave, $policy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $pendingLeave, $policy); }, 'leave_approval_incomplete', 'pending leave blocks calculation');
$unclassified = $days;
payrollSetDay($unclassified, 4, ['scheduled_workday' => true, 'shift_status' => 'Active', 'attendance_status' => 'Leave', 'leave_status' => 'Approved', 'leave_type' => 'Sick Leave', 'active_leave_count' => 1]);
payrollExpectBlocked(static function () use ($employee, $unclassified, $policy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $unclassified, $policy); }, 'leave_type_payment_classification_missing', 'unclassified approved leave blocks calculation');
$overlapLeave = $days;
payrollSetDay($overlapLeave, 4, ['scheduled_workday' => true, 'shift_status' => 'Active', 'leave_status' => 'Approved', 'leave_type' => 'Paid Leave', 'active_leave_count' => 2]);
payrollExpectBlocked(static function () use ($employee, $overlapLeave, $policy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $overlapLeave, $policy); }, 'overlapping_leave_requests', 'overlapping active leave requests block payroll');
$missingIncentive = $employee;
unset($missingIncentive['incentive']);
payrollExpectBlocked(static function () use ($missingIncentive, $days, $policy) { coreaxis_payroll_calculate_month($missingIncentive, '2026-03-01', $days, $policy); }, 'invalid_authoritative_pay_amount', 'missing authoritative incentive blocks calculation instead of defaulting to zero');
$incomplete = array_slice($days, 1);
payrollExpectBlocked(static function () use ($employee, $incomplete, $policy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $incomplete, $policy); }, 'incomplete_payroll_day_inputs', 'missing payroll calendar day blocks calculation');
$duplicateDays = $days;
$duplicateDays[] = $days[0];
payrollExpectBlocked(static function () use ($employee, $duplicateDays, $policy) { coreaxis_payroll_calculate_month($employee, '2026-03-01', $duplicateDays, $policy); }, 'incomplete_payroll_day_inputs', 'duplicate day input cannot conceal missing calendar coverage');

coreaxis_payroll_assert_period_not_generated([], 'EMP9801', '2026-03-01');
payrollExpectBlocked(static function () { coreaxis_payroll_assert_period_not_generated([['employee_id' => 'EMP9801', 'salary_period' => '2026-03-01']], 'EMP9801', '2026-03-01'); }, 'duplicate_employee_period', 'existing employee-period calculation is rejected');
$generatedIdempotencyKey = coreaxis_payroll_new_idempotency_key();
payrollCalculationAssert(preg_match('/^[a-f0-9]{64}$/D', $generatedIdempotencyKey) === 1, 'idempotency tokens use 256 bits of cryptographic randomness');

$approvedCalculation = ['status' => 'Approved', 'approved_amount_cents' => 10000, 'currency' => 'INR'];
$paymentBase = [
    'actor_role' => 'Admin', 'actor_status' => 'Active', 'payment_transaction_exists' => false,
    'actor_user_id' => 1, 'actor_employee_id' => 'EMP0001',
    'currency' => 'INR',
    'payment_key' => str_repeat('a', 64), 'idempotency_key' => str_repeat('b', 64),
    'event_type' => 'payment_initiated', 'amount_cents' => 5000, 'transaction_amount_cents' => 5000,
    'external_reference' => null, 'reason' => 'Authorized payroll disbursement',
];
$initiation = coreaxis_payroll_validate_payment_event($approvedCalculation, [], $paymentBase);
payrollCalculationAssert($initiation['event_type'] === 'payment_initiated' && $initiation['external_reference'] === null, 'payment initiation records intent without fabricating transfer evidence');
$history = [[
    'id' => 10, 'payment_key' => $paymentBase['payment_key'], 'idempotency_key' => $paymentBase['idempotency_key'],
    'event_type' => 'payment_initiated', 'amount_cents' => 5000,
]];
$submit = array_merge($paymentBase, ['payment_transaction_exists' => true, 'payment_transaction_id' => 21, 'event_type' => 'payment_submitted', 'idempotency_key' => str_repeat('c', 64)]);
$submitted = coreaxis_payroll_validate_payment_event($approvedCalculation, $history, $submit);
payrollCalculationAssert($submitted['previous_event_id'] === 10 && $submitted['payment_transaction_id'] === 21, 'payment lifecycle appends a transition linked to prior evidence');
$settle = array_merge($paymentBase, [
    'payment_transaction_exists' => true, 'payment_transaction_id' => 21, 'event_type' => 'payment_settled',
    'idempotency_key' => str_repeat('d', 64), 'external_reference' => 'BANK-REF-EXTERNAL',
]);
$settled = coreaxis_payroll_validate_payment_event($approvedCalculation, array_merge($history, [[
    'id' => 11, 'payment_key' => $paymentBase['payment_key'], 'idempotency_key' => $submit['idempotency_key'],
    'event_type' => 'payment_submitted', 'amount_cents' => 5000, 'previous_event_id' => 10,
]]), $settle);
payrollCalculationAssert($settled['event_type'] === 'payment_settled', 'settlement requires submitted state and external transfer reference');
$duplicateKey = $settle;
$replayedHistory = array_merge($history, [[
    'id' => 9, 'payment_key' => str_repeat('9', 64), 'idempotency_key' => $duplicateKey['idempotency_key'],
    'event_type' => 'payment_initiated', 'amount_cents' => 100,
]]);
payrollExpectBlocked(static function () use ($approvedCalculation, $replayedHistory, $duplicateKey) { coreaxis_payroll_validate_payment_event($approvedCalculation, $replayedHistory, $duplicateKey); }, 'duplicate_payment_idempotency_key', 'payment event idempotency prevents replay');
$noReference = array_merge($settle, ['idempotency_key' => str_repeat('e', 64), 'external_reference' => null]);
payrollExpectBlocked(static function () use ($approvedCalculation, $history, $noReference) { coreaxis_payroll_validate_payment_event($approvedCalculation, array_merge($history, [[
    'id' => 11, 'payment_key' => $noReference['payment_key'], 'idempotency_key' => str_repeat('c', 64), 'event_type' => 'payment_submitted', 'amount_cents' => 5000, 'previous_event_id' => 10,
]]), $noReference); }, 'external_payment_evidence_missing', 'settlement without external evidence is rejected');
$unauthorized = array_merge($paymentBase, ['actor_role' => 'Employee']);
payrollExpectBlocked(static function () use ($approvedCalculation, $unauthorized) { coreaxis_payroll_validate_payment_event($approvedCalculation, [], $unauthorized); }, 'payment_actor_not_authorized', 'non-Admin cannot create payment events');
$missingActor = array_merge($paymentBase, ['actor_user_id' => null]);
payrollExpectBlocked(static function () use ($approvedCalculation, $missingActor) { coreaxis_payroll_validate_payment_event($approvedCalculation, [], $missingActor); }, 'payment_actor_identity_invalid', 'payment audit requires a concrete actor identity');
$currencyMismatch = array_merge($paymentBase, ['currency' => 'USD']);
payrollExpectBlocked(static function () use ($approvedCalculation, $currencyMismatch) { coreaxis_payroll_validate_payment_event($approvedCalculation, [], $currencyMismatch); }, 'payment_currency_missing_or_mismatched', 'payment currency must match the approved payroll calculation');
$currencyMissing = $employee;
unset($currencyMissing['currency']);
payrollExpectBlocked(static function () use ($currencyMissing, $days, $policy) { coreaxis_payroll_calculate_month($currencyMissing, '2026-03-01', $days, $policy); }, 'authoritative_pay_currency_missing_or_mismatched', 'missing authoritative employee currency blocks calculation');
$unapproved = array_merge($approvedCalculation, ['status' => 'Draft']);
payrollExpectBlocked(static function () use ($unapproved, $paymentBase) { coreaxis_payroll_validate_payment_event($unapproved, [], $paymentBase); }, 'payment_requires_approved_calculation', 'payment release requires approved calculation');
$unavailable = array_merge($paymentBase, ['payment_transaction_exists' => true]);
payrollExpectBlocked(static function () use ($approvedCalculation, $unavailable) { coreaxis_payroll_validate_payment_event($approvedCalculation, [], $unavailable); }, 'duplicate_payment_idempotency_key', 'existing payment transaction cannot be initialized twice');
$badTransition = array_merge($paymentBase, [
    'payment_transaction_exists' => true, 'payment_transaction_id' => 21, 'event_type' => 'payment_settled',
    'idempotency_key' => str_repeat('f', 64), 'external_reference' => 'BANK-REF-EXTERNAL',
]);
payrollExpectBlocked(static function () use ($approvedCalculation, $history, $badTransition) { coreaxis_payroll_validate_payment_event($approvedCalculation, $history, $badTransition); }, 'payment_transition_invalid', 'settlement cannot skip submission');
$overAmount = array_merge($paymentBase, ['amount_cents' => 10001, 'transaction_amount_cents' => 10001]);
payrollExpectBlocked(static function () use ($approvedCalculation, $overAmount) { coreaxis_payroll_validate_payment_event($approvedCalculation, [], $overAmount); }, 'payment_amount_exceeds_approved_balance', 'payment intent cannot exceed approved net pay');
payrollCalculationAssert(coreaxis_payroll_derive_payment_status(5000, []) === 'Pending', 'payment status starts Pending until an event exists');
payrollCalculationAssert(coreaxis_payroll_derive_payment_status(10000, $history) === 'PaymentInProgress', 'initiated payment is distinct from settled payment');
$submittedHistory = array_merge($history, [[
    'id' => 11, 'payment_key' => $paymentBase['payment_key'], 'idempotency_key' => $submit['idempotency_key'],
    'event_type' => 'payment_submitted', 'amount_cents' => 5000, 'previous_event_id' => 10,
]]);
$settledHistory = array_merge($submittedHistory, [[
    'id' => 12, 'payment_key' => $paymentBase['payment_key'], 'idempotency_key' => str_repeat('d', 64), 'event_type' => 'payment_settled', 'amount_cents' => 5000, 'previous_event_id' => 11,
]]);
payrollCalculationAssert(coreaxis_payroll_derive_payment_status(10000, $settledHistory) === 'PartiallyPaid', 'partial settlement is derived from immutable payment events');
payrollCalculationAssert(coreaxis_payroll_derive_payment_status(5000, $settledHistory) === 'Paid', 'Paid state requires settled value to equal approved net pay');
$reversedHistory = array_merge($settledHistory, [[
    'id' => 13, 'payment_key' => $paymentBase['payment_key'], 'idempotency_key' => str_repeat('e', 64), 'event_type' => 'payment_reversed', 'amount_cents' => 5000, 'previous_event_id' => 12,
]]);
payrollCalculationAssert(coreaxis_payroll_derive_payment_status(10000, $reversedHistory) === 'Reversed', 'reversed payment remains distinguishable from never-paid');

$root = dirname(__DIR__);
$salarySource = file_get_contents($root . '/salary.php');
payrollCalculationAssert(is_string($salarySource), 'salary source is readable');
payrollCalculationAssert(
    strpos($salarySource, '$payroll_actions_enabled = false;') !== false
        && strpos($salarySource, 'http_response_code(503)') !== false,
    'salary generation and payment release remain fail-closed'
);
echo "PASS: portable payroll calculation contract completed; migration review is classified separately.\n";