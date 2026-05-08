<?php
namespace App\Models;

use App\Core\Database;
use PDO;

class Barangay {
  /**
   * Auto / generic barangay handoff to dispatcher queue (system placeholder, not a real user id).
   * Must stay in sync with barangay UI and escalate payloads.
   */
  public const DISPATCHER_ESCALATION_PLACEHOLDER_ID = '1';

  /** True when dispatcher_id represents timed-out barangay queue / generic escalate, reclaimable by barangay accept. */
  public static function isDispatcherEscalationPlaceholder(?string $dispatcherId): bool {
    return trim((string)($dispatcherId ?? '')) === self::DISPATCHER_ESCALATION_PLACEHOLDER_ID;
  }

  // Profile
  public static function findByApiKey(string $apiKey): ?array {
    $db = Database::connect();
    
    $sql = "SELECT * FROM users WHERE api_key = :api_key AND role = 'barangay' LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':api_key', $apiKey);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
  }

  public static function updateProfile(int $userId, array $data): bool {
    $db = Database::connect();
    
    // Build SET clause
    $setClause = [];
    $params = [':id' => $userId];
    
    foreach ($data as $field => $value) {
        $setClause[] = "$field = :$field";
        $params[":$field"] = $value;
    }
    
    $setClauseStr = implode(', ', $setClause);
    
    $sql = "UPDATE users SET $setClauseStr WHERE id = :id";
    $stmt = $db->prepare($sql);
    
    return $stmt->execute($params);
  }

  // Barangay
  public static function getBaranggays(int $limit = 10, int $offset = 0, string $search = ''): array {
    $db = Database::connect();
    
    $sql = "SELECT baranggay_id, baranggay, latitude, longitude, created_at, updated_at 
            FROM baranggay 
            WHERE baranggay LIKE :search 
            ORDER BY baranggay ASC 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($sql);
    $searchTerm = '%' . $search . '%';
    
    $stmt->bindParam(':search', $searchTerm);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public static function getTotalBaranggays(string $search = ''): int {
    $db = Database::connect();
    
    $sql = "SELECT COUNT(*) as total FROM baranggay WHERE baranggay LIKE :search";
    $stmt = $db->prepare($sql);
    $searchTerm = '%' . $search . '%';
    $stmt->bindParam(':search', $searchTerm);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return intval($result['total']);
  }

  // Incidents
  public static function getIncidentsByBarangayId($barangayId, $limit = 20, $offset = 0, $status = '', $severity = '', $type = '', $search = '') {
    $db = Database::connect();
    
    $sql = "SELECT 
              i.*,
              u.first_name,
              u.middle_name,
              u.last_name,
              u.phone,
              u.email,
              u.user_id
            FROM incidents i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE i.baranggay_id = :barangay_id";
    
    $params = [':barangay_id' => $barangayId];
    
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
      
      $stmt->bindParam(':barangay_id', $params[':barangay_id']);
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
      error_log("Database error in getIncidentsByBarangayId: " . $e->getMessage());
      return [];
    }
  }

  public static function getTotalIncidentsByBarangayId($barangayId, $status = '', $severity = '', $type = '', $search = '') {
    $db = Database::connect();
    
    $sql = "SELECT COUNT(*) as total 
            FROM incidents i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE i.baranggay_id = :barangay_id";
    
    $params = [':barangay_id' => $barangayId];
    
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
      
      $stmt->bindParam(':barangay_id', $params[':barangay_id']);
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
      error_log("Database error in getTotalIncidentsByBarangayId: " . $e->getMessage());
      return 0;
    }
  }

  public static function getIncidentById($incidentId) {
    $db = Database::connect();
    
    $stmt = $db->prepare("
      SELECT i.*, u.email, u.first_name, u.middle_name, u.last_name, u.phone,b.baranggay
      FROM incidents i 
      LEFT JOIN baranggay b ON i.baranggay_id = b.baranggay_id 
      LEFT JOIN users u ON i.user_id = u.user_id
      WHERE i.incident_id = ?
    ");
    $stmt->execute([$incidentId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public static function updateIncidentStatus($incidentId, $updateData) {
    $db = Database::connect();
    
    $setClause = [];
    $params = [];
    
    foreach ($updateData as $field => $value) {
      $setClause[] = "$field = ?";
      $params[] = $value;
    }
    
    $params[] = $incidentId;
    
    $sql = "UPDATE incidents SET " . implode(', ', $setClause) . " WHERE incident_id = ?";
    $stmt = $db->prepare($sql);
    
    return $stmt->execute($params);
  }

  public static function validateDispatcher($dispatcherId, $baranggayId) {
    $db = Database::connect();
    
    $stmt = $db->prepare("
      SELECT user_id 
      FROM users 
      WHERE user_id = ? AND baranggay_id = ? AND role = 'dispatcher' AND status = 'active'
    ");
    $stmt->execute([$dispatcherId, $baranggayId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public static function validateAgency($agencyId, $baranggayId) {
    $db = Database::connect();
    
    $stmt = $db->prepare("
      SELECT agency_id 
      FROM agencies 
      WHERE agency_id = ? AND baranggay_id = ? AND status = 'active'
    ");
    $stmt->execute([$agencyId, $baranggayId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }
  
  public static function autoEscalateIncidentsToDispatcher($barangayId, $thresholdMinutes = 10) {
    try {
        $thresholdTime = date('Y-m-d H:i:s', strtotime("-{$thresholdMinutes} minutes"));
        
        $db = Database::connect();
        
        // Only escalate cases barangay has not taken: pending (normalize casing/whitespace),
        // no assignee, no dispatcher yet, and old enough by created_at.
        $sql = "UPDATE incidents 
                SET dispatcher_id = :placeholder_dispatcher_id,
                    status = 'ongoing', 
                    updated_at = :updated_at
                WHERE baranggay_id = :barangay_id 
                AND (
                  status IS NULL
                  OR TRIM(status) = ''
                  OR LOWER(TRIM(status)) = 'pending'
                )
                AND (assign_id IS NULL OR assign_id = '' OR assign_id = '0')
                AND (dispatcher_id IS NULL OR dispatcher_id = '' OR dispatcher_id = '0')
                AND created_at <= :threshold_time
                AND created_at IS NOT NULL";
        
        $stmt = $db->prepare($sql);
        $updatedAt = date('Y-m-d H:i:s');
        
        $stmt->bindValue(':placeholder_dispatcher_id', self::DISPATCHER_ESCALATION_PLACEHOLDER_ID);
        $stmt->bindParam(':barangay_id', $barangayId);
        $stmt->bindParam(':threshold_time', $thresholdTime);
        $stmt->bindParam(':updated_at', $updatedAt);
        $stmt->execute();
        
        $updatedCount = $stmt->rowCount();
        
        return [
            'success' => true,
            'escalated_count' => $updatedCount,
            'message' => "Auto-escalated {$updatedCount} incidents to dispatcher"
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in autoEscalateIncidentsToDispatcher: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'escalated_count' => 0
        ];
    }
  }

  // Dashboard Statistics
  public static function getDashboardStats($barangayId, $startDate = '', $endDate = '') {
    $db = Database::connect();
    
    $sql = "SELECT 
              COUNT(*) as total_incidents,
              SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cases,
              SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing_cases,
              SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_cases,
              AVG(CASE WHEN severity_level = 'high' THEN 1 
                       WHEN severity_level = 'medium' THEN 0.5 
                       WHEN severity_level = 'low' THEN 0.25 
                       ELSE 0 END) as avg_severity_score
            FROM incidents 
            WHERE baranggay_id = :barangay_id";
    
    $params = [':barangay_id' => $barangayId];
    
    // Add date range filter if provided
    if (!empty($startDate) && !empty($endDate)) {
      $sql .= " AND DATE(created_at) BETWEEN :start_date AND :end_date";
      $params[':start_date'] = $startDate;
      $params[':end_date'] = $endDate;
    } elseif (!empty($startDate)) {
      $sql .= " AND DATE(created_at) >= :start_date";
      $params[':start_date'] = $startDate;
    } elseif (!empty($endDate)) {
      $sql .= " AND DATE(created_at) <= :end_date";
      $params[':end_date'] = $endDate;
    }
    
    try {
      $stmt = $db->prepare($sql);
      $stmt->execute($params);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      
      return [
        'total_incidents' => intval($result['total_incidents'] ?? 0),
        'pending_cases' => intval($result['pending_cases'] ?? 0),
        'ongoing_cases' => intval($result['ongoing_cases'] ?? 0),
        'resolved_cases' => intval($result['resolved_cases'] ?? 0),
        'avg_severity_score' => round(floatval($result['avg_severity_score'] ?? 0), 2)
      ];
    } catch (PDOException $e) {
      error_log("Database error in getDashboardStats: " . $e->getMessage());
      return [
        'total_incidents' => 0,
        'pending_cases' => 0,
        'ongoing_cases' => 0,
        'resolved_cases' => 0,
        'avg_severity_score' => 0
      ];
    }
  }

  public static function getIncidentsByType($barangayId, $startDate = '', $endDate = '') {
    $db = Database::connect();
    
    $sql = "SELECT 
              incident_type,
              COUNT(*) as count,
              SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing,
              SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
            FROM incidents 
            WHERE baranggay_id = :barangay_id";
    
    $params = [':barangay_id' => $barangayId];
    
    // Add date range filter if provided
    if (!empty($startDate) && !empty($endDate)) {
      $sql .= " AND DATE(created_at) BETWEEN :start_date AND :end_date";
      $params[':start_date'] = $startDate;
      $params[':end_date'] = $endDate;
    } elseif (!empty($startDate)) {
      $sql .= " AND DATE(created_at) >= :start_date";
      $params[':start_date'] = $startDate;
    } elseif (!empty($endDate)) {
      $sql .= " AND DATE(created_at) <= :end_date";
      $params[':end_date'] = $endDate;
    }
    
    $sql .= " GROUP BY incident_type ORDER BY count DESC";
    
    try {
      $stmt = $db->prepare($sql);
      $stmt->execute($params);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Database error in getIncidentsByType: " . $e->getMessage());
      return [];
    }
  }

  public static function getRecentIncidents($barangayId, $limit = 5, $startDate = '', $endDate = '') {
    $db = Database::connect();
    
    $sql = "SELECT 
              i.*,
              u.first_name,
              u.last_name,
              u.email
            FROM incidents i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE i.baranggay_id = :barangay_id";
    
    $params = [':barangay_id' => $barangayId];
    
    // Add date range filter if provided
    if (!empty($startDate) && !empty($endDate)) {
      $sql .= " AND DATE(i.created_at) BETWEEN :start_date AND :end_date";
      $params[':start_date'] = $startDate;
      $params[':end_date'] = $endDate;
    } elseif (!empty($startDate)) {
      $sql .= " AND DATE(i.created_at) >= :start_date";
      $params[':start_date'] = $startDate;
    } elseif (!empty($endDate)) {
      $sql .= " AND DATE(i.created_at) <= :end_date";
      $params[':end_date'] = $endDate;
    }
    
    $sql .= " ORDER BY i.created_at DESC LIMIT :limit";
    $params[':limit'] = $limit;
    
    try {
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':barangay_id', $params[':barangay_id']);
      $stmt->bindParam(':limit', $params[':limit'], PDO::PARAM_INT);
      
      if (!empty($startDate) && !empty($endDate)) {
        $stmt->bindParam(':start_date', $params[':start_date']);
        $stmt->bindParam(':end_date', $params[':end_date']);
      } elseif (!empty($startDate)) {
        $stmt->bindParam(':start_date', $params[':start_date']);
      } elseif (!empty($endDate)) {
        $stmt->bindParam(':end_date', $params[':end_date']);
      }
      
      $stmt->execute();
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Database error in getRecentIncidents: " . $e->getMessage());
      return [];
    }
  }

  // Barangay Officials
  public static function createBarangayOfficial(array $data): int {
    $db = Database::connect();
    
    $sql = "INSERT INTO barangay_officials 
            (baranggay_id, first_name, middle_name, last_name, suffix, position, 
            contact_number, email, responsibilities, term_start, image_path,
            created_by, created_at, updated_at) 
            VALUES 
            (:baranggay_id, :first_name, :middle_name, :last_name, :suffix, :position,
            :contact_number, :email, :responsibilities, :term_start, :image_path,
            :created_by, :created_at, :updated_at)";
    
    $stmt = $db->prepare($sql);
    
    $params = [
      ':baranggay_id' => $data['baranggay_id'],
      ':first_name' => $data['first_name'],
      ':middle_name' => $data['middle_name'],
      ':last_name' => $data['last_name'],
      ':suffix' => $data['suffix'],
      ':position' => $data['position'],
      ':contact_number' => $data['contact_number'],
      ':email' => $data['email'],
      ':responsibilities' => $data['responsibilities'],
      ':term_start' => $data['term_start'],
      ':image_path' => $data['image_path'],
      ':created_by' => $data['created_by'],
      ':created_at' => $data['created_at'],
      ':updated_at' => $data['updated_at']
    ];
    
    try {
      $stmt->execute($params);
      return $db->lastInsertId();
    } catch (PDOException $e) {
      error_log("Database error in createBarangayOfficial: " . $e->getMessage());
      return 0;
    }
  }

  public static function getBarangayOfficialById(int $officialId): ?array {
    $db = Database::connect();
    
    $sql = "SELECT 
              id,
              baranggay_id,
              first_name,
              COALESCE(middle_name, '') as middle_name,
              last_name,
              COALESCE(suffix, '') as suffix,
              position,
              COALESCE(contact_number, '') as contact_number,
              COALESCE(email, '') as email,
              COALESCE(responsibilities, '') as responsibilities,
              term_start,
              COALESCE(image_path, '') as image_path,
              created_by,
              created_at,
              updated_at
            FROM barangay_officials 
            WHERE id = :id";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':id', $officialId);
    $stmt->execute();
    
    $official = $stmt->fetch(PDO::FETCH_ASSOC);
    return $official ?: null;
  }
  
  public static function getBarangayOfficials($barangayId, $limit = 50, $offset = 0) {
    $db = Database::connect();
    
    $sql = "SELECT 
              id,
              baranggay_id,
              first_name,
              COALESCE(middle_name, '') as middle_name,
              last_name,
              COALESCE(suffix, '') as suffix,
              position,
              COALESCE(contact_number, '') as contact_number,
              COALESCE(email, '') as email,
              COALESCE(responsibilities, '') as responsibilities,
              COALESCE(status, 'active') as status,
              term_start,
              COALESCE(image_path, '') as image_path,
              created_by,
              created_at,
              updated_at
            FROM barangay_officials 
            WHERE baranggay_id = :barangay_id
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset";
    
    try {
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':barangay_id', $barangayId);
      $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
      $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
      $stmt->execute();
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Database error in getBarangayOfficials: " . $e->getMessage());
      return [];
    }
  }

  public static function getTotalBarangayOfficials($barangayId) {
    $db = Database::connect();
    
    $sql = "SELECT COUNT(*) as total 
            FROM barangay_officials 
            WHERE baranggay_id = :barangay_id";
    
    try {
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':barangay_id', $barangayId);
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return intval($result['total'] ?? 0);
    } catch (PDOException $e) {
      error_log("Database error in getTotalBarangayOfficials: " . $e->getMessage());
      return 0;
    }
  }

  public static function updateBarangayOfficial(int $officialId, array $data): bool {
    $db = Database::connect();
    
    $sql = "UPDATE barangay_officials SET 
              first_name = :first_name,
              middle_name = :middle_name,
              last_name = :last_name,
              suffix = :suffix,
              position = :position,
              contact_number = :contact_number,
              email = :email,
              responsibilities = :responsibilities,
              term_start = :term_start,
              image_path = :image_path,
              updated_at = :updated_at
            WHERE id = :id";
    
    $stmt = $db->prepare($sql);
    
    $params = [
      ':first_name' => $data['first_name'],
      ':middle_name' => $data['middle_name'],
      ':last_name' => $data['last_name'],
      ':suffix' => $data['suffix'],
      ':position' => $data['position'],
      ':contact_number' => $data['contact_number'],
      ':email' => $data['email'],
      ':responsibilities' => $data['responsibilities'],
      ':term_start' => $data['term_start'],
      ':image_path' => $data['image_path'],
      ':updated_at' => $data['updated_at'],
      ':id' => $officialId
    ];
    
    try {
      return $stmt->execute($params);
    } catch (PDOException $e) {
      error_log("Database error in updateBarangayOfficial: " . $e->getMessage());
      return false;
    }
  }

  public static function deleteBarangayOfficial(int $officialId): bool {
    $db = Database::connect();
    
    $sql = "DELETE FROM barangay_officials WHERE id = :id";
    $stmt = $db->prepare($sql);
    
    try {
      return $stmt->execute([':id' => $officialId]);
    } catch (PDOException $e) {
      error_log("Database error in deleteBarangayOfficial: " . $e->getMessage());
      return false;
    }
  }
  
  // Barangay Users
  public static function getBarangayUsers(int $limit = 20, int $offset = 0, string $status = 'pending', string $search = ''): array {
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
          WHERE role = 'barangay' 
          AND status = :status";
  
  $params = [':status' => $status];
  
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
    if (!empty($search)) {
      $stmt->bindParam(':search', $params[':search']);
    }
    $stmt->bindParam(':limit', $params[':limit'], PDO::PARAM_INT);
    $stmt->bindParam(':offset', $params[':offset'], PDO::PARAM_INT);
    
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    error_log("Database error in getBarangayUsers: " . $e->getMessage());
    return [];
  }
}

  public static function getTotalBarangayUsers(string $status = 'pending', string $search = ''): int {
  $db = Database::connect();
  
  $sql = "SELECT COUNT(*) as total 
          FROM users 
          WHERE role = 'barangay' 
          AND status = :status";
  
  $params = [':status' => $status];
  
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
    if (!empty($search)) {
      $stmt->bindParam(':search', $params[':search']);
    }
    
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return intval($result['total'] ?? 0);
  } catch (PDOException $e) {
    error_log("Database error in getTotalBarangayUsers: " . $e->getMessage());
    return 0;
  }
}

  public static function getUserById(string $userId): ?array {
  $db = Database::connect();
  
  $sql = "SELECT * FROM users WHERE user_id = :user_id LIMIT 1";
  $stmt = $db->prepare($sql);
  $stmt->bindParam(':user_id', $userId);
  $stmt->execute();
  
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  return $user ?: null;
}

  public static function updateUserStatus(string $userId, string $status): bool {
  $db = Database::connect();
  
  $sql = "UPDATE users SET 
            status = :status,
            updated_at = :updated_at
          WHERE user_id = :user_id";
  
  $stmt = $db->prepare($sql);
  
  $params = [
    ':status' => $status,
    ':updated_at' => date('Y-m-d H:i:s'),
    ':user_id' => $userId
  ];
  
  try {
    return $stmt->execute($params);
  } catch (PDOException $e) {
    error_log("Database error in updateUserStatus: " . $e->getMessage());
    return false;
  }
}

  public static function getAgencies($limit = 10, $offset = 0, $status = '', $search = '') {
    $db = Database::connect();
    
    $sql = "SELECT * FROM agency WHERE 1=1";
    $params = [];
    
    if (!empty($status)) {
      $sql .= " AND status = :status";
      $params[':status'] = $status;
    }
    
    if (!empty($search)) {
      $sql .= " AND (agency LIKE :search 
                    OR agency_type LIKE :search_type 
                    OR contact_person LIKE :search_contact 
                    OR email_address LIKE :search_email
                    OR address LIKE :search_address)";
      $searchTerm = "%$search%";
      $params[':search'] = $searchTerm;
      $params[':search_type'] = $searchTerm;
      $params[':search_contact'] = $searchTerm;
      $params[':search_email'] = $searchTerm;
      $params[':search_address'] = $searchTerm;
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    try {
      $stmt = $db->prepare($sql);
      
      if (!empty($status)) $stmt->bindParam(':status', $params[':status']);
      if (!empty($search)) {
        $stmt->bindParam(':search', $params[':search']);
        $stmt->bindParam(':search_type', $params[':search_type']);
        $stmt->bindParam(':search_contact', $params[':search_contact']);
        $stmt->bindParam(':search_email', $params[':search_email']);
        $stmt->bindParam(':search_address', $params[':search_address']);
      }
      $stmt->bindParam(':limit', $params[':limit'], PDO::PARAM_INT);
      $stmt->bindParam(':offset', $params[':offset'], PDO::PARAM_INT);
      
      $stmt->execute();
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Database error in getAgencies: " . $e->getMessage());
      return [];
    }
  }

  public static function getTotalAgencies($status = '', $search = '') {
    $db = Database::connect();
    
    $sql = "SELECT COUNT(*) as total FROM agency WHERE 1=1";
    $params = [];
    
    if (!empty($status)) {
      $sql .= " AND status = :status";
      $params[':status'] = $status;
    }
    
    if (!empty($search)) {
      $sql .= " AND (agency LIKE :search 
                    OR agency_type LIKE :search_type 
                    OR contact_person LIKE :search_contact 
                    OR email_address LIKE :search_email
                    OR address LIKE :search_address)";
      $searchTerm = "%$search%";
      $params[':search'] = $searchTerm;
      $params[':search_type'] = $searchTerm;
      $params[':search_contact'] = $searchTerm;
      $params[':search_email'] = $searchTerm;
      $params[':search_address'] = $searchTerm;
    }
    
    try {
      $stmt = $db->prepare($sql);
      
      if (!empty($status)) $stmt->bindParam(':status', $params[':status']);
      if (!empty($search)) {
        $stmt->bindParam(':search', $params[':search']);
        $stmt->bindParam(':search_type', $params[':search_type']);
        $stmt->bindParam(':search_contact', $params[':search_contact']);
        $stmt->bindParam(':search_email', $params[':search_email']);
        $stmt->bindParam(':search_address', $params[':search_address']);
      }
      
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      return intval($result['total'] ?? 0);
    } catch (PDOException $e) {
      error_log("Database error in getTotalAgencies: " . $e->getMessage());
      return 0;
    }
  }
}