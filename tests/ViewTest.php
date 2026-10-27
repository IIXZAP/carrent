<?php
use App\Core\View;

/** วาด view จาก fixtures แล้วคืน [ผลลัพธ์, exception|null, ข้อความที่หลุดออกไปยัง output ภายนอก, ระดับบัฟเฟอร์ก่อน/หลัง] */
function t_view_run(string $view, ?string $layout = null): array
{
    View::useRoot(__DIR__ . '/fixtures/views');
    $before = ob_get_level();
    ob_start();                       // จับสิ่งที่หลุดออกไปยัง output ภายนอก
    $thrown = null;
    $result = null;
    try {
        $result = View::render($view, ['name' => 'สมชาย'], $layout);
    } catch (Throwable $e) {
        $thrown = $e;
    }
    $leaked = ob_get_level() > $before ? ob_get_clean() : '';
    // ถ้า View ทิ้งบัฟเฟอร์ค้าง ob_get_clean ข้างบนจะดึงบัฟเฟอร์ของ View แทน → ระดับจะไม่ตรง
    $after = ob_get_level();
    View::useRoot(null);
    return [$result, $thrown, $leaked, $before, $after];
}

function test_view_renders_normally(): void
{
    [$result, $thrown, $leaked] = t_view_run('ok');
    assert_same(null, $thrown);
    assert_same("hello สมชาย", $result);
    assert_same('', $leaked);
}

function test_view_exception_does_not_leak_partial_output_or_leave_buffers_open(): void
{
    [$result, $thrown, $leaked, $before, $after] = t_view_run('boom');
    assert_true($thrown instanceof RuntimeException && $thrown->getMessage() === 'view exploded', 'exception ต้องถูกโยนต่อ');
    assert_same($before, $after, 'ห้ามทิ้ง output buffer ค้าง (ไม่งั้นหน้าครึ่งเดียวจะถูกส่งตอนจบ request)');
    assert_true(strpos((string) $leaked, 'SECRET-PARTIAL-OUTPUT') === false, 'เนื้อหาที่วาดไปครึ่งเดียวต้องไม่หลุดออกไป');
}

function test_layout_exception_also_cleans_up(): void
{
    [$result, $thrown, $leaked, $before, $after] = t_view_run('ok', 'layout_boom');
    assert_true($thrown instanceof RuntimeException && $thrown->getMessage() === 'layout exploded');
    assert_same($before, $after, 'layout พังก็ต้องไม่ทิ้งบัฟเฟอร์');
    assert_true(strpos((string) $leaked, 'LAYOUT-PARTIAL') === false && strpos((string) $leaked, 'hello') === false, 'ไม่มีอะไรหลุด');
}

function test_view_data_named_like_internal_variables_is_not_swallowed(): void
{
    View::useRoot(__DIR__ . '/fixtures/views');
    $out = View::render('collide', ['name' => 'N', 'data' => 'D', 'file' => 'F', 'level' => 'L', 'e' => 'E'], null);
    View::useRoot(null);
    assert_same('N|D|F|L|E', $out, 'ข้อมูลของ view ที่ชื่อ name/data/file/level/e ต้องไม่ถูกตัวแปรภายในของ View บัง');
}
