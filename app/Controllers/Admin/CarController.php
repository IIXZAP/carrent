<?php

namespace App\Controllers\Admin;

use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Car;
use App\Models\Maintenance;
use App\Services\UploadService;

/** จัดการรถ — สถานะ ราคา รูป เอกสาร และงานประจำรถ */
class CarController extends AdminController
{
    private const STATUSES = ['available', 'maintenance', 'inactive'];
    private const TYPES    = ['eco', 'sedan', 'suv', 'pickup', 'van'];

    public function index(): Response
    {
        $cars = Car::all();
        foreach ($cars as &$c) {
            $c['active_booking'] = Car::activeRental((int) $c['id']);
        }
        unset($c);

        return $this->view('admin/cars/index', [
            'title'    => 'จัดการรถ',
            'cars'     => $cars,
            'today'    => date('Y-m-d'),
            'warnDays' => (int) setting('reminder_days_before', '30'),
        ]);
    }

    public function status(): Response
    {
        $carId  = $this->request->int('car_id');
        $status = $this->request->str('status');
        $status = in_array($status, self::STATUSES, true) ? $status : 'available';

        // รถที่ยังมีงานค้าง ห้ามปิดใช้งาน — ไม่งั้นการจองเหล่านั้นจะหายจากรายงาน และแก้ไข/เช่าต่อไม่ได้
        $open = $status === 'inactive' ? Car::openBookingCount($carId) : 0;
        if ($open > 0) {
            return $this->done('admin/cars', 'danger', 'รถคันนี้ยังมีการจองที่ยังไม่จบ ' . $open . ' รายการ — ปิดใช้งานไม่ได้ ให้ตั้งเป็น "ซ่อมบำรุง" หรือจัดการการจองให้เสร็จก่อน');
        }
        Car::setStatus($carId, $status);
        ActivityLog::log('car_status', 'cars', $carId, $status);
        return $this->done('admin/cars', 'ok', 'อัปเดตสถานะรถแล้ว');
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(int $id): Response
    {
        return $this->form($this->find($id));
    }

    public function store(): Response
    {
        return $this->save(null);
    }

    public function update(int $id): Response
    {
        return $this->save($this->find($id));
    }

    private function find(int $id): array
    {
        $car = Car::find($id);
        if (!$car) {
            $this->halt($this->done('admin/cars', 'danger', 'ไม่พบรถคันนี้'));
        }
        return $car;
    }

    private function form(?array $car, array $old = []): Response
    {
        $v = $old + ($car ?: []);
        return $this->view('admin/cars/form', [
            'title'  => $car ? 'แก้ไขรถ ' . $car['plate'] : 'เพิ่มรถใหม่',
            'car'    => $car,
            'id'     => $car ? (int) $car['id'] : 0,
            'v'      => $v,
            'feats'  => isset($old['features']) ? array_values(array_filter(array_map('trim', explode("\n", (string) $old['features'])))) :
                        ($car ? (json_decode((string) $car['features'], true) ?: []) : []),
            'images' => $car ? (json_decode((string) $car['images'], true) ?: []) : [],
            'limit'  => UploadService::limitBytes(),
        ]);
    }

    private function save(?array $car): Response
    {
        $id = $car ? (int) $car['id'] : 0;
        try {
            $data = $this->readCarFields();
            $this->checkRequired($data);
            $data = $data + $this->readFeatures() + $this->readImages($car);

            if ($id) {
                Car::updateById($id, $data);
                ActivityLog::log('car_updated', 'cars', $id, $data['plate']);
                $this->flash('ok', 'บันทึกข้อมูลรถแล้ว');
            } else {
                $id = Car::insert($data);
                ActivityLog::log('car_created', 'cars', $id, $data['plate']);
                $this->flash('ok', 'เพิ่มรถเรียบร้อย');
            }
            $this->createMaintenanceTasks($id, $data);
            return $this->redirect('admin/cars');
        } catch (\Throwable $ex) {
            $this->flash('danger', $this->errorMessage($ex));
            return $this->form($car, $this->request->all());
        }
    }

    /** ข้อความผิดพลาดที่แสดงให้ผู้ใช้เห็น — ข้อความจากระบบฐานข้อมูลไม่แสดงตรงๆ */
    private function errorMessage(\Throwable $ex): string
    {
        if ($ex instanceof \RuntimeException && !($ex instanceof \PDOException)) {
            return $ex->getMessage();
        }
        return 'บันทึกไม่สำเร็จ กรุณาตรวจข้อมูลแล้วลองใหม่';
    }

    /** อ่านช่องข้อมูลทั่วไปจากฟอร์ม */
    private function readCarFields(): array
    {
        $r = $this->request;
        $carType = in_array($r->str('car_type'), self::TYPES, true) ? $r->str('car_type') : 'sedan';
        $status  = in_array($r->str('status'), self::STATUSES, true) ? $r->str('status') : 'available';

        return [
            'plate'            => $r->str('plate'),
            'province'         => $r->str('province') ?: null,
            'brand'            => $r->str('brand'),
            'model'            => $r->str('model'),
            'year'             => $r->int('year') ?: null,
            'color'            => $r->str('color') ?: null,
            'car_type'         => $carType,
            'seats'            => $r->int('seats', 5),
            'gear'             => $r->str('gear', 0, 'auto') === 'manual' ? 'manual' : 'auto',
            'fuel'             => $r->str('fuel', 0, 'เบนซิน'),
            'price_day'        => $r->float('price_day'),
            'price_week'       => $r->float('price_week'),
            'price_month'      => $r->float('price_month'),
            'deposit'          => $r->float('deposit'),
            'deposit_booking'  => $r->float('deposit_booking'),
            'delivery_fee'     => $r->float('delivery_fee'),
            'driver_fee_day'   => $r->float('driver_fee_day'),
            'status'           => $status,
            'mileage'          => $r->int('mileage'),
            'insurance_type'   => $r->str('insurance_type') ?: null,
            'insurance_expire' => $r->str('insurance_expire') ?: null,
            'act_expire'       => $r->str('act_expire') ?: null,
            'tax_expire'       => $r->str('tax_expire') ?: null,
            'next_service_km'  => $r->int('next_service_km') ?: null,
            'next_service_at'  => $r->str('next_service_at') ?: null,
            'description'      => $r->str('description') ?: null,
            'sort_order'       => $r->int('sort_order'),
        ];
    }

    private function checkRequired(array $data): void
    {
        if ($data['plate'] === '' || $data['brand'] === '' || $data['model'] === '') {
            throw new \RuntimeException('กรุณากรอก ทะเบียน / ยี่ห้อ / รุ่น');
        }
        if ($data['price_day'] <= 0) {
            throw new \RuntimeException('กรุณากรอกราคาเช่ารายวัน');
        }
    }

    /** อุปกรณ์/จุดเด่น (บรรทัดละ 1 รายการ) เก็บเป็น JSON */
    private function readFeatures(): array
    {
        $features = array_values(array_filter(array_map('trim', explode("\n", (string) $this->request->post('features', '')))));
        return ['features' => $features ? json_encode($features, JSON_UNESCAPED_UNICODE) : null];
    }

    /** รูปหน้าปก + รูปเพิ่มเติม (เพิ่มรูปใหม่ และตัดรูปที่ติ๊กลบออกจากรายการ) */
    private function readImages(?array $car): array
    {
        $r      = $this->request;
        $result = [];

        $cover = UploadService::save($r->file('cover_image'), 'cars', 6);
        if ($cover) {
            $result['cover_image'] = $cover;
        }

        $images = $car ? (json_decode((string) $car['images'], true) ?: []) : [];
        foreach ($r->fileList('images') as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $path = UploadService::save($file, 'cars', 6);
            if ($path) {
                $images[] = $path;
            }
        }
        // ลบเฉพาะการอ้างอิง — ไฟล์จริงคงอยู่ในเซิร์ฟเวอร์
        $remove = array_map('strval', $r->arr('remove_images'));
        if ($remove) {
            $images = array_values(array_diff($images, $remove));
        }
        $result['images'] = $images ? json_encode(array_values($images), JSON_UNESCAPED_UNICODE) : null;
        return $result;
    }

    /** สร้างงานประจำรถอัตโนมัติจากวันครบกำหนด (ประกัน / พ.ร.บ. / ภาษี / เช็กระยะ) */
    private function createMaintenanceTasks(int $carId, array $data): void
    {
        $tasks = [
            ['insurance', 'ต่อประกันภัย', $data['insurance_expire']],
            ['act', 'ต่อ พ.ร.บ.', $data['act_expire']],
            ['tax', 'ต่อภาษีประจำปี', $data['tax_expire']],
            ['service', 'เช็กระยะ/เปลี่ยนถ่ายน้ำมัน', $data['next_service_at']],
        ];
        foreach ($tasks as [$type, $title, $dueDate]) {
            if ($dueDate && !Maintenance::pendingExists($carId, $type, $dueDate)) {
                Maintenance::insert([
                    'car_id'    => $carId,
                    'task_type' => $type,
                    'title'     => $title . ' ' . $data['plate'],
                    'due_date'  => $dueDate,
                    'status'    => 'pending',
                ]);
            }
        }
    }
}
