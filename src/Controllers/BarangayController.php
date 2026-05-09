<?php
namespace App\Controllers;

use App\Core\Request;
use App\Models\Barangay;
use App\Core\RateLimiter;
use App\Core\Auth;
use Exception;

class BarangayController {
  private function getApiKey(Request $request): ?string {
    $authHeader = $request->getHeader('Authorization');
    if (empty($authHeader)) {
      return null;
    }
    return trim(str_replace('Bearer ', '', $authHeader));
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

    $csrfToken = $request->getHeader('X-CSRF-Token');
    if (empty($csrfToken)) {
      http_response_code(403);
      return json_encode(['error' => 'CSRF token is required']);
    }

    try {
      // Get user by API key
      $userModel = new Barangay();
      $user = $userModel->findByApiKey($apiKey);
      
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

      // Build profile with actual database fields
      $profile = [
        'id' => $user['id'] ?? '',
        'user_id' => $user['user_id'] ?? '',
        'api_key' => $user['api_key'] ?? '',
        'csrf_token' => $user['csrf_token'] ?? '',
        'baranggay_id' => $user['baranggay_id'] ?? '',
        'role' => $user['role'] ?? '',
        'status' => $user['status'] ?? '',
        'profile' => $user['profile'] ?? '',
        'phone' => $user['phone'] ?? '',
        'first_name' => $user['first_name'] ?? '',
        'middle_name' => $user['middle_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'email' => $user['email'] ?? '', 
        'google_id' => $user['google_id'] ?? '',
        'created_at' => $user['created_at'] ?? '',
        'updated_at' => $user['updated_at'] ?? ''
      ];

      // Check if profile is complete (basic validation)
      $missingFields = $this->checkProfileCompletion($user);
      if (!empty($missingFields)) {
        http_response_code(422);
        return json_encode([
          'error' => 'Profile incomplete',
          'message' => 'Please complete your profile before accessing this feature',
          'missing_fields' => $missingFields,
          'profile' => $profile 
        ]);
      }

      return json_encode([
        'success' => true,
        'data' => $profile
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  private function checkProfileCompletion(array $user): array {
    $requiredFields = [
      'first_name',
      'last_name', 
      'email'
    ];

    $missingFields = [];
    foreach ($requiredFields as $field) {
      if (empty($user[$field])) {
        $missingFields[] = $field;
      }
    }

    return $missingFields;
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
      $user = Barangay::findByApiKey($apiKey);
      
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

      // Get request data from php://input instead of getInput()
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON data']);
      }

      // Define allowed fields for update
      $allowedFields = [
        'first_name',
        'middle_name', 
        'last_name',
        'email',
        'baranggay_id',
        'phone',
        'profile' 
      ];

      // Filter and validate input
      $updateData = [];
      foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
          // Basic validation
          if ($field === 'email' && !filter_var($input[$field], FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            return json_encode(['error' => 'Invalid email format']);
          }
          
          if (in_array($field, ['first_name', 'last_name']) && empty(trim($input[$field]))) {
            http_response_code(422);
            return json_encode(['error' => "$field cannot be empty"]);
          }

          $updateData[$field] = trim($input[$field]);
        }
      }

      // Check if there's anything to update
      if (empty($updateData)) {
        http_response_code(422);
        return json_encode(['error' => 'No valid fields to update']);
      }

      // Add updated timestamp
      $updateData['updated_at'] = date('Y-m-d H:i:s');

      // Update user profile
      $success = Barangay::updateProfile($user['id'], $updateData);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to update profile']);
      }

      // Get updated user data
      $updatedUser = Barangay::findByApiKey($apiKey);
      
      // Build response profile
      $profile = [
        'id' => $updatedUser['id'] ?? '',
        'user_id' => $updatedUser['user_id'] ?? '',
        'api_key' => $updatedUser['api_key'] ?? '',
        'csrf_token' => $updatedUser['csrf_token'] ?? '',
        'baranggay_id' => $updatedUser['baranggay_id'] ?? '',
        'role' => $updatedUser['role'] ?? '',
        'status' => $updatedUser['status'] ?? '',
        'profile' => $updatedUser['profile'] ?? '',
        'phone' => $updatedUser['phone'] ?? '',
        'first_name' => $updatedUser['first_name'] ?? '',
        'middle_name' => $updatedUser['middle_name'] ?? '',
        'last_name' => $updatedUser['last_name'] ?? '',
        'email' => $updatedUser['email'] ?? '',
        'google_id' => $updatedUser['google_id'] ?? '',
        'created_at' => $updatedUser['created_at'] ?? '',
        'updated_at' => $updatedUser['updated_at'] ?? ''
      ];

      return json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'data' => $profile
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  // Barangay
  public function getBarangay(Request $request) {
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
      $user = Barangay::findByApiKey($apiKey);
      
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
      $limit = max(1, min(50, intval($request->getQuery('limit', 50))));
      $offset = ($page - 1) * $limit;

      // Get search parameter
      $search = $request->getQuery('search', '');

      // Get barangays with pagination
      $baranggays = Barangay::getBaranggays($limit, $offset, $search);
      $totalBaranggays = Barangay::getTotalBaranggays($search);

      // Calculate total pages
      $totalPages = ceil($totalBaranggays / $limit);

      return json_encode([
        'success' => true,
        'data' => $baranggays,
        'pagination' => [
          'current_page' => $page,
          'per_page'     => $limit,
          'total_items'  => $totalBaranggays,
          'total_pages'  => $totalPages,
          'has_next'     => $page < $totalPages,
          'has_prev'     => $page > 1
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
      $user = Barangay::findByApiKey($apiKey);
      
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

      // Check if user has barangay_id
      if (empty($user['baranggay_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'User barangay not set. Please update your profile.']);
      }
      
      $escalationResult = Barangay::autoEscalateIncidentsToDispatcher($user['baranggay_id']);

      // Get pagination parameters from query string
      $page = max(1, intval($request->getQuery('page', 1)));
      $limit = max(1, min(100, intval($request->getQuery('limit', 20))));
      $offset = ($page - 1) * $limit;

      // Get filter parameters
      $status = $request->getQuery('status', '');
      $severity = $request->getQuery('severity', '');
      $type = $request->getQuery('type', '');
      $search = $request->getQuery('search', '');

      // Get incidents by barangay_id with pagination and filters
      $incidents = Barangay::getIncidentsByBarangayId(
        $user['baranggay_id'], 
        $limit, 
        $offset, 
        $status, 
        $severity, 
        $type, 
        $search
      );
      
      $totalIncidents = Barangay::getTotalIncidentsByBarangayId(
        $user['baranggay_id'], 
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
          'per_page'     => $limit,
          'total_items'  => $totalIncidents,
          'total_pages'  => $totalPages,
          'has_next'     => $page < $totalPages,
          'has_prev'     => $page > 1
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
      $user = Barangay::findByApiKey($apiKey);
      
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

      // Check if user has barangay_id
      if (empty($user['baranggay_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'User barangay not set. Please update your profile.']);
      }

      // Get request data
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON data']);
      }

      // Validate required fields
      if (!isset($input['incident_id']) || empty($input['incident_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'Incident ID is required']);
      }

      $incidentId = trim($input['incident_id']);

      // Get incident details to verify ownership/access
      $incident = Barangay::getIncidentById($incidentId);
      
      if (!$incident) {
        http_response_code(404);
        return json_encode(['error' => 'Incident not found']);
      }

      // Verify that the incident belongs to the user's barangay
      if ($incident['baranggay_id'] !== $user['baranggay_id']) {
        http_response_code(403);
        return json_encode(['error' => 'You do not have permission to update this incident']);
      }

      // Prepare update data
      $updateData = [
        'updated_at' => date('Y-m-d H:i:s')
      ];

      // Handle status update
      if (isset($input['status']) && !empty($input['status'])) {
        $status = trim($input['status']);
        
        // Validate status value
        $allowedStatuses = ['ongoing', 'resolved'];
        if (!in_array($status, $allowedStatuses)) {
          http_response_code(422);
          return json_encode(['error' => 'Invalid status. Allowed values: ' . implode(', ', $allowedStatuses)]);
        }
        
        $updateData['status'] = $status;

        // If status is changing to ongoing, set assign_id
        if ($status === 'ongoing') {
          $assignId = $user['user_id'];
          $updateData['assign_id'] = $assignId;
        }

        if ($status === 'resolved') {
          $resolutionPhoto = isset($input['resolution_photo']) ? trim((string)$input['resolution_photo']) : '';
          if ($resolutionPhoto === '') {
            http_response_code(422);
            return json_encode(['error' => 'A proof photo is required to resolve this case']);
          }
          $updateData['resolved_by'] = $user['user_id'];
          $updateData['resolved_by_role'] = 'barangay';
          $updateData['resolved_at'] = date('Y-m-d H:i:s');
          $updateData['resolution_photo'] = $resolutionPhoto;
          if (isset($input['resolution_notes'])) {
            $updateData['resolution_notes'] = trim((string)$input['resolution_notes']);
          }
        }
      }

      // Handle dispatcher_id update (normalize JSON booleans / integers from the barangay app)
      if (array_key_exists('dispatcher_id', $input)) {
        $dispatcherIdRaw = $input['dispatcher_id'];
        if ($dispatcherIdRaw === true || $dispatcherIdRaw === 1) {
          $dispatcherId = Barangay::DISPATCHER_ESCALATION_PLACEHOLDER_ID;
        } elseif ($dispatcherIdRaw === false || $dispatcherIdRaw === null) {
          $dispatcherId = '';
        } elseif (is_scalar($dispatcherIdRaw)) {
          $dispatcherId = trim((string) $dispatcherIdRaw);
        } else {
          $dispatcherId = '';
        }

        if ($dispatcherId !== '') {
          $updateData['dispatcher_id'] = $dispatcherId;
          $updateData['status'] = 'ongoing';
        } else {
          $updateData['dispatcher_id'] = null;
        }
      }

      // Handle agency_id update
      if (isset($input['agency_id'])) {
        $agencyId = trim($input['agency_id']);
        if (!empty($agencyId)) {
          // Validate agency exists if needed
          $updateData['agency_id'] = $agencyId;
        } else {
          $updateData['agency_id'] = null;
        }
      }

      // Accept & Respond: ongoing + assign_id without handing off to a dispatcher.
      // If auto-escalation already set placeholder dispatcher_id = '1', clear it so the
      // incident stays barangay-owned (spec: accepted reports must not remain escalated).
      $dispatcherIdKeyPresent = array_key_exists('dispatcher_id', $input);
      if (
        ($updateData['status'] ?? '') === 'ongoing'
        && isset($updateData['assign_id'])
        && !$dispatcherIdKeyPresent
      ) {
        $prevDispatcher = isset($incident['dispatcher_id']) ? trim((string) $incident['dispatcher_id']) : '';
        if (Barangay::isDispatcherEscalationPlaceholder($prevDispatcher)) {
          $updateData['dispatcher_id'] = null;
        }
      }

      // Check if any update data is provided (besides updated_at)
      if (count($updateData) <= 1) {
        http_response_code(422);
        return json_encode(['error' => 'No update data provided. Please provide status, dispatcher_id, or agency_id']);
      }

      // Update incident
      $success = Barangay::updateIncidentStatus($incidentId, $updateData);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to update incident']);
      }

      // Get updated incident data
      $updatedIncident = Barangay::getIncidentById($incidentId);
      
      // Check if there's a phone number and send SMS
      if (!empty($updatedIncident['phone'])) {
        // Format phone number properly for SMS API
        $phoneNumber = $this->formatPhoneNumber($updatedIncident['phone']);
        
        if ($phoneNumber) {
          $smsResult = $this->sendSMS(
            "RES-Q Laguna: Hi {$updatedIncident['first_name']} {$updatedIncident['last_name']}!, Incident #{$incidentId} has been updated to status: " . ($updateData['status'] ?? 'updated') . " by {$user['first_name']} {$user['last_name']}. from Barangay {$updatedIncident['baranggay']}",
            [$phoneNumber]  
          );
          
          // Log SMS result
          if (!$smsResult['success']) {
            error_log("SMS sending failed for incident {$incidentId}: " . json_encode($smsResult['error']));
          }
        } else {
          error_log("Invalid phone number format for incident {$incidentId}: " . $updatedIncident['phone']);
        }
      } else {
        error_log("No phone number found for incident {$incidentId}");
      }

      return json_encode([
        'success' => true,
        'message' => 'Incident updated successfully',
        'data' => $updatedIncident
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
      $user = Barangay::findByApiKey($apiKey);
      
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

      // Check if user has barangay_id
      if (empty($user['baranggay_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'User barangay not set. Please update your profile.']);
      }

      // Get date range parameters
      $startDate = $request->getQuery('start_date', '');
      $endDate = $request->getQuery('end_date', '');

      // Validate date format if provided
      if (!empty($startDate) && !$this->isValidDate($startDate)) {
        http_response_code(422);
        return json_encode(['error' => 'Invalid start_date format. Use YYYY-MM-DD']);
      }

      if (!empty($endDate) && !$this->isValidDate($endDate)) {
        http_response_code(422);
        return json_encode(['error' => 'Invalid end_date format. Use YYYY-MM-DD']);
      }

      // Get dashboard statistics
      $stats = Barangay::getDashboardStats($user['baranggay_id'], $startDate, $endDate);
      
      // Get incidents by type
      $incidentsByType = Barangay::getIncidentsByType($user['baranggay_id'], $startDate, $endDate);

      // Get recent incidents
      $recentIncidents = Barangay::getRecentIncidents($user['baranggay_id'], 5, $startDate, $endDate);

      return json_encode([
        'success' => true,
        'data' => [
          'stats' => $stats,
          'incidents_by_type' => $incidentsByType,
          'recent_incidents' => $recentIncidents,
          'date_range' => [
            'start_date' => $startDate,
            'end_date' => $endDate
          ]
        ]
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  private function isValidDate($date) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date);
  }

  // Officials
  public function createBarangayOfficial(Request $request) {
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
      $user = Barangay::findByApiKey($apiKey);
      
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

      // Check if user has barangay_id
      if (empty($user['baranggay_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'User barangay not set. Please update your profile.']);
      }

      // Get request data
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON data']);
      }

      // Validate required fields
      $requiredFields = ['first_name', 'last_name', 'position'];
      $missingFields = [];
      
      foreach ($requiredFields as $field) {
        if (empty(trim($input[$field] ?? ''))) {
          $missingFields[] = $field;
        }
      }

      if (!empty($missingFields)) {
        http_response_code(422);
        return json_encode([
          'error' => 'Missing required fields',
          'missing_fields' => $missingFields
        ]);
      }

      // Prepare official data
      $officialData = [
        'baranggay_id' => $user['baranggay_id'],
        'first_name' => trim($input['first_name']),
        'middle_name' => trim($input['middle_name'] ?? ''),
        'last_name' => trim($input['last_name']),
        'suffix' => trim($input['suffix'] ?? ''),
        'position' => trim($input['position']),
        'contact_number' => trim($input['contact'] ?? ''),
        'email' => trim($input['email'] ?? ''),
        'responsibilities' => trim($input['responsibilities'] ?? ''),
        'term_start' => !empty($input['termStart']) ? $input['termStart'] : null,
        'image_path' => trim($input['image_path'] ?? ''),
        'created_by' => $user['user_id'],
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
      ];

      // Validate email if provided
      if (!empty($officialData['email']) && !filter_var($officialData['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        return json_encode(['error' => 'Invalid email format']);
      }

      // Validate contact number if provided
      if (!empty($officialData['contact_number']) && !preg_match('/^\+?[\d\s\-\(\)]{10,}$/', $officialData['contact_number'])) {
        http_response_code(422);
        return json_encode(['error' => 'Invalid contact number format']);
      }

      // Create barangay official
      $officialId = Barangay::createBarangayOfficial($officialData);

      if (!$officialId) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to create barangay official']);
      }

      // Get created official data
      $createdOfficial = Barangay::getBarangayOfficialById($officialId);

      return json_encode([
        'success' => true,
        'message' => 'Barangay official created successfully',
        'data' => $createdOfficial
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function getOfficials(Request $request) {
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
      $user = Barangay::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Verify CSRF token
      if ($user['csrf_token'] !== $csrfToken) {
        http_response_code(403);
        return json_encode(['error' => 'Invalid CSRF token']);
      }

      // Check if user has barangay_id
      if (empty($user['baranggay_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'User barangay not set. Please update your profile.']);
      }

      // Get pagination parameters
      $page = max(1, intval($request->getQuery('page', 1)));
      $limit = max(1, min(100, intval($request->getQuery('limit', 50))));
      $offset = ($page - 1) * $limit;

      // Get barangay officials with pagination
      $officials = Barangay::getBarangayOfficials(
        $user['baranggay_id'], 
        $limit, 
        $offset
      );
      
      $totalOfficials = Barangay::getTotalBarangayOfficials($user['baranggay_id']);

      // Calculate total pages
      $totalPages = ceil($totalOfficials / $limit);

      return json_encode([
        'success' => true,
        'data' => $officials,
        'pagination' => [
          'current_page' => $page,
          'per_page' => $limit,
          'total_items' => $totalOfficials,
          'total_pages' => $totalPages,
          'has_next' => $page < $totalPages,
          'has_prev' => $page > 1
        ]
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function updateBarangayOfficial(Request $request) {
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
      $user = Barangay::findByApiKey($apiKey);
      
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

      // Check if user has barangay_id
      if (empty($user['baranggay_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'User barangay not set. Please update your profile.']);
      }

      // Get request data
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON data']);
      }

      // Validate required fields
      if (empty($input['id'])) {
        http_response_code(422);
        return json_encode(['error' => 'Official ID is required']);
      }

      $requiredFields = ['first_name', 'last_name', 'position'];
      $missingFields = [];
      
      foreach ($requiredFields as $field) {
        if (empty(trim($input[$field] ?? ''))) {
          $missingFields[] = $field;
        }
      }

      if (!empty($missingFields)) {
        http_response_code(422);
        return json_encode([
          'error' => 'Missing required fields',
          'missing_fields' => $missingFields
        ]);
      }

      // Verify the official belongs to the user's barangay
      $existingOfficial = Barangay::getBarangayOfficialById($input['id']);
      if (!$existingOfficial) {
        http_response_code(404);
        return json_encode(['error' => 'Official not found']);
      }

      if ($existingOfficial['baranggay_id'] !== $user['baranggay_id']) {
        http_response_code(403);
        return json_encode(['error' => 'You do not have permission to update this official']);
      }

      // Prepare update data
      $updateData = [
        'first_name' => trim($input['first_name']),
        'middle_name' => trim($input['middle_name'] ?? ''),
        'last_name' => trim($input['last_name']),
        'suffix' => trim($input['suffix'] ?? ''),
        'position' => trim($input['position']),
        'contact_number' => trim($input['contact'] ?? ''),
        'email' => trim($input['email'] ?? ''),
        'responsibilities' => trim($input['responsibilities'] ?? ''),
        'term_start' => !empty($input['termStart']) ? $input['termStart'] : null,
        'image_path' => trim($input['image_path'] ?? ''),
        'updated_at' => date('Y-m-d H:i:s')
      ];

      // Validate email if provided
      if (!empty($updateData['email']) && !filter_var($updateData['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        return json_encode(['error' => 'Invalid email format']);
      }

      // Validate contact number if provided
      if (!empty($updateData['contact_number']) && !preg_match('/^\+?[\d\s\-\(\)]{10,}$/', $updateData['contact_number'])) {
        http_response_code(422);
        return json_encode(['error' => 'Invalid contact number format']);
      }

      // Update barangay official
      $success = Barangay::updateBarangayOfficial($input['id'], $updateData);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to update barangay official']);
      }

      // Get updated official data
      $updatedOfficial = Barangay::getBarangayOfficialById($input['id']);

      return json_encode([
        'success' => true,
        'message' => 'Barangay official updated successfully',
        'data' => $updatedOfficial
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function deleteBarangayOfficial(Request $request) {
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
      $user = Barangay::findByApiKey($apiKey);
      
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

      // Check if user has barangay_id
      if (empty($user['baranggay_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'User barangay not set. Please update your profile.']);
      }

      // Get request data
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON data']);
      }

      // Validate required fields
      if (empty($input['id'])) {
        http_response_code(422);
        return json_encode(['error' => 'Official ID is required']);
      }

      // Verify the official belongs to the user's barangay
      $existingOfficial = Barangay::getBarangayOfficialById($input['id']);
      if (!$existingOfficial) {
        http_response_code(404);
        return json_encode(['error' => 'Official not found']);
      }

      if ($existingOfficial['baranggay_id'] !== $user['baranggay_id']) {
        http_response_code(403);
        return json_encode(['error' => 'You do not have permission to delete this official']);
      }

      // Delete barangay official
      $success = Barangay::deleteBarangayOfficial($input['id']);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to delete barangay official']);
      }

      return json_encode([
        'success' => true,
        'message' => 'Barangay official deleted successfully'
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }
  
  // Approval
  public function getBarangayUsers(Request $request) {
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
    $user = Barangay::findByApiKey($apiKey);
    
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

    // Get filter parameters - default status is 'pending'
    $status = $request->getQuery('status', 'pending');
    $search = $request->getQuery('search', '');

    // Validate status parameter
    $allowedStatuses = ['pending', 'active', 'banned'];
    if (!in_array($status, $allowedStatuses)) {
      http_response_code(422);
      return json_encode([
        'error' => 'Invalid status. Allowed values: ' . implode(', ', $allowedStatuses)
      ]);
    }

    // Get barangay users with pagination and filters
    $barangayUsers = Barangay::getBarangayUsers(
      $limit, 
      $offset, 
      $status, 
      $search
    );
    
    $totalBarangayUsers = Barangay::getTotalBarangayUsers(
      $status, 
      $search
    );

    // Calculate total pages
    $totalPages = ceil($totalBarangayUsers / $limit);

    return json_encode([
      'success' => true,
      'data' => $barangayUsers,
      'pagination' => [
        'current_page' => $page,
        'per_page'     => $limit,
        'total_items'  => $totalBarangayUsers,
        'total_pages'  => $totalPages,
        'has_next'     => $page < $totalPages,
        'has_prev'     => $page > 1
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

  public function updateBarangayUserStatus(Request $request) {
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
    $user = Barangay::findByApiKey($apiKey);
    
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

    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      http_response_code(400);
      return json_encode(['error' => 'Invalid JSON data']);
    }

    // Validate required fields
    if (!isset($input['user_id']) || empty($input['user_id'])) {
      http_response_code(422);
      return json_encode(['error' => 'User ID is required']);
    }

    if (!isset($input['status']) || empty($input['status'])) {
      http_response_code(422);
      return json_encode(['error' => 'Status is required']);
    }

    $userId = trim($input['user_id']);
    $status = trim($input['status']);

    // Validate status value
    $allowedStatuses = ['pending', 'active', 'banned'];
    if (!in_array($status, $allowedStatuses)) {
      http_response_code(422);
      return json_encode(['error' => 'Invalid status. Allowed values: ' . implode(', ', $allowedStatuses)]);
    }

    // Get user to update
    $userToUpdate = Barangay::getUserById($userId);
    
    if (!$userToUpdate) {
      http_response_code(404);
      return json_encode(['error' => 'User not found']);
    }

    // Verify the user has barangay role
    if ($userToUpdate['role'] !== 'barangay') {
      http_response_code(422);
      return json_encode(['error' => 'User is not a barangay user']);
    }

    // Update user status
    $success = Barangay::updateUserStatus($userId, $status);

    if (!$success) {
      http_response_code(500);
      return json_encode(['error' => 'Failed to update user status']);
    }

    // Get updated user data
    $updatedUser = Barangay::getUserById($userId);

    return json_encode([
      'success' => true,
      'message' => 'User status updated successfully',
      'data' => $updatedUser
    ]);

  } catch (Exception $e) {
    http_response_code(500);
    return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  }
}

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
      $user = Barangay::findByApiKey($apiKey);
      
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
      $agencies = Barangay::getAgencies(
        $limit, 
        $offset, 
        $status, 
        $search
      );

      // Get total count for pagination
      $totalAgencies = Barangay::getTotalAgencies(
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
}