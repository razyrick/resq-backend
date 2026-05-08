<?php
namespace App\Models;

use App\Core\Database;
use PDO;

class Auth {
  public static function findByEmail($email) {
    $db = Database::connect();
    
    $sql = "SELECT * FROM users WHERE email = :email LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public static function createUser($user_data) {
    $db = Database::connect();
    
    $columns = implode(', ', array_keys($user_data));
    $placeholders = ':' . implode(', :', array_keys($user_data));
    
    $sql = "INSERT INTO users ($columns) VALUES ($placeholders)";
    $stmt = $db->prepare($sql);
    
    foreach ($user_data as $key => $value) {
      $stmt->bindValue(":$key", $value);
    }
    
    return $stmt->execute();
  }

  public static function updateCsrfToken($user_id, $csrf_token) {
    $db = Database::connect();
    
    $sql = "UPDATE users SET csrf_token = :csrf_token, updated_at = NOW() WHERE user_id = :user_id";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':csrf_token', $csrf_token);
    $stmt->bindParam(':user_id', $user_id);
    
    return $stmt->execute();
  }

  public static function updateLastLogin($user_id) {
    $db = Database::connect();
    
    $sql = "UPDATE users SET updated_at = NOW() WHERE user_id = :user_id";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':user_id', $user_id);
    
    return $stmt->execute();
  }

  public static function findById($user_id) {
    $db = Database::connect();
    
    $sql = "SELECT * FROM users WHERE user_id = :user_id LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public static function updatePassword($user_id, $hashed_password) {
    $db = Database::connect();
    
    $sql = "UPDATE users SET password = :password, updated_at = NOW() WHERE user_id = :user_id";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':password', $hashed_password);
    $stmt->bindParam(':user_id', $user_id);
    
    return $stmt->execute();
  }

  public static function updateUserStatus($user_id, $status) {
    $db = Database::connect();
    
    $sql = "UPDATE users SET status = :status, updated_at = NOW() WHERE user_id = :user_id";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':user_id', $user_id);
    
    return $stmt->execute();
  }

  public static function getAllUsers($role = null) {
    $db = Database::connect();
    
    $sql = "SELECT user_id, api_key, role, status, first_name, middle_name, last_name, email, barangay_name, agency_name, dispatcher_id, created_at, updated_at, last_login FROM users";
    
    if ($role) {
      $sql .= " WHERE role = :role";
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $db->prepare($sql);
    
    if ($role) {
      $stmt->bindParam(':role', $role);
    }
    
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public static function deleteUser($user_id) {
    $db = Database::connect();
    
    $sql = "DELETE FROM users WHERE user_id = :user_id";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':user_id', $user_id);
    
    return $stmt->execute();
  }

  public static function findByApiKey($api_key) {
    $db = Database::connect();
    
    $sql = "SELECT * FROM users WHERE api_key = :api_key LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':api_key', $api_key);
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public static function verifyCsrfToken($user_id, $csrf_token) {
    $db = Database::connect();
    
    $sql = "SELECT user_id FROM users WHERE user_id = :user_id AND csrf_token = :csrf_token LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':csrf_token', $csrf_token);
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
  }
  
  public static function generateVerificationCode(string $userId): string {
    $db = Database::connect();
    
    $verificationCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    $sql = "UPDATE users 
            SET verification_code = :code,
                updated_at = NOW()
            WHERE user_id = :user_id";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':code', $verificationCode);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();
    
    return $verificationCode;
  }
  
  public static function clearVerificationCode($userId) {
    try {
        $db = Database::connect();
        
        $sql = "UPDATE users 
                SET verification_code = NULL, 
                    updated_at = NOW() 
                WHERE user_id = :user_id";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_STR);
        
        return $stmt->execute();
        
    } catch (PDOException $e) {
        error_log("Clear verification code error: " . $e->getMessage());
        return false;
    }
  }
  
  public static function storePasswordResetCode($userId, $resetCode, $expiryTime) {
    $db = Database::connect();
    
    // First, clear any existing reset codes for this user
    $sql = "DELETE FROM password_resets WHERE user_id = :user_id";
    $stmt = $db->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    
    // Insert new reset code
    $sql = "INSERT INTO password_resets (user_id, reset_code, expires_at, created_at) 
            VALUES (:user_id, :reset_code, :expires_at, NOW())";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        'user_id' => $userId,
        'reset_code' => $resetCode,
        'expires_at' => $expiryTime
    ]);
}

  public static function verifyPasswordResetCode($userId, $resetCode) {
    $db = Database::connect();
    $sql = "SELECT * FROM password_resets 
            WHERE user_id = :user_id 
            AND reset_code = :reset_code 
            AND used = 0
            ORDER BY created_at DESC 
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'user_id' => $userId,
        'reset_code' => $resetCode
    ]);
    
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $result ?: null;
}

//   public static function updatePassword($userId, $hashedPassword) {
//     $db = Database::connect();
//     $sql = "UPDATE users SET password = :password, updated_at = NOW() WHERE user_id = :user_id";
//     $stmt = $db->prepare($sql);
//     return $stmt->execute([
//         'user_id' => $userId,
//         'password' => $hashedPassword
//     ]);
// }

  public static function clearPasswordResetCode($userId) {
    $db = Database::connect();
    $sql = "DELETE FROM password_resets WHERE user_id = :user_id";
    $stmt = $db->prepare($sql);
    return $stmt->execute(['user_id' => $userId]);
}
}