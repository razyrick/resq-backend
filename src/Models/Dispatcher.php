<?php
namespace App\Models;

use App\Core\Database;
use PDO;
use PDOException;

class Dispatcher {
  // Profile
  public static function findByApiKey(string $apiKey): ?array {
    $db = Database::connect();
    
    $sql = "SELECT * FROM users WHERE api_key = :api_key AND role = 'dispatcher' LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':api_key', $apiKey);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
  }

  // Incidents
  public static function getIncidentsWithDispatcherTrue($limit = 20, $offset = 0, $status = '', $severity = '', $type = '', $search = '', $dateScope = 'today') {
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
            WHERE (
              (i.dispatcher_id IS NOT NULL AND i.dispatcher_id != '' AND i.dispatcher_id != '0')
              OR i.status = 'dispatched'
              OR (i.agency_id IS NOT NULL AND i.agency_id != '' AND i.agency_id != '0')
            )";
    
    $params = [];
    
    // Use PHP calendar date (Asia/Manila via index.php), not MySQL CURDATE(),
    // so "today" matches incident rows written with PHP date() on hosts where DB TZ is UTC.
    if ($dateScope === 'today') {
      $sql .= " AND DATE(i.created_at) = :today_date";
    }
    
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
      
      if ($dateScope === 'today') {
        $stmt->bindValue(':today_date', date('Y-m-d'));
      }
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
      error_log("Database error in getIncidentsWithDispatcherTrue: " . $e->getMessage());
      return [];
    }
  }

  public static function getTotalIncidentsWithDispatcherTrue($status = '', $severity = '', $type = '', $search = '', $dateScope = 'today') {
    $db = Database::connect();
    
    $sql = "SELECT COUNT(*) as total 
            FROM incidents i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE (
              (i.dispatcher_id IS NOT NULL AND i.dispatcher_id != '' AND i.dispatcher_id != '0')
              OR i.status = 'dispatched'
              OR (i.agency_id IS NOT NULL AND i.agency_id != '' AND i.agency_id != '0')
            )";
    
    $params = [];
    
    if ($dateScope === 'today') {
      $sql .= " AND DATE(i.created_at) = :today_date";
    }
    
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
      
      if ($dateScope === 'today') {
        $stmt->bindValue(':today_date', date('Y-m-d'));
      }
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
      error_log("Database error in getTotalIncidentsWithDispatcherTrue: " . $e->getMessage());
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

  public static function updateIncidentStatus($incidentId, $status, $dispatcherId, $agencyId) {
    $db = Database::connect();
    
    // Simple update query - no complex checks
    $stmt = $db->prepare("
      UPDATE incidents 
      SET status = ?, 
          dispatcher_id = ?, 
          agency_id = ?, 
          updated_at = NOW() 
      WHERE incident_id = ?
    ");
    
    return $stmt->execute([$status, $dispatcherId, $agencyId, $incidentId]);
  }

  // Agency
  public static function createAgency($agency, $agencyType, $contactPerson, $phoneNumber, $emailAddress, $address, $numberOfUnits, $status = 'active') {
    $db = Database::connect();
    
    $agencyId = 'AG' . date('Ymd') . str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    
    $stmt = $db->prepare("
      INSERT INTO agency 
      (agency_id, agency, agency_type, contact_person, phone_number, email_address, address, number_of_units, status, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    return $stmt->execute([
      $agencyId,
      $agency,
      $agencyType,
      $contactPerson,
      $phoneNumber,
      $emailAddress,
      $address,
      $numberOfUnits,
      $status
    ]) ? $agencyId : false;
  }

  public static function getAgencies($limit = 10, $offset = 0, $status = '', $search = '') {
    $db = Database::connect();
    
    $lim = max(1, min(1000, (int) $limit));
    $off = max(0, (int) $offset);

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
    
    $sql .= " ORDER BY created_at DESC LIMIT {$lim} OFFSET {$off}";
    
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

  public static function getAgencyById($agencyId) {
    $db = Database::connect();
    
    $stmt = $db->prepare("SELECT * FROM agency WHERE agency_id = ?");
    $stmt->execute([$agencyId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public static function updateAgency($agencyId, $data) {
    $db = Database::connect();
    
    $allowedFields = ['agency', 'agency_type', 'contact_person', 'phone_number', 'email_address', 'address', 'number_of_units', 'status'];
    $updates = [];
    $params = [];
    
    foreach ($allowedFields as $field) {
      if (isset($data[$field])) {
        $updates[] = "$field = ?";
        $params[] = $data[$field];
      }
    }
    
    if (empty($updates)) {
      error_log("No fields to update for agency: $agencyId");
      return false;
    }
    
    $params[] = $agencyId;
    
    $sql = "UPDATE agency SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE agency_id = ?";
    
    try {
      error_log("Executing SQL: $sql with params: " . json_encode($params));
      $stmt = $db->prepare($sql);
      $result = $stmt->execute($params);
      error_log("Update result: " . ($result ? 'success' : 'failed'));
      return $result;
    } catch (PDOException $e) {
      error_log("Database error in updateAgency: " . $e->getMessage());
      return false;
    }
  }

  public static function deleteAgency($agencyId) {
    $db = Database::connect();
    
    try {
      $stmt = $db->prepare("DELETE FROM agency WHERE agency_id = ?");
      return $stmt->execute([$agencyId]);
    } catch (PDOException $e) {
      error_log("Database error in deleteAgency: " . $e->getMessage());
      return false;
    }
  }

  // Dashboard
  public static function getDashboardStats($year = '', $startDate = '', $endDate = '', $activeIncidentsTodayOnly = false) {
    $db = Database::connect();
    
    $activeDateClause = $activeIncidentsTodayOnly ? " AND DATE(created_at) = CURDATE()" : '';
    $sql = "
        SELECT 
            (SELECT COUNT(*) FROM incidents) as total_incidents,
            (SELECT COUNT(*) FROM incidents WHERE (status IS NULL OR status IN ('pending', 'ongoing', 'dispatched')){$activeDateClause}) as active_incidents,
            (SELECT COUNT(*) FROM agency WHERE status = 'active') as total_agencies,
            (SELECT COUNT(*) FROM incidents WHERE status = 'resolved') as resolved_cases
    ";
    
    try {
      $stmt = $db->prepare($sql);
      $stmt->execute();
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      
      return [
        'total_incidents' => intval($result['total_incidents'] ?? 0),
        'active_incidents' => intval($result['active_incidents'] ?? 0),
        'total_agencies' => intval($result['total_agencies'] ?? 0),
        'resolved_cases' => intval($result['resolved_cases'] ?? 0)
      ];
    } catch (PDOException $e) {
      error_log("Database error in getDashboardStats: " . $e->getMessage());
      return [
        'total_incidents' => 0,
        'active_incidents' => 0,
        'total_agencies' => 0,
        'resolved_cases' => 0
      ];
    }
  }

  public static function getMonthlyIncidentsCount($year = '', $startDate = '', $endDate = '') {
    $db = Database::connect();
    
    // Keep date filtering for monthly breakdown
    $whereConditions = [];
    $params = [];
    
    if (!empty($startDate) && !empty($endDate)) {
      $whereConditions[] = "DATE(created_at) BETWEEN ? AND ?";
      $params[] = $startDate;
      $params[] = $endDate;
    } elseif (!empty($year)) {
      $whereConditions[] = "YEAR(created_at) = ?";
      $params[] = $year;
    }
    
    $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);
    
    $sql = "
      SELECT 
          MONTH(created_at) as month,
          COUNT(*) as count,
          SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count
      FROM incidents 
      $whereClause
      GROUP BY MONTH(created_at)
      ORDER BY month
    ";
    
    try {
      $stmt = $db->prepare($sql);
      $stmt->execute($params);
      $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create array for all months
        $monthlyData = [];
        for ($i = 1; $i <= 12; $i++) {
          $monthlyData[$i] = [
            'month' => $i,
            'month_name' => date('F', mktime(0, 0, 0, $i, 1)),
            'total' => 0,
            'resolved' => 0
          ];
        }
        
        // Fill with actual data
        foreach ($results as $row) {
          $month = intval($row['month']);
          $monthlyData[$month]['total'] = intval($row['count']);
          $monthlyData[$month]['resolved'] = intval($row['resolved_count']);
        }
        
        return array_values($monthlyData);
    } catch (PDOException $e) {
      error_log("Database error in getMonthlyIncidentsCount: " . $e->getMessage());
      return [];
    }
  }

  public static function getRecentActivity($limit = 10) {
    $db = Database::connect();

    $sql = "
      SELECT 
          i.*,
          u.first_name,
          u.last_name,
          u.email,
          b.baranggay,
          a.agency as agency_name
      FROM incidents i
      LEFT JOIN users u ON i.user_id = u.user_id
      LEFT JOIN baranggay b ON i.baranggay_id = b.baranggay_id
      LEFT JOIN agency a ON i.agency_id = a.agency_id
      ORDER BY i.created_at DESC
      LIMIT ?
    ";

    try {
      $stmt = $db->prepare($sql);
      $stmt->execute([$limit]);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log("Database error in getRecentActivity: " . $e->getMessage());
      return [];
    }
  }

  public static function getIncidentCoordinates($year = '', $startDate = '', $endDate = '', $dashboardActiveToday = false) {
    $db = Database::connect();
    
    $whereConditions = ["latitude IS NOT NULL", "longitude IS NOT NULL", "latitude != 0", "longitude != 0"];
    $params = [];
    
    if ($dashboardActiveToday) {
      $whereConditions[] = "DATE(created_at) = CURDATE()";
      $whereConditions[] = "(status IS NULL OR status IN ('pending', 'ongoing', 'dispatched'))";
    }
    
    if (!empty($startDate) && !empty($endDate)) {
      $whereConditions[] = "DATE(created_at) BETWEEN ? AND ?";
      $params[] = $startDate;
      $params[] = $endDate;
    } elseif (!empty($year)) {
      $whereConditions[] = "YEAR(created_at) = ?";
      $params[] = $year;
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    $sql = "
      SELECT 
          incident_id,
          incident_type,
          severity_level,
          status,
          latitude,
          longitude,
          created_at,
          description
      FROM incidents 
      $whereClause
      ORDER BY created_at DESC
    ";
    
    try {
      $stmt = $db->prepare($sql);
      $stmt->execute($params);
      $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
      
      // Filter out invalid coordinates and format the data
      $coordinates = [];
      foreach ($results as $incident) {
        $lat = floatval($incident['latitude']);
        $lng = floatval($incident['longitude']);
        
        // Validate coordinates (Philippines coordinates range)
        if ($lat >= 4 && $lat <= 21 && $lng >= 116 && $lng <= 127) {
          $coordinates[] = [
            'incident_id' => $incident['incident_id'],
            'incident_type' => $incident['incident_type'],
            'severity_level' => $incident['severity_level'],
            'status' => $incident['status'] ?? 'pending',
            'latitude' => $lat,
            'longitude' => $lng,
            'created_at' => $incident['created_at'],
            'description' => $incident['description']
          ];
        }
      }
        
      return $coordinates;
    } catch (PDOException $e) {
      error_log("Database error in getIncidentCoordinates: " . $e->getMessage());
      return [];
    }
  }
  
  // Dispatcher Users
public static function getDispatcherUsers(int $limit = 20, int $offset = 0, string $status = 'pending', string $search = ''): array {
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
          WHERE role = 'dispatcher' 
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
    error_log("Database error in getDispatcherUsers: " . $e->getMessage());
    return [];
  }
}

public static function getTotalDispatcherUsers(string $status = 'pending', string $search = ''): int {
  $db = Database::connect();
  
  $sql = "SELECT COUNT(*) as total 
          FROM users 
          WHERE role = 'dispatcher' 
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
    error_log("Database error in getTotalDispatcherUsers: " . $e->getMessage());
    return 0;
  }
}
}