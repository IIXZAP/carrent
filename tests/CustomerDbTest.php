<?php
use App\Core\Database;
use App\Models\Customer;

function t_customer_row(string $phone): int
{
    return Database::insert(
        "INSERT INTO customers (code, type, full_name, phone) VALUES (:c, 'individual', 'ทดสอบ', :p)",
        ['c' => 'T' . bin2hex(random_bytes(6)), 'p' => $phone]
    );
}

function test_db_customer_active_phone_is_unique(): void
{
    t_customer_row('0866660001');
    $blocked = false;
    try {
        t_customer_row('0866660001');
    } catch (\PDOException $e) {
        $blocked = (int) $e->errorInfo[1] === 1062;
    }
    assert_true($blocked, 'DB ต้องปฏิเสธเบอร์ซ้ำ (duplicate key)');
}

function test_db_deleted_customer_does_not_block_phone_reuse(): void
{
    $old = t_customer_row('0866660002');
    Database::run('UPDATE customers SET deleted_at = NOW() WHERE id = :id', ['id' => $old]);
    $new = t_customer_row('0866660002');
    assert_true($new > $old, 'ใช้เบอร์ของลูกค้าที่ลบแล้วซ้ำได้');
    assert_same($new, (int) Customer::findByPhone('0866660002')['id'], 'ค้นหาเจอเฉพาะคนที่ยังใช้งาน');
}

function test_db_create_or_find_returns_existing_on_duplicate(): void
{
    $first = Customer::createOrFind('คนแรก', '0866660003', null, null);
    assert_true($first['created'], 'ครั้งแรกสร้างใหม่');

    $second = Customer::createOrFind('ชื่ออื่น', '0866660003', 'x@y.test', null);
    assert_true(!$second['created'], 'ครั้งที่สองไม่สร้างซ้ำ');
    assert_same($first['customer']['id'], $second['customer']['id']);
    assert_same('คนแรก', $second['customer']['full_name'], 'ไม่ทับข้อมูลเดิม');
    assert_same(1, (int) Database::value('SELECT COUNT(*) FROM customers WHERE phone = :p', ['p' => '0866660003']));
}
