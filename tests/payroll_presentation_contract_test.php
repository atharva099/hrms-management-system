<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/payroll_presentation.php';

function payrollPresentationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$pending = coreaxis_payroll_payment_presentation('Pending', null);
payrollPresentationAssert($pending === ['status' => 'Pending', 'date' => 'Not recorded'], 'pending payroll never displays an invented payment date');
$pendingWithStaleDate = coreaxis_payroll_payment_presentation('Pending', '2026-10-12');
payrollPresentationAssert($pendingWithStaleDate['date'] === 'Not recorded', 'pending status suppresses a conflicting stored payment date');

$paid = coreaxis_payroll_payment_presentation('Paid', '2026-10-12');
payrollPresentationAssert($paid === ['status' => 'Paid', 'date' => '12 Oct 2026'], 'paid payroll displays only its recorded payment date');

$invalid = coreaxis_payroll_payment_presentation('Paid', '2026-02-31');
payrollPresentationAssert($invalid['date'] === 'Not recorded', 'invalid calendar dates are not presented as payment evidence');

$unknown = coreaxis_payroll_payment_presentation('Reversed', null);
payrollPresentationAssert($unknown['status'] === 'Status unavailable', 'unknown legacy payment states are not silently mapped');

$root = dirname(__DIR__);
$slip = file_get_contents($root . '/salary_slip.php');
$report = file_get_contents($root . '/salary_report.php');
payrollPresentationAssert(is_string($slip) && strpos($slip, "date('d M Y')") === false && strpos($slip, "['payment_date']") !== false, 'salary slip uses the stored payment date, not the render date');
payrollPresentationAssert(is_string($report) && strpos($report, 'COUNT(DISTINCT CASE WHEN payment_status=\'Paid\' THEN employee_id END)') !== false && strpos($report, '$payment_presentation[\'status\']') !== false, 'salary report distinguishes paid employees and exposes row payment state');

echo "PASS: payroll presentation contracts; no DB or HTTP operations were performed.\n";
