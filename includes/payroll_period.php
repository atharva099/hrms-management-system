<?php

/**
 * Payroll periods use the first day of the month as the canonical DATE value.
 * The legacy Month YYYY label remains available for existing screens and
 * integrations while the schema transition is completed.
 */
function coreaxis_payroll_period_from_label($label)
{
    if (!is_string($label)
        || preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December) ([0-9]{4})$/D', $label, $matches) !== 1) {
        return false;
    }

    $months = [
        'January' => '01', 'February' => '02', 'March' => '03', 'April' => '04',
        'May' => '05', 'June' => '06', 'July' => '07', 'August' => '08',
        'September' => '09', 'October' => '10', 'November' => '11', 'December' => '12',
    ];
    $year = (int) $matches[2];
    if ($year < 1000) {
        return false;
    }

    return [
        'label' => $label,
        'date' => sprintf('%04d-%s-01', $year, $months[$matches[1]]),
    ];
}

/** Convert a DECIMAL(10,2) database value to integer minor units without floats. */
function coreaxis_payroll_amount_to_cents($amount)
{
    if (!is_string($amount) && !is_int($amount)) {
        return false;
    }

    $amount = (string) $amount;
    if (preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', $amount, $matches) !== 1) {
        return false;
    }

    $whole = (int) $matches[1];
    $fraction = str_pad(isset($matches[2]) ? $matches[2] : '', 2, '0');
    return ($whole * 100) + (int) $fraction;
}

/** Convert a signed DECIMAL string to cents without floating-point arithmetic. */
function coreaxis_payroll_signed_amount_to_cents($amount)
{
    if (!is_string($amount) && !is_int($amount)) {
        return false;
    }
    $amount = (string) $amount;
    if (preg_match('/^(-?)([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', $amount, $matches) !== 1) {
        return false;
    }
    $whole = (int) $matches[2];
    $fraction = str_pad(isset($matches[3]) ? $matches[3] : '', 2, '0');
    $cents = ($whole * 100) + (int) $fraction;
    return $matches[1] === '-' ? -$cents : $cents;
}

/** Format integer minor units for a DECIMAL column without floating-point math. */
function coreaxis_payroll_cents_to_decimal($cents)
{
    if (!is_int($cents)) {
        throw new InvalidArgumentException('Payroll amount must be integer minor units.');
    }

    $negative = $cents < 0;
    $absolute = $negative ? -$cents : $cents;
    return ($negative ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}
