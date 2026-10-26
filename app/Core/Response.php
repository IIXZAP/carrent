<?php

namespace App\Core;

/**
 * ผลลัพธ์ที่ controller ส่งกลับ — ยังไม่ส่งออกจนกว่า Application จะเรียก send()
 */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
        private ?string $file = null
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($text, $status, ['Content-Type' => $type . '; charset=utf-8']);
    }

    public static function json($data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /** $path เป็น path ภายในระบบ (ไม่ต้องมี BASE_URL) หรือ URL เต็มขึ้นต้น http */
    public static function redirect(string $path, int $status = 302): self
    {
        $target = preg_match('#^https?://#i', $path) ? $path : url($path);
        return new self('', $status, ['Location' => $target]);
    }

    /** ส่งไฟล์บนดิสก์ออกไปตรงๆ (ไฟล์สำรอง เอกสารลูกค้า) */
    public static function file(string $fullPath, array $headers = [], int $status = 200): self
    {
        if (!is_file($fullPath)) {
            return self::text('ไม่พบไฟล์', 404);
        }
        $headers['Content-Length'] = (string) filesize($fullPath);
        return new self('', $status, $headers, $fullPath);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(int $code): self
    {
        $this->status = $code;
        return $this;
    }

    public function statusCode(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        if ($this->file !== null) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($this->file);
            return;
        }
        echo $this->body;
    }
}
