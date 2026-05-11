<?php
namespace App\Controllers;

use App\Core\Request;
use App\Models\Agency;
use App\Models\Patient;
use App\Core\RateLimiter;
use App\Core\Auth;
use Exception;

class AgencyController {
  private function getApiKey(Request $request): ?string {
    $authHeader = $request->getHeader('Authorization');
    if (empty($authHeader)) {
      return null;
    }
    return trim(str_replace('Bearer ', '', $authHeader));
  }

  private function resolveAgencyIdForPatients(array $user): string {
    $linked = trim((string) ($user['linked_agency_id'] ?? ''));
    $stored = trim((string) ($user['agency_id'] ?? ''));
    $candidates = [];
    if ($linked !== '') {
      $candidates[] = $linked;
    }
    if ($stored !== '' && !in_array($stored, $candidates, true)) {
      $candidates[] = $stored;
    }
    $email = trim((string) ($user['email'] ?? ''));
    if ($email !== '') {
      $fromEmail = Agency::findAgencyIdByContactEmail($email);
      if ($fromEmail !== null && $fromEmail !== '' && !in_array($fromEmail, $candidates, true)) {
        $candidates[] = $fromEmail;
      }
    }
    foreach ($candidates as $cid) {
      if (Patient::countByAgency($cid, '', '') > 0) {
        return $cid;
      }
    }
    return $candidates[0] ?? '';
  }

  // Profile
  public function getProfile(Request $request) {
    $apiKey = $this->getApiKey($request);

    if (empty($apiKey)) {
      http_response_code(401);
      return json_encode(['error' => 'API key is required']);
    }

    if (!RateLimiter::check($apiKey)) {
      http_response_code(429);
      return json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    }

    try {
      // Get user by API key
      $user = Agency::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Return user profile without sensitive information
      $profile = [
        'user_id' => $user['user_id'],
        'agency_id' => $user['agency_id'],
        'agency' => $user['agency'],
        'agency_type' => $user['agency_type'],
        'contact_person' => $user['contact_person'],
        'agency_phone' => $user['agency_phone'],
        'agency_email' => $user['agency_email'],
        'agency_address' => $user['agency_address'],
        'number_of_units' => $user['number_of_units'],
        'agency_status' => $user['agency_status'],
        'agency_created_at' => $user['agency_created_at'],
        'agency_updated_at' => $user['agency_updated_at'],
        'first_name' => $user['first_name'],
        'middle_name' => $user['middle_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'profile' => $user['profile'],
        'role' => $user['role'],
        'status' => $user['status'],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at']
      ];

      return json_encode([
        'success' => true,
        'data' => $profile
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function updateProfile(Request $request) {
    $apiKey = $this->getApiKey($request);

    if (empty($apiKey)) {
      http_response_code(401);
      return json_encode(['error' => 'API key is required']);
    }

    if (!RateLimiter::check($apiKey)) {
      http_response_code(429);
      return json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    }

    $csrfToken = $request->getHeader('X-CSRF-Token');
    if (empty($csrfToken)) {
      http_response_code(403);
      return json_encode(['error' => 'CSRF token is required']);
    }

    try {
      // Get user by API key
      $user = Agency::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Verify CSRF token
      if ($user['csrf_token'] !== $csrfToken) {
        http_response_code(403);
        return json_encode(['error' => 'Invalid CSRF token']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Get request body
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (empty($input)) {
        http_response_code(400);
        return json_encode(['error' => 'Request body is required']);
      }

      // Validate required fields
      $requiredFields = ['first_name', 'last_name', 'email', 'phone', 'profile', 'agency_id'];
      foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
          http_response_code(400);
          return json_encode(['error' => "Field '$field' is required"]);
        }
      }

      // Validate email format
      if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid email format']);
      }

      // Check if email is already taken by another user
      $existingUser = Agency::findByEmail($input['email']);
      if ($existingUser && $existingUser['user_id'] !== $user['user_id']) {
        http_response_code(400);
        return json_encode(['error' => 'Email is already taken by another user']);
      }

      // Prepare update data
      $updateData = [
        'agency_id' => trim($input['agency_id']),
        'first_name' => trim($input['first_name']),
        'last_name' => trim($input['last_name']),
        'middle_name' => isset($input['middle_name']) ? trim($input['middle_name']) : null,
        'email' => trim($input['email']),
        'profile' => trim($input['profile']),
        'phone' => trim($input['phone'])
      ];

      // Update user profile
      $success = Agency::updateUserProfile($user['user_id'], $updateData);

      if ($success) {
        // Get updated user data
        $updatedUser = Agency::findByApiKey($apiKey);
        
        $profile = [
          'user_id' => $updatedUser['user_id'],
          'agency_id' => $updatedUser['agency_id'],
          'first_name' => $updatedUser['first_name'],
          'middle_name' => $updatedUser['middle_name'],
          'last_name' => $updatedUser['last_name'],
          'email' => $updatedUser['email'],
          'phone' => $updatedUser['phone'],
          'profile' => $updatedUser['profile'],
          'role' => $updatedUser['role'],
          'status' => $updatedUser['status'],
          'created_at' => $updatedUser['created_at'],
          'updated_at' => $updatedUser['updated_at']
        ];

        return json_encode([
          'success' => true,
          'message' => 'Profile updated successfully',
          'data' => $profile
        ]);
      } else {
        http_response_code(500);
        return json_encode(['error' => 'Failed to update profile']);
      }

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  // Agency
  public function getAgencies(Request $request) {
    $apiKey = $this->getApiKey($request);

    if (empty($apiKey)) {
      http_response_code(401);
      return json_encode(['error' => 'API key is required']);
    }

    if (!RateLimiter::check($apiKey)) {
      http_response_code(429);
      return json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    }

    try {
      // Get user by API key
      $user = Agency::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Get pagination parameters from query string
      $page = max(1, intval($request->getQuery('page', 1)));
      $limit = max(1, min(100, intval($request->getQuery('limit', 10))));
      $offset = ($page - 1) * $limit;

      // Get filter parameters
      $status = $request->getQuery('status', '');
      $search = $request->getQuery('search', '');

      // Get agencies
      $agencies = Agency::getAgencies(
        $limit, 
        $offset, 
        $status, 
        $search
      );

      // Get total count for pagination
      $totalAgencies = Agency::getTotalAgencies(
        $status, 
        $search
      );

      // Calculate total pages
      $totalPages = ceil($totalAgencies / $limit);

      return json_encode([
        'success' => true,
        'data' => $agencies,
        'pagination' => [
          'current_page' => $page,
          'per_page' => $limit,
          'total_items' => $totalAgencies,
          'total_pages' => $totalPages,
          'has_next' => $page < $totalPages,
          'has_prev' => $page > 1
        ],
        'filters' => [
          'status' => $status,
          'search' => $search
        ]
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  // Incidents
  public function getIncidents(Request $request) {
    $apiKey = $this->getApiKey($request);

    if (empty($apiKey)) {
      http_response_code(401);
      return json_encode(['error' => 'API key is required']);
    }

    if (!RateLimiter::check($apiKey)) {
      http_response_code(429);
      return json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    }

    $csrfToken = $request->getHeader('X-CSRF-Token');
    if (empty($csrfToken)) {
      http_response_code(403);
      return json_encode(['error' => 'CSRF token is required']);
    }

    try {
      // Get user by API key
      $user = Agency::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Verify CSRF token
      if ($user['csrf_token'] !== $csrfToken) {
        http_response_code(403);
        return json_encode(['error' => 'Invalid CSRF token']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Get pagination parameters from query string
      $page = max(1, intval($request->getQuery('page', 1)));
      $limit = max(1, min(100, intval($request->getQuery('limit', 20))));
      $offset = ($page - 1) * $limit;

      // Get filter parameters
      $status = $request->getQuery('status', '');
      $severity = $request->getQuery('severity', '');
      $type = $request->getQuery('type', '');
      $search = $request->getQuery('search', '');

      // Get incidents where Agency_id = true
      $incidents = Agency::getIncidents(
        $user['agency_id'],
        $limit, 
        $offset, 
        $status, 
        $severity, 
        $type, 
        $search
      );

      // Get total count for pagination
      $totalIncidents = Agency::getTotalIncidents(
        $user['agency_id'],
        $status, 
        $severity, 
        $type, 
        $search
      );

      // Calculate total pages
      $totalPages = ceil($totalIncidents / $limit);

      return json_encode([
        'success' => true,
        'data' => $incidents,
        'pagination' => [
          'current_page' => $page,
          'per_page' => $limit,
          'total_items' => $totalIncidents,
          'total_pages' => $totalPages,
          'has_next' => $page < $totalPages,
          'has_prev' => $page > 1
        ],
        'filters' => [
          'status' => $status,
          'severity' => $severity,
          'type' => $type,
          'search' => $search
        ]
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function getPatients(Request $request) {
    $apiKey = $this->getApiKey($request);

    if (empty($apiKey)) {
      http_response_code(401);
      return json_encode(['error' => 'API key is required']);
    }

    if (!RateLimiter::check($apiKey)) {
      http_response_code(429);
      return json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    }

    $csrfToken = $request->getHeader('X-CSRF-Token');
    if (empty($csrfToken)) {
      http_response_code(403);
      return json_encode(['error' => 'CSRF token is required']);
    }

    try {
      $user = Agency::findByApiKey($apiKey);

      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      if ($user['csrf_token'] !== $csrfToken) {
        http_response_code(403);
        return json_encode(['error' => 'Invalid CSRF token']);
      }

      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      $agencyId = $this->resolveAgencyIdForPatients($user);
      if ($agencyId === '') {
        http_response_code(400);
        return json_encode(['error' => 'No agency is assigned to this account; patients cannot be listed.']);
      }

      $page = max(1, intval($request->getQuery('page', 1)));
      $limit = max(1, min(100, intval($request->getQuery('limit', 20))));
      $offset = ($page - 1) * $limit;

      $status = $request->getQuery('status', '');
      $search = $request->getQuery('search', '');

      $rows = Patient::getByAgency(
        $agencyId,
        $limit,
        $offset,
        $status,
        $search
      );
      $total = Patient::countByAgency($agencyId, $status, $search);
      $totalPages = ceil($total / $limit);

      $allPatients = Patient::countAll();
      $meta = [
        'your_agency_id' => $agencyId,
        'profile_agency_id' => trim((string) ($user['agency_id'] ?? '')),
        'patients_total_in_database' => $allPatients,
        'rows_matching_your_agency' => $total,
      ];
      if ($total === 0 && $allPatients > 0) {
        $meta['sample_agency_ids_in_patients_table'] = Patient::sampleDistinctAgencyIds(8);
      }

      return json_encode([
        'success' => true,
        'data' => $rows,
        'meta' => $meta,
        'pagination' => [
          'current_page' => $page,
          'per_page' => $limit,
          'total_items' => $total,
          'total_pages' => $totalPages,
          'has_next' => $page < $totalPages,
          'has_prev' => $page > 1,
        ],
        'filters' => [
          'status' => $status,
          'search' => $search,
        ],
      ]);
    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function updatePatientStatus(Request $request) {
    $apiKey = $this->getApiKey($request);

    if (empty($apiKey)) {
      http_response_code(401);
      return json_encode(['error' => 'API key is required']);
    }

    if (!RateLimiter::check($apiKey)) {
      http_response_code(429);
      return json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    }

    $csrfToken = $request->getHeader('X-CSRF-Token');
    if (empty($csrfToken)) {
      http_response_code(403);
      return json_encode(['error' => 'CSRF token is required']);
    }

    try {
      $user = Agency::findByApiKey($apiKey);

      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      if ($user['csrf_token'] !== $csrfToken) {
        http_response_code(403);
        return json_encode(['error' => 'Invalid CSRF token']);
      }

      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      $agencyId = $this->resolveAgencyIdForPatients($user);
      if ($agencyId === '') {
        http_response_code(400);
        return json_encode(['error' => 'No agency is assigned to this account.']);
      }

      $input = json_decode(file_get_contents('php://input'), true);

      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON input']);
      }

      $patientId = isset($input['patient_id']) ? trim((string)$input['patient_id']) : '';
      $status = isset($input['status']) ? trim((string)$input['status']) : '';

      if ($patientId === '' || $status === '') {
        http_response_code(400);
        return json_encode(['error' => 'patient_id and status are required']);
      }

      if ($status !== 'arrived' && $status !== 'resolved') {
        http_response_code(400);
        return json_encode(['error' => 'status must be arrived or resolved']);
      }

      $existing = Patient::getRowForAgency($patientId, $agencyId);
      if (!$existing) {
        http_response_code(404);
        return json_encode(['error' => 'Patient not found']);
      }

      $current = (string) ($existing['status'] ?? '');
      if ($status === 'arrived' && $current !== 'incoming') {
        http_response_code(400);
        return json_encode(['error' => 'Only incoming patients can be marked arrived']);
      }
      if ($status === 'resolved' && $current !== 'arrived' && $current !== 'ongoing') {
        http_response_code(400);
        return json_encode(['error' => 'Resolve is only allowed after arrival, or for legacy ongoing patients']);
      }

      if (!Patient::updateStatusForAgency($patientId, $agencyId, $status)) {
        http_response_code(400);
        return json_encode(['error' => 'Could not apply status change (invalid transition or no row updated)']);
      }

      return json_encode([
        'success' => true,
        'message' => 'Patient status updated',
        'data' => [
          'patient_id' => $patientId,
          'status' => $status,
        ],
      ]);
    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function updateIncidentStatus(Request $request) {
    $apiKey = $this->getApiKey($request);

    if (empty($apiKey)) {
      http_response_code(401);
      return json_encode(['error' => 'API key is required']);
    }

    if (!RateLimiter::check($apiKey)) {
      http_response_code(429);
      return json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    }

    $csrfToken = $request->getHeader('X-CSRF-Token');
    if (empty($csrfToken)) {
      http_response_code(403);
      return json_encode(['error' => 'CSRF token is required']);
    }

    try {
      // Get user by API key
      $user = Agency::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Verify CSRF token
      if ($user['csrf_token'] !== $csrfToken) {
        http_response_code(403);
        return json_encode(['error' => 'Invalid CSRF token']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Get request body
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (empty($input['incident_id'])) {
        http_response_code(400);
        return json_encode(['error' => 'Incident ID is required']);
      }

      if (empty($input['status'])) {
        http_response_code(400);
        return json_encode(['error' => 'Status is required']);
      }

      $incidentId = $input['incident_id'];
      $status = $input['status'];

      $resolutionExtras = [];
      if ($status === 'resolved') {
        $resolutionPhoto = isset($input['resolution_photo']) ? trim((string)$input['resolution_photo']) : '';
        if ($resolutionPhoto === '') {
          http_response_code(422);
          return json_encode(['error' => 'A proof photo is required to resolve this incident']);
        }
        $resolutionExtras['resolution_photo'] = $resolutionPhoto;
        if (isset($input['resolution_notes'])) {
          $resolutionExtras['resolution_notes'] = trim((string)$input['resolution_notes']);
        }
        $resolutionExtras['resolved_by'] = $user['user_id'];
      }

      // Get incident details first to check phone number and verify ownership
      $incident = Agency::getIncidentById($incidentId);
      
      if (!$incident) {
        http_response_code(404);
        return json_encode(['error' => 'Incident not found']);
      }

      // Verify that the incident belongs to the agency
      if ($incident['agency_id'] != $user['agency_id']) {
        http_response_code(403);
        return json_encode(['error' => 'You do not have permission to update this incident']);
      }

      // Update incident status
      $success = Agency::updateIncidentStatus($incidentId, $status, $user['agency_id'], $resolutionExtras);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to update incident status']);
      }

      // Check if there's a phone number and send SMS
      if (!empty($incident['phone'])) {
        // Format phone number properly for SMS API
        $phoneNumber = $this->formatPhoneNumber($incident['phone']);
        
        if ($phoneNumber) {
          // Get reporter's full name
          $reporterFirstName = $incident['first_name'] ?? '';
          $reporterLastName = $incident['last_name'] ?? '';
          $reporterName = trim($reporterFirstName . ' ' . $reporterLastName) ?: 'User';
          
          // Get agency details
          $agencyDetails = Agency::getAgencyById($user['agency_id']);
          $agencyName = $agencyDetails ? ($agencyDetails['agency'] ?? 'Response Agency') : 'Response Agency';
          
          // Get agency user's name
          $agencyUserFirstName = $user['first_name'] ?? '';
          $agencyUserLastName = $user['last_name'] ?? '';
          $agencyUserName = trim($agencyUserFirstName . ' ' . $agencyUserLastName) ?: 'Agency Personnel';
          
          // Determine appropriate message based on status
          $statusMessage = '';
          if ($status === 'ongoing') {
            $statusMessage = "is now being handled by {$agencyName}";
          } elseif ($status === 'resolved') {
            $statusMessage = "has been RESOLVED by {$agencyName}";
          } elseif ($status === 'dispatched') {
            $statusMessage = "has been dispatched to {$agencyName}";
          } else {
            $statusMessage = "status has been updated to '{$status}' by {$agencyName}";
          }
          
          $smsMessage = "RES-Q Laguna: Hi {$reporterName}, your incident report (ID:{$incidentId}) {$statusMessage}. Updated by {$agencyUserName}.";
          
          $smsResult = $this->sendSMS($smsMessage, [$phoneNumber]);
          
          // Log SMS result
          if (!$smsResult['success']) {
            error_log("SMS sending failed for incident {$incidentId}: " . json_encode($smsResult['error']));
          }
        } else {
          error_log("Invalid phone number format for incident {$incidentId}: " . $incident['phone']);
        }
      } else {
        error_log("No phone number found for incident {$incidentId}");
      }

      return json_encode([
        'success' => true,
        'message' => 'Incident status updated successfully',
        'data' => [
          'incident_id' => $incidentId,
          'status' => $status,
          'updated_at' => date('Y-m-d H:i:s')
        ]
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }
  
  private function formatPhoneNumber(string $phone): ?string {
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Check if it's empty
    if (empty($phone)) {
        return null;
    }
    
    // If it starts with 0, replace with +63
    if (substr($phone, 0, 1) === '0') {
        $phone = '+63' . substr($phone, 1);
    }
    // If it starts with 63, add +
    elseif (substr($phone, 0, 2) === '63') {
        $phone = '+' . $phone;
    }
    // If it starts with 9 (mobile number without country code), add +63
    elseif (substr($phone, 0, 1) === '9') {
        $phone = '+63' . $phone;
    }
    // If it doesn't start with +, add it
    elseif (substr($phone, 0, 1) !== '+') {
        $phone = '+' . $phone;
    }
    
    return $phone;
  }
  
  private function sendSMS(string $message, array $recipients): array {
    $apiKey = 'sk-ea496ceb5e380399b5575e32';
    $url = 'https://sms-api-ph.onrender.com/send/sms';
    
    $data = [
        'recipient' => $recipients[0],
        'message' => $message
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'x-api-key: ' . $apiKey,
            'content-type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("SMS sending error: " . $error);
        return [
            'success' => false,
            'error' => $error
        ];
    }
    
    $responseData = json_decode($response, true);
    
    if ($httpCode !== 200) {
        error_log("SMS API error - HTTP {$httpCode}: " . $response);
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => $responseData['error'] ?? 'Failed to send SMS'
        ];
    }
    
    return [
        'success' => true,
        'data' => $responseData
    ];
  }

  // Dashboard
  public function getDashboardStats(Request $request) {
    $apiKey = $this->getApiKey($request);

    if (empty($apiKey)) {
      http_response_code(401);
      return json_encode(['error' => 'API key is required']);
    }

    if (!RateLimiter::check($apiKey)) {
      http_response_code(429);
      return json_encode(['error' => 'Rate limit exceeded. Try again later.']);
    }

    $csrfToken = $request->getHeader('X-CSRF-Token');
    if (empty($csrfToken)) {
      http_response_code(403);
      return json_encode(['error' => 'CSRF token is required']);
    }

    try {
      // Get user by API key
      $user = Agency::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Verify CSRF token
      if ($user['csrf_token'] !== $csrfToken) {
        http_response_code(403);
        return json_encode(['error' => 'Invalid CSRF token']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Get dashboard statistics
      $stats = Agency::getDashboardStats($user['agency_id']);

      return json_encode([
        'success' => true,
        'data' => $stats
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }
}