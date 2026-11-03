-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Dec 11, 2025 at 09:48 AM
-- Server version: 9.1.0
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `loan_management`
--

DELIMITER $$
--
-- Procedures
--
DROP PROCEDURE IF EXISTS `fix_payment_schedule_numbering`$$
CREATE DEFINER=`Harry`@`localhost` PROCEDURE `fix_payment_schedule_numbering` ()   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_loan_id INT;
    
    DECLARE cur CURSOR FOR
        SELECT DISTINCT loan_application_id 
        FROM payment_schedules 
        ORDER BY loan_application_id;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO v_loan_id;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Reset numbering starting from 1 for this loan
        SET @row_number = 0;
        
        UPDATE payment_schedules 
        SET payment_number = (@row_number := @row_number + 1)
        WHERE loan_application_id = v_loan_id
        ORDER BY due_date ASC;
        
    END LOOP;
    
    CLOSE cur;
END$$

DROP PROCEDURE IF EXISTS `generate_all_payment_schedules`$$
CREATE DEFINER=`Harry`@`localhost` PROCEDURE `generate_all_payment_schedules` ()   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_loan_id INT;
    DECLARE v_loan_amount DECIMAL(12,2);
    DECLARE v_payment_duration INT;
    DECLARE v_dispatch_date DATE;
    
    -- Cursor for all dispatched/active/closed loans without existing schedules
    DECLARE cur CURSOR FOR
        SELECT 
            id,
            loan_amount,
            payment_duration,
            DATE(dispatch_date) AS dispatch_date
        FROM loan_applications
        WHERE 
            status IN ('dispatched', 'active', 'closed')
            AND dispatch_date IS NOT NULL
            AND id NOT IN (
                SELECT DISTINCT loan_application_id 
                FROM payment_schedules
            );
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO v_loan_id, v_loan_amount, v_payment_duration, v_dispatch_date;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Call the existing procedure to generate schedule for this loan
        CALL sp_generate_payment_schedule(
            v_loan_id,
            v_loan_amount,
            v_payment_duration,
            v_dispatch_date
        );
        
    END LOOP;
    
    CLOSE cur;
    
END$$

DROP PROCEDURE IF EXISTS `sp_create_loan_application`$$
CREATE DEFINER=`Harry`@`localhost` PROCEDURE `sp_create_loan_application` (IN `p_application_id` VARCHAR(50), IN `p_user_id` INT, IN `p_full_name` VARCHAR(100), IN `p_id_number` VARCHAR(50), IN `p_pf_number` BIGINT, IN `p_email` VARCHAR(100), IN `p_loan_type` ENUM('mortgage','car'), IN `p_loan_amount` DECIMAL(12,2), IN `p_payment_duration` INT, IN `p_basic_salary` DECIMAL(12,2), IN `p_employment_type` ENUM('permanent','contracted'), IN `p_bank_name` ENUM('Family Bank'), IN `p_bank_branch` VARCHAR(100), IN `p_bank_account_number` VARCHAR(50))   BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Validate loan amount against limits
    IF p_loan_amount <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Loan amount must be greater than 0';
    END IF;
    
    IF p_payment_duration <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment duration must be greater than 0';
    END IF;
    
    -- Insert loan application
    INSERT INTO loan_applications (
        application_id, user_id, full_name, id_number, pf_number, email,
        loan_type, loan_amount, payment_duration, basic_salary,
        employment_type, bank_name, bank_branch, bank_account_number
    ) VALUES (
        p_application_id, p_user_id, p_full_name, p_id_number, p_pf_number, p_email,
        p_loan_type, p_loan_amount, p_payment_duration, p_basic_salary,
        p_employment_type, p_bank_name, p_bank_branch, p_bank_account_number
    );
    
    COMMIT;
END$$

DROP PROCEDURE IF EXISTS `sp_generate_payment_schedule`$$
CREATE DEFINER=`Harry`@`localhost` PROCEDURE `sp_generate_payment_schedule` (IN `p_loan_id` INT, IN `p_loan_amount` DECIMAL(12,2), IN `p_payment_duration` INT, IN `p_dispatch_date` DATE)   BEGIN
    DECLARE v_rate DECIMAL(5,4) DEFAULT 3.0;
    DECLARE v_monthly_rate DECIMAL(10,8);
    DECLARE v_months INT;
    DECLARE v_payment DECIMAL(12,2);
    DECLARE v_balance DECIMAL(12,2);
    DECLARE v_interest DECIMAL(12,2);
    DECLARE v_principal DECIMAL(12,2);
    DECLARE v_i INT DEFAULT 1;
    DECLARE v_due_date DATE;

    -- Get current rate
    SELECT CAST(value AS DECIMAL(5,4)) INTO v_rate
    FROM system_settings WHERE setting_key = 'amortization_rate' LIMIT 1;

    SET v_monthly_rate = v_rate / 100 / 12;
    SET v_months = p_payment_duration * 12;

    -- Calculate exact monthly payment
    SET v_payment = ROUND(
        p_loan_amount * (v_monthly_rate * POW(1 + v_monthly_rate, v_months)) /
        (POW(1 + v_monthly_rate, v_months) - 1), 2
    );

    SET v_balance = p_loan_amount;
    SET v_due_date = DATE_ADD(p_dispatch_date, INTERVAL 1 MONTH);

    -- Clear old data
    DELETE FROM payment_schedules WHERE loan_application_id = p_loan_id;

    -- Generate 120 perfect rows
    WHILE v_i <= v_months DO
        SET v_interest = ROUND(v_balance * v_monthly_rate, 2);
        SET v_principal = LEAST(v_payment - v_interest, v_balance);

        INSERT INTO payment_schedules (
            loan_application_id,
            payment_number,
            due_date,
            expected_amount,
            expected_principal,
            expected_interest,
            expected_balance,
            status
        ) VALUES (
            p_loan_id,
            v_i,
            DATE_ADD(v_due_date, INTERVAL (v_i - 1) MONTH),
            IF(v_i = v_months, ROUND(v_balance + v_interest, 2), v_payment),
            GREATEST(0, v_principal),
            GREATEST(0, v_interest),
            GREATEST(0, ROUND(v_balance - v_principal, 2)),
            'pending'
        );

        SET v_balance = GREATEST(0, v_balance - v_principal);
        SET v_i = v_i + 1;
    END WHILE;

    -- Force last payment to zero balance exactly
    UPDATE payment_schedules
    SET expected_amount = expected_balance + expected_interest,
        expected_principal = expected_balance,
        expected_balance = 0
    WHERE loan_application_id = p_loan_id
      AND payment_number = v_months;

END$$

DROP PROCEDURE IF EXISTS `sp_update_loan_status`$$
CREATE DEFINER=`Harry`@`localhost` PROCEDURE `sp_update_loan_status` (IN `p_application_id` VARCHAR(50), IN `p_new_status` ENUM('pending','forwarded','accepted','dispatched','active','rejected','returned','closed'), IN `p_feedback` TEXT, IN `p_updated_by` INT)   BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Update loan status
    UPDATE loan_applications 
    SET status = p_new_status,
        feedback = p_feedback,
        updated_at = CURRENT_TIMESTAMP
    WHERE application_id = p_application_id;
    
    -- Log the status change
    INSERT INTO activity_log (admin_id, action, details)
    VALUES (p_updated_by, 'UPDATE_LOAN_STATUS', 
            CONCAT('Updated loan ', p_application_id, ' to status: ', p_new_status));
    
    COMMIT;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `accountants`
--

DROP TABLE IF EXISTS `accountants`;
CREATE TABLE IF NOT EXISTS `accountants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pf_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('accountant') DEFAULT 'accountant',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pf_number` (`pf_number`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `accountants`
--

INSERT INTO `accountants` (`id`, `pf_number`, `full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '20250000002', 'David Muimi', 'davidmuimi3@gmail.com', '$2a$12$XYEtr0sHmNxBE60X6IbpqOmKHcLQXxGddkdfpkwl4VAxSKVnuhEeC', 'accountant', 1, '2025-11-04 11:50:06', '2025-11-28 09:26:01'),
(2, '20250000004', 'Brian Ngari', 'brianngari3@gmail.com', '$2y$10$oqOYkGyerIG1qnCdx8lLn.ZUxrtBd4QFHfumZ2uW47j7.tTVz5TQq', 'accountant', 1, '2025-11-17 06:59:29', '2025-12-02 11:30:24');

-- --------------------------------------------------------

--
-- Table structure for table `accountant_decisions`
--

DROP TABLE IF EXISTS `accountant_decisions`;
CREATE TABLE IF NOT EXISTS `accountant_decisions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_application_id` int NOT NULL,
  `accountant_id` int NOT NULL,
  `decision` enum('accepted','rejected','dispatched') NOT NULL,
  `feedback` text,
  `processed_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loan_application_id` (`loan_application_id`),
  KEY `accountant_id` (`accountant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` int DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `details` text,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_activity_log_admin` (`admin_id`)
) ENGINE=MyISAM AUTO_INCREMENT=217 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `admin_id`, `action`, `details`, `timestamp`) VALUES
(29, 1, 'logout', 'User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) logged out from IP: ::1', '2025-11-21 08:23:40'),
(30, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1, User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 11:21:27'),
(31, 1, 'logout', 'User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) logged out from IP: ::1', '2025-11-24 06:17:30'),
(32, 1, 'logout', 'User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) logged out from IP: ::1', '2025-11-24 07:46:05'),
(33, 1, 'logout', 'User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) logged out from IP: ::1', '2025-11-24 09:04:41'),
(34, 1, 'logout', 'User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) logged out from IP: ::1', '2025-11-25 07:21:39'),
(35, 4, 'logout', 'User \'Zion Harrison\' (Role: Loanee, Email: zionharry191@gmail.com) logged out from IP: ::1', '2025-11-25 07:25:35'),
(36, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1, User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 07:25:48'),
(37, 1, 'logout', 'User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) logged out from IP: ::1', '2025-11-25 07:29:16'),
(38, 1, 'logout', 'User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) logged out from IP: ::1', '2025-11-25 07:29:28'),
(39, 1, 'logout', 'User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) logged out from IP: ::1', '2025-11-25 07:32:21'),
(40, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-25 07:44:27'),
(41, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-25 07:44:35'),
(42, 1, 'update_setting', 'Updated setting: amortization_rate to 3', '2025-11-25 09:45:01'),
(43, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-25 10:26:25'),
(44, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-25 10:32:15'),
(45, 1, 'update_setting', 'Updated setting: amortization_rate to 3', '2025-11-26 06:25:04'),
(46, 1, 'update_setting', 'Updated setting: amortization_rate to 3', '2025-11-26 06:25:05'),
(47, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-26 06:38:22'),
(48, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-26 06:58:08'),
(49, 1, 'change_password', 'User Shawn Muthomi changed their password', '2025-11-26 06:58:38'),
(50, 1, 'change_password', 'User Shawn Muthomi changed their password', '2025-11-26 07:00:26'),
(51, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-26 14:40:56'),
(52, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-28 12:41:27'),
(53, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-28 13:49:15'),
(54, 1, 'update_setting', 'Updated setting: amortization_rate to 3', '2025-11-28 14:04:16'),
(55, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-11-28 14:13:51'),
(56, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-01 08:49:46'),
(57, 1, 'toggle_user_status', 'Admin Shawn Muthomi deactivated user: Carson (bencarson2@gmail.com)', '2025-12-01 10:43:37'),
(58, 1, 'toggle_user_status', 'Admin Shawn Muthomi activated user: Carson (bencarson2@gmail.com)', '2025-12-01 10:43:39'),
(59, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-02 07:37:46'),
(60, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-02 11:23:35'),
(61, 1, 'toggle_user_status', 'Admin Shawn Muthomi deactivated user: Brian Ngari (brianngari3@gmail.com)', '2025-12-02 11:29:59'),
(62, 1, 'toggle_user_status', 'Admin Shawn Muthomi activated user: Brian Ngari (brianngari3@gmail.com)', '2025-12-02 11:30:02'),
(63, 1, 'toggle_user_status', 'Admin Shawn Muthomi deactivated user: Brian Ngari (brianngari3@gmail.com)', '2025-12-02 11:30:18'),
(64, 1, 'toggle_user_status', 'Admin Shawn Muthomi activated user: Brian Ngari (brianngari3@gmail.com)', '2025-12-02 11:30:24'),
(65, 1, 'toggle_user_status', 'Admin Shawn Muthomi activated user: Antony Bundi (anto@gmail.com)', '2025-12-02 11:43:20'),
(66, 1, 'toggle_user_status', 'Admin Shawn Muthomi deactivated user: Antony Bundi (anto@gmail.com)', '2025-12-02 14:07:15'),
(67, 1, 'toggle_user_status', 'Admin Shawn Muthomi deactivated user: Antony Bundi (anto@gmail.com)', '2025-12-02 14:07:15'),
(68, 1, 'toggle_user_status', 'Admin Shawn Muthomi activated user: Antony Bundi (anto@gmail.com)', '2025-12-02 14:07:18'),
(69, 1, 'toggle_user_status', 'Admin Shawn Muthomi activated user: Antony Bundi (anto@gmail.com)', '2025-12-02 14:07:19'),
(70, 1, 'toggle_user_status', 'Admin Shawn Muthomi deactivated user: Antony Bundi (anto@gmail.com)', '2025-12-02 14:07:45'),
(71, 1, 'toggle_user_status', 'Admin Shawn Muthomi deactivated user: Antony Bundi (anto@gmail.com)', '2025-12-02 14:07:46'),
(72, 1, 'toggle_user_status', 'Admin Shawn Muthomi activated user: Antony Bundi (anto@gmail.com)', '2025-12-02 14:07:50'),
(73, 1, 'toggle_user_status', 'Admin Shawn Muthomi activated user: Antony Bundi (anto@gmail.com)', '2025-12-02 14:07:50'),
(74, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-02 14:08:46'),
(75, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-03 07:15:19'),
(76, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-03 07:15:19'),
(77, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-03 07:15:32'),
(78, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-03 07:15:32'),
(79, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-03 07:15:44'),
(80, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-03 07:15:44'),
(81, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-03 07:15:51'),
(82, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-03 07:15:51'),
(83, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-03 09:17:41'),
(84, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-03 09:17:41'),
(85, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-03 09:18:14'),
(86, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-03 09:18:14'),
(87, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-03 13:48:43'),
(88, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-03 13:49:34'),
(89, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-03 13:49:43'),
(90, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-03 13:49:57'),
(91, 2, 'switch_to_loanee_portal', 'Staff Carson switched to loanee portal', '2025-12-03 14:29:20'),
(92, 2, 'switch_to_loanee_portal', 'Staff Carson switched to loanee portal', '2025-12-03 14:34:31'),
(93, 2, 'switch_to_loanee_portal', 'Staff Carson switched to loanee portal', '2025-12-03 14:34:41'),
(94, 2, 'switch_to_loanee_portal', 'Staff Carson switched to loanee portal', '2025-12-04 06:23:38'),
(95, 3, 'return_from_loanee', 'Returned from loanee portal to accountant portal', '2025-12-04 11:04:25'),
(96, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-04 11:05:13'),
(97, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-04 11:05:13'),
(98, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-04 11:05:13'),
(99, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-04 11:05:14'),
(100, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-04 11:22:23'),
(101, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-04 11:33:20'),
(102, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-04 11:35:42'),
(103, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-04 11:37:26'),
(104, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-04 11:37:31'),
(105, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-04 11:37:34'),
(106, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-04 11:37:45'),
(107, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-04 11:37:49'),
(108, 1, 'return_from_loanee', 'Returned from loanee portal to accountant portal', '2025-12-04 12:13:27'),
(109, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-04 12:39:25'),
(110, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-04 12:39:28'),
(111, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-04 12:43:08'),
(112, 3, 'return_from_loanee', 'Returned from loanee portal to accountant portal', '2025-12-05 06:40:15'),
(113, 3, 'switch_to_loanee_portal', 'Staff David Muimi switched to loanee portal', '2025-12-05 06:40:19'),
(114, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-05 08:10:24'),
(115, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-05 09:01:29'),
(116, 4, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-05 09:18:30'),
(117, 1, 'return_from_loanee', 'Returned from loanee portal to accountant portal', '2025-12-05 10:28:09'),
(118, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-05 10:28:13'),
(119, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-05 10:57:42'),
(120, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-05 10:57:50'),
(121, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-05 10:57:55'),
(122, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-05 10:57:59'),
(123, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-05 10:58:03'),
(124, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-05 11:01:51'),
(125, 3, 'return_from_loanee', 'Returned from loanee portal to accountant portal', '2025-12-05 11:03:50'),
(126, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-05 12:03:46'),
(127, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-05 12:03:46'),
(128, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-05 12:22:32'),
(129, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-05 12:39:01'),
(130, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-05 12:39:01'),
(131, 1, 'update_setting', 'Updated setting: amortization_rate to 3', '2025-12-05 12:40:41'),
(132, 1, 'update_setting', 'Updated setting: amortization_rate to 3', '2025-12-05 12:40:41'),
(133, 1, 'update_setting', 'Updated setting: amortization_rate to 3', '2025-12-05 12:40:43'),
(134, 1, 'update_setting', 'Updated setting: amortization_rate to 3', '2025-12-05 12:40:43'),
(135, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:46:11'),
(136, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:46:11'),
(137, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 9', '2025-12-05 12:46:15'),
(138, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 9', '2025-12-05 12:46:15'),
(139, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:46:31'),
(140, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:46:31'),
(141, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:48:21'),
(142, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:48:21'),
(143, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:49:41'),
(144, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:49:42'),
(145, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:49:45'),
(146, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 10', '2025-12-05 12:49:46'),
(147, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-05 13:08:21'),
(148, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-05 13:08:21'),
(149, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-05 13:10:02'),
(150, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-05 13:10:02'),
(151, 1, 'backup_database', 'Admin performed full database backup', '2025-12-05 13:59:11'),
(152, 1, 'backup_database', 'Admin performed full database backup', '2025-12-05 13:59:13'),
(153, 1, 'backup_database', 'Admin performed full database backup', '2025-12-05 13:59:38'),
(154, 1, 'backup_database', 'Admin performed full database backup', '2025-12-05 14:07:45'),
(155, 1, 'backup_database', 'Admin performed full database backup', '2025-12-05 14:07:47'),
(156, 1, 'backup_database', 'Admin performed full database backup', '2025-12-05 14:08:28'),
(157, 1, 'backup_database', 'Admin performed full database backup', '2025-12-05 14:08:29'),
(158, 1, 'backup_database', 'Backup downloaded', '2025-12-05 14:19:53'),
(159, 1, 'restore_backup', 'Full system restore performed', '2025-12-05 14:28:40'),
(160, 1, 'restore_backup', 'Full system restore performed', '2025-12-05 14:29:24'),
(161, 1, 'restore_backup', 'Full system restore performed', '2025-12-05 14:34:26'),
(162, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-08 06:00:35'),
(163, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-08 06:00:35'),
(164, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-08 06:00:47'),
(165, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-08 06:00:47'),
(166, 1, 'restore_backup', 'Full system restore performed', '2025-12-08 06:01:58'),
(167, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-08 06:02:22'),
(168, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-08 06:03:18'),
(169, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-08 06:03:22'),
(170, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-08 06:03:26'),
(171, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-08 06:03:28'),
(172, 1, 'switch_to_loanee_portal', 'Staff Shawn Muthomi switched to loanee portal', '2025-12-08 06:21:50'),
(173, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-08 07:07:37'),
(174, 2, 'return_from_loanee', 'Returned from loanee portal to accountant portal', '2025-12-08 08:16:25'),
(175, 3, 'return_from_loanee', 'Returned from loanee portal to accountant portal', '2025-12-08 08:16:57'),
(176, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-08 08:17:31'),
(177, 1, 'restore_backup', 'Full system restore performed', '2025-12-08 08:18:04'),
(178, 1, 'restore_backup', 'Full system restore performed', '2025-12-08 08:53:17'),
(179, 1, 'restore_backup', 'Full system restore performed', '2025-12-08 09:02:29'),
(180, 1, 'restore_backup', 'Full system restore performed', '2025-12-08 09:04:03'),
(181, 1, 'restore_backup', 'Full system restore performed', '2025-12-08 09:19:10'),
(182, 1, 'backup_database', 'Backup downloaded', '2025-12-08 09:29:49'),
(183, 1, 'backup_database', 'Backup downloaded', '2025-12-08 09:35:48'),
(184, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-08 09:46:34'),
(185, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-08 09:46:34'),
(186, 1, 'backup_database', 'Backup downloaded', '2025-12-08 09:46:38'),
(187, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-08 10:56:57'),
(188, 1, 'backup_database', 'Backup downloaded', '2025-12-08 11:04:25'),
(189, 1, 'restore_backup', 'Full system restore performed', '2025-12-08 11:17:25'),
(190, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 11', '2025-12-08 11:42:09'),
(191, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 11', '2025-12-08 11:42:17'),
(192, 1, 'restore_backup', 'Restored 12 tables', '2025-12-08 11:42:47'),
(193, 1, 'restore_backup', 'Restored 12 tables', '2025-12-08 11:43:27'),
(194, 1, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-08 11:44:09'),
(195, 1, 'restore_backup', 'Restored 12 tables', '2025-12-08 11:46:56'),
(196, 1, 'backup_database', 'Full backup downloaded', '2025-12-08 11:54:20'),
(197, 1, 'backup_database', 'Full backup downloaded', '2025-12-08 12:02:10'),
(198, 1, 'restore_backup', 'Restored 9 tables (3 errors)', '2025-12-08 12:06:48'),
(199, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 11', '2025-12-08 12:07:07'),
(200, 4, 'api_logout', 'API Logout: User \'Zion Harrison\' (Role: Loanee, Email: zionharry191@gmail.com) from IP: ::1', '2025-12-09 06:25:23'),
(201, 2, 'api_logout', 'API Logout: User \'Carson\' (Role: Cashier, Email: bencarson2@gmail.com) from IP: ::1', '2025-12-09 06:26:09'),
(202, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-09 06:33:43'),
(203, 1, 'update_setting', 'Updated setting: user_theme_preference to dark', '2025-12-09 06:33:44'),
(204, 1, 'update_setting', 'Updated setting: user_theme_preference to light', '2025-12-09 06:33:44'),
(205, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 11', '2025-12-09 06:39:58'),
(206, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 11', '2025-12-09 06:40:09'),
(207, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 11', '2025-12-09 06:40:21'),
(208, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 11', '2025-12-09 06:40:24'),
(209, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 11', '2025-12-09 06:40:27'),
(210, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 4', '2025-12-09 06:40:33'),
(211, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-09 06:40:38'),
(212, 1, 'update_user', 'Admin Shawn Muthomi updated user ID: 4', '2025-12-09 06:41:15'),
(213, 3, 'api_logout', 'API Logout: User \'David Muimi\' (Role: Accountant, Email: davidmuimi3@gmail.com) from IP: ::1', '2025-12-09 10:03:35'),
(214, 1, 'api_logout', 'API Logout: User \'Shawn Muthomi\' (Role: Admin, Email: shawnkaboi3@gmail.com) from IP: ::1', '2025-12-10 11:11:45'),
(215, 3, 'return_from_loanee', 'User returned from loanee portal to admin portal', '2025-12-10 11:15:09'),
(216, 2, 'api_logout', 'API Logout: User \'Carson\' (Role: Cashier, Email: bencarson2@gmail.com) from IP: ::1', '2025-12-10 13:02:17');

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pf_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin') DEFAULT 'admin',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pf_number` (`pf_number`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `pf_number`, `full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '10987654321', 'Shawn Muthomi', 'shawnkaboi3@gmail.com', '$2y$10$LVCV0MKZD0cA0doMHt1dy.egzbFyKwzP6.fvlVB3BlQfFUyBw2SFm', 'admin', 1, '2025-11-03 12:26:46', '2025-11-28 09:26:01');

-- --------------------------------------------------------

--
-- Table structure for table `admin_logs`
--

DROP TABLE IF EXISTS `admin_logs`;
CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` int NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_admin_logs_admin` (`admin_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admin_logs`
--

INSERT INTO `admin_logs` (`id`, `admin_id`, `action`, `details`, `timestamp`) VALUES
(1, 1, 'CLEAR_LOGS', 'All admin logs cleared', '2025-11-04 11:52:09');

-- --------------------------------------------------------

--
-- Table structure for table `api_keys`
--

DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `api_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_secret` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `user_id` int DEFAULT NULL,
  `permissions` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `usage_count` int DEFAULT '0',
  `last_used` datetime DEFAULT NULL,
  `last_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `idx_api_key` (`api_key`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `api_keys`
--

INSERT INTO `api_keys` (`id`, `api_key`, `api_secret`, `name`, `description`, `user_id`, `permissions`, `is_active`, `usage_count`, `last_used`, `last_ip`, `expires_at`, `created_at`, `revoked_at`, `revoked_by`) VALUES
(1, 'lsk_0d6ab8e6032f6289a3f701dc550f3c321c2c93f7', '$argon2id$v=19$m=65536,t=4,p=3$eS9PY283dFdxbEprcnUuag$PUjuPmr6TItyRh0PoGMpjyWovnlwXooJzyer39Q93VM', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 13:54:56', NULL, NULL),
(2, 'lsk_9f6b7559a7bff4d9f74d44fa0a3b76618bb6fefc', '$argon2id$v=19$m=65536,t=4,p=3$RFFoU0hya05EZHJ5RDdScA$xi5B1wN52KF/lPnI17d++O8tVCmugbroVGdXPYDmNnM', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:37:18', NULL, NULL),
(3, 'lsk_c898f5ee08577301649984efe1fe1d3a13915aa2', '$argon2id$v=19$m=65536,t=4,p=3$c2V0ZDEwak9KUy5yNW5ieQ$r//30t5yU9g5MRXekgDcvpApew08IFlG97BuYfRzk7A', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:37:31', NULL, NULL),
(4, 'lsk_984ea954233786cee894c97e49f3d277f1d7507d', '$argon2id$v=19$m=65536,t=4,p=3$YjNIc09IdTBSY2tNYUhjWg$eJjvalQKJO1X2O+vaVcDToh6o09/rnzgRz5pdq+AtEc', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:37:47', NULL, NULL),
(5, 'lsk_3173b8205ab8b52340fd954286ea02065eec6974', '$argon2id$v=19$m=65536,t=4,p=3$ZjdaTS92MFdmNldvcGMvNg$BSQpTwBFi4SMBC+B3Gcbvgg1dZgXZMNMGY5E/ziJhwU', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:40:21', NULL, NULL),
(6, 'lsk_3da22f3f23aaf3fa7b96ca212394202ac97f622e', '$argon2id$v=19$m=65536,t=4,p=3$blEuRjFEb3U1ZWFhU1Zjeg$0zXlJT0ulpcLpwSJ2FF1YqIHHY4qEViNR52BnI3/V7c', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:40:36', NULL, NULL),
(7, 'lsk_783404232cdad621fe00fb4abbceb8615008f66e', '$argon2id$v=19$m=65536,t=4,p=3$bDFrVmYwRFBydEZRbm9WeA$j2XQrcFgFJwt8hg992bd9rP9q1FhwfIR1NjvWwuflxc', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:41:07', NULL, NULL),
(8, 'lsk_bfc14c11820fa27f34109ee04e451cf767443061', '$argon2id$v=19$m=65536,t=4,p=3$MmsuL1pPNUc1d3MwTzM0WA$vm6Hn5DdFq/tm7W672uqD1cFgP4maiVeTtq4UiMQSMw', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:41:11', NULL, NULL),
(9, 'lsk_948b23787a86f10e71b2dab5bdac81db6104f97d', '$argon2id$v=19$m=65536,t=4,p=3$eVBzdHFKVGRudGVTZ1plQg$2mfHZ7dXwhVcinn/At+rJJ+ZRJ5sMNPDAWbW9cY66LA', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:47:28', NULL, NULL),
(10, 'lsk_a558867940957e52aa3b1dfde7ea496f9a410fa5', '$argon2id$v=19$m=65536,t=4,p=3$VGVBYW9yUFZGRkFlWUJOQw$sM5NzV4xPMrgAL++1kvJcEErM7K7LAqFMoueh5R7rBc', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:47:29', NULL, NULL),
(11, 'lsk_2d7edb9b3a43eb407ba9e1bf1e038e36908e6978', '$argon2id$v=19$m=65536,t=4,p=3$VGpEcnhwa3lKbGY1MlNXNg$8pRWfrMd4TKoOQY9JitaYdI2eWACUi5VsAD6sO+IBR0', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-25 14:49:28', NULL, NULL),
(12, 'lsk_caa23acf1ddbd446704fd49da0d7783929e71783', '$argon2id$v=19$m=65536,t=4,p=3$VS95V2d2b2VOcVJCRExpcg$n9JrndkhAs59Visgn27odjnWeinOm0P1dgaFxyxcZo8', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:16:10', NULL, NULL),
(13, 'lsk_6bab45d0982f66f94857a63c8a8d1ae120376460', '$argon2id$v=19$m=65536,t=4,p=3$TVpBdVBBbXliWk1zaGtBQg$1y013E56iE+dlafqSczIwkvWhG+X2Z5dH9qgGOp73W0', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:18:01', NULL, NULL),
(14, 'lsk_b031f4a103c6e97862b5cb77b0db1a74a51674df', '$argon2id$v=19$m=65536,t=4,p=3$SS5sMTNDbUZhMjY4NnBEQw$TMQ+Fusv+W7C567r6GWer516dRWuaDWOW4dWEq46r9I', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:18:03', NULL, NULL),
(15, 'lsk_e3a9825ff85aff205569110eeb3f7488db1f5ec1', '$argon2id$v=19$m=65536,t=4,p=3$MG5UZXNBUVNYYjNQSXdmTg$D9TKmC0krfO3RMzyXIdNh7TO2yZjmXtiXoMsvEzYeEA', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:18:04', NULL, NULL),
(16, 'lsk_183f3a92ad56d3cc7d79c38f713b5d7a5c9d71c1', '$argon2id$v=19$m=65536,t=4,p=3$R0tGNUtxNVBTcUljV3E5eQ$rM7V4u2O5m4bLjMNfBVtNzHs6h94z+/V4JoXPTdJtIM', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:18:05', NULL, NULL),
(17, 'lsk_c0d0d2f582da2c5f98ddd2277c7d313a0f438dcc', '$argon2id$v=19$m=65536,t=4,p=3$aUpHZjFDdDZJTjU0cVl0NQ$Lx9xQjBCMPr/5mDmaUUX3SqR58jI9QkhpR6aOsWNWdo', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:18:08', NULL, NULL),
(18, 'lsk_bd4fc309d45b70df4b36656a0b96177157151dca', '$argon2id$v=19$m=65536,t=4,p=3$djVWQlRDc3FXYkw1TW9Xcg$mM7/YZzWf6d0QrMwLFK3iEc/RgWEoARJoyRejLaE1AU', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:18:11', NULL, NULL),
(19, 'lsk_c05de124dc2174c2ca8ab178e4f06041b5772994', '$argon2id$v=19$m=65536,t=4,p=3$eUNFQm1IYWZzQ1dHS25kSg$T8NxkVCL4MPWFtmD7Vkfe+s5uFswqt0aZWliWgze1i0', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:18:51', NULL, NULL),
(20, 'lsk_ec24cf793d7f993b0d0fab645dc13724ac030e61', '$argon2id$v=19$m=65536,t=4,p=3$WlM5blZ1T0J3ZFhLWjB5Qw$ApXX1D3qxo/YXS5S4USJz1gEsHUljDjdHO8AbbLiCOQ', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:19:09', NULL, NULL),
(21, 'lsk_ac40bb42b7e2064ffd81cd3c35951ec7f2cf1498', '$argon2id$v=19$m=65536,t=4,p=3$Skx2a0J6OWkuV3FhdHQ1Sg$vryGGliasV8VjlRbKYt7Q8uGwahWipaEhmZRPNMj9oE', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:19:21', NULL, NULL),
(22, 'lsk_aad75940c70b81ccdb552d1f81903889f4e65f1b', '$argon2id$v=19$m=65536,t=4,p=3$RWVsV3UyTnFKVkNSSE5tWQ$BPykXVPJl8vd9/II9D2B7bMNcJ+QDF8ZlJgzc+uBqlI', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:20:04', NULL, NULL),
(23, 'lsk_6efdd4f09a0f8eda1203fa831e206d0cc15e6567', '$argon2id$v=19$m=65536,t=4,p=3$TnY3SGxtOUlhNjd5SWMxQw$3gL0iUjiW5g3J14ZwpJcEmnIG5lC7mkhj/LYfPxR8Ck', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:20:56', NULL, NULL),
(24, 'lsk_9986d41dc2a23e99603e54dd64cd7d548bdb409c', '$argon2id$v=19$m=65536,t=4,p=3$R041cXI3dE81WXg5Z1p1Ug$BBgotbyinES7ujVQoNlQj3PZuON5qaV1wwUxm6vtVIc', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:21:11', NULL, NULL),
(25, 'lsk_20f811961993ead96842fa363d40e3f46d1dde2f', '$argon2id$v=19$m=65536,t=4,p=3$cnhQV2hSeXBhOGtLSElJTw$mNwiQp2Ijsbib2TUPFqx6IPr+Jk/BlwgCpZuSjAo+1g', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:21:12', NULL, NULL),
(26, 'lsk_e69c5e0145cc2f659014b3b5d98511a274b5fcd0', '$argon2id$v=19$m=65536,t=4,p=3$ZGtlVWVVYjJGOTJaWHE0aQ$kAYtTumvZy3hXEP7tQYACiAmovwm2Vwhg81tiArNhrE', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:21:12', NULL, NULL),
(27, 'lsk_5f2297aba6fd25f1aa25b522ca735b187ebcd1b1', '$argon2id$v=19$m=65536,t=4,p=3$UGtpMXRJYXFjLzI2ZnJiSg$e+/devQ2/Nv3aDS/NzxHs1q7BSDzs4pfJLks9qh+NU8', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:21:13', NULL, NULL),
(28, 'lsk_dcf96a8cb994e4cc551ea212702e524acf50c7a3', '$argon2id$v=19$m=65536,t=4,p=3$VG9HM1Nsb1NhdUxWU215cQ$iNY+jpy9If1oFcIB7YrTkcXCj5+FouyEBf0ReIU04CA', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 10:21:50', NULL, NULL),
(29, 'lsk_10a6528b8cc5249a9709471f871588cb7bf63dc3', '$argon2id$v=19$m=65536,t=4,p=3$MVpWcUpicXMzVWFtc0ZUNA$LhM9S+PDIuYkBK5fZXAjZoo1Ag24kPXkV1D7pEzAVtk', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 11:27:01', NULL, NULL),
(30, 'lsk_0cfddd530e231669788d1fd85aaa174841c00bcb', '$argon2id$v=19$m=65536,t=4,p=3$NGxCUExDTE1nUGFWQUpNSA$CrZ/R77a3CBkwDDmj1CNgyR+YOLETH1Xjj8Gw3nqn0Y', 'Loan System Frontend', 'Main API key for the loan application system', NULL, '[\"read\", \"write\"]', 1, 0, NULL, NULL, NULL, '2025-11-26 11:27:01', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `api_key_logs`
--

DROP TABLE IF EXISTS `api_key_logs`;
CREATE TABLE IF NOT EXISTS `api_key_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `api_key_id` int NOT NULL,
  `endpoint` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `status_code` int DEFAULT NULL,
  `request_time` datetime NOT NULL,
  `response_time_ms` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_api_key_id` (`api_key_id`),
  KEY `idx_request_time` (`request_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cashiers`
--

DROP TABLE IF EXISTS `cashiers`;
CREATE TABLE IF NOT EXISTS `cashiers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pf_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('cashier') DEFAULT 'cashier',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pf_number` (`pf_number`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `cashiers`
--

INSERT INTO `cashiers` (`id`, `pf_number`, `full_name`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '20250000001', 'Carson', 'bencarson2@gmail.com', '$2a$12$.3d8rxNUqCdaCneTQbXfe.EwqyKBfTCNUJ9MzRZcvZfKmSbm.P4H2', 'cashier', 1, '2025-11-04 07:45:53', '2025-12-01 10:43:39'),
(2, '20250000003', 'Jacob Munene', 'jacob@gmail.com', '$2y$10$gxuBrcxUMjnsx1NBihBIeOKE5JspOKRA3VkrY742MhFuWhEXcPswK', 'cashier', 1, '2025-11-10 09:41:43', '2025-11-28 09:26:01');

-- --------------------------------------------------------

--
-- Table structure for table `cashier_notifications`
--

DROP TABLE IF EXISTS `cashier_notifications`;
CREATE TABLE IF NOT EXISTS `cashier_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cashier_id` int NOT NULL,
  `loan_application_id` int DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cashier_id` (`cashier_id`),
  KEY `loan_application_id` (`loan_application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `funding_accounts`
--

DROP TABLE IF EXISTS `funding_accounts`;
CREATE TABLE IF NOT EXISTS `funding_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `funding_accounts`
--

INSERT INTO `funding_accounts` (`id`, `name`, `balance`, `created_at`, `updated_at`) VALUES
(1, 'Main Fund 1', 98700000.00, '2025-11-04 07:44:17', '2025-11-28 12:24:36'),
(2, 'Main Fund 2', 110052000.00, '2025-11-04 11:52:53', '2025-11-25 08:19:36'),
(7, 'Collections Fund', 597000.00, '2025-11-25 08:35:41', '2025-11-26 07:03:33');

-- --------------------------------------------------------

--
-- Table structure for table `funding_transactions`
--

DROP TABLE IF EXISTS `funding_transactions`;
CREATE TABLE IF NOT EXISTS `funding_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `funding_account_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transaction_type` enum('withdrawal','deposit','refund') NOT NULL DEFAULT 'withdrawal',
  `reference` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_funding_account_id` (`funding_account_id`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Logs all financial transactions from funding accounts';

--
-- Dumping data for table `funding_transactions`
--

INSERT INTO `funding_transactions` (`id`, `funding_account_id`, `amount`, `transaction_type`, `reference`, `created_by`, `created_at`) VALUES
(1, 2, 1200000.00, 'withdrawal', 'Loan dispatch for application ID: 11', 3, '2025-11-20 10:20:14'),
(4, 2, 52000.00, 'deposit', 'Loan repayment - Application: 2025112115025555369418, Payment ID: 14', 2, '2025-11-25 08:19:36'),
(6, 7, 7000.00, 'deposit', 'Loan repayment collection - Application: 2025112115025555369418, Payment ID: 15', 2, '2025-11-25 08:35:41'),
(7, 7, 60000.00, 'deposit', 'Loan repayment collection - Application: 2025112013091774145738, Payment ID: 16', 2, '2025-11-25 12:11:34'),
(8, 7, 530000.00, 'deposit', 'Loan repayment collection - Application: 2025112013091774145738, Payment ID: 17', 2, '2025-11-26 07:03:33'),
(9, 1, 800000.00, 'withdrawal', 'Loan dispatch for application ID: 20', 3, '2025-11-28 12:24:36');

-- --------------------------------------------------------

--
-- Table structure for table `loandetails`
--

DROP TABLE IF EXISTS `loandetails`;
CREATE TABLE IF NOT EXISTS `loandetails` (
  `detail_id` int NOT NULL AUTO_INCREMENT,
  `application_id` varchar(20) NOT NULL,
  `property_location` varchar(255) DEFAULT NULL,
  `property_value` decimal(12,2) DEFAULT NULL,
  `car_model` varchar(100) DEFAULT NULL,
  `car_value` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`detail_id`),
  KEY `fk_loandetails_application_id` (`application_id`)
) ENGINE=MyISAM AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `loandetails`
--

INSERT INTO `loandetails` (`detail_id`, `application_id`, `property_location`, `property_value`, `car_model`, `car_value`) VALUES
(1, '20251121150255553694', 'Murang\'a', 2000000.00, NULL, NULL),
(2, '20251121150255553694', 'Murang\'a', 2000000.00, NULL, NULL),
(3, '20251120130917741457', NULL, NULL, NULL, NULL),
(4, '20251117103421187035', NULL, NULL, NULL, NULL),
(5, '20251110162903367198', NULL, NULL, NULL, NULL),
(6, '20251105113905552789', NULL, NULL, NULL, NULL),
(7, 'APP-1762161725828', NULL, NULL, NULL, NULL),
(8, '20251121150255553694', 'Murang\'a', 2000000.00, NULL, NULL),
(9, '20251114131646688262', NULL, NULL, NULL, NULL),
(10, '20251128131602224270', 'Embu', 3000000.00, NULL, NULL),
(11, '20251128150108857932', 'Murang\'a', 1200000.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `loanee`
--

DROP TABLE IF EXISTS `loanee`;
CREATE TABLE IF NOT EXISTS `loanee` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pf_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('loanee') DEFAULT 'loanee',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pf_number` (`pf_number`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loanee_notifications`
--

DROP TABLE IF EXISTS `loanee_notifications`;
CREATE TABLE IF NOT EXISTS `loanee_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loanee_id` int NOT NULL,
  `loan_application_id` int DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `loanee_id` (`loanee_id`),
  KEY `loan_application_id` (`loan_application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_applications`
--

DROP TABLE IF EXISTS `loan_applications`;
CREATE TABLE IF NOT EXISTS `loan_applications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `application_id` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `id_number` varchar(50) NOT NULL,
  `pf_number` bigint NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `loan_type` enum('mortgage','car') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `loan_amount` decimal(12,2) NOT NULL,
  `payment_duration` int NOT NULL,
  `payment_frequency` enum('monthly') DEFAULT 'monthly',
  `basic_salary` decimal(12,2) DEFAULT NULL,
  `employment_type` enum('permanent','contracted') DEFAULT NULL,
  `contract_period` int DEFAULT NULL,
  `contract_start_date` date DEFAULT NULL,
  `contract_end_date` date DEFAULT NULL,
  `property_location` varchar(255) DEFAULT NULL,
  `property_value` decimal(12,2) DEFAULT NULL,
  `car_type` varchar(100) DEFAULT NULL,
  `car_value` decimal(12,2) DEFAULT NULL,
  `bank_name` enum('Family Bank') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `bank_branch` varchar(100) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `status` enum('pending','forwarded','accepted','dispatched','active','rejected','returned','closed') DEFAULT 'pending',
  `application_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dispatch_date` datetime DEFAULT NULL,
  `closed_date` datetime DEFAULT NULL,
  `feedback` text,
  `cashier_processed_date` datetime DEFAULT NULL,
  `accountant_processed_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_id` (`application_id`),
  KEY `idx_user_id` (`user_id`)
) ;

--
-- Dumping data for table `loan_applications`
--

INSERT INTO `loan_applications` (`id`, `user_id`, `application_id`, `full_name`, `id_number`, `pf_number`, `email`, `loan_type`, `loan_amount`, `payment_duration`, `payment_frequency`, `basic_salary`, `employment_type`, `contract_period`, `contract_start_date`, `contract_end_date`, `property_location`, `property_value`, `car_type`, `car_value`, `bank_name`, `bank_branch`, `bank_account_number`, `status`, `application_date`, `dispatch_date`, `closed_date`, `feedback`, `cashier_processed_date`, `accountant_processed_date`, `created_at`, `updated_at`) VALUES
(12, 11, '2025112013091774145738', 'Antony Bundi', '145235789', 20250007896, 'anto@gmail.com', 'mortgage', 1200000.00, 10, 'monthly', 150000.00, NULL, NULL, NULL, NULL, 'Nairobi', 1500000.00, NULL, NULL, 'Family Bank', 'Nyeri', '7745621458786', 'active', '2025-11-20 07:09:17', '2025-11-29 11:15:41', NULL, 'Payment received.', NULL, NULL, '2025-11-20 07:43:07', '2025-12-05 09:21:59'),
(13, 4, '2025111710342118703580', 'Zion Harrison', '123456789', 12345678910, 'zionharry191@gmail.com', 'mortgage', 400000.00, 10, 'monthly', 100000.00, NULL, NULL, NULL, NULL, 'Nakuru', 500000.00, NULL, NULL, 'Family Bank', 'Nyeri', '7745621458788', 'closed', '2025-11-17 04:34:21', '2025-11-29 11:15:41', NULL, 'Loan fully paid off.', NULL, NULL, '2025-11-20 07:43:07', '2025-12-05 09:21:59'),
(14, 9, '2025111413164668826274', 'Test ONE', '45674123', 14756321457, 'test@gmail.com', 'car', 700000.00, 10, 'monthly', 100000.00, NULL, NULL, NULL, NULL, NULL, NULL, 'Toyota', 800000.00, 'Family Bank', 'Nyeri', '7745621458788', 'active', '2025-11-14 07:16:46', '2025-11-29 11:15:41', NULL, 'Payment received.', NULL, NULL, '2025-11-20 07:43:07', '2025-12-05 09:21:59'),
(15, 8, '2025111016290336719882', 'John Doe', '87564213', 1234567899, 'john@gmail.com', 'mortgage', 400000.00, 10, 'monthly', 100000.00, NULL, NULL, NULL, NULL, 'Mombasa', 600000.00, NULL, NULL, 'Family Bank', 'Nairobi', '7745621458786', 'active', '2025-11-10 10:29:03', '2025-11-29 11:15:41', NULL, 'Payment received.', NULL, NULL, '2025-11-20 07:43:07', '2025-12-05 09:21:59'),
(16, 4, '2025110511390555278943', 'Zion Harrison', '123456789', 0, 'zionharry191@gmail.com', 'mortgage', 500000.00, 10, 'monthly', 100000.00, NULL, NULL, NULL, NULL, 'Kisumu', 700000.00, NULL, NULL, 'Family Bank', 'Nyeri', '7745621458785', 'active', '2025-11-05 05:39:05', '2025-11-29 11:15:41', NULL, 'Payment received.', NULL, NULL, '2025-11-20 07:43:07', '2025-12-05 09:21:59'),
(17, 4, 'APP-1762161725828', 'Harrison Muhoro', '85623147', 2147483647, 'zionharry191@gmail.com', 'mortgage', 500000.00, 10, 'monthly', 100000.00, NULL, NULL, NULL, NULL, 'Eldoret', 800000.00, NULL, NULL, 'Family Bank', 'Nyeri', '7745621458785', 'active', '2025-11-03 06:22:05', '2025-11-29 11:15:41', NULL, 'Payment received.', NULL, NULL, '2025-11-20 07:43:07', '2025-12-05 09:21:59'),
(18, 4, '2025112115025555369418', 'Zion Harrison', '123456789', 12345678910, 'zionharry191@gmail.com', 'mortgage', 300000.00, 2, 'monthly', 200000.00, 'contracted', 31, '2025-10-21', '2028-05-21', 'Murang\'a', 2000000.00, NULL, NULL, 'Family Bank', 'Nyeri', '7745621458785', 'active', '2025-11-21 09:02:55', '2025-11-21 12:41:31', NULL, 'Payment received.', NULL, '2025-11-21 15:41:31', '2025-11-21 09:02:55', '2025-12-04 09:24:25'),
(19, 4, '2025112813160222427052', 'Zion Harrison', '123456789', 12345678910, 'zionharry191@gmail.com', 'mortgage', 800000.00, 10, 'monthly', 120000.00, 'permanent', NULL, NULL, NULL, 'Embu', 3000000.00, NULL, NULL, 'Family Bank', 'Nyeri', '7745621458785', 'closed', '2025-11-28 07:16:02', '2025-11-29 11:15:41', NULL, 'Innacurrate information', NULL, NULL, '2025-11-28 07:16:02', '2025-12-05 09:21:59'),
(20, 4, '2025112815010885793222', 'Zion Harrison', '123456789', 12345678910, 'zionharry191@gmail.com', 'mortgage', 800000.00, 10, 'monthly', 100000.00, 'permanent', NULL, NULL, NULL, 'Murang\'a', 1200000.00, NULL, NULL, 'Family Bank', 'Nyeri', '7745621458785', 'dispatched', '2025-11-28 09:01:08', '2025-11-28 12:24:36', NULL, 'Accepted by Accountant', NULL, '2025-11-28 15:24:36', '2025-11-28 09:01:08', '2025-11-28 09:24:36');

--
-- Triggers `loan_applications`
--
DROP TRIGGER IF EXISTS `after_loan_dispatch`;
DELIMITER $$
CREATE TRIGGER `after_loan_dispatch` AFTER UPDATE ON `loan_applications` FOR EACH ROW BEGIN
    IF NEW.status = 'dispatched' AND OLD.status != 'dispatched' AND NEW.dispatch_date IS NOT NULL THEN
        CALL sp_generate_payment_schedule(
            NEW.id,
            NEW.application_id,
            NEW.loan_amount,
            NEW.payment_duration,
            NEW.dispatch_date
        );
    END IF;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `loan_applications_before_insert`;
DELIMITER $$
CREATE TRIGGER `loan_applications_before_insert` BEFORE INSERT ON `loan_applications` FOR EACH ROW BEGIN
    IF NEW.application_date IS NULL THEN
        SET NEW.application_date = CURRENT_TIMESTAMP;
    END IF;
    IF NEW.created_at IS NULL THEN
        SET NEW.created_at = CURRENT_TIMESTAMP;
    END IF;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `loan_applications_before_update`;
DELIMITER $$
CREATE TRIGGER `loan_applications_before_update` BEFORE UPDATE ON `loan_applications` FOR EACH ROW BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `validate_loan_amount`;
DELIMITER $$
CREATE TRIGGER `validate_loan_amount` BEFORE INSERT ON `loan_applications` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `loan_applications_with_users`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `loan_applications_with_users`;
CREATE TABLE IF NOT EXISTS `loan_applications_with_users` (
`id` int
,`user_id` int
,`application_id` varchar(50)
,`full_name` varchar(100)
,`id_number` varchar(50)
,`pf_number` bigint
,`email` varchar(100)
,`loan_type` varchar(8)
,`loan_amount` decimal(12,2)
,`payment_duration` int
,`payment_frequency` varchar(7)
,`basic_salary` decimal(12,2)
,`employment_type` varchar(10)
,`contract_period` int
,`contract_start_date` date
,`contract_end_date` date
,`property_location` varchar(255)
,`property_value` decimal(12,2)
,`car_type` varchar(100)
,`car_value` decimal(12,2)
,`bank_name` varchar(11)
,`bank_branch` varchar(100)
,`bank_account_number` varchar(50)
,`status` varchar(10)
,`application_date` timestamp
,`dispatch_date` datetime
,`closed_date` datetime
,`feedback` mediumtext
,`cashier_processed_date` datetime
,`accountant_processed_date` datetime
,`created_at` timestamp
,`updated_at` timestamp
,`user_full_name` varchar(100)
,`user_email` varchar(100)
,`user_id_number` varchar(20)
,`user_pf_number` bigint
,`user_role` varchar(10)
);

-- --------------------------------------------------------

--
-- Table structure for table `loan_extended_details`
--

DROP TABLE IF EXISTS `loan_extended_details`;
CREATE TABLE IF NOT EXISTS `loan_extended_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `surname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_names` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `po_box` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `town_home_telephone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_address` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marital_status` enum('Single','Married','Divorced','Widowed','Separated') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_residence` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permanent_residence` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province_county` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `court_road_residence` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district_road` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `employer_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_station` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `office_address` text COLLATE utf8mb4_unicode_ci,
  `office_telephone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_employed` date DEFAULT NULL,
  `gross_salary` decimal(12,2) DEFAULT NULL,
  `net_salary` decimal(12,2) DEFAULT NULL,
  `id_front_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_back_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payslip_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kra_pin_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_application_id` (`application_id`),
  KEY `idx_surname` (`surname`),
  KEY `idx_mobile` (`mobile_number`),
  KEY `idx_email` (`email_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  KEY `fk_notifications_user` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
CREATE TABLE IF NOT EXISTS `payments` (
  `payment_id` int NOT NULL AUTO_INCREMENT,
  `application_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `principal_amount` decimal(10,2) NOT NULL,
  `interest_amount` decimal(10,2) NOT NULL,
  `balance_after` decimal(12,2) NOT NULL,
  `payment_source` enum('cash_deposit','bank_transfer','salary_deduction') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `transaction_reference` varchar(50) DEFAULT NULL,
  `recorded_by` varchar(100) NOT NULL,
  `recorded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text,
  PRIMARY KEY (`payment_id`),
  KEY `idx_payments_application_id` (`application_id`)
) ;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `application_id`, `payment_date`, `amount`, `principal_amount`, `interest_amount`, `balance_after`, `payment_source`, `transaction_reference`, `recorded_by`, `recorded_at`, `notes`) VALUES
(1, '2025110511390555278943', '2025-11-07', 18250.00, 17000.00, 1250.00, 483000.00, 'bank_transfer', 'REF-001-2026', 'Carson', '2025-11-07 13:27:15', ''),
(2, '2025110511390555278943', '2025-11-07', 6200.00, 5000.00, 1200.00, 495000.00, 'salary_deduction', 'REF-001-2026', 'Carson', '2025-11-07 13:57:39', ''),
(3, '2025110511390555278943', '2025-11-07', 6200.00, 5000.00, 1200.00, 495000.00, 'salary_deduction', 'REF-001-2026', 'Carson', '2025-11-07 14:06:46', ''),
(4, '2025110511390555278943', '2025-11-07', 6000.00, 5000.00, 1000.00, 495000.00, 'bank_transfer', 'REF-001-2026', 'Carson', '2025-11-07 14:14:34', ''),
(5, '2025110511390555278943', '2025-12-07', 60000.00, 50000.00, 10000.00, 418000.00, 'bank_transfer', 'REF-001-2024', 'Carson', '2025-11-07 14:58:31', ''),
(6, '2025111016290336719882', '2025-12-14', 13000.00, 10000.00, 3000.00, 390000.00, 'bank_transfer', 'REF-001-2027', 'David Muimi', '2025-11-14 06:35:45', ''),
(7, '2025111413164668826274', '2025-12-14', 16000.00, 10000.00, 6000.00, 690000.00, 'bank_transfer', 'REF-001-2027', 'Carson', '2025-11-14 10:18:39', ''),
(8, 'APP-1762161725828', '2025-12-17', 52000.00, 50000.00, 2000.00, 450000.00, 'bank_transfer', 'REF-001-2027', 'Shawn Muthomi', '2025-11-17 07:11:49', ''),
(9, '2025111710342118703580', '2025-12-17', 90000.00, 80000.00, 10000.00, 320000.00, 'bank_transfer', 'REF-001-2025', 'Carson', '2025-11-17 07:46:04', ''),
(10, '2025111710342118703580', '2026-01-07', 100000.00, 90000.00, 10000.00, 230000.00, 'salary_deduction', 'REF-001-2026', 'Zion Harrison', '2025-11-17 08:24:13', ''),
(11, '2025111710342118703580', '2026-01-17', 230575.00, 230000.00, 575.00, 0.00, 'cash_deposit', 'REF-001-2027', 'Carson', '2025-11-17 08:42:32', ''),
(12, '2025112013091774145738', '2025-11-20', 11667.00, 8000.00, 3667.00, 1192000.00, 'salary_deduction', 'REF-001-2025', 'Carson', '2025-11-20 10:35:49', ''),
(13, '2025112115025555369418', '2025-01-21', 14000.00, 12000.00, 2000.00, 288000.00, 'salary_deduction', 'REF-001-2026', 'Carson', '2025-11-21 12:42:38', ''),
(14, '2025112115025555369418', '2025-11-25', 52000.00, 50000.00, 2000.00, 238000.00, 'bank_transfer', 'REF-001-2026', 'Carson', '2025-11-25 08:19:36', ''),
(15, '2025112115025555369418', '2025-12-25', 7000.00, 5000.00, 2000.00, 233000.00, 'cash_deposit', 'REF-001-2025-65', 'Carson', '2025-11-25 08:35:41', ''),
(16, '2025112013091774145738', '2025-11-25', 60000.00, 50000.00, 10000.00, 1142000.00, 'cash_deposit', 'REF-001-2025-65', 'Carson', '2025-11-25 12:11:34', ''),
(17, '2025112013091774145738', '2025-11-26', 530000.00, 510000.00, 20000.00, 632000.00, 'cash_deposit', 'REF-001-2026', 'Carson', '2025-11-26 07:03:33', '');

-- --------------------------------------------------------

--
-- Table structure for table `payment_schedules`
--

DROP TABLE IF EXISTS `payment_schedules`;
CREATE TABLE IF NOT EXISTS `payment_schedules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_application_id` int NOT NULL,
  `payment_number` int NOT NULL,
  `due_date` date NOT NULL,
  `expected_amount` decimal(12,2) NOT NULL,
  `expected_principal` decimal(12,2) NOT NULL,
  `expected_interest` decimal(12,2) NOT NULL,
  `expected_balance` decimal(12,2) NOT NULL,
  `status` enum('pending','partial','paid','overdue') DEFAULT 'pending',
  `actual_amount` decimal(12,2) DEFAULT '0.00',
  `actual_interest` decimal(12,2) DEFAULT '0.00',
  `actual_date` date DEFAULT NULL,
  `variance` decimal(12,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_payment_schedules_loan_id` (`loan_application_id`)
) ;

--
-- Dumping data for table `payment_schedules`
--

INSERT INTO `payment_schedules` (`id`, `loan_application_id`, `payment_number`, `due_date`, `expected_amount`, `expected_principal`, `expected_interest`, `expected_balance`, `status`, `actual_amount`, `actual_interest`, `actual_date`, `variance`, `created_at`, `updated_at`) VALUES
(360, 20, 120, '2035-11-28', 19.26, 0.00, 19.26, 0.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(359, 20, 119, '2035-10-28', 7724.86, 7686.38, 38.48, 7705.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(358, 20, 118, '2035-09-28', 7724.86, 7667.21, 57.65, 15391.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(357, 20, 117, '2035-08-28', 7724.86, 7648.09, 76.77, 23059.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(356, 20, 116, '2035-07-28', 7724.86, 7629.02, 95.84, 30707.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(355, 20, 115, '2035-06-28', 7724.86, 7609.99, 114.87, 38336.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(354, 20, 114, '2035-05-28', 7724.86, 7591.02, 133.84, 45946.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(353, 20, 113, '2035-04-28', 7724.86, 7572.09, 152.77, 53537.31, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(352, 20, 112, '2035-03-28', 7724.86, 7553.20, 171.66, 61109.40, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(351, 20, 111, '2035-02-28', 7724.86, 7534.37, 190.49, 68662.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(350, 20, 110, '2035-01-28', 7724.86, 7515.58, 209.28, 76196.97, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(349, 20, 109, '2034-12-28', 7724.86, 7496.84, 228.02, 83712.55, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(348, 20, 108, '2034-11-28', 7724.86, 7478.14, 246.72, 91209.39, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(347, 20, 107, '2034-10-28', 7724.86, 7459.49, 265.37, 98687.53, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(346, 20, 106, '2034-09-28', 7724.86, 7440.89, 283.97, 106147.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(345, 20, 105, '2034-08-28', 7724.86, 7422.33, 302.53, 113587.91, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(344, 20, 104, '2034-07-28', 7724.86, 7403.82, 321.04, 121010.24, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(343, 20, 103, '2034-06-28', 7724.86, 7385.36, 339.50, 128414.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(342, 20, 102, '2034-05-28', 7724.86, 7366.94, 357.92, 135799.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(341, 20, 101, '2034-04-28', 7724.86, 7348.57, 376.29, 143166.36, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(340, 20, 100, '2034-03-28', 7724.86, 7330.25, 394.61, 150514.93, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(339, 20, 99, '2034-02-28', 7724.86, 7311.97, 412.89, 157845.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(338, 20, 98, '2034-01-28', 7724.86, 7293.73, 431.13, 165157.15, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(337, 20, 97, '2033-12-28', 7724.86, 7275.54, 449.32, 172450.88, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(336, 20, 96, '2033-11-28', 7724.86, 7257.40, 467.46, 179726.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(335, 20, 95, '2033-10-28', 7724.86, 7239.30, 485.56, 186983.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(334, 20, 94, '2033-09-28', 7724.86, 7221.25, 503.61, 194223.12, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(333, 20, 93, '2033-08-28', 7724.86, 7203.24, 521.62, 201444.37, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(332, 20, 92, '2033-07-28', 7724.86, 7185.28, 539.58, 208647.61, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(331, 20, 91, '2033-06-28', 7724.86, 7167.36, 557.50, 215832.89, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(330, 20, 90, '2033-05-28', 7724.86, 7149.49, 575.37, 223000.25, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(329, 20, 89, '2033-04-28', 7724.86, 7131.66, 593.20, 230149.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(328, 20, 88, '2033-03-28', 7724.86, 7113.87, 610.99, 237281.40, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(327, 20, 87, '2033-02-28', 7724.86, 7096.13, 628.73, 244395.27, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(326, 20, 86, '2033-01-28', 7724.86, 7078.44, 646.42, 251491.40, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(325, 20, 85, '2032-12-28', 7724.86, 7060.78, 664.08, 258569.84, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(324, 20, 84, '2032-11-28', 7724.86, 7043.18, 681.68, 265630.62, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(323, 20, 83, '2032-10-28', 7724.86, 7025.61, 699.25, 272673.80, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(322, 20, 82, '2032-09-28', 7724.86, 7008.09, 716.77, 279699.41, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(321, 20, 81, '2032-08-28', 7724.86, 6990.61, 734.25, 286707.50, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(320, 20, 80, '2032-07-28', 7724.86, 6973.18, 751.68, 293698.11, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(319, 20, 79, '2032-06-28', 7724.86, 6955.79, 769.07, 300671.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(318, 20, 78, '2032-05-28', 7724.86, 6938.45, 786.41, 307627.08, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(317, 20, 77, '2032-04-28', 7724.86, 6921.14, 803.72, 314565.53, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(316, 20, 76, '2032-03-28', 7724.86, 6903.88, 820.98, 321486.67, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(315, 20, 75, '2032-02-28', 7724.86, 6886.67, 838.19, 328390.55, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(314, 20, 74, '2032-01-28', 7724.86, 6869.49, 855.37, 335277.22, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(313, 20, 73, '2031-12-28', 7724.86, 6852.36, 872.50, 342146.71, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(312, 20, 72, '2031-11-28', 7724.86, 6835.27, 889.59, 348999.07, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(311, 20, 71, '2031-10-28', 7724.86, 6818.23, 906.63, 355834.34, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(310, 20, 70, '2031-09-28', 7724.86, 6801.23, 923.63, 362652.57, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(309, 20, 69, '2031-08-28', 7724.86, 6784.26, 940.60, 369453.80, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(308, 20, 68, '2031-07-28', 7724.86, 6767.35, 957.51, 376238.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(307, 20, 67, '2031-06-28', 7724.86, 6750.47, 974.39, 383005.41, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(306, 20, 66, '2031-05-28', 7724.86, 6733.64, 991.22, 389755.88, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(305, 20, 65, '2031-04-28', 7724.86, 6716.84, 1008.02, 396489.52, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(304, 20, 64, '2031-03-28', 7724.86, 6700.09, 1024.77, 403206.36, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(303, 20, 63, '2031-02-28', 7724.86, 6683.39, 1041.47, 409906.45, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(302, 20, 62, '2031-01-28', 7724.86, 6666.72, 1058.14, 416589.84, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(301, 20, 61, '2030-12-28', 7724.86, 6650.09, 1074.77, 423256.56, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(300, 20, 60, '2030-11-28', 7724.86, 6633.51, 1091.35, 429906.65, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(299, 20, 59, '2030-10-28', 7724.86, 6616.97, 1107.89, 436540.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(298, 20, 58, '2030-09-28', 7724.86, 6600.47, 1124.39, 443157.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(297, 20, 57, '2030-08-28', 7724.86, 6584.01, 1140.85, 449757.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(296, 20, 56, '2030-07-28', 7724.86, 6567.59, 1157.27, 456341.61, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(295, 20, 55, '2030-06-28', 7724.86, 6551.21, 1173.65, 462909.20, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(294, 20, 54, '2030-05-28', 7724.86, 6534.87, 1189.99, 469460.41, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(293, 20, 53, '2030-04-28', 7724.86, 6518.58, 1206.28, 475995.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(292, 20, 52, '2030-03-28', 7724.86, 6502.32, 1222.54, 482513.86, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(291, 20, 51, '2030-02-28', 7724.86, 6486.10, 1238.76, 489016.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(290, 20, 50, '2030-01-28', 7724.86, 6469.93, 1254.93, 495502.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(289, 20, 49, '2029-12-28', 7724.86, 6453.79, 1271.07, 501972.21, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(288, 20, 48, '2029-11-28', 7724.86, 6437.70, 1287.16, 508426.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(287, 20, 47, '2029-10-28', 7724.86, 6421.65, 1303.21, 514863.70, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(286, 20, 46, '2029-09-28', 7724.86, 6405.63, 1319.23, 521285.35, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(285, 20, 45, '2029-08-28', 7724.86, 6389.66, 1335.20, 527690.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(284, 20, 44, '2029-07-28', 7724.86, 6373.72, 1351.14, 534080.64, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(283, 20, 43, '2029-06-28', 7724.86, 6357.83, 1367.03, 540454.36, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(282, 20, 42, '2029-05-28', 7724.86, 6341.97, 1382.89, 546812.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(281, 20, 41, '2029-04-28', 7724.86, 6326.16, 1398.70, 553154.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(280, 20, 40, '2029-03-28', 7724.86, 6310.38, 1414.48, 559480.32, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(279, 20, 39, '2029-02-28', 7724.86, 6294.65, 1430.21, 565790.70, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(278, 20, 38, '2029-01-28', 7724.86, 6278.95, 1445.91, 572085.35, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(277, 20, 37, '2028-12-28', 7724.86, 6263.29, 1461.57, 578364.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(276, 20, 36, '2028-11-28', 7724.86, 6247.67, 1477.19, 584627.59, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(275, 20, 35, '2028-10-28', 7724.86, 6232.09, 1492.77, 590875.26, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(274, 20, 34, '2028-09-28', 7724.86, 6216.55, 1508.31, 597107.35, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(273, 20, 33, '2028-08-28', 7724.86, 6201.05, 1523.81, 603323.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(272, 20, 32, '2028-07-28', 7724.86, 6185.58, 1539.28, 609524.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(271, 20, 31, '2028-06-28', 7724.86, 6170.16, 1554.70, 615710.53, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(270, 20, 30, '2028-05-28', 7724.86, 6154.77, 1570.09, 621880.69, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(269, 20, 29, '2028-04-28', 7724.86, 6139.42, 1585.44, 628035.46, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(268, 20, 28, '2028-03-28', 7724.86, 6124.11, 1600.75, 634174.88, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(267, 20, 27, '2028-02-28', 7724.86, 6108.84, 1616.02, 640298.99, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(266, 20, 26, '2028-01-28', 7724.86, 6093.61, 1631.25, 646407.83, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(265, 20, 25, '2027-12-28', 7724.86, 6078.41, 1646.45, 652501.44, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(264, 20, 24, '2027-11-28', 7724.86, 6063.25, 1661.61, 658579.85, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(263, 20, 23, '2027-10-28', 7724.86, 6048.13, 1676.73, 664643.10, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(262, 20, 22, '2027-09-28', 7724.86, 6033.05, 1691.81, 670691.23, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(261, 20, 21, '2027-08-28', 7724.86, 6018.00, 1706.86, 676724.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(260, 20, 20, '2027-07-28', 7724.86, 6003.00, 1721.86, 682742.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(259, 20, 19, '2027-06-28', 7724.86, 5988.03, 1736.83, 688745.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(258, 20, 18, '2027-05-28', 7724.86, 5973.09, 1751.77, 694733.31, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(257, 20, 17, '2027-04-28', 7724.86, 5958.20, 1766.66, 700706.40, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(256, 20, 16, '2027-03-28', 7724.86, 5943.34, 1781.52, 706664.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(255, 20, 15, '2027-02-28', 7724.86, 5928.52, 1796.34, 712607.94, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(254, 20, 14, '2027-01-28', 7724.86, 5913.73, 1811.13, 718536.46, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(253, 20, 13, '2026-12-28', 7724.86, 5898.99, 1825.87, 724450.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(252, 20, 12, '2026-11-28', 7724.86, 5884.28, 1840.58, 730349.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(251, 20, 11, '2026-10-28', 7724.86, 5869.60, 1855.26, 736233.46, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(250, 20, 10, '2026-09-28', 7724.86, 5854.96, 1869.90, 742103.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(249, 20, 9, '2026-08-28', 7724.86, 5840.36, 1884.50, 747958.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(248, 20, 8, '2026-07-28', 7724.86, 5825.80, 1899.06, 753798.38, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(247, 20, 7, '2026-06-28', 7724.86, 5811.27, 1913.59, 759624.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(246, 20, 6, '2026-05-28', 7724.86, 5796.78, 1928.08, 765435.45, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(245, 20, 5, '2026-04-28', 7724.86, 5782.32, 1942.54, 771232.23, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(244, 20, 4, '2026-03-28', 7724.86, 5767.90, 1956.96, 777014.55, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(243, 20, 3, '2026-02-28', 7724.86, 5753.52, 1971.34, 782782.45, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(242, 20, 2, '2026-01-28', 7724.86, 5739.17, 1985.69, 788535.97, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(241, 20, 1, '2025-12-28', 7724.86, 5724.86, 2000.00, 794275.14, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 10:39:27', '2025-12-05 10:39:27'),
(361, 18, 1, '2025-12-21', 12894.36, 12144.36, 750.00, 287855.64, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(362, 18, 2, '2026-01-21', 12894.36, 12174.72, 719.64, 275680.92, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(363, 18, 3, '2026-02-21', 12894.36, 12205.16, 689.20, 263475.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(364, 18, 4, '2026-03-21', 12894.36, 12235.67, 658.69, 251240.09, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(365, 18, 5, '2026-04-21', 12894.36, 12266.26, 628.10, 238973.83, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(366, 18, 6, '2026-05-21', 12894.36, 12296.93, 597.43, 226676.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(367, 18, 7, '2026-06-21', 12894.36, 12327.67, 566.69, 214349.23, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(368, 18, 8, '2026-07-21', 12894.36, 12358.49, 535.87, 201990.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(369, 18, 9, '2026-08-21', 12894.36, 12389.38, 504.98, 189601.36, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(370, 18, 10, '2026-09-21', 12894.36, 12420.36, 474.00, 177181.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(371, 18, 11, '2026-10-21', 12894.36, 12451.41, 442.95, 164729.59, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(372, 18, 12, '2026-11-21', 12894.36, 12482.54, 411.82, 152247.05, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(373, 18, 13, '2026-12-21', 12894.36, 12513.74, 380.62, 139733.31, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(374, 18, 14, '2027-01-21', 12894.36, 12545.03, 349.33, 127188.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(375, 18, 15, '2027-02-21', 12894.36, 12576.39, 317.97, 114611.89, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(376, 18, 16, '2027-03-21', 12894.36, 12607.83, 286.53, 102004.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(377, 18, 17, '2027-04-21', 12894.36, 12639.35, 255.01, 89364.71, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(378, 18, 18, '2027-05-21', 12894.36, 12670.95, 223.41, 76693.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(379, 18, 19, '2027-06-21', 12894.36, 12702.63, 191.73, 63991.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(380, 18, 20, '2027-07-21', 12894.36, 12734.38, 159.98, 51256.75, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(381, 18, 21, '2027-08-21', 12894.36, 12766.22, 128.14, 38490.53, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(382, 18, 22, '2027-09-21', 12894.36, 12798.13, 96.23, 25692.40, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(383, 18, 23, '2027-10-21', 12894.36, 12830.13, 64.23, 12862.27, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(384, 18, 24, '2027-11-21', 32.23, 0.07, 32.16, 0.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 11:49:52', '2025-12-05 11:49:52'),
(385, 12, 1, '2025-12-29', 11587.29, 8587.29, 3000.00, 1191412.71, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(386, 12, 2, '2026-01-29', 11587.29, 8608.76, 2978.53, 1182803.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(387, 12, 3, '2026-02-28', 11587.29, 8630.28, 2957.01, 1174173.67, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(388, 12, 4, '2026-03-29', 11587.29, 8651.86, 2935.43, 1165521.81, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(389, 12, 5, '2026-04-29', 11587.29, 8673.49, 2913.80, 1156848.32, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(390, 12, 6, '2026-05-29', 11587.29, 8695.17, 2892.12, 1148153.15, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(391, 12, 7, '2026-06-29', 11587.29, 8716.91, 2870.38, 1139436.24, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(392, 12, 8, '2026-07-29', 11587.29, 8738.70, 2848.59, 1130697.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(393, 12, 9, '2026-08-29', 11587.29, 8760.55, 2826.74, 1121936.99, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(394, 12, 10, '2026-09-29', 11587.29, 8782.45, 2804.84, 1113154.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(395, 12, 11, '2026-10-29', 11587.29, 8804.40, 2782.89, 1104350.14, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(396, 12, 12, '2026-11-29', 11587.29, 8826.41, 2760.88, 1095523.73, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(397, 12, 13, '2026-12-29', 11587.29, 8848.48, 2738.81, 1086675.25, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(398, 12, 14, '2027-01-29', 11587.29, 8870.60, 2716.69, 1077804.65, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(399, 12, 15, '2027-02-28', 11587.29, 8892.78, 2694.51, 1068911.87, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(400, 12, 16, '2027-03-29', 11587.29, 8915.01, 2672.28, 1059996.86, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(401, 12, 17, '2027-04-29', 11587.29, 8937.30, 2649.99, 1051059.56, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(402, 12, 18, '2027-05-29', 11587.29, 8959.64, 2627.65, 1042099.92, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(403, 12, 19, '2027-06-29', 11587.29, 8982.04, 2605.25, 1033117.88, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(404, 12, 20, '2027-07-29', 11587.29, 9004.50, 2582.79, 1024113.38, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(405, 12, 21, '2027-08-29', 11587.29, 9027.01, 2560.28, 1015086.37, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(406, 12, 22, '2027-09-29', 11587.29, 9049.57, 2537.72, 1006036.80, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(407, 12, 23, '2027-10-29', 11587.29, 9072.20, 2515.09, 996964.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(408, 12, 24, '2027-11-29', 11587.29, 9094.88, 2492.41, 987869.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(409, 12, 25, '2027-12-29', 11587.29, 9117.62, 2469.67, 978752.10, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(410, 12, 26, '2028-01-29', 11587.29, 9140.41, 2446.88, 969611.69, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(411, 12, 27, '2028-02-29', 11587.29, 9163.26, 2424.03, 960448.43, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(412, 12, 28, '2028-03-29', 11587.29, 9186.17, 2401.12, 951262.26, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(413, 12, 29, '2028-04-29', 11587.29, 9209.13, 2378.16, 942053.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(414, 12, 30, '2028-05-29', 11587.29, 9232.16, 2355.13, 932820.97, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(415, 12, 31, '2028-06-29', 11587.29, 9255.24, 2332.05, 923565.73, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(416, 12, 32, '2028-07-29', 11587.29, 9278.38, 2308.91, 914287.35, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(417, 12, 33, '2028-08-29', 11587.29, 9301.57, 2285.72, 904985.78, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(418, 12, 34, '2028-09-29', 11587.29, 9324.83, 2262.46, 895660.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(419, 12, 35, '2028-10-29', 11587.29, 9348.14, 2239.15, 886312.81, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(420, 12, 36, '2028-11-29', 11587.29, 9371.51, 2215.78, 876941.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(421, 12, 37, '2028-12-29', 11587.29, 9394.94, 2192.35, 867546.36, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(422, 12, 38, '2029-01-29', 11587.29, 9418.42, 2168.87, 858127.94, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(423, 12, 39, '2029-02-28', 11587.29, 9441.97, 2145.32, 848685.97, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(424, 12, 40, '2029-03-29', 11587.29, 9465.58, 2121.71, 839220.39, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(425, 12, 41, '2029-04-29', 11587.29, 9489.24, 2098.05, 829731.15, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(426, 12, 42, '2029-05-29', 11587.29, 9512.96, 2074.33, 820218.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(427, 12, 43, '2029-06-29', 11587.29, 9536.74, 2050.55, 810681.45, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(428, 12, 44, '2029-07-29', 11587.29, 9560.59, 2026.70, 801120.86, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(429, 12, 45, '2029-08-29', 11587.29, 9584.49, 2002.80, 791536.37, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(430, 12, 46, '2029-09-29', 11587.29, 9608.45, 1978.84, 781927.92, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(431, 12, 47, '2029-10-29', 11587.29, 9632.47, 1954.82, 772295.45, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(432, 12, 48, '2029-11-29', 11587.29, 9656.55, 1930.74, 762638.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(433, 12, 49, '2029-12-29', 11587.29, 9680.69, 1906.60, 752958.21, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(434, 12, 50, '2030-01-29', 11587.29, 9704.89, 1882.40, 743253.32, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(435, 12, 51, '2030-02-28', 11587.29, 9729.16, 1858.13, 733524.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(436, 12, 52, '2030-03-29', 11587.29, 9753.48, 1833.81, 723770.68, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(437, 12, 53, '2030-04-29', 11587.29, 9777.86, 1809.43, 713992.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(438, 12, 54, '2030-05-29', 11587.29, 9802.31, 1784.98, 704190.51, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(439, 12, 55, '2030-06-29', 11587.29, 9826.81, 1760.48, 694363.70, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(440, 12, 56, '2030-07-29', 11587.29, 9851.38, 1735.91, 684512.32, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(441, 12, 57, '2030-08-29', 11587.29, 9876.01, 1711.28, 674636.31, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(442, 12, 58, '2030-09-29', 11587.29, 9900.70, 1686.59, 664735.61, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(443, 12, 59, '2030-10-29', 11587.29, 9925.45, 1661.84, 654810.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(444, 12, 60, '2030-11-29', 11587.29, 9950.26, 1637.03, 644859.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(445, 12, 61, '2030-12-29', 11587.29, 9975.14, 1612.15, 634884.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(446, 12, 62, '2031-01-29', 11587.29, 10000.08, 1587.21, 624884.68, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(447, 12, 63, '2031-02-28', 11587.29, 10025.08, 1562.21, 614859.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(448, 12, 64, '2031-03-29', 11587.29, 10050.14, 1537.15, 604809.46, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(449, 12, 65, '2031-04-29', 11587.29, 10075.27, 1512.02, 594734.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(450, 12, 66, '2031-05-29', 11587.29, 10100.45, 1486.84, 584633.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(451, 12, 67, '2031-06-29', 11587.29, 10125.71, 1461.58, 574508.03, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(452, 12, 68, '2031-07-29', 11587.29, 10151.02, 1436.27, 564357.01, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(453, 12, 69, '2031-08-29', 11587.29, 10176.40, 1410.89, 554180.61, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(454, 12, 70, '2031-09-29', 11587.29, 10201.84, 1385.45, 543978.77, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(455, 12, 71, '2031-10-29', 11587.29, 10227.34, 1359.95, 533751.43, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(456, 12, 72, '2031-11-29', 11587.29, 10252.91, 1334.38, 523498.52, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(457, 12, 73, '2031-12-29', 11587.29, 10278.54, 1308.75, 513219.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(458, 12, 74, '2032-01-29', 11587.29, 10304.24, 1283.05, 502915.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(459, 12, 75, '2032-02-29', 11587.29, 10330.00, 1257.29, 492585.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(460, 12, 76, '2032-03-29', 11587.29, 10355.83, 1231.46, 482229.91, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(461, 12, 77, '2032-04-29', 11587.29, 10381.72, 1205.57, 471848.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(462, 12, 78, '2032-05-29', 11587.29, 10407.67, 1179.62, 461440.52, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(463, 12, 79, '2032-06-29', 11587.29, 10433.69, 1153.60, 451006.83, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(464, 12, 80, '2032-07-29', 11587.29, 10459.77, 1127.52, 440547.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(465, 12, 81, '2032-08-29', 11587.29, 10485.92, 1101.37, 430061.14, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(466, 12, 82, '2032-09-29', 11587.29, 10512.14, 1075.15, 419549.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(467, 12, 83, '2032-10-29', 11587.29, 10538.42, 1048.87, 409010.58, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(468, 12, 84, '2032-11-29', 11587.29, 10564.76, 1022.53, 398445.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(469, 12, 85, '2032-12-29', 11587.29, 10591.18, 996.11, 387854.64, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(470, 12, 86, '2033-01-29', 11587.29, 10617.65, 969.64, 377236.99, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(471, 12, 87, '2033-02-28', 11587.29, 10644.20, 943.09, 366592.79, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(472, 12, 88, '2033-03-29', 11587.29, 10670.81, 916.48, 355921.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(473, 12, 89, '2033-04-29', 11587.29, 10697.49, 889.80, 345224.49, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(474, 12, 90, '2033-05-29', 11587.29, 10724.23, 863.06, 334500.26, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(475, 12, 91, '2033-06-29', 11587.29, 10751.04, 836.25, 323749.22, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(476, 12, 92, '2033-07-29', 11587.29, 10777.92, 809.37, 312971.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(477, 12, 93, '2033-08-29', 11587.29, 10804.86, 782.43, 302166.44, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(478, 12, 94, '2033-09-29', 11587.29, 10831.87, 755.42, 291334.57, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(479, 12, 95, '2033-10-29', 11587.29, 10858.95, 728.34, 280475.62, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(480, 12, 96, '2033-11-29', 11587.29, 10886.10, 701.19, 269589.52, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(481, 12, 97, '2033-12-29', 11587.29, 10913.32, 673.97, 258676.20, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(482, 12, 98, '2034-01-29', 11587.29, 10940.60, 646.69, 247735.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(483, 12, 99, '2034-02-28', 11587.29, 10967.95, 619.34, 236767.65, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(484, 12, 100, '2034-03-29', 11587.29, 10995.37, 591.92, 225772.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(485, 12, 101, '2034-04-29', 11587.29, 11022.86, 564.43, 214749.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(486, 12, 102, '2034-05-29', 11587.29, 11050.42, 536.87, 203699.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(487, 12, 103, '2034-06-29', 11587.29, 11078.04, 509.25, 192620.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(488, 12, 104, '2034-07-29', 11587.29, 11105.74, 481.55, 181515.22, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(489, 12, 105, '2034-08-29', 11587.29, 11133.50, 453.79, 170381.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(490, 12, 106, '2034-09-29', 11587.29, 11161.34, 425.95, 159220.38, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(491, 12, 107, '2034-10-29', 11587.29, 11189.24, 398.05, 148031.14, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(492, 12, 108, '2034-11-29', 11587.29, 11217.21, 370.08, 136813.93, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(493, 12, 109, '2034-12-29', 11587.29, 11245.26, 342.03, 125568.67, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(494, 12, 110, '2035-01-29', 11587.29, 11273.37, 313.92, 114295.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(495, 12, 111, '2035-02-28', 11587.29, 11301.55, 285.74, 102993.75, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(496, 12, 112, '2035-03-29', 11587.29, 11329.81, 257.48, 91663.94, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(497, 12, 113, '2035-04-29', 11587.29, 11358.13, 229.16, 80305.81, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(498, 12, 114, '2035-05-29', 11587.29, 11386.53, 200.76, 68919.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(499, 12, 115, '2035-06-29', 11587.29, 11414.99, 172.30, 57504.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(500, 12, 116, '2035-07-29', 11587.29, 11443.53, 143.76, 46060.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(501, 12, 117, '2035-08-29', 11587.29, 11472.14, 115.15, 34588.62, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(502, 12, 118, '2035-09-29', 11587.29, 11500.82, 86.47, 23087.80, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(503, 12, 119, '2035-10-29', 11587.29, 11529.57, 57.72, 11558.23, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(504, 12, 120, '2035-11-29', 28.90, 0.00, 28.90, 0.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(505, 14, 1, '2025-12-29', 6759.25, 5009.25, 1750.00, 694990.75, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(506, 14, 2, '2026-01-29', 6759.25, 5021.77, 1737.48, 689968.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(507, 14, 3, '2026-02-28', 6759.25, 5034.33, 1724.92, 684934.65, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(508, 14, 4, '2026-03-29', 6759.25, 5046.91, 1712.34, 679887.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(509, 14, 5, '2026-04-29', 6759.25, 5059.53, 1699.72, 674828.21, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(510, 14, 6, '2026-05-29', 6759.25, 5072.18, 1687.07, 669756.03, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(511, 14, 7, '2026-06-29', 6759.25, 5084.86, 1674.39, 664671.17, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(512, 14, 8, '2026-07-29', 6759.25, 5097.57, 1661.68, 659573.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(513, 14, 9, '2026-08-29', 6759.25, 5110.32, 1648.93, 654463.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(514, 14, 10, '2026-09-29', 6759.25, 5123.09, 1636.16, 649340.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(515, 14, 11, '2026-10-29', 6759.25, 5135.90, 1623.35, 644204.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(516, 14, 12, '2026-11-29', 6759.25, 5148.74, 1610.51, 639055.55, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(517, 14, 13, '2026-12-29', 6759.25, 5161.61, 1597.64, 633893.94, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(518, 14, 14, '2027-01-29', 6759.25, 5174.52, 1584.73, 628719.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(519, 14, 15, '2027-02-28', 6759.25, 5187.45, 1571.80, 623531.97, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(520, 14, 16, '2027-03-29', 6759.25, 5200.42, 1558.83, 618331.55, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(521, 14, 17, '2027-04-29', 6759.25, 5213.42, 1545.83, 613118.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(522, 14, 18, '2027-05-29', 6759.25, 5226.45, 1532.80, 607891.68, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(523, 14, 19, '2027-06-29', 6759.25, 5239.52, 1519.73, 602652.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(524, 14, 20, '2027-07-29', 6759.25, 5252.62, 1506.63, 597399.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(525, 14, 21, '2027-08-29', 6759.25, 5265.75, 1493.50, 592133.79, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(526, 14, 22, '2027-09-29', 6759.25, 5278.92, 1480.33, 586854.87, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(527, 14, 23, '2027-10-29', 6759.25, 5292.11, 1467.14, 581562.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(528, 14, 24, '2027-11-29', 6759.25, 5305.34, 1453.91, 576257.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(529, 14, 25, '2027-12-29', 6759.25, 5318.61, 1440.64, 570938.81, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(530, 14, 26, '2028-01-29', 6759.25, 5331.90, 1427.35, 565606.91, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(531, 14, 27, '2028-02-29', 6759.25, 5345.23, 1414.02, 560261.68, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(532, 14, 28, '2028-03-29', 6759.25, 5358.60, 1400.65, 554903.08, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(533, 14, 29, '2028-04-29', 6759.25, 5371.99, 1387.26, 549531.09, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(534, 14, 30, '2028-05-29', 6759.25, 5385.42, 1373.83, 544145.67, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(535, 14, 31, '2028-06-29', 6759.25, 5398.89, 1360.36, 538746.78, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(536, 14, 32, '2028-07-29', 6759.25, 5412.38, 1346.87, 533334.40, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(537, 14, 33, '2028-08-29', 6759.25, 5425.91, 1333.34, 527908.49, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(538, 14, 34, '2028-09-29', 6759.25, 5439.48, 1319.77, 522469.01, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(539, 14, 35, '2028-10-29', 6759.25, 5453.08, 1306.17, 517015.93, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(540, 14, 36, '2028-11-29', 6759.25, 5466.71, 1292.54, 511549.22, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(541, 14, 37, '2028-12-29', 6759.25, 5480.38, 1278.87, 506068.84, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(542, 14, 38, '2029-01-29', 6759.25, 5494.08, 1265.17, 500574.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(543, 14, 39, '2029-02-28', 6759.25, 5507.81, 1251.44, 495066.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(544, 14, 40, '2029-03-29', 6759.25, 5521.58, 1237.67, 489545.37, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(545, 14, 41, '2029-04-29', 6759.25, 5535.39, 1223.86, 484009.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(546, 14, 42, '2029-05-29', 6759.25, 5549.23, 1210.02, 478460.75, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(547, 14, 43, '2029-06-29', 6759.25, 5563.10, 1196.15, 472897.65, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(548, 14, 44, '2029-07-29', 6759.25, 5577.01, 1182.24, 467320.64, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(549, 14, 45, '2029-08-29', 6759.25, 5590.95, 1168.30, 461729.69, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(550, 14, 46, '2029-09-29', 6759.25, 5604.93, 1154.32, 456124.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(551, 14, 47, '2029-10-29', 6759.25, 5618.94, 1140.31, 450505.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(552, 14, 48, '2029-11-29', 6759.25, 5632.99, 1126.26, 444872.83, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(553, 14, 49, '2029-12-29', 6759.25, 5647.07, 1112.18, 439225.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(554, 14, 50, '2030-01-29', 6759.25, 5661.19, 1098.06, 433564.57, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(555, 14, 51, '2030-02-28', 6759.25, 5675.34, 1083.91, 427889.23, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(556, 14, 52, '2030-03-29', 6759.25, 5689.53, 1069.72, 422199.70, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(557, 14, 53, '2030-04-29', 6759.25, 5703.75, 1055.50, 416495.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(558, 14, 54, '2030-05-29', 6759.25, 5718.01, 1041.24, 410777.94, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(559, 14, 55, '2030-06-29', 6759.25, 5732.31, 1026.94, 405045.63, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(560, 14, 56, '2030-07-29', 6759.25, 5746.64, 1012.61, 399298.99, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(561, 14, 57, '2030-08-29', 6759.25, 5761.00, 998.25, 393537.99, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(562, 14, 58, '2030-09-29', 6759.25, 5775.41, 983.84, 387762.58, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(563, 14, 59, '2030-10-29', 6759.25, 5789.84, 969.41, 381972.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(564, 14, 60, '2030-11-29', 6759.25, 5804.32, 954.93, 376168.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(565, 14, 61, '2030-12-29', 6759.25, 5818.83, 940.42, 370349.59, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(566, 14, 62, '2031-01-29', 6759.25, 5833.38, 925.87, 364516.21, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(567, 14, 63, '2031-02-28', 6759.25, 5847.96, 911.29, 358668.25, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(568, 14, 64, '2031-03-29', 6759.25, 5862.58, 896.67, 352805.67, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(569, 14, 65, '2031-04-29', 6759.25, 5877.24, 882.01, 346928.43, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(570, 14, 66, '2031-05-29', 6759.25, 5891.93, 867.32, 341036.50, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(571, 14, 67, '2031-06-29', 6759.25, 5906.66, 852.59, 335129.84, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(572, 14, 68, '2031-07-29', 6759.25, 5921.43, 837.82, 329208.41, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(573, 14, 69, '2031-08-29', 6759.25, 5936.23, 823.02, 323272.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(574, 14, 70, '2031-09-29', 6759.25, 5951.07, 808.18, 317321.11, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(575, 14, 71, '2031-10-29', 6759.25, 5965.95, 793.30, 311355.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(576, 14, 72, '2031-11-29', 6759.25, 5980.86, 778.39, 305374.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(577, 14, 73, '2031-12-29', 6759.25, 5995.81, 763.44, 299378.49, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(578, 14, 74, '2032-01-29', 6759.25, 6010.80, 748.45, 293367.69, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(579, 14, 75, '2032-02-29', 6759.25, 6025.83, 733.42, 287341.86, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(580, 14, 76, '2032-03-29', 6759.25, 6040.90, 718.35, 281300.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04');
INSERT INTO `payment_schedules` (`id`, `loan_application_id`, `payment_number`, `due_date`, `expected_amount`, `expected_principal`, `expected_interest`, `expected_balance`, `status`, `actual_amount`, `actual_interest`, `actual_date`, `variance`, `created_at`, `updated_at`) VALUES
(581, 14, 77, '2032-04-29', 6759.25, 6056.00, 703.25, 275244.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(582, 14, 78, '2032-05-29', 6759.25, 6071.14, 688.11, 269173.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(583, 14, 79, '2032-06-29', 6759.25, 6086.32, 672.93, 263087.50, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(584, 14, 80, '2032-07-29', 6759.25, 6101.53, 657.72, 256985.97, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(585, 14, 81, '2032-08-29', 6759.25, 6116.79, 642.46, 250869.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(586, 14, 82, '2032-09-29', 6759.25, 6132.08, 627.17, 244737.10, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(587, 14, 83, '2032-10-29', 6759.25, 6147.41, 611.84, 238589.69, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(588, 14, 84, '2032-11-29', 6759.25, 6162.78, 596.47, 232426.91, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(589, 14, 85, '2032-12-29', 6759.25, 6178.18, 581.07, 226248.73, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(590, 14, 86, '2033-01-29', 6759.25, 6193.63, 565.62, 220055.10, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(591, 14, 87, '2033-02-28', 6759.25, 6209.11, 550.14, 213845.99, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(592, 14, 88, '2033-03-29', 6759.25, 6224.64, 534.61, 207621.35, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(593, 14, 89, '2033-04-29', 6759.25, 6240.20, 519.05, 201381.15, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(594, 14, 90, '2033-05-29', 6759.25, 6255.80, 503.45, 195125.35, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(595, 14, 91, '2033-06-29', 6759.25, 6271.44, 487.81, 188853.91, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(596, 14, 92, '2033-07-29', 6759.25, 6287.12, 472.13, 182566.79, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(597, 14, 93, '2033-08-29', 6759.25, 6302.83, 456.42, 176263.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(598, 14, 94, '2033-09-29', 6759.25, 6318.59, 440.66, 169945.37, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(599, 14, 95, '2033-10-29', 6759.25, 6334.39, 424.86, 163610.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(600, 14, 96, '2033-11-29', 6759.25, 6350.22, 409.03, 157260.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(601, 14, 97, '2033-12-29', 6759.25, 6366.10, 393.15, 150894.66, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(602, 14, 98, '2034-01-29', 6759.25, 6382.01, 377.24, 144512.65, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(603, 14, 99, '2034-02-28', 6759.25, 6397.97, 361.28, 138114.68, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(604, 14, 100, '2034-03-29', 6759.25, 6413.96, 345.29, 131700.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(605, 14, 101, '2034-04-29', 6759.25, 6430.00, 329.25, 125270.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(606, 14, 102, '2034-05-29', 6759.25, 6446.07, 313.18, 118824.65, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(607, 14, 103, '2034-06-29', 6759.25, 6462.19, 297.06, 112362.46, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(608, 14, 104, '2034-07-29', 6759.25, 6478.34, 280.91, 105884.12, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(609, 14, 105, '2034-08-29', 6759.25, 6494.54, 264.71, 99389.58, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(610, 14, 106, '2034-09-29', 6759.25, 6510.78, 248.47, 92878.80, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(611, 14, 107, '2034-10-29', 6759.25, 6527.05, 232.20, 86351.75, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(612, 14, 108, '2034-11-29', 6759.25, 6543.37, 215.88, 79808.38, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(613, 14, 109, '2034-12-29', 6759.25, 6559.73, 199.52, 73248.65, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(614, 14, 110, '2035-01-29', 6759.25, 6576.13, 183.12, 66672.52, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(615, 14, 111, '2035-02-28', 6759.25, 6592.57, 166.68, 60079.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(616, 14, 112, '2035-03-29', 6759.25, 6609.05, 150.20, 53470.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(617, 14, 113, '2035-04-29', 6759.25, 6625.57, 133.68, 46845.33, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(618, 14, 114, '2035-05-29', 6759.25, 6642.14, 117.11, 40203.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(619, 14, 115, '2035-06-29', 6759.25, 6658.74, 100.51, 33544.45, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(620, 14, 116, '2035-07-29', 6759.25, 6675.39, 83.86, 26869.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(621, 14, 117, '2035-08-29', 6759.25, 6692.08, 67.17, 20176.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(622, 14, 118, '2035-09-29', 6759.25, 6708.81, 50.44, 13468.17, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(623, 14, 119, '2035-10-29', 6759.25, 6725.58, 33.67, 6742.59, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(624, 14, 120, '2035-11-29', 17.06, 0.20, 16.86, 0.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(625, 15, 1, '2025-12-29', 3862.43, 2862.43, 1000.00, 397137.57, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(626, 15, 2, '2026-01-29', 3862.43, 2869.59, 992.84, 394267.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(627, 15, 3, '2026-02-28', 3862.43, 2876.76, 985.67, 391391.22, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(628, 15, 4, '2026-03-29', 3862.43, 2883.95, 978.48, 388507.27, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(629, 15, 5, '2026-04-29', 3862.43, 2891.16, 971.27, 385616.11, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(630, 15, 6, '2026-05-29', 3862.43, 2898.39, 964.04, 382717.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(631, 15, 7, '2026-06-29', 3862.43, 2905.64, 956.79, 379812.08, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(632, 15, 8, '2026-07-29', 3862.43, 2912.90, 949.53, 376899.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(633, 15, 9, '2026-08-29', 3862.43, 2920.18, 942.25, 373979.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(634, 15, 10, '2026-09-29', 3862.43, 2927.48, 934.95, 371051.52, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(635, 15, 11, '2026-10-29', 3862.43, 2934.80, 927.63, 368116.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(636, 15, 12, '2026-11-29', 3862.43, 2942.14, 920.29, 365174.58, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(637, 15, 13, '2026-12-29', 3862.43, 2949.49, 912.94, 362225.09, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(638, 15, 14, '2027-01-29', 3862.43, 2956.87, 905.56, 359268.22, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(639, 15, 15, '2027-02-28', 3862.43, 2964.26, 898.17, 356303.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(640, 15, 16, '2027-03-29', 3862.43, 2971.67, 890.76, 353332.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(641, 15, 17, '2027-04-29', 3862.43, 2979.10, 883.33, 350353.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(642, 15, 18, '2027-05-29', 3862.43, 2986.55, 875.88, 347366.64, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(643, 15, 19, '2027-06-29', 3862.43, 2994.01, 868.42, 344372.63, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(644, 15, 20, '2027-07-29', 3862.43, 3001.50, 860.93, 341371.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(645, 15, 21, '2027-08-29', 3862.43, 3009.00, 853.43, 338362.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(646, 15, 22, '2027-09-29', 3862.43, 3016.52, 845.91, 335345.61, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(647, 15, 23, '2027-10-29', 3862.43, 3024.07, 838.36, 332321.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(648, 15, 24, '2027-11-29', 3862.43, 3031.63, 830.80, 329289.91, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(649, 15, 25, '2027-12-29', 3862.43, 3039.21, 823.22, 326250.70, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(650, 15, 26, '2028-01-29', 3862.43, 3046.80, 815.63, 323203.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(651, 15, 27, '2028-02-29', 3862.43, 3054.42, 808.01, 320149.48, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(652, 15, 28, '2028-03-29', 3862.43, 3062.06, 800.37, 317087.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(653, 15, 29, '2028-04-29', 3862.43, 3069.71, 792.72, 314017.71, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(654, 15, 30, '2028-05-29', 3862.43, 3077.39, 785.04, 310940.32, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(655, 15, 31, '2028-06-29', 3862.43, 3085.08, 777.35, 307855.24, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(656, 15, 32, '2028-07-29', 3862.43, 3092.79, 769.64, 304762.45, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(657, 15, 33, '2028-08-29', 3862.43, 3100.52, 761.91, 301661.93, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(658, 15, 34, '2028-09-29', 3862.43, 3108.28, 754.15, 298553.65, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(659, 15, 35, '2028-10-29', 3862.43, 3116.05, 746.38, 295437.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(660, 15, 36, '2028-11-29', 3862.43, 3123.84, 738.59, 292313.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(661, 15, 37, '2028-12-29', 3862.43, 3131.65, 730.78, 289182.11, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(662, 15, 38, '2029-01-29', 3862.43, 3139.47, 722.96, 286042.64, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(663, 15, 39, '2029-02-28', 3862.43, 3147.32, 715.11, 282895.32, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(664, 15, 40, '2029-03-29', 3862.43, 3155.19, 707.24, 279740.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(665, 15, 41, '2029-04-29', 3862.43, 3163.08, 699.35, 276577.05, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(666, 15, 42, '2029-05-29', 3862.43, 3170.99, 691.44, 273406.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(667, 15, 43, '2029-06-29', 3862.43, 3178.91, 683.52, 270227.15, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(668, 15, 44, '2029-07-29', 3862.43, 3186.86, 675.57, 267040.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(669, 15, 45, '2029-08-29', 3862.43, 3194.83, 667.60, 263845.46, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(670, 15, 46, '2029-09-29', 3862.43, 3202.82, 659.61, 260642.64, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(671, 15, 47, '2029-10-29', 3862.43, 3210.82, 651.61, 257431.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(672, 15, 48, '2029-11-29', 3862.43, 3218.85, 643.58, 254212.97, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(673, 15, 49, '2029-12-29', 3862.43, 3226.90, 635.53, 250986.07, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(674, 15, 50, '2030-01-29', 3862.43, 3234.96, 627.47, 247751.11, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(675, 15, 51, '2030-02-28', 3862.43, 3243.05, 619.38, 244508.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(676, 15, 52, '2030-03-29', 3862.43, 3251.16, 611.27, 241256.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(677, 15, 53, '2030-04-29', 3862.43, 3259.29, 603.14, 237997.61, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(678, 15, 54, '2030-05-29', 3862.43, 3267.44, 594.99, 234730.17, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(679, 15, 55, '2030-06-29', 3862.43, 3275.60, 586.83, 231454.57, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(680, 15, 56, '2030-07-29', 3862.43, 3283.79, 578.64, 228170.78, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(681, 15, 57, '2030-08-29', 3862.43, 3292.00, 570.43, 224878.78, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(682, 15, 58, '2030-09-29', 3862.43, 3300.23, 562.20, 221578.55, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(683, 15, 59, '2030-10-29', 3862.43, 3308.48, 553.95, 218270.07, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(684, 15, 60, '2030-11-29', 3862.43, 3316.75, 545.68, 214953.32, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(685, 15, 61, '2030-12-29', 3862.43, 3325.05, 537.38, 211628.27, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(686, 15, 62, '2031-01-29', 3862.43, 3333.36, 529.07, 208294.91, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(687, 15, 63, '2031-02-28', 3862.43, 3341.69, 520.74, 204953.22, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(688, 15, 64, '2031-03-29', 3862.43, 3350.05, 512.38, 201603.17, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(689, 15, 65, '2031-04-29', 3862.43, 3358.42, 504.01, 198244.75, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(690, 15, 66, '2031-05-29', 3862.43, 3366.82, 495.61, 194877.93, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(691, 15, 67, '2031-06-29', 3862.43, 3375.24, 487.19, 191502.69, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(692, 15, 68, '2031-07-29', 3862.43, 3383.67, 478.76, 188119.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(693, 15, 69, '2031-08-29', 3862.43, 3392.13, 470.30, 184726.89, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(694, 15, 70, '2031-09-29', 3862.43, 3400.61, 461.82, 181326.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(695, 15, 71, '2031-10-29', 3862.43, 3409.11, 453.32, 177917.17, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(696, 15, 72, '2031-11-29', 3862.43, 3417.64, 444.79, 174499.53, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(697, 15, 73, '2031-12-29', 3862.43, 3426.18, 436.25, 171073.35, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(698, 15, 74, '2032-01-29', 3862.43, 3434.75, 427.68, 167638.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(699, 15, 75, '2032-02-29', 3862.43, 3443.33, 419.10, 164195.27, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(700, 15, 76, '2032-03-29', 3862.43, 3451.94, 410.49, 160743.33, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(701, 15, 77, '2032-04-29', 3862.43, 3460.57, 401.86, 157282.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(702, 15, 78, '2032-05-29', 3862.43, 3469.22, 393.21, 153813.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(703, 15, 79, '2032-06-29', 3862.43, 3477.90, 384.53, 150335.64, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(704, 15, 80, '2032-07-29', 3862.43, 3486.59, 375.84, 146849.05, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(705, 15, 81, '2032-08-29', 3862.43, 3495.31, 367.12, 143353.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(706, 15, 82, '2032-09-29', 3862.43, 3504.05, 358.38, 139849.69, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(707, 15, 83, '2032-10-29', 3862.43, 3512.81, 349.62, 136336.88, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(708, 15, 84, '2032-11-29', 3862.43, 3521.59, 340.84, 132815.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(709, 15, 85, '2032-12-29', 3862.43, 3530.39, 332.04, 129284.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(710, 15, 86, '2033-01-29', 3862.43, 3539.22, 323.21, 125745.68, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(711, 15, 87, '2033-02-28', 3862.43, 3548.07, 314.36, 122197.61, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(712, 15, 88, '2033-03-29', 3862.43, 3556.94, 305.49, 118640.67, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(713, 15, 89, '2033-04-29', 3862.43, 3565.83, 296.60, 115074.84, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(714, 15, 90, '2033-05-29', 3862.43, 3574.74, 287.69, 111500.10, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(715, 15, 91, '2033-06-29', 3862.43, 3583.68, 278.75, 107916.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(716, 15, 92, '2033-07-29', 3862.43, 3592.64, 269.79, 104323.78, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(717, 15, 93, '2033-08-29', 3862.43, 3601.62, 260.81, 100722.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(718, 15, 94, '2033-09-29', 3862.43, 3610.62, 251.81, 97111.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(719, 15, 95, '2033-10-29', 3862.43, 3619.65, 242.78, 93491.89, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(720, 15, 96, '2033-11-29', 3862.43, 3628.70, 233.73, 89863.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(721, 15, 97, '2033-12-29', 3862.43, 3637.77, 224.66, 86225.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(722, 15, 98, '2034-01-29', 3862.43, 3646.87, 215.56, 82578.55, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(723, 15, 99, '2034-02-28', 3862.43, 3655.98, 206.45, 78922.57, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(724, 15, 100, '2034-03-29', 3862.43, 3665.12, 197.31, 75257.45, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(725, 15, 101, '2034-04-29', 3862.43, 3674.29, 188.14, 71583.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(726, 15, 102, '2034-05-29', 3862.43, 3683.47, 178.96, 67899.69, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(727, 15, 103, '2034-06-29', 3862.43, 3692.68, 169.75, 64207.01, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(728, 15, 104, '2034-07-29', 3862.43, 3701.91, 160.52, 60505.10, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(729, 15, 105, '2034-08-29', 3862.43, 3711.17, 151.26, 56793.93, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(730, 15, 106, '2034-09-29', 3862.43, 3720.45, 141.98, 53073.48, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(731, 15, 107, '2034-10-29', 3862.43, 3729.75, 132.68, 49343.73, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(732, 15, 108, '2034-11-29', 3862.43, 3739.07, 123.36, 45604.66, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(733, 15, 109, '2034-12-29', 3862.43, 3748.42, 114.01, 41856.24, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(734, 15, 110, '2035-01-29', 3862.43, 3757.79, 104.64, 38098.45, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(735, 15, 111, '2035-02-28', 3862.43, 3767.18, 95.25, 34331.27, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(736, 15, 112, '2035-03-29', 3862.43, 3776.60, 85.83, 30554.67, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(737, 15, 113, '2035-04-29', 3862.43, 3786.04, 76.39, 26768.63, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(738, 15, 114, '2035-05-29', 3862.43, 3795.51, 66.92, 22973.12, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(739, 15, 115, '2035-06-29', 3862.43, 3805.00, 57.43, 19168.12, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(740, 15, 116, '2035-07-29', 3862.43, 3814.51, 47.92, 15353.61, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(741, 15, 117, '2035-08-29', 3862.43, 3824.05, 38.38, 11529.56, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(742, 15, 118, '2035-09-29', 3862.43, 3833.61, 28.82, 7695.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(743, 15, 119, '2035-10-29', 3862.43, 3843.19, 19.24, 3852.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(744, 15, 120, '2035-11-29', 9.63, 0.00, 9.63, 0.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(745, 16, 1, '2025-12-29', 4828.04, 3578.04, 1250.00, 496421.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(746, 16, 2, '2026-01-29', 4828.04, 3586.99, 1241.05, 492834.97, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(747, 16, 3, '2026-02-28', 4828.04, 3595.95, 1232.09, 489239.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(748, 16, 4, '2026-03-29', 4828.04, 3604.94, 1223.10, 485634.08, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(749, 16, 5, '2026-04-29', 4828.04, 3613.95, 1214.09, 482020.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(750, 16, 6, '2026-05-29', 4828.04, 3622.99, 1205.05, 478397.14, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(751, 16, 7, '2026-06-29', 4828.04, 3632.05, 1195.99, 474765.09, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(752, 16, 8, '2026-07-29', 4828.04, 3641.13, 1186.91, 471123.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(753, 16, 9, '2026-08-29', 4828.04, 3650.23, 1177.81, 467473.73, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(754, 16, 10, '2026-09-29', 4828.04, 3659.36, 1168.68, 463814.37, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(755, 16, 11, '2026-10-29', 4828.04, 3668.50, 1159.54, 460145.87, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(756, 16, 12, '2026-11-29', 4828.04, 3677.68, 1150.36, 456468.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(757, 16, 13, '2026-12-29', 4828.04, 3686.87, 1141.17, 452781.32, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(758, 16, 14, '2027-01-29', 4828.04, 3696.09, 1131.95, 449085.23, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(759, 16, 15, '2027-02-28', 4828.04, 3705.33, 1122.71, 445379.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(760, 16, 16, '2027-03-29', 4828.04, 3714.59, 1113.45, 441665.31, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(761, 16, 17, '2027-04-29', 4828.04, 3723.88, 1104.16, 437941.43, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(762, 16, 18, '2027-05-29', 4828.04, 3733.19, 1094.85, 434208.24, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(763, 16, 19, '2027-06-29', 4828.04, 3742.52, 1085.52, 430465.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(764, 16, 20, '2027-07-29', 4828.04, 3751.88, 1076.16, 426713.84, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(765, 16, 21, '2027-08-29', 4828.04, 3761.26, 1066.78, 422952.58, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(766, 16, 22, '2027-09-29', 4828.04, 3770.66, 1057.38, 419181.92, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(767, 16, 23, '2027-10-29', 4828.04, 3780.09, 1047.95, 415401.83, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(768, 16, 24, '2027-11-29', 4828.04, 3789.54, 1038.50, 411612.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(769, 16, 25, '2027-12-29', 4828.04, 3799.01, 1029.03, 407813.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(770, 16, 26, '2028-01-29', 4828.04, 3808.51, 1019.53, 404004.77, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(771, 16, 27, '2028-02-29', 4828.04, 3818.03, 1010.01, 400186.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(772, 16, 28, '2028-03-29', 4828.04, 3827.57, 1000.47, 396359.17, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(773, 16, 29, '2028-04-29', 4828.04, 3837.14, 990.90, 392522.03, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(774, 16, 30, '2028-05-29', 4828.04, 3846.73, 981.31, 388675.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(775, 16, 31, '2028-06-29', 4828.04, 3856.35, 971.69, 384818.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(776, 16, 32, '2028-07-29', 4828.04, 3865.99, 962.05, 380952.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(777, 16, 33, '2028-08-29', 4828.04, 3875.66, 952.38, 377077.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(778, 16, 34, '2028-09-29', 4828.04, 3885.35, 942.69, 373191.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(779, 16, 35, '2028-10-29', 4828.04, 3895.06, 932.98, 369296.89, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(780, 16, 36, '2028-11-29', 4828.04, 3904.80, 923.24, 365392.09, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(781, 16, 37, '2028-12-29', 4828.04, 3914.56, 913.48, 361477.53, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(782, 16, 38, '2029-01-29', 4828.04, 3924.35, 903.69, 357553.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(783, 16, 39, '2029-02-28', 4828.04, 3934.16, 893.88, 353619.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(784, 16, 40, '2029-03-29', 4828.04, 3943.99, 884.05, 349675.03, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(785, 16, 41, '2029-04-29', 4828.04, 3953.85, 874.19, 345721.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(786, 16, 42, '2029-05-29', 4828.04, 3963.74, 864.30, 341757.44, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(787, 16, 43, '2029-06-29', 4828.04, 3973.65, 854.39, 337783.79, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(788, 16, 44, '2029-07-29', 4828.04, 3983.58, 844.46, 333800.21, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(789, 16, 45, '2029-08-29', 4828.04, 3993.54, 834.50, 329806.67, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(790, 16, 46, '2029-09-29', 4828.04, 4003.52, 824.52, 325803.15, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(791, 16, 47, '2029-10-29', 4828.04, 4013.53, 814.51, 321789.62, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(792, 16, 48, '2029-11-29', 4828.04, 4023.57, 804.47, 317766.05, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(793, 16, 49, '2029-12-29', 4828.04, 4033.62, 794.42, 313732.43, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(794, 16, 50, '2030-01-29', 4828.04, 4043.71, 784.33, 309688.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(795, 16, 51, '2030-02-28', 4828.04, 4053.82, 774.22, 305634.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(796, 16, 52, '2030-03-29', 4828.04, 4063.95, 764.09, 301570.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(797, 16, 53, '2030-04-29', 4828.04, 4074.11, 753.93, 297496.84, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(798, 16, 54, '2030-05-29', 4828.04, 4084.30, 743.74, 293412.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(799, 16, 55, '2030-06-29', 4828.04, 4094.51, 733.53, 289318.03, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(800, 16, 56, '2030-07-29', 4828.04, 4104.74, 723.30, 285213.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(801, 16, 57, '2030-08-29', 4828.04, 4115.01, 713.03, 281098.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(802, 16, 58, '2030-09-29', 4828.04, 4125.29, 702.75, 276972.99, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(803, 16, 59, '2030-10-29', 4828.04, 4135.61, 692.43, 272837.38, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(804, 16, 60, '2030-11-29', 4828.04, 4145.95, 682.09, 268691.43, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(805, 16, 61, '2030-12-29', 4828.04, 4156.31, 671.73, 264535.12, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(806, 16, 62, '2031-01-29', 4828.04, 4166.70, 661.34, 260368.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(807, 16, 63, '2031-02-28', 4828.04, 4177.12, 650.92, 256191.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(808, 16, 64, '2031-03-29', 4828.04, 4187.56, 640.48, 252003.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(809, 16, 65, '2031-04-29', 4828.04, 4198.03, 630.01, 247805.71, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(810, 16, 66, '2031-05-29', 4828.04, 4208.53, 619.51, 243597.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(811, 16, 67, '2031-06-29', 4828.04, 4219.05, 608.99, 239378.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(812, 16, 68, '2031-07-29', 4828.04, 4229.59, 598.45, 235148.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(813, 16, 69, '2031-08-29', 4828.04, 4240.17, 587.87, 230908.37, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(814, 16, 70, '2031-09-29', 4828.04, 4250.77, 577.27, 226657.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(815, 16, 71, '2031-10-29', 4828.04, 4261.40, 566.64, 222396.20, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(816, 16, 72, '2031-11-29', 4828.04, 4272.05, 555.99, 218124.15, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(817, 16, 73, '2031-12-29', 4828.04, 4282.73, 545.31, 213841.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(818, 16, 74, '2032-01-29', 4828.04, 4293.44, 534.60, 209547.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(819, 16, 75, '2032-02-29', 4828.04, 4304.17, 523.87, 205243.81, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(820, 16, 76, '2032-03-29', 4828.04, 4314.93, 513.11, 200928.88, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(821, 16, 77, '2032-04-29', 4828.04, 4325.72, 502.32, 196603.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(822, 16, 78, '2032-05-29', 4828.04, 4336.53, 491.51, 192266.63, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(823, 16, 79, '2032-06-29', 4828.04, 4347.37, 480.67, 187919.26, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(824, 16, 80, '2032-07-29', 4828.04, 4358.24, 469.80, 183561.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(825, 16, 81, '2032-08-29', 4828.04, 4369.14, 458.90, 179191.88, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(826, 16, 82, '2032-09-29', 4828.04, 4380.06, 447.98, 174811.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(827, 16, 83, '2032-10-29', 4828.04, 4391.01, 437.03, 170420.81, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(828, 16, 84, '2032-11-29', 4828.04, 4401.99, 426.05, 166018.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(829, 16, 85, '2032-12-29', 4828.04, 4412.99, 415.05, 161605.83, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(830, 16, 86, '2033-01-29', 4828.04, 4424.03, 404.01, 157181.80, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(831, 16, 87, '2033-02-28', 4828.04, 4435.09, 392.95, 152746.71, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(832, 16, 88, '2033-03-29', 4828.04, 4446.17, 381.87, 148300.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(833, 16, 89, '2033-04-29', 4828.04, 4457.29, 370.75, 143843.25, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(834, 16, 90, '2033-05-29', 4828.04, 4468.43, 359.61, 139374.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(835, 16, 91, '2033-06-29', 4828.04, 4479.60, 348.44, 134895.22, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(836, 16, 92, '2033-07-29', 4828.04, 4490.80, 337.24, 130404.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(837, 16, 93, '2033-08-29', 4828.04, 4502.03, 326.01, 125902.39, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(838, 16, 94, '2033-09-29', 4828.04, 4513.28, 314.76, 121389.11, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(839, 16, 95, '2033-10-29', 4828.04, 4524.57, 303.47, 116864.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(840, 16, 96, '2033-11-29', 4828.04, 4535.88, 292.16, 112328.66, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(841, 16, 97, '2033-12-29', 4828.04, 4547.22, 280.82, 107781.44, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(842, 16, 98, '2034-01-29', 4828.04, 4558.59, 269.45, 103222.85, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(843, 16, 99, '2034-02-28', 4828.04, 4569.98, 258.06, 98652.87, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(844, 16, 100, '2034-03-29', 4828.04, 4581.41, 246.63, 94071.46, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(845, 16, 101, '2034-04-29', 4828.04, 4592.86, 235.18, 89478.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(846, 16, 102, '2034-05-29', 4828.04, 4604.34, 223.70, 84874.26, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(847, 16, 103, '2034-06-29', 4828.04, 4615.85, 212.19, 80258.41, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(848, 16, 104, '2034-07-29', 4828.04, 4627.39, 200.65, 75631.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(849, 16, 105, '2034-08-29', 4828.04, 4638.96, 189.08, 70992.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(850, 16, 106, '2034-09-29', 4828.04, 4650.56, 177.48, 66341.50, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(851, 16, 107, '2034-10-29', 4828.04, 4662.19, 165.85, 61679.31, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(852, 16, 108, '2034-11-29', 4828.04, 4673.84, 154.20, 57005.47, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(853, 16, 109, '2034-12-29', 4828.04, 4685.53, 142.51, 52319.94, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(854, 16, 110, '2035-01-29', 4828.04, 4697.24, 130.80, 47622.70, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(855, 16, 111, '2035-02-28', 4828.04, 4708.98, 119.06, 42913.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(856, 16, 112, '2035-03-29', 4828.04, 4720.76, 107.28, 38192.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(857, 16, 113, '2035-04-29', 4828.04, 4732.56, 95.48, 33460.40, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(858, 16, 114, '2035-05-29', 4828.04, 4744.39, 83.65, 28716.01, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(859, 16, 115, '2035-06-29', 4828.04, 4756.25, 71.79, 23959.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(860, 16, 116, '2035-07-29', 4828.04, 4768.14, 59.90, 19191.62, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(861, 16, 117, '2035-08-29', 4828.04, 4780.06, 47.98, 14411.56, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(862, 16, 118, '2035-09-29', 4828.04, 4792.01, 36.03, 9619.55, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(863, 16, 119, '2035-10-29', 4828.04, 4803.99, 24.05, 4815.56, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(864, 16, 120, '2035-11-29', 12.04, 0.00, 12.04, 0.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(865, 17, 1, '2025-12-29', 4828.04, 3578.04, 1250.00, 496421.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(866, 17, 2, '2026-01-29', 4828.04, 3586.99, 1241.05, 492834.97, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(867, 17, 3, '2026-02-28', 4828.04, 3595.95, 1232.09, 489239.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(868, 17, 4, '2026-03-29', 4828.04, 3604.94, 1223.10, 485634.08, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(869, 17, 5, '2026-04-29', 4828.04, 3613.95, 1214.09, 482020.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(870, 17, 6, '2026-05-29', 4828.04, 3622.99, 1205.05, 478397.14, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(871, 17, 7, '2026-06-29', 4828.04, 3632.05, 1195.99, 474765.09, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(872, 17, 8, '2026-07-29', 4828.04, 3641.13, 1186.91, 471123.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(873, 17, 9, '2026-08-29', 4828.04, 3650.23, 1177.81, 467473.73, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(874, 17, 10, '2026-09-29', 4828.04, 3659.36, 1168.68, 463814.37, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(875, 17, 11, '2026-10-29', 4828.04, 3668.50, 1159.54, 460145.87, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(876, 17, 12, '2026-11-29', 4828.04, 3677.68, 1150.36, 456468.19, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(877, 17, 13, '2026-12-29', 4828.04, 3686.87, 1141.17, 452781.32, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(878, 17, 14, '2027-01-29', 4828.04, 3696.09, 1131.95, 449085.23, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(879, 17, 15, '2027-02-28', 4828.04, 3705.33, 1122.71, 445379.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(880, 17, 16, '2027-03-29', 4828.04, 3714.59, 1113.45, 441665.31, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(881, 17, 17, '2027-04-29', 4828.04, 3723.88, 1104.16, 437941.43, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(882, 17, 18, '2027-05-29', 4828.04, 3733.19, 1094.85, 434208.24, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(883, 17, 19, '2027-06-29', 4828.04, 3742.52, 1085.52, 430465.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(884, 17, 20, '2027-07-29', 4828.04, 3751.88, 1076.16, 426713.84, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(885, 17, 21, '2027-08-29', 4828.04, 3761.26, 1066.78, 422952.58, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(886, 17, 22, '2027-09-29', 4828.04, 3770.66, 1057.38, 419181.92, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(887, 17, 23, '2027-10-29', 4828.04, 3780.09, 1047.95, 415401.83, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(888, 17, 24, '2027-11-29', 4828.04, 3789.54, 1038.50, 411612.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(889, 17, 25, '2027-12-29', 4828.04, 3799.01, 1029.03, 407813.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(890, 17, 26, '2028-01-29', 4828.04, 3808.51, 1019.53, 404004.77, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(891, 17, 27, '2028-02-29', 4828.04, 3818.03, 1010.01, 400186.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(892, 17, 28, '2028-03-29', 4828.04, 3827.57, 1000.47, 396359.17, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(893, 17, 29, '2028-04-29', 4828.04, 3837.14, 990.90, 392522.03, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(894, 17, 30, '2028-05-29', 4828.04, 3846.73, 981.31, 388675.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(895, 17, 31, '2028-06-29', 4828.04, 3856.35, 971.69, 384818.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(896, 17, 32, '2028-07-29', 4828.04, 3865.99, 962.05, 380952.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(897, 17, 33, '2028-08-29', 4828.04, 3875.66, 952.38, 377077.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(898, 17, 34, '2028-09-29', 4828.04, 3885.35, 942.69, 373191.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(899, 17, 35, '2028-10-29', 4828.04, 3895.06, 932.98, 369296.89, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(900, 17, 36, '2028-11-29', 4828.04, 3904.80, 923.24, 365392.09, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(901, 17, 37, '2028-12-29', 4828.04, 3914.56, 913.48, 361477.53, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(902, 17, 38, '2029-01-29', 4828.04, 3924.35, 903.69, 357553.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(903, 17, 39, '2029-02-28', 4828.04, 3934.16, 893.88, 353619.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(904, 17, 40, '2029-03-29', 4828.04, 3943.99, 884.05, 349675.03, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(905, 17, 41, '2029-04-29', 4828.04, 3953.85, 874.19, 345721.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(906, 17, 42, '2029-05-29', 4828.04, 3963.74, 864.30, 341757.44, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(907, 17, 43, '2029-06-29', 4828.04, 3973.65, 854.39, 337783.79, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(908, 17, 44, '2029-07-29', 4828.04, 3983.58, 844.46, 333800.21, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(909, 17, 45, '2029-08-29', 4828.04, 3993.54, 834.50, 329806.67, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(910, 17, 46, '2029-09-29', 4828.04, 4003.52, 824.52, 325803.15, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(911, 17, 47, '2029-10-29', 4828.04, 4013.53, 814.51, 321789.62, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(912, 17, 48, '2029-11-29', 4828.04, 4023.57, 804.47, 317766.05, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(913, 17, 49, '2029-12-29', 4828.04, 4033.62, 794.42, 313732.43, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(914, 17, 50, '2030-01-29', 4828.04, 4043.71, 784.33, 309688.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(915, 17, 51, '2030-02-28', 4828.04, 4053.82, 774.22, 305634.90, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(916, 17, 52, '2030-03-29', 4828.04, 4063.95, 764.09, 301570.95, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(917, 17, 53, '2030-04-29', 4828.04, 4074.11, 753.93, 297496.84, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(918, 17, 54, '2030-05-29', 4828.04, 4084.30, 743.74, 293412.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(919, 17, 55, '2030-06-29', 4828.04, 4094.51, 733.53, 289318.03, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(920, 17, 56, '2030-07-29', 4828.04, 4104.74, 723.30, 285213.29, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(921, 17, 57, '2030-08-29', 4828.04, 4115.01, 713.03, 281098.28, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(922, 17, 58, '2030-09-29', 4828.04, 4125.29, 702.75, 276972.99, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(923, 17, 59, '2030-10-29', 4828.04, 4135.61, 692.43, 272837.38, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04');
INSERT INTO `payment_schedules` (`id`, `loan_application_id`, `payment_number`, `due_date`, `expected_amount`, `expected_principal`, `expected_interest`, `expected_balance`, `status`, `actual_amount`, `actual_interest`, `actual_date`, `variance`, `created_at`, `updated_at`) VALUES
(924, 17, 60, '2030-11-29', 4828.04, 4145.95, 682.09, 268691.43, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(925, 17, 61, '2030-12-29', 4828.04, 4156.31, 671.73, 264535.12, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(926, 17, 62, '2031-01-29', 4828.04, 4166.70, 661.34, 260368.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(927, 17, 63, '2031-02-28', 4828.04, 4177.12, 650.92, 256191.30, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(928, 17, 64, '2031-03-29', 4828.04, 4187.56, 640.48, 252003.74, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(929, 17, 65, '2031-04-29', 4828.04, 4198.03, 630.01, 247805.71, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(930, 17, 66, '2031-05-29', 4828.04, 4208.53, 619.51, 243597.18, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(931, 17, 67, '2031-06-29', 4828.04, 4219.05, 608.99, 239378.13, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(932, 17, 68, '2031-07-29', 4828.04, 4229.59, 598.45, 235148.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(933, 17, 69, '2031-08-29', 4828.04, 4240.17, 587.87, 230908.37, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(934, 17, 70, '2031-09-29', 4828.04, 4250.77, 577.27, 226657.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(935, 17, 71, '2031-10-29', 4828.04, 4261.40, 566.64, 222396.20, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(936, 17, 72, '2031-11-29', 4828.04, 4272.05, 555.99, 218124.15, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(937, 17, 73, '2031-12-29', 4828.04, 4282.73, 545.31, 213841.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(938, 17, 74, '2032-01-29', 4828.04, 4293.44, 534.60, 209547.98, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(939, 17, 75, '2032-02-29', 4828.04, 4304.17, 523.87, 205243.81, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(940, 17, 76, '2032-03-29', 4828.04, 4314.93, 513.11, 200928.88, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(941, 17, 77, '2032-04-29', 4828.04, 4325.72, 502.32, 196603.16, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(942, 17, 78, '2032-05-29', 4828.04, 4336.53, 491.51, 192266.63, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(943, 17, 79, '2032-06-29', 4828.04, 4347.37, 480.67, 187919.26, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(944, 17, 80, '2032-07-29', 4828.04, 4358.24, 469.80, 183561.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(945, 17, 81, '2032-08-29', 4828.04, 4369.14, 458.90, 179191.88, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(946, 17, 82, '2032-09-29', 4828.04, 4380.06, 447.98, 174811.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(947, 17, 83, '2032-10-29', 4828.04, 4391.01, 437.03, 170420.81, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(948, 17, 84, '2032-11-29', 4828.04, 4401.99, 426.05, 166018.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(949, 17, 85, '2032-12-29', 4828.04, 4412.99, 415.05, 161605.83, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(950, 17, 86, '2033-01-29', 4828.04, 4424.03, 404.01, 157181.80, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(951, 17, 87, '2033-02-28', 4828.04, 4435.09, 392.95, 152746.71, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(952, 17, 88, '2033-03-29', 4828.04, 4446.17, 381.87, 148300.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(953, 17, 89, '2033-04-29', 4828.04, 4457.29, 370.75, 143843.25, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(954, 17, 90, '2033-05-29', 4828.04, 4468.43, 359.61, 139374.82, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(955, 17, 91, '2033-06-29', 4828.04, 4479.60, 348.44, 134895.22, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(956, 17, 92, '2033-07-29', 4828.04, 4490.80, 337.24, 130404.42, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(957, 17, 93, '2033-08-29', 4828.04, 4502.03, 326.01, 125902.39, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(958, 17, 94, '2033-09-29', 4828.04, 4513.28, 314.76, 121389.11, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(959, 17, 95, '2033-10-29', 4828.04, 4524.57, 303.47, 116864.54, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(960, 17, 96, '2033-11-29', 4828.04, 4535.88, 292.16, 112328.66, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(961, 17, 97, '2033-12-29', 4828.04, 4547.22, 280.82, 107781.44, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(962, 17, 98, '2034-01-29', 4828.04, 4558.59, 269.45, 103222.85, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(963, 17, 99, '2034-02-28', 4828.04, 4569.98, 258.06, 98652.87, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(964, 17, 100, '2034-03-29', 4828.04, 4581.41, 246.63, 94071.46, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(965, 17, 101, '2034-04-29', 4828.04, 4592.86, 235.18, 89478.60, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(966, 17, 102, '2034-05-29', 4828.04, 4604.34, 223.70, 84874.26, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(967, 17, 103, '2034-06-29', 4828.04, 4615.85, 212.19, 80258.41, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(968, 17, 104, '2034-07-29', 4828.04, 4627.39, 200.65, 75631.02, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(969, 17, 105, '2034-08-29', 4828.04, 4638.96, 189.08, 70992.06, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(970, 17, 106, '2034-09-29', 4828.04, 4650.56, 177.48, 66341.50, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(971, 17, 107, '2034-10-29', 4828.04, 4662.19, 165.85, 61679.31, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(972, 17, 108, '2034-11-29', 4828.04, 4673.84, 154.20, 57005.47, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(973, 17, 109, '2034-12-29', 4828.04, 4685.53, 142.51, 52319.94, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(974, 17, 110, '2035-01-29', 4828.04, 4697.24, 130.80, 47622.70, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(975, 17, 111, '2035-02-28', 4828.04, 4708.98, 119.06, 42913.72, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(976, 17, 112, '2035-03-29', 4828.04, 4720.76, 107.28, 38192.96, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(977, 17, 113, '2035-04-29', 4828.04, 4732.56, 95.48, 33460.40, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(978, 17, 114, '2035-05-29', 4828.04, 4744.39, 83.65, 28716.01, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(979, 17, 115, '2035-06-29', 4828.04, 4756.25, 71.79, 23959.76, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(980, 17, 116, '2035-07-29', 4828.04, 4768.14, 59.90, 19191.62, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(981, 17, 117, '2035-08-29', 4828.04, 4780.06, 47.98, 14411.56, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(982, 17, 118, '2035-09-29', 4828.04, 4792.01, 36.03, 9619.55, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(983, 17, 119, '2035-10-29', 4828.04, 4803.99, 24.05, 4815.56, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04'),
(984, 17, 120, '2035-11-29', 12.04, 0.00, 12.04, 0.00, 'pending', 0.00, 0.00, NULL, 0.00, '2025-12-05 12:31:04', '2025-12-05 12:31:04');

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

DROP TABLE IF EXISTS `staff`;
CREATE TABLE IF NOT EXISTS `staff` (
  `staff_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `pf_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `id_number` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','accountant','cashier') NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `uk_pf_number` (`pf_number`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `fk_staff_user_id` (`user_id`),
  KEY `fk_staff_created_by` (`created_by`),
  KEY `fk_staff_updated_by` (`updated_by`),
  KEY `idx_staff_role` (`role`),
  KEY `idx_staff_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`staff_id`, `user_id`, `pf_number`, `full_name`, `email`, `id_number`, `password`, `role`, `is_active`, `created_by`, `created_at`, `updated_by`, `updated_at`) VALUES
(1, 1, '10987654321', 'Shawn Muthomi', 'shawnkaboi3@gmail.com', '12345678', '$2y$10$LVCV0MKZD0cA0doMHt1dy.egzbFyKwzP6.fvlVB3BlQfFUyBw2SFm', 'admin', 1, NULL, '2025-11-03 12:26:46', NULL, '2025-11-28 09:26:01'),
(2, 3, '20250000002', 'David Muimi', 'davidmuimi3@gmail.com', '20250000002', '$2a$12$XYEtr0sHmNxBE60X6IbpqOmKHcLQXxGddkdfpkwl4VAxSKVnuhEeC', 'accountant', 1, NULL, '2025-11-04 11:50:06', NULL, '2025-11-28 09:26:01'),
(3, 10, '20250000004', 'Brian Ngari', 'brianngari3@gmail.com', '14563287', '$2y$10$oqOYkGyerIG1qnCdx8lLn.ZUxrtBd4QFHfumZ2uW47j7.tTVz5TQq', 'accountant', 1, NULL, '2025-11-17 06:59:29', NULL, '2025-11-28 09:26:01'),
(5, 2, '20250000001', 'Carson', 'bencarson2@gmail.com', '20250000001', '$2a$12$.3d8rxNUqCdaCneTQbXfe.EwqyKBfTCNUJ9MzRZcvZfKmSbm.P4H2', 'cashier', 1, NULL, '2025-11-04 07:45:53', NULL, '2025-11-28 09:26:01'),
(6, 5, '20250000003', 'Jacob Munene', 'jacob@gmail.com', '145678912', '$2y$10$gxuBrcxUMjnsx1NBihBIeOKE5JspOKRA3VkrY742MhFuWhEXcPswK', 'cashier', 1, NULL, '2025-11-10 09:41:43', NULL, '2025-11-28 09:26:01');

-- --------------------------------------------------------

--
-- Table structure for table `staff_portal_access`
--

DROP TABLE IF EXISTS `staff_portal_access`;
CREATE TABLE IF NOT EXISTS `staff_portal_access` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `can_apply_for_loan` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_updates`
--

DROP TABLE IF EXISTS `staff_updates`;
CREATE TABLE IF NOT EXISTS `staff_updates` (
  `update_id` int NOT NULL AUTO_INCREMENT,
  `staff_id` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `update_type` enum('create','update','delete','activate','deactivate') NOT NULL,
  `field_name` varchar(50) DEFAULT NULL,
  `old_value` text,
  `new_value` text,
  `update_reason` text,
  `update_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`update_id`),
  KEY `fk_staff_updates_staff_id` (`staff_id`),
  KEY `fk_staff_updates_updated_by` (`updated_by`),
  KEY `idx_update_type` (`update_type`),
  KEY `idx_update_date` (`update_date`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `staff_updates`
--

INSERT INTO `staff_updates` (`update_id`, `staff_id`, `updated_by`, `update_type`, `field_name`, `old_value`, `new_value`, `update_reason`, `update_date`) VALUES
(1, 1, NULL, 'create', 'migration', NULL, 'Migrated from users table: {\"role\": \"admin\", \"email\": \"shawnkaboi3@gmail.com\", \"user_id\": 1, \"full_name\": \"Shawn Muthomi\", \"id_number\": \"12345678\", \"pf_number\": 10987654321, \"created_at\": \"2025-11-03 15:26:46.000000\"}', 'Initial migration of users to role-specific tables', '2025-11-28 09:26:01'),
(2, 5, NULL, 'create', 'migration', NULL, 'Migrated from users table: {\"role\": \"cashier\", \"email\": \"bencarson2@gmail.com\", \"user_id\": 2, \"full_name\": \"Carson\", \"id_number\": \"20250000001\", \"pf_number\": 20250000001, \"created_at\": \"2025-11-04 10:45:53.000000\"}', 'Initial migration of users to role-specific tables', '2025-11-28 09:26:01'),
(3, 2, NULL, 'create', 'migration', NULL, 'Migrated from users table: {\"role\": \"accountant\", \"email\": \"davidmuimi3@gmail.com\", \"user_id\": 3, \"full_name\": \"David Muimi\", \"id_number\": \"20250000002\", \"pf_number\": 20250000002, \"created_at\": \"2025-11-04 14:50:06.000000\"}', 'Initial migration of users to role-specific tables', '2025-11-28 09:26:01'),
(4, 6, NULL, 'create', 'migration', NULL, 'Migrated from users table: {\"role\": \"cashier\", \"email\": \"jacob@gmail.com\", \"user_id\": 5, \"full_name\": \"Jacob Munene\", \"id_number\": \"145678912\", \"pf_number\": 20250000003, \"created_at\": \"2025-11-10 12:41:43.000000\"}', 'Initial migration of users to role-specific tables', '2025-11-28 09:26:01'),
(5, 3, NULL, 'create', 'migration', NULL, 'Migrated from users table: {\"role\": \"accountant\", \"email\": \"brianngari3@gmail.com\", \"user_id\": 10, \"full_name\": \"Brian Ngari\", \"id_number\": \"14563287\", \"pf_number\": 20250000004, \"created_at\": \"2025-11-17 09:59:29.000000\"}', 'Initial migration of users to role-specific tables', '2025-11-28 09:26:01');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_updated_by` (`updated_by`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `value`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'user_theme_preference', 'light', 1, 1, '2025-12-08 09:46:34', '2025-12-09 06:33:44');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `id_number` varchar(20) NOT NULL,
  `pf_number` bigint NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('loanee','accountant','cashier','admin') NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1',
  `can_apply_for_loan` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uk_pf_number` (`pf_number`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_id_number` (`id_number`)
) ;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `id_number`, `pf_number`, `password`, `role`, `created_at`, `updated_at`, `is_active`, `can_apply_for_loan`) VALUES
(1, 'Shawn Muthomi', 'shawnkaboi3@gmail.com', '12345678', 10987654321, '$2y$10$LVCV0MKZD0cA0doMHt1dy.egzbFyKwzP6.fvlVB3BlQfFUyBw2SFm', 'admin', '2025-11-03 12:26:46', '2025-12-03 13:48:43', 1, 1),
(2, 'Carson', 'bencarson2@gmail.com', '20250000001', 20250000001, '$2a$12$.3d8rxNUqCdaCneTQbXfe.EwqyKBfTCNUJ9MzRZcvZfKmSbm.P4H2', 'cashier', '2025-11-04 07:45:53', '2025-12-03 14:29:20', 1, 1),
(3, 'David Muimi', 'davidmuimi3@gmail.com', '20250000002', 20250000002, '$2a$12$XYEtr0sHmNxBE60X6IbpqOmKHcLQXxGddkdfpkwl4VAxSKVnuhEeC', 'accountant', '2025-11-04 11:50:06', '2025-11-06 12:53:10', 1, 0),
(4, 'Zion Harrison', 'zionharry191@gmail.com', '123456789', 12345678910, '$2y$10$kBKz7QQIbzEkM0uQuvnvkOYO..FavO.vjjkL0zUDByCrdn0l4KfLe', 'loanee', '2025-11-05 08:37:58', '2025-12-09 06:41:15', 1, 0),
(5, 'Jacob Munene', 'jacob@gmail.com', '145678912', 20250000003, '$2y$10$gxuBrcxUMjnsx1NBihBIeOKE5JspOKRA3VkrY742MhFuWhEXcPswK', 'cashier', '2025-11-10 09:41:43', '2025-11-18 09:19:13', 1, 0),
(8, 'John Doe', 'john@gmail.com', '87564213', 12345678999, '$2a$12$FznwfIY5eZ8fSzMxyj27I.97mDfuKIyUDZriGEtTkPobZNPBNeLTK', 'loanee', '2025-11-10 13:45:27', '2025-11-14 07:55:55', 1, 0),
(9, 'Test ONE', 'test@gmail.com', '45674123', 14756321457, '$2y$12$79bfwuIVZewJOuKDfhmEZ.bK.Rz7LYQNNE1AWR3WOYoqGc7hIogeq', 'loanee', '2025-11-14 10:06:29', '2025-12-05 12:46:15', 1, 0),
(10, 'Brian Ngari', 'brianngari3@gmail.com', '14563287', 20250000004, '$2y$10$oqOYkGyerIG1qnCdx8lLn.ZUxrtBd4QFHfumZ2uW47j7.tTVz5TQq', 'accountant', '2025-11-17 06:59:29', '2025-12-05 12:49:46', 1, 0),
(11, 'Antony Bundi', 'anto@gmail.com', '145235789', 20250007896, '$2y$12$v8bi9Hz2oLoawPXbx15N7uvBjuRvSCCu3xvVpusJ/LZqyPUCaGWu.', 'loanee', '2025-11-20 09:27:11', '2025-12-09 06:40:27', 1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_preferences`
--

DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `preference_key` varchar(100) NOT NULL,
  `preference_value` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_preference` (`user_id`,`preference_key`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_active_loans_summary`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_active_loans_summary`;
CREATE TABLE IF NOT EXISTS `v_active_loans_summary` (
`application_id` varchar(50)
,`full_name` varchar(100)
,`loan_amount` decimal(12,2)
,`loan_type` enum('mortgage','car')
,`status` enum('pending','forwarded','accepted','dispatched','active','rejected','returned','closed')
,`application_date` timestamp
,`dispatch_date` datetime
,`applicant_name` varchar(100)
,`applicant_email` varchar(100)
,`total_payments` bigint
,`total_paid` decimal(32,2)
,`outstanding_balance` decimal(33,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_loan_applications_full`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `v_loan_applications_full`;
CREATE TABLE IF NOT EXISTS `v_loan_applications_full` (
`id` int
,`user_id` int
,`application_id` varchar(50)
,`full_name` varchar(100)
,`id_number` varchar(50)
,`pf_number` bigint
,`email` varchar(100)
,`loan_type` enum('mortgage','car')
,`loan_amount` decimal(12,2)
,`payment_duration` int
,`payment_frequency` enum('monthly')
,`basic_salary` decimal(12,2)
,`employment_type` enum('permanent','contracted')
,`contract_period` int
,`contract_start_date` date
,`contract_end_date` date
,`property_location` varchar(255)
,`property_value` decimal(12,2)
,`car_type` varchar(100)
,`car_value` decimal(12,2)
,`bank_name` enum('Family Bank')
,`bank_branch` varchar(100)
,`bank_account_number` varchar(50)
,`status` enum('pending','forwarded','accepted','dispatched','active','rejected','returned','closed')
,`application_date` timestamp
,`dispatch_date` datetime
,`closed_date` datetime
,`feedback` text
,`cashier_processed_date` datetime
,`accountant_processed_date` datetime
,`created_at` timestamp
,`updated_at` timestamp
,`user_full_name` varchar(100)
,`user_email` varchar(100)
,`user_role` enum('loanee','accountant','cashier','admin')
,`user_pf_number` bigint
,`user_id_number` varchar(20)
,`detail_property_location` varchar(255)
,`detail_property_value` decimal(12,2)
,`detail_car_model` varchar(100)
,`detail_car_value` decimal(12,2)
);

-- --------------------------------------------------------

--
-- Structure for view `loan_applications_with_users`
--
DROP TABLE IF EXISTS `loan_applications_with_users`;

DROP VIEW IF EXISTS `loan_applications_with_users`;
CREATE ALGORITHM=UNDEFINED DEFINER=`Harry`@`localhost` SQL SECURITY DEFINER VIEW `loan_applications_with_users`  AS SELECT `la`.`id` AS `id`, `la`.`user_id` AS `user_id`, `la`.`application_id` AS `application_id`, `la`.`full_name` AS `full_name`, `la`.`id_number` AS `id_number`, `la`.`pf_number` AS `pf_number`, `la`.`email` AS `email`, `la`.`loan_type` AS `loan_type`, `la`.`loan_amount` AS `loan_amount`, `la`.`payment_duration` AS `payment_duration`, `la`.`payment_frequency` AS `payment_frequency`, `la`.`basic_salary` AS `basic_salary`, `la`.`employment_type` AS `employment_type`, `la`.`contract_period` AS `contract_period`, `la`.`contract_start_date` AS `contract_start_date`, `la`.`contract_end_date` AS `contract_end_date`, `la`.`property_location` AS `property_location`, `la`.`property_value` AS `property_value`, `la`.`car_type` AS `car_type`, `la`.`car_value` AS `car_value`, `la`.`bank_name` AS `bank_name`, `la`.`bank_branch` AS `bank_branch`, `la`.`bank_account_number` AS `bank_account_number`, `la`.`status` AS `status`, `la`.`application_date` AS `application_date`, `la`.`dispatch_date` AS `dispatch_date`, `la`.`closed_date` AS `closed_date`, `la`.`feedback` AS `feedback`, `la`.`cashier_processed_date` AS `cashier_processed_date`, `la`.`accountant_processed_date` AS `accountant_processed_date`, `la`.`created_at` AS `created_at`, `la`.`updated_at` AS `updated_at`, `u`.`full_name` AS `user_full_name`, `u`.`email` AS `user_email`, `u`.`id_number` AS `user_id_number`, `u`.`pf_number` AS `user_pf_number`, `u`.`role` AS `user_role` FROM (`loan_applications` `la` left join `users` `u` on((`la`.`user_id` = `u`.`user_id`)))union select `la`.`id` AS `id`,`la`.`user_id` AS `user_id`,`la`.`application_id` AS `application_id`,`la`.`full_name` AS `full_name`,`la`.`id_number` AS `id_number`,`la`.`pf_number` AS `pf_number`,`la`.`email` AS `email`,`la`.`loan_type` AS `loan_type`,`la`.`loan_amount` AS `loan_amount`,`la`.`payment_duration` AS `payment_duration`,`la`.`payment_frequency` AS `payment_frequency`,`la`.`basic_salary` AS `basic_salary`,`la`.`employment_type` AS `employment_type`,`la`.`contract_period` AS `contract_period`,`la`.`contract_start_date` AS `contract_start_date`,`la`.`contract_end_date` AS `contract_end_date`,`la`.`property_location` AS `property_location`,`la`.`property_value` AS `property_value`,`la`.`car_type` AS `car_type`,`la`.`car_value` AS `car_value`,`la`.`bank_name` AS `bank_name`,`la`.`bank_branch` AS `bank_branch`,`la`.`bank_account_number` AS `bank_account_number`,`la`.`status` AS `status`,`la`.`application_date` AS `application_date`,`la`.`dispatch_date` AS `dispatch_date`,`la`.`closed_date` AS `closed_date`,`la`.`feedback` AS `feedback`,`la`.`cashier_processed_date` AS `cashier_processed_date`,`la`.`accountant_processed_date` AS `accountant_processed_date`,`la`.`created_at` AS `created_at`,`la`.`updated_at` AS `updated_at`,`u`.`full_name` AS `user_full_name`,`u`.`email` AS `user_email`,`u`.`id_number` AS `user_id_number`,`u`.`pf_number` AS `user_pf_number`,`u`.`role` AS `user_role` from (`loan_applications` `la` left join `users` `u` on((`la`.`pf_number` = `u`.`pf_number`))) where ((`la`.`user_id` is null) or (`la`.`user_id` = 0))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_active_loans_summary`
--
DROP TABLE IF EXISTS `v_active_loans_summary`;

DROP VIEW IF EXISTS `v_active_loans_summary`;
CREATE ALGORITHM=UNDEFINED DEFINER=`Harry`@`localhost` SQL SECURITY DEFINER VIEW `v_active_loans_summary`  AS SELECT `la`.`application_id` AS `application_id`, `la`.`full_name` AS `full_name`, `la`.`loan_amount` AS `loan_amount`, `la`.`loan_type` AS `loan_type`, `la`.`status` AS `status`, `la`.`application_date` AS `application_date`, `la`.`dispatch_date` AS `dispatch_date`, `u`.`full_name` AS `applicant_name`, `u`.`email` AS `applicant_email`, count(`p`.`payment_id`) AS `total_payments`, coalesce(sum(`p`.`amount`),0) AS `total_paid`, (`la`.`loan_amount` - coalesce(sum(`p`.`amount`),0)) AS `outstanding_balance` FROM ((`loan_applications` `la` left join `users` `u` on((`la`.`user_id` = `u`.`user_id`))) left join `payments` `p` on((`la`.`application_id` = `p`.`application_id`))) WHERE (`la`.`status` in ('active','dispatched')) GROUP BY `la`.`application_id`, `la`.`full_name`, `la`.`loan_amount`, `la`.`loan_type`, `la`.`status`, `la`.`application_date`, `la`.`dispatch_date`, `u`.`full_name`, `u`.`email` ;

-- --------------------------------------------------------

--
-- Structure for view `v_loan_applications_full`
--
DROP TABLE IF EXISTS `v_loan_applications_full`;

DROP VIEW IF EXISTS `v_loan_applications_full`;
CREATE ALGORITHM=UNDEFINED DEFINER=`Harry`@`localhost` SQL SECURITY DEFINER VIEW `v_loan_applications_full`  AS SELECT `la`.`id` AS `id`, `la`.`user_id` AS `user_id`, `la`.`application_id` AS `application_id`, `la`.`full_name` AS `full_name`, `la`.`id_number` AS `id_number`, `la`.`pf_number` AS `pf_number`, `la`.`email` AS `email`, `la`.`loan_type` AS `loan_type`, `la`.`loan_amount` AS `loan_amount`, `la`.`payment_duration` AS `payment_duration`, `la`.`payment_frequency` AS `payment_frequency`, `la`.`basic_salary` AS `basic_salary`, `la`.`employment_type` AS `employment_type`, `la`.`contract_period` AS `contract_period`, `la`.`contract_start_date` AS `contract_start_date`, `la`.`contract_end_date` AS `contract_end_date`, `la`.`property_location` AS `property_location`, `la`.`property_value` AS `property_value`, `la`.`car_type` AS `car_type`, `la`.`car_value` AS `car_value`, `la`.`bank_name` AS `bank_name`, `la`.`bank_branch` AS `bank_branch`, `la`.`bank_account_number` AS `bank_account_number`, `la`.`status` AS `status`, `la`.`application_date` AS `application_date`, `la`.`dispatch_date` AS `dispatch_date`, `la`.`closed_date` AS `closed_date`, `la`.`feedback` AS `feedback`, `la`.`cashier_processed_date` AS `cashier_processed_date`, `la`.`accountant_processed_date` AS `accountant_processed_date`, `la`.`created_at` AS `created_at`, `la`.`updated_at` AS `updated_at`, `u`.`full_name` AS `user_full_name`, `u`.`email` AS `user_email`, `u`.`role` AS `user_role`, `u`.`pf_number` AS `user_pf_number`, `u`.`id_number` AS `user_id_number`, `ld`.`property_location` AS `detail_property_location`, `ld`.`property_value` AS `detail_property_value`, `ld`.`car_model` AS `detail_car_model`, `ld`.`car_value` AS `detail_car_value` FROM ((`loan_applications` `la` left join `users` `u` on((`la`.`user_id` = `u`.`user_id`))) left join `loandetails` `ld` on((`la`.`application_id` = `ld`.`application_id`))) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `loan_applications`
--
ALTER TABLE `loan_applications` ADD FULLTEXT KEY `ft_full_name` (`full_name`);
ALTER TABLE `loan_applications` ADD FULLTEXT KEY `ft_application_id` (`application_id`);
ALTER TABLE `loan_applications` ADD FULLTEXT KEY `ft_email` (`email`);
ALTER TABLE `loan_applications` ADD FULLTEXT KEY `ft_feedback` (`feedback`);

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accountant_decisions`
--
ALTER TABLE `accountant_decisions`
  ADD CONSTRAINT `fk_accountant_decisions_accountant` FOREIGN KEY (`accountant_id`) REFERENCES `accountants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_accountant_decisions_loan` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `api_key_logs`
--
ALTER TABLE `api_key_logs`
  ADD CONSTRAINT `api_key_logs_ibfk_1` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `funding_transactions`
--
ALTER TABLE `funding_transactions`
  ADD CONSTRAINT `fk_funding_transactions_funding_account` FOREIGN KEY (`funding_account_id`) REFERENCES `funding_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_funding_transactions_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `staff`
--
ALTER TABLE `staff`
  ADD CONSTRAINT `fk_staff_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `staff_updates`
--
ALTER TABLE `staff_updates`
  ADD CONSTRAINT `fk_staff_updates_staff_id` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_updates_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `fk_system_settings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_system_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
