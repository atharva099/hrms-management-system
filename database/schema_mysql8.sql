-- CoreAxis CRM college-core schema; MySQL 8.0.16 or newer.
-- Schema only: no database selection/creation, credentials, user rows, or fixture data.
-- Operator must select a new, empty target schema before running this file.
-- Uses the verified R5 synthetic MySQL 8 schema DDL; import itself was not runtime-tested in this publication task.
-- Creates 9 InnoDB tables, 13 foreign keys, indexes/checks, and 4 append-only audit triggers.

CREATE TABLE departments (
    id INT NOT NULL AUTO_INCREMENT,
    department_name VARCHAR(100) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_name (department_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE shifts (
    id INT NOT NULL AUTO_INCREMENT,
    shift_name VARCHAR(100) NOT NULL,
    in_time TIME NOT NULL,
    out_time TIME NOT NULL,
    late_after TIME NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    early_checkin_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    late_checkin_cutoff_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shifts_name (shift_name),
    CONSTRAINT chk_shifts_early_checkin_range CHECK (early_checkin_minutes <= 1439),
    CONSTRAINT chk_shifts_late_cutoff_range CHECK (late_checkin_cutoff_minutes <= 1439),
    CONSTRAINT chk_shifts_checkin_windows_do_not_overlap CHECK (early_checkin_minutes + late_checkin_cutoff_minutes < 1440)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE users (
    id INT NOT NULL AUTO_INCREMENT,
    employee_id VARCHAR(20) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    department VARCHAR(50) NOT NULL,
    designation VARCHAR(50) NOT NULL,
    shift_time TIME NULL,
    salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    incentive DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    joining_date DATE NOT NULL,
    role VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    email VARCHAR(100) NOT NULL,
    shift_id INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_employee_id (employee_id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_shift_id (shift_id),
    CONSTRAINT fk_users_shift_id_shifts FOREIGN KEY (shift_id) REFERENCES shifts (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_users_role CHECK (role IN ('Admin','Employee')),
    CONSTRAINT chk_users_status CHECK (status IN ('Active','Inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE attendance (
    id INT NOT NULL AUTO_INCREMENT,
    employee_id VARCHAR(20) NOT NULL,
    attendance_date DATE NOT NULL,
    check_in TIME NULL,
    check_in_at DATETIME NULL,
    check_out TIME NULL,
    check_out_at DATETIME NULL,
    scheduled_start_at DATETIME NULL,
    scheduled_end_at DATETIME NULL,
    late_after_at DATETIME NULL,
    status VARCHAR(50) NULL,
    working_hours VARCHAR(20) NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    shift_id_snapshot INT NULL DEFAULT NULL,
    shift_name_snapshot VARCHAR(100) NULL DEFAULT NULL,
    early_checkin_minutes_snapshot SMALLINT UNSIGNED NULL DEFAULT NULL,
    late_checkin_cutoff_minutes_snapshot SMALLINT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_employee_date (employee_id, attendance_date),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES users (employee_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_attendance_row_version CHECK (row_version >= 1),
    CONSTRAINT chk_attendance_policy_snapshot_pair_window CHECK (
        (early_checkin_minutes_snapshot IS NULL AND late_checkin_cutoff_minutes_snapshot IS NULL)
        OR (early_checkin_minutes_snapshot IS NOT NULL AND late_checkin_cutoff_minutes_snapshot IS NOT NULL
            AND early_checkin_minutes_snapshot <= 1439 AND late_checkin_cutoff_minutes_snapshot <= 1439
            AND early_checkin_minutes_snapshot + late_checkin_cutoff_minutes_snapshot < 1440)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE holidays (
    id INT NOT NULL AUTO_INCREMENT,
    holiday_name VARCHAR(100) NOT NULL,
    holiday_date DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_holidays_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE leaves (
    id INT NOT NULL AUTO_INCREMENT,
    employee_id VARCHAR(20) NOT NULL,
    leave_type VARCHAR(100) NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    applied_on TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    employee_notification_status VARCHAR(20) NOT NULL DEFAULT 'Unread',
    PRIMARY KEY (id),
    KEY idx_leaves_employee_id (employee_id),
    KEY idx_leaves_employee_dates_status (employee_id, from_date, to_date, status),
    CONSTRAINT fk_leaves_employee FOREIGN KEY (employee_id) REFERENCES users (employee_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_leaves_date_order CHECK (to_date >= from_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE salaries (
    id INT NOT NULL AUTO_INCREMENT,
    employee_id VARCHAR(20) NOT NULL,
    salary_month VARCHAR(20) NOT NULL,
    salary_period DATE NOT NULL,
    basic_salary DECIMAL(10,2) NOT NULL,
    incentive DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    late_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    halfday_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    absent_deduction DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    final_salary DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payment_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    payment_date DATE NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_salaries_employee_period (employee_id, salary_period),
    KEY idx_salaries_employee_id (employee_id),
    CONSTRAINT fk_salaries_employee FOREIGN KEY (employee_id) REFERENCES users (employee_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_salaries_period_contract CHECK (
        employee_id <> '' AND DAYOFMONTH(salary_period) = 1
        AND BINARY salary_month = BINARY CONCAT(
            CASE MONTH(salary_period)
                WHEN 1 THEN 'January' WHEN 2 THEN 'February' WHEN 3 THEN 'March'
                WHEN 4 THEN 'April' WHEN 5 THEN 'May' WHEN 6 THEN 'June'
                WHEN 7 THEN 'July' WHEN 8 THEN 'August' WHEN 9 THEN 'September'
                WHEN 10 THEN 'October' WHEN 11 THEN 'November' WHEN 12 THEN 'December'
            END, ' ', YEAR(salary_period)
        )
    ),
    CONSTRAINT chk_salaries_payment_status CHECK (payment_status IN ('Pending','Paid'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE leave_attendance_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    correlation_id CHAR(36) NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    leave_id INT NOT NULL,
    employee_id VARCHAR(20) NOT NULL,
    attendance_id INT NULL,
    conflicting_leave_id INT NULL,
    attendance_date DATE NOT NULL,
    conflict_type VARCHAR(40) NOT NULL,
    resolution_decision VARCHAR(40) NOT NULL,
    previous_leave_status VARCHAR(50) NULL,
    new_leave_status VARCHAR(50) NULL,
    previous_attendance_status VARCHAR(50) NULL,
    new_attendance_status VARCHAR(50) NULL,
    previous_check_in TIME NULL,
    previous_check_out TIME NULL,
    previous_check_in_at DATETIME NULL,
    previous_check_out_at DATETIME NULL,
    previous_scheduled_end_at DATETIME NULL,
    previous_working_hours VARCHAR(20) NULL,
    actor_user_id INT NOT NULL,
    actor_employee_id VARCHAR(20) NOT NULL,
    reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_laa_leave_attendance (leave_id, attendance_id),
    KEY idx_laa_employee_date (employee_id, attendance_date),
    KEY idx_laa_conflicting_leave (conflicting_leave_id),
    KEY idx_laa_actor_created (actor_user_id, created_at),
    KEY idx_laa_correlation (correlation_id),
    CONSTRAINT fk_laa_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_laa_attendance FOREIGN KEY (attendance_id) REFERENCES attendance (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_laa_conflicting_leave FOREIGN KEY (conflicting_leave_id) REFERENCES leaves (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_laa_employee FOREIGN KEY (employee_id) REFERENCES users (employee_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_laa_leave FOREIGN KEY (leave_id) REFERENCES leaves (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE attendance_correction_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    correlation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type ENUM('APPLIED','REVERSAL') NOT NULL,
    correction_type ENUM('MISSING_CHECKOUT','INCORRECT_PUNCH','INCORRECT_STATUS','ABSENCE_CORRECTION','ADMIN_REVERSAL') NOT NULL,
    recorded_at DATETIME NOT NULL,
    attendance_id INT NOT NULL,
    employee_user_id INT NOT NULL,
    actor_user_id INT NOT NULL,
    employee_id_snapshot VARCHAR(20) NOT NULL,
    actor_employee_id_snapshot VARCHAR(20) NOT NULL,
    actor_role_snapshot VARCHAR(20) NOT NULL,
    reason TEXT NOT NULL,
    evidence_type VARCHAR(40) NOT NULL,
    evidence_reference VARCHAR(255) NULL,
    before_row_version INT UNSIGNED NOT NULL,
    after_row_version INT UNSIGNED NOT NULL,
    before_employee_id VARCHAR(20) NOT NULL,
    after_employee_id VARCHAR(20) NOT NULL,
    before_attendance_date DATE NOT NULL,
    after_attendance_date DATE NOT NULL,
    before_check_in TIME NULL,
    after_check_in TIME NULL,
    before_check_out TIME NULL,
    after_check_out TIME NULL,
    before_check_in_at DATETIME NULL,
    after_check_in_at DATETIME NULL,
    before_check_out_at DATETIME NULL,
    after_check_out_at DATETIME NULL,
    before_scheduled_start_at DATETIME NULL,
    after_scheduled_start_at DATETIME NULL,
    before_scheduled_end_at DATETIME NULL,
    after_scheduled_end_at DATETIME NULL,
    before_late_after_at DATETIME NULL,
    after_late_after_at DATETIME NULL,
    before_shift_id_snapshot INT NULL,
    after_shift_id_snapshot INT NULL,
    before_shift_name_snapshot VARCHAR(100) NULL,
    after_shift_name_snapshot VARCHAR(100) NULL,
    before_status VARCHAR(50) NULL,
    after_status VARCHAR(50) NULL,
    before_working_hours VARCHAR(20) NULL,
    after_working_hours VARCHAR(20) NULL,
    payroll_impact ENUM('NO_PAYROLL_IMPACT','UNGENERATED_PERIOD','PENDING_RECONCILED','PAID_RECONCILED') NOT NULL,
    salary_id INT NULL,
    salary_period DATE NULL,
    salary_status_snapshot VARCHAR(20) NULL,
    payroll_reconciliation_ref VARCHAR(64) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aca_event_uuid (event_uuid),
    UNIQUE KEY uq_aca_attendance_after_version (attendance_id, after_row_version),
    KEY idx_aca_correlation_id (correlation_id),
    KEY idx_aca_employee_date (employee_user_id, before_attendance_date, id),
    KEY idx_aca_actor_recorded (actor_user_id, recorded_at),
    KEY idx_aca_salary_period (salary_id, salary_period),
    CONSTRAINT chk_aca_version_increases CHECK (after_row_version = before_row_version + 1),
    CONSTRAINT chk_aca_reason_nonblank_length CHECK (CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000),
    CONSTRAINT chk_aca_evidence_type_nonblank CHECK (CHAR_LENGTH(TRIM(evidence_type)) BETWEEN 1 AND 40),
    CONSTRAINT chk_aca_evidence_reference_nonblank CHECK (evidence_reference IS NULL OR CHAR_LENGTH(TRIM(evidence_reference)) BETWEEN 1 AND 255),
    CONSTRAINT chk_aca_payroll_reconciliation_ref CHECK (
        (payroll_impact IN ('PENDING_RECONCILED','PAID_RECONCILED') AND payroll_reconciliation_ref IS NOT NULL AND CHAR_LENGTH(TRIM(payroll_reconciliation_ref)) BETWEEN 1 AND 64)
        OR (payroll_impact IN ('NO_PAYROLL_IMPACT','UNGENERATED_PERIOD') AND payroll_reconciliation_ref IS NULL)
    ),
    CONSTRAINT fk_aca_attendance FOREIGN KEY (attendance_id) REFERENCES attendance (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_aca_employee_user FOREIGN KEY (employee_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_aca_actor_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_aca_salary FOREIGN KEY (salary_id) REFERENCES salaries (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DELIMITER //
CREATE TRIGGER leave_attendance_audit_no_update BEFORE UPDATE ON leave_attendance_audit
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave attendance audit is append-only';
END//

CREATE TRIGGER leave_attendance_audit_no_delete BEFORE DELETE ON leave_attendance_audit
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Leave attendance audit is append-only';
END//

CREATE TRIGGER trg_aca_no_update BEFORE UPDATE ON attendance_correction_audit
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance correction audit is append-only';
END//

CREATE TRIGGER trg_aca_no_delete BEFORE DELETE ON attendance_correction_audit
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attendance correction audit is append-only';
END//

DELIMITER ;
