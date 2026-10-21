<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\CarType;

/** จัดการประเภทรถ (เฉพาะเจ้าของร้าน) — เพิ่มประเภทใหม่ได้เองโดยไม่ต้องแก้โค้ด */
class CarTypeController extends AdminController
{
    public function index(): Response
    {
        $editCode = $this->request->str('edit', 20);
        return $this->view('admin/car_types/index', [
            'title' => 'ประเภทรถ',
            'types' => CarType::allWithUsage(),
            'edit'  => $this->findForEdit($editCode),
        ]);
    }

    /** บันทึก: ถ้ามีรหัสเดิม (original) คือแก้ไข ถ้าไม่มีคือเพิ่มใหม่ */
    public function save(): Response
    {
        $r = $this->request;
        try {
            $original = $r->str('original', 20);
            $label    = $r->str('label_th', 60);
            $sort     = $r->int('sort_order');
            if ($label === '') {
                throw new \RuntimeException('กรุณากรอกชื่อประเภท');
            }

            if ($original !== '') {
                CarType::change($original, $label, $sort, $r->has('is_active'));
                ActivityLog::log('car_type_updated', 'car_types', null, $original);
                $this->flash('ok', 'บันทึกประเภทรถแล้ว');
            } else {
                $code = $this->readNewCode();
                CarType::create($code, $label, $sort);
                ActivityLog::log('car_type_created', 'car_types', null, $code);
                $this->flash('ok', 'เพิ่มประเภทรถแล้ว');
            }
        } catch (\Throwable $ex) {
            $this->flash('danger', $this->errorMessage($ex));
        }
        return $this->redirect('admin/car-types');
    }

    public function delete(): Response
    {
        $code = $this->request->str('code', 20);
        $used = CarType::usage($code);
        if ($used > 0) {
            return $this->done('admin/car-types', 'danger', "ลบไม่ได้ มีรถใช้ประเภทนี้อยู่ $used คัน — ปิดใช้งานแทน หรือเปลี่ยนประเภทของรถก่อน");
        }
        CarType::remove($code);
        ActivityLog::log('car_type_deleted', 'car_types', null, $code);
        return $this->done('admin/car-types', 'warn', 'ลบประเภทรถแล้ว');
    }

    /** ประเภทที่กำลังแก้ไข (จาก ?edit=รหัส) */
    private function findForEdit(string $code): ?array
    {
        if ($code === '') {
            return null;
        }
        foreach (CarType::allWithUsage() as $type) {
            if ($type['code'] === $code) {
                return $type;
            }
        }
        return null;
    }

    /** รหัสประเภทใหม่: ตัวอักษรเล็ก ตัวเลข ขีดกลาง/ขีดล่าง ไม่ซ้ำของเดิม */
    private function readNewCode(): string
    {
        $code = strtolower($this->request->str('code', 20));
        if (!preg_match('/^[a-z0-9_-]{2,20}$/', $code)) {
            throw new \RuntimeException('รหัสประเภทใช้ได้เฉพาะตัวอักษรอังกฤษพิมพ์เล็ก ตัวเลข - และ _ (2–20 ตัว)');
        }
        if (CarType::exists($code)) {
            throw new \RuntimeException('รหัสนี้มีอยู่แล้ว');
        }
        return $code;
    }

    private function errorMessage(\Throwable $ex): string
    {
        if ($ex instanceof \RuntimeException && !($ex instanceof \PDOException)) {
            return $ex->getMessage();
        }
        return 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
    }
}
