<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\User;

/** จัดการผู้ใช้งานหลังบ้าน (เฉพาะเจ้าของร้าน) */
class UserController extends AdminController
{
    public function index(): Response
    {
        $editId = $this->request->int('edit');
        return $this->view('admin/users/index', [
            'title' => 'ผู้ใช้งาน',
            'me'    => auth_user(),
            'edit'  => $editId ? User::find($editId) : null,
            'users' => User::all(),
            'logs'  => ActivityLog::recent(40),
        ]);
    }

    public function save(): Response
    {
        $r  = $this->request;
        $me = auth_user();
        try {
            $uid   = $r->int('id');
            $email = $r->str('email', 150);
            $name  = $r->str('name', 150);
            $pass  = (string) $r->post('password', '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('กรุณากรอกชื่อและอีเมลให้ถูกต้อง');
            }
            if (User::emailTaken($email, $uid)) {
                throw new \RuntimeException('อีเมลนี้ถูกใช้แล้ว');
            }

            $data = [
                'name'      => $name,
                'email'     => $email,
                'role'      => $r->str('role') === 'admin' ? 'admin' : 'staff',
                'phone'     => $r->str('phone', 30) ?: null,
                'is_active' => $r->has('is_active') ? 1 : 0,
            ];
            if ($pass !== '') {
                if (strlen($pass) < 8) {
                    throw new \RuntimeException('รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร');
                }
                $data['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
            }

            if ($uid) {
                // กันไม่ให้ปิดบัญชีตัวเอง หรือลดสิทธิ์ตัวเองจนไม่เหลือ admin
                if ($uid === (int) $me['id']) {
                    $data['is_active'] = 1;
                    $data['role']      = 'admin';
                }
                // ต้องเหลือเจ้าของร้านที่ใช้งานได้อย่างน้อย 1 คนเสมอ
                $cur = User::roleAndStatus($uid);
                if ($cur && $cur['role'] === 'admin' && (int) $cur['is_active'] === 1
                    && ($data['role'] !== 'admin' || $data['is_active'] !== 1)
                    && User::activeAdminCount($uid) < 1) {
                    throw new \RuntimeException('ต้องมีเจ้าของร้านที่ใช้งานได้อย่างน้อย 1 คน');
                }
                User::updateById($uid, $data);
                ActivityLog::log('user_updated', 'users', $uid);
                $this->flash('ok', 'บันทึกข้อมูลผู้ใช้แล้ว');
            } else {
                if ($pass === '') {
                    throw new \RuntimeException('กรุณากำหนดรหัสผ่านสำหรับผู้ใช้ใหม่');
                }
                $uid = User::insert($data);
                ActivityLog::log('user_created', 'users', $uid);
                $this->flash('ok', 'เพิ่มผู้ใช้แล้ว');
            }
        } catch (\Throwable $ex) {
            $this->flash('danger', $ex instanceof \RuntimeException && !($ex instanceof \PDOException)
                ? $ex->getMessage() : 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        }
        return $this->redirect('admin/users');
    }

    public function delete(): Response
    {
        $me = auth_user();
        try {
            $uid = $this->request->int('id');
            if ($uid === (int) $me['id']) {
                throw new \RuntimeException('ลบบัญชีตัวเองไม่ได้');
            }
            $target = User::roleAndStatus($uid);
            if ($target && $target['role'] === 'admin' && User::activeAdminCount($uid) < 1) {
                throw new \RuntimeException('ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน');
            }
            User::deleteById($uid);
            ActivityLog::log('user_deleted', 'users', $uid);
            $this->flash('warn', 'ลบผู้ใช้แล้ว');
        } catch (\Throwable $ex) {
            $this->flash('danger', $ex instanceof \RuntimeException && !($ex instanceof \PDOException)
                ? $ex->getMessage() : 'ลบไม่สำเร็จ ผู้ใช้นี้มีประวัติการใช้งานที่อ้างอิงอยู่ ให้ปิดบัญชีแทน');
        }
        return $this->redirect('admin/users');
    }
}
