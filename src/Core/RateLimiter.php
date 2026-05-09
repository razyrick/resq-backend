<?php
namespace App\Core;

use App\Core\Database;
use PDO;

class RateLimiter {
  // max requests per minute
  private static int $limit = 50;

  public static function check(string $apiKey): bool {
    try {
      $db = Database::connect();

      $stmt = $db->prepare("SELECT * FROM rate_limits WHERE api_key = :api_key LIMIT 1");
      $stmt->execute([':api_key' => $apiKey]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      $currentTime = time();
      $windowStart = $currentTime - 60;

      if ($row) {
        if ($row['last_request'] < $windowStart) {
          $stmt = $db->prepare("UPDATE rate_limits 
          SET requests = 1, last_request = :now 
          WHERE api_key = :api_key");
          $stmt->execute([
            ':now' => $currentTime,
            ':api_key' => $apiKey
          ]);
          return true;
        }

        if ($row['requests'] >= self::$limit) {
          return false;
        }

        $stmt = $db->prepare("UPDATE rate_limits 
        SET requests = requests + 1, last_request = :now 
        WHERE api_key = :api_key");
        $stmt->execute([
          ':now' => $currentTime,
          ':api_key' => $apiKey
        ]);
        return true;
      }

      $stmt = $db->prepare("INSERT INTO rate_limits (api_key, requests, last_request) 
        VALUES (:api_key, 1, :now)");
      $stmt->execute([
        ':api_key' => $apiKey,
        ':now' => $currentTime
      ]);
      return true;
    } catch (\Throwable $e) {
      error_log('RateLimiter::check failed (allowing request): ' . $e->getMessage());
      return true;
    }
  }
}
