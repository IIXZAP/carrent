<?php
namespace App\Services;

/** เบอร์โทร: เก็บเป็นตัวเลขล้วนรูปแบบเดียว เพื่อค้นหา/เทียบเบอร์ได้ตรง */
final class Phone
{
    /**
     * "081-234 5678" / "+66 81 234 5678" / "0066812345678" → "0812345678"
     * เบอร์ต่างประเทศคงเป็นตัวเลขทั้งหมด ; คืน '' ถ้าไม่ใช่เบอร์ที่เป็นไปได้ (9–15 หลัก)
     */
    public static function normalize(string $input): string
    {
        if (!preg_match('/^[0-9\\-\\+\\s\\(\\)\\.]+$/', trim($input))) {
            return '';
        }
        $digits = preg_replace('/\\D+/', '', $input);
        if (strncmp($digits, '0066', 4) === 0) {
            $digits = '66' . substr($digits, 4);
        }
        if (strncmp($digits, '66', 2) === 0 && strlen($digits) === 11) {
            $digits = '0' . substr($digits, 2);
        }
        $len = strlen($digits);
        return $len >= 9 && $len <= 15 ? $digits : '';
    }

    /** แสดงผล: 10 หลักขึ้นต้น 0 → 081-234-5678 */
    public static function display(string $normalized): string
    {
        if (preg_match('/^0(\\d{2})(\\d{3})(\\d{4})$/', $normalized, $m)) {
            return '0' . $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return $normalized;
    }
}
