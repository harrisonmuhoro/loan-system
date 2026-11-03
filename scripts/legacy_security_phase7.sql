-- Phase 7 financial-integrity hardening
-- Requires Phases 1-6.

DELIMITER $$

DROP TRIGGER IF EXISTS legacy_transactions_before_delete$$
CREATE TRIGGER legacy_transactions_before_delete
BEFORE DELETE ON legacy_transactions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Legacy financial transactions are immutable and cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_payment_details_before_update$$
CREATE TRIGGER legacy_payment_details_before_update
BEFORE UPDATE ON legacy_payment_details
FOR EACH ROW
BEGIN
    IF NOT (NEW.payment_method <=> OLD.payment_method)
       OR NOT (NEW.payment_date <=> OLD.payment_date)
       OR NOT (NEW.transaction_number <=> OLD.transaction_number)
       OR NOT (NEW.receipt_number <=> OLD.receipt_number)
       OR NOT (NEW.payer_name <=> OLD.payer_name)
       OR NOT (NEW.payer_reference <=> OLD.payer_reference)
       OR NOT (NEW.funding_account_id <=> OLD.funding_account_id)
       OR NOT (NEW.posting_notes <=> OLD.posting_notes) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Posted payment details are immutable';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_payment_details_before_delete$$
CREATE TRIGGER legacy_payment_details_before_delete
BEFORE DELETE ON legacy_payment_details
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Posted payment details are immutable and cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_action_requests_before_delete$$
CREATE TRIGGER legacy_action_requests_before_delete
BEFORE DELETE ON legacy_action_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Legacy action requests are immutable and cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_migrations_before_delete$$
CREATE TRIGGER legacy_migrations_before_delete
BEFORE DELETE ON legacy_migrations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Legacy migration history is immutable and cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_audit_events_before_update$$
CREATE TRIGGER legacy_audit_events_before_update
BEFORE UPDATE ON legacy_audit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Legacy audit events are immutable';
END$$

DROP TRIGGER IF EXISTS legacy_audit_events_before_delete$$
CREATE TRIGGER legacy_audit_events_before_delete
BEFORE DELETE ON legacy_audit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Legacy audit events are immutable and cannot be deleted';
END$$

DROP TRIGGER IF EXISTS legacy_import_batches_before_update$$
CREATE TRIGGER legacy_import_batches_before_update
BEFORE UPDATE ON legacy_import_batches
FOR EACH ROW
BEGIN
    IF NOT (NEW.source_filename <=> OLD.source_filename)
       OR NOT (NEW.source_file_path <=> OLD.source_file_path)
       OR NOT (NEW.source_file_hash <=> OLD.source_file_hash)
       OR NOT (NEW.source_mime_type <=> OLD.source_mime_type)
       OR NOT (NEW.uploaded_by <=> OLD.uploaded_by) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Retained import source metadata is immutable';
    END IF;
END$$

DROP TRIGGER IF EXISTS legacy_import_batches_before_delete$$
CREATE TRIGGER legacy_import_batches_before_delete
BEFORE DELETE ON legacy_import_batches
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Import batches and their source references cannot be deleted';
END$$

DELIMITER ;
