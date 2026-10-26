<?php
namespace App\Services;

use App\Models\Addon;
use App\Models\Setting;

/** ตัวเชื่อม: โหลดกฎราคา/บริการเสริมจาก DB แล้วส่งให้ PricingService (ที่เป็นฟังก์ชันล้วน) */
final class QuoteService
{
    /** กฎราคาจากตาราง settings */
    public static function rules(): array
    {
        return [
            'vat_enabled'              => Setting::get('vat_enabled', '0') === '1',
            'vat_rate'                 => (float) Setting::get('vat_rate', '7'),
            'booking_deposit'          => (float) Setting::get('booking_deposit', '1000'),
            'deposit_percent'          => (float) Setting::get('deposit_percent', '20'),
            'security_deposit_default' => (float) Setting::get('security_deposit_default', '3000'),
            'grace_hours'              => (int) Setting::get('late_grace_hours', '2'),
            'free_km'                  => (float) Setting::get('free_delivery_km', '30'),
            'delivery_tiers'           => DeliveryFeeService::parseTiers(Setting::get('delivery_tiers', '')),
        ];
    }

    /** ใบเสนอราคาของรถคันหนึ่ง ($rawAddons = ค่าดิบจากฟอร์ม) */
    public static function forCar(array $car, string $pickup, string $return, array $rawAddons = [], float $discount = 0.0, float $km = 0.0): array
    {
        $catalog  = Addon::activeCatalog();
        $selected = self::cleanAddons($rawAddons, $catalog);
        return PricingService::quote($car, $catalog, $pickup, $return, $selected, $discount, max(0.0, $km), self::rules());
    }

    /** เก็บเฉพาะ code ที่มีขายจริง จำนวน 0–10 */
    public static function cleanAddons(array $raw, array $catalog): array
    {
        $out = [];
        foreach ($catalog as $code => $addon) {
            $qty = $raw[$code] ?? 0;
            if (is_scalar($qty) && ctype_digit((string) $qty) && (int) $qty > 0) {
                $out[$code] = min(10, (int) $qty);
            }
        }
        return $out;
    }
}
