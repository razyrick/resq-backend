<?php
namespace App\Models;

use App\Core\Database;
use PDO;
use PDOException;

class Admin {
    public static function findByApiKey(string $apiKey): ?array {
        $db = Database::connect();
        
        $sql = "SELECT * FROM users WHERE api_key = :api_key AND role = 'admin' LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':api_key', $apiKey);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }
    
    public static function findById(int $userId): ?array {
        $db = Database::connect();
        
        $sql = "SELECT * FROM users WHERE user_id = :user_id LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_STRING);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }
    
    public static function getPendingUsers(int $limit = 20, int $offset = 0, string $search = '', string $role = '', string $status = 'pending'): array {
        $db = Database::connect();
        
        $sql = "SELECT 
                    id,
                    user_id,
                    first_name,
                    middle_name,
                    last_name,
                    email,
                    phone,
                    baranggay_id,
                    role,
                    status,
                    profile,
                    google_id,
                    api_key,
                    csrf_token,
                    created_at,
                    updated_at
                FROM users
                WHERE status = :status
                AND role != 'user'";
        
        $params = [':status' => $status];
        
        if (!empty($role)) {
            $sql .= " AND role = :role";
            $params[':role'] = $role;
        }
        
        if (!empty($search)) {
            $sql .= " AND (first_name LIKE :search 
                          OR last_name LIKE :search 
                          OR email LIKE :search 
                          OR user_id LIKE :search)";
            $searchTerm = "%$search%";
            $params[':search'] = $searchTerm;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        
        try {
            $stmt = $db->prepare($sql);
            
            $stmt->bindParam(':status', $params[':status']);
            
            if (!empty($role)) {
                $stmt->bindParam(':role', $params[':role']);
            }
            
            if (!empty($search)) {
                $stmt->bindParam(':search', $params[':search']);
            }
            
            $stmt->bindParam(':limit', $params[':limit'], PDO::PARAM_INT);
            $stmt->bindParam(':offset', $params[':offset'], PDO::PARAM_INT);
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getPendingUsers: " . $e->getMessage());
            return [];
        }
    }
    
    public static function getTotalPendingUsers(string $search = '', string $role = '', string $status = 'pending'): int {
        $db = Database::connect();
        
        $sql = "SELECT COUNT(*) as total 
                FROM users 
                WHERE status = :status
                AND role != 'user'";
        
        $params = [':status' => $status];
        
        if (!empty($role)) {
            $sql .= " AND role = :role";
            $params[':role'] = $role;
        }
        
        if (!empty($search)) {
            $sql .= " AND (first_name LIKE :search 
                          OR last_name LIKE :search 
                          OR email LIKE :search 
                          OR user_id LIKE :search)";
            $searchTerm = "%$search%";
            $params[':search'] = $searchTerm;
        }
        
        try {
            $stmt = $db->prepare($sql);
            
            $stmt->bindParam(':status', $params[':status']);
            
            if (!empty($role)) {
                $stmt->bindParam(':role', $params[':role']);
            }
            
            if (!empty($search)) {
                $stmt->bindParam(':search', $params[':search']);
            }
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return intval($result['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("Database error in getTotalPendingUsers: " . $e->getMessage());
            return 0;
        }
    }
    
    public static function updateUserStatus(string $userId, string $status): bool {
        $db = Database::connect();
        
        try {
            $sql = "UPDATE users 
                    SET status = :status, 
                        updated_at = NOW()
                    WHERE user_id = :user_id";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':user_id', $userId);
            
            return $stmt->execute();
            
        } catch (PDOException $e) {
            error_log("Database error in updateUserStatus: " . $e->getMessage());
            return false;
        }
    }
    
    // Incidents
    public static function getIncidents($limit = 20, $offset = 0, $status = '', $severity = '', $type = '', $search = '') {
      $db = Database::connect();
      
      $sql = "SELECT 
                i.*,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.phone,
                u.email,
                u.user_id,
                b.baranggay
              FROM incidents i
              LEFT JOIN users u ON i.user_id = u.user_id
              LEFT JOIN baranggay b ON i.baranggay_id = b.baranggay_id
              WHERE 1=1";
      
      $params = [];
      
      if (!empty($status)) {
        $sql .= " AND i.status = :status";
        $params[':status'] = $status;
      }
      
      if (!empty($severity)) {
        $sql .= " AND i.severity_level = :severity";
        $params[':severity'] = $severity;
      }
      
      if (!empty($type)) {
        $sql .= " AND i.incident_type LIKE :type";
        $params[':type'] = "%$type%";
      }
      
      if (!empty($search)) {
        $sql .= " AND (i.incident_id LIKE :search 
                      OR i.description LIKE :search_desc 
                      OR i.incident_type LIKE :search_type
                      OR u.first_name LIKE :search_user
                      OR u.last_name LIKE :search_user
                      OR u.email LIKE :search_user)";
        $searchTerm = "%$search%";
        $params[':search'] = $searchTerm;
        $params[':search_desc'] = $searchTerm;
        $params[':search_type'] = $searchTerm;
        $params[':search_user'] = $searchTerm;
      }
      
      $sql .= " ORDER BY i.created_at DESC LIMIT :limit OFFSET :offset";
      $params[':limit'] = $limit;
      $params[':offset'] = $offset;
      
      try {
        $stmt = $db->prepare($sql);
        
        if (!empty($status)) $stmt->bindParam(':status', $params[':status']);
        if (!empty($severity)) $stmt->bindParam(':severity', $params[':severity']);
        if (!empty($type)) $stmt->bindParam(':type', $params[':type']);
        if (!empty($search)) {
          $stmt->bindParam(':search', $params[':search']);
          $stmt->bindParam(':search_desc', $params[':search_desc']);
          $stmt->bindParam(':search_type', $params[':search_type']);
          $stmt->bindParam(':search_user', $params[':search_user']);
        }
        $stmt->bindParam(':limit', $params[':limit'], PDO::PARAM_INT);
        $stmt->bindParam(':offset', $params[':offset'], PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
      } catch (PDOException $e) {
        error_log("Database error in getIncidents: " . $e->getMessage());
        return [];
      }
    }

    public static function getTotalIncidents($status = '', $severity = '', $type = '', $search = '') {
      $db = Database::connect();
      
      $sql = "SELECT COUNT(*) as total 
              FROM incidents i
              LEFT JOIN users u ON i.user_id = u.user_id
              WHERE 1=1";
      
      $params = [];
      
      if (!empty($status)) {
        $sql .= " AND i.status = :status";
        $params[':status'] = $status;
      }
      
      if (!empty($severity)) {
        $sql .= " AND i.severity_level = :severity";
        $params[':severity'] = $severity;
      }
      
      if (!empty($type)) {
        $sql .= " AND i.incident_type LIKE :type";
        $params[':type'] = "%$type%";
      }
      
      if (!empty($search)) {
        $sql .= " AND (i.incident_id LIKE :search 
                      OR i.description LIKE :search_desc 
                      OR i.incident_type LIKE :search_type
                      OR u.first_name LIKE :search_user
                      OR u.last_name LIKE :search_user
                      OR u.email LIKE :search_user)";
        $searchTerm = "%$search%";
        $params[':search'] = $searchTerm;
        $params[':search_desc'] = $searchTerm;
        $params[':search_type'] = $searchTerm;
        $params[':search_user'] = $searchTerm;
      }
      
      try {
        $stmt = $db->prepare($sql);
        
        if (!empty($status)) $stmt->bindParam(':status', $params[':status']);
        if (!empty($severity)) $stmt->bindParam(':severity', $params[':severity']);
        if (!empty($type)) $stmt->bindParam(':type', $params[':type']);
        if (!empty($search)) {
          $stmt->bindParam(':search', $params[':search']);
          $stmt->bindParam(':search_desc', $params[':search_desc']);
          $stmt->bindParam(':search_type', $params[':search_type']);
          $stmt->bindParam(':search_user', $params[':search_user']);
        }
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return intval($result['total'] ?? 0);
      } catch (PDOException $e) {
        error_log("Database error in getTotalIncidents: " . $e->getMessage());
        return 0;
      }
    }
}