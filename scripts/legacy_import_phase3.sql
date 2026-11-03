-- Phase 3 legacy import and reconciliation extensions
-- Requires Phase 1 and Phase 2 legacy schemas first.

ALTER TABLE legacy_import_batches
    ADD UNIQUE KEY uk_legacy_import_source_hash_year
        (financial_year_id, source_file_hash);

CREATE TABLE IF NOT EXISTS legacy_reconciliation_runs (
    reconciliation_run_id INT NOT NULL AUTO_INCREMENT,
    run_reference VARCHAR(100) NOT NULL,
    source_type ENUM('OUTSTANDING_DEBTORS','IMPORT_BATCH','FULL') NOT NULL,
    source_batch_id INT NULL,
    status ENUM('RUNNING','COMPLETED','COMPLETED_WITH_MISMATCHES','FAILED')
        NOT NULL DEFAULT 'RUNNING',
    source_count INT UNSIGNED NOT NULL DEFAULT 0,
    normalized_count INT UNSIGNED NOT NULL DEFAULT 0,
    matched_count INT UNSIGNED NOT NULL DEFAULT 0,
    mismatch_count INT UNSIGNED NOT NULL DEFAULT 0,
    started_by INT NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    error_summary VARCHAR(1000) NULL,
    PRIMARY KEY (reconciliation_run_id),
    UNIQUE KEY uk_legacy_reconciliation_reference (run_reference),
    KEY idx_legacy_reconciliation_status (status, started_at),
    KEY idx_legacy_reconciliation_batch (source_batch_id),
    CONSTRAINT fk_legacy_reconciliation_batch
        FOREIGN KEY (source_batch_id) REFERENCES legacy_import_batches (import_batch_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_reconciliation_started_by
        FOREIGN KEY (started_by) REFERENCES users (user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_reconciliation_results (
    reconciliation_result_id INT NOT NULL AUTO_INCREMENT,
    reconciliation_run_id INT NOT NULL,
    source_reference VARCHAR(150) NOT NULL,
    source_name VARCHAR(255) NULL,
    source_pf_number VARCHAR(50) NULL,
    source_national_id VARCHAR(50) NULL,
    source_amount DECIMAL(19,4) NULL,
    normalized_debtor_id INT NULL,
    normalized_account_id INT NULL,
    normalized_amount DECIMAL(19,4) NULL,
    result_state ENUM('MATCHED','MISSING_NORMALIZED','MISSING_SOURCE','AMOUNT_MISMATCH','IDENTITY_MISMATCH','DUPLICATE_REVIEW')
        NOT NULL,
    difference_amount DECIMAL(19,4) NULL,
    details VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reconciliation_result_id),
    UNIQUE KEY uk_legacy_reconciliation_source (reconciliation_run_id, source_reference),
    KEY idx_legacy_reconciliation_result_state (reconciliation_run_id, result_state),
    KEY idx_legacy_reconciliation_result_account (normalized_account_id),
    CONSTRAINT fk_legacy_reconciliation_result_run
        FOREIGN KEY (reconciliation_run_id) REFERENCES legacy_reconciliation_runs (reconciliation_run_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_reconciliation_result_debtor
        FOREIGN KEY (normalized_debtor_id) REFERENCES legacy_debtors (legacy_debtor_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_reconciliation_result_account
        FOREIGN KEY (normalized_account_id) REFERENCES legacy_debt_accounts (legacy_account_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

