<?php
namespace App\Core;

final class View
{
    /** โฟลเดอร์ view (null = app/Views) — เปลี่ยนได้เฉพาะในเทสต์ */
    private static ?string $root = null;

    /** ส่วนเสริมที่ view ฝากไว้ให้ layout (เช่น แถบปุ่มด้านบน) — ชื่อ => เนื้อหา HTML */
    private static array $sections = [];
    private static array $open = [];
    private static int $depth = 0;
    /** layout ที่ view ขอเอง (View::layout) — มีผลแทน layout ที่ controller ส่งมา */
    private static ?array $layoutOverride = null;

    /** view ระบุ layout และตัวแปรของ layout เอง เช่น View::layout('layouts/public', ['title' => 'FAQ', 'active' => 'faq']) */
    public static function layout(string $name, array $vars = []): void
    {
        self::$layoutOverride = [$name, $vars];
    }

    /** วาด view ย่อยโดยไม่ครอบ layout (ได้เฉพาะตัวแปรที่ส่งให้) */
    public static function partial(string $name, array $data = []): string
    {
        return self::render($name, $data, null);
    }

    /** เริ่มเก็บเนื้อหาของส่วนเสริม (ใช้ใน view: View::start('top_actions') ... View::end()) */
    public static function start(string $name): void
    {
        self::$open[] = $name;
        ob_start();
    }

    public static function end(): void
    {
        $name = array_pop(self::$open);
        $html = (string) ob_get_clean();
        if ($name !== null) {
            self::$sections[$name] = $html;
        }
    }

    public static function hasSection(string $name): bool
    {
        return isset(self::$sections[$name]);
    }

    public static function section(string $name): string
    {
        return self::$sections[$name] ?? '';
    }

    public static function useRoot(?string $root): void
    {
        self::$root = $root;
    }

    /** วาด view แล้วครอบด้วย layout (ถ้าระบุ) — layout ได้ $content และ $title */
    public static function render(string $name, array $data = [], ?string $layout = 'layouts/public'): string
    {
        // ส่วนเสริมล้างเมื่อเริ่มวาดหน้าใหม่ระดับนอกสุด (view ซ้อน เช่น ข้อสัญญา ต้องไม่ล้างของหน้าหลัก)
        if (self::$depth === 0) {
            self::$sections = [];
            self::$open = [];
            self::$layoutOverride = null;
        }
        self::$depth++;
        try {
            $content = self::capture($name, $data);
            if ($layout === null) {
                return $content;
            }
            if (self::$depth === 1 && self::$layoutOverride !== null) {
                [$layout, $vars] = self::$layoutOverride;
                $data = $vars + $data;
            }
            $data['content'] = $content;
            return self::capture($layout, $data);
        } finally {
            self::$depth--;
        }
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
