<?php
/**
 * Settings Class
 * Handles system settings and configuration
 */
class Settings
{
    private $conn;
    private $table = 'system_settings';

    // Properties
    public $setting_id;
    public $setting_key;
    public $setting_value;
    public $description;
    public $updated_at;
    public $updated_by;

    public function __construct($db)
    {
        $this->conn = $db->getConnection();
    }

    /**
     * Get setting by key
     */
    public function getByKey($key)
    {
        $query = "SELECT * FROM " . $this->table . " WHERE setting_key = :key LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':key', $key);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get setting value by key (returns only the value)
     */
    public function getValue($key, $default = null)
    {
        $setting = $this->getByKey($key);
        return $setting ? $setting['value'] : $default;
    }

    /**
     * Get all settings
     */
    public function getAllSettings()
    {
        $query = "SELECT * FROM " . $this->table . " ORDER BY setting_key ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update setting value
     */
    public function updateValue($key, $value, $updatedBy = null)
    {
        // Check if setting exists
        $existing = $this->getByKey($key);

        if ($existing) {
            // Update existing setting
            $query = "UPDATE " . $this->table . " 
                      SET value = :value,
                          updated_at = NOW(),
                          updated_by = :updated_by
                      WHERE setting_key = :key";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':value', $value);
            $stmt->bindParam(':updated_by', $updatedBy);
            $stmt->bindParam(':key', $key);

            return $stmt->execute();
        } else {
            // Create new setting
            $query = "INSERT INTO " . $this->table . " 
                      SET setting_key = :key,
                          value = :value,
                          updated_at = NOW(),
                          updated_by = :updated_by";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':key', $key);
            $stmt->bindParam(':value', $value);
            $stmt->bindParam(':updated_by', $updatedBy);

            return $stmt->execute();
        }
    }

    /**
     * Create new setting
     */
    public function create()
    {
        $query = "INSERT INTO " . $this->table . " 
                  SET setting_key = :setting_key,
                      value = :value,
                      description = :description,
                      updated_at = NOW(),
                      updated_by = :updated_by";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':setting_key', $this->setting_key);
        $stmt->bindParam(':value', $this->setting_value);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':updated_by', $this->updated_by);

        if ($stmt->execute()) {
            $this->setting_id = $this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    /**
     * Update setting
     */
    public function update()
    {
        $query = "UPDATE " . $this->table . " 
                  SET value = :value,
                      description = :description,
                      updated_at = NOW(),
                      updated_by = :updated_by
                  WHERE setting_key = :setting_key";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':value', $this->setting_value);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':updated_by', $this->updated_by);
        $stmt->bindParam(':setting_key', $this->setting_key);

        return $stmt->execute();
    }

    /**
     * Delete setting
     */
    public function delete($key)
    {
        $query = "DELETE FROM " . $this->table . " WHERE setting_key = :key";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':key', $key);
        return $stmt->execute();
    }

    /**
     * Get interest rate (most commonly used setting)
     */
    public function getInterestRate()
    {
        return floatval($this->getValue('interest_rate', 3.0));
    }

    /**
     * Update interest rate
     */
    public function updateInterestRate($rate, $updatedBy = null)
    {
        return $this->updateValue('interest_rate', $rate, $updatedBy);
    }

    /**
     * Get maximum loan amount
     */
    public function getMaxLoanAmount()
    {
        return floatval($this->getValue('max_loan_amount', 10000000));
    }

    /**
     * Get minimum loan amount
     */
    public function getMinLoanAmount()
    {
        return floatval($this->getValue('min_loan_amount', 50000));
    }

    /**
     * Get maximum loan term (years)
     */
    public function getMaxLoanTerm()
    {
        return floatval($this->getValue('max_loan_term_years', 30));
    }

    /**
     * Get minimum loan term (years)
     */
    public function getMinLoanTerm()
    {
        return floatval($this->getValue('min_loan_term_years', 1));
    }

    /**
     * Get system name
     */
    public function getSystemName()
    {
        return $this->getValue('system_name', 'Loan Management System');
    }

    /**
     * Get system email
     */
    public function getSystemEmail()
    {
        return $this->getValue('system_email', 'admin@loansystem.com');
    }

    /**
     * Get allowed bank name
     */
    public function getAllowedBankName()
    {
        return $this->getValue('allowed_bank_name', 'Family Bank');
    }

    /**
     * Get settings by category (if you add categories)
     */
    public function getByCategory($category)
    {
        $query = "SELECT * FROM " . $this->table . " 
                  WHERE setting_key LIKE :category 
                  ORDER BY setting_key ASC";

        $stmt = $this->conn->prepare($query);
        $searchPattern = $category . '%';
        $stmt->bindParam(':category', $searchPattern);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Bulk update settings
     */
    public function bulkUpdate($settings, $updatedBy = null)
    {
        $success = true;

        foreach ($settings as $key => $value) {
            if (!$this->updateValue($key, $value, $updatedBy)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Validate setting value
     */
    public function validateValue($key, $value)
    {
        $errors = [];

        switch ($key) {
            case 'interest_rate':
                if (!is_numeric($value) || $value < 0 || $value > 100) {
                    $errors[] = "Interest rate must be between 0 and 100";
                }
                break;

            case 'max_loan_amount':
            case 'min_loan_amount':
                if (!is_numeric($value) || $value < 0) {
                    $errors[] = "Loan amount must be a positive number";
                }
                break;

            case 'max_loan_term_years':
            case 'min_loan_term_years':
                if (!is_numeric($value) || $value <= 0) {
                    $errors[] = "Loan term must be greater than 0";
                }
                break;

            case 'system_email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid email address";
                }
                break;

            case 'salary_deduction_limit':
                if (!is_numeric($value) || $value < 0 || $value > 1) {
                    $errors[] = "Salary deduction limit must be between 0 and 1";
                }
                break;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get settings as key-value array
     */
    public function getAsArray()
    {
        $settings = $this->getAllSettings();
        $result = [];

        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = $setting['value'];
        }

        return $result;
    }

    /**
     * Export settings to JSON
     */
    public function exportToJson()
    {
        return json_encode($this->getAllSettings(), JSON_PRETTY_PRINT);
    }

    /**
     * Import settings from JSON
     */
    public function importFromJson($json, $updatedBy = null)
    {
        $settings = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        foreach ($settings as $setting) {
            if (isset($setting['setting_key']) && isset($setting['value'])) {
                $this->updateValue($setting['setting_key'], $setting['value'], $updatedBy);
            }
        }

        return true;
    }
}