@extends('layouts.admin')

@section('title', 'Stok '.$variant->name)
@section('heading', 'Stok: '.$variant->product->name)
@section('subheading', 'Varian '.$variant->name.' · '.rupiah($variant->price).' · mode '.$variant->deliveryLabel())

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="card-pad">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Tambah stok massal</h2>
                <p class="hint">
                    Satu baris = satu akun/kode. Setiap baris <span class="font-semibold">langsung dienkripsi</span>
                    sebelum disimpan, jadi tidak ada kredensial yang tersimpan polos.
                </p>

                <form action="{{ route('admin.variants.stock.store', $variant) }}" method="POST" class="mt-3">
                    @csrf
                    <textarea name="payloads" rows="8" class="field font-mono text-xs" required maxlength="200000"
                              placeholder="email@contoh.com|password123&#10;email2@contoh.com|password456"></textarea>
                    @error('payloads')<p class="error-text">{{ $message }}</p>@enderror

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button class="btn-primary">Impor stok</button>
                        <span class="hint">
                            Duplikat otomatis dilewati (dicek lewat hash payload). Menambah stok juga
                            mengubah mode varian ini menjadi <span class="font-semibold">otomatis</span>.
                        </span>
                    </div>
                </form>
            </div>

            <div class="card-pad">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Daftar stok</h2>
                    <span class="text-xs text-slate-400">{{ $items->total() }} baris</span>
                </div>

                <div class="table-wrap mt-3">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Status</th>
                                <th>Terkait order</th>
                                <th>Dibuat</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($items as $item)
                                <tr>
                                    <td class="font-mono text-xs">{{ $item->id }}</td>
                                    <td>
                                        <span class="badge {{ status_badge($item->status) }}">{{ $item->status }}</span>
                                    </td>
                                    <td class="text-xs text-slate-500">
                                        @if($item->order_item_id)
                                            <a href="{{ route('admin.orders.index') }}" class="text-brand-600 hover:underline">
                                                item #{{ $item->order_item_id }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-xs text-slate-500">{{ $item->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="text-right">
                                        @if($item->status !== \App\Models\StockItem::STATUS_DELIVERED)
                                            <form action="{{ route('admin.stock.destroy', $item) }}" method="POST"
                                                  data-confirm="Hapus stok #{{ $item->id }}?">
                                                @csrf @method('DELETE')
                                                <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Hapus</button>
                                            </form>
                                        @else
                                            <span class="text-[11px] text-slate-400">terkunci</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-8 text-center text-slate-500">Belum ada stok untuk varian ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $items->links() }}</div>

                <p class="hint mt-3">
                    Isi stok tidak ditampilkan di daftar — admin hanya melihat status. Untuk memeriksa isi satu
                    item (mis. sengketa garansi), lihat halaman detail pesanan terkait yang menampilkan
                    apa yang benar-benar diterima pembeli.
                </p>
            </div>
        </div>

        <div class="space-y-4">
            <div class="card-pad">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Ringkasan stok</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li class="flex items-center justify-between">
                        <span class="text-slate-600">Tersedia</span>
                        <span class="font-bold text-emerald-600">{{ $counts['available'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-slate-600">Direservasi (order pending)</span>
                        <span class="font-bold text-amber-600">{{ $counts['reserved'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-slate-600">Sudah terkirim</span>
                        <span class="font-bold text-slate-700">{{ $counts['delivered'] }}</span>
                    </li>
                    <li class="flex items-center justify-between border-t border-slate-100 pt-2">
                        <span class="text-slate-600">Angka stok situs asal</span>
                        <span class="text-slate-500">{{ number_format($variant->stock_original, 0, ',', '.') }}</span>
                    </li>
                </ul>
                <p class="hint mt-3">
                    Angka "stok situs asal" adalah nilai marketing dari situs lama (sering diisi ribuan slot
                    placeholder). Stok nyata di sistem ini hanya menghitung item yang benar-benar tersedia.
                </p>
            </div>

            <div class="card-pad">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Status varian</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Tipe pengiriman: <span class="font-semibold">{{ $variant->delivery_type }}</span>
                </p>
                @if(! $variant->requiresStock())
                    <p class="mt-2 rounded-xl bg-amber-50 p-3 text-xs text-amber-800 ring-1 ring-amber-100">
                        Varian ini bertipe <span class="font-semibold">{{ $variant->delivery_type }}</span>, jadi tidak
                        memakai inventaris. Mengimpor stok di atas akan mengubahnya menjadi otomatis.
                    </p>
                @endif
                <a href="{{ route('admin.products.edit', $variant->product) }}" class="btn-secondary mt-3 w-full">Kembali ke produk</a>
            </div>
        </div>
    </div>
@endsection
