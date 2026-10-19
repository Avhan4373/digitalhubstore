{{--
    Satu baris produk di daftar admin.

    Dipakai dua kali:
    - `index.blade.php` saat halaman dimuat;
    - jawaban JSON dari aksi cepat (`products.status`, `products.restore`,
      `variants.quick`) supaya halaman bisa memperbarui baris tanpa reload.
      Lihat `initAjaxForms()` di `resources/js/app.js`.

    Karena itu setiap form di sini memakai `data-ajax="row"`, dan potongan yang
    aman diganti ditandai `data-product-…` / `data-variant-row`.
    Harapan variabel: `$product` (relasi `category` + `variants` dengan
    `available_count` sudah dimuat) dan `$sampah`.
--}}
@php($variants = $product->variants)
@php($aktif = $variants->where('is_active', true))
@php($harga = $aktif->pluck('price')->filter(fn ($p) => (int) $p > 0))
@php($min = $harga->min())
@php($max = $harga->max())
@php($stokHabis = $aktif->filter(fn ($v) => $v->requiresStock() && (int) $v->available_count === 0)->count())

<article class="card overflow-hidden"
         data-product-row="{{ $product->id }}"
         data-product-status="{{ $product->is_active ? '1' : '0' }}"
         data-product-trashed="{{ $sampah ? '1' : '0' }}">
    {{-- Satu baris sejak sm: identitas boleh menyusut (truncate) tapi
         ringkasan + tombol Edit tidak pernah pindah baris. --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2.5 p-3 sm:grid sm:grid-cols-[auto_minmax(8rem,1fr)_auto_auto] sm:items-center sm:gap-x-3">
        {{-- Sampul + identitas --}}
        @if($sampah)
            <span class="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
                @if($product->cover_image)
                    <img src="{{ $product->coverUrl() }}" alt="" class="h-full w-full object-cover opacity-50">
                @else
                    <i data-lucide="package" class="h-5 w-5 text-slate-300"></i>
                @endif
            </span>
        @else
            <a href="{{ route('admin.products.edit', $product) }}" class="block h-12 w-12 shrink-0" aria-label="Edit {{ $product->name }}">
                @if($product->cover_image)
                    <img src="{{ $product->coverUrl() }}" alt="" class="h-12 w-12 rounded-xl object-cover ring-1 ring-slate-200 transition hover:ring-brand-300">
                @else
                    <span class="grid h-12 w-12 place-items-center rounded-xl bg-slate-100 text-slate-300 ring-1 ring-slate-200">
                        <i data-lucide="package" class="h-5 w-5"></i>
                    </span>
                @endif
            </a>
        @endif

        <div class="min-w-0 flex-1 basis-40" data-product-identitas>
            <div class="flex flex-wrap items-center gap-1.5">
                @if($sampah)
                    <span class="min-w-12 truncate text-sm font-bold text-slate-500">{{ $product->name }}</span>
                @else
                    <a href="{{ route('admin.products.edit', $product) }}"
                       class="min-w-12 truncate text-sm font-bold text-slate-900 transition hover:text-brand-600">{{ $product->name }}</a>
                @endif

                @if(! $product->is_active)
                    <span class="badge bg-slate-100 text-slate-600 ring-slate-200">Nonaktif</span>
                @endif
                @if($product->is_best_seller)
                    <span class="badge bg-amber-50 text-amber-700 ring-amber-200" title="Terlaris">
                        <i data-lucide="star" class="h-2.5 w-2.5"></i><span class="hidden xl:inline">Terlaris</span>
                    </span>
                @endif
                @if($product->is_hot)
                    <span class="badge bg-rose-50 text-rose-700 ring-rose-200" title="Hot">
                        <i data-lucide="flame" class="h-2.5 w-2.5"></i><span class="hidden xl:inline">Hot</span>
                    </span>
                @endif
            </div>

            <p class="mt-0.5 truncate text-[11px] text-slate-400">
                {{ $product->category?->name ?? 'Tanpa kategori' }} · <span class="font-mono">{{ $product->slug }}</span>
            </p>

            @if($stokHabis > 0)
                <p class="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-rose-600">
                    <i data-lucide="triangle-alert" class="h-3 w-3"></i>
                    {{ $stokHabis }} varian otomatis kehabisan stok
                </p>
            @endif
        </div>

        {{-- Ringkasan angka --}}
        <dl class="flex w-full shrink-0 items-center gap-4 sm:w-auto sm:gap-4" data-product-ringkasan>
            <div class="min-w-0">
                <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Harga</dt>
                <dd class="whitespace-nowrap text-xs font-semibold {{ $min ? 'text-slate-800' : 'text-amber-600' }}">
                    @if($min)
                        {{ rupiah($min) }}@if($max > $min)<span class="text-slate-400"> – </span>{{ rupiah($max) }}@endif
                    @else
                        Belum diset
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Varian</dt>
                <dd class="text-xs font-semibold text-slate-800">
                    {{ $aktif->count() }}
                    @if($variants->count() > $aktif->count())
                        <span class="font-normal text-slate-400">/ {{ $variants->count() }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Terjual</dt>
                <dd class="text-xs font-semibold text-slate-800">{{ number_format($product->sold_count, 0, ',', '.') }}</dd>
            </div>
        </dl>

        {{-- Aksi cepat --}}
        <div class="ml-auto flex w-full shrink-0 items-center justify-end gap-1.5 sm:w-auto" data-product-aksi>
            @if($sampah)
                <form action="{{ route('admin.products.restore', $product->id) }}" method="POST" data-ajax="row">
                    @csrf
                    <button class="btn-secondary btn-sm">
                        <i data-lucide="rotate-ccw" class="h-3.5 w-3.5"></i> Pulihkan
                    </button>
                </form>
            @else
                <form action="{{ route('admin.products.status', $product) }}" method="POST" data-ajax="row">
                    @csrf
                    <input type="hidden" name="is_active" value="{{ $product->is_active ? 0 : 1 }}">
                    <button class="btn-secondary btn-sm {{ $product->is_active ? '' : 'text-emerald-700' }}"
                            title="{{ $product->is_active ? 'Sembunyikan dari toko' : 'Tampilkan di toko' }}">
                        <i data-lucide="{{ $product->is_active ? 'eye-off' : 'eye' }}" class="h-3.5 w-3.5"></i>
                        <span class="hidden xl:inline">{{ $product->is_active ? 'Sembunyikan' : 'Tampilkan' }}</span>
                    </button>
                </form>

                <a href="{{ route('products.show', $product->slug) }}" target="_blank" rel="noopener"
                   class="btn-secondary btn-sm" title="Lihat di toko">
                    <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
                    <span class="hidden xl:inline">Lihat</span>
                </a>

                <a href="{{ route('admin.products.edit', $product) }}" class="btn-primary btn-sm">
                    <i data-lucide="pencil" class="h-3.5 w-3.5"></i> Edit
                </a>
            @endif
        </div>
    </div>

    {{-- Kelola varian langsung dari daftar: harga & status jual tanpa membuka
         form edit. Panel tetap terbuka setelah menyimpan karena aksi cepat
         tidak lagi memuat ulang halaman (lihat initAjaxForms() di app.js). --}}
    <details data-variant-panel="{{ $product->id }}" class="group border-t border-slate-100">
        <summary data-product-panel-bar
                 class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-xs font-bold text-slate-500 transition hover:bg-slate-50 sm:px-4">
            <i data-lucide="chevron-down" class="h-3.5 w-3.5 transition group-open:rotate-180"></i>
            Kelola varian
            <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">{{ $variants->count() }}</span>
            <span class="ml-auto font-normal text-slate-400">{{ $aktif->count() }} dijual</span>
        </summary>

        <div class="border-t border-slate-100 bg-slate-50/50">
            @forelse($variants as $variant)
                <div class="border-b border-slate-100 px-3 py-2.5 last:border-b-0 sm:px-4"
                     data-variant-row="{{ $variant->id }}">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div class="min-w-0 flex-1 basis-40">
                            <p class="truncate text-sm font-semibold {{ $variant->is_active ? 'text-slate-800' : 'text-slate-400 line-through' }}">
                                {{ $variant->name }}
                            </p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-slate-400">
                                <span>{{ $variant->deliveryLabel() }}</span>
                                @if($variant->hasDiscount())
                                    <span>· sebelum {{ rupiah($variant->original_price) }}</span>
                                @endif
                                @if($variant->requiresStock())
                                    <span class="badge {{ $variant->available_count > 0 ? 'bg-brand-50 text-brand-700 ring-brand-100' : 'bg-rose-50 text-rose-700 ring-rose-100' }}">
                                        stok {{ $variant->available_count }}
                                    </span>
                                @endif
                            </p>
                        </div>

                        <form action="{{ route('admin.variants.quick', $variant) }}" method="POST"
                              class="flex items-center gap-1.5" data-ajax="row">
                            @csrf @method('PUT')
                            <label class="sr-only" for="harga-{{ $variant->id }}">Harga {{ $variant->name }}</label>
                            <input id="harga-{{ $variant->id }}" name="price" type="number" min="0" step="500"
                                   value="{{ $variant->price }}" title="Harga jual (Rp)"
                                   class="field w-28 px-2 py-1.5 text-right text-xs font-semibold">
                            <label class="flex cursor-pointer items-center gap-1 text-[11px] font-semibold text-slate-500"
                                   title="Varian aktif dijual">
                                <input type="checkbox" name="is_active" value="1" @checked($variant->is_active)
                                       class="h-3.5 w-3.5 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                                jual
                            </label>
                            <button class="btn-secondary btn-sm" title="Simpan harga & status varian">Simpan</button>
                        </form>

                        <div class="flex items-center gap-1">
                            <a href="{{ route('admin.variants.stock', $variant) }}" class="btn-ghost btn-sm" title="Kelola stok">
                                <i data-lucide="boxes" class="h-3.5 w-3.5"></i>
                                <span class="hidden sm:inline">Stok</span>
                            </a>
                            <a href="{{ route('admin.products.edit', ['product' => $product, 'varian' => $variant->id]) }}#varian-{{ $variant->id }}"
                               class="btn-ghost btn-sm" title="Edit lengkap varian ini">
                                <i data-lucide="pencil" class="h-3.5 w-3.5"></i>
                                <span class="hidden sm:inline">Detail</span>
                            </a>
                        </div>
                    </div>
                </div>
            @empty
                <p class="px-4 py-4 text-xs text-slate-500">
                    Belum ada varian. Produk tanpa varian tidak bisa dibeli di toko.
                </p>
            @endforelse

            @unless($sampah)
                <div class="flex items-center justify-between gap-2 px-3 py-2 sm:px-4">
                    <p class="text-[11px] text-slate-400">
                        Order lama tetap memakai harga saat dibeli.
                    </p>
                    <a href="{{ route('admin.products.edit', $product) }}#tambah-varian"
                       class="btn-ghost btn-sm text-brand-600 hover:bg-brand-50">
                        <i data-lucide="plus" class="h-3.5 w-3.5"></i> Tambah varian
                    </a>
                </div>
            @endunless
        </div>
    </details>
</article>
