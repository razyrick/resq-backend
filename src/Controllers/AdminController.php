<?php
namespace App\Controllers;

use App\Core\Request;
use App\Models\Admin;
use App\Core\RateLimiter;
use App\Core\Auth;
use Exception;

class AdminController {  
    private function getApiKey(Request $request): ?string {
        $authHeader = $request->getHeader('Authorization');
        if (empty($authHeader)) {
          return null;
        }
        return trim(str_replace('Bearer ', '', $authHeader));
    }
  
    public function getPendingUsers(Request $request) {
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
            $user = Admin::findByApiKey($apiKey);
            
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
            
            // Check if user is admin
            if ($user['role'] !== 'admin') {
                http_response_code(403);
                return json_encode(['error' => 'Admin access required']);
            }
            
            // Get pagination parameters from query string
            $page = max(1, intval($request->getQuery('page', 1)));
            $limit = max(1, min(100, intval($request->getQuery('limit', 20))));
            $offset = ($page - 1) * $limit;
            
            // Get filter parameters - with role parameter
            $search = $request->getQuery('search', '');
            $role = $request->getQuery('role', '');
            $status = $request->getQuery('status', 'pending');
            
            // Validate status parameter
            $allowedStatuses = ['pending', 'verify', 'active', 'banned'];
            if (!in_array($status, $allowedStatuses)) {
                http_response_code(422);
                return json_encode([
                    'error' => 'Invalid status. Allowed values: ' . implode(', ', $allowedStatuses)
                ]);
            }
            
            // Validate role parameter
            if ($role) {
                $allowedRoles = ['user', 'barangay', 'dispatcher', 'agency', 'admin'];
                if (!in_array($role, $allowedRoles)) {
                    http_response_code(422);
                    return json_encode([
                        'error' => 'Invalid role. Allowed values: ' . implode(', ', $allowedRoles)
                    ]);
                }
            }
            
            // Get users with pagination and filters
            $pendingUsers = Admin::getPendingUsers(
                $limit, 
                $offset, 
                $search,
                $role,
                $status
            );
            
            $totalPendingUsers = Admin::getTotalPendingUsers(
                $search,
                $role,
                $status
            );
            
            // Calculate total pages
            $totalPages = ceil($totalPendingUsers / $limit);
            
            return json_encode([
                'success' => true,
                'data' => $pendingUsers,
                'pagination' => [
                    'current_page' => $page,
                    'per_page'     => $limit,
                    'total_items'  => $totalPendingUsers,
                    'total_pages'  => $totalPages,
                    'has_next'     => $page < $totalPages,
                    'has_prev'     => $page > 1
                ],
                'filters' => [
                    'search' => $search,
                    'role' => $role,
                    'status' => $status
                ]
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            return json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        }
    }
    
    public function updateUserStatus(Request $request) {
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
            // Get admin user by API key
            $admin = Admin::findByApiKey($apiKey);
            
            if (!$admin) {
                http_response_code(404);
                return json_encode(['error' => 'Admin user not found']);
            }
            
            // Verify CSRF token
            if ($admin['csrf_token'] !== $csrfToken) {
                http_response_code(403);
                return json_encode(['error' => 'Invalid CSRF token']);
            }
            
            // Check if admin is active
            if ($admin['status'] !== 'active') {
                http_response_code(403);
                return json_encode(['error' => 'Admin account is not active']);
            }
            
            // Get request body
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                return json_encode(['error' => 'Request body is required']);
            }
            
            if (!isset($input['user_id'])) {
                http_response_code(400);
                return json_encode(['error' => 'user_id is required']);
            }
            
            if (!isset($input['status'])) {
                http_response_code(400);
                return json_encode(['error' => 'status is required']);
            }
            
            $userId = $input['user_id'];
            $status = $input['status'];
            
            // Validate status - only 'active' or 'banned'
            if (!in_array($status, ['active', 'banned'])) {
                http_response_code(422);
                return json_encode(['error' => 'Invalid status. Use "active" for approve or "banned" for decline']);
            }
            
            // Update user status
            $success = Admin::updateUserStatus($userId, $status);
            
            if ($success) {
                $statusText = $status === 'active' ? 'approved' : 'declined';
                return json_encode([
                    'success' => true,
                    'message' => 'User has been ' . $statusText . ' successfully'
                ]);
            } else {
                http_response_code(500);
                return json_encode(['error' => 'Failed to update user status']);
            }
            
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
      $user = Admin::findByApiKey($apiKey);
      
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

      // Get incidents 
      $incidents = Admin::getIncidents(
        $limit, 
        $offset, 
        $status, 
        $severity, 
        $type, 
        $search
      );

      // Get total count for pagination
      $totalIncidents = Admin::getTotalIncidents(
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
}