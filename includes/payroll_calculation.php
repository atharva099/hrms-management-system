<?php

declare(strict_types=1);

require_once __DIR__ . '/payroll_period.php';

/** Payroll rule/input failures are safe to show as a blocked calculation. */
final class CoreAxisPayrollCalculationException extends DomainException
{
    private string $reasonCode;

    public function __construct(string $reasonCode)
    {
        $this->reasonCode = $reasonCode;
        parent::__construct('Payroll calculation is blocked: ' . $reasonCode);
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }
}

function coreaxis_payroll_calculation_fail(string $reason): void
{
    throw new CoreAxisPayrollCalculationException($reason);
}

/** Generate an opaque idempotency token; callers must still enforce uniqueness in SQL. */
function coreaxis_payroll_new_idempotency_key(): string
{
    return bin2hex(random_bytes(32));
}

function coreaxis_payroll_is_iso_date($value): bool
{
    if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

/**
 * Validate and calculate a complete month from normalized, server-loaded inputs.
 * All rule amounts are explicitly approved integer cents; no rate is defaulted.
 * Each day in the calendar month must be supplied exactly once by the adapter.
 */
function coreaxis_payroll_calculate_month(array $employee, string $period, array $days, array $policy): array
{
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-01$/D', $period) || !coreaxis_payroll_is_iso_date($period)) {
        coreaxis_payroll_calculation_fail('invalid_period');
    }
    if (($employee['role'] ?? null) !== 'Employee' || ($employee['status'] ?? null) !== 'Active') {
        coreaxis_payroll_calculation_fail('inactive_or_unauthorized_employee');
    }
    $employeeId = $employee['employee_id'] ?? null;
    if (!is_string($employeeId) || preg_match('/^EMP[0-9]{3,17}$/iD', $employeeId) !== 1) {
        coreaxis_payroll_calculation_fail('invalid_employee_identity');
    }
    $basicCents = coreaxis_payroll_amount_to_cents($employee['salary'] ?? null);
    $incentiveCents = coreaxis_payroll_amount_to_cents($employee['incentive'] ?? null);
    if ($basicCents === false || $incentiveCents === false) {
        coreaxis_payroll_calculation_fail('invalid_authoritative_pay_amount');
    }
    $start = new DateTimeImmutable($period);
    $monthEnd = $start->modify('+1 month');
    if (($policy['status'] ?? null) !== 'Approved'
        || !is_int($policy['effective_policy_count'] ?? null) || $policy['effective_policy_count'] !== 1
        || !is_int($policy['version'] ?? null) || $policy['version'] < 1
        || !coreaxis_payroll_is_iso_date($policy['effective_from'] ?? null)
        || !coreaxis_payroll_is_iso_date($policy['effective_through'] ?? null)
        || $policy['effective_from'] > $period
        || $policy['effective_through'] < $monthEnd->modify('-1 day')->format('Y-m-d')) {
        coreaxis_payroll_calculation_fail('approved_policy_missing_or_not_effective_for_full_period');
    }
    $employeeCurrency = $employee['currency'] ?? null;
    $policyCurrency = $policy['currency'] ?? null;
    if (!is_string($employeeCurrency) || preg_match('/^[A-Z]{3}$/D', $employeeCurrency) !== 1
        || !is_string($policyCurrency) || preg_match('/^[A-Z]{3}$/D', $policyCurrency) !== 1
        || $employeeCurrency !== $policyCurrency) {
        coreaxis_payroll_calculation_fail('authoritative_pay_currency_missing_or_mismatched');
    }

    $requiredRules = [
        'late', 'half_day', 'absent', 'paid_leave', 'unpaid_leave',
        'holiday_work', 'weekly_off_work', 'holiday_nonworkday', 'weekly_off_nonworkday',
    ];
    $rules = $policy['adjustment_cents'] ?? null;
    if (!is_array($rules)) {
        coreaxis_payroll_calculation_fail('payroll_rules_missing');
    }
    foreach ($requiredRules as $ruleName) {
        if (!array_key_exists($ruleName, $rules) || !is_int($rules[$ruleName])
            || abs($rules[$ruleName]) > 9999999999) {
            coreaxis_payroll_calculation_fail('payroll_rule_missing_or_invalid_' . $ruleName);
        }
    }
    ksort($rules);
    foreach (['late', 'half_day', 'absent', 'unpaid_leave'] as $deductionRule) {
        if ($rules[$deductionRule] > 0) {
            coreaxis_payroll_calculation_fail('deduction_rule_has_earning_sign_' . $deductionRule);
        }
    }
    foreach (['holiday_work', 'weekly_off_work'] as $earningRule) {
        if ($rules[$earningRule] < 0) {
            coreaxis_payroll_calculation_fail('work_premium_rule_has_deduction_sign_' . $earningRule);
        }
    }
    if ($rules['paid_leave'] < 0) {
        coreaxis_payroll_calculation_fail('paid_leave_rule_cannot_deduct');
    }
    foreach (['leave_counts_on_holidays', 'leave_counts_on_weekly_offs'] as $flag) {
        if (!array_key_exists($flag, $policy) || !is_bool($policy[$flag])) {
            coreaxis_payroll_calculation_fail('leave_calendar_rule_missing_or_invalid');
        }
    }
    $leaveClassifications = $policy['leave_classifications'] ?? null;
    if (!is_array($leaveClassifications)) {
        coreaxis_payroll_calculation_fail('leave_classification_policy_missing');
    }

    $numberOfDays = (int) $start->format('t');
    if (count($days) !== $numberOfDays) {
        coreaxis_payroll_calculation_fail('incomplete_payroll_day_inputs');
    }
    $byDate = [];
    foreach ($days as $day) {
        if (!is_array($day) || !coreaxis_payroll_is_iso_date($day['date'] ?? null)
            || substr($day['date'], 0, 7) !== substr($period, 0, 7)) {
            coreaxis_payroll_calculation_fail('invalid_payroll_day_input');
        }
        if (isset($byDate[$day['date']])) {
            coreaxis_payroll_calculation_fail('duplicate_day_input');
        }
        foreach (['scheduled_workday', 'holiday', 'weekly_off', 'attendance_conflict'] as $booleanField) {
            if (!array_key_exists($booleanField, $day) || !is_bool($day[$booleanField])) {
                coreaxis_payroll_calculation_fail('incomplete_payroll_day_inputs');
            }
        }
        if (!is_int($day['active_leave_count'] ?? null) || $day['active_leave_count'] < 0) {
            coreaxis_payroll_calculation_fail('leave_input_incomplete');
        }
        ksort($day);
        $byDate[$day['date']] = $day;
    }

    $events = array_fill_keys($requiredRules, 0);
    $leaveTypes = [];
    for ($cursor = $start; $cursor < $monthEnd; $cursor = $cursor->modify('+1 day')) {
        $date = $cursor->format('Y-m-d');
        if (!isset($byDate[$date])) {
            coreaxis_payroll_calculation_fail('incomplete_payroll_day_inputs');
        }
        $day = $byDate[$date];
        $attendanceStatus = $day['attendance_status'] ?? null;
        $leaveStatus = $day['leave_status'] ?? null;
        $leaveType = $day['leave_type'] ?? null;
        $activeShift = ($day['shift_status'] ?? null) === 'Active';

        if ($day['attendance_conflict']) {
            coreaxis_payroll_calculation_fail('attendance_conflict');
        }
        if ($leaveStatus === 'Pending') {
            coreaxis_payroll_calculation_fail('leave_approval_incomplete');
        }
        if ($day['active_leave_count'] > 1) {
            coreaxis_payroll_calculation_fail('overlapping_leave_requests');
        }
        if (($day['active_leave_count'] === 1) !== in_array($leaveStatus, ['Pending', 'Approved'], true)) {
            coreaxis_payroll_calculation_fail('leave_input_incomplete');
        }
        if ($leaveStatus !== null && !in_array($leaveStatus, ['Approved', 'Rejected'], true)) {
            coreaxis_payroll_calculation_fail('invalid_leave_status');
        }
        if ($leaveStatus === 'Approved' && (!is_string($leaveType) || trim($leaveType) === '')) {
            coreaxis_payroll_calculation_fail('approved_leave_type_missing');
        }
        if ($leaveStatus !== 'Approved' && $attendanceStatus === 'Leave') {
            coreaxis_payroll_calculation_fail('leave_attendance_without_approved_leave');
        }
        if ($leaveStatus === 'Approved' && $attendanceStatus !== null && $attendanceStatus !== 'Leave') {
            coreaxis_payroll_calculation_fail('attendance_conflicts_with_approved_leave');
        }

        $worked = in_array($attendanceStatus, [
            'Present', 'Late', 'Half Day', 'Late Half Day', 'Sunday Working', 'Holiday Working',
        ], true);
        if (($day['scheduled_workday'] || $worked) && !$activeShift) {
            coreaxis_payroll_calculation_fail('missing_or_inactive_shift');
        }
        if ($attendanceStatus === 'Absent' && ($day['holiday'] || $day['weekly_off'])) {
            coreaxis_payroll_calculation_fail('absence_record_conflicts_with_nonworkday');
        }
        if ($day['scheduled_workday'] && !$day['holiday'] && $attendanceStatus === null && $leaveStatus !== 'Approved') {
            coreaxis_payroll_calculation_fail('scheduled_day_attendance_missing');
        }
        $allowedStatuses = [null, 'Present', 'Late', 'Half Day', 'Late Half Day', 'Absent', 'Sunday Working', 'Holiday Working', 'Leave'];
        if (!in_array($attendanceStatus, $allowedStatuses, true)) {
            coreaxis_payroll_calculation_fail('unknown_attendance_status');
        }

        $classifiedLeave = null;
        if ($leaveStatus === 'Approved') {
            if (!array_key_exists($leaveType, $leaveClassifications)
                || !in_array($leaveClassifications[$leaveType], ['Paid', 'Unpaid'], true)) {
                coreaxis_payroll_calculation_fail('leave_type_payment_classification_missing');
            }
            $classifiedLeave = $leaveClassifications[$leaveType];
            $leaveTypes[$leaveType] = $classifiedLeave;
        }

        $chargeLeave = $classifiedLeave !== null
            && (!$day['holiday'] || $policy['leave_counts_on_holidays'])
            && (!$day['weekly_off'] || $policy['leave_counts_on_weekly_offs']);
        if ($chargeLeave) {
            $events[$classifiedLeave === 'Paid' ? 'paid_leave' : 'unpaid_leave']++;
        }
        if (!$worked && $day['holiday']
            && !($classifiedLeave !== null && $policy['leave_counts_on_holidays'])) {
            $events['holiday_nonworkday']++;
        }
        if (!$worked && $day['weekly_off']
            && !($classifiedLeave !== null && $policy['leave_counts_on_weekly_offs'])) {
            $events['weekly_off_nonworkday']++;
        }

        if ($worked) {
            if ($day['holiday']) {
                $events['holiday_work']++;
            }
            if ($day['weekly_off']) {
                $events['weekly_off_work']++;
            }
            if ($attendanceStatus === 'Late' || $attendanceStatus === 'Late Half Day') {
                $events['late']++;
            }
            if ($attendanceStatus === 'Half Day' || $attendanceStatus === 'Late Half Day') {
                $events['half_day']++;
            }
        } elseif ($attendanceStatus === 'Absent' && $day['scheduled_workday']) {
            $events['absent']++;
        }
    }

    ksort($byDate);
    ksort($leaveTypes);
    $breakdown = [];
    $adjustments = 0;
    foreach ($requiredRules as $ruleName) {
        $count = $events[$ruleName];
        $amount = $count * $rules[$ruleName];
        $adjustments += $amount;
        $breakdown[$ruleName] = [
            'count' => $count,
            'adjustment_cents_each' => $rules[$ruleName],
            'total_adjustment_cents' => $amount,
        ];
    }
    $netCents = $basicCents + $incentiveCents + $adjustments;
    if ($netCents < 0) {
        coreaxis_payroll_calculation_fail('calculation_would_produce_negative_net_pay');
    }

    $source = [
        'employee_id' => $employeeId,
        'period' => $period,
        'basic_cents' => $basicCents,
        'incentive_cents' => $incentiveCents,
        'compensation_version_id' => isset($employee['compensation_version_id']) && is_int($employee['compensation_version_id']) ? $employee['compensation_version_id'] : null,
        'days' => array_values($byDate),
        'policy_version_id' => isset($policy['id']) && is_int($policy['id']) ? $policy['id'] : null,
        'policy_version' => $policy['version'],
        'effective_policy_count' => $policy['effective_policy_count'],
        'currency' => $policyCurrency,
        'policy_effective_from' => $policy['effective_from'],
        'policy_effective_through' => $policy['effective_through'],
        'rules' => $rules,
        'leave_classifications' => $leaveTypes,
        'leave_counts_on_holidays' => $policy['leave_counts_on_holidays'],
        'leave_counts_on_weekly_offs' => $policy['leave_counts_on_weekly_offs'],
    ];
    $sourceJson = json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return [
        'employee_id' => $employeeId,
        'salary_period' => $period,
        'policy_version' => $policy['version'],
        'currency' => $policyCurrency,
        'source_sha256' => hash('sha256', $sourceJson),
        'basic_salary' => coreaxis_payroll_cents_to_decimal($basicCents),
        'incentive' => coreaxis_payroll_cents_to_decimal($incentiveCents),
        'adjustments' => $breakdown,
        'total_adjustment' => coreaxis_payroll_cents_to_decimal($adjustments),
        'final_salary' => coreaxis_payroll_cents_to_decimal($netCents),
        'leave_classifications_used' => $leaveTypes,
        'input_fingerprint' => $sourceJson,
        'status' => 'Draft',
    ];
}

function coreaxis_payroll_assert_period_not_generated(array $existing, string $employeeId, string $period): void
{
    if (preg_match('/^EMP[0-9]{3,17}$/iD', $employeeId) !== 1
        || !preg_match('/^[0-9]{4}-[0-9]{2}-01$/D', $period)
        || !coreaxis_payroll_is_iso_date($period)) {
        coreaxis_payroll_calculation_fail('invalid_duplicate_check_scope');
    }
    foreach ($existing as $row) {
        if (is_array($row)
            && ($row['employee_id'] ?? null) === $employeeId
            && ($row['salary_period'] ?? null) === $period) {
            coreaxis_payroll_calculation_fail('duplicate_employee_period');
        }
    }
}

function coreaxis_payroll_normalize_payment_history(array $history): array
{
    $seenIds = [];
    $seenEventKeys = [];
    foreach ($history as $event) {
        if (!is_array($event) || !is_int($event['id'] ?? null) || $event['id'] < 1
            || isset($seenIds[$event['id']])
            || !is_string($event['payment_key'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $event['payment_key']) !== 1
            || !is_string($event['idempotency_key'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $event['idempotency_key']) !== 1
            || isset($seenEventKeys[$event['idempotency_key']])
            || !is_int($event['amount_cents'] ?? null) || $event['amount_cents'] < 1
            || !in_array($event['event_type'] ?? null, [
                'payment_initiated', 'payment_submitted', 'payment_settled', 'payment_failed', 'payment_reversed',
            ], true)
            || (($event['previous_event_id'] ?? null) !== null
                && (!is_int($event['previous_event_id']) || $event['previous_event_id'] < 1))) {
            coreaxis_payroll_calculation_fail('payment_history_invalid');
        }
        $seenIds[$event['id']] = true;
        $seenEventKeys[$event['idempotency_key']] = true;
    }
    usort($history, static function ($left, $right) { return $left['id'] <=> $right['id']; });
    $latest = [];
    $expectedPrevious = [
        'payment_submitted' => 'payment_initiated',
        'payment_settled' => 'payment_submitted',
        'payment_failed' => ['payment_initiated', 'payment_submitted'],
        'payment_reversed' => 'payment_settled',
    ];
    foreach ($history as $event) {
        $key = $event['payment_key'];
        if (!isset($latest[$key])) {
            if ($event['event_type'] !== 'payment_initiated' || ($event['previous_event_id'] ?? null) !== null) {
                coreaxis_payroll_calculation_fail('payment_history_invalid');
            }
        } else {
            $previous = $latest[$key];
            $required = $expectedPrevious[$event['event_type']] ?? null;
            if ($required === null || ($event['previous_event_id'] ?? null) !== $previous['id']
                || $event['amount_cents'] !== $previous['amount_cents']
                || (is_array($required)
                    ? !in_array($previous['event_type'], $required, true)
                    : $previous['event_type'] !== $required)) {
                coreaxis_payroll_calculation_fail('payment_history_invalid');
            }
        }
        $latest[$key] = $event;
    }
    return $history;
}

/**
 * Validate one append-only payment-event transition. The caller must hold the
 * salary row lock and insert this event in the same DB transaction as the
 * corresponding calculation/payment state update.
 */
function coreaxis_payroll_validate_payment_event(array $calculation, array $history, array $command): array
{
    if (($calculation['status'] ?? null) !== 'Approved') {
        coreaxis_payroll_calculation_fail('payment_requires_approved_calculation');
    }
    if (($command['actor_role'] ?? null) !== 'Admin' || ($command['actor_status'] ?? null) !== 'Active') {
        coreaxis_payroll_calculation_fail('payment_actor_not_authorized');
    }
    $actorUserId = $command['actor_user_id'] ?? null;
    $actorEmployeeId = $command['actor_employee_id'] ?? null;
    if (!is_int($actorUserId) || $actorUserId < 1
        || !is_string($actorEmployeeId) || preg_match('/^EMP[0-9]{3,17}$/iD', $actorEmployeeId) !== 1) {
        coreaxis_payroll_calculation_fail('payment_actor_identity_invalid');
    }
    if (!is_string($command['currency'] ?? null)
        || preg_match('/^[A-Z]{3}$/D', $command['currency']) !== 1
        || $command['currency'] !== ($calculation['currency'] ?? null)) {
        coreaxis_payroll_calculation_fail('payment_currency_missing_or_mismatched');
    }
    $eventKey = $command['idempotency_key'] ?? null;
    $paymentKey = $command['payment_key'] ?? null;
    if (!is_string($eventKey) || preg_match('/^[a-f0-9]{64}$/D', $eventKey) !== 1
        || !is_string($paymentKey) || preg_match('/^[a-f0-9]{64}$/D', $paymentKey) !== 1) {
        coreaxis_payroll_calculation_fail('payment_idempotency_key_invalid');
    }
    $history = coreaxis_payroll_normalize_payment_history($history);
    foreach ($history as $event) {
        if ($event['idempotency_key'] === $eventKey) {
            coreaxis_payroll_calculation_fail('duplicate_payment_idempotency_key');
        }
    }
    $samePayment = array_values(array_filter($history, static function ($event) use ($paymentKey) {
        return is_array($event) && ($event['payment_key'] ?? null) === $paymentKey;
    }));
    $previous = $samePayment ? $samePayment[count($samePayment) - 1] : null;
    $eventType = $command['event_type'] ?? null;
    $allowedPrevious = [
        'payment_initiated' => null,
        'payment_submitted' => 'payment_initiated',
        'payment_settled' => 'payment_submitted',
        'payment_failed' => ['payment_initiated', 'payment_submitted'],
        'payment_reversed' => 'payment_settled',
    ];
    if (!is_string($eventType) || !array_key_exists($eventType, $allowedPrevious)) {
        coreaxis_payroll_calculation_fail('payment_transition_invalid');
    }
    $expected = $allowedPrevious[$eventType];
    if ($eventType === 'payment_initiated' ? $previous !== null
        : ($previous === null || (is_array($expected)
            ? !in_array($previous['event_type'] ?? null, $expected, true)
            : ($previous['event_type'] ?? null) !== $expected))) {
        coreaxis_payroll_calculation_fail('payment_transition_invalid');
    }
    if (!is_bool($command['payment_transaction_exists'] ?? null)
        || ($eventType === 'payment_initiated' && $command['payment_transaction_exists'])
        || ($eventType !== 'payment_initiated' && !$command['payment_transaction_exists'])) {
        coreaxis_payroll_calculation_fail('duplicate_payment_idempotency_key');
    }
    $transactionId = $command['payment_transaction_id'] ?? null;
    if (($eventType === 'payment_initiated' && $transactionId !== null)
        || ($eventType !== 'payment_initiated'
            && (!is_int($transactionId) || $transactionId < 1))) {
        coreaxis_payroll_calculation_fail('payment_transaction_identity_invalid');
    }
    if (!is_string($command['reason'] ?? null) || trim($command['reason']) === '' || strlen(trim($command['reason'])) > 500) {
        coreaxis_payroll_calculation_fail('payment_reason_missing');
    }
    $amountCents = $command['amount_cents'] ?? null;
    $transactionAmount = $command['transaction_amount_cents'] ?? null;
    if (!is_int($amountCents) || $amountCents <= 0 || $amountCents > 9999999999
        || !is_int($transactionAmount) || $transactionAmount !== $amountCents) {
        coreaxis_payroll_calculation_fail('payment_amount_invalid');
    }
    if ($previous !== null && (int) ($previous['amount_cents'] ?? -1) !== $amountCents) {
        coreaxis_payroll_calculation_fail('payment_transition_amount_mismatch');
    }
    $settledNet = 0;
    $latestByPayment = [];
    foreach ($history as $event) {
        $latestByPayment[$event['payment_key']] = $event;
        if ($event['event_type'] === 'payment_settled') {
            $settledNet += (int) $event['amount_cents'];
        } elseif ($event['event_type'] === 'payment_reversed') {
            $settledNet -= (int) $event['amount_cents'];
        }
    }
    $reservedCents = 0;
    foreach ($latestByPayment as $latest) {
        if (in_array($latest['event_type'], ['payment_initiated', 'payment_submitted'], true)) {
            $reservedCents += (int) $latest['amount_cents'];
        }
    }
    $approvedAmount = $calculation['approved_amount_cents'] ?? null;
    if (!is_int($approvedAmount) || $approvedAmount < 0) {
        coreaxis_payroll_calculation_fail('approved_payment_amount_invalid');
    }
    if (in_array($eventType, ['payment_initiated', 'payment_submitted', 'payment_settled'], true)
        && ($settledNet + $reservedCents + ($eventType === 'payment_initiated' ? $amountCents : 0) > $approvedAmount)) {
        coreaxis_payroll_calculation_fail('payment_amount_exceeds_approved_balance');
    }
    $reference = $command['external_reference'] ?? null;
    if (in_array($eventType, ['payment_settled', 'payment_reversed'], true)
        && (!is_string($reference) || trim($reference) === '' || strlen($reference) > 191)) {
        coreaxis_payroll_calculation_fail('external_payment_evidence_missing');
    }
    if ($eventType === 'payment_reversed' && $settledNet < $amountCents) {
        coreaxis_payroll_calculation_fail('payment_reversal_exceeds_settled_balance');
    }
    return [
        'amount_cents' => $amountCents,
        'external_reference' => is_string($reference) && trim($reference) !== '' ? trim($reference) : null,
        'idempotency_key' => $eventKey,
        'payment_key' => $paymentKey,
        'payment_transaction_id' => $transactionId,
        'currency' => $command['currency'],
        'previous_event_id' => $previous['id'] ?? null,
        'actor_user_id' => $actorUserId,
        'actor_employee_id' => $actorEmployeeId,
        'reason' => trim($command['reason']),
        'event_type' => $eventType,
    ];
}

/** Derive payment state from the immutable event stream; do not trust a POSTed flag. */
function coreaxis_payroll_derive_payment_status(int $approvedAmountCents, array $history): string
{
    if ($approvedAmountCents < 0) {
        coreaxis_payroll_calculation_fail('approved_payment_amount_invalid');
    }
    $settledNet = 0;
    $latestByPayment = [];
    $sawFailure = false;
    $sawReversal = false;
    $history = coreaxis_payroll_normalize_payment_history($history);
    foreach ($history as $event) {
        if (!in_array($event['event_type'] ?? null, [
            'payment_initiated', 'payment_submitted', 'payment_settled', 'payment_failed', 'payment_reversed',
        ], true)) {
            coreaxis_payroll_calculation_fail('payment_history_invalid');
        }
        $latestByPayment[$event['payment_key']] = $event;
        if ($event['event_type'] === 'payment_settled') {
            $settledNet += $event['amount_cents'];
        } elseif ($event['event_type'] === 'payment_reversed') {
            $settledNet -= $event['amount_cents'];
            $sawReversal = true;
        } elseif ($event['event_type'] === 'payment_failed') {
            $sawFailure = true;
        }
    }
    if ($settledNet < 0 || $settledNet > $approvedAmountCents) {
        coreaxis_payroll_calculation_fail('payment_history_exceeds_approved_amount');
    }
    if ($approvedAmountCents > 0 && $settledNet === $approvedAmountCents) {
        return 'Paid';
    }
    if ($settledNet > 0) {
        return 'PartiallyPaid';
    }
    foreach ($latestByPayment as $latest) {
        if (in_array($latest['event_type'], ['payment_initiated', 'payment_submitted'], true)) {
            return 'PaymentInProgress';
        }
    }
    if ($sawReversal) {
        return 'Reversed';
    }
    return $sawFailure ? 'PaymentFailed' : 'Pending';
}
