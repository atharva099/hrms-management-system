<?php
/** Pure, database-free target validation for the row-version integration runner. */

function attendance_row_version_protected_databases()
{
    return [
        'coreaxis_crm',
        'coreaxis_crm_runtime_test',
        'coreaxis_crm_overnight_workflow_test',
        'coreaxis_r5_overnight_forensic_baseline_20261011',
        'coreaxis_r5_attendance_overnight_rerun_20261012_7f3a91c2',
        'coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c'
    ];
}

function attendance_row_version_disposable_database_is_allowed($candidate)
{
    return is_string($candidate)
        && preg_match('/\\Acoreaxis_r5_attendance_[a-z0-9_]{8,64}\\z/D', $candidate) === 1
        && !in_array($candidate, attendance_row_version_protected_databases(), true);
}
