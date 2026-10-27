<?php
namespace App\Core;

final class View
{
    /** โฟลเดอร์ view (null = app/Views) — เปลี่ยนได้เฉพาะในเทสต์ */
    private static ?string $root = null;

    public static function useRoot(?string $root): void
    {
        self::$root = $root;
    }

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

    /**
     * วาด view ในฟังก์ชันแยก เพื่อให้ตัวแปรภายในของเราไม่ชนกับชื่อข้อมูลที่ส่งให้ view
     * (ถ้าชื่อซ้ำ extract แบบ EXTR_SKIP จะเมินข้อมูลของ view เงียบๆ — เคยมีบั๊กกับข้อมูลชื่อ name/data/file/level)
     */
    private static function capture(string $__view, array $__data): string
    {
        $__file = (self::$root ?? APP_ROOT . '/app/Views') . '/' . $__view . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException('ไม่พบ view: ' . $__view);
        }
        $__level = ob_get_level();
        ob_start();
        try {
            (static function () use ($__file, $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })();
            return (string) ob_get_clean();
        } catch (\Throwable $__e) {
            // view พังกลางทาง: ทิ้งสิ่งที่วาดไปครึ่งเดียว ไม่ปล่อยให้ค้างแล้วถูกส่งออกไปพร้อมหน้า error
            while (ob_get_level() > $__level) {
                ob_end_clean();
            }
            throw $__e;
        }
    }
}
