-- Phase 12: auditability and data-integrity remediation.
-- Apply after Phases 1-11 on a backup/test database first.

CREATE TABLE IF NOT EXISTS legacy_import_validation_attempts (
    validation_attempt_id INT NOT NULL AUTO_INCREMENT,
    import_batch_id INT NOT NULL,
    attempt_number INT UNSIGNED NOT NULL,
    validation_status ENUM('RUNNING','COMPLETED','FAILED') NOT NULL DEFAULT 'RUNNING',
    validated_by INT NOT NULL,
    source_file_hash CHAR(64) NULL,
    detected_count INT UNSIGNED NOT NULL DEFAULT 0,
    valid_count INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    failure_reason VARCHAR(1000) NULL,
    PRIMARY KEY (validation_attempt_id),
    UNIQUE KEY uk_legacy_validation_attempt_number (import_batch_id, attempt_number),
    KEY idx_legacy_validation_attempt_batch (import_batch_id, started_at),
    CONSTRAINT fk_legacy_validation_attempt_batch
        FOREIGN KEY (import_batch_id) REFERENCES legacy_import_batches(import_batch_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_validation_attempt_actor
        FOREIGN KEY (validated_by) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_import_validation_rows (
    validation_row_id BIGINT NOT NULL AUTO_INCREMENT,
    validation_attempt_id INT NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    source_data JSON NULL,
    normalized_name VARCHAR(255) NULL,
    normalized_pf_number VARCHAR(50) NULL,
    normalized_national_id VARCHAR(50) NULL,
    source_reference VARCHAR(150) NULL,
    amount DECIMAL(19,4) NULL,
    validation_state ENUM('VALID','INVALID','DUPLICATE','POSSIBLE_DUPLICATE','IMPORTED','SKIPPED') NOT NULL,
    error_message VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (validation_row_id),
    UNIQUE KEY uk_legacy_validation_row_attempt (validation_attempt_id, source_row_number),
    KEY idx_legacy_validation_row_identity (normalized_pf_number, normalized_national_id),
    CONSTRAINT fk_legacy_validation_row_attempt
        FOREIGN KEY (validation_attempt_id) REFERENCES legacy_import_validation_attempts(validation_attempt_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_legacy_validation_row_amount CHECK (amount IS NULL OR amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_duplicate_resolutions (
    duplicate_resolution_id BIGINT NOT NULL AUTO_INCREMENT,
    import_record_id INT NOT NULL,
    candidate_record_id INT NULL,
    resolution ENUM('SAME_PERSON','DIFFERENT_PERSON','UNRESOLVED') NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    resolved_by INT NOT NULL,
    resolved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (duplicate_resolution_id),
    KEY idx_legacy_duplicate_resolution_record (import_record_id, resolved_at),
    CONSTRAINT fk_legacy_duplicate_resolution_record
        FOREIGN KEY (import_record_id) REFERENCES legacy_import_records(import_record_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_duplicate_resolution_candidate
        FOREIGN KEY (candidate_record_id) REFERENCES legacy_import_records(import_record_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_duplicate_resolution_actor
        FOREIGN KEY (resolved_by) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_dispute_events (
    dispute_event_id BIGINT NOT NULL AUTO_INCREMENT,
    legacy_account_id INT NOT NULL,
    old_status ENUM('NONE','OPEN','RESOLVED','REJECTED') NULL,
    new_status ENUM('NONE','OPEN','RESOLVED','REJECTED') NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    resolution VARCHAR(1000) NULL,
    financial_impact DECIMAL(19,4) NULL,
    actor_user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (dispute_event_id),
    KEY idx_legacy_dispute_account (legacy_account_id, created_at),
    CONSTRAINT fk_legacy_dispute_event_account
        FOREIGN KEY (legacy_account_id) REFERENCES legacy_debt_accounts(legacy_account_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_dispute_event_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO legacy_permissions (permission_key, description)
VALUES ('legacy.dispute.manage', 'Record and resolve auditable legacy debt disputes')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO legacy_role_permissions (role, permission_id, allowed)
SELECT r.role, p.permission_id, 1
  FROM (SELECT 'admin' AS role UNION ALL SELECT 'accountant' UNION ALL SELECT 'cashier') r
  JOIN legacy_permissions p ON p.permission_key = 'legacy.dispute.manage'
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

-- Make the financial-year context explicit on structured audit events.
SET @p12_audit_year_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'legacy_audit_events'
       AND column_name = 'financial_year_id'
);
SET @p12_audit_year_sql = IF(
    @p12_audit_year_exists = 0,
    'ALTER TABLE legacy_audit_events ADD COLUMN financial_year_id INT NULL AFTER actor_user_id',
    'SELECT 1'
);
PREPARE p12_audit_year_stmt FROM @p12_audit_year_sql;
EXECUTE p12_audit_year_stmt;
DEALLOCATE PREPARE p12_audit_year_stmt;

SET @p12_audit_year_fk_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'legacy_audit_events'
       AND constraint_name = 'fk_legacy_audit_financial_year'
);
SET @p12_audit_year_fk_sql = IF(
    @p12_audit_year_fk_exists = 0,
    'ALTER TABLE legacy_audit_events ADD CONSTRAINT fk_legacy_audit_financial_year FOREIGN KEY (financial_year_id) REFERENCES legacy_financial_years(financial_year_id) ON DELETE RESTRICT ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE p12_audit_year_fk_stmt FROM @p12_audit_year_fk_sql;
EXECUTE p12_audit_year_fk_stmt;
DEALLOCATE PREPARE p12_audit_year_fk_stmt;

DELIMITER $$

DROP TRIGGER IF EXISTS legacy_import_records_before_delete$$
CREATE TRIGGER legacy_import_records_before_delete
BEFORE DELETE ON legacy_import_records
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Legacy import records are historical and cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_validation_attempts_before_update$$
CREATE TRIGGER legacy_validation_attempts_before_update
BEFORE UPDATE ON legacy_import_validation_attempts
FOR EACH ROW
BEGIN
    IF NOT (NEW.import_batch_id <=> OLD.import_batch_id)
       OR NOT (NEW.attempt_number <=> OLD.attempt_number)
       OR NOT (NEW.validated_by <=> OLD.validated_by)
       OR NOT (NEW.source_file_hash <=> OLD.source_file_hash)
       OR NOT (NEW.started_at <=> OLD.started_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Validation attempt identity is immutable';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_validation_attempts_before_delete$$
CREATE TRIGGER legacy_validation_attempts_before_delete
BEFORE DELETE ON legacy_import_validation_attempts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Validation history cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_validation_rows_before_update$$
CREATE TRIGGER legacy_validation_rows_before_update
BEFORE UPDATE ON legacy_import_validation_rows
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Validation result history is immutable';
END$$

DROP TRIGGER IF EXISTS legacy_validation_rows_before_delete$$
CREATE TRIGGER legacy_validation_rows_before_delete
BEFORE DELETE ON legacy_import_validation_rows
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Validation result history cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_duplicate_resolutions_before_update$$
CREATE TRIGGER legacy_duplicate_resolutions_before_update
BEFORE UPDATE ON legacy_duplicate_resolutions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Duplicate-resolution decisions are immutable; create a new decision';
END$$

DROP TRIGGER IF EXISTS legacy_duplicate_resolutions_before_delete$$
CREATE TRIGGER legacy_duplicate_resolutions_before_delete
BEFORE DELETE ON legacy_duplicate_resolutions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Duplicate-resolution decisions cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_dispute_events_before_update$$
CREATE TRIGGER legacy_dispute_events_before_update
BEFORE UPDATE ON legacy_dispute_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dispute history is immutable';
END$$

DROP TRIGGER IF EXISTS legacy_dispute_events_before_delete$$
CREATE TRIGGER legacy_dispute_events_before_delete
BEFORE DELETE ON legacy_dispute_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dispute history cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_action_requests_before_update$$
CREATE TRIGGER legacy_action_requests_before_update
BEFORE UPDATE ON legacy_action_requests
FOR EACH ROW
BEGIN
    IF NEW.approved_by IS NOT NULL AND NEW.approved_by = NEW.requested_by THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A legacy action requester cannot approve their own request';
    END IF;
    IF NEW.secondary_approved_by IS NOT NULL
       AND NEW.secondary_approved_by = NEW.requested_by THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A legacy action requester cannot approve their own request';
    END IF;
    IF OLD.approved_by IS NOT NULL
       AND (NOT (NEW.approved_by <=> OLD.approved_by)
            OR NOT (NEW.approved_at <=> OLD.approved_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Approval identity and timestamp cannot be reused or changed';
    END IF;
    IF OLD.status IN ('EXECUTED','CANCELLED','REJECTED') AND NEW.status <> OLD.status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Completed action requests cannot change state';
    END IF;
END$$

DELIMITER ;
