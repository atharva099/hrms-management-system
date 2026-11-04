<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/payroll_period.php';

function payrollPeriodAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

payrollPeriodAssert(
    coreaxis_payroll_period_from_label('May 2026') === ['label' => 'May 2026', 'date' => '2026-05-01'],
    'legacy Month YYYY maps to first-of-month canonical DATE'
);
payrollPeriodAssert(
    coreaxis_payroll_period_from_label('February 2000')['date'] === '2000-02-01',
    'leap-year February maps to the canonical first day'
);
foreach (['May 0999', 'may 2026', 'May 2026 ', '2026-05', ['May', '2026']] as $bad_label) {
    payrollPeriodAssert(coreaxis_payroll_period_from_label($bad_label) === false, 'malformed payroll period labels are rejected');
}

payrollPeriodAssert(coreaxis_payroll_amount_to_cents('500000.00') === 50000000, 'DECIMAL salary is converted exactly to cents');
payrollPeriodAssert(coreaxis_payroll_amount_to_cents('0.5') === 50, 'one fractional decimal digit is padded exactly');
payrollPeriodAssert(coreaxis_payroll_amount_to_cents('99999999.99') === 9999999999, 'maximum DECIMAL(10,2) is represented without float conversion');
foreach (['-1.00', '1.001', '1e3', '100000000.00', '', 1.25, null] as $bad_amount) {
    payrollPeriodAssert(coreaxis_payroll_amount_to_cents($bad_amount) === false, 'invalid or inexact payroll amounts are rejected');
}
payrollPeriodAssert(coreaxis_payroll_cents_to_decimal(123456) === '1234.56', 'integer cents format to a DECIMAL string');
payrollPeriodAssert(coreaxis_payroll_cents_to_decimal(-5) === '-0.05', 'negative net pay retains exact cents');
payrollPeriodAssert(coreaxis_payroll_signed_amount_to_cents('-123.45') === -12345, 'signed DECIMAL adjustments convert exactly without floats');
payrollPeriodAssert(coreaxis_payroll_signed_amount_to_cents('0.00') === 0, 'zero signed adjustment remains exact');
foreach (['+1.00', '--1.00', '-1.001', '-100000000.00', '-1e2', 1.25, null] as $bad_signed_amount) {
    payrollPeriodAssert(coreaxis_payroll_signed_amount_to_cents($bad_signed_amount) === false, 'invalid signed adjustments are rejected');
}

$root = dirname(__DIR__);
$salary = file_get_contents($root . '/salary.php');
$report = file_get_contents($root . '/salary_report.php');
$slip = file_get_contents($root . '/salary_slip.php');
$salary = str_replace(["\r\n", "\r"], "\n", $salary);
$generate_start = strpos($salary, "if (isset(\$_POST['generate_salary'])) {");
$generate_end = strpos($salary, "\n?>", $generate_start === false ? 0 : $generate_start);
$generate = $generate_start !== false && $generate_end !== false
    ? substr($salary, $generate_start, $generate_end - $generate_start)
    : '';
payrollPeriodAssert($generate !== '', 'generation action block boundaries exist');
payrollPeriodAssert(
    strpos($generate, "\$_POST['basic_salary']") === false
        && strpos($generate, "\$_POST['incentive']") === false,
    'generation continues to ignore posted salary values'
);
payrollPeriodAssert(
    strpos($generate, '(salary_period=? OR salary_month=?)') !== false
        && strpos($generate, 'salary_period,') !== false,
    'duplicate lookup covers canonical date and legacy label, and inserts both'
);
payrollPeriodAssert(
    strpos($generate, "'sssssssss'") !== false
        && strpos($generate, 'coreaxis_payroll_cents_to_decimal') !== false,
    'payroll amounts are bound as exact decimal strings derived with integer cents'
);
payrollPeriodAssert(
    substr_count($generate, 'attendance_date >= ? AND attendance_date < ?') === 3,
    'attendance deductions use a canonical half-open calendar-month range'
);
payrollPeriodAssert(
    strpos($report, 'salary_period') !== false && strpos($slip, 'salaries.salary_period') !== false,
    'salary report and payslip read the same canonical date column'
);

echo "PASS: portable payroll period contract completed; migration/DB integration checks are classified separately.\n";
