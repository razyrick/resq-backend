<?php
namespace App\Services;

use App\Models\User;
use Exception;

class NotificationService {
  private $userModel;

  public function __construct() {
    $this->userModel = new User();
  }

  public function createNotification(array $notificationData): bool {
    try {
      $notificationData['notification_id'] = $this->generateNotificationId();
      $notificationData['is_read'] = $notificationData['is_read'] ?? 0;
      $notificationData['created_at'] = date('Y-m-d H:i:s');
      $notificationData['updated_at'] = date('Y-m-d H:i:s');

      $requiredFields = ['user_id', 'title', 'message'];
      foreach ($requiredFields as $field) {
        if (empty($notificationData[$field])) {
          throw new Exception("Missing required field: {$field}");
        }
      }

      return $this->userModel->insertNotification($notificationData);

    } catch (Exception $e) {
      error_log("NotificationService Error: " . $e->getMessage());
      return false;
    }
  }

  public function createIncidentNotification(string $userId, string $incidentId, string $incidentType, string $severityLevel, string $barangayName = null): bool {
    $title = "New Incident Reported";
    $message = "A new {$severityLevel} level {$incidentType} has been reported";

    if ($barangayName) {
      $message .= " in {$barangayName}";
    }

    $notificationData = [
      'user_id' => $userId,
      'title' => $title,
      'message' => $message,
      'type' => 'incident',
      'related_incident_id' => $incidentId,
      'is_read' => 0
    ];

    return $this->createNotification($notificationData);
  }

  public function createIncidentStatusNotification(string $userId, string $incidentId, string $oldStatus, string $newStatus): bool {
    $title = "Incident Status Updated";
    $message = "Your incident report status has been changed from {$oldStatus} to {$newStatus}";

    $notificationData = [
      'user_id' => $userId,
      'title' => $title,
      'message' => $message,
      'type' => 'update',
      'related_incident_id' => $incidentId,
      'is_read' => 0
    ];

    return $this->createNotification($notificationData);
  }

  public function createSystemAlert(string $userId, string $title, string $message): bool {
    $notificationData = [
      'user_id' => $userId,
      'title' => $title,
      'message' => $message,
      'type' => 'alert',
      'is_read' => 0
    ];

    return $this->createNotification($notificationData);
  }

  public function createProfileUpdateNotification(string $userId): bool {
    $notificationData = [
      'user_id' => $userId,
      'title' => "Profile Updated",
      'message' => "Your profile information has been successfully updated",
      'type' => 'update',
      'is_read' => 0
    ];

    return $this->createNotification($notificationData);
  }

  public function markAsRead(string $notificationId, string $userId): bool {
    try {
      return $this->userModel->markNotificationAsRead($notificationId, $userId);
    } catch (Exception $e) {
      error_log("NotificationService Error: " . $e->getMessage());
      return false;
    }
  }

  public function markAllAsRead(string $userId): bool {
    try {
      return $this->userModel->markAllNotificationsAsRead($userId);
    } catch (Exception $e) {
      error_log("NotificationService Error: " . $e->getMessage());
      return false;
    }
  }

  public function getUserNotifications(string $userId, int $limit = 10, int $offset = 0, bool $unreadOnly = false): array {
    try {
      return $this->userModel->getUserNotifications($userId, $limit, $offset, $unreadOnly);
    } catch (Exception $e) {
      error_log("NotificationService Error: " . $e->getMessage());
      return [];
    }
  }

  public function getUnreadCount(string $userId): int {
    try {
      return $this->userModel->getUnreadNotificationsCount($userId);
    } catch (Exception $e) {
      error_log("NotificationService Error: " . $e->getMessage());
      return 0;
    }
  }

  public function deleteNotification(string $notificationId, string $userId): bool {
    try {
      return $this->userModel->deleteNotification($notificationId, $userId);
    } catch (Exception $e) {
      error_log("NotificationService Error: " . $e->getMessage());
      return false;
    }
  }

  private function generateNotificationId(): string {
    return 'NOTIF_' . bin2hex(random_bytes(8));
  }
}