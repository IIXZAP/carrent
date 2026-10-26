<?php
namespace App\Controllers\Admin;

use App\Services\Auth;

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
        if (!Auth::attempt($email, (string) ($_POST['password'] ?? ''))) {
            flash('error', 'อีเมลหรือรหัสผ่านไม่ถูกต้อง');
            view('admin/login', ['title' => 'เข้าสู่ระบบ', 'email' => $email], 'layouts/public');
            return;
        }
        redirect('/admin');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/admin/login');
    }
}
