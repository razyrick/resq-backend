<?php
namespace App\Controllers;

use App\Core\Request;
use App\Models\Dispatcher;
use App\Models\Patient;
use App\Core\RateLimiter;
use App\Core\Auth;
use Exception;

class DispatcherController {
  private function getApiKey(Request $request): ?string {
    $authHeader = $request->getHeader('Authorization');
    if (empty($authHeader)) {
      return null;
    }
    return trim(str_replace('Bearer ', '', $authHeader));
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
      $user = Dispatcher::findByApiKey($apiKey);
      
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
      $dateScopeRaw = $request->getQuery('date_scope', 'today');
      $dateScope = ($dateScopeRaw === 'all') ? 'all' : 'today';

      // Get incidents visible to dispatcher
      $incidents = Dispatcher::getIncidentsWithDispatcherTrue(
        $limit, 
        $offset, 
        $status, 
        $severity, 
        $type, 
        $search,
        $dateScope
      );

      // Get total count for pagination
      $totalIncidents = Dispatcher::getTotalIncidentsWithDispatcherTrue(
        $status, 
        $severity, 
        $type, 
        $search,
        $dateScope
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
          'search' => $search,
          'date_scope' => $dateScope
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

    try {
      // Get user by API key
      $user = Dispatcher::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Get request body
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON input']);
      }

      // Validate required fields - SIMPLIFIED!
      if (empty($input['incident_id'])) {
        http_response_code(400);
        return json_encode(['error' => 'incident_id is required']);
      }

      if (empty($input['status'])) {
        http_response_code(400);
        return json_encode(['error' => 'status is required']);
      }

      if (empty($input['agency_id'])) {
        http_response_code(400);
        return json_encode(['error' => 'agency_id is required']);
      }

      $incidentId = $input['incident_id'];
      $status = $input['status'];
      $agencyId = $input['agency_id'];

      // Validate that status is 'dispatched'
      if ($status !== 'dispatched') {
        http_response_code(400);
        return json_encode(['error' => 'Status must be "dispatched"']);
      }

      // Update incident - SIMPLIFIED!
      $success = Dispatcher::updateIncidentStatus(
        $incidentId, 
        $status, 
        $user['user_id'],
        $agencyId
      );

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to update incident status']);
      }
      
      // Get updated incident data
      $updatedIncident = Dispatcher::getIncidentById($incidentId);
      
      // Check if there's a phone number and send SMS
      if (!empty($updatedIncident['phone'])) {
        // Format phone number properly for SMS API
        $phoneNumber = $this->formatPhoneNumber($updatedIncident['phone']);
        
        if ($phoneNumber) {
          // Get reporter's full name
          $reporterFirstName = $updatedIncident['first_name'] ?? '';
          $reporterLastName = $updatedIncident['last_name'] ?? '';
          $reporterName = trim($reporterFirstName . ' ' . $reporterLastName);
          
          // Get dispatcher's full name
          $dispatcherFirstName = $user['first_name'] ?? '';
          $dispatcherLastName = $user['last_name'] ?? '';
          $dispatcherName = trim($dispatcherFirstName . ' ' . $dispatcherLastName);
          
          // Get agency name
          $agency = Dispatcher::getAgencyById($agencyId);
          $agencyName = $agency ? ($agency['agency'] ?? 'response agency') : 'response agency';
          
          $smsMessage = "RES-Q Laguna: Hi {$reporterName}, your incident report (ID:{$incidentId}) has been dispatched to {$agencyName} by dispatcher {$dispatcherName}.";
          
          $smsResult = $this->sendSMS(
            $smsMessage,  // FIXED: Added comma here
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
        'message' => 'Incident dispatched successfully'
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function createPatient(Request $request) {
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
      $user = Dispatcher::findByApiKey($apiKey);

      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      $input = json_decode(file_get_contents('php://input'), true);

      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON input']);
      }

      $fullName = isset($input['full_name']) ? trim((string)$input['full_name']) : '';
      $reason = isset($input['reason']) ? trim((string)$input['reason']) : '';
      $agencyId = isset($input['agency_id']) ? trim((string)$input['agency_id']) : '';
      $incidentId = isset($input['incident_id']) ? trim((string)$input['incident_id']) : '';
      $incidentId = $incidentId === '' ? null : $incidentId;

      if ($fullName === '' || $reason === '' || $agencyId === '') {
        http_response_code(400);
        return json_encode(['error' => 'full_name, reason, and agency_id are required']);
      }

      if ($incidentId !== null && !Dispatcher::getIncidentById($incidentId)) {
        http_response_code(400);
        return json_encode(['error' => 'Incident not found']);
      }

      $agency = Dispatcher::getAgencyById($agencyId);
      if (!$agency) {
        http_response_code(400);
        return json_encode(['error' => 'Agency not found']);
      }

      // No FK from patients to agency in DB — validate agency exists here.
      if (($agency['status'] ?? '') !== 'active') {
        http_response_code(400);
        return json_encode(['error' => 'Agency is not active']);
      }

      $patientId = 'PT' . date('Ymd') . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);

      if (!Patient::insert($patientId, $fullName, $reason, $agencyId)) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to create patient']);
      }

      if ($incidentId !== null && !Dispatcher::linkPatientToIncident($incidentId, $patientId)) {
        http_response_code(500);
        return json_encode(['error' => 'Patient created, but failed to link it to the incident']);
      }

      return json_encode([
        'success' => true,
        'message' => 'Patient record created',
        'data' => [
          'patient_id' => $patientId,
          'full_name' => $fullName,
          'reason' => $reason,
          'agency_id' => $agencyId,
          'incident_id' => $incidentId,
          'status' => 'ongoing',
        ],
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

  // Agency
  public function createAgency(Request $request) {
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
      $user = Dispatcher::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Get request body
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON input']);
      }

      // Validate required fields
      $requiredFields = ['agency', 'agency_type', 'contact_person', 'phone_number', 'email_address', 'address', 'number_of_units'];
      foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
          http_response_code(400);
          return json_encode(['error' => "{$field} is required"]);
        }
      }

      // Validate status
      $status = $input['status'] ?? 'active';
      if (!in_array($status, ['active', 'inactive'])) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid status. Must be active or inactive']);
      }

      // Validate number_of_units
      $numberOfUnits = intval($input['number_of_units']);
      if ($numberOfUnits < 0) {
        http_response_code(400);
        return json_encode(['error' => 'Number of units must be a positive integer']);
      }

      // Create agency
      $agencyId = Dispatcher::createAgency(
        $input['agency'],
        $input['agency_type'],
        $input['contact_person'],
        $input['phone_number'],
        $input['email_address'],
        $input['address'],
        $numberOfUnits,
        $status
      );

      if (!$agencyId) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to create agency']);
      }

      return json_encode([
        'success' => true,
        'message' => 'Agency created successfully',
        'data' => ['agency_id' => $agencyId]
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
      $user = Dispatcher::findByApiKey($apiKey);
      
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
      $agencies = Dispatcher::getAgencies(
        $limit, 
        $offset, 
        $status, 
        $search
      );

      // Get total count for pagination
      $totalAgencies = Dispatcher::getTotalAgencies(
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

  public function updateAgency(Request $request) {
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
      $user = Dispatcher::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Get request body
      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON input: ' . json_last_error_msg()]);
      }

      // Get agency_id from request body
      if (empty($input['agency_id'])) {
        http_response_code(400);
        return json_encode(['error' => 'agency_id is required']);
      }

      $agencyId = $input['agency_id'];

      // Validate agency exists
      $existingAgency = Dispatcher::getAgencyById($agencyId);
      if (!$existingAgency) {
        http_response_code(404);
        return json_encode(['error' => 'Agency not found']);
      }

      // Validate status if provided
      if (!empty($input['status']) && !in_array($input['status'], ['active', 'inactive'])) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid status. Must be active or inactive']);
      }

      // Validate number_of_units if provided
      if (isset($input['number_of_units'])) {
        $numberOfUnits = intval($input['number_of_units']);
        if ($numberOfUnits < 0) {
          http_response_code(400);
          return json_encode(['error' => 'Number of units must be a positive integer']);
        }
      }

      // Remove agency_id from data before updating (since it's the identifier)
      $updateData = $input;
      unset($updateData['agency_id']);

      // Update agency
      $success = Dispatcher::updateAgency($agencyId, $updateData);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to update agency']);
      }

      return json_encode([
        'success' => true,
        'message' => 'Agency updated successfully'
      ]);

    } catch (Exception $e) {
      error_log("Error in updateAgency: " . $e->getMessage());
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function deleteAgency(Request $request) {
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
      $user = Dispatcher::findByApiKey($apiKey);
      
      if (!$user) {
        http_response_code(404);
        return json_encode(['error' => 'User not found']);
      }

      // Check if user is active
      if ($user['status'] !== 'active') {
        http_response_code(403);
        return json_encode(['error' => 'Account is not active']);
      }

      // Get agency_id from query string
      $agencyId = $request->getQuery('agency_id');
      if (empty($agencyId)) {
        http_response_code(400);
        return json_encode(['error' => 'agency_id is required']);
      }

      // Validate agency exists
      $existingAgency = Dispatcher::getAgencyById($agencyId);
      if (!$existingAgency) {
        http_response_code(404);
        return json_encode(['error' => 'Agency not found']);
      }

      // Delete agency
      $success = Dispatcher::deleteAgency($agencyId);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to delete agency']);
      }

      return json_encode([
        'success' => true,
        'message' => 'Agency deleted successfully'
      ]);

    } catch (Exception $e) {
      error_log("Error in deleteAgency: " . $e->getMessage());
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  // Dashboard
  public function dashboardStats(Request $request) {
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
      $user = Dispatcher::findByApiKey($apiKey);
      
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

      // Get filter parameters
      $year = $request->getQuery('year', date('Y'));
      $startDate = $request->getQuery('start_date', '');
      $endDate = $request->getQuery('end_date', '');
      $statsScope = $request->getQuery('stats_scope', 'today');
      $activeTodayOnly = ($statsScope !== 'all');

      // Get dashboard statistics
      $stats = Dispatcher::getDashboardStats($year, $startDate, $endDate, $activeTodayOnly);

      // Get monthly incidents count
      $monthlyIncidents = Dispatcher::getMonthlyIncidentsCount($year, $startDate, $endDate);

      // Get recent activity (global feed; dashboard sidebar uses /dispatcher/incidents instead)
      $recentActivity = Dispatcher::getRecentActivity();

      // Get incident map coordinates
      $incidentCoordinates = Dispatcher::getIncidentCoordinates($year, $startDate, $endDate, $activeTodayOnly);

      $payload = [
        'success' => true,
        'data' => [
          'stats' => $stats,
          'monthly_incidents' => $monthlyIncidents,
          'recent_activity' => $recentActivity,
          'incident_coordinates' => $incidentCoordinates
        ],
        'filters' => [
          'year' => $year,
          'start_date' => $startDate,
          'end_date' => $endDate,
          'stats_scope' => $activeTodayOnly ? 'today' : 'all'
        ]
      ];

      $jsonFlags = JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
      $json = json_encode($payload, $jsonFlags);
      if ($json === false) {
        http_response_code(500);
        return json_encode([
          'success' => false,
          'error' => 'Failed to encode dashboard JSON: ' . json_last_error_msg()
        ]);
      }
      return $json;

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }
  
  // Dispatcher Users
public function getDispatcherUsers(Request $request) {
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
    $user = Dispatcher::findByApiKey($apiKey);
    
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

    // Get dispatcher users with pagination and filters
    $dispatcherUsers = Dispatcher::getDispatcherUsers(
      $limit, 
      $offset, 
      $status, 
      $search
    );
    
    $totalDispatcherUsers = Dispatcher::getTotalDispatcherUsers(
      $status, 
      $search
    );

    // Calculate total pages
    $totalPages = ceil($totalDispatcherUsers / $limit);

    return json_encode([
      'success' => true,
      'data' => $dispatcherUsers,
      'pagination' => [
        'current_page' => $page,
        'per_page'     => $limit,
        'total_items'  => $totalDispatcherUsers,
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

public function updateDispatcherUserStatus(Request $request) {
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
    $user = Dispatcher::findByApiKey($apiKey);
    
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
    $userToUpdate = Dispatcher::getUserById($userId);
    
    if (!$userToUpdate) {
      http_response_code(404);
      return json_encode(['error' => 'User not found']);
    }

    // Verify the user has dispatcher role
    if ($userToUpdate['role'] !== 'dispatcher') {
      http_response_code(422);
      return json_encode(['error' => 'User is not a dispatcher user']);
    }

    // Update user status
    $success = Dispatcher::updateUserStatus($userId, $status);

    if (!$success) {
      http_response_code(500);
      return json_encode(['error' => 'Failed to update user status']);
    }

    // Get updated user data
    $updatedUser = Dispatcher::getUserById($userId);

    return json_encode([
      'success' => true,
      'message' => 'Dispatcher user status updated successfully',
      'data' => $updatedUser
    ]);

  } catch (Exception $e) {
    http_response_code(500);
    return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  }
}
}