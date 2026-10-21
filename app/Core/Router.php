<?php

namespace App\Core;

/**
 * ตัวกำหนดเส้นทาง — จับคู่ method + path กับ controller แล้วรัน middleware ตามลำดับ
 */
final class Router
{
    /** @var Route[] */
    private array $routes = [];

    /** @var array<int,array{prefix:string,middleware:string[]}> */
    private array $groups = [];

    public function get(string $pattern, $handler): Route
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    public function post(string $pattern, $handler): Route
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    /** ตอบได้ทั้ง GET และ POST */
    public function any(string $pattern, $handler): Route
    {
        return $this->add(['GET', 'HEAD', 'POST'], $pattern, $handler);
    }

    /**
     * จัดกลุ่มเส้นทางที่ใช้ prefix / middleware ร่วมกัน
     * @param array{prefix?:string,middleware?:string[]} $attrs
     */
    public function group(array $attrs, callable $routes): void
    {
        $this->groups[] = [
            'prefix'     => $attrs['prefix'] ?? '',
            'middleware' => $attrs['middleware'] ?? [],
        ];
        $routes($this);
        array_pop($this->groups);
    }

    /** เส้นทางเก่า (.php) → ส่งต่อ (301) ไปเส้นทางใหม่ พร้อม query string เดิม */
    public function legacy(string $oldPath, string $newPath): void
    {
        $this->get($oldPath, static function (Request $r) use ($newPath): Response {
            $qs = $r->queryString();
            return Response::redirect($newPath . ($qs !== '' ? (str_contains($newPath, '?') ? '&' : '?') . $qs : ''), 301);
        });
    }

    private function add(array $methods, string $pattern, $handler): Route
    {
        $prefix = '';
        $mw     = [];
        foreach ($this->groups as $g) {
            $prefix .= $g['prefix'];
            $mw      = array_merge($mw, $g['middleware']);
        }
        $full = '/' . trim($prefix . '/' . ltrim($pattern, '/'), '/');
        $route = new Route($methods, $full === '' ? '/' : $full, $handler, $mw);
        $this->routes[] = $route;
        return $route;
    }

    public function dispatch(Request $request): Response
    {
        $path   = $request->path();
        $method = $request->method();
        $allowedElsewhere = false;

        foreach ($this->routes as $route) {
            $params = $route->match($path);
            if ($params === null) {
                continue;
            }
            if (!$route->allows($method)) {
                $allowedElsewhere = true;
                continue;
            }
            return $this->run($route, $params, $request);
        }

        if ($allowedElsewhere) {
            return Response::text('Method Not Allowed', 405);
        }
        return (new \App\Controllers\ErrorController($request))->notFound();
    }

    private function run(Route $route, array $params, Request $request): Response
    {
        // ไฟล์ใหญ่เกิน post_max_size ถูก PHP ทิ้งทั้งก้อน — ต้องดักก่อน CSRF เพราะตอนนี้ $_POST ว่าง
        // ถ้าไม่ดัก ผู้ใช้จะเห็นแค่ "เซสชันหมดอายุ" หรือหน้าเด้งกลับโดยไม่รู้สาเหตุ
        if ($request->postTooLarge()) {
            $limit = round(\App\Services\UploadService::limitBytes() / 1048576, 1);
            if ($request->wantsJson()) {
                return Response::json([
                    'ok'    => false,
                    'error' => 'ไฟล์ใหญ่เกินไป (เซิร์ฟเวอร์รับได้ไม่เกิน ' . $limit . ' MB) กรุณาย่อรูปหรือใช้ภาพหน้าจอแทนรูปถ่ายจากกล้อง',
                ], 413);
            }
            Flash::add('danger', 'ไฟล์ที่อัปโหลดใหญ่เกินไป (เซิร์ฟเวอร์รับได้ไม่เกิน ' . $limit . ' MB) กรุณาย่อรูปก่อนอัปโหลด');
            return Response::redirect($request->safeRefererPath(str_starts_with($request->path(), '/admin') ? 'admin' : ''));
        }

        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $route->needsCsrf() && !Csrf::valid($request)) {
            $msg = 'เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่';
            return $request->wantsJson()
                ? Response::json(['ok' => false, 'error' => $msg], 419)
                : Response::text($msg, 419);
        }

        try {
            foreach ($route->middleware() as $class) {
                $early = (new $class())->handle($request);
                if ($early instanceof Response) {
                    return $early;
                }
            }
            $result = $this->call($route->handler(), $params, $request);
        } catch (HttpException $e) {
            return $e->response();
        }
        return $result instanceof Response ? $result : Response::html((string) $result);
    }

    private function call($handler, array $params, Request $request)
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class($request);
            return $controller->$method(...array_values($params));
        }
        return $handler($request, ...array_values($params));
    }
}
