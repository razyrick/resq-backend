<?php
namespace App\Controllers;

use App\Core\Request;
use App\Models\Auth;
use Exception;
use Google\Client;

class AuthController {
  public function login(Request $request) {
    $data = $request->body();
    
    // Validate required fields
    if (empty($data['email']) || empty($data['password'])) {
      http_response_code(400);
      return json_encode(['success' => false, 'error' => 'Email and password are required']);
    }

    $email = trim($data['email']);
    $password = $data['password'];

    try {
      // Find user by email
      $user = Auth::findByEmail($email);
      
      if (!$user) {
        http_response_code(401);
        return json_encode(['success' => false, 'error' => 'Invalid email or password']);
      }

      // Check account status
      if (strtolower($user['status']) === 'banned') {
        http_response_code(403);
        return json_encode([
          'success' => false,
          'error' => 'Your account has been banned. Please contact support.'
        ]);
      }

      if (strtolower($user['status']) === 'pending') {
        http_response_code(403);
        return json_encode([
          'success' => false,
          'error' => 'Your account is pending approval. Please wait for admin verification.'
        ]);
      }
      
      // Check if account needs email verification
      if (strtolower($user['status']) === 'verify') {
        // Generate verification code
        $verificationCode = Auth::generateVerificationCode($user['user_id']);
        
        // Send verification email
        $this->sendVerificationLink($user['email'], $user['first_name'], $verificationCode, $user['user_id']);
        
        http_response_code(200);
        return json_encode([
            'success' => false,
            'error' => 'Please verify your email address. A new verification link has been sent.',
            'requires_verification' => true
        ]);
      }

      // Verify password - ONLY for active/verified accounts
      if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        return json_encode(['success' => false, 'error' => 'Invalid email or password']);
      }

      // Generate new CSRF token
      $csrf_token = bin2hex(random_bytes(16));
      Auth::updateCsrfToken($user['user_id'], $csrf_token);
      $user['csrf_token'] = $csrf_token;

      // Update last login
      Auth::updateLastLogin($user['user_id']);

      // Remove sensitive data
      unset($user['id']);
      unset($user['google_id']);
      unset($user['created_at']);
      unset($user['updated_at']);
      unset($user['password']);

      return json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => $user
      ]);

    } catch (Exception $e) {
      error_log("Login error: " . $e->getMessage());
      http_response_code(500);
      return json_encode([
        'success' => false,
        'error' => 'Login failed. Please try again.'
      ]);
    }
  }

  public function verifyEmail(Request $request) {
    $userId = $_GET['user_id'] ?? null;
    $verificationCode = $_GET['verification_code'] ?? null;
    
    // Validate required fields
    if (empty($userId) || empty($verificationCode)) {
        http_response_code(400);
        return json_encode(['success' => false, 'error' => 'User ID and verification code are required']);
    }
    
    try {
        // Find user by user_id
        $user = Auth::findById($userId);
        
        if (!$user) {
            return json_encode(['success' => false, 'error' => 'User not found']);
        }
        
        // Check if user is already verified
        $currentStatus = strtolower($user['status'] ?? '');
        if ($currentStatus === 'verify') {
            $storedCode = $user['verification_code'] ?? null;
            
            // Verify the code matches
            if ($storedCode !== $verificationCode) {
                return json_encode([
                    'success' => false, 
                    'error' => 'Invalid verification code.'
                ]);
            }
            
            $newStatus = 'pending';
            
            // Update user status in database
            $updated = Auth::updateUserStatus($userId, $newStatus);
            
            if (!$updated) {
                return json_encode(['success' => false, 'error' => 'Failed to update user status']);
            }
            
            // Clear the verification code after successful verification
            Auth::clearVerificationCode($userId);
    
            // Redirect on success
            header("Location: https://resq-laguna.netlify.app/");
            exit;
        } else {
            return json_encode(['success' => false, 'error' => "Email isn't allowed to verify"]);
        }
    } catch (Exception $e) {
        return json_encode([
            'success' => false,
            'error' => 'Verification failed. Please try again.'
        ]);
    }
  }

  private function sendVerificationLink(string $toEmail, string $name, string $verificationCode, string $userId): void {
    $apiKey = $_ENV['BREVO_API_KEY'] ?? '';
    $senderName = $_ENV['BREVO_SENDER_NAME'] ?? 'RES-Q LAGUNA';
    $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? '';
    if ($apiKey === '' || $senderEmail === '') {
        error_log('Brevo is not configured (BREVO_API_KEY / BREVO_SENDER_EMAIL)');
        return;
    }
    $url = "https://api.brevo.com/v3/smtp/email";

    $verificationUrl = "https://greenyellow-hawk-206191.hostingersite.com/auth/verify?verification_code={$verificationCode}&user_id={$userId}";
    
    $subject = "RES-Q Laguna Verification Link";
    $content = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .button { 
                    background-color: #4CAF50; 
                    color: white; 
                    padding: 12px 24px; 
                    text-decoration: none; 
                    border-radius: 4px; 
                    display: inline-block;
                }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>Email Verification</h2>
                <p>Hi {$name},</p>
                <p>Please verify your email address by clicking the button below:</p>
                <p>
                    <a href='{$verificationUrl}' class='button'>Verify Email</a>
                </p>
                <p>Or copy and paste this link in your browser:</p>
                <p><small>{$verificationUrl}</small></p>
                <p>If you did not create an account, please ignore this email.</p>
                <br>
                <p>Best regards,<br>RES-Q LAGUNA Team</p>
            </div>
        </body>
        </html>
    ";

    $data = [
        "sender" => ["name" => $senderName, "email" => $senderEmail],
        "to" => [["email" => $toEmail, "name" => $name]],
        "subject" => $subject,
        "htmlContent" => $content
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "accept: application/json",
            "api-key: {$apiKey}",
            "content-type: application/json"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Email sending error: " . $error);
    }
  }

  public function register(Request $request) {
    $data = $request->body();
    
    // Validate required fields
    $required_fields = ['first_name', 'middle_name', 'last_name', 'email', 'password', 'role'];
    foreach ($required_fields as $field) {
      if (empty($data[$field])) {
        http_response_code(400);
        return json_encode(['success' => false, 'error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
      }
    }

    $first_name = trim($data['first_name']);
    $middle_name = trim($data['middle_name']);
    $last_name = trim($data['last_name']);
    $middle_name = trim($data['middle_name'] ?? '');
    $email = trim($data['email']);
    $password = $data['password'];
    $role = $data['role'];

    try {
      // Validate email format
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        return json_encode(['success' => false, 'error' => 'Invalid email format']);
      }

      // Validate password strength
      if (strlen($password) < 8) {
        http_response_code(400);
        return json_encode(['success' => false, 'error' => 'Password must be at least 8 characters long']);
      }

      // Check if email already exists
      if (Auth::findByEmail($email)) {
        http_response_code(409);
        return json_encode(['success' => false, 'error' => 'Email already registered']);
      }

      // Validate role
      $allowed_roles = ['user', 'barangay', 'dispatcher', 'agency'];
      if (!in_array($role, $allowed_roles)) {
        http_response_code(400);
        return json_encode(['success' => false, 'error' => 'Invalid role specified']);
      }

      // Set status based on role (admin accounts need approval)
      if ($role === 'user') {
        $status = 'active';
      } else {
        $status = 'verify';
      }

      // Prepare user data - matching database field names
      $user_data = [
        'user_id'       => bin2hex(random_bytes(16)),
        'api_key'       => bin2hex(random_bytes(16)),
        'csrf_token'    => bin2hex(random_bytes(16)),
        'role'          => $role,
        'status'        => $status,
        'first_name'    => $first_name,
        'middle_name'    => $middle_name,
        'middle_name'   => $middle_name,
        'last_name'     => $last_name,
        'email'         => $email,
        'password'      => password_hash($password, PASSWORD_DEFAULT),
        'created_at'    => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s')
      ];

      // Add role-specific fields
      if ($role === 'barangay' && !empty($data['barangay_name'])) {
        $user_data['barangay_name'] = $data['barangay_name'];
      }

      if ($role === 'agency' && !empty($data['agency_name'])) {
        $user_data['agency_name'] = $data['agency_name'];
      }

      if ($role === 'dispatcher' && !empty($data['dispatcher_id'])) {
        $user_data['dispatcher_id'] = $data['dispatcher_id'];
      }

      // Create user
      if (!Auth::createUser($user_data)) {
        http_response_code(500);
        return json_encode(['success' => false, 'error' => 'Failed to create user account']);
      }

      // Remove sensitive data before returning
      unset($user_data['password']);

      return json_encode([
        'success' => true,
        'message' => $status === 'pending' ? 
          'Registration successful! Your account is pending approval.' : 
          'Registration successful! You can now login.',
        'user' => $user_data
      ]);

    } catch (Exception $e) {
      error_log("Registration error: " . $e->getMessage());
      http_response_code(500);
      return json_encode([
        'success' => false,
        'error' => 'Registration failed. Please try again.'
      ]);
    }
  }
  
  private function sendPasswordResetEmail(string $toEmail, string $name, string $resetCode): void {
    $apiKey = $_ENV['BREVO_API_KEY'] ?? '';
    $senderName = $_ENV['BREVO_SENDER_NAME'] ?? 'RES-Q LAGUNA';
    $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? '';
    if ($apiKey === '' || $senderEmail === '') {
        error_log('Brevo is not configured (BREVO_API_KEY / BREVO_SENDER_EMAIL)');
        return;
    }
    $url = "https://api.brevo.com/v3/smtp/email";

    $resetUrl = "https://resq-laguna.netlify.app/reset-password.html?verification_code={$resetCode}&email=" . urlencode($toEmail);
    
    $subject = "RES-Q Laguna Password Reset Request";
    $content = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .button { 
                    background-color: #4CAF50; 
                    color: white; 
                    padding: 12px 24px; 
                    text-decoration: none; 
                    border-radius: 4px; 
                    display: inline-block;
                }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .warning { color: #f44336; font-size: 14px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>Password Reset Request</h2>
                <p>Hi {$name},</p>
                <p>We received a request to reset your password. Click the button below to create a new password:</p>
                <p>
                    <a href='{$resetUrl}' class='button'>Reset Password</a>
                </p>
                <p>Or copy and paste this link in your browser:</p>
                <p><small>{$resetUrl}</small></p>
                <p class='warning'>This link will expire in 1 hour for security reasons.</p>
                <p>If you did not request a password reset, please ignore this email or contact support if you have concerns.</p>
                <br>
                <p>Best regards,<br>RES-Q LAGUNA Team</p>
            </div>
        </body>
        </html>
    ";

    $data = [
        "sender" => ["name" => $senderName, "email" => $senderEmail],
        "to" => [["email" => $toEmail, "name" => $name]],
        "subject" => $subject,
        "htmlContent" => $content
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "accept: application/json",
            "api-key: {$apiKey}",
            "content-type: application/json"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Password reset email error: " . $error);
    }
}
  
  public function forgotPassword(Request $request) {
    $data = $request->body();
    
    // Validate email
    if (empty($data['email'])) {
        http_response_code(400);
        return json_encode(['success' => false, 'error' => 'Email is required']);
    }

    $email = trim($data['email']);

    try {
        // Find user by email
        $user = Auth::findByEmail($email);
        
        // Always return success even if email doesn't exist (security best practice)
        if (!$user) {
            return json_encode([
                'success' => true,
                'message' => 'If your email exists in our system, you will receive a password reset link.'
            ]);
        }

        // Check account status
        if (strtolower($user['status']) === 'banned') {
            return json_encode([
                'success' => false,
                'error' => 'Your account has been banned. Please contact support.'
            ]);
        }

        // Generate password reset code
        $resetCode = bin2hex(random_bytes(16));
        $expiryTime = date('Y-m-d H:i:s', strtotime('+1 hour')); // Code expires in 1 hour
        
        // Store reset code in database
        Auth::storePasswordResetCode($user['user_id'], $resetCode, $expiryTime);

        // Send password reset email
        $this->sendPasswordResetEmail($user['email'], $user['first_name'], $resetCode);

        return json_encode([
            'success' => true,
            'message' => 'Password reset link has been sent to your email.'
        ]);

    } catch (Exception $e) {
        error_log("Forgot password error: " . $e->getMessage());
        http_response_code(500);
        return json_encode([
            'success' => false,
            'error' => 'Failed to process request. Please try again.'
        ]);
    }
}

  public function resetPassword(Request $request) {
    $data = $request->body();
    
    // Validate required fields
    if (empty($data['email']) || empty($data['verification_code']) || empty($data['new_password'])) {
        http_response_code(400);
        return json_encode(['success' => false, 'error' => 'Email, verification code, and new password are required']);
    }

    $email = trim($data['email']);
    $resetCode = $data['verification_code'];
    $newPassword = $data['new_password'];

    try {
        // Validate password strength
        if (strlen($newPassword) < 8) {
            http_response_code(400);
            return json_encode(['success' => false, 'error' => 'Password must be at least 8 characters long']);
        }

        // Find user by email
        $user = Auth::findByEmail($email);
        
        if (!$user) {
            return json_encode(['success' => false, 'error' => 'Invalid reset link']);
        }

        // Verify reset code
        $resetData = Auth::verifyPasswordResetCode($user['user_id'], $resetCode);
        
        if (!$resetData) {
            return json_encode(['success' => false, 'error' => 'Invalid or expired reset link']);
        }

        // Check if code is expired
        if (strtotime($resetData['expires_at']) < time()) {
            return json_encode(['success' => false, 'error' => 'Reset link has expired']);
        }

        // Update password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $updated = Auth::updatePassword($user['user_id'], $hashedPassword);

        if (!$updated) {
            return json_encode(['success' => false, 'error' => 'Failed to reset password']);
        }

        // Clear the reset code after successful password reset
        Auth::clearPasswordResetCode($user['user_id']);

        // Invalidate all existing sessions by generating new CSRF token
        Auth::updateCsrfToken($user['user_id'], bin2hex(random_bytes(16)));

        return json_encode([
            'success' => true,
            'message' => 'Password has been reset successfully. You can now login with your new password.'
        ]);

    } catch (Exception $e) {
        error_log("Reset password error: " . $e->getMessage());
        http_response_code(500);
        return json_encode([
            'success' => false,
            'error' => 'Failed to reset password. Please try again.'
        ]);
    }
}

  public function logout(Request $request) {
    try {
      $data = $request->body();
      $user_id = $data['user_id'] ?? null;
      
      if ($user_id) {
        // Clear CSRF token for security
        Auth::updateCsrfToken($user_id, '');
      }

      return json_encode([
        'success' => true,
        'message' => 'Logout successful'
      ]);

    } catch (Exception $e) {
      error_log("Logout error: " . $e->getMessage());
      http_response_code(500);
      return json_encode([
        'success' => false,
        'error' => 'Logout failed'
      ]);
    }
  }
}