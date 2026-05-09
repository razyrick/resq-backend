<?php
namespace App\Controllers;

use App\Core\Request;
use App\Models\User;
use App\Core\RateLimiter;
use App\Core\Auth;
use App\Services\NotificationService;
use Exception;

class UserController {
  private $notificationService;

  public function __construct() {
    $this->notificationService = new NotificationService();
  }

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
      $userModel = new User();
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
        'first_name' => $user['first_name'] ?? '',
        'middle_name' => $user['middle_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'email' => $user['email'] ?? '', 
        'phone' => $user['phone'] ?? '', 
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
      $user = User::findByApiKey($apiKey);
      
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
      $success = User::updateProfile($user['id'], $updateData);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to update profile']);
      }

      // Get updated user data
      $updatedUser = User::findByApiKey($apiKey);
      
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
        'first_name' => $updatedUser['first_name'] ?? '',
        'middle_name' => $updatedUser['middle_name'] ?? '',
        'last_name' => $updatedUser['last_name'] ?? '',
        'phone' => $updatedUser['phone'] ?? '',
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

  // Baranggay
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
      $user = User::findByApiKey($apiKey);
      
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
      $baranggays = User::getBaranggays($limit, $offset, $search);
      $totalBaranggays = User::getTotalBaranggays($search);

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
  public function createIncident(Request $request) {
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
      $user = User::findByApiKey($apiKey);
      
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

      // Required fields validation
      $requiredFields = ['latitude', 'longitude', 'incident_type', 'severity_level', 'description', 'photo'];
      $missingFields = [];
      
      foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
          $missingFields[] = $field;
        }
      }

      if (!empty($missingFields)) {
        http_response_code(422);
        return json_encode([
          'error' => 'Missing required fields'
        ]);
      }

      // Validate data types and formats
      if (!is_numeric($input['latitude']) || !is_numeric($input['longitude'])) {
        http_response_code(422);
        return json_encode(['error' => 'Latitude and longitude must be numeric values']);
      }

      if ($input['latitude'] < -90 || $input['latitude'] > 90) {
        http_response_code(422);
        return json_encode(['error' => 'Latitude must be between -90 and 90']);
      }

      if ($input['longitude'] < -180 || $input['longitude'] > 180) {
        http_response_code(422);
        return json_encode(['error' => 'Longitude must be between -180 and 180']);
      }

      // Get all barangays
      $barangays = User::getAllBaranggays();
      
      if (empty($barangays)) {
        http_response_code(500);
        return json_encode(['error' => 'No barangays found in database']);
      }

      // Find nearest barangay based on coordinates
      $nearestBarangay = $this->findNearestBarangay(
        floatval($input['latitude']), 
        floatval($input['longitude']), 
        $barangays
      );

      if (!$nearestBarangay) {
        http_response_code(500);
        return json_encode(['error' => 'Could not determine nearest barangay']);
      }

      // Prepare incident data
      $incidentData = [
        'incident_id' => bin2hex(random_bytes(8)),
        'user_id' => $user['user_id'],
        'baranggay_id' => $nearestBarangay['baranggay_id'],
        'latitude' => floatval($input['latitude']),
        'longitude' => floatval($input['longitude']),
        'incident_type' => trim($input['incident_type']),
        'severity_level' => trim($input['severity_level']),
        'description' => trim($input['description']),
        'photo' => trim($input['photo']),
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
      ];

      // Create incident
      $success = User::createIncident($incidentData);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to create incident']);
      }
      
      // Send SMS to the user who reported the incident
      if (!empty($user['phone'])) {
        // Format phone number properly for SMS API
        $phoneNumber = $this->formatPhoneNumber($user['phone']);
        
        if ($phoneNumber) {
          // Get user's name
          $userFirstName = $user['first_name'] ?? '';
          $userLastName = $user['last_name'] ?? '';
          $userName = trim($userFirstName . ' ' . $userLastName) ?: 'User';
          
          // Get barangay name
          $barangayName = $nearestBarangay['baranggay'] ?? 'Barangay';
          
          // Create SMS message
          $smsMessage = "RES-Q Laguna: Hi {$userName}, your {$incidentData['incident_type']} report has been received (ID:{$incidentData['incident_id']}). It has been assigned to {$barangayName} for response.";
          
          $smsResult = $this->sendSMS($smsMessage, [$phoneNumber]);
          
          // Log SMS result
          if (!$smsResult['success']) {
            error_log("SMS sending failed for incident {$incidentData['incident_id']}: " . json_encode($smsResult['error']));
          }
        } else {
          error_log("Invalid phone number format for user {$user['user_id']}: " . $user['phone']);
        }
      } else {
        error_log("No phone number found for user {$user['user_id']}");
      }

      // Create notification for the incident
      $this->notificationService->createIncidentNotification(
        $user['user_id'],
        $incidentData['incident_id'],
        $incidentData['incident_type'],
        $incidentData['severity_level'],
        $nearestBarangay['baranggay'] ?? null
      );

      return json_encode([
        'success' => true,
        'message' => 'Incident created successfully',
        'data' => [
          'incident_id' => $incidentData['incident_id'],
          'latitude' => $incidentData['latitude'],
          'longitude' => $incidentData['longitude'],
          'incident_type' => $incidentData['incident_type'],
          'severity_level' => $incidentData['severity_level'],
          'description' => $incidentData['description'],
          'baranggay_id' => $incidentData['baranggay_id'],
          'baranggay_name' => $nearestBarangay['baranggay'] ?? 'Unknown',
          'distance_km' => round($nearestBarangay['distance'] ?? 0, 2),
          'created_at' => $incidentData['created_at']
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

  private function findNearestBarangay(float $latitude, float $longitude, array $barangays): ?array {
    $nearestBarangay = null;
    $shortestDistance = PHP_FLOAT_MAX;

    foreach ($barangays as $barangay) {
      if (!isset($barangay['latitude']) || !isset($barangay['longitude'])) {
        continue;
      }

      $distance = $this->calculateDistance(
        $latitude,
        $longitude,
        floatval($barangay['latitude']),
        floatval($barangay['longitude'])
      );

      if ($distance < $shortestDistance) {
        $shortestDistance = $distance;
        $nearestBarangay = $barangay;
        $nearestBarangay['distance'] = $distance;
      }
    }

    return $nearestBarangay;
  }

  private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371; // Earth radius in kilometers

    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);

    $a = sin($latDelta / 2) * sin($latDelta / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($lonDelta / 2) * sin($lonDelta / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
  }

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
      $user = User::findByApiKey($apiKey);
      
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
      $limit = max(1, min(50, intval($request->getQuery('limit', 10))));
      $offset = ($page - 1) * $limit;

      // Get incidents for the current user
      $incidents = User::getIncidentsByUser($user['user_id'], $limit, $offset);
      $totalIncidents = User::getTotalIncidentsByUser($user['user_id']);

      // Format the incidents data to include barangay information
      $formattedIncidents = array_map(function($incident) {
        return [
          'incident_id' => $incident['incident_id'],
          'latitude' => $incident['latitude'],
          'longitude' => $incident['longitude'],
          'incident_type' => $incident['incident_type'],
          'severity_level' => $incident['severity_level'],
          'description' => $incident['description'],
          'photo' => $incident['photo'],
          'status' => $incident['status'],
          'created_at' => $incident['created_at'],
          'updated_at' => $incident['updated_at'],
          'resolution_photo' => $incident['resolution_photo'] ?? null,
          'resolution_notes' => $incident['resolution_notes'] ?? null,
          'resolved_at' => $incident['resolved_at'] ?? null,
          'resolved_by' => $incident['resolved_by'] ?? null,
          'resolved_by_role' => $incident['resolved_by_role'] ?? null,
          'baranggay' => [
            'baranggay_id' => $incident['baranggay_id'],
            'baranggay_name' => $incident['baranggay_name'],
            'latitude' => $incident['baranggay_latitude'],
            'longitude' => $incident['baranggay_longitude'],
            'created_at' => $incident['baranggay_created_at'],
            'updated_at' => $incident['baranggay_updated_at'],
          ],
          'agency' => [
            'agency_id' => $incident['agency_id'] ?? null,
            'agency_name' => $incident['agency'] ?? null,
            'agency_type' => $incident['agency_type'] ?? null,
            'contact_person' => $incident['contact_person'] ?? null,
            'phone_number' => $incident['phone_number'] ?? null,
            'emal_address' => $incident['email_address'] ?? null,
            'address' => $incident['address'] ?? null,
          ]
        ];
      }, $incidents);

      // Calculate total pages
      $totalPages = ceil($totalIncidents / $limit);

      return json_encode([
        'success' => true,
        'data' => $formattedIncidents,
        'pagination' => [
          'current_page' => $page,
          'per_page'     => $limit,
          'total_items'  => $totalIncidents,
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

  public function getIncidentsByBaranggay(Request $request) {
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
      $user = User::findByApiKey($apiKey);
      
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
      $limit = max(1, min(50, intval($request->getQuery('limit', 10))));
      $offset = ($page - 1) * $limit;

      // Get incidents for the current user
      $incidents = User::getIncidentsByBarangay($user['baranggay_id'], $limit, $offset);
      $totalIncidents = User::getTotalIncidentsByBarangay($user['baranggay_id']);

      // Format the incidents data to include barangay information
      $formattedIncidents = array_map(function($incident) {
        return [
          'incident_id' => $incident['incident_id'],
          'latitude' => $incident['latitude'],
          'longitude' => $incident['longitude'],
          'incident_type' => $incident['incident_type'],
          'severity_level' => $incident['severity_level'],
          'description' => $incident['description'],
          'photo' => $incident['photo'],
          'status' => $incident['status'],
          'created_at' => $incident['created_at'],
          'updated_at' => $incident['updated_at'],
          'resolution_photo' => $incident['resolution_photo'] ?? null,
          'resolution_notes' => $incident['resolution_notes'] ?? null,
          'resolved_at' => $incident['resolved_at'] ?? null,
          'resolved_by' => $incident['resolved_by'] ?? null,
          'resolved_by_role' => $incident['resolved_by_role'] ?? null,
        ];
      }, $incidents);

      // Calculate total pages
      $totalPages = ceil($totalIncidents / $limit);

      return json_encode([
        'success' => true,
        'data' => $formattedIncidents,
        'pagination' => [
          'current_page' => $page,
          'per_page'     => $limit,
          'total_items'  => $totalIncidents,
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
      $user = User::findByApiKey($apiKey);
      
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

      // Get dashboard statistics for user's barangay
      $stats = User::getDashboardStats($user['user_id']);

      return json_encode([
        'success' => true,
        'data' => [
          'total_incidents' => intval($stats['total_incidents'] ?? 0),
          'reports_submitted' => intval($stats['reports_submitted'] ?? 0),
          'resolved' => intval($stats['resolved'] ?? 0),
          'pending' => intval($stats['pending'] ?? 0)
        ]
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  // Notifications
  public function getNotifications(Request $request) {
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
      $user = User::findByApiKey($apiKey);
      
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

      $page = max(1, intval($request->getQuery('page', 1)));
      $limit = max(1, min(50, intval($request->getQuery('limit', 10))));
      $offset = ($page - 1) * $limit;

      $unreadOnly = filter_var($request->getQuery('unread_only', false), FILTER_VALIDATE_BOOLEAN);

      $notifications = User::getUserNotifications(
        $user['user_id'], 
        $limit, 
        $offset, 
        $unreadOnly
      );

      // FIX: Use the correct count based on the same filter
      if ($unreadOnly) {
        $totalNotifications = User::getUnreadNotificationsCount($user['user_id']);
      } else {
        $totalNotifications = User::getTotalNotificationsCount($user['user_id']);
      }

      $totalPages = ceil($totalNotifications / $limit);

      return json_encode([
        'success' => true,
        'data' => $notifications,
        'pagination' => [
          'current_page' => $page,
          'per_page'     => $limit,
          'total_items'  => $totalNotifications,
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

  public function markNotificationAsRead(Request $request) {
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
      $user = User::findByApiKey($apiKey);
      
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

      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON data']);
      }

      if (empty($input['notification_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'Notification ID is required']);
      }

      $success = $this->notificationService->markAsRead($input['notification_id'], $user['user_id']);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to mark notification as read']);
      }

      return json_encode([
        'success' => true,
        'message' => 'Notification marked as read'
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function markAllNotificationsAsRead(Request $request) {
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
      $user = User::findByApiKey($apiKey);
      
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

      $success = $this->notificationService->markAllAsRead($user['user_id']);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to mark all notifications as read']);
      }

      return json_encode([
        'success' => true,
        'message' => 'All notifications marked as read'
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function getUnreadNotificationsCount(Request $request) {
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
      $user = User::findByApiKey($apiKey);
      
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

      $count = $this->notificationService->getUnreadCount($user['user_id']);

      return json_encode([
        'success' => true,
        'data' => [
          'unread_count' => $count
        ]
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }

  public function deleteNotification(Request $request) {
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
      $user = User::findByApiKey($apiKey);
      
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

      $input = json_decode(file_get_contents('php://input'), true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid JSON data']);
      }

      if (empty($input['notification_id'])) {
        http_response_code(422);
        return json_encode(['error' => 'Notification ID is required']);
      }

      $success = $this->notificationService->deleteNotification($input['notification_id'], $user['user_id']);

      if (!$success) {
        http_response_code(500);
        return json_encode(['error' => 'Failed to delete notification']);
      }

      return json_encode([
        'success' => true,
        'message' => 'Notification deleted successfully'
      ]);

    } catch (Exception $e) {
      http_response_code(500);
      return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
  }
}