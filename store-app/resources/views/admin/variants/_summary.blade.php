{{--
    Baris judul (badge harga/status/stok/margin/supplier) satu varian.

    Dipakai form edit produk AND jawaban JSON `variants.update`
    (`initAjaxForms()` di `resources/js/app.js`): setelah simpan, ringkasan ini
    ditukar dengan hasil render server tanpa memuat ulang halaman.
    Harapan: `$variant` (dengan `available_count` sudah dimuat).
--}}
<summary data-variant-summary="{{ $variant->id }}"
         class="flex cursor-pointer flex-wrap items-center gap-2 p-3.5">
    <span class="text-sm font-semibold text-slate-800">{{ $variant->name }}</span>
    <span class="badge bg-slate-100 text-slate-600 ring-slate-200">{{ rupiah($variant->price) }}</span>
    <span class="badge {{ $variant->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
        {{ $variant->is_active ? 'Aktif' : 'Nonaktif' }}
    </span>
    @if($variant->delivery_type === \App\Models\Variant::DELIVERY_AUTO)
        <span class="badge {{ $variant->available_count > 0 ? 'bg-brand-50 text-brand-700 ring-brand-100' : 'bg-rose-50 text-rose-700 ring-rose-100' }}">
            stok {{ $variant->available_count }}
        </span>
    @endif
    @if($variant->cost_price > 0)
        <span class="badge bg-slate-100 text-slate-600 ring-slate-200">margin {{ rupiah($variant->margin()) }}</span>
    @endif
    @if($variant->hasSupplier())
        <span class="badge bg-sky-50 text-sky-700 ring-sky-200">beli di {{ $variant->supplier_name ?: 'supplier' }}</span>
    @endif
    <a href="{{ route('admin.variants.stock', $variant) }}"
       class="ml-auto text-xs font-semibold text-brand-600 hover:underline">Kelola stok &rarr;</a>
</summary>
