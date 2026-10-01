<?php

require_once __DIR__ . '/auth.php';

auth_start_session();

$navigation_role = auth_current_role();
$navigation_active = isset($active_nav) && is_string($active_nav)
    ? $active_nav
    : '';

if ($navigation_role !== 'Admin' && $navigation_role !== 'Employee') {
    return;
}

$render_navigation_link = static function ($key, $module, $href, $icon, $label) use ($navigation_active) {
    $is_current_page = $navigation_active === $key;
    $classes = 'nav-link' . ($is_current_page ? ' active' : '');
    $current_attribute = $is_current_page ? ' aria-current="page"' : '';

    echo '<a class="' . $classes . '" href="' . $href . '"' . $current_attribute . '>';
    echo '<i class="' . $icon . '" aria-hidden="true"></i>';
    echo '<span>' . $label . '</span>';
    echo '</a>';
};

$render_navigation_section = static function ($key, $label, $items) use ($navigation_active, $render_navigation_link) {
    $is_active_module = $navigation_active === $key;

    echo '<div class="nav-section' . ($is_active_module ? ' is-active' : '') . '">';
    echo '<div class="nav-section-label">' . $label . '</div>';

    foreach ($items as $item) {
        $render_navigation_link(
            $item['key'],
            $key,
            $item['href'],
            $item['icon'],
            $item['label']
        );
    }

    echo '</div>';
};

$admin_sections = [
    'people' => [
        'People',
        [
            ['key' => 'employees', 'href' => 'employees.php', 'icon' => 'fa-solid fa-users', 'label' => 'Employees'],
            ['key' => 'add_employee', 'href' => 'add_employee.php', 'icon' => 'fa-solid fa-user-plus', 'label' => 'Add Employee'],
            ['key' => 'departments', 'href' => 'department_master.php', 'icon' => 'fa-solid fa-building', 'label' => 'Departments'],
        ],
    ],
    'time_leave' => [
        'Time & Leave',
        [
            ['key' => 'attendance', 'href' => 'attendance.php', 'icon' => 'fa-solid fa-calendar-check', 'label' => 'Attendance'],
            ['key' => 'manage_leave', 'href' => 'manage_leave.php', 'icon' => 'fa-solid fa-calendar-days', 'label' => 'Manage Leave'],
            ['key' => 'holidays', 'href' => 'holidays.php', 'icon' => 'fa-solid fa-umbrella-beach', 'label' => 'Holidays'],
        ],
    ],
    'shifts' => [
        'Shifts',
        [
            ['key' => 'manage_shift', 'href' => 'manage_shift.php', 'icon' => 'fa-solid fa-clock', 'label' => 'Manage Shifts'],
            ['key' => 'assign_shift', 'href' => 'assign_shift.php', 'icon' => 'fa-solid fa-user-clock', 'label' => 'Assign Shift'],
            ['key' => 'assigned_shift', 'href' => 'view_assigned_shift.php', 'icon' => 'fa-solid fa-eye', 'label' => 'View Assigned Shifts'],
        ],
    ],
    'payroll' => [
        'Payroll',
        [
            ['key' => 'salary', 'href' => 'salary.php', 'icon' => 'fa-solid fa-money-bill-wave', 'label' => 'Salary'],
        ],
    ],
    'reports' => [
        'Reports',
        [
            ['key' => 'attendance_report', 'href' => 'attendance_report.php', 'icon' => 'fa-solid fa-chart-column', 'label' => 'Attendance Report'],
            ['key' => 'salary_report', 'href' => 'salary_report.php', 'icon' => 'fa-solid fa-file-invoice-dollar', 'label' => 'Salary Report'],
        ],
    ],
];

$employee_sections = [
    'my_work' => [
        'My Work',
        [
            ['key' => 'attendance', 'href' => 'attendance.php', 'icon' => 'fa-solid fa-calendar-check', 'label' => 'Attendance'],
            ['key' => 'leave', 'href' => 'leave.php', 'icon' => 'fa-solid fa-calendar-days', 'label' => 'Leave'],
            ['key' => 'leave_history', 'href' => 'leave_history.php', 'icon' => 'fa-solid fa-clock-rotate-left', 'label' => 'Leave History'],
            ['key' => 'my_shift', 'href' => 'view_assigned_shift.php', 'icon' => 'fa-solid fa-clock', 'label' => 'My Shift'],
            ['key' => 'holidays', 'href' => 'holidays.php', 'icon' => 'fa-solid fa-umbrella-beach', 'label' => 'Holidays'],
        ],
    ],
    'compensation' => [
        'Compensation',
        [
            ['key' => 'salary', 'href' => 'salary.php', 'icon' => 'fa-solid fa-money-bill-wave', 'label' => 'Salary'],
        ],
    ],
];

$account_section = [
    'Account',
    [
        ['key' => 'notifications', 'href' => 'notifications.php', 'icon' => 'fa-solid fa-bell', 'label' => 'Notifications'],
        ['key' => 'profile', 'href' => 'edit_profile.php', 'icon' => 'fa-solid fa-user-pen', 'label' => 'Edit Profile'],
    ],
];

$sections = $navigation_role === 'Admin' ? $admin_sections : $employee_sections;
?>

<aside class="sidebar app-sidebar" aria-label="Primary navigation">
    <a class="nav-brand" href="dashboard.php" aria-label="CoreAxis CRM dashboard">
        CoreAxis CRM
    </a>

    <nav class="sidebar-nav" aria-label="Application navigation">
        <?php $render_navigation_link('dashboard', 'dashboard', 'dashboard.php', 'fa-solid fa-house', 'Dashboard'); ?>

        <?php foreach ($sections as $key => [$label, $items]) { ?>
            <?php $render_navigation_section($key, $label, $items); ?>
        <?php } ?>

        <?php $render_navigation_section('account', $account_section[0], $account_section[1]); ?>
    </nav>

    <div class="sidebar-footer">
        <?php $render_navigation_link('logout', 'logout', 'logout.php', 'fa-solid fa-right-from-bracket', 'Logout'); ?>
    </div>
</aside>
