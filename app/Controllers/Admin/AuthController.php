<?php
namespace App\Controllers\Admin;

use App\Models\ActivityLog;
use App\Services\Auth;
use App\Services\LoginThrottle;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('/admin');
        }
        view('admin/login', ['title' => 'เข้าสู่ระบบ', 'email' => ''], 'layouts/public');
    }

    public function login(): void
    {
        $email = post('email');
        $ip = client_ip();

        // ตรวจก่อนเทียบรหัสผ่าน: ระงับแล้วไม่เสีย CPU กับ password_verify และรหัสถูกก็เข้าไม่ได้
        if (LoginThrottle::isBlocked($ip, $email)) {
            http_response_code(429);
            flash('error', 'พยายามเข้าสู่ระบบผิดหลายครั้ง กรุณารอ 15 นาทีแล้วลองใหม่');
            view('admin/login', ['title' => 'เข้าสู่ระบบ', 'email' => $email], 'layouts/public');
            return;
        }
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (!Auth::attempt($email, $password)) {
            LoginThrottle::recordFailure($ip, $email);
            flash('error', 'อีเมลหรือรหัสผ่านไม่ถูกต้อง');
            view('admin/login', ['title' => 'เข้าสู่ระบบ', 'email' => $email], 'layouts/public');
            return;
        }
        ActivityLog::log('login', 'users', (int) Auth::user()['id'], null, (int) Auth::user()['id'], $ip);
        redirect('/admin');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/admin/login');
    }
}
