<?php
namespace App\Models;

use App\Core\Database;
use PDO;
use PDOException;

class Patient {
  public static function insert(
    string $patientId,
    string $fullName,
    string $reason,
    string $agencyId,
    string $status = 'ongoing'
  ): bool {
    $allowed = ['ongoing', 'incoming'];
    if (!in_array($status, $allowed, true)) {
      $status = 'ongoing';
    }

    $db = Database::connect();

    try {
      $stmt = $db->prepare("
        INSERT INTO patients (patient_id, full_name, reason, agency_id, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
      ");
      return $stmt->execute([$patientId, $fullName, $reason, $agencyId, $status]);
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
      return self::attachIncidentLinks($db, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
      error_log('Patient::getByAgency error: ' . $e->getMessage());
      return [];
    }
  }

  private static function attachIncidentLinks(PDO $db, array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    $patientIds = [];
    foreach ($rows as $row) {
      $patientId = trim((string) ($row['patient_id'] ?? ''));
      if ($patientId !== '') {
        $patientIds[] = $patientId;
      }
    }
    $patientIds = array_values(array_unique($patientIds));
    if (empty($patientIds)) {
      return $rows;
    }

    try {
      $placeholders = implode(',', array_fill(0, count($patientIds), '?'));
      $stmt = $db->prepare("
        SELECT patient_id, incident_id, incident_type, status, severity_level, created_at
        FROM incidents
        WHERE patient_id IN ($placeholders)
        ORDER BY created_at DESC
      ");
      $stmt->execute($patientIds);
      $links = [];
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $incident) {
        $linkedPatientId = (string) ($incident['patient_id'] ?? '');
        if ($linkedPatientId !== '' && !isset($links[$linkedPatientId])) {
          $links[$linkedPatientId] = $incident;
        }
      }

      foreach ($rows as &$row) {
        $patientId = (string) ($row['patient_id'] ?? '');
        if (!isset($links[$patientId])) {
          continue;
        }
        $incident = $links[$patientId];
        $row['linked_incident_id'] = $incident['incident_id'] ?? null;
        $row['linked_incident_type'] = $incident['incident_type'] ?? null;
        $row['linked_incident_status'] = $incident['status'] ?? null;
        $row['linked_incident_severity'] = $incident['severity_level'] ?? null;
        $row['linked_incident_created_at'] = $incident['created_at'] ?? null;
      }
      unset($row);
    } catch (PDOException $e) {
      error_log('Patient::attachIncidentLinks error: ' . $e->getMessage());
    }

    return $rows;
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

  /**
   * Agency-only transitions: incoming → arrived; arrived|ongoing → resolved.
   * Returns true when a row was updated (invalid transition updates 0 rows).
   */
  public static function updateStatusForAgency(string $patientId, string $agencyId, string $newStatus): bool {
    if ($newStatus === 'arrived') {
      $sql = "
        UPDATE patients
        SET status = 'arrived', updated_at = NOW()
        WHERE patient_id = ?
          AND TRIM(COALESCE(agency_id, '')) = ?
          AND status = 'incoming'
      ";
    } elseif ($newStatus === 'resolved') {
      $sql = "
        UPDATE patients
        SET status = 'resolved', updated_at = NOW()
        WHERE patient_id = ?
          AND TRIM(COALESCE(agency_id, '')) = ?
          AND status IN ('arrived', 'ongoing')
      ";
    } else {
      return false;
    }

    $db = Database::connect();

    try {
      $stmt = $db->prepare($sql);
      $stmt->execute([$patientId, trim($agencyId)]);
      return $stmt->rowCount() > 0;
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

  /**
   * Move patient to another agency (status reset to incoming for the receiver).
   * Also updates incidents linked by patient_id that are still assigned to $fromAgencyId.
   *
   * @return array{ok: bool, incidents_updated: int, error?: string}
   */
  public static function transferPatientToAgency(
    string $patientId,
    string $fromAgencyId,
    string $toAgencyId
  ): array {
    $from = trim($fromAgencyId);
    $to = trim($toAgencyId);
    if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
      return ['ok' => false, 'incidents_updated' => 0, 'error' => 'invalid_agencies'];
    }

    $db = Database::connect();

    try {
      $db->beginTransaction();

      $stmt = $db->prepare("
        UPDATE patients
        SET agency_id = ?, status = 'incoming', updated_at = NOW()
        WHERE patient_id = ?
          AND TRIM(COALESCE(agency_id, '')) = ?
          AND status IN ('incoming', 'arrived')
      ");
      $stmt->execute([$to, $patientId, $from]);
      if ($stmt->rowCount() < 1) {
        $db->rollBack();
        return ['ok' => false, 'incidents_updated' => 0, 'error' => 'patient_not_transferable'];
      }

      $stmtInc = $db->prepare("
        UPDATE incidents
        SET agency_id = ?, updated_at = NOW()
        WHERE patient_id = ?
          AND TRIM(COALESCE(agency_id, '')) = ?
      ");
      $stmtInc->execute([$to, $patientId, $from]);
      $incidentsUpdated = (int) $stmtInc->rowCount();

      if ($incidentsUpdated === 0) {
        $stmtFallback = $db->prepare("
          UPDATE incidents
          SET agency_id = ?, updated_at = NOW()
          WHERE patient_id = ?
            AND (status IS NULL OR status <> 'resolved')
          ORDER BY created_at DESC
          LIMIT 1
        ");
        $stmtFallback->execute([$to, $patientId]);
        $incidentsUpdated = (int) $stmtFallback->rowCount();
      }

      $db->commit();
      return ['ok' => true, 'incidents_updated' => $incidentsUpdated];
    } catch (PDOException $e) {
      if ($db->inTransaction()) {
        $db->rollBack();
      }
      error_log('Patient::transferPatientToAgency error: ' . $e->getMessage());
      return ['ok' => false, 'incidents_updated' => 0, 'error' => 'database'];
    }
  }
}
