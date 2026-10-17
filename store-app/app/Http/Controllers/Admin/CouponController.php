<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index()
    {
        $coupons = Coupon::query()
            ->withCount('orders')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.coupons.index', ['coupons' => $coupons]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $coupon = Coupon::create($data);

        ActivityLog::record('coupon.created', 'Voucher '.$coupon->code.' dibuat', $coupon);

        return back()->with('success', 'Voucher '.$coupon->code.' dibuat.');
    }

    public function update(Request $request, Coupon $coupon)
    {
        $data = $this->validated($request, $coupon->id);

        $coupon->update($data);

        ActivityLog::record('coupon.updated', 'Voucher '.$coupon->code.' diperbarui', $coupon);

        return back()->with('success', 'Voucher diperbarui.');
    }

    public function destroy(Coupon $coupon)
    {
        if ($coupon->orders()->exists()) {
            $coupon->update(['is_active' => false]);

            return back()->with('success', 'Voucher sudah dipakai order, jadi hanya dinonaktifkan.');
        }

        $code = $coupon->code;
        $coupon->delete();

        ActivityLog::record('coupon.deleted', 'Voucher '.$code.' dihapus');

        return back()->with('success', "Voucher {$code} dihapus.");
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'min:3', 'max:40', 'alpha_dash',
                Rule::unique('coupons', 'code')->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:160'],
            'type' => ['required', 'in:fixed,percent'],
            'value' => ['required', 'integer', 'min:1', 'max:10000000'],
            'min_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'max_discount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'usage_limit' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'per_buyer_limit' => ['nullable', 'integer', 'min:0', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($data['type'] === Coupon::TYPE_PERCENT && $data['value'] > 100) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'value' => 'Potongan persen tidak boleh lebih dari 100%.',
            ]);
        }

        $data['code'] = strtoupper(trim($data['code']));
        $data['min_amount'] = $data['min_amount'] ?? 0;
        $data['max_discount'] = $data['max_discount'] ?? 0;
        $data['usage_limit'] = $data['usage_limit'] ?? 0;
        $data['per_buyer_limit'] = $data['per_buyer_limit'] ?? 1;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
