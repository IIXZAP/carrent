<?php

namespace App\Controllers\Admin;

use App\Core\Flash;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;

/** เข้า/ออกจากระบบหลังบ้าน */
class AuthController extends AdminController
{
    public function showLogin(): Response
    {
        if (AuthService::check()) {
            return $this->redirect('admin/dashboard');
        }
        return $this->view('admin/login', ['title' => 'เข้าสู่ระบบ']);
    }

    public function login(): Response
    {
        $email = $this->request->str('email');
        $pass  = (string) $this->request->post('password', '');

        if (AuthService::attempt($email, $pass)) {
            return $this->redirect('admin/dashboard');
        }
        if (!Flash::has()) {
            $this->flash('danger', 'อีเมลหรือรหัสผ่านไม่ถูกต้อง');
        }
        return $this->redirect('admin/login');
    }

    public function logout(): Response
    {
        AuthService::logout();
        Session::start();
        $this->flash('ok', 'ออกจากระบบเรียบร้อย');
        return $this->redirect('admin/login');
    }
}
