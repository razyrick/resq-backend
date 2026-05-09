<?php
namespace App\Models;

use App\Core\Database;
use PDO;
use PDOException;

class Patient {
  public static function insert(string $patientId, string $fullName, string $reason, string $agencyId): bool {
    $db = Database::connect();

    try {
      $stmt = $db->prepare("
        INSERT INTO patients (patient_id, full_name, reason, agency_id, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'ongoing', NOW(), NOW())
      ");
      return $stmt->execute([$patientId, $fullName, $reason, $agencyId]);
    } catch (PDOException $e) {
      error_log('Patient::insert error: ' . $e->getMessage());
      return false;
    }
  }

  public static function getByAgency(
    string $agencyId,
    int $limit = 20,
    int $offset = 0,
    string $status = '',
    string $search = ''
  ): array {
    $db = Database::connect();

    $lim = max(1, min(500, (int) $limit));
    $off = max(0, (int) $offset);

    $sql = "
      SELECT p.*
      FROM patients p
      WHERE TRIM(COALESCE(p.agency_id, '')) = :agency_id
    ";
    $params = [':agency_id' => $agencyId];

    if ($status !== '') {
      $sql .= " AND p.status = :status";
      $params[':status'] = $status;
    }

    if ($search !== '') {
      $sql .= " AND (p.full_name LIKE :search OR p.reason LIKE :search2 OR p.patient_id LIKE :search3)";
      $term = '%' . $search . '%';
      $params[':search'] = $term;
      $params[':search2'] = $term;
      $params[':search3'] = $term;
    }

    $sql .= " ORDER BY p.created_at DESC, p.patient_id DESC LIMIT {$lim} OFFSET {$off}";

    try {
      $stmt = $db->prepare($sql);
      foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
      }
      $stmt->execute();
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      error_log('Patient::getByAgency error: ' . $e->getMessage());
      return [];
    }
  }

  public static function countByAgency(string $agencyId, string $status = '', string $search = ''): int {
    $db = Database::connect();

    $sql = "SELECT COUNT(*) AS total FROM patients p WHERE TRIM(COALESCE(p.agency_id, '')) = :agency_id";
    $params = [':agency_id' => $agencyId];

    if ($status !== '') {
      $sql .= " AND p.status = :status";
      $params[':status'] = $status;
    }

    if ($search !== '') {
      $sql .= " AND (p.full_name LIKE :search OR p.reason LIKE :search2 OR p.patient_id LIKE :search3)";
      $term = '%' . $search . '%';
      $params[':search'] = $term;
      $params[':search2'] = $term;
      $params[':search3'] = $term;
    }

    try {
      $stmt = $db->prepare($sql);
      foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
      }
      $stmt->execute();
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return intval($row['total'] ?? 0);
    } catch (PDOException $e) {
      error_log('Patient::countByAgency error: ' . $e->getMessage());
      return 0;
    }
  }

  public static function countAll(): int {
    $db = Database::connect();

    try {
      $stmt = $db->query('SELECT COUNT(*) AS total FROM patients');
      if ($stmt === false) {
        return 0;
      }
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return (int) ($row['total'] ?? 0);
    } catch (PDOException $e) {
      error_log('Patient::countAll error: ' . $e->getMessage());
      return 0;
    }
  }

  public static function sampleDistinctAgencyIds(int $limit = 8): array {
    $db = Database::connect();
    $lim = max(1, min(50, (int) $limit));

    try {
      $sql = "
        SELECT DISTINCT agency_id AS aid
        FROM patients
        WHERE agency_id IS NOT NULL AND TRIM(agency_id) <> ''
        ORDER BY agency_id ASC
        LIMIT {$lim}
      ";
      $stmt = $db->query($sql);
      if ($stmt === false) {
        return [];
      }
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $out = [];
      foreach ($rows as $row) {
        if (!empty($row['aid'])) {
          $out[] = (string) $row['aid'];
        }
      }
      return $out;
    } catch (PDOException $e) {
      error_log('Patient::sampleDistinctAgencyIds error: ' . $e->getMessage());
      return [];
    }
  }

  public static function updateStatusForAgency(string $patientId, string $agencyId, string $status): bool {
    if ($status !== 'resolved') {
      return false;
    }

    $db = Database::connect();

    try {
      $stmt = $db->prepare("
        UPDATE patients
        SET status = ?, updated_at = NOW()
        WHERE patient_id = ? AND TRIM(COALESCE(agency_id, '')) = ?
      ");
      return $stmt->execute([$status, $patientId, trim($agencyId)]);
    } catch (PDOException $e) {
      error_log('Patient::updateStatusForAgency error: ' . $e->getMessage());
      return false;
    }
  }

  public static function getRowForAgency(string $patientId, string $agencyId): ?array {
    $db = Database::connect();

    try {
      $stmt = $db->prepare("
        SELECT patient_id, agency_id, status
        FROM patients
        WHERE patient_id = ? AND TRIM(COALESCE(agency_id, '')) = ?
      ");
      $stmt->execute([$patientId, trim($agencyId)]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (PDOException $e) {
      error_log('Patient::getRowForAgency error: ' . $e->getMessage());
      return null;
    }
  }
}
