<?php

declare(strict_types=1);

/**
 * Format the legacy salary payment summary without inferring a payment date.
 * A date is shown only when it is actually stored on the salary record.
 */
function coreaxis_payroll_payment_presentation($status, $paymentDate): array
{
    $statusLabel = in_array($status, ['Paid', 'Pending'], true) ? $status : 'Status unavailable';
    $dateLabel = 'Not recorded';

    if ($statusLabel === 'Paid'
        && is_string($paymentDate)
        && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $paymentDate) === 1) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $paymentDate) {
            $dateLabel = $date->format('d M Y');
        }
    }

    return ['status' => $statusLabel, 'date' => $dateLabel];
}
