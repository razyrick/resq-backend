<?php
namespace App\Services;

class EmailService
{
    private $brevo;
    
    public function __construct()
    {
        $this->brevo = new BrevoEmail();
    }
    
    public function sendCustomEmail($to, $subject, $body): array
    {
        return $this->brevo->sendEmail([
            'to' => $to,
            'subject' => $subject,
            'content' => $body
        ]);
    }
    
    public function sendVerificationEmail($userEmail, $userName, $verificationCode): array
    {
        $verificationLink = "https://greenyellow-hawk-206191.hostingersite.com/verify-email?code=" . $verificationCode;
        
        $emailContent = '
        <!DOCTYPE html>
        <html>
        <head>
            <title>RES-Q Laguna - Email Verification</title>
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0;">
            <div style="max-width: 600px; margin: 0 auto; background: #f9fafb;">
                <div style="background: #2563eb; color: white; padding: 20px; text-align: center;">
                    <h1 style="margin: 0;">RES-Q Laguna</h1>
                    <p style="margin: 5px 0 0 0;">Email Verification Required</p>
                </div>
                
                <div style="padding: 30px;">
                    <h2 style="color: #1f2937;">Hello ' . htmlspecialchars($userName) . ',</h2>
                    
                    <p style="color: #4b5563; font-size: 16px;">
                        You need to verify your email address before you can access your RES-Q Laguna account.
                    </p>
                    
                    <p style="color: #4b5563; font-size: 16px;">
                        <strong>Please click the button below to verify your email address:</strong>
                    </p>
                    
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="' . $verificationLink . '" 
                           style="background: #2563eb; color: white; padding: 12px 24px; 
                                  text-decoration: none; border-radius: 6px; display: inline-block; 
                                  font-weight: bold;">
                            Verify Email Address
                        </a>
                    </div>
                    
                    <p style="color: #4b5563; font-size: 14px;">
                        Or copy and paste this link in your browser:<br>
                        <code style="background: #f3f4f6; padding: 5px; border-radius: 4px; font-size: 12px;">
                            ' . $verificationLink . '
                        </code>
                    </p>
                    
                    <p style="color: #4b5563; font-size: 16px;">
                        This verification link will expire in 24 hours.
                    </p>
                    
                    <p style="color: #4b5563; font-size: 16px;">
                        If you did not request this verification, please ignore this email.
                    </p>
                </div>
                
                <div style="text-align: center; padding: 20px; color: #6b7280; font-size: 12px; border-top: 1px solid #e5e7eb;">
                    <p style="margin: 0;">© ' . date('Y') . ' RES-Q Laguna. All rights reserved.</p>
                    <p style="margin: 5px 0 0 0;">This is an automated email, please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        return $this->sendCustomEmail(
            $userEmail,
            'RES-Q Laguna - Email Verification Required',
            $emailContent
        );
    }
}