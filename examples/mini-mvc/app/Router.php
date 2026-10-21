<?php
/**
 * Router: เก็บตารางเส้นทาง แล้วหาว่า URL ที่เข้ามาให้ Controller ตัวไหนรับ
 */
class Router
{
    /** เส้นทางทั้งหมด แต่ละรายการ = [method, regex, [Controller, เมธอด]] */
    private array $routes = [];

    public function get(string $pattern, array $handler): void
    {
        $this->routes[] = ['GET', $this->toRegex($pattern), $handler];
    }

    public function post(string $pattern, array $handler): void
    {
        $this->routes[] = ['POST', $this->toRegex($pattern), $handler];
    }

    /** แปลง /cars/{id:\d+} ให้เป็น regex เช่น #^/cars/(?P<id>\d+)$# */
    private function toRegex(string $pattern): string
    {
        // {ชื่อ:รูปแบบ} → (?P<ชื่อ>รูปแบบ)
        $regex = preg_replace('#\{(\w+):([^}]+)\}#', '(?P<$1>$2)', $pattern);
        // {ชื่อ} (ไม่ระบุรูปแบบ) → รับอะไรก็ได้ยกเว้น /
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $regex);
        return '#^' . $regex . '$#';
    }

    /** หา route ที่ตรงกับ URL แล้วเรียก Controller */
    public function dispatch(string $method, string $path): void
    {
        $pathFound = false;

        foreach ($this->routes as [$routeMethod, $regex, $handler]) {
            if (!preg_match($regex, $path, $matches)) {
                continue;                          // URL ไม่ตรง ลองอันถัดไป
            }
            $pathFound = true;
            if ($routeMethod !== $method) {
                continue;                          // URL ตรงแต่ method ไม่ตรง
            }

            // เก็บเฉพาะค่าที่ตั้งชื่อไว้ เช่น id ส่งต่อให้เมธอดของ Controller
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[] = $value;
                }
            }

            [$class, $action] = $handler;
            $controller = new $class();
            $controller->$action(...$params);
            return;
        }

        if ($pathFound) {
            http_response_code(405);
            echo '405 ใช้ method ไม่ถูกต้อง';
        } else {
            http_response_code(404);
            echo '404 ไม่พบหน้านี้';
        }
    }
}
