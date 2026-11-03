-- Phase 4 legacy debt RBAC and approval controls
-- Requires Phase 1, Phase 2 and Phase 3 schemas.

CREATE TABLE IF NOT EXISTS legacy_permissions (
    permission_id INT NOT NULL AUTO_INCREMENT,
    permission_key VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    PRIMARY KEY (permission_id),
    UNIQUE KEY uk_legacy_permission_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_role_permissions (
    role ENUM('admin','accountant','cashier','loanee') NOT NULL,
    permission_id INT NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (role, permission_id),
    CONSTRAINT fk_legacy_role_permission_permission
        FOREIGN KEY (permission_id) REFERENCES legacy_permissions(permission_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS legacy_user_permissions (
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    allowed TINYINT(1) NOT NULL,
    granted_by INT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(500) NULL,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_legacy_user_permission_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_user_permission_permission
        FOREIGN KEY (permission_id) REFERENCES legacy_permissions(permission_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_legacy_user_permission_granted_by
        FOREIGN KEY (granted_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO legacy_permissions (permission_key, description) VALUES
('legacy.view', 'View legacy debt accounts and history'),
('legacy.import', 'Upload, validate and preview legacy imports'),
('legacy.payment.create', 'Record legacy payments'),
('legacy.payment.reconcile', 'Reconcile legacy payments and migration results'),
('legacy.payment.reverse', 'Request payment reversals'),
('legacy.payment.reverse.approve', 'Approve payment reversals'),
('legacy.payment.reverse.process', 'Process approved payment reversals'),
('legacy.adjustment.request', 'Request legacy debit or credit adjustments'),
('legacy.adjustment.approve', 'Approve legacy adjustments'),
('legacy.adjustment.process', 'Process approved legacy adjustments'),
('legacy.waiver.request', 'Request legacy waivers'),
('legacy.waiver.approve', 'Approve legacy waivers'),
('legacy.waiver.process', 'Process approved legacy waivers'),
('legacy.writeoff.request', 'Request legacy write-offs'),
('legacy.writeoff.approve', 'Approve legacy write-offs'),
('legacy.writeoff.process', 'Process approved legacy write-offs'),
('legacy.migration.request', 'Request legacy migrations'),
('legacy.migration.approve', 'Approve and post legacy migrations'),
('legacy.interest.apply', 'Apply manually authorized legacy interest'),
('legacy.penalty.apply', 'Apply manually authorized legacy penalties'),
('legacy.audit.view', 'Review legacy audit events')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO legacy_role_permissions (role, permission_id, allowed)
SELECT r.role, p.permission_id, 1
  FROM (
    SELECT 'admin' AS role UNION ALL SELECT 'accountant' UNION ALL SELECT 'cashier' UNION ALL SELECT 'loanee'
  ) r
  INNER JOIN legacy_permissions p
 WHERE (r.role IN ('admin','accountant','cashier') AND p.permission_key IN (
    'legacy.view','legacy.import','legacy.payment.create','legacy.payment.reconcile',
    'legacy.payment.reverse','legacy.payment.reverse.approve','legacy.payment.reverse.process',
    'legacy.adjustment.request','legacy.adjustment.approve','legacy.adjustment.process',
    'legacy.waiver.request','legacy.waiver.approve','legacy.waiver.process',
    'legacy.migration.request','legacy.migration.approve'
  ))
    OR (r.role = 'accountant' AND p.permission_key IN (
      'legacy.interest.apply','legacy.penalty.apply',
      'legacy.writeoff.request','legacy.writeoff.approve','legacy.writeoff.process',
      'legacy.audit.view'
    ))
    OR (r.role = 'admin' AND p.permission_key IN (
      'legacy.interest.apply','legacy.penalty.apply',
      'legacy.writeoff.request','legacy.writeoff.approve','legacy.writeoff.process',
      'legacy.audit.view'
    ))
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

