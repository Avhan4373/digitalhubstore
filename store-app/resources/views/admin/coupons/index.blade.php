@extends('layouts.admin')

@section('title', 'Voucher')
@section('heading', 'Voucher & Kupon')
@section('subheading', 'Potongan dihitung ulang di server saat checkout')

@section('content')
    @if($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-rose-200">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Potongan</th>
                            <th>Min. belanja</th>
                            <th class="text-center">Pemakaian</th>
                            <th>Periode</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($coupons as $coupon)
                            <tr>
                                <td>
                                    <p class="font-mono text-sm font-bold text-slate-800">{{ $coupon->code }}</p>
                                    @if($coupon->description)
                                        <p class="text-[11px] text-slate-400">{{ $coupon->description }}</p>
                                    @endif
                                </td>
                                <td class="text-sm">
                                    {{ $coupon->type === \App\Models\Coupon::TYPE_PERCENT ? $coupon->value.'%' : rupiah($coupon->value) }}
                                    @if($coupon->type === \App\Models\Coupon::TYPE_PERCENT && $coupon->max_discount > 0)
                                        <span class="block text-[11px] text-slate-400">maks {{ rupiah($coupon->max_discount) }}</span>
                                    @endif
                                </td>
                                <td class="text-sm">{{ $coupon->min_amount > 0 ? rupiah($coupon->min_amount) : '—' }}</td>
                                <td class="text-center text-sm">
                                    {{ $coupon->orders_count }}
                                    @if($coupon->usage_limit > 0)
                                        <span class="text-slate-400">/ {{ $coupon->usage_limit }}</span>
                                    @else
                                        <span class="text-slate-400">/ ∞</span>
                                    @endif
                                </td>
                                <td class="text-xs text-slate-500">
                                    @if($coupon->starts_at || $coupon->expires_at)
                                        {{ $coupon->starts_at?->format('d/m/y') ?? '—' }}
                                        &rarr;
                                        {{ $coupon->expires_at?->format('d/m/y') ?? '—' }}
                                    @else
                                        tanpa batas
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $coupon->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                        {{ $coupon->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <form action="{{ route('admin.coupons.destroy', $coupon) }}" method="POST"
                                          data-confirm="Hapus voucher {{ $coupon->code }}?">
                                        @csrf @method('DELETE')
                                        <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Hapus</button>
                                    </form>
                                </td>
                            </tr>

                            <tr class="bg-slate-50/60">
                                <td colspan="7">
                                    <details>
                                        <summary class="cursor-pointer text-xs font-semibold text-slate-500">Ubah voucher {{ $coupon->code }}</summary>
                                        <form action="{{ route('admin.coupons.update', $coupon) }}" method="POST"
                                              class="mt-3 grid gap-3 sm:grid-cols-3">
                                            @csrf @method('PUT')
                                            <div>
                                                <label class="label">Kode</label>
                                                <input name="code" value="{{ $coupon->code }}" class="field" required maxlength="40">
                                            </div>
                                            <div>
                                                <label class="label">Tipe</label>
                                                <select name="type" class="field">
                                                    <option value="fixed" @selected($coupon->type === 'fixed')>Nominal (Rp)</option>
                                                    <option value="percent" @selected($coupon->type === 'percent')>Persen (%)</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="label">Nilai</label>
                                                <input name="value" type="number" min="1" value="{{ $coupon->value }}" class="field" required>
                                            </div>
                                            <div>
                                                <label class="label">Min. belanja</label>
                                                <input name="min_amount" type="number" min="0" value="{{ $coupon->min_amount }}" class="field">
                                            </div>
                                            <div>
                                                <label class="label">Maks. potongan (%)</label>
                                                <input name="max_discount" type="number" min="0" value="{{ $coupon->max_discount }}" class="field">
                                            </div>
                                            <div>
                                                <label class="label">Kuota total (0 = tak terbatas)</label>
                                                <input name="usage_limit" type="number" min="0" value="{{ $coupon->usage_limit }}" class="field">
                                            </div>
                                            <div>
                                                <label class="label">Batas per pembeli</label>
                                                <input name="per_buyer_limit" type="number" min="0" value="{{ $coupon->per_buyer_limit }}" class="field">
                                            </div>
                                            <div>
                                                <label class="label">Mulai</label>
                                                <input name="starts_at" type="datetime-local" value="{{ $coupon->starts_at?->format('Y-m-d\TH:i') }}" class="field">
                                            </div>
                                            <div>
                                                <label class="label">Berakhir</label>
                                                <input name="expires_at" type="datetime-local" value="{{ $coupon->expires_at?->format('Y-m-d\TH:i') }}" class="field">
                                            </div>
                                            <div class="sm:col-span-3">
                                                <label class="label">Deskripsi</label>
                                                <input name="description" value="{{ $coupon->description }}" class="field" maxlength="160">
                                            </div>
                                            <div class="sm:col-span-3 flex items-center gap-4">
                                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                                    <input type="checkbox" name="is_active" value="1" @checked($coupon->is_active)
                                                           class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                                                    Aktif
                                                </label>
                                                <button class="btn-primary btn-sm">Simpan</button>
                                            </div>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $coupons->links() }}</div>
        </div>

        <div class="card-pad">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Voucher baru</h2>

            <form action="{{ route('admin.coupons.store') }}" method="POST" class="mt-3 space-y-3">
                @csrf
                <div>
                    <label class="label">Kode</label>
                    <input name="code" class="field font-mono uppercase" required maxlength="40" placeholder="HEMAT10">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Tipe</label>
                        <select name="type" class="field">
                            <option value="fixed">Nominal (Rp)</option>
                            <option value="percent">Persen (%)</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Nilai</label>
                        <input name="value" type="number" min="1" class="field" required>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Min. belanja</label>
                        <input name="min_amount" type="number" min="0" value="0" class="field">
                    </div>
                    <div>
                        <label class="label">Maks potongan (%)</label>
                        <input name="max_discount" type="number" min="0" value="0" class="field">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Kuota (0 = ∞)</label>
                        <input name="usage_limit" type="number" min="0" value="0" class="field">
                    </div>
                    <div>
                        <label class="label">Per pembeli</label>
                        <input name="per_buyer_limit" type="number" min="0" value="1" class="field">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Mulai</label>
                        <input name="starts_at" type="datetime-local" class="field">
                    </div>
                    <div>
                        <label class="label">Berakhir</label>
                        <input name="expires_at" type="datetime-local" class="field">
                    </div>
                </div>
                <div>
                    <label class="label">Deskripsi</label>
                    <input name="description" class="field" maxlength="160" placeholder="Potongan 10% maks Rp 20.000">
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="is_active" value="1" checked
                           class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                    Aktifkan sekarang
                </label>
                <button class="btn-primary w-full">Buat voucher</button>
            </form>

            <p class="hint mt-3">
                Potongan tidak pernah melebihi nominal belanja, dan batas pemakaian per pembeli dihitung
                dari nomor WhatsApp yang dipakai saat memesan.
            </p>
        </div>
    </div>
@endsection
