-- ============================================================
-- Loan Management System - Full Database Schema
-- Collation: utf8mb4_general_ci (compatible with MySQL & MariaDB)
-- No seed data - structure only
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;
SET time_zone = "+03:00";
SET NAMES utf8mb4;

-- ============================================================
-- Create and select database
-- ============================================================
CREATE DATABASE IF NOT EXISTS `loan_management`
  DEFAULT 
  DEFAULT ;

USE `loan_management`;

-- ============================================================
-- Table: users
-- ============================================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id`            INT            NOT NULL AUTO_INCREMENT,
  `full_name`          VARCHAR(100)   NOT NULL,
  `email`              VARCHAR(100)   DEFAULT NULL,
  `id_number`          VARCHAR(20)    NOT NULL,
  `pf_number`          BIGINT         NOT NULL,
  `password`           VARCHAR(255)   NOT NULL,
  `role`               ENUM('loanee','accountant','cashier','admin') NOT NULL,
  `created_at`         TIMESTAMP      NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP      NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `can_apply_for_loan` TINYINT(1)     DEFAULT '0',
  `is_active`          TINYINT(1)     DEFAULT '1',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uk_pf_number` (`pf_number`),
  UNIQUE KEY `uk_email`     (`email`),
  KEY `idx_id_number`       (`id_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: admins
-- ============================================================
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `pf_number`     VARCHAR(20)  NOT NULL,
  `full_name`     VARCHAR(100) NOT NULL,
  `email`         VARCHAR(100) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('admin') DEFAULT 'admin',
  `created_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active`     TINYINT(1)   DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `pf_number` (`pf_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: accountants
-- ============================================================
DROP TABLE IF EXISTS `accountants`;
CREATE TABLE `accountants` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `pf_number`     VARCHAR(20)  NOT NULL,
  `full_name`     VARCHAR(100) NOT NULL,
  `email`         VARCHAR(100) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('accountant') DEFAULT 'accountant',
  `created_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active`     TINYINT(1)   DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `pf_number` (`pf_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: cashiers
-- ============================================================
DROP TABLE IF EXISTS `cashiers`;
CREATE TABLE `cashiers` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `pf_number`     VARCHAR(20)  NOT NULL,
  `full_name`     VARCHAR(100) NOT NULL,
  `email`         VARCHAR(100) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('cashier') DEFAULT 'cashier',
  `created_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active`     TINYINT(1)   DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `pf_number` (`pf_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: loanee
-- ============================================================
DROP TABLE IF EXISTS `loanee`;
CREATE TABLE `loanee` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `pf_number`     VARCHAR(20)  NOT NULL,
  `full_name`     VARCHAR(100) NOT NULL,
  `email`         VARCHAR(100) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('loanee') DEFAULT 'loanee',
  `created_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active`     TINYINT(1)   DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `pf_number` (`pf_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: staff
-- ============================================================
DROP TABLE IF EXISTS `staff`;
CREATE TABLE `staff` (
  `staff_id`   INT          NOT NULL AUTO_INCREMENT,
  `user_id`    INT          NOT NULL,
  `pf_number`  VARCHAR(20)  NOT NULL,
  `full_name`  VARCHAR(100) NOT NULL,
  `email`      VARCHAR(100) DEFAULT NULL,
  `id_number`  VARCHAR(20)  NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `role`       ENUM('admin','accountant','cashier') NOT NULL,
  `is_active`  TINYINT(1)   DEFAULT '1',
  `created_by` INT          DEFAULT NULL,
  `created_at` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` INT          DEFAULT NULL,
  `updated_at` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `uk_pf_number` (`pf_number`),
  UNIQUE KEY `uk_email`     (`email`),
  KEY `fk_staff_user_id`    (`user_id`),
  KEY `fk_staff_created_by` (`created_by`),
  KEY `fk_staff_updated_by` (`updated_by`),
  KEY `idx_staff_role`      (`role`),
  KEY `idx_staff_active`    (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: staff_updates
-- ============================================================
DROP TABLE IF EXISTS `staff_updates`;
CREATE TABLE `staff_updates` (
  `update_id`     INT          NOT NULL AUTO_INCREMENT,
  `staff_id`      INT          NOT NULL,
  `updated_by`    INT          DEFAULT NULL,
  `update_type`   ENUM('create','update','delete','activate','deactivate') NOT NULL,
  `field_name`    VARCHAR(50)  DEFAULT NULL,
  `old_value`     TEXT,
  `new_value`     TEXT,
  `update_reason` TEXT,
  `update_date`   TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`update_id`),
  KEY `fk_staff_updates_staff_id`   (`staff_id`),
  KEY `fk_staff_updates_updated_by` (`updated_by`),
  KEY `idx_update_type`             (`update_type`),
  KEY `idx_update_date`             (`update_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: staff_portal_access
-- ============================================================
DROP TABLE IF EXISTS `staff_portal_access`;
CREATE TABLE `staff_portal_access` (
  `id`                 INT       NOT NULL AUTO_INCREMENT,
  `user_id`            INT       NOT NULL,
  `can_apply_for_loan` TINYINT(1) DEFAULT '0',
  `created_at`         TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`         TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: loan_applications
-- ============================================================
DROP TABLE IF EXISTS `loan_applications`;
CREATE TABLE `loan_applications` (
  `id`                        INT            NOT NULL AUTO_INCREMENT,
  `user_id`                   INT            DEFAULT NULL,
  `application_id`            VARCHAR(50)    NOT NULL,
  `full_name`                 VARCHAR(100)   NOT NULL,
  `id_number`                 VARCHAR(20)    DEFAULT NULL,
  `pf_number`                 BIGINT         DEFAULT NULL,
  `email`                     VARCHAR(100)   DEFAULT NULL,
  `loan_type`                 ENUM('mortgage','car') NOT NULL,
  `loan_amount`               DECIMAL(19,4)  DEFAULT NULL,
  `payment_duration`          INT            DEFAULT '12',
  `payment_frequency`         ENUM('monthly') DEFAULT 'monthly',
  `basic_salary`              DECIMAL(12,2)  DEFAULT NULL,
  `employment_type`           ENUM('permanent','contracted') DEFAULT NULL,
  `contract_period`           INT            DEFAULT NULL,
  `contract_start_date`       DATE           DEFAULT NULL,
  `contract_end_date`         DATE           DEFAULT NULL,
  `property_location`         VARCHAR(255)   DEFAULT NULL,
  `property_value`            DECIMAL(19,4)  DEFAULT NULL,
  `car_type`                  VARCHAR(100)   DEFAULT NULL,
  `car_value`                 DECIMAL(19,4)  DEFAULT NULL,
  `bank_name`                 ENUM('Family Bank') DEFAULT NULL,
  `bank_branch`               VARCHAR(100)   DEFAULT NULL,
  `bank_account_number`       VARCHAR(50)    DEFAULT NULL,
  `status`                    ENUM('pending','forwarded','accepted','dispatched','active','rejected','returned','closed') DEFAULT 'pending',
  `application_date`          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dispatch_date`             DATETIME       DEFAULT NULL,
  `closed_date`               DATETIME       DEFAULT NULL,
  `feedback`                  TEXT,
  `cashier_processed_date`    DATETIME       DEFAULT NULL,
  `accountant_processed_date` DATETIME       DEFAULT NULL,
  `created_at`                TIMESTAMP      NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                TIMESTAMP      NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_id`      (`application_id`),
  KEY `idx_user_id`                (`user_id`),
  FULLTEXT KEY `ft_full_name`      (`full_name`),
  FULLTEXT KEY `ft_application_id` (`application_id`),
  FULLTEXT KEY `ft_email`          (`email`),
  FULLTEXT KEY `ft_feedback`       (`feedback`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Triggers for loan_applications
DROP TRIGGER IF EXISTS `loan_applications_before_insert`;
DELIMITER $$
CREATE TRIGGER `loan_applications_before_insert`
BEFORE INSERT ON `loan_applications` FOR EACH ROW
BEGIN
    IF NEW.application_date IS NULL THEN
        SET NEW.application_date = CURRENT_TIMESTAMP;
    END IF;
    IF NEW.created_at IS NULL THEN
        SET NEW.created_at = CURRENT_TIMESTAMP;
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `loan_applications_before_update`;
DELIMITER $$
CREATE TRIGGER `loan_applications_before_update`
BEFORE UPDATE ON `loan_applications` FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `validate_loan_amount`;
DELIMITER $$
CREATE TRIGGER `validate_loan_amount`
BEFORE INSERT ON `loan_applications` FOR EACH ROW
BEGIN
    IF NEW.loan_type = 'mortgage' AND NEW.property_value IS NOT NULL THEN
        IF NEW.loan_amount > NEW.property_value THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Loan amount cannot exceed property value';
        END IF;
    END IF;
    IF NEW.loan_type = 'car' AND NEW.car_value IS NOT NULL THEN
        IF NEW.loan_amount > NEW.car_value THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Loan amount cannot exceed car value';
        END IF;
    END IF;
END$$
DELIMITER ;

-- ============================================================
-- Table: loan_extended_details
-- ============================================================
DROP TABLE IF EXISTS `loan_extended_details`;
CREATE TABLE `loan_extended_details` (
  `id`                   INT          NOT NULL AUTO_INCREMENT,
  `application_id`       VARCHAR(50)  NOT NULL,
  `surname`              VARCHAR(100) DEFAULT NULL,
  `other_names`          VARCHAR(100) DEFAULT NULL,
  `po_box`               VARCHAR(50)  DEFAULT NULL,
  `town_home_telephone`  VARCHAR(20)  DEFAULT NULL,
  `mobile_number`        VARCHAR(20)  DEFAULT NULL,
  `email_address`        VARCHAR(100) DEFAULT NULL,
  `marital_status`       ENUM('Single','Married','Divorced','Widowed','Separated') DEFAULT NULL,
  `current_residence`    VARCHAR(255) DEFAULT NULL,
  `permanent_residence`  VARCHAR(255) DEFAULT NULL,
  `province_county`      VARCHAR(100) DEFAULT NULL,
  `court_road_residence` VARCHAR(255) DEFAULT NULL,
  `district_road`        VARCHAR(100) DEFAULT NULL,
  `date_of_birth`        DATE         DEFAULT NULL,
  `employer_name`        VARCHAR(200) DEFAULT NULL,
  `work_station`         VARCHAR(200) DEFAULT NULL,
  `office_address`       TEXT,
  `office_telephone`     VARCHAR(20)  DEFAULT NULL,
  `designation`          VARCHAR(100) DEFAULT NULL,
  `date_employed`        DATE         DEFAULT NULL,
  `gross_salary`         DECIMAL(12,2) DEFAULT NULL,
  `net_salary`           DECIMAL(12,2) DEFAULT NULL,
  `id_front_image`       VARCHAR(255) DEFAULT NULL,
  `id_back_image`        VARCHAR(255) DEFAULT NULL,
  `payslip_image`        VARCHAR(255) DEFAULT NULL,
  `kra_pin_image`        VARCHAR(255) DEFAULT NULL,
  `created_at`           TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_application_id` (`application_id`),
  KEY `idx_surname`        (`surname`),
  KEY `idx_mobile`         (`mobile_number`),
  KEY `idx_email`          (`email_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: loandetails
-- ============================================================
DROP TABLE IF EXISTS `loandetails`;
CREATE TABLE `loandetails` (
  `detail_id`         INT           NOT NULL AUTO_INCREMENT,
  `application_id`    VARCHAR(20)   NOT NULL,
  `property_location` VARCHAR(255)  DEFAULT NULL,
  `property_value`    DECIMAL(12,2) DEFAULT NULL,
  `car_model`         VARCHAR(100)  DEFAULT NULL,
  `car_value`         DECIMAL(12,2) DEFAULT NULL,
  PRIMARY KEY (`detail_id`),
  KEY `fk_loandetails_application_id` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: accountant_decisions
-- ============================================================
DROP TABLE IF EXISTS `accountant_decisions`;
CREATE TABLE `accountant_decisions` (
  `id`                  INT       NOT NULL AUTO_INCREMENT,
  `loan_application_id` INT       NOT NULL,
  `accountant_id`       INT       NOT NULL,
  `decision`            ENUM('accepted','rejected','dispatched') NOT NULL,
  `feedback`            TEXT,
  `processed_date`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loan_application_id` (`loan_application_id`),
  KEY `accountant_id`       (`accountant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: payments
-- ============================================================
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `payment_id`            INT           NOT NULL AUTO_INCREMENT,
  `application_id`        VARCHAR(50)   NOT NULL,
  `payment_date`          DATE          NOT NULL,
  `amount`                DECIMAL(10,2) NOT NULL,
  `principal_amount`      DECIMAL(10,2) NOT NULL,
  `interest_amount`       DECIMAL(10,2) NOT NULL,
  `balance_after`         DECIMAL(12,2) NOT NULL,
  `payment_source`        ENUM('cash_deposit','bank_transfer','salary_deduction') NOT NULL,
  `transaction_reference` VARCHAR(50)   DEFAULT NULL,
  `recorded_by`           VARCHAR(100)  NOT NULL,
  `recorded_at`           TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  `notes`                 TEXT,
  PRIMARY KEY (`payment_id`),
  KEY `idx_payments_application_id` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: payment_schedules
-- ============================================================
DROP TABLE IF EXISTS `payment_schedules`;
CREATE TABLE `payment_schedules` (
  `id`                  INT           NOT NULL AUTO_INCREMENT,
  `loan_application_id` INT           NOT NULL,
  `payment_number`      INT           NOT NULL,
  `due_date`            DATE          NOT NULL,
  `expected_amount`     DECIMAL(12,2) NOT NULL,
  `expected_principal`  DECIMAL(12,2) NOT NULL,
  `expected_interest`   DECIMAL(12,2) NOT NULL,
  `expected_balance`    DECIMAL(12,2) NOT NULL,
  `status`              ENUM('pending','partial','paid','overdue') DEFAULT 'pending',
  `actual_amount`       DECIMAL(12,2) DEFAULT '0.00',
  `actual_interest`     DECIMAL(12,2) DEFAULT '0.00',
  `actual_date`         DATE          DEFAULT NULL,
  `variance`            DECIMAL(12,2) DEFAULT '0.00',
  `created_at`          TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_payment_schedules_loan_id` (`loan_application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: funding_accounts
-- ============================================================
DROP TABLE IF EXISTS `funding_accounts`;
CREATE TABLE `funding_accounts` (
  `id`         INT           NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(255)  NOT NULL,
  `balance`    DECIMAL(15,2) NOT NULL DEFAULT '0.00',
  `created_at` TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: funding_transactions
-- ============================================================
DROP TABLE IF EXISTS `funding_transactions`;
CREATE TABLE `funding_transactions` (
  `id`                 INT           NOT NULL AUTO_INCREMENT,
  `funding_account_id` INT           NOT NULL,
  `amount`             DECIMAL(15,2) NOT NULL,
  `transaction_type`   ENUM('withdrawal','deposit','refund') NOT NULL DEFAULT 'withdrawal',
  `reference`          VARCHAR(255)  DEFAULT NULL,
  `created_by`         INT           DEFAULT NULL,
  `created_at`         TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_funding_account_id` (`funding_account_id`),
  KEY `idx_created_by`         (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Logs all financial transactions from funding accounts';

-- ============================================================
-- Table: outstanding_debtors
-- ============================================================
DROP TABLE IF EXISTS `outstanding_debtors`;
CREATE TABLE `outstanding_debtors` (
  `debtor_id`           INT           NOT NULL AUTO_INCREMENT COMMENT 'Unique debtor record ID',
  `financial_year`      VARCHAR(20)   NOT NULL COMMENT 'Financial year e.g. 2024/2025',
  `name`                VARCHAR(255)  NOT NULL COMMENT 'Debtor full name',
  `pf_number`           VARCHAR(50)   DEFAULT NULL,
  `id_number`           VARCHAR(50)   DEFAULT NULL,
  `amount`              DECIMAL(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Outstanding amount',
  `created_by`          INT           DEFAULT NULL COMMENT 'User ID who imported this record',
  `created_at`          TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Import date',
  `updated_at`          TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update',
  `loan_type`           ENUM('mortgage','car') DEFAULT 'mortgage',
  `employment_type`     ENUM('permanent','contracted') DEFAULT 'permanent',
  `basic_salary`        DECIMAL(12,2) DEFAULT '0.00',
  `payment_duration`    INT           DEFAULT '12',
  `payment_frequency`   ENUM('monthly') DEFAULT 'monthly',
  `application_date`    TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
  `dispatch_date`       DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `status`              ENUM('pending','forwarded','accepted','dispatched','active','rejected','returned','closed') DEFAULT 'active',
  `bank_name`           VARCHAR(100)  DEFAULT 'Family Bank',
  `bank_branch`         VARCHAR(100)  DEFAULT '',
  `bank_account_number` VARCHAR(50)   DEFAULT '',
  `email`               VARCHAR(100)  DEFAULT '',
  `migrated_to_loan`    TINYINT(1)    DEFAULT '0' COMMENT 'Flag: 1 if converted to loan_applications',
  `loan_application_id` VARCHAR(100)  DEFAULT NULL COMMENT 'Application ID after migration',
  PRIMARY KEY (`debtor_id`),
  UNIQUE KEY `unique_debtor_fy`         (`financial_year`,`name`),
  KEY `idx_financial_year`              (`financial_year`),
  KEY `idx_name`                        (`name`),
  KEY `idx_created_at`                  (`created_at`),
  KEY `fk_debtor_created_by`            (`created_by`),
  KEY `idx_status`                      (`status`),
  KEY `idx_migrated`                    (`migrated_to_loan`),
  KEY `idx_pf_number`                   (`pf_number`),
  KEY `idx_id_number`                   (`id_number`),
  KEY `idx_migration_lookup`            (`migrated_to_loan`,`status`),
  KEY `idx_financial_year_status`       (`financial_year`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Outstanding debtors from previous financial years';

-- ============================================================
-- Table: notifications
-- ============================================================
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notification_id` INT          NOT NULL AUTO_INCREMENT,
  `user_id`         INT          NOT NULL,
  `title`           VARCHAR(100) NOT NULL,
  `message`         TEXT         NOT NULL,
  `is_read`         TINYINT(1)   DEFAULT '0',
  `created_at`      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  KEY `fk_notifications_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: cashier_notifications
-- ============================================================
DROP TABLE IF EXISTS `cashier_notifications`;
CREATE TABLE `cashier_notifications` (
  `id`                  INT        NOT NULL AUTO_INCREMENT,
  `cashier_id`          INT        NOT NULL,
  `loan_application_id` INT        DEFAULT NULL,
  `message`             TEXT       NOT NULL,
  `is_read`             TINYINT(1) DEFAULT '0',
  `created_at`          TIMESTAMP  NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cashier_id`          (`cashier_id`),
  KEY `loan_application_id` (`loan_application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: loanee_notifications
-- ============================================================
DROP TABLE IF EXISTS `loanee_notifications`;
CREATE TABLE `loanee_notifications` (
  `id`                  INT        NOT NULL AUTO_INCREMENT,
  `loanee_id`           INT        NOT NULL,
  `loan_application_id` INT        DEFAULT NULL,
  `message`             TEXT       NOT NULL,
  `is_read`             TINYINT(1) DEFAULT '0',
  `created_at`          TIMESTAMP  NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loanee_id`           (`loanee_id`),
  KEY `loan_application_id` (`loan_application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: activity_log
-- ============================================================
DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id`        INT         NOT NULL AUTO_INCREMENT,
  `admin_id`  INT         DEFAULT NULL,
  `action`    VARCHAR(50) NOT NULL,
  `details`   TEXT,
  `timestamp` TIMESTAMP   NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_activity_log_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: admin_logs
-- ============================================================
DROP TABLE IF EXISTS `admin_logs`;
CREATE TABLE `admin_logs` (
  `id`        INT          NOT NULL AUTO_INCREMENT,
  `admin_id`  INT          NOT NULL,
  `action`    VARCHAR(100) NOT NULL,
  `details`   TEXT,
  `timestamp` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_admin_logs_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: api_keys
-- ============================================================
DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE `api_keys` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `api_key`     VARCHAR(64)  NOT NULL,
  `api_secret`  VARCHAR(128) NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `description` TEXT,
  `user_id`     INT          DEFAULT NULL,
  `permissions` JSON         DEFAULT NULL,
  `is_active`   TINYINT(1)   DEFAULT '1',
  `usage_count` INT          DEFAULT '0',
  `last_used`   DATETIME     DEFAULT NULL,
  `last_ip`     VARCHAR(45)  DEFAULT NULL,
  `expires_at`  DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL,
  `revoked_at`  DATETIME     DEFAULT NULL,
  `revoked_by`  INT          DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_key`  (`api_key`),
  KEY `idx_api_key`     (`api_key`),
  KEY `idx_active`      (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: api_key_logs
-- ============================================================
DROP TABLE IF EXISTS `api_key_logs`;
CREATE TABLE `api_key_logs` (
  `id`               BIGINT       NOT NULL AUTO_INCREMENT,
  `api_key_id`       INT          NOT NULL,
  `endpoint`         VARCHAR(255) DEFAULT NULL,
  `method`           VARCHAR(10)  DEFAULT NULL,
  `ip_address`       VARCHAR(45)  DEFAULT NULL,
  `user_agent`       TEXT,
  `status_code`      INT          DEFAULT NULL,
  `request_time`     DATETIME     NOT NULL,
  `response_time_ms` INT          DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_api_key_id`   (`api_key_id`),
  KEY `idx_request_time` (`request_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: system_settings
-- ============================================================
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `value`       TEXT         NOT NULL,
  `created_by`  INT          DEFAULT NULL,
  `updated_by`  INT          DEFAULT NULL,
  `created_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_created_by`     (`created_by`),
  KEY `idx_updated_by`     (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: user_preferences
-- ============================================================
DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE `user_preferences` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `user_id`          INT          NOT NULL,
  `preference_key`   VARCHAR(50)  NOT NULL,
  `preference_value` VARCHAR(100) NOT NULL,
  `created_at`       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_preference` (`user_id`,`preference_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Foreign Key Constraints
-- ============================================================
ALTER TABLE `accountant_decisions`
  ADD CONSTRAINT `fk_accountant_decisions_accountant`
    FOREIGN KEY (`accountant_id`)       REFERENCES `accountants`       (`id`)      ON DELETE CASCADE  ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_accountant_decisions_loan`
    FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`)      ON DELETE CASCADE  ON UPDATE CASCADE;

ALTER TABLE `api_key_logs`
  ADD CONSTRAINT `api_key_logs_ibfk_1`
    FOREIGN KEY (`api_key_id`)          REFERENCES `api_keys`          (`id`)      ON DELETE CASCADE;

ALTER TABLE `funding_transactions`
  ADD CONSTRAINT `fk_funding_transactions_funding_account`
    FOREIGN KEY (`funding_account_id`)  REFERENCES `funding_accounts`  (`id`)      ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_funding_transactions_user`
    FOREIGN KEY (`created_by`)          REFERENCES `users`             (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `outstanding_debtors`
  ADD CONSTRAINT `fk_debtor_created_by`
    FOREIGN KEY (`created_by`)          REFERENCES `users`             (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `staff`
  ADD CONSTRAINT `fk_staff_user_id`
    FOREIGN KEY (`user_id`)             REFERENCES `users`             (`user_id`) ON DELETE CASCADE  ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_created_by`
    FOREIGN KEY (`created_by`)          REFERENCES `users`             (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_updated_by`
    FOREIGN KEY (`updated_by`)          REFERENCES `users`             (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `staff_updates`
  ADD CONSTRAINT `fk_staff_updates_staff_id`
    FOREIGN KEY (`staff_id`)            REFERENCES `staff`             (`staff_id`) ON DELETE CASCADE  ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_updates_updated_by`
    FOREIGN KEY (`updated_by`)          REFERENCES `users`             (`user_id`)  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `system_settings`
  ADD CONSTRAINT `fk_system_settings_created_by`
    FOREIGN KEY (`created_by`)          REFERENCES `users`             (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_system_settings_updated_by`
    FOREIGN KEY (`updated_by`)          REFERENCES `users`             (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ============================================================
-- Views (no DEFINER - runs as current user)
-- ============================================================
DROP VIEW IF EXISTS `loan_applications_with_users`;
CREATE VIEW `loan_applications_with_users` AS
  SELECT la.*, u.full_name AS user_full_name, u.email AS user_email,
         u.id_number AS user_id_number, u.pf_number AS user_pf_number, u.role AS user_role
  FROM `loan_applications` la
  LEFT JOIN `users` u ON la.user_id = u.user_id
UNION
  SELECT la.*, u.full_name AS user_full_name, u.email AS user_email,
         u.id_number AS user_id_number, u.pf_number AS user_pf_number, u.role AS user_role
  FROM `loan_applications` la
  LEFT JOIN `users` u ON la.pf_number = u.pf_number
  WHERE (la.user_id IS NULL OR la.user_id = 0);

DROP VIEW IF EXISTS `v_active_loans_summary`;
CREATE VIEW `v_active_loans_summary` AS
  SELECT la.application_id, la.full_name, la.loan_amount, la.loan_type,
         la.status, la.application_date, la.dispatch_date,
         u.full_name AS applicant_name, u.email AS applicant_email,
         COUNT(p.payment_id) AS total_payments,
         COALESCE(SUM(p.amount), 0) AS total_paid,
         (la.loan_amount - COALESCE(SUM(p.amount), 0)) AS outstanding_balance
  FROM `loan_applications` la
  LEFT JOIN `users`    u ON la.user_id        = u.user_id
  LEFT JOIN `payments` p ON la.application_id = p.application_id
  WHERE la.status IN ('active','dispatched')
  GROUP BY la.application_id, la.full_name, la.loan_amount, la.loan_type,
           la.status, la.application_date, la.dispatch_date, u.full_name, u.email;

DROP VIEW IF EXISTS `v_all_users`;
CREATE VIEW `v_all_users` AS
  SELECT u.user_id, u.full_name, u.email, u.id_number, u.pf_number, u.role,
         u.is_active, u.can_apply_for_loan, u.created_at, u.updated_at,
         CASE
           WHEN u.role = 'admin'      THEN a.id
           WHEN u.role = 'accountant' THEN ac.id
           WHEN u.role = 'cashier'    THEN c.id
           WHEN u.role = 'loanee'     THEN l.id
         END AS role_table_id
  FROM `users` u
  LEFT JOIN `admins`      a  ON u.pf_number = a.pf_number  AND u.role = 'admin'
  LEFT JOIN `accountants` ac ON u.pf_number = ac.pf_number AND u.role = 'accountant'
  LEFT JOIN `cashiers`    c  ON u.pf_number = c.pf_number  AND u.role = 'cashier'
  LEFT JOIN `loanee`      l  ON u.pf_number = l.pf_number  AND u.role = 'loanee';

DROP VIEW IF EXISTS `v_loan_applications_full`;
CREATE VIEW `v_loan_applications_full` AS
  SELECT la.*, u.full_name AS user_full_name, u.email AS user_email, u.role AS user_role,
         u.pf_number AS user_pf_number, u.id_number AS user_id_number,
         ld.property_location AS detail_property_location,
         ld.property_value    AS detail_property_value,
         ld.car_model         AS detail_car_model,
         ld.car_value         AS detail_car_value
  FROM `loan_applications` la
  LEFT JOIN `users`       u  ON la.user_id        = u.user_id
  LEFT JOIN `loandetails` ld ON la.application_id = ld.application_id;

DROP VIEW IF EXISTS `v_unified_loans`;
CREATE VIEW `v_unified_loans` AS
  SELECT la.id, la.user_id, la.application_id, la.full_name, la.id_number,
         CAST(la.pf_number AS CHAR(50)) AS pf_number,
         la.email, la.loan_type, la.loan_amount, la.payment_duration,
         la.payment_frequency, la.basic_salary, la.employment_type,
         la.contract_period, la.contract_start_date, la.contract_end_date,
         la.property_location, la.property_value, la.car_type, la.car_value,
         la.bank_name, la.bank_branch, la.bank_account_number,
         la.status, la.application_date, la.dispatch_date, la.closed_date,
         la.feedback, la.cashier_processed_date, la.accountant_processed_date,
         la.created_at, la.updated_at,
         NULL AS financial_year, 'standard' AS record_type
  FROM `loan_applications` la
UNION ALL
  SELECT od.debtor_id AS id, od.created_by AS user_id,
         CONCAT('MIGRATED-', LPAD(od.debtor_id, 10, '0')) AS application_id,
         od.name AS full_name,
         COALESCE(od.id_number, 'LEGACY-IMPORT') AS id_number,
         COALESCE(od.pf_number, CONCAT('LEGACY-', LPAD(od.debtor_id, 6, '0'))) AS pf_number,
         NULL AS email, 'mortgage' AS loan_type, od.amount AS loan_amount,
         12 AS payment_duration, 'monthly' AS payment_frequency,
         0 AS basic_salary, 'permanent' AS employment_type,
         NULL AS contract_period, NULL AS contract_start_date, NULL AS contract_end_date,
         NULL AS property_location, NULL AS property_value,
         NULL AS car_type, NULL AS car_value, 'Family Bank' AS bank_name,
         '' AS bank_branch,
         CONCAT('LEGACY-', LPAD(od.debtor_id, 10, '0')) AS bank_account_number,
         COALESCE(od.status, 'active') AS status,
         od.created_at AS application_date, od.created_at AS dispatch_date,
         NULL AS closed_date,
         CONCAT('Migrated from Outstanding Debtors - FY: ', od.financial_year) AS feedback,
         NULL AS cashier_processed_date, NULL AS accountant_processed_date,
         od.created_at, od.updated_at, od.financial_year, 'legacy' AS record_type
  FROM `outstanding_debtors` od;

DROP VIEW IF EXISTS `v_users_missing_role_table`;
CREATE VIEW `v_users_missing_role_table` AS
  SELECT u.user_id, u.full_name, u.email, u.pf_number, u.role,
         CASE
           WHEN u.role = 'admin'      AND a.id  IS NULL THEN 'Missing from admins table'
           WHEN u.role = 'accountant' AND ac.id IS NULL THEN 'Missing from accountants table'
           WHEN u.role = 'cashier'    AND c.id  IS NULL THEN 'Missing from cashiers table'
           WHEN u.role = 'loanee'     AND l.id  IS NULL THEN 'Missing from loanee table'
           ELSE 'OK'
         END AS status
  FROM `users` u
  LEFT JOIN `admins`      a  ON u.pf_number = a.pf_number  AND u.role = 'admin'
  LEFT JOIN `accountants` ac ON u.pf_number = ac.pf_number AND u.role = 'accountant'
  LEFT JOIN `cashiers`    c  ON u.pf_number = c.pf_number  AND u.role = 'cashier'
  LEFT JOIN `loanee`      l  ON u.pf_number = l.pf_number  AND u.role = 'loanee'
  WHERE (u.role = 'admin'      AND a.id  IS NULL)
     OR (u.role = 'accountant' AND ac.id IS NULL)
     OR (u.role = 'cashier'    AND c.id  IS NULL)
     OR (u.role = 'loanee'     AND l.id  IS NULL);

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
