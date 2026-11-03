-- Phase 10 financial integrity remediation.
-- Additive migration. Apply after Phases 1-7 on a backup/test copy first.

CREATE TABLE IF NOT EXISTS legacy_post_close_authorizations (
    post_close_authorization_id INT NOT NULL AUTO_INCREMENT,
    authorization_reference VARCHAR(100) NOT NULL,
    legacy_account_id INT NOT NULL,
    financial_year_id INT NOT NULL,
    operation_type ENUM('PAYMENT','INTEREST','PENALTY') NOT NULL,
    amount DECIMAL(19,4) NOT NULL,
    reason_text VARCHAR(1000) NOT NULL,
    status ENUM('REQUESTED','APPROVED','REJECTED','CANCELLED','CONSUMED') NOT NULL DEFAULT 'REQUESTED',
    requested_by INT NOT NULL,
    approved_by INT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    consumed_at DATETIME NULL,
    consumed_transaction_id INT NULL,
    PRIMARY KEY (post_close_authorization_id),
    UNIQUE KEY uk_legacy_post_close_reference (authorization_reference),
    UNIQUE KEY uk_legacy_post_close_consumed_tx (consumed_transaction_id),
    KEY idx_legacy_post_close_pending (status, financial_year_id, requested_at),
    CONSTRAINT chk_legacy_post_close_amount CHECK (amount > 0),
    CONSTRAINT fk_legacy_post_close_account FOREIGN KEY (legacy_account_id)
        REFERENCES legacy_debt_accounts (legacy_account_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_post_close_year FOREIGN KEY (financial_year_id)
        REFERENCES legacy_financial_years (financial_year_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_post_close_requester FOREIGN KEY (requested_by)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_post_close_approver FOREIGN KEY (approved_by)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_post_close_transaction FOREIGN KEY (consumed_transaction_id)
        REFERENCES legacy_transactions (legacy_transaction_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_post_close_action_consumptions (
    action_request_id INT NOT NULL,
    legacy_transaction_id INT NOT NULL,
    consumed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (action_request_id),
    UNIQUE KEY uk_legacy_post_close_action_transaction (legacy_transaction_id),
    CONSTRAINT fk_legacy_post_close_consumption_action FOREIGN KEY (action_request_id)
        REFERENCES legacy_action_requests (action_request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_post_close_consumption_transaction FOREIGN KEY (legacy_transaction_id)
        REFERENCES legacy_transactions (legacy_transaction_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @p10_index_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'legacy_transactions'
       AND index_name = 'uk_legacy_transaction_single_reversal'
);
SET @p10_drop_index_sql = IF(
    @p10_index_exists > 0,
    'ALTER TABLE legacy_transactions DROP INDEX uk_legacy_transaction_single_reversal',
    'SELECT 1'
);
PREPARE p10_drop_index FROM @p10_drop_index_sql;
EXECUTE p10_drop_index;
DEALLOCATE PREPARE p10_drop_index;

ALTER TABLE legacy_transactions
    ADD UNIQUE KEY uk_legacy_transaction_single_reversal (parent_transaction_id, transaction_type);

INSERT INTO legacy_permissions (permission_key, description) VALUES
('legacy.post_close.request', 'Request controlled posting to a closed financial year'),
('legacy.post_close.approve', 'Approve controlled posting to a closed financial year')
ON DUPLICATE KEY UPDATE description=VALUES(description);

INSERT INTO legacy_role_permissions (role, permission_id, allowed)
SELECT roles.role, permissions.permission_id, 1
  FROM (SELECT 'cashier' AS role UNION ALL SELECT 'accountant' UNION ALL SELECT 'admin') roles
  JOIN legacy_permissions permissions
    ON permissions.permission_key IN ('legacy.post_close.request','legacy.post_close.approve')
ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

DELIMITER $$

DROP TRIGGER IF EXISTS legacy_post_close_before_insert$$
CREATE TRIGGER legacy_post_close_before_insert
BEFORE INSERT ON legacy_post_close_authorizations
FOR EACH ROW
BEGIN
    DECLARE v_year_status VARCHAR(10);
    DECLARE v_post_close_allowed TINYINT;
    SELECT status, post_close_transactions_allowed
      INTO v_year_status, v_post_close_allowed
      FROM legacy_financial_years WHERE financial_year_id=NEW.financial_year_id;
    IF NEW.status <> 'REQUESTED' OR NEW.approved_by IS NOT NULL
       OR NEW.approved_at IS NOT NULL OR NEW.consumed_transaction_id IS NOT NULL
       OR v_year_status <> 'CLOSED' OR v_post_close_allowed <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Post-close authorization must begin as a request for an enabled closed year';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_post_close_before_delete$$
CREATE TRIGGER legacy_post_close_before_delete
BEFORE DELETE ON legacy_post_close_authorizations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Post-close authorization history cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_post_close_before_update$$
CREATE TRIGGER legacy_post_close_before_update
BEFORE UPDATE ON legacy_post_close_authorizations
FOR EACH ROW
BEGIN
    IF NOT (NEW.authorization_reference <=> OLD.authorization_reference)
       OR NOT (NEW.legacy_account_id <=> OLD.legacy_account_id)
       OR NOT (NEW.financial_year_id <=> OLD.financial_year_id)
       OR NOT (NEW.operation_type <=> OLD.operation_type)
       OR NOT (NEW.amount <=> OLD.amount)
       OR NOT (NEW.reason_text <=> OLD.reason_text)
       OR NOT (NEW.requested_by <=> OLD.requested_by)
       OR NOT (NEW.requested_at <=> OLD.requested_at)
       OR (NEW.approved_by IS NOT NULL AND NEW.approved_by = NEW.requested_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Post-close authorization request is immutable';
    END IF;
    IF OLD.status='CONSUMED' AND NEW.status <> OLD.status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Consumed post-close authorization is immutable';
    END IF;
    IF OLD.status='CONSUMED'
       AND (NOT (NEW.consumed_at <=> OLD.consumed_at)
            OR NOT (NEW.consumed_transaction_id <=> OLD.consumed_transaction_id)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Consumed authorization transaction link is immutable';
    END IF;
    IF OLD.status='APPROVED' AND NEW.status NOT IN ('APPROVED','CONSUMED','CANCELLED') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid post-close authorization state transition';
    END IF;
    IF OLD.status='REQUESTED' AND NEW.status='APPROVED'
       AND (NEW.approved_by IS NULL OR NEW.approved_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Post-close approval requires an approver and timestamp';
    END IF;
    IF OLD.status <> 'REQUESTED'
       AND (NOT (NEW.approved_by <=> OLD.approved_by)
            OR NOT (NEW.approved_at <=> OLD.approved_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Post-close approval identity is immutable';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_transactions_before_insert$$
CREATE TRIGGER legacy_transactions_before_insert
BEFORE INSERT ON legacy_transactions
FOR EACH ROW
BEGIN
    DECLARE v_year_status VARCHAR(10);
    DECLARE v_year_allows_post_close TINYINT;
    DECLARE v_auth_count INT DEFAULT 0;
    DECLARE v_action_count INT DEFAULT 0;
    DECLARE v_migration_count INT DEFAULT 0;
    DECLARE v_operation VARCHAR(20);

    SELECT status, post_close_transactions_allowed
      INTO v_year_status, v_year_allows_post_close
      FROM legacy_financial_years WHERE financial_year_id=NEW.financial_year_id;

    IF (NEW.debit_amount <= 0 AND NEW.credit_amount <= 0)
       OR (NEW.debit_amount > 0 AND NEW.credit_amount > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Legacy transaction must contain exactly one positive debit or credit';
    END IF;

    IF NEW.transaction_type='MIGRATION' THEN
        SELECT COUNT(*) INTO v_migration_count
          FROM legacy_migrations m
          JOIN legacy_action_requests r ON r.action_request_id=m.action_request_id
         WHERE m.migration_reference=NEW.post_close_approval_reference
           AND m.legacy_account_id=NEW.legacy_account_id
           AND m.migration_status='APPROVED'
           AND r.status='APPROVED'
           AND r.requested_by <> r.approved_by
           AND NEW.credit_amount=m.balance_at_migration;
        IF v_migration_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration ledger posting requires its independent approved migration request';
        END IF;
    END IF;

    IF v_year_status='CLOSED' THEN
        IF NEW.post_close_approval_reference IS NULL
           OR CHAR_LENGTH(TRIM(NEW.post_close_approval_reference))=0
           OR (v_year_allows_post_close <> 1 AND NEW.transaction_type <> 'MIGRATION') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Closed financial years require approved post-close authorization';
        END IF;

        IF NEW.transaction_type <> 'MIGRATION' THEN
            SET v_operation = CASE
                WHEN NEW.transaction_type='PAYMENT' THEN 'PAYMENT'
                WHEN NEW.transaction_type='INTEREST' THEN 'INTEREST'
                WHEN NEW.transaction_type='PENALTY' THEN 'PENALTY'
                ELSE NULL END;

            IF v_operation IS NOT NULL THEN
                SELECT COUNT(*) INTO v_auth_count
                  FROM legacy_post_close_authorizations a
                 WHERE a.authorization_reference=NEW.post_close_approval_reference
                   AND a.legacy_account_id=NEW.legacy_account_id
                   AND a.financial_year_id=NEW.financial_year_id
                   AND a.operation_type=v_operation
                   AND a.amount=CASE WHEN NEW.debit_amount > 0 THEN NEW.debit_amount ELSE NEW.credit_amount END
                   AND a.status='APPROVED'
                   AND a.requested_by <> a.approved_by
                   AND NEW.recorded_by <> a.approved_by;
            END IF;

            SELECT COUNT(*) INTO v_action_count
              FROM legacy_action_requests r
             WHERE r.request_reference=NEW.post_close_approval_reference
               AND r.legacy_account_id=NEW.legacy_account_id
               AND r.financial_year_id=NEW.financial_year_id
               AND r.status='APPROVED'
               AND r.requested_by <> r.approved_by
               AND (r.action_type='PAYMENT_REVERSAL' AND NEW.transaction_type='PAYMENT_REVERSAL'
                    AND NEW.parent_transaction_id=r.target_transaction_id
                    AND NEW.debit_amount=(SELECT t.credit_amount FROM legacy_transactions t WHERE t.legacy_transaction_id=r.target_transaction_id)
                    OR r.action_type='ADJUSTMENT' AND NEW.transaction_type=r.posting_type
                    AND NEW.debit_amount+NEW.credit_amount=r.requested_amount
                    OR r.action_type IN ('WAIVER','WRITE_OFF') AND NEW.transaction_type=r.posting_type
                    AND NEW.credit_amount=r.requested_amount);

            IF v_auth_count <> 1 AND v_action_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Closed-year transaction requires a matching approved, unused authorization';
            END IF;
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_transactions_after_insert$$
CREATE TRIGGER legacy_transactions_after_insert
AFTER INSERT ON legacy_transactions
FOR EACH ROW
BEGIN
    IF NEW.post_close_approval_reference IS NOT NULL THEN
        UPDATE legacy_post_close_authorizations
           SET status='CONSUMED', consumed_at=CURRENT_TIMESTAMP,
               consumed_transaction_id=NEW.legacy_transaction_id
         WHERE authorization_reference=NEW.post_close_approval_reference
           AND status='APPROVED';

        INSERT INTO legacy_post_close_action_consumptions (action_request_id, legacy_transaction_id)
        SELECT action_request_id, NEW.legacy_transaction_id
          FROM legacy_action_requests
         WHERE request_reference=NEW.post_close_approval_reference
           AND status='APPROVED'
        ;
    END IF;
END$$

DELIMITER ;

-- Prevent a normal loan from being linked to multiple legacy migration records.
SET @p10_index_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'legacy_debt_accounts'
       AND index_name = 'uk_legacy_account_linked_loan_unique'
);
SET @p10_add_link_index_sql = IF(
    @p10_index_exists = 0,
    'ALTER TABLE legacy_debt_accounts ADD UNIQUE KEY uk_legacy_account_linked_loan_unique (linked_loan_application_id)',
    'SELECT 1'
);
PREPARE p10_add_link_index FROM @p10_add_link_index_sql;
EXECUTE p10_add_link_index;
DEALLOCATE PREPARE p10_add_link_index;

-- Unified loan reporting contains normal loans (including migrated legacy loans) once,
-- plus only currently collectible normalized legacy accounts.
CREATE OR REPLACE VIEW v_unified_loans AS
SELECT la.id, la.user_id,
       CONVERT(la.application_id USING utf8mb4) COLLATE utf8mb4_general_ci AS application_id,
       CONVERT(la.full_name USING utf8mb4) COLLATE utf8mb4_general_ci AS full_name,
       CONVERT(la.id_number USING utf8mb4) COLLATE utf8mb4_general_ci AS id_number,
       CONVERT(CAST(la.pf_number AS CHAR(50)) USING utf8mb4) COLLATE utf8mb4_general_ci AS pf_number,
       CONVERT(la.email USING utf8mb4) COLLATE utf8mb4_general_ci AS email,
       CONVERT(la.loan_type USING utf8mb4) COLLATE utf8mb4_general_ci AS loan_type,
       la.loan_amount, la.payment_duration,
       CONVERT(la.payment_frequency USING utf8mb4) COLLATE utf8mb4_general_ci AS payment_frequency,
       la.basic_salary,
       CONVERT(la.employment_type USING utf8mb4) COLLATE utf8mb4_general_ci AS employment_type,
       la.contract_period, la.contract_start_date, la.contract_end_date,
       CONVERT(la.property_location USING utf8mb4) COLLATE utf8mb4_general_ci AS property_location,
       la.property_value,
       CONVERT(la.car_type USING utf8mb4) COLLATE utf8mb4_general_ci AS car_type,
       la.car_value,
       CONVERT(la.bank_name USING utf8mb4) COLLATE utf8mb4_general_ci AS bank_name,
       CONVERT(la.bank_branch USING utf8mb4) COLLATE utf8mb4_general_ci AS bank_branch,
       CONVERT(la.bank_account_number USING utf8mb4) COLLATE utf8mb4_general_ci AS bank_account_number,
       CONVERT(la.status USING utf8mb4) COLLATE utf8mb4_general_ci AS status,
       la.application_date, la.dispatch_date, la.closed_date,
       CONVERT(la.feedback USING utf8mb4) COLLATE utf8mb4_general_ci AS feedback,
       la.cashier_processed_date, la.accountant_processed_date, la.created_at, la.updated_at,
       CONVERT(CAST(NULL AS CHAR(20)) USING utf8mb4) COLLATE utf8mb4_general_ci AS financial_year,
       CONVERT('standard' USING utf8mb4) COLLATE utf8mb4_general_ci AS record_type
  FROM loan_applications la
UNION ALL
SELECT a.legacy_account_id AS id, d.linked_user_id AS user_id,
       CONVERT(CONCAT('LEGACY-', a.account_reference) USING utf8mb4) COLLATE utf8mb4_general_ci AS application_id,
       CONVERT(d.full_name USING utf8mb4) COLLATE utf8mb4_general_ci AS full_name,
       CONVERT(COALESCE(d.national_id,'') USING utf8mb4) COLLATE utf8mb4_general_ci AS id_number,
       CONVERT(COALESCE(d.pf_number,'') USING utf8mb4) COLLATE utf8mb4_general_ci AS pf_number,
       CONVERT(COALESCE(d.email,'') USING utf8mb4) COLLATE utf8mb4_general_ci AS email,
       CONVERT('mortgage' USING utf8mb4) COLLATE utf8mb4_general_ci AS loan_type,
       COALESCE((SELECT SUM(t.debit_amount)-SUM(t.credit_amount) FROM legacy_transactions t WHERE t.legacy_account_id=a.legacy_account_id),0) AS loan_amount,
       12 AS payment_duration,
       CONVERT('monthly' USING utf8mb4) COLLATE utf8mb4_general_ci AS payment_frequency,
       0 AS basic_salary,
       CONVERT('permanent' USING utf8mb4) COLLATE utf8mb4_general_ci AS employment_type,
       CAST(NULL AS UNSIGNED) AS contract_period, CAST(NULL AS DATE) AS contract_start_date,
       CAST(NULL AS DATE) AS contract_end_date,
       CONVERT(CAST(NULL AS CHAR(255)) USING utf8mb4) COLLATE utf8mb4_general_ci AS property_location, CAST(NULL AS DECIMAL(15,2)) AS property_value,
       CONVERT(CAST(NULL AS CHAR(100)) USING utf8mb4) COLLATE utf8mb4_general_ci AS car_type, CAST(NULL AS DECIMAL(15,2)) AS car_value,
       CONVERT('' USING utf8mb4) COLLATE utf8mb4_general_ci AS bank_name,
       CONVERT('' USING utf8mb4) COLLATE utf8mb4_general_ci AS bank_branch,
       CONVERT('' USING utf8mb4) COLLATE utf8mb4_general_ci AS bank_account_number,
       CONVERT(a.status USING utf8mb4) COLLATE utf8mb4_general_ci AS status,
       a.created_at AS application_date, a.created_at AS dispatch_date,
       CAST(NULL AS DATETIME) AS closed_date,
       CONVERT('Legacy debt account' USING utf8mb4) COLLATE utf8mb4_general_ci AS feedback,
       CAST(NULL AS DATETIME) AS cashier_processed_date,
       CAST(NULL AS DATETIME) AS accountant_processed_date,
       a.created_at, a.updated_at, CONVERT(CAST(NULL AS CHAR(20)) USING utf8mb4) COLLATE utf8mb4_general_ci AS financial_year,
       CONVERT('legacy' USING utf8mb4) COLLATE utf8mb4_general_ci AS record_type
  FROM legacy_debt_accounts a
  JOIN legacy_debtors d ON d.legacy_debtor_id=a.legacy_debtor_id
 WHERE a.migration_status <> 'MIGRATED';
