<?php
namespace App\Models;

use App\Core\Database;
use PDO;
use PDOException;

class Agency {
  // Profile
  public static function findByApiKey(string $apiKey): ?array {
      $db = Database::connect();
      
      $sql = "SELECT u.*, a.agency_id AS linked_agency_id, a.agency, a.agency_type, a.contact_person, a.phone_number as agency_phone, 
                    a.email_address as agency_email, a.address as agency_address, 
                    a.number_of_units, a.status as agency_status, a.created_at as agency_created_at,
                    a.updated_at as agency_updated_at
              FROM users u 
              LEFT JOIN agency a ON TRIM(COALESCE(u.agency_id, '')) = TRIM(COALESCE(a.agency_id, ''))
              WHERE u.api_key = :api_key AND u.role = 'agency' LIMIT 1";
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':api_key', $apiKey);
      $stmt->execute();
      
      $user = $stmt->fetch(PDO::FETCH_ASSOC);
      return $user ?: null;
  }

  public static function findByEmail(string $email): ?array {
      $db = Database::connect();
      
      $sql = "SELECT u.*, a.agency_id AS linked_agency_id, a.agency, a.agency_type, a.contact_person, a.phone_number as agency_phone, 
                    a.email_address as agency_email, a.address as agency_address, 
                    a.number_of_units, a.status as agency_status, a.created_at as agency_created_at,
                    a.updated_at as agency_updated_at
              FROM users u 
              LEFT JOIN agency a ON TRIM(COALESCE(u.agency_id, '')) = TRIM(COALESCE(a.agency_id, ''))
              WHERE u.email = :email AND u.role = 'agency' LIMIT 1";
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':email', $email);
      $stmt->execute();
      
      $user = $stmt->fetch(PDO::FETCH_ASSOC);
      return $user ?: null;
  }

  public static function findAgencyIdByContactEmail(string $email): ?string {
    $email = trim($email);
    if ($email === '') {
      return null;
    }

    $db = Database::connect();

    try {
      $stmt = $db->prepare(
        'SELECT agency_id FROM agency WHERE LOWER(TRIM(email_address)) = LOWER(?) LIMIT 1'
      );
      $stmt->execute([$email]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row || !isset($row['agency_id'])) {
        return null;
      }
      $id = trim((string) $row['agency_id']);
      return $id !== '' ? $id : null;
    } catch (PDOException $e) {
      error_log('Agency::findAgencyIdByContactEmail error: ' . $e->getMessage());
      return null;
    }
  }

  public static function updateUserProfile(string $userId, array $updateData): bool {
    $db = Database::connect();
    
    $sql = "UPDATE users SET 
              agency_id = :agency_id,
              first_name = :first_name,
              last_name = :last_name,
              middle_name = :middle_name,
              email = :email,
              phone = :phone,
              profile = :profile,
              updated_at = NOW()
            WHERE user_id = :user_id";
    
    try {
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':agency_id', $updateData['agency_id']);
      $stmt->bindParam(':first_name', $updateData['first_name']);
      $stmt->bindParam(':last_name', $updateData['last_name']);
      $stmt->bindParam(':middle_name', $updateData['middle_name']);
      $stmt->bindParam(':email', $updateData['email']);
      $stmt->bindParam(':phone', $updateData['phone']);
      $stmt->bindParam(':profile', $updateData['profile']);
      $stmt->bindParam(':user_id', $userId);
      
      return $stmt->execute();
    } catch (PDOException $e) {
      error_log("Database error in updateUserProfile: " . $e->getMessage());
      return false;
    }
  }

  // Agency
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

  // Incidents
  public static function getIncidents($agencyId, $limit = 20, $offset = 0, $status = '', $severity = '', $type = '', $search = '') {
    $db = Database::connect();
    
    $sql = "SELECT 
              i.*,
              u.first_name,
              u.middle_name,
              u.last_name,
              u.phone,
              u.email,
              u.user_id,
              b.baranggay,
              p.patient_id AS linked_patient_id,
              p.full_name AS linked_patient_name,
              p.reason AS linked_patient_reason,
              p.status AS linked_patient_status,
              p.created_at AS linked_patient_created_at
            FROM incidents i
            LEFT JOIN users u ON i.user_id = u.user_id
            LEFT JOIN baranggay b ON i.baranggay_id = b.baranggay_id
            LEFT JOIN patients p ON i.patient_id IS NOT NULL AND i.patient_id = p.patient_id
            WHERE i.agency_id = :agency_id";
    
    $params = [':agency_id' => $agencyId];
    
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
        
        // Bind all parameters
        $stmt->bindParam(':agency_id', $params[':agency_id']);
        
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

  public static function getTotalIncidents($agencyId, $status = '', $severity = '', $type = '', $search = '') {
    $db = Database::connect();
    
    $sql = "SELECT COUNT(*) as total 
            FROM incidents i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE i.agency_id = :agency_id";
    
    $params = [':agency_id' => $agencyId];
    
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
        
        // Bind all parameters
        $stmt->bindParam(':agency_id', $params[':agency_id']);
        
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

  public static function updateIncidentStatus($incidentId, $status, $agencyId, array $resolution = []) {
    $db = Database::connect();
    
    $setParts = ['status = :status', 'updated_at = NOW()'];
    $params = [
      ':incident_id' => $incidentId,
      ':agency_id' => $agencyId,
      ':status' => $status,
    ];

    if ($status === 'resolved') {
      if (!empty($resolution['resolution_photo'])) {
        $setParts[] = 'resolution_photo = :resolution_photo';
        $params[':resolution_photo'] = $resolution['resolution_photo'];
      }
      if (array_key_exists('resolution_notes', $resolution)) {
        $setParts[] = 'resolution_notes = :resolution_notes';
        $params[':resolution_notes'] = $resolution['resolution_notes'];
      }
      if (!empty($resolution['resolved_by'])) {
        $setParts[] = 'resolved_by = :resolved_by';
        $params[':resolved_by'] = $resolution['resolved_by'];
      }
      $setParts[] = 'resolved_by_role = :resolved_by_role';
      $params[':resolved_by_role'] = 'agency';
      $setParts[] = 'resolved_at = NOW()';
    }

    $sql = 'UPDATE incidents SET ' . implode(', ', $setParts) . '
            WHERE incident_id = :incident_id
            AND agency_id = :agency_id';
    
    try {
      $stmt = $db->prepare($sql);
      foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
      }
      
      return $stmt->execute();
    } catch (PDOException $e) {
      error_log("Database error in updateIncidentStatus: " . $e->getMessage());
      return false;
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
  
  public static function getAgencyById($agencyId) {
    $db = Database::connect();
    
    $stmt = $db->prepare("
        SELECT agency_id, agency 
        FROM agency 
        WHERE agency_id = ?
    ");
    $stmt->execute([$agencyId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  // Dashboard
  public static function getDashboardStats($agencyId) {
    $db = Database::connect();
    
    $stats = [];

    try {
      // Total Incidents
      $sql = "SELECT COUNT(*) as total_incidents FROM incidents WHERE agency_id = :agency_id";
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':agency_id', $agencyId);
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      $stats['total_incidents'] = intval($result['total_incidents'] ?? 0);

      // Total Users (civilians who reported incidents to this agency)
      $sql = "SELECT COUNT(DISTINCT user_id) as total_users 
              FROM incidents 
              WHERE agency_id = :agency_id AND user_id IS NOT NULL";
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':agency_id', $agencyId);
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      $stats['total_users'] = intval($result['total_users'] ?? 0);

      // Total Dispatchers (agency users)
      $sql = "SELECT COUNT(*) as total_dispatchers 
              FROM users 
              WHERE agency_id = :agency_id AND role = 'agency' AND status = 'active'";
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':agency_id', $agencyId);
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      $stats['total_dispatchers'] = intval($result['total_dispatchers'] ?? 0);

      // Resolved Cases
      $sql = "SELECT COUNT(*) as resolved_cases 
              FROM incidents 
              WHERE agency_id = :agency_id AND status = 'resolved'";
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':agency_id', $agencyId);
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      $stats['resolved_cases'] = intval($result['resolved_cases'] ?? 0);

      // Reports by Status
      $sql = "SELECT status, COUNT(*) as count 
              FROM incidents 
              WHERE agency_id = :agency_id 
              GROUP BY status";
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':agency_id', $agencyId);
      $stmt->execute();
      $statusReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stats['reports_by_status'] = $statusReports;

      // Incidents by Type
      $sql = "SELECT incident_type, COUNT(*) as count 
              FROM incidents 
              WHERE agency_id = :agency_id 
              GROUP BY incident_type";
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':agency_id', $agencyId);
      $stmt->execute();
      $typeReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stats['incidents_by_type'] = $typeReports;

      // Live Incident Map Data (active incidents with coordinates)
      $sql = "SELECT incident_id, incident_type, latitude, longitude, status, severity_level, created_at
              FROM incidents 
              WHERE agency_id = :agency_id 
              AND status IN ('pending', 'dispatched', 'in-progress')
              AND latitude IS NOT NULL 
              AND longitude IS NOT NULL";
      $stmt = $db->prepare($sql);
      $stmt->bindParam(':agency_id', $agencyId);
      $stmt->execute();
      $liveIncidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $stats['live_incidents'] = $liveIncidents;

      return $stats;

    } catch (PDOException $e) {
      error_log("Database error in getDashboardStats: " . $e->getMessage());
      
      // Return default empty stats in case of error
      return [
        'total_incidents' => 0,
        'total_users' => 0,
        'total_dispatchers' => 0,
        'resolved_cases' => 0,
        'reports_by_status' => [],
        'incidents_by_type' => [],
        'live_incidents' => []
      ];
    }
  }
}