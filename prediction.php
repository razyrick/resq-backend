<?php
header('Content-Type: application/json');

try {
    $db = new PDO("mysql:host=localhost;dbname=u949229918_resq", "u949229918_resq", "Resq@2025");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql = "SELECT 
              i.*,
              u.first_name,
              u.middle_name,
              u.last_name,
              u.phone,
              u.email,
              u.user_id,
              b.baranggay,
              a.agency
            FROM incidents i
            LEFT JOIN users u ON i.user_id = u.user_id
            LEFT JOIN baranggay b ON i.baranggay_id = b.baranggay_id
            LEFT JOIN agency a ON i.agency_id = a.agency_id
            ORDER BY i.created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $incidents,
        'total' => count($incidents)
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>