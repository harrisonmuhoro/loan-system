-- Phase 2 legacy financial ledger extensions
-- Requires scripts/legacy_debt_foundation.sql to have been applied first.
-- This migration changes structure only; it does not post financial transactions.

ALTER TABLE legacy_action_requests
    ADD COLUMN posting_type ENUM(
        'ADJUSTMENT_DEBIT','ADJUSTMENT_CREDIT',
        'WAIVER','WRITE_OFF','PAYMENT_REVERSAL'
    ) NULL AFTER action_type,
    ADD COLUMN financial_year_id INT NULL AFTER legacy_account_id,
    ADD COLUMN secondary_approved_by INT NULL AFTER approved_by,
    ADD COLUMN secondary_approved_at DATETIME NULL AFTER approved_at,
    ADD KEY idx_legacy_action_request_year (financial_year_id),
    ADD KEY idx_legacy_action_request_secondary_approver (secondary_approved_by),
    ADD CONSTRAINT fk_legacy_action_request_year
        FOREIGN KEY (financial_year_id) REFERENCES legacy_financial_years (financial_year_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_legacy_action_request_secondary_approved_by
        FOREIGN KEY (secondary_approved_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE legacy_debt_accounts
    MODIFY COLUMN status ENUM(
        'OPEN','ACTIVE_PAYMENT','PARTIALLY_PAID','FULLY_PAID','OVERDUE',
        'OVERPAID','UNDER_REVIEW','DISPUTED','WAIVED','WRITTEN_OFF',
        'MIGRATION_PENDING','MIGRATED','CLOSED'
    ) NOT NULL DEFAULT 'OPEN';

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

    IF NEW.secondary_approved_by IS NOT NULL
       AND NEW.secondary_approved_by = NEW.requested_by THEN
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

    IF NEW.secondary_approved_by IS NOT NULL
       AND NEW.secondary_approved_by = NEW.requested_by THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A legacy action requester cannot approve their own request';
    END IF;
END$$

DELIMITER ;

