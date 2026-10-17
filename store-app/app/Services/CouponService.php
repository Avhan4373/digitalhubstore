<?php

namespace App\Services;

use App\Models\Coupon;

class CouponService
{
    /**
     * Validasi kupon untuk satu pembeli.
     *
     * @return array{ok: bool, message: string, coupon: Coupon|null, discount: int}
     */
    public function validate(?string $code, int $amount, ?string $whatsapp = null): array
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return ['ok' => false, 'message' => 'Masukkan kode voucher.', 'coupon' => null, 'discount' => 0];
        }

        $coupon = Coupon::query()->where('code', $code)->first();

        if (! $coupon) {
            return ['ok' => false, 'message' => 'Kode voucher tidak ditemukan.', 'coupon' => null, 'discount' => 0];
        }

        if (! $coupon->is_active) {
            return ['ok' => false, 'message' => 'Voucher sudah tidak aktif.', 'coupon' => null, 'discount' => 0];
        }

        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            return ['ok' => false, 'message' => 'Voucher belum berlaku.', 'coupon' => null, 'discount' => 0];
        }

        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            return ['ok' => false, 'message' => 'Voucher sudah kedaluwarsa.', 'coupon' => null, 'discount' => 0];
        }

        if ($coupon->isExhausted()) {
            return ['ok' => false, 'message' => 'Kuota voucher sudah habis.', 'coupon' => null, 'discount' => 0];
        }

        if ($amount < $coupon->min_amount) {
            return [
                'ok' => false,
                'message' => 'Minimal belanja '.rupiah($coupon->min_amount).' untuk memakai voucher ini.',
                'coupon' => null,
                'discount' => 0,
            ];
        }

        if ($whatsapp && $coupon->per_buyer_limit > 0
            && $coupon->usageBy(wa_normalize($whatsapp)) >= $coupon->per_buyer_limit) {
            return [
                'ok' => false,
                'message' => 'Voucher ini sudah pernah Anda pakai.',
                'coupon' => null,
                'discount' => 0,
            ];
        }

        $discount = $coupon->discountFor($amount);

        if ($discount <= 0) {
            return ['ok' => false, 'message' => 'Voucher tidak memberi potongan untuk pesanan ini.', 'coupon' => null, 'discount' => 0];
        }

        return ['ok' => true, 'message' => 'Voucher diterapkan: hemat '.rupiah($discount).'.', 'coupon' => $coupon, 'discount' => $discount];
    }
}
