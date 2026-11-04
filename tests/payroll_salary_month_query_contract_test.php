<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$salaryPage = file_get_contents($root . '/salary.php');


function payrollMonthQueryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$queryStart = strpos($salaryPage, 'SELECT salary_month,');
$queryEnd = $queryStart === false ? false : strpos($salaryPage, 'ORDER BY salary_month_order DESC, salary_month DESC', $queryStart);
$pageQuery = $queryStart === false || $queryEnd === false ? '' : substr($salaryPage, $queryStart, $queryEnd - $queryStart + strlen('ORDER BY salary_month_order DESC, salary_month DESC'));

payrollMonthQueryAssert(
    strpos($pageQuery, 'GROUP BY salary_month') !== false
        && strpos($pageQuery, 'MAX(COALESCE(') !== false
        && strpos($pageQuery, 'salary_period') !== false
        && strpos($pageQuery, 'DISTINCT') === false
        && strpos($pageQuery, 'ORDER BY id') === false,
    'salary period list uses MySQL 8 compatible grouped ordering and canonical period fallback'
);

payrollMonthQueryAssert(
    strpos($salaryPage, 'if ($month_query instanceof mysqli_result)') !== false
        && strpos($salaryPage, 'if ($month_result instanceof mysqli_result)') !== false
        && strpos($salaryPage, 'Payroll months are temporarily unavailable.') !== false,
    'salary month selectors guard failed query results and show a safe fallback'
);

$filterStart = strpos($salaryPage, '$month_filter_sql =');
$filterEnd = $filterStart === false ? false : strpos($salaryPage, 'foreach ($month_filter_options as $month_row)', $filterStart);
$filterQuery = $filterStart === false || $filterEnd === false
    ? ''
    : substr($salaryPage, $filterStart, $filterEnd - $filterStart);
payrollMonthQueryAssert(
    strpos($filterQuery, 'GROUP BY salary_month') !== false
        && strpos($filterQuery, 'MAX(COALESCE(') !== false
        && strpos($filterQuery, 'SELECT DISTINCT salary_month') === false
        && strpos($salaryPage, 'mysqli_fetch_all($month_result, MYSQLI_ASSOC)') !== false,
    'salary record month filter uses MySQL 8 compatible grouping and guards failed query results'
);

payrollMonthQueryAssert(
    strpos($salaryPage, '$payroll_actions_enabled = false;') !== false
        && strpos($salaryPage, 'http_response_code(503);') !== false,
    'salary mutation gate remains disabled and returns service unavailable'
);

echo "PASS: salary month query contracts; no database or HTTP operations were performed.\n";
