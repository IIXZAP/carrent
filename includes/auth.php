<?php
/**
 * ระบบล็อกอินหลังบ้าน (admin / staff) + ป้องกัน brute force
 */
require_once __DIR__ . '/functions.php';

/**
 * นับจำนวนครั้งที่ล็อกอินผิดภายในช่วงเวลาที่กำหนด
 *
 * เก็บใน activity_logs (ไม่ใช่ $_SESSION) เพราะผู้โจมตีสามารถทิ้งคุกกี้
 * แล้วเริ่มเซสชันใหม่ทุกครั้ง ทำให้ตัวนับใน session ไม่มีผลใดๆ
 * นับทั้ง "อีเมลเดิม" และ "IP เดิม" เพื่อกันการไล่เดาหลายบัญชีจากเครื่องเดียว
 */
function auth_recent_failures(string $email): int
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
    try {
        return (int) db_val(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action = 'login_failed' AND created_at >= :s
               AND (detail = :e OR (ip = :ip AND ip <> ''))",
            [':s' => $since, ':e' => $email, ':ip' => $ip]
        );
    } catch (Throwable $e) {
        return 0;   // ถ้าอ่านตารางไม่ได้ อย่าล็อกผู้ใช้จนเข้าระบบไม่ได้
    }
}

function auth_attempt(string $email, string $password): bool
{
    start_session();

    // ล็อกชั่วคราวหลังพยายามล็อกอินผิดหลายครั้ง (นับจากฐานข้อมูล)
    if (auth_recent_failures($email) >= MAX_LOGIN_ATTEMPTS) {
        flash('danger', 'พยายามเข้าสู่ระบบผิดหลายครั้ง กรุณารออีก ' . LOGIN_LOCK_MINUTES . ' นาที');
        return false;
    }

    $user = db_one('SELECT * FROM users WHERE email = :e AND is_active = 1', [':e' => $email]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        log_activity('login_failed', 'users', null, $email);
        return false;
    }

    // ล็อกอินสำเร็จ — ล้างประวัติที่ผิดของอีเมลนี้ เพื่อไม่ให้ไปล็อกครั้งถัดไป
    try {
        db()->prepare("DELETE FROM activity_logs WHERE action = 'login_failed' AND detail = :e")
            ->execute([':e' => $email]);
    } catch (Throwable $e) {
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'    => (int) $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    $_SESSION['last_activity'] = time();
    db_update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id', $user['id']);
    log_activity('login', 'users', (int) $user['id']);
    return true;
}

function auth_user(): ?array
{
    start_session();
    return $_SESSION['user'] ?? null;
}

function auth_check(): bool
{
    start_session();
    if (empty($_SESSION['user'])) {
        return false;
    }
    // หมดอายุจากการไม่ใช้งาน
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
        auth_logout();
        return false;
    }
    $_SESSION['last_activity'] = time();

    // อ่านสิทธิ์จากฐานข้อมูลทุกครั้ง (ครั้งเดียวต่อ 1 request)
    // เจ้าของร้านปิดบัญชี ลบ หรือลดสิทธิ์พนักงานแล้วต้องมีผลทันที ไม่ใช่รอให้คนนั้นออกจากระบบเอง
    static $checked = false;
    if (!$checked) {
        $checked = true;
        try {
            $u = db_one('SELECT name, role, is_active FROM users WHERE id = :i', [':i' => (int) $_SESSION['user']['id']]);
        } catch (Throwable $e) {
            return true;   // อ่านไม่ได้ชั่วคราว — อย่าเตะผู้ใช้ออกกลางงาน
        }
        if (!$u || !(int) $u['is_active']) {
            auth_logout();
            return false;
        }
        $_SESSION['user']['role'] = $u['role'];
        $_SESSION['user']['name'] = $u['name'];
    }
    return true;
}

function require_login(): void
{
    if (!auth_check()) {
        flash('warn', 'กรุณาเข้าสู่ระบบก่อนใช้งาน');
        redirect('admin/login.php');
    }
}

function require_admin(): void
{
    require_login();
    if ((auth_user()['role'] ?? '') !== 'admin') {
        // พนักงานเปิดหน้าของเจ้าของร้าน → พากลับหน้าภาพรวมพร้อมบอกเหตุผล แทนหน้าขาวที่มีข้อความบรรทัดเดียว
        flash('warn', 'หน้านี้สำหรับเจ้าของร้านเท่านั้น');
        redirect('admin/dashboard.php');
    }
}

function is_admin(): bool
{
    return (auth_user()['role'] ?? '') === 'admin';
}

function auth_logout(): void
{
    start_session();
    if (!empty($_SESSION['user'])) {
        log_activity('logout', 'users', (int) $_SESSION['user']['id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
