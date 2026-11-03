<?php
class User {
    // Database connection and table name
    private $conn;
    private $table_name = "users";
    
    // User properties
    public $user_id;
    public $full_name;
    public $pf_number;
    public $id_number;
    public $email;
    public $password;
    public $role;
    public $created_at;
    public $updated_at;
    public $is_active;
    public $can_apply_for_loan; // NEW: Field for staff portal access
    
    // Constructor with database connection
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Create new user
    public function create() {
        try {
            // Start transaction
            $this->conn->beginTransaction();
            
            // Hash password
            $hashed_password = password_hash($this->password, PASSWORD_DEFAULT);
            
            // Insert into users table
            $query = "INSERT INTO " . $this->table_name . "
                     (full_name, email, id_number, pf_number, password, role, is_active, can_apply_for_loan)
                     VALUES
                     (:full_name, :email, :id_number, :pf_number, :password, :role, 1, :can_apply_for_loan)";
            
            $stmt = $this->conn->prepare($query);
            
            // Sanitize and bind values
            $this->full_name = htmlspecialchars(strip_tags($this->full_name));
            $this->email = htmlspecialchars(strip_tags($this->email));
            $this->id_number = htmlspecialchars(strip_tags($this->id_number));
            $this->pf_number = htmlspecialchars(strip_tags($this->pf_number));
            $this->role = htmlspecialchars(strip_tags($this->role));
            
            // Default can_apply_for_loan to 0 for new users
            $can_apply_for_loan = isset($this->can_apply_for_loan) ? (int)$this->can_apply_for_loan : 0;
            
            $stmt->bindParam(":full_name", $this->full_name);
            $stmt->bindParam(":email", $this->email);
            $stmt->bindParam(":id_number", $this->id_number);
            $stmt->bindParam(":pf_number", $this->pf_number);
            $stmt->bindParam(":password", $hashed_password);
            $stmt->bindParam(":role", $this->role);
            $stmt->bindParam(":can_apply_for_loan", $can_apply_for_loan);
            
            // Execute query
            if(!$stmt->execute()) {
                $this->conn->rollBack();
                return false;
            }
            
            $this->user_id = $this->conn->lastInsertId();
            
            // Insert into role-specific table
            $roleInserted = $this->insertIntoRoleTable($hashed_password);
            
            if(!$roleInserted) {
                $this->conn->rollBack();
                return false;
            }
            
            // Commit transaction
            $this->conn->commit();
            return true;
            
        } catch (PDOException $e) {
            // Rollback on error
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("User creation error: " . $e->getMessage());
            return false;
        }
    }
    
    // Helper method to insert into role-specific tables
    private function insertIntoRoleTable($hashed_password) {
        try {
            switch($this->role) {
                case 'admin':
                    $query = "INSERT INTO admins (pf_number, full_name, email, password_hash, role, is_active, created_at, updated_at)
                             VALUES (:pf_number, :full_name, :email, :password_hash, 'admin', 1, NOW(), NOW())";
                    break;
                    
                case 'accountant':
                    $query = "INSERT INTO accountants (pf_number, full_name, email, password_hash, role, is_active, created_at, updated_at)
                             VALUES (:pf_number, :full_name, :email, :password_hash, 'accountant', 1, NOW(), NOW())";
                    break;
                    
                case 'cashier':
                    $query = "INSERT INTO cashiers (pf_number, full_name, email, password_hash, role, is_active, created_at, updated_at)
                             VALUES (:pf_number, :full_name, :email, :password_hash, 'cashier', 1, NOW(), NOW())";
                    break;
                    
                case 'loanee':
                    $query = "INSERT INTO loanee (pf_number, full_name, email, password_hash, role, is_active, created_at, updated_at)
                             VALUES (:pf_number, :full_name, :email, :password_hash, 'loanee', 1, NOW(), NOW())";
                    break;
                    
                default:
                    error_log("Unknown role: " . $this->role);
                    return false;
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":pf_number", $this->pf_number);
            $stmt->bindParam(":full_name", $this->full_name);
            $stmt->bindParam(":email", $this->email);
            $stmt->bindParam(":password_hash", $hashed_password);
            
            return $stmt->execute();
            
        } catch (PDOException $e) {
            error_log("Role table insertion error: " . $e->getMessage());
            return false;
        }
    }
    
    // Also update the update() method to sync with role tables
    public function update() {
        try {
            // Start transaction
            $this->conn->beginTransaction();
            
            // Update users table
            $query = "UPDATE " . $this->table_name . "
                     SET full_name = :full_name,
                         email = :email,
                         id_number = :id_number,
                         pf_number = :pf_number,
                         role = :role,
                         updated_at = NOW(),
                         can_apply_for_loan = :can_apply_for_loan
                     WHERE user_id = :user_id";
            
            $stmt = $this->conn->prepare($query);
            
            // Sanitize and bind values
            $this->full_name = htmlspecialchars(strip_tags($this->full_name));
            $this->email = htmlspecialchars(strip_tags($this->email));
            $this->id_number = htmlspecialchars(strip_tags($this->id_number));
            $this->pf_number = htmlspecialchars(strip_tags($this->pf_number));
            $this->role = htmlspecialchars(strip_tags($this->role));
            
            // Handle can_apply_for_loan
            if (isset($this->can_apply_for_loan)) {
                $can_apply_for_loan = (int)$this->can_apply_for_loan;
            } else {
                $query = "SELECT can_apply_for_loan FROM " . $this->table_name . " WHERE user_id = :user_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":user_id", $this->user_id);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $can_apply_for_loan = $result['can_apply_for_loan'];
            }
            
            $stmt->bindParam(":full_name", $this->full_name);
            $stmt->bindParam(":email", $this->email);
            $stmt->bindParam(":id_number", $this->id_number);
            $stmt->bindParam(":pf_number", $this->pf_number);
            $stmt->bindParam(":role", $this->role);
            $stmt->bindParam(":can_apply_for_loan", $can_apply_for_loan);
            $stmt->bindParam(":user_id", $this->user_id);
            
            if(!$stmt->execute()) {
                $this->conn->rollBack();
                return false;
            }
            
            // Update role-specific table
            $roleUpdated = $this->updateRoleTable();
            
            if(!$roleUpdated) {
                $this->conn->rollBack();
                return false;
            }
            
            // Commit transaction
            $this->conn->commit();
            return true;
            
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("User update error: " . $e->getMessage());
            return false;
        }
    }
    
    // Helper method to update role-specific tables
private function updateRoleTable() {
    try {
        switch($this->role) {
            case 'admin':
                $table = 'admins';
                break;
            case 'accountant':
                $table = 'accountants';
                break;
            case 'cashier':
                $table = 'cashiers';
                break;
            case 'loanee':
                $table = 'loanee';
                break;
            default:
                return false;
        }
        
        $query = "UPDATE {$table}
                 SET full_name = :full_name,
                     email = :email,
                     updated_at = NOW()
                 WHERE pf_number = :pf_number";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":full_name", $this->full_name);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":pf_number", $this->pf_number);
        
        return $stmt->execute();
        
    } catch (PDOException $e) {
        error_log("Role table update error: " . $e->getMessage());
        return false;
    }
}
    
    // Delete user
    public function delete() {
        try {
            // Delete query
            $query = "DELETE FROM " . $this->table_name . " WHERE user_id = :user_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $this->user_id);
            
            // Execute query
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("User deletion error: " . $e->getMessage());
            return false;
        }
    }
    
    // Get user by ID
    public function getById($id) {
        try {
            $query = "SELECT * FROM " . $this->table_name . " WHERE user_id = :user_id LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $id);
            $stmt->execute();
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if($row) {
                // Set properties
                $this->user_id = $row['user_id'];
                $this->full_name = $row['full_name'];
                $this->email = $row['email'];
                $this->id_number = $row['id_number'];
                $this->pf_number = $row['pf_number'];
                $this->password = $row['password'];
                $this->role = $row['role'];
                $this->created_at = $row['created_at'];
                $this->updated_at = $row['updated_at'];
                $this->is_active = $row['is_active'];
                $this->can_apply_for_loan = $row['can_apply_for_loan']; // NEW: Set can_apply_for_loan property
                
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Get user by ID error: " . $e->getMessage());
            return false;
        }
    }
    
    // Get user by email (PRIMARY METHOD)
    public function getByEmail($email) {
        try {
            $query = "SELECT * FROM " . $this->table_name . " WHERE email = :email LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if($row) {
                // Set properties
                $this->user_id = $row['user_id'];
                $this->full_name = $row['full_name'];
                $this->email = $row['email'];
                $this->id_number = $row['id_number'];
                $this->pf_number = $row['pf_number'];
                $this->password = $row['password'];
                $this->role = $row['role'];
                $this->created_at = $row['created_at'];
                $this->updated_at = $row['updated_at'];
                $this->is_active = $row['is_active'];
                $this->can_apply_for_loan = $row['can_apply_for_loan']; // NEW: Set can_apply_for_loan property
                
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Get user by email error: " . $e->getMessage());
            return false;
        }
    }
    
    // Get user by PF number (SECONDARY METHOD - NOT FOR AUTHENTICATION)
    public function getByPfNumber($pfNumber) {
        try {
            $query = "SELECT * FROM " . $this->table_name . " WHERE pf_number = :pf_number LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":pf_number", $pfNumber);
            $stmt->execute();
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if($row) {
                // Set properties
                $this->user_id = $row['user_id'];
                $this->full_name = $row['full_name'];
                $this->email = $row['email'];
                $this->id_number = $row['id_number'];
                $this->pf_number = $row['pf_number'];
                $this->password = $row['password'];
                $this->role = $row['role'];
                $this->created_at = $row['created_at'];
                $this->updated_at = $row['updated_at'];
                $this->is_active = $row['is_active'];
                $this->can_apply_for_loan = $row['can_apply_for_loan']; // NEW: Set can_apply_for_loan property
                
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Get user by PF number error: " . $e->getMessage());
            return false;
        }
    }
    
    // Get user by ID number (SECONDARY METHOD - NOT FOR AUTHENTICATION)
    public function getByIdNumber($idNumber) {
        try {
            $query = "SELECT * FROM " . $this->table_name . " WHERE id_number = :id_number LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id_number", $idNumber);
            $stmt->execute();
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if($row) {
                // Set properties
                $this->user_id = $row['user_id'];
                $this->full_name = $row['full_name'];
                $this->email = $row['email'];
                $this->id_number = $row['id_number'];
                $this->pf_number = $row['pf_number'];
                $this->password = $row['password'];
                $this->role = $row['role'];
                $this->created_at = $row['created_at'];
                $this->updated_at = $row['updated_at'];
                $this->is_active = $row['is_active'];
                $this->can_apply_for_loan = $row['can_apply_for_loan']; // NEW: Set can_apply_for_loan property
                
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Get user by ID number error: " . $e->getMessage());
            return false;
        }
    }
    
    // Get all users by role
    public function getAll($role = null) {
        try {
            $query = "SELECT * FROM " . $this->table_name;
            
            if ($role) {
                $query .= " WHERE role = :role";
            }
            
            $query .= " ORDER BY created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if ($role) {
                $stmt->bindParam(":role", $role);
            }
            
            $stmt->execute();
            
            $users = array();
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $user_item = array(
                    "user_id" => $row['user_id'],
                    "name" => $row['full_name'],
                    "full_name" => $row['full_name'],
                    "email" => $row['email'],
                    "id_number" => $row['id_number'],
                    "pf_number" => $row['pf_number'],
                    "role" => $row['role'],
                    "created_at" => $row['created_at'],
                    "updated_at" => $row['updated_at'],
                    "is_active" => $row['is_active'],
                    "can_apply_for_loan" => (bool)$row['can_apply_for_loan'] // NEW: Include can_apply_for_loan in result
                );
                
                array_push($users, $user_item);
            }
            
            return $users;
        } catch (PDOException $e) {
            error_log("Get all users error: " . $e->getMessage());
            return array();
        }
    }
    
    // Update password
    public function updatePassword($newPassword) {
        try {
            // Hash new password
            $hashed_password = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update query
            $query = "UPDATE " . $this->table_name . "
                     SET password = :password,
                         updated_at = NOW()
                     WHERE user_id = :user_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":password", $hashed_password);
            $stmt->bindParam(":user_id", $this->user_id);
            
            // Execute query
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Password update error: " . $e->getMessage());
            return false;
        }
    }
    
    // NEW: Update can_apply_for_loan status
    public function updateLoanAccess($canApply) {
        try {
            // Update query
            $query = "UPDATE " . $this->table_name . "
                     SET can_apply_for_loan = :can_apply_for_loan,
                         updated_at = NOW()
                     WHERE user_id = :user_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":can_apply_for_loan", $canApply, PDO::PARAM_INT);
            $stmt->bindParam(":user_id", $this->user_id);
            
            // Execute query
            if ($stmt->execute()) {
                $this->can_apply_for_loan = $canApply;
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Update loan access error: " . $e->getMessage());
            return false;
        }
    }
    
    // Authenticate user (USES EMAIL AS PRIMARY IDENTIFIER)
    public function authenticate($email, $password) {
        try {
            $query = "SELECT * FROM " . $this->table_name . " WHERE email = :email AND is_active = 1 LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if($row && password_verify($password, $row['password'])) {
                // Set properties
                $this->user_id = $row['user_id'];
                $this->full_name = $row['full_name'];
                $this->email = $row['email'];
                $this->id_number = $row['id_number'];
                $this->pf_number = $row['pf_number'];
                $this->password = $row['password'];
                $this->role = $row['role'];
                $this->created_at = $row['created_at'];
                $this->updated_at = $row['updated_at'];
                $this->is_active = $row['is_active'];
                $this->can_apply_for_loan = $row['can_apply_for_loan']; // NEW: Set can_apply_for_loan property
                
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Authentication error: " . $e->getMessage());
            return false;
        }
    }
    
    // Check if email exists (PRIMARY CHECK)
    public function emailExists($email) {
        try {
            $query = "SELECT user_id FROM " . $this->table_name . " WHERE email = :email LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();
            
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Email exists check error: " . $e->getMessage());
            return false;
        }
    }
    
    // Check if PF number exists (SECONDARY CHECK)
    public function pfNumberExists($pfNumber) {
        try {
            $query = "SELECT user_id FROM " . $this->table_name . " WHERE pf_number = :pf_number LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":pf_number", $pfNumber);
            $stmt->execute();
            
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("PF number exists check error: " . $e->getMessage());
            return false;
        }
    }
    
    // Check if ID number exists (SECONDARY CHECK)
    public function idNumberExists($idNumber) {
        try {
            $query = "SELECT user_id FROM " . $this->table_name . " WHERE id_number = :id_number LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id_number", $idNumber);
            $stmt->execute();
            
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("ID number exists check error: " . $e->getMessage());
            return false;
        }
    }
}
?>