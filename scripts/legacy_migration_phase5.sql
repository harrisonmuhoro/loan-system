-- Phase 5 controlled legacy-to-normal loan migration
-- Requires Phases 1-4.

INSERT INTO legacy_permissions (permission_key, description) VALUES
('legacy.migration.process', 'Execute an approved legacy-to-normal-loan migration')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO legacy_role_permissions (role, permission_id, allowed)
SELECT r.role, p.permission_id, 1
  FROM (
    SELECT 'admin' AS role UNION ALL SELECT 'accountant' UNION ALL SELECT 'cashier'
  ) r
  INNER JOIN legacy_permissions p ON p.permission_key = 'legacy.migration.process'
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

ALTER TABLE legacy_migrations
    ADD UNIQUE KEY uk_legacy_migration_action_request (action_request_id);

DELIMITER $$

DROP TRIGGER IF EXISTS legacy_migrations_before_update$$
CREATE TRIGGER legacy_migrations_before_update
BEFORE UPDATE ON legacy_migrations
FOR EACH ROW
BEGIN
    IF NEW.approved_by IS NOT NULL AND NEW.approved_by = NEW.requested_by THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A migration requester cannot approve their own migration';
    END IF;

    IF OLD.migration_status = 'EXECUTED' AND (
        NOT (NEW.legacy_account_id <=> OLD.legacy_account_id)
        OR NOT (NEW.balance_at_migration <=> OLD.balance_at_migration)
        OR NOT (NEW.migration_date <=> OLD.migration_date)
        OR NOT (NEW.loan_application_id <=> OLD.loan_application_id)
        OR NOT (NEW.loan_application_reference <=> OLD.loan_application_reference)
        OR NEW.migration_status <> OLD.migration_status
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Executed migration relationships are immutable';
    END IF;
END$$

DELIMITER ;
