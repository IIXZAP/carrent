<?php
use App\Core\Database;

function t_pdo_error(int $driverCode, string $sqlState): PDOException
{
    $e = new PDOException('x', 0);
    $e->errorInfo = [$sqlState, $driverCode, 'x'];
    return $e;
}

function test_retry_on_deadlock_succeeds_after_deadlocks(): void
{
    $calls = 0;
    $result = Database::retryOnDeadlock(static function () use (&$calls): string {
        $calls++;
        if ($calls < 3) {
            throw t_pdo_error(1213, '40001');
        }
        return 'ok';
    });
    assert_same('ok', $result);
    assert_same(3, $calls);
}

function test_retry_on_deadlock_gives_up_after_max_tries(): void
{
    $calls = 0;
    $thrown = false;
    try {
        Database::retryOnDeadlock(static function () use (&$calls): void {
            $calls++;
            throw t_pdo_error(1213, '40001');
        }, 3);
    } catch (PDOException $e) {
        $thrown = true;
    }
    assert_true($thrown, 'ครบรอบแล้วต้องโยน error ต่อ');
    assert_same(3, $calls);
}

function test_retry_on_deadlock_does_not_retry_other_errors(): void
{
    $calls = 0;
    foreach ([t_pdo_error(1062, '23000'), new RuntimeException('x')] as $error) {
        $calls = 0;
        $thrown = false;
        try {
            Database::retryOnDeadlock(static function () use (&$calls, $error): void {
                $calls++;
                throw $error;
            });
        } catch (Throwable $e) {
            $thrown = true;
        }
        assert_true($thrown);
        assert_same(1, $calls, 'ข้อผิดพลาดอื่นห้ามลองซ้ำ: ' . get_class($error));
    }
}
