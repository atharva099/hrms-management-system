<?php

declare(strict_types=1);

function uiReleaseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$assignedShifts = file_get_contents($root . '/view_assigned_shift.php');
$navigation = file_get_contents($root . '/includes/navigation.php');
$salarySlip = file_get_contents($root . '/salary_slip.php');
$styles = file_get_contents($root . '/css/style.css');

uiReleaseAssert(
    is_string($assignedShifts)
        && preg_match('/href="css\/style\.css(?:\?[^\"]*)?"/', $assignedShifts) === 1
        && strpos($assignedShifts, "include __DIR__ . '/includes/navigation.php'") !== false,
    'assigned-shift page uses the shared stylesheet and role-aware navigation'
);
uiReleaseAssert(
    is_string($assignedShifts)
        && strpos($assignedShifts, "? 'assigned_shift' : 'my_shift'") !== false
        && strpos($assignedShifts, 'class="table-container"') !== false
        && strpos($assignedShifts, 'class="attendance-table"') !== false,
    'assigned-shift page selects the active role menu and keeps the wide table scrollable'
);
uiReleaseAssert(
    is_string($navigation)
        && strpos($navigation, "\$navigation_role === 'Admin' ? \$admin_sections : \$employee_sections") !== false,
    'shared navigation keeps Admin and Employee menus separated'
);
uiReleaseAssert(
    is_string($salarySlip)
        && strpos($salarySlip, 'src="images/logo.png"') !== false
        && strpos($salarySlip, 'alt="CoreAxis CRM logo"') !== false
        && is_file($root . '/images/logo.png'),
    'salary slip uses the existing local logo with alternative text'
);
uiReleaseAssert(
    is_string($styles)
        && strpos($styles, '@media (max-width: 600px)') !== false
        && strpos($styles, '.salary-slip-card {') !== false
        && strpos($styles, 'box-sizing: border-box;') !== false,
    'salary slip layout is constrained on narrow phone viewports'
);
uiReleaseAssert(
    is_string($styles)
        && preg_match('/\.app-sidebar\s*\{[^}]*flex:\s*0\s+0\s+260px\s*;/s', $styles) === 1
        && preg_match('/@media\s*\(max-width:\s*768px\)[^{]*\{.*?\.sidebar\s*\{[^}]*position:\s*static\s*;[^}]*overflow:\s*visible\s*;[^}]*flex-basis:\s*auto\s*;/s', $styles) === 1
        && preg_match('/\.content\s*\{[^}]*min-width:\s*0\s*;[^}]*width:\s*auto\s*;[^}]*box-sizing:\s*border-box\s*;/s', $styles) === 1,
    'shared shell keeps the sidebar fixed, prevents content overflow, and returns to full-width layout on mobile'
);

echo "PASS: UI release contracts; no database or HTTP operations were performed.\n";
