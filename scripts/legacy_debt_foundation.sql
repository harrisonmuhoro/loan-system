-- Legacy Debtors Phase 1 database foundation
-- Requires the base schema to be installed first.
-- Target: MySQL 8.4+ (also uses broadly compatible InnoDB features).
-- This migration creates structure only. It does not migrate outstanding_debtors data.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_financial_years (
    financial_year_id INT NOT NULL AUTO_INCREMENT,
    label VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
    post_close_transactions_allowed TINYINT(1) NOT NULL DEFAULT 0,
    opening_notes VARCHAR(500) NULL,
    closing_notes VARCHAR(500) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_by INT NULL,
    closed_at DATETIME NULL,
    PRIMARY KEY (financial_year_id),
    UNIQUE KEY uk_legacy_financial_year_label (label),
    UNIQUE KEY uk_legacy_financial_year_dates (start_date, end_date),
    KEY idx_legacy_financial_year_status (status),
    CONSTRAINT chk_legacy_financial_year_dates CHECK (start_date < end_date),
    CONSTRAINT fk_legacy_financial_year_created_by
        FOREIGN KEY (created_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_financial_year_closed_by
        FOREIGN KEY (closed_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_debtors (
    legacy_debtor_id INT NOT NULL AUTO_INCREMENT,
    legacy_reference VARCHAR(100) NULL,
    full_name VARCHAR(255) NOT NULL,
    pf_number VARCHAR(50) NULL,
    national_id VARCHAR(50) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    linked_user_id INT NULL,
    identity_status ENUM('UNVERIFIED','VERIFIED','DUPLICATE_REVIEW','CLOSED')
        NOT NULL DEFAULT 'UNVERIFIED',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (legacy_debtor_id),
    UNIQUE KEY uk_legacy_debtor_reference (legacy_reference),
    UNIQUE KEY uk_legacy_debtor_pf_number (pf_number),
    UNIQUE KEY uk_legacy_debtor_national_id (national_id),
    KEY idx_legacy_debtor_name (full_name),
    KEY idx_legacy_debtor_linked_user (linked_user_id),
    KEY idx_legacy_debtor_identity_status (identity_status),
    CONSTRAINT fk_legacy_debtor_linked_user
        FOREIGN KEY (linked_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_debtor_created_by
        FOREIGN KEY (created_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_debt_accounts (
    legacy_account_id INT NOT NULL AUTO_INCREMENT,
    legacy_debtor_id INT NOT NULL,
    account_reference VARCHAR(100) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    status ENUM(
        'OPEN','ACTIVE_PAYMENT','PARTIALLY_PAID','FULLY_PAID','OVERDUE',
        'UNDER_REVIEW','DISPUTED','WAIVED','WRITTEN_OFF',
        'MIGRATION_PENDING','MIGRATED','CLOSED'
    ) NOT NULL DEFAULT 'OPEN',
    dispute_status ENUM('NONE','OPEN','RESOLVED','REJECTED') NOT NULL DEFAULT 'NONE',
    migration_status ENUM('NOT_MIGRATED','PENDING','MIGRATED','FAILED')
        NOT NULL DEFAULT 'NOT_MIGRATED',
    migration_date DATETIME NULL,
    linked_loan_application_id INT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (legacy_account_id),
    UNIQUE KEY uk_legacy_account_reference (account_reference),
    UNIQUE KEY uk_legacy_one_account_per_debtor (legacy_debtor_id),
    KEY idx_legacy_account_status (status),
    KEY idx_legacy_account_dispute (dispute_status),
    KEY idx_legacy_account_migration (migration_status),
    KEY idx_legacy_account_linked_loan (linked_loan_application_id),
    CONSTRAINT fk_legacy_account_debtor
        FOREIGN KEY (legacy_debtor_id) REFERENCES legacy_debtors (legacy_debtor_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_account_loan
        FOREIGN KEY (linked_loan_application_id) REFERENCES loan_applications (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_account_created_by
        FOREIGN KEY (created_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_loan_components (
    legacy_component_id INT NOT NULL AUTO_INCREMENT,
    legacy_account_id INT NOT NULL,
    financial_year_id INT NOT NULL,
    component_reference VARCHAR(100) NOT NULL,
    source_system VARCHAR(100) NULL,
    source_reference VARCHAR(150) NULL,
    loan_description VARCHAR(500) NULL,
    original_principal DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    component_date DATE NOT NULL,
    status ENUM('OPEN','MIGRATED','CLOSED') NOT NULL DEFAULT 'OPEN',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (legacy_component_id),
    UNIQUE KEY uk_legacy_component_reference (component_reference),
    UNIQUE KEY uk_legacy_component_source (source_system, source_reference),
    KEY idx_legacy_component_account (legacy_account_id),
    KEY idx_legacy_component_year (financial_year_id),
    CONSTRAINT chk_legacy_component_principal CHECK (original_principal >= 0),
    CONSTRAINT fk_legacy_component_account
        FOREIGN KEY (legacy_account_id) REFERENCES legacy_debt_accounts (legacy_account_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_component_year
        FOREIGN KEY (financial_year_id) REFERENCES legacy_financial_years (financial_year_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_component_created_by
        FOREIGN KEY (created_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_transactions (
    legacy_transaction_id INT NOT NULL AUTO_INCREMENT,
    legacy_account_id INT NOT NULL,
    legacy_component_id INT NULL,
    financial_year_id INT NOT NULL,
    transaction_reference VARCHAR(100) NOT NULL,
    transaction_type ENUM(
        'OPENING_BALANCE','PAYMENT','INTEREST','PENALTY',
        'ADJUSTMENT_DEBIT','ADJUSTMENT_CREDIT','WAIVER','WRITE_OFF',
        'PAYMENT_REVERSAL','MIGRATION'
    ) NOT NULL,
    debit_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    credit_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    transaction_date DATE NOT NULL,
    posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_type VARCHAR(50) NULL,
    source_reference VARCHAR(150) NULL,
    description VARCHAR(500) NULL,
    reason_code VARCHAR(80) NULL,
    post_close_approval_reference VARCHAR(100) NULL,
    parent_transaction_id INT NULL,
    status ENUM('POSTED','REVERSED') NOT NULL DEFAULT 'POSTED',
    recorded_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (legacy_transaction_id),
    UNIQUE KEY uk_legacy_transaction_reference (transaction_reference),
    KEY idx_legacy_transaction_account_date
        (legacy_account_id, transaction_date, legacy_transaction_id),
    KEY idx_legacy_transaction_component (legacy_component_id),
    KEY idx_legacy_transaction_year (financial_year_id),
    KEY idx_legacy_transaction_type (transaction_type),
    KEY idx_legacy_transaction_parent (parent_transaction_id),
    KEY idx_legacy_transaction_source (source_type, source_reference),
    KEY idx_legacy_transaction_post_close (post_close_approval_reference),
    CONSTRAINT chk_legacy_transaction_one_sided
        CHECK (
            (debit_amount > 0 AND credit_amount = 0)
            OR (debit_amount = 0 AND credit_amount > 0)
        ),
    CONSTRAINT chk_legacy_transaction_nonnegative
        CHECK (debit_amount >= 0 AND credit_amount >= 0),
    CONSTRAINT fk_legacy_transaction_account
        FOREIGN KEY (legacy_account_id) REFERENCES legacy_debt_accounts (legacy_account_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_transaction_component
        FOREIGN KEY (legacy_component_id) REFERENCES legacy_loan_components (legacy_component_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_transaction_year
        FOREIGN KEY (financial_year_id) REFERENCES legacy_financial_years (financial_year_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_transaction_parent
        FOREIGN KEY (parent_transaction_id) REFERENCES legacy_transactions (legacy_transaction_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_transaction_recorded_by
        FOREIGN KEY (recorded_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_payment_details (
    legacy_transaction_id INT NOT NULL,
    payment_method ENUM('CASH','BANK_TRANSFER','SALARY_DEDUCTION','OTHER') NOT NULL,
    payment_date DATE NOT NULL,
    transaction_number VARCHAR(150) NULL,
    receipt_number VARCHAR(100) NULL,
    payer_name VARCHAR(255) NULL,
    payer_reference VARCHAR(150) NULL,
    funding_account_id INT NULL,
    reconciliation_state ENUM('UNRECONCILED','RECONCILED','REVERSED')
        NOT NULL DEFAULT 'UNRECONCILED',
    reconciled_by INT NULL,
    reconciled_at DATETIME NULL,
    posting_notes VARCHAR(500) NULL,
    PRIMARY KEY (legacy_transaction_id),
    UNIQUE KEY uk_legacy_payment_transaction_number (transaction_number),
    KEY idx_legacy_payment_date (payment_date),
    KEY idx_legacy_payment_reconciliation (reconciliation_state),
    KEY idx_legacy_payment_funding_account (funding_account_id),
    CONSTRAINT fk_legacy_payment_transaction
        FOREIGN KEY (legacy_transaction_id) REFERENCES legacy_transactions (legacy_transaction_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_payment_funding_account
        FOREIGN KEY (funding_account_id) REFERENCES funding_accounts (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_payment_reconciled_by
        FOREIGN KEY (reconciled_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_import_batches (
    import_batch_id INT NOT NULL AUTO_INCREMENT,
    batch_reference VARCHAR(100) NOT NULL,
    financial_year_id INT NOT NULL,
    source_filename VARCHAR(255) NULL,
    source_file_path VARCHAR(500) NOT NULL,
    source_file_hash CHAR(64) NULL,
    source_mime_type VARCHAR(100) NULL,
    source_type ENUM('CSV','XLS','XLSX','MANUAL','API') NOT NULL,
    status ENUM(
        'UPLOADED','VALIDATING','PREVIEWED','CONFIRMED',
        'POSTED','PARTIAL','REJECTED','CANCELLED'
    ) NOT NULL DEFAULT 'UPLOADED',
    detected_count INT UNSIGNED NOT NULL DEFAULT 0,
    valid_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_imported_debt DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    uploaded_by INT NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_by INT NULL,
    confirmed_at DATETIME NULL,
    completed_at DATETIME NULL,
    failure_summary VARCHAR(1000) NULL,
    PRIMARY KEY (import_batch_id),
    UNIQUE KEY uk_legacy_import_batch_reference (batch_reference),
    KEY idx_legacy_import_batch_year_status (financial_year_id, status, uploaded_at),
    KEY idx_legacy_import_batch_uploader (uploaded_by),
    CONSTRAINT chk_legacy_import_batch_counts CHECK (
        detected_count >= valid_count
        AND detected_count >= imported_count
        AND detected_count >= rejected_count
        AND detected_count >= duplicate_count
    ),
    CONSTRAINT chk_legacy_import_batch_total CHECK (total_imported_debt >= 0),
    CONSTRAINT fk_legacy_import_batch_year
        FOREIGN KEY (financial_year_id) REFERENCES legacy_financial_years (financial_year_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_import_batch_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users (user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_import_batch_confirmed_by
        FOREIGN KEY (confirmed_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_import_records (
    import_record_id INT NOT NULL AUTO_INCREMENT,
    import_batch_id INT NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    source_data JSON NULL,
    normalized_name VARCHAR(255) NULL,
    normalized_pf_number VARCHAR(50) NULL,
    normalized_national_id VARCHAR(50) NULL,
    source_reference VARCHAR(150) NULL,
    amount DECIMAL(19,4) NULL,
    validation_state ENUM(
        'VALID','INVALID','DUPLICATE','POSSIBLE_DUPLICATE','IMPORTED','SKIPPED'
    ) NOT NULL DEFAULT 'VALID',
    duplicate_of_record_id INT NULL,
    legacy_debtor_id INT NULL,
    legacy_account_id INT NULL,
    error_message VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (import_record_id),
    UNIQUE KEY uk_legacy_import_batch_row (import_batch_id, source_row_number),
    KEY idx_legacy_import_record_state (import_batch_id, validation_state),
    KEY idx_legacy_import_record_identity
        (normalized_pf_number, normalized_national_id),
    KEY idx_legacy_import_record_account (legacy_account_id),
    CONSTRAINT chk_legacy_import_record_amount
        CHECK (amount IS NULL OR amount >= 0),
    CONSTRAINT fk_legacy_import_record_batch
        FOREIGN KEY (import_batch_id) REFERENCES legacy_import_batches (import_batch_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_import_record_duplicate
        FOREIGN KEY (duplicate_of_record_id) REFERENCES legacy_import_records (import_record_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_import_record_debtor
        FOREIGN KEY (legacy_debtor_id) REFERENCES legacy_debtors (legacy_debtor_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_import_record_account
        FOREIGN KEY (legacy_account_id) REFERENCES legacy_debt_accounts (legacy_account_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_action_requests (
    action_request_id INT NOT NULL AUTO_INCREMENT,
    request_reference VARCHAR(100) NOT NULL,
    legacy_account_id INT NOT NULL,
    action_type ENUM(
        'PAYMENT_REVERSAL','ADJUSTMENT','INTEREST','PENALTY',
        'WAIVER','WRITE_OFF','MIGRATION','RECONCILIATION'
    ) NOT NULL,
    requested_amount DECIMAL(19,4) NULL,
    target_transaction_id INT NULL,
    reason_code VARCHAR(80) NOT NULL,
    reason_text VARCHAR(1000) NOT NULL,
    supporting_reference VARCHAR(150) NULL,
    status ENUM('REQUESTED','UNDER_REVIEW','APPROVED','REJECTED','EXECUTED','CANCELLED')
        NOT NULL DEFAULT 'REQUESTED',
    requested_by INT NOT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    executed_by INT NULL,
    executed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (action_request_id),
    UNIQUE KEY uk_legacy_action_request_reference (request_reference),
    KEY idx_legacy_action_request_account (legacy_account_id),
    KEY idx_legacy_action_request_status (status, action_type, created_at),
    KEY idx_legacy_action_request_target (target_transaction_id),
    CONSTRAINT chk_legacy_action_request_amount
        CHECK (requested_amount IS NULL OR requested_amount > 0),
    CONSTRAINT fk_legacy_action_request_account
        FOREIGN KEY (legacy_account_id) REFERENCES legacy_debt_accounts (legacy_account_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_action_request_target
        FOREIGN KEY (target_transaction_id) REFERENCES legacy_transactions (legacy_transaction_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_action_request_requested_by
        FOREIGN KEY (requested_by) REFERENCES users (user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_action_request_approved_by
        FOREIGN KEY (approved_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_action_request_executed_by
        FOREIGN KEY (executed_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_migrations (
    legacy_migration_id INT NOT NULL AUTO_INCREMENT,
    migration_reference VARCHAR(100) NOT NULL,
    legacy_account_id INT NOT NULL,
    action_request_id INT NULL,
    migration_status ENUM('REQUESTED','VALIDATED','APPROVED','EXECUTED','FAILED','CANCELLED')
        NOT NULL DEFAULT 'REQUESTED',
    balance_at_migration DECIMAL(19,4) NOT NULL,
    migration_date DATETIME NULL,
    loan_application_id INT NULL,
    loan_application_reference VARCHAR(50) NULL,
    requested_by INT NOT NULL,
    approved_by INT NULL,
    executed_by INT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    executed_at DATETIME NULL,
    failure_reason VARCHAR(1000) NULL,
    PRIMARY KEY (legacy_migration_id),
    UNIQUE KEY uk_legacy_migration_reference (migration_reference),
    UNIQUE KEY uk_legacy_migration_account (legacy_account_id),
    UNIQUE KEY uk_legacy_migration_loan (loan_application_id),
    KEY idx_legacy_migration_status (migration_status, executed_at),
    CONSTRAINT chk_legacy_migration_balance CHECK (balance_at_migration >= 0),
    CONSTRAINT fk_legacy_migration_account
        FOREIGN KEY (legacy_account_id) REFERENCES legacy_debt_accounts (legacy_account_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_migration_request
        FOREIGN KEY (action_request_id) REFERENCES legacy_action_requests (action_request_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_migration_loan
        FOREIGN KEY (loan_application_id) REFERENCES loan_applications (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_migration_requested_by
        FOREIGN KEY (requested_by) REFERENCES users (user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_migration_approved_by
        FOREIGN KEY (approved_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_migration_executed_by
        FOREIGN KEY (executed_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_audit_events (
    legacy_audit_event_id INT NOT NULL AUTO_INCREMENT,
    event_reference VARCHAR(100) NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id INT NOT NULL,
    action VARCHAR(60) NOT NULL,
    actor_user_id INT NULL,
    request_reference VARCHAR(100) NULL,
    reason_code VARCHAR(80) NULL,
    reason_text VARCHAR(1000) NULL,
    before_data JSON NULL,
    after_data JSON NULL,
    source_ip VARBINARY(16) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (legacy_audit_event_id),
    UNIQUE KEY uk_legacy_audit_event_reference (event_reference),
    KEY idx_legacy_audit_entity (entity_type, entity_id, created_at),
    KEY idx_legacy_audit_actor (actor_user_id, created_at),
    KEY idx_legacy_audit_action (action, created_at),
    CONSTRAINT fk_legacy_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DELIMITER $$

DROP TRIGGER IF EXISTS legacy_action_requests_before_insert$$
CREATE TRIGGER legacy_action_requests_before_insert
BEFORE INSERT ON legacy_action_requests
FOR EACH ROW
BEGIN
    IF NEW.approved_by IS NOT NULL AND NEW.approved_by = NEW.requested_by THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A legacy action requester cannot approve their own request';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_action_requests_before_update$$
CREATE TRIGGER legacy_action_requests_before_update
BEFORE UPDATE ON legacy_action_requests
FOR EACH ROW
BEGIN
    IF NEW.approved_by IS NOT NULL AND NEW.approved_by = NEW.requested_by THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A legacy action requester cannot approve their own request';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_migrations_before_insert$$
CREATE TRIGGER legacy_migrations_before_insert
BEFORE INSERT ON legacy_migrations
FOR EACH ROW
BEGIN
    IF NEW.approved_by IS NOT NULL AND NEW.approved_by = NEW.requested_by THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A migration requester cannot approve their own migration';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_migrations_before_update$$
CREATE TRIGGER legacy_migrations_before_update
BEFORE UPDATE ON legacy_migrations
FOR EACH ROW
BEGIN
    IF NEW.approved_by IS NOT NULL AND NEW.approved_by = NEW.requested_by THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A migration requester cannot approve their own migration';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_transactions_before_insert$$
CREATE TRIGGER legacy_transactions_before_insert
BEFORE INSERT ON legacy_transactions
FOR EACH ROW
BEGIN
    DECLARE year_status VARCHAR(10);
    DECLARE year_allows_post_close TINYINT;

    SELECT status, post_close_transactions_allowed
      INTO year_status, year_allows_post_close
      FROM legacy_financial_years
     WHERE financial_year_id = NEW.financial_year_id;

    IF (NEW.debit_amount <= 0 AND NEW.credit_amount <= 0)
       OR (NEW.debit_amount > 0 AND NEW.credit_amount > 0) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Legacy transaction must contain exactly one positive debit or credit';
    END IF;

    IF year_status = 'CLOSED'
       AND (year_allows_post_close = 0
            OR NEW.post_close_approval_reference IS NULL
            OR CHAR_LENGTH(TRIM(NEW.post_close_approval_reference)) = 0) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Closed financial years require post-close approval';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_transactions_before_update$$
CREATE TRIGGER legacy_transactions_before_update
BEFORE UPDATE ON legacy_transactions
FOR EACH ROW
BEGIN
    IF OLD.status IN ('POSTED','REVERSED') AND (
        NOT (NEW.legacy_account_id <=> OLD.legacy_account_id)
        OR NOT (NEW.legacy_component_id <=> OLD.legacy_component_id)
        OR NOT (NEW.transaction_reference <=> OLD.transaction_reference)
        OR NOT (NEW.transaction_type <=> OLD.transaction_type)
        OR NOT (NEW.debit_amount <=> OLD.debit_amount)
        OR NOT (NEW.credit_amount <=> OLD.credit_amount)
        OR NOT (NEW.transaction_date <=> OLD.transaction_date)
        OR NOT (NEW.posted_at <=> OLD.posted_at)
        OR NOT (NEW.source_type <=> OLD.source_type)
        OR NOT (NEW.source_reference <=> OLD.source_reference)
        OR NOT (NEW.description <=> OLD.description)
        OR NOT (NEW.reason_code <=> OLD.reason_code)
        OR NOT (NEW.parent_transaction_id <=> OLD.parent_transaction_id)
        OR NOT (NEW.recorded_by <=> OLD.recorded_by)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Posted legacy transactions are immutable; post a compensating transaction instead';
    END IF;
END$$

DELIMITER ;

