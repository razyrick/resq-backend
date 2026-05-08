<?php
namespace App\Core;

class Router {
  private array $routes = [];
  private Request $request;
  private string $basePath;

  public function __construct(Request $request) {
    $this->request = $request;
    $this->basePath = $_ENV['BASEPATH'] ?? '';
  }

  public function get(string $path, array $callback) {
    $this->routes['GET'][$this->basePath . $path] = $callback;
  }

  public function post(string $path, array $callback) {
    $this->routes['POST'][$this->basePath . $path] = $callback;
  }

  public function put(string $path, array $callback) {
    $this->routes['PUT'][$this->basePath . $path] = $callback;
  }

  public function delete(string $path, array $callback) {
    $this->routes['DELETE'][$this->basePath . $path] = $callback;
  }

  public function resolve() {
    $method = $this->request->method();
    $path = $this->request->path();

    $callback = $this->routes[$method][$path] ?? false;

    if (!$callback) {
      http_response_code(404);
      echo json_encode(['error' => 'Route not found']);
      return;
    }

    [$class, $method] = $callback;
    $controller = new $class();
    echo call_user_func([$controller, $method], $this->request);
  }
}
