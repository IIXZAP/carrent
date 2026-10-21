<?php

namespace App\Core;

/**
 * ตัวแสดงผลเทมเพลต PHP ธรรมดา (ไม่มีภาษาแม่แบบใหม่ให้เรียน)
 *
 * ในไฟล์ view:
 *   <?php $this->layout('layouts/public', ['title' => 'หน้าแรก']) ?>   เลือก layout + ส่งค่าให้ layout
 *   <?= $this->partial('partials/car_card', ['c' => $car]) ?>          แทรกชิ้นส่วนย่อย
 *   <?php $this->start('mobile_bar') ?> ... <?php $this->end() ?>     เก็บเนื้อหาเป็นส่วนไว้ให้ layout เรียกใช้
 *   <?= $this->section('mobile_bar') ?>                                  (ใน layout) แสดงส่วนที่เก็บไว้
 *   <?= $content ?>                                                      (ใน layout) เนื้อหาของหน้า
 */
final class View
{
    private ?string $layoutName = null;
    private array $layoutData = [];
    private array $sections = [];
    private array $sectionStack = [];
    private array $shared = [];

    public static function make(string $name, array $data = []): string
    {
        return (new self())->render($name, $data);
    }

    public function render(string $name, array $data = []): string
    {
        $this->shared = $data;
        $content = $this->capture($name, $data);

        // layout ซ้อนกันได้ (layout เรียก $this->layout() ต่อ) — วนจนไม่มี layout ให้ห่อแล้ว
        while ($this->layoutName !== null) {
            $layout     = $this->layoutName;
            $layoutData = $this->layoutData;
            $this->layoutName = null;
            $this->layoutData = [];
            $content = $this->capture($layout, $layoutData + $this->shared + ['content' => $content]);
        }
        return $content;
    }

    public function layout(string $name, array $data = []): void
    {
        $this->layoutName = $name;
        $this->layoutData = $data;
    }

    /** แทรกชิ้นส่วนย่อย — ส่งตัวแปรให้เฉพาะที่ระบุ */
    public function partial(string $name, array $data = []): string
    {
        return $this->capture($name, $data);
    }

    public function start(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function end(): void
    {
        $name = array_pop($this->sectionStack);
        $this->sections[$name] = (string) ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]) && trim($this->sections[$name]) !== '';
    }

    private function capture(string $name, array $data): string
    {
        $file = VIEW_PATH . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('ไม่พบไฟล์ view: ' . $name);
        }
        return $this->includeFile($file, $data);
    }

    private function includeFile(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
