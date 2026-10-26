<?php
/** สร้างผู้ใช้หลังบ้าน: php bin/create-admin.php อีเมล "ชื่อ" [admin|staff]  (จะถามรหัสผ่านทีหลัง ไม่ใส่ใน argument) */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../app/bootstrap.php';

use App\Models\User;

[$script, $email, $name] = $argv + [null, null, null];
$role = $argv[3] ?? 'admin';
if (!$email || !$name || !in_array($role, ['admin', 'staff'], true)) {
    exit("วิธีใช้: php bin/create-admin.php อีเมล \"ชื่อ\" [admin|staff]\n");
}
if (User::findByEmail($email) !== null) {
    exit("มีอีเมลนี้อยู่แล้ว\n");
}

$password = getenv('ADMIN_PASSWORD') ?: '';
if ($password === '') {
    echo 'รหัสผ่าน (อย่างน้อย 10 ตัว): ';
    $password = trim((string) fgets(STDIN));
}
if (strlen($password) < 10) {
    exit("รหัสผ่านสั้นเกินไป\n");
}

User::create($name, $email, password_hash($password, PASSWORD_DEFAULT), $role);
echo "สร้างผู้ใช้ $email ($role) แล้ว\n";
