-- Fix: Recreate v_unified_loans with explicit COLLATE to resolve
-- SQLSTATE[HY000]: General error: 1271 Illegal mix of collations for operation 'UNION'
-- Tables use utf8mb4_general_ci; DB connection defaults to utf8mb4_0900_ai_ci.
-- We cast all string literals to utf8mb4_general_ci to match the table columns.

DROP VIEW IF EXISTS `v_unified_loans`;

CREATE VIEW `v_unified_loans` AS
  -- Branch 1: Standard loan applications (columns already utf8mb4_general_ci)
  SELECT
    la.id,
    la.user_id,
    la.application_id,
    la.full_name,
    la.id_number,
    CAST(la.pf_number AS CHAR(50)) AS pf_number,
    la.email,
    la.loan_type,
    la.loan_amount,
    la.payment_duration,
    la.payment_frequency,
    la.basic_salary,
    la.employment_type,
    la.contract_period,
    la.contract_start_date,
    la.contract_end_date,
    la.property_location,
    la.property_value,
    la.car_type,
    la.car_value,
    la.bank_name,
    la.bank_branch,
    la.bank_account_number,
    la.status,
    la.application_date,
    la.dispatch_date,
    la.closed_date,
    la.feedback,
    la.cashier_processed_date,
    la.accountant_processed_date,
    la.created_at,
    la.updated_at,
    CAST(NULL AS CHAR(20)) AS financial_year,
    CONVERT('standard' USING utf8mb4) COLLATE utf8mb4_general_ci AS record_type
  FROM `loan_applications` la

UNION ALL

  -- Branch 2: Legacy / migrated outstanding debtors
  -- Wrap every literal in CONVERT(...) COLLATE utf8mb4_general_ci to match branch 1
  SELECT
    od.debtor_id AS id,
    od.created_by AS user_id,
    CONVERT(CONCAT('MIGRATED-', LPAD(od.debtor_id, 10, '0')) USING utf8mb4) COLLATE utf8mb4_general_ci AS application_id,
    CONVERT(od.name USING utf8mb4)  COLLATE utf8mb4_general_ci AS full_name,
    CONVERT(COALESCE(od.id_number, 'LEGACY-IMPORT') USING utf8mb4) COLLATE utf8mb4_general_ci AS id_number,
    CONVERT(COALESCE(od.pf_number, CONCAT('LEGACY-', LPAD(od.debtor_id, 6, '0'))) USING utf8mb4) COLLATE utf8mb4_general_ci AS pf_number,
    CAST(NULL AS CHAR(100)) AS email,
    CONVERT('mortgage'  USING utf8mb4) COLLATE utf8mb4_general_ci AS loan_type,
    od.amount AS loan_amount,
    12 AS payment_duration,
    CONVERT('monthly'   USING utf8mb4) COLLATE utf8mb4_general_ci AS payment_frequency,
    0 AS basic_salary,
    CONVERT('permanent' USING utf8mb4) COLLATE utf8mb4_general_ci AS employment_type,
    CAST(NULL AS UNSIGNED) AS contract_period,
    CAST(NULL AS DATE) AS contract_start_date,
    CAST(NULL AS DATE) AS contract_end_date,
    CAST(NULL AS CHAR(255)) AS property_location,
    CAST(NULL AS DECIMAL(15,2)) AS property_value,
    CAST(NULL AS CHAR(100)) AS car_type,
    CAST(NULL AS DECIMAL(15,2)) AS car_value,
    CONVERT('Family Bank' USING utf8mb4) COLLATE utf8mb4_general_ci AS bank_name,
    CONVERT('' USING utf8mb4) COLLATE utf8mb4_general_ci AS bank_branch,
    CONVERT(CONCAT('LEGACY-', LPAD(od.debtor_id, 10, '0')) USING utf8mb4) COLLATE utf8mb4_general_ci AS bank_account_number,
    CONVERT(COALESCE(od.status, 'active') USING utf8mb4) COLLATE utf8mb4_general_ci AS status,
    od.created_at AS application_date,
    od.created_at AS dispatch_date,
    CAST(NULL AS DATETIME) AS closed_date,
    CONVERT(CONCAT('Migrated from Outstanding Debtors - FY: ', od.financial_year) USING utf8mb4) COLLATE utf8mb4_general_ci AS feedback,
    CAST(NULL AS DATETIME) AS cashier_processed_date,
    CAST(NULL AS DATETIME) AS accountant_processed_date,
    od.created_at,
    od.updated_at,
    od.financial_year,
    CONVERT('legacy' USING utf8mb4) COLLATE utf8mb4_general_ci AS record_type
  FROM `outstanding_debtors` od;
