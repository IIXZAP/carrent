<?php
namespace App\Services;

use App\Core\Database;
use App\Models\ActivityLog;
use App\Models\Customer;

/**
 * เอกสารลูกค้า (บัตรประชาชน ใบขับขี่ ฯลฯ) — เก็บใน storage/private/customer_docs (เว็บเปิดตรงไม่ได้)
 * ชื่อไฟล์เป็นเลขสุ่ม · ตรวจชนิดจากเนื้อไฟล์จริง · เปิดดูผ่านหน้าที่ตรวจสิทธิ์และบันทึกประวัติการเปิดเท่านั้น
 * ลบได้ (สิทธิขอลบข้อมูลตาม PDPA) โดยเจ้าของร้าน พร้อมบันทึกประวัติ
 */
final class CustomerDocService
{
    public const MAX_PER_CUSTOMER = 20;
    private const MAX_BYTES = 8 * 1024 * 1024;
    public const KINDS = ['id_card' => 'บัตรประชาชน', 'driver_license' => 'ใบขับขี่', 'passport' => 'หนังสือเดินทาง', 'other' => 'เอกสารอื่น'];
    private const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    private const MIME_BY_EXT = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    public static function absPath(string $rel): ?string
    {
        if (!preg_match('#^customer_docs/[0-9a-f]{32}\.(jpg|png|webp|pdf)\z#', $rel)) {
            return null;
        }
        $full = storage_path($rel);
        return is_file($full) ? $full : null;
    }

    /** @throws UserError */
    public static function add(int $customerId, string $kind, array $file, string $note, ?int $userId, bool $viaHttp = true): int
    {
        if (!isset(self::KINDS[$kind])) {
            throw new UserError('ชนิดเอกสารไม่ถูกต้อง');
        }
        $customer = Customer::find($customerId);
        if ($customer === null || $customer['deleted_at'] !== null) {
            throw new UserError('ไม่พบลูกค้า');
        }
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            throw new UserError('ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($code !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp) || ($viaHttp && !is_uploaded_file($tmp))) {
            throw new UserError('กรุณาแนบไฟล์เอกสาร');
        }
        clearstatcache(true, $tmp);
        if (filesize($tmp) > self::MAX_BYTES) {
            throw new UserError('ไฟล์ใหญ่เกิน ' . (self::MAX_BYTES / 1024 / 1024) . ' MB');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            throw new UserError('รองรับเฉพาะไฟล์ JPG, PNG, WEBP และ PDF');
        }
        if ($ext === 'pdf') {
            // libmagic ยอมให้ %PDF- อยู่ที่ไหนก็ได้ใน 1 KB แรก (ไฟล์ที่มีโค้ดนำหน้าก็ถูกมองเป็น PDF) — เรารับเฉพาะที่ขึ้นต้นด้วย %PDF- จริง
            $fh = fopen($tmp, 'rb');
            $head = $fh ? (string) fread($fh, 5) : '';
            if ($fh) {
                fclose($fh);
            }
            if ($head !== '%PDF-') {
                throw new UserError('ไฟล์ PDF ไม่ถูกต้อง');
            }
        } else {
            $info = @getimagesize($tmp);
            if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000) {
                throw new UserError('ไฟล์รูปไม่ถูกต้อง');
            }
        }

        $dir = storage_path('customer_docs');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('สร้างโฟลเดอร์เก็บเอกสารไม่ได้');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $rel = 'customer_docs/' . $name;
        $moved = $viaHttp ? move_uploaded_file($tmp, $dir . '/' . $name) : copy($tmp, $dir . '/' . $name);
        if (!$moved) {
            throw new \RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
        }
        chmod($dir . '/' . $name, 0640);
        $size = (int) filesize($dir . '/' . $name);

        try {
            $id = (int) Database::transaction(static function () use ($customerId, $kind, $rel, $mime, $size, $note, $userId): int {
                // ล็อกแถวลูกค้า: นับจำนวนเอกสารกับเพิ่มต้องเป็นก้อนเดียว ไม่งั้นพร้อมกันแล้วเกินกำหนด
                if (Database::value('SELECT id FROM customers WHERE id = :i AND deleted_at IS NULL FOR UPDATE', ['i' => $customerId]) === null) {
                    throw new UserError('ไม่พบลูกค้า');
                }
                if ((int) Database::value('SELECT COUNT(*) FROM customer_documents WHERE customer_id = :c', ['c' => $customerId]) >= self::MAX_PER_CUSTOMER) {
                    throw new UserError('แนบเอกสารได้ไม่เกิน ' . self::MAX_PER_CUSTOMER . ' ไฟล์ต่อคน');
                }
                return Customer::addDocument([
                    'customer_id' => $customerId, 'doc_kind' => $kind, 'file_path' => $rel, 'mime' => $mime, 'file_size' => $size,
                    'note' => mb_substr(trim($note), 0, 200) ?: null, 'uploaded_by' => $userId,
                ]);
            });
        } catch (\Throwable $e) {
            @unlink($dir . '/' . $name);   // ไฟล์นี้เพิ่งสร้างเองและยังไม่มีแถวอ้างถึง
            throw $e;
        }
        ActivityLog::log('customer_document_uploaded', 'customer_documents', $id, $kind, $userId, null);
        return $id;
    }

    /** @throws UserError */
    public static function delete(int $documentId, ?int $userId, string $ip): void
    {
        $doc = Customer::findDocument($documentId);
        if ($doc === null || !Customer::deleteDocument($documentId)) {
            throw new UserError('ไม่พบเอกสารนี้ (อาจถูกลบไปแล้ว)');
        }
        if (($full = self::absPath((string) $doc['file_path'])) !== null) {
            @unlink($full);
        }
        ActivityLog::log('customer_document_deleted', 'customers', (int) $doc['customer_id'], (string) $doc['doc_kind'], $userId, $ip !== '' ? $ip : null);
    }

    /** ส่งไฟล์ให้เบราว์เซอร์ (เรียกหลังตรวจสิทธิ์แล้วเท่านั้น) และบันทึกว่าใครเปิดดู */
    public static function serve(array $doc, ?int $userId, string $ip): void
    {
        $full = self::absPath((string) $doc['file_path']);
        if ($full === null) {
            abort(404);
        }
        ActivityLog::log('customer_document_viewed', 'customer_documents', (int) $doc['id'], (string) $doc['doc_kind'], $userId, $ip !== '' ? $ip : null);
        $ext = pathinfo($full, PATHINFO_EXTENSION);
        header('Content-Type: ' . (self::MIME_BY_EXT[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: inline; filename="customer-' . (int) $doc['customer_id'] . '-' . $doc['doc_kind'] . '.' . $ext . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        readfile($full);
        exit;
    }
}
