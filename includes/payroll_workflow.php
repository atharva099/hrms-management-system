<?php

declare(strict_types=1);

require_once __DIR__ . '/payroll_database.php';

/**
 * Stage-only persistence workflow. No public page calls these functions while
 * the payroll release gate is disabled. All writes require the fully verified
 * candidate schema and run in a transaction.
 */
function coreaxis_payroll_workflow_actor(mysqli $conn, int $actorUserId, string $actorEmployeeId): array
{
    if ($actorUserId < 1 || preg_match('/^EMP[0-9]{3,17}$/iD', $actorEmployeeId) !== 1) {
        coreaxis_payroll_db_fail('payroll_actor_identity_invalid');
    }
    $rows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
        $conn,
        "SELECT id, employee_id, role, status FROM users WHERE id = ? FOR UPDATE",
        'i', [$actorUserId]
    ));
    if (count($rows) !== 1 || $rows[0]['employee_id'] !== $actorEmployeeId
        || $rows[0]['role'] !== 'Admin' || $rows[0]['status'] !== 'Active') {
        coreaxis_payroll_db_fail('active_admin_identity_required');
    }
    return $rows[0];
}

function coreaxis_payroll_workflow_insert_calculation_event(
    mysqli $conn,
    int $calculationId,
    string $eventType,
    ?string $previousStatus,
    string $newStatus,
    int $actorUserId,
    string $actorEmployeeId,
    string $reason
): void {
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 500) {
        coreaxis_payroll_db_fail('payroll_audit_reason_invalid');
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO payroll_calculation_events
         (calculation_id,event_type,previous_status,new_status,actor_user_id,actor_employee_id,reason)
         VALUES (?,?,?,?,?,?,?)'
    );
    if (!$stmt || !mysqli_stmt_bind_param(
        $stmt, 'isssiss', $calculationId, $eventType, $previousStatus, $newStatus,
        $actorUserId, $actorEmployeeId, $reason
    ) || !mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
        if ($stmt instanceof mysqli_stmt) { mysqli_stmt_close($stmt); }
        coreaxis_payroll_db_fail('payroll_calculation_event_write_failed');
    }
    mysqli_stmt_close($stmt);
}

/** Persist a server-calculated, fingerprinted Draft and its legacy salary row atomically. */
function coreaxis_payroll_create_draft(
    mysqli $conn,
    string $employeeId,
    string $period,
    int $actorUserId,
    string $actorEmployeeId,
    string $reason = 'Calculation draft created'
): int {
    coreaxis_payroll_db_assert_stage($conn);
    if (!mysqli_begin_transaction($conn)) { coreaxis_payroll_db_fail('payroll_transaction_unavailable'); }
    try {
        coreaxis_payroll_db_require_schema($conn);
        coreaxis_payroll_workflow_actor($conn, $actorUserId, $actorEmployeeId);
        $calculation = coreaxis_payroll_load_month($conn, $employeeId, $period, true);
        foreach (['late', 'half_day', 'absent'] as $requiredLegacyRule) {
            if (!isset($calculation['adjustments'][$requiredLegacyRule]['total_adjustment_cents'])) {
                coreaxis_payroll_db_fail('legacy_salary_breakdown_incompatible');
            }
        }
        $basicCents = coreaxis_payroll_amount_to_cents($calculation['basic_salary']);
        $incentiveCents = coreaxis_payroll_amount_to_cents($calculation['incentive']);
        $finalCents = coreaxis_payroll_amount_to_cents($calculation['final_salary']);
        $adjustmentCents = 0;
        $legacyAdjustmentCents = 0;
        $legacyRules = ['late' => 'late', 'half_day' => 'halfday', 'absent' => 'absent'];
        foreach (($calculation['adjustments'] ?? []) as $rule => $breakdown) {
            if (!is_string($rule) || !is_array($breakdown)
                || !is_int($breakdown['total_adjustment_cents'] ?? null)) {
                coreaxis_payroll_db_fail('payroll_calculation_snapshot_invalid');
            }
            $ruleCents = $breakdown['total_adjustment_cents'];
            if (($ruleCents > 0 && $ruleCents > PHP_INT_MAX - $adjustmentCents)
                || ($ruleCents < 0 && $adjustmentCents < PHP_INT_MIN - $ruleCents)) {
                coreaxis_payroll_db_fail('payroll_calculation_snapshot_invalid');
            }
            $adjustmentCents += $ruleCents;
            if (!array_key_exists($rule, $legacyRules)) {
                if ($ruleCents !== 0) {
                    coreaxis_payroll_db_fail('legacy_salary_breakdown_incompatible');
                }
                continue;
            }
            // The legacy salary row has deduction columns only. Do not persist
            // a positive adjustment or an amount it cannot represent exactly.
            if ($ruleCents > 0 || $ruleCents < -9999999999) {
                coreaxis_payroll_db_fail('legacy_salary_breakdown_incompatible');
            }
            $legacyAdjustmentCents += $ruleCents;
        }
        $reportedAdjustmentCents = is_string($calculation['total_adjustment'] ?? null)
            ? coreaxis_payroll_signed_amount_to_cents($calculation['total_adjustment'])
            : false;
        if ($basicCents === false || $incentiveCents === false || $finalCents === false
            || $reportedAdjustmentCents === false || $reportedAdjustmentCents !== $adjustmentCents
            || $legacyAdjustmentCents !== $adjustmentCents
            || $basicCents + $incentiveCents + $adjustmentCents !== $finalCents
            || !is_string($calculation['input_fingerprint'] ?? null)
            || !is_string($calculation['source_sha256'] ?? null)
            || !hash_equals($calculation['source_sha256'], hash('sha256', $calculation['input_fingerprint']))
            || !is_int($calculation['policy_version_id'] ?? null)
            || !is_string($calculation['currency'] ?? null)
            || preg_match('/^[A-Z]{3}$/D', $calculation['currency']) !== 1) {
            coreaxis_payroll_db_fail('payroll_calculation_snapshot_invalid');
        }
        $existing = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            'SELECT id FROM salaries WHERE employee_id = ? AND salary_period = ? LIMIT 1 FOR UPDATE',
            'ss', [$employeeId, $period]
        ));
        if ($existing) { coreaxis_payroll_db_fail('duplicate_employee_period'); }

        $periodLabel = (new DateTimeImmutable($period))->format('F Y');
        $legacyDeductions = [
            'late' => -$calculation['adjustments']['late']['total_adjustment_cents'],
            'half_day' => -$calculation['adjustments']['half_day']['total_adjustment_cents'],
            'absent' => -$calculation['adjustments']['absent']['total_adjustment_cents'],
        ];
        $basic = coreaxis_payroll_cents_to_decimal($basicCents);
        $incentive = coreaxis_payroll_cents_to_decimal($incentiveCents);
        $late = coreaxis_payroll_cents_to_decimal($legacyDeductions['late']);
        $halfDay = coreaxis_payroll_cents_to_decimal($legacyDeductions['half_day']);
        $absent = coreaxis_payroll_cents_to_decimal($legacyDeductions['absent']);
        $final = coreaxis_payroll_cents_to_decimal($finalCents);
        $salaryStmt = mysqli_prepare(
            $conn,
            "INSERT INTO salaries (employee_id,salary_month,salary_period,basic_salary,incentive,
             late_deduction,halfday_deduction,absent_deduction,final_salary,payment_status)
             VALUES (?,?,?,?,?,?,?,?,?,'Pending')"
        );
        if (!$salaryStmt || !mysqli_stmt_bind_param(
            $salaryStmt, 'sssssssss', $employeeId, $periodLabel, $period, $basic, $incentive,
            $late, $halfDay, $absent, $final
        ) || !mysqli_stmt_execute($salaryStmt) || mysqli_stmt_affected_rows($salaryStmt) !== 1) {
            if ($salaryStmt instanceof mysqli_stmt) { mysqli_stmt_close($salaryStmt); }
            coreaxis_payroll_db_fail('payroll_salary_row_write_failed');
        }
        $salaryId = (int) mysqli_stmt_insert_id($salaryStmt);
        mysqli_stmt_close($salaryStmt);

        $inputSnapshot = json_encode([
            'source_inputs' => json_decode($calculation['input_fingerprint'], true, 512, JSON_THROW_ON_ERROR),
            'adjustments' => $calculation['adjustments'],
            'total_adjustment' => $calculation['total_adjustment'],
            'final_salary' => $calculation['final_salary'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $snapshotStmt = mysqli_prepare(
            $conn,
            "INSERT INTO payroll_calculation_snapshots
             (salary_id,employee_id,salary_period,currency,policy_version_id,source_sha256,input_snapshot,
              basic_salary_cents,incentive_cents,adjustment_cents,final_salary_cents,calculation_status,created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,'Draft',?)"
        );
        if (!$snapshotStmt || !mysqli_stmt_bind_param(
            $snapshotStmt, 'isssissiiiii', $salaryId, $employeeId, $period, $calculation['currency'],
            $calculation['policy_version_id'], $calculation['source_sha256'], $inputSnapshot,
            $basicCents, $incentiveCents, $adjustmentCents, $finalCents, $actorUserId
        ) || !mysqli_stmt_execute($snapshotStmt) || mysqli_stmt_affected_rows($snapshotStmt) !== 1) {
            if ($snapshotStmt instanceof mysqli_stmt) { mysqli_stmt_close($snapshotStmt); }
            coreaxis_payroll_db_fail('payroll_snapshot_write_failed');
        }
        $snapshotId = (int) mysqli_stmt_insert_id($snapshotStmt);
        mysqli_stmt_close($snapshotStmt);
        coreaxis_payroll_workflow_insert_calculation_event(
            $conn, $snapshotId, 'draft_created', null, 'Draft', $actorUserId, $actorEmployeeId, $reason
        );
        if (!mysqli_commit($conn)) { coreaxis_payroll_db_fail('payroll_transaction_commit_failed'); }
        return $snapshotId;
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}

/** Independent Admin approves only if the locked source fingerprint is unchanged. */
function coreaxis_payroll_approve_draft(
    mysqli $conn,
    int $snapshotId,
    int $actorUserId,
    string $actorEmployeeId,
    string $reason
): bool {
    coreaxis_payroll_db_assert_stage($conn);
    if ($snapshotId < 1 || trim($reason) === '' || strlen(trim($reason)) > 500) {
        coreaxis_payroll_db_fail('payroll_approval_input_invalid');
    }
    if (!mysqli_begin_transaction($conn)) { coreaxis_payroll_db_fail('payroll_transaction_unavailable'); }
    try {
        coreaxis_payroll_db_require_schema($conn);
        coreaxis_payroll_workflow_actor($conn, $actorUserId, $actorEmployeeId);
        $rows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id,salary_id,employee_id,salary_period,source_sha256,input_snapshot,calculation_status,
                    created_by_user_id,policy_version_id,final_salary_cents
             FROM payroll_calculation_snapshots WHERE id = ? FOR UPDATE",
            'i', [$snapshotId]
        ));
        if (count($rows) !== 1 || $rows[0]['calculation_status'] !== 'Draft'
            || (int) $rows[0]['created_by_user_id'] === $actorUserId) {
            coreaxis_payroll_db_fail('payroll_approval_not_independent_or_not_draft');
        }
        $stored = $rows[0];
        $salaryRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            'SELECT id,employee_id,salary_period,final_salary,payment_status FROM salaries WHERE id = ? FOR UPDATE',
            'i', [(int) $stored['salary_id']]
        ));
        if (count($salaryRows) !== 1 || $salaryRows[0]['employee_id'] !== $stored['employee_id']
            || $salaryRows[0]['salary_period'] !== $stored['salary_period']
            || $salaryRows[0]['payment_status'] !== 'Pending'
            || coreaxis_payroll_amount_to_cents($salaryRows[0]['final_salary']) !== (int) $stored['final_salary_cents']) {
            coreaxis_payroll_db_fail('payroll_draft_salary_source_changed');
        }
        $calculation = coreaxis_payroll_load_month($conn, $stored['employee_id'], $stored['salary_period'], true);
        $currentFinalCents = coreaxis_payroll_amount_to_cents($calculation['final_salary'] ?? null);
        $sameSource = hash_equals($stored['source_sha256'], $calculation['source_sha256'])
            && (int) $stored['policy_version_id'] === (int) $calculation['policy_version_id']
            && $currentFinalCents !== false
            && (int) $stored['final_salary_cents'] === $currentFinalCents;
        $nextStatus = $sameSource ? 'Validated' : 'Blocked';
        $update = mysqli_prepare(
            $conn,
            'UPDATE payroll_calculation_snapshots SET calculation_status = ? WHERE id = ? AND calculation_status = \'Draft\''
        );
        if (!$update || !mysqli_stmt_bind_param($update, 'si', $nextStatus, $snapshotId)
            || !mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== 1) {
            if ($update instanceof mysqli_stmt) { mysqli_stmt_close($update); }
            coreaxis_payroll_db_fail('payroll_snapshot_transition_failed');
        }
        mysqli_stmt_close($update);
        coreaxis_payroll_workflow_insert_calculation_event(
            $conn, $snapshotId, $sameSource ? 'draft_validated' : 'source_changed', 'Draft',
            $nextStatus, $actorUserId, $actorEmployeeId,
            $sameSource ? trim($reason) : 'Approval blocked because payroll source data changed'
        );
        if ($sameSource) {
            $approve = mysqli_prepare(
                $conn,
                'UPDATE payroll_calculation_snapshots SET calculation_status = \'Approved\' WHERE id = ? AND calculation_status = \'Validated\''
            );
            if (!$approve || !mysqli_stmt_bind_param($approve, 'i', $snapshotId)
                || !mysqli_stmt_execute($approve) || mysqli_stmt_affected_rows($approve) !== 1) {
                if ($approve instanceof mysqli_stmt) { mysqli_stmt_close($approve); }
                coreaxis_payroll_db_fail('payroll_snapshot_transition_failed');
            }
            mysqli_stmt_close($approve);
            coreaxis_payroll_workflow_insert_calculation_event(
                $conn, $snapshotId, 'draft_approved', 'Validated', 'Approved', $actorUserId, $actorEmployeeId, trim($reason)
            );
        }
        if (!mysqli_commit($conn)) { coreaxis_payroll_db_fail('payroll_transaction_commit_failed'); }
        return $sameSource;
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}
