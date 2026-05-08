<?php
namespace App\Core;

class Request {
  public function method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD']);
  }

  public function path(): string {
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    return strtok($path, '?') ?: '/';
  }

  public function body(): array {
    $method = $this->method();

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
      $input = json_decode(file_get_contents('php://input'), true);
      return $input ?? [];
    }

    return $_GET;
  }

  public function getHeader(string $name): ?string {
    $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$normalized])) {
      return $_SERVER[$normalized];
    }

    if (strtolower($name) === 'authorization') {
      if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
      }
      if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
      }
    }

    if (function_exists('getallheaders')) {
      $headers = getallheaders();
      foreach ($headers as $key => $value) {
        if (strcasecmp($key, $name) === 0) {
          return $value;
        }
      }
    }

    return null;
  }

  public function getQuery(string $key, $default = null) {
    return $_GET[$key] ?? $default;
  }
}
