<?php
namespace App\Core;

final class View
{
    /** วาด view แล้วครอบด้วย layout (ถ้าระบุ) — layout ได้ $content และ $title */
    public static function render(string $name, array $data = [], ?string $layout = 'layouts/public'): string
    {
        $content = self::capture($name, $data);
        if ($layout === null) {
            return $content;
        }
        $data['content'] = $content;
        return self::capture($layout, $data);
    }

    private static function capture(string $name, array $data): string
    {
        $file = APP_ROOT . '/app/Views/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('ไม่พบ view: ' . $name);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
