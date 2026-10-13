<?php

require_once __DIR__ . '/../includes/attendance_time.php';

date_default_timezone_set('Asia/Kolkata');

$assert = static function ($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};
$check = static function ($now, $in, $out, $early, $late, $context = null) {
    return attendance_checkin_eligibility($now, $in, $out, $early, $late, $context);
};

// Day shift: inclusive boundaries, with rejection immediately outside them.
$early_boundary = $check('2026-10-05 08:45:00', '09:00:00', '18:00:00', 15, 120);
$assert($early_boundary['eligible'] && $early_boundary['business_date'] === '2026-10-05', 'normal shift accepts the exact early boundary');
$assert($early_boundary['scheduled_start'] === '2026-10-05 09:00:00', 'normal shift returns its scheduled start');
$assert($early_boundary['scheduled_end'] === '2026-10-05 18:00:00', 'normal shift returns its scheduled end');
$assert($early_boundary['permitted_window'] === ['start' => '2026-10-05 08:45:00', 'end' => '2026-10-05 11:00:00'], 'normal shift returns inclusive permitted window');
$too_early = $check('2026-10-05 08:44:59', '09:00:00', '18:00:00', 15, 120);
$assert(!$too_early['eligible'] && $too_early['rejection_reason'] === 'before_early_checkin_window', 'normal shift rejects one second before early boundary');
$late_boundary = $check('2026-10-05 11:00:00', '09:00:00', '18:00:00', 15, 120);
$assert($late_boundary['eligible'], 'normal shift accepts the exact late cutoff boundary');
$persisted_policy = $check('2026-10-05 08:45:00', '09:00:00', '18:00:00', '15', '120');
$assert($persisted_policy['eligible'], 'canonical numeric-string policy values from MariaDB are accepted');
$too_late = $check('2026-10-05 11:00:01', '09:00:00', '18:00:00', 15, 120);
$assert(!$too_late['eligible'] && $too_late['rejection_reason'] === 'after_late_checkin_cutoff', 'normal shift rejects one second after late cutoff');

// Overnight business date remains the shift start date after midnight.
$overnight = $check('2026-10-03 21:45:00', '22:00:00', '06:00:00', 15, 120);
$assert($overnight['eligible'] && $overnight['business_date'] === '2026-10-03', 'overnight shift accepts early check-in on its start date');
$assert($overnight['scheduled_start'] === '2026-10-03 22:00:00' && $overnight['scheduled_end'] === '2026-10-04 06:00:00', 'overnight decision returns next-day scheduled end');
$overnight_cutoff = $check('2026-10-04 00:00:00', '22:00:00', '06:00:00', 15, 120);
$assert($overnight_cutoff['eligible'] && $overnight_cutoff['business_date'] === '2026-10-03', 'overnight shift accepts exactly at its post-midnight cutoff');
$overnight_late_threshold = attendance_shift_late_threshold('2026-10-03', '22:15:00');
$assert($overnight_late_threshold && $overnight_late_threshold->format('Y-m-d H:i:s') === '2026-10-03 22:15:00', 'late threshold snapshot uses the selected overnight business date');
$assert(attendance_shift_late_threshold('2026-10-03', '25:00:00') === false, 'malformed late threshold is rejected before snapshot');
$overnight_after_cutoff = $check('2026-10-04 00:00:01', '22:00:00', '06:00:00', 15, 120);
$assert(!$overnight_after_cutoff['eligible'] && $overnight_after_cutoff['rejection_reason'] === 'after_late_checkin_cutoff', 'overnight shift rejects after its post-midnight cutoff');

// A midnight-start shift can have an early window on the prior calendar date.
$midnight_start = $check('2026-10-03 23:45:00', '00:15:00', '08:15:00', 30, 120);
$assert($midnight_start['eligible'] && $midnight_start['business_date'] === '2026-10-04', 'midnight-start early window resolves to following business date');
$assert($midnight_start['scheduled_start'] === '2026-10-04 00:15:00', 'midnight-start schedule retains following business date');
$year_transition = $check('2026-12-31 23:45:00', '00:15:00', '08:15:00', 30, 120);
$assert($year_transition['eligible'] && $year_transition['business_date'] === '2027-01-01', 'candidate dates cross the calendar year safely');
$explicit_business_date = $check('2026-10-04 00:00:00', '22:00:00', '06:00:00', 15, 120, ['business_date' => '2026-10-03']);
$assert($explicit_business_date['eligible'] && $explicit_business_date['business_date'] === '2026-10-03', 'explicit business-date context is honored');
$explicit_timezone = $check(
    new DateTimeImmutable('2026-10-05 03:15:00', new DateTimeZone('UTC')),
    '09:00:00',
    '18:00:00',
    15,
    120,
    ['timezone' => 'Asia/Kolkata', 'business_date' => '2026-10-05']
);
$assert($explicit_timezone['eligible'] && $explicit_timezone['permitted_window']['start'] === '2026-10-05 08:45:00', 'DateTime input is evaluated in the explicit application timezone');

// Ambiguous/overlapping windows and malformed input must fail closed.
$overlap_policy = $check('2026-10-05 09:00:00', '09:00:00', '18:00:00', 720, 720);
$assert(!$overlap_policy['eligible'] && $overlap_policy['rejection_reason'] === 'overlapping_business_date_windows', 'daily policy windows that would overlap are rejected');
$ambiguous_context = $check('2026-10-05 09:00:00', '09:00:00', '18:00:00', 15, 120, ['candidate_business_dates' => ['2026-10-05', '2026-10-05']]);
$assert(!$ambiguous_context['eligible'] && $ambiguous_context['rejection_reason'] === 'ambiguous_business_date', 'multiple matching business-date candidates are rejected');
$bad_policy = $check('2026-10-05 09:00:00', '09:00:00', '18:00:00', -1, 120);
$assert(!$bad_policy['eligible'] && $bad_policy['rejection_reason'] === 'invalid_policy', 'negative policy values are rejected');
$bad_schedule = $check('2026-10-05 09:00:00', '25:00:00', '18:00:00', 15, 120);
$assert(!$bad_schedule['eligible'] && $bad_schedule['rejection_reason'] === 'invalid_schedule', 'malformed shift times are rejected');
$ambiguous_24h_shift = $check('2026-10-05 09:00:00', '09:00:00', '09:00:00', 15, 120);
$assert(!$ambiguous_24h_shift['eligible'] && $ambiguous_24h_shift['rejection_reason'] === 'ambiguous_shift_duration', 'equal shift start/end times are rejected as ambiguous');
$bad_timestamp = $check('2026-02-30 09:00:00', '09:00:00', '18:00:00', 15, 120);
$assert(!$bad_timestamp['eligible'] && $bad_timestamp['rejection_reason'] === 'invalid_timestamp', 'invalid calendar timestamp is rejected');
$bad_context = $check('2026-10-05 09:00:00', '09:00:00', '18:00:00', 15, 120, '2026-02-30');
$assert(!$bad_context['eligible'] && $bad_context['rejection_reason'] === 'invalid_business_date_context', 'invalid business date is rejected');

// Existing helpers remain behaviorally compatible; eligibility is separate
// from Sunday/holiday classification and no checkout rule is introduced here.
$legacy_schedule = attendance_shift_schedule('2026-10-03', '22:00:00', '06:00:00');
$assert($legacy_schedule && $legacy_schedule['end']->format('Y-m-d H:i:s') === '2026-10-04 06:00:00', 'existing shift schedule helper remains overnight-aware');
$assert(!attendance_is_late('2026-10-05', '2026-10-05 09:15:00', '09:15:00'), 'existing late threshold remains exclusive at equality');
$assert(attendance_is_late('2026-10-05', '2026-10-05 09:15:01', '09:15:00'), 'existing late threshold remains late after equality');
$assert(attendance_elapsed_seconds('2026-10-03 22:00:00', '2026-10-04 06:00:00') === 28800, 'existing elapsed-time helper remains overnight-correct');
$assert(attendance_status_after_checkout('Late', 5 * 3600) === 'Late Half Day', 'existing Late Half Day checkout behavior remains intact');
$assert(!array_key_exists('status', $overnight), 'check-in eligibility does not classify Sunday or holiday status');

echo "Database-free check-in eligibility tests completed.\n";
