-- Test-only expected schema contract; no employee data or setup statements.
CREATE TABLE holidays (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  holiday_name VARCHAR(100) NOT NULL,
  holiday_date DATE NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_holidays_date (holiday_date)
) ENGINE=InnoDB;