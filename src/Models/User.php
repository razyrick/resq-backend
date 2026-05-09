<?php
namespace App\Models;

use App\Core\Database;
use PDO;

class User {
  // Profile
  public static function findByApiKey(string $apiKey): ?array {
    $db = Database::connect();
    
    $sql = "SELECT * FROM users WHERE api_key = :api_key AND role = 'user' LIMIT 1";
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

  // Baranggay
  public static function getAllBaranggays(): array {
    $db = Database::connect();
    
    $sql = "SELECT baranggay_id, baranggay, contact_number, contact_person, email, latitude, longitude 
            FROM baranggay 
            ORDER BY baranggay ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public static function getBaranggays(int $limit = 10, int $offset = 0, string $search = ''): array {
    $db = Database::connect();
    
    $sql = "SELECT baranggay_id, baranggay, contact_number, contact_person, email, latitude, longitude, created_at, updated_at 
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

  public static function findBaranggayById(int $baranggayId): ?array {
    $db = Database::connect();
    
    $sql = "SELECT * FROM baranggay WHERE baranggay_id = :baranggay_id LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':baranggay_id', $baranggayId);
    $stmt->execute();
    
    $baranggay = $stmt->fetch(PDO::FETCH_ASSOC);
    return $baranggay ?: null;
  }

  public static function findBaranggayByName(string $baranggayName): ?array {
    $db = Database::connect();
    
    $sql = "SELECT * FROM baranggay WHERE baranggay = :baranggay LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':baranggay', $baranggayName);
    $stmt->execute();
    
    $baranggay = $stmt->fetch(PDO::FETCH_ASSOC);
    return $baranggay ?: null;
  }

  // Incidents
  public static function createIncident(array $incidentData): bool {
    $db = Database::connect();
    
    $sql = "INSERT INTO incidents (
              incident_id, user_id, baranggay_id, latitude, longitude, 
              incident_type, severity_level, description, photo, status, created_at, updated_at
            ) VALUES (
              :incident_id, :user_id, :baranggay_id, :latitude, :longitude,
              :incident_type, :severity_level, :description, :photo, :status, :created_at, :updated_at
            )";
    
    $stmt = $db->prepare($sql);
    
    return $stmt->execute([
      ':incident_id' => $incidentData['incident_id'],
      ':user_id' => $incidentData['user_id'],
      ':baranggay_id' => $incidentData['baranggay_id'],
      ':latitude' => $incidentData['latitude'],
      ':longitude' => $incidentData['longitude'],
      ':incident_type' => $incidentData['incident_type'],
      ':severity_level' => $incidentData['severity_level'],
      ':description' => $incidentData['description'],
      ':photo' => $incidentData['photo'],
      ':status' => $incidentData['status'],
      ':created_at' => $incidentData['created_at'],
      ':updated_at' => $incidentData['updated_at']
    ]);
  }

  public static function getIncidentsByUser(string $userId, int $limit = 10, int $offset = 0): array {
    $db = Database::connect();
    
   $sql = "SELECT 
              i.incident_id, i.latitude, i.longitude, i.incident_type, 
              i.severity_level, i.desacription, i.photo, i.status, i.created_at, i.updated_at,
              i.resolution_photo, i.resolution_notes, i.resolved_at, i.resolved_by, i.resolved_by_role,
              i.baranggay_id,
              b.baranggay as baranggay_name,
              b.latitude as baranggay_latitude,
              b.longitude as baranggay_longitude,
              b.created_at as baranggay_created_at,
              b.updated_at as baranggay_updated_at,
              a.agency_id,
              a.agency,
              a.agency_type,
              a.contact_person,
              a.phone_number,
              a.email_address,
              a.address
            FROM incidents i
            LEFT JOIN baranggay b ON i.baranggay_id = b.baranggay_id
            LEFT JOIN agency a ON i.agency_id = a.agency_id
            WHERE i.user_id = :user_id 
            ORDER BY i.created_at DESC 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_STR);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public static function getTotalIncidentsByUser(string $userId): int {
    $db = Database::connect();
    
    $sql = "SELECT COUNT(*) as total FROM incidents WHERE user_id = :user_id";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return intval($result['total'] ?? 0);
  }

  public static function getIncidentById(string $incidentId, string $userId): ?array {
    $db = Database::connect();
    
    $sql = "SELECT 
              i.*, 
              b.baranggay,
              b.latitude,
              b.longitude,
              b.created_at,
              b.updated_at
            FROM incidents i
            LEFT JOIN baranggay b ON i.baranggay_id = b.baranggay_id
            WHERE i.incident_id = :incident_id AND i.user_id = :user_id";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':incident_id', $incidentId, PDO::PARAM_STR);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
  }

  public static function getIncidentsByBarangay($barangayId, $limit = 10, $offset = 0) {
    $db = Database::connect();
    
    $sql = "SELECT * FROM incidents 
            WHERE baranggay_id = :barangay_id
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':barangay_id', $barangayId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public static function getTotalIncidentsByBarangay($barangayId) {
    $db = Database::connect();
    
    $sql = "SELECT COUNT(*) as total 
            FROM incidents 
            WHERE baranggay_id = :barangay_id";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':barangay_id', $barangayId, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'] ?? 0;
  }

  // Dashboard
  public static function getDashboardStats($userId) {
    $db = Database::connect();
    
    $sql = "SELECT 
      COUNT(*) as total_incidents,
      SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
      SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
      SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing,
      SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
      COUNT(DISTINCT user_id) as reports_submitted
      FROM incidents 
      WHERE user_id = :user_id";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  // Notifications
  public static function insertNotification(array $notificationData): bool {
    $db = Database::connect();

    $sql = "INSERT INTO notifications (
      notification_id, user_id, title, message, type, 
      related_incident_id, is_read, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $db->prepare($sql);
    return $stmt->execute([
      $notificationData['notification_id'],
      $notificationData['user_id'],
      $notificationData['title'],
      $notificationData['message'],
      $notificationData['type'] ?? 'system',
      $notificationData['related_incident_id'] ?? null,
      $notificationData['is_read'] ?? 0,
      $notificationData['created_at'],
      $notificationData['updated_at']
    ]);
  }

  public static function markNotificationAsRead(string $notificationId, string $userId): bool {
    $db = Database::connect();

    $sql = "UPDATE notifications SET is_read = 1, updated_at = ? 
            WHERE notification_id = ? AND user_id = ?";
    
    $stmt = $db->prepare($sql);
    return $stmt->execute([date('Y-m-d H:i:s'), $notificationId, $userId]);
  }

  public static function markAllNotificationsAsRead(string $userId): bool {
    $db = Database::connect();

    $sql = "UPDATE notifications SET is_read = 1, updated_at = ? 
            WHERE user_id = ? AND is_read = 0";
    
    $stmt = $db->prepare($sql);
    return $stmt->execute([date('Y-m-d H:i:s'), $userId]);
  }

  public static function getUserNotifications(string $userId, int $limit = 10, int $offset = 0, bool $unreadOnly = false): array {
    $db = Database::connect();

    $sql = "SELECT * FROM notifications WHERE user_id = :user_id";
    
    if ($unreadOnly) {
        $sql .= " AND is_read = 0";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($sql);
    
    // Bind parameters with proper types
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  public static function getTotalNotificationsCount(string $userId): int {
    $db = Database::connect();

    $sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return intval($result['count'] ?? 0);
  }

  public static function getUnreadNotificationsCount(string $userId): int {
    $db = Database::connect();

    $sql = "SELECT COUNT(*) as count FROM notifications 
            WHERE user_id = ? AND is_read = 0";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return intval($result['count'] ?? 0);
  }

  public static function deleteNotification(string $notificationId, string $userId): bool {
    $db = Database::connect();

    $sql = "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?";
    $stmt = $db->prepare($sql);
    return $stmt->execute([$notificationId, $userId]);
  }
}