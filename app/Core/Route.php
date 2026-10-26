<?php

namespace App\Core;

/**
 * เส้นทาง 1 รายการ: method + pattern → handler [Controller::class, 'method']
 */
final class Route
{
    private string $regex;
    /** @var string[] */
    private array $paramNames = [];
    private bool $csrf = true;
    /** @var string[] */
    private array $middleware;

    /**
     * @param string[]       $methods
     * @param array|callable $handler
     * @param string[]       $middleware
     */
    public function __construct(private array $methods, string $pattern, private $handler, array $middleware = [])
    {
        $this->middleware = $middleware;
        $this->regex = $this->compile($pattern);
    }

    /**
     * แปลง /cars/{id} หรือ /cars/{id:\d+} เป็น regex
     * ส่วนที่เป็นตัวอักษรตายตัว (เช่น "/cars.php") ถูก escape ก่อน จุด "." จึงเป็นจุดจริง ไม่ใช่ตัวแทนอักขระใดก็ได้
     */
    private function compile(string $pattern): string
    {
        // แยกเป็นส่วนสลับกัน: ตัวอักษรตายตัว / {พารามิเตอร์} / ตัวอักษรตายตัว ...
        $parts = preg_split('#(\{\w+(?::[^}]+)?\})#', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);

        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('#^\{(\w+)(?::([^}]+))?\}$#', $part, $m)) {
                $this->paramNames[] = $m[1];
                $regex .= '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return '#^' . $regex . '$#u';
    }

    /** ปิดการตรวจ CSRF ของเส้นทางนี้ (webhook จากภายนอก) */
    public function noCsrf(): self
    {
        $this->csrf = false;
        return $this;
    }

    public function needsCsrf(): bool
    {
        return $this->csrf;
    }

    public function handler()
    {
        return $this->handler;
    }

    /** @return string[] */
    public function middleware(): array
    {
        return $this->middleware;
    }

    public function allows(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    /** @return array<string,string>|null พารามิเตอร์ที่จับได้ หรือ null ถ้าไม่ตรง */
    public function match(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $m)) {
            return null;
        }
        $params = [];
        foreach ($this->paramNames as $name) {
            $params[$name] = $m[$name];
        }
        return $params;
    }
}
