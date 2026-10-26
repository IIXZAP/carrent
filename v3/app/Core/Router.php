<?php
namespace App\Core;

use App\Services\Auth;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** POST ทุกเส้นตรวจ CSRF อัตโนมัติ */
    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    private function add(string $method, string $pattern, array $handler, array $middleware): void
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method'     => $method,
            'regex'      => '#^' . $regex . '$#',
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $this->run($route, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            return;
        }
        if ($pathMatched) {
            abort(405, 'ไม่รองรับวิธีเรียกนี้');
        }
        abort(404);
    }

    private function run(array $route, array $params): void
    {
        if ($route['method'] === 'POST' && !Csrf::valid($_POST['_csrf'] ?? null)) {
            abort(419, 'หน้าหมดอายุ กรุณากลับไปโหลดหน้าใหม่แล้วลองอีกครั้ง');
        }
        foreach ($route['middleware'] as $name) {
            $this->middleware($name);
        }
        [$class, $action] = $route['handler'];
        (new $class())->$action(...array_values($params));
    }

    private function middleware(string $name): void
    {
        if ($name === 'auth' && !Auth::check()) {
            redirect('/admin/login');
        }
        if ($name === 'admin' && !Auth::isAdmin()) {
            abort(403, 'เฉพาะเจ้าของร้านเท่านั้น');
        }
    }
}
