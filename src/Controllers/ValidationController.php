<?php
namespace App\Controllers;

use App\Core\Request;
use App\Models\User;
use App\Core\RateLimiter;

class ValidationController {
  private function getApiKey(Request $request): ?string {
    $authHeader = $request->getHeader('Authorization');
    if (empty($authHeader)) {
      return null;
    }
    return trim(str_replace('Bearer ', '', $authHeader));
  }

  public function validateUserProfile(Request $request) {
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

    $user = User::findByApiKey($apiKey, $csrfToken);
    if (!$user) {
      http_response_code(401);
      return json_encode(['error' => 'Invalid API key or unauthorized access']);
    }

    // Check required fields
    $requiredFields = [
      'phone_number',
      'emergency_contact', 
      'emergency_contact_number',
      'address',
      'latitude',
      'longitude'
    ];

    $missingFields = [];
    foreach ($requiredFields as $field) {
      if (empty($user[$field]) || trim($user[$field]) === '') {
        $missingFields[] = $field;
      }
    }

    if (!empty($missingFields)) {
      http_response_code(422);
      return json_encode([
        'error' => 'Profile incomplete',
        'message' => 'Please complete your profile before accessing this feature',
        'missing_fields' => $missingFields
      ]);
    }

    return json_encode([
      'success' => true,
      'message' => 'Profile is complete and valid',
      'data' => [
        'user_id' => $user['user_id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name']
      ]
    ]);
  }

  // Middleware method that can be used in other controllers
  public static function checkProfileCompletion($apiKey, $csrfToken) {
    $user = User::findByApiKey($apiKey, $csrfToken);
    
    if (!$user) {
      return ['valid' => false, 'error' => 'User not found'];
    }

    $requiredFields = [
      'phone_number',
      'emergency_contact', 
      'emergency_contact_number',
      'address',
      'latitude',
      'longitude'
    ];

    $missingFields = [];
    foreach ($requiredFields as $field) {
      if (empty($user[$field]) || trim($user[$field]) === '') {
        $missingFields[] = $field;
      }
    }

    if (!empty($missingFields)) {
      return [
        'valid' => false, 
        'error' => 'Profile incomplete',
        'missing_fields' => $missingFields
      ];
    }

    return ['valid' => true, 'user' => $user];
  }
}