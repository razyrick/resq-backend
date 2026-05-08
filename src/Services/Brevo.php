<?php
namespace App\Services;

use Exception;

class BrevoEmail
{
    private $apiKey;
    private $apiUrl = 'https://api.brevo.com/v3';
    
    public function __construct()
    {
        $this->apiKey = $_ENV['BREVO_API_KEY'] ?? '';

        if (empty($this->apiKey)) {
            throw new Exception('Brevo API key is not configured');
        }
    }
    
    public function sendEmail(array $data): array
    {
        try {
            $url = $this->apiUrl . '/smtp/email';
            
            $payload = [
                'sender' => [
                    'name' => $_ENV['BREVO_SENDER_NAME'] ?? 'RES-Q Laguna',
                    'email' => $_ENV['BREVO_SENDER_EMAIL'] ?? ''
                ],
                'to' => $this->formatRecipients($data['to']),
                'subject' => $data['subject'],
                'htmlContent' => $data['content'],
                'textContent' => isset($data['textContent']) ? $data['textContent'] : strip_tags($data['content'])
            ];
            
            $response = $this->makeRequest('POST', $url, $payload);
            
            if (isset($response['messageId'])) {
                return [
                    'success' => true,
                    'message_id' => $response['messageId']
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Invalid response from Brevo API'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function formatRecipients($recipients): array
    {
        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }
        
        $formatted = [];
        foreach ($recipients as $recipient) {
            if (is_array($recipient)) {
                $formatted[] = [
                    'email' => $recipient['email'] ?? $recipient,
                    'name' => $recipient['name'] ?? ''
                ];
            } else {
                $formatted[] = [
                    'email' => $recipient,
                    'name' => ''
                ];
            }
        }
        
        return $formatted;
    }
    
    private function makeRequest(string $method, string $url, array $data = []): array
    {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api-key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('CURL Error: ' . $error);
        }
        
        curl_close($ch);
        
        $decodedResponse = json_decode($response, true);
        
        if ($decodedResponse === null) {
            return [];
        }
        
        if ($httpCode >= 400) {
            $errorMessage = $decodedResponse['message'] ?? 'HTTP Error ' . $httpCode;
            throw new Exception($errorMessage);
        }
        
        return $decodedResponse;
    }
}