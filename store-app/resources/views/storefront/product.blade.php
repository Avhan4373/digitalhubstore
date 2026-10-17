@extends('layouts.storefront')

@section('title', $product->name)
@section('meta_description', \Illuminate\Support\Str::limit(strip_tags($product->description), 150))

@section('content')
    @php
        // Galeri = cover + gambar tambahan (unik, tanpa duplikat).
        $gallery = collect([$product->coverUrl()])
            ->merge($product->images->map(fn ($image) => $image->url()))
            ->filter()
            ->unique()
            ->values();

        $variants = $product->activeVariants;
        $firstBuyable = $variants->first(fn ($variant) => $variant->isPurchasable());
        $firstStock = $firstBuyable && $firstBuyable->requiresStock() ? $firstBuyable->availableStock() : null;
    @endphp

    <div class="mx-auto w-full max-w-lg">
        {{-- Remah roti --}}
        <nav class="mb-3 flex flex-wrap items-center gap-1.5 px-1 text-[11px] text-slate-500">
            <a href="{{ route('home') }}" class="hover:text-[#f5125e]">Beranda</a>
            <span>/</span>
            <a href="{{ route('catalog') }}" class="hover:text-[#f5125e]">Produk</a>
            @if($product->category)
                <span>/</span>
                <a href="{{ route('catalog', ['kategori' => $product->category->slug]) }}" class="hover:text-[#f5125e]">{{ $product->category->name }}</a>
            @endif
        </nav>

        <div data-picker-root class="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-2xl">
            {{-- ============================ Galeri ============================ --}}
            <div class="relative w-full flex-shrink-0 bg-slate-900">
                @if($gallery->isNotEmpty())
                    <div data-slider="detail" class="product-slider-container no-scrollbar flex aspect-[16/10] w-full overflow-x-auto sm:aspect-[16/9]">
                        @foreach($gallery as $image)
                            <div class="product-slider-item flex h-full w-full flex-shrink-0 items-center justify-center p-2">
                                <img src="{{ $image }}" alt="{{ $product->name }}" class="h-full w-full rounded-xl bg-slate-900 object-contain">
                            </div>
                        @endforeach
                    </div>

                    @if($gallery->count() > 1)
                        <div data-slider-dots="detail" class="absolute inset-x-0 bottom-3 z-20 flex items-center justify-center gap-1.5">
                            @foreach($gallery as $index => $image)
                                <span data-dot class="h-2 w-2 rounded-full transition-all {{ $index === 0 ? 'w-4 bg-white' : 'bg-white/50' }}"></span>
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="flex aspect-[16/9] w-full items-center justify-center bg-slate-900">
                        <span class="text-5xl font-black text-slate-700">{{ mb_substr($product->name, 0, 1) }}</span>
                    </div>
                @endif
            </div>

            {{-- ======================= Identitas produk ======================= --}}
            <div class="flex flex-shrink-0 flex-col gap-1 border-b border-slate-100 bg-white p-4">
                <div class="flex items-center justify-between gap-2">
                    @if($product->category)
                        <span class="rounded-md bg-rose-50 px-2 py-0.5 text-[9px] font-bold uppercase text-[#f5125e]">{{ $product->category->name }}</span>
                    @endif
                    @if($product->warranty)
                        <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-600">{{ $product->warranty }}</span>
                    @endif
                </div>

                <h1 class="text-base font-black leading-tight text-slate-900 sm:text-lg">{{ $product->name }}</h1>

                <div class="mt-1 flex items-baseline gap-2">
                    <p data-price-output class="text-base font-black text-[#f5125e] sm:text-xl">
                        {{ $firstBuyable ? rupiah($firstBuyable->price) : 'Hubungi admin' }}
                    </p>
                    <p data-compare-output class="hidden text-xs font-semibold text-slate-400 line-through sm:text-sm"></p>
                </div>
            </div>

            {{-- ========================= Pilihan varian ========================= --}}
            <form id="form-beli" action="{{ route('cart.add') }}" method="POST" data-variant-picker data-max-qty="{{ \App\Services\Cart::MAX_QTY_PER_ITEM }}">
                @csrf
                <div data-variant-form data-max-qty="{{ \App\Services\Cart::MAX_QTY_PER_ITEM }}">

                    <div class="space-y-4 border-b border-slate-100 p-4 text-xs sm:text-sm">
                        @if($variants->isEmpty())
                            <p class="rounded-xl bg-amber-50 p-3 text-xs font-medium text-amber-800">
                                Belum ada varian aktif untuk produk ini. Hubungi admin untuk ketersediaan.
                            </p>
                        @else
                            <div>
                                <h4 class="mb-1.5 flex items-center justify-between text-xs font-bold text-slate-800">
                                    <span>Pilihan Varian / Layanan:</span>
                                    <span data-stock-output
                                          class="rounded-md border border-emerald-100 bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-600">
                                        {{ $firstStock !== null ? 'Tersedia '.$firstStock.' Akun Instan' : 'Stok tersedia' }}
                                    </span>
                                </h4>

                                <div class="flex flex-wrap gap-2">
                                    @foreach($variants as $variant)
                                        @php($stock = $variant->requiresStock() ? $variant->availableStock() : null)
                                        @php($disabled = ! $variant->isPurchasable())
                                        <label class="cursor-pointer">
                                            <input type="radio" name="variant_id" value="{{ $variant->id }}" class="peer sr-only"
                                                   data-price="{{ $variant->price }}"
                                                   data-compare="{{ $variant->hasDiscount() ? $variant->original_price : 0 }}"
                                                   data-stock="{{ $stock ?? '' }}"
                                                   data-limit="{{ $stock && $stock > 0 ? min($stock, \App\Services\Cart::MAX_QTY_PER_ITEM) : \App\Services\Cart::MAX_QTY_PER_ITEM }}"
                                                   data-purchasable="{{ $disabled ? '0' : '1' }}"
                                                   data-required="{{ implode(' | ', $variant->requiredFieldLabels()) }}"
                                                   data-delivery="{{ $variant->deliveryLabel() }}"
                                                   @disabled($disabled) @checked($firstBuyable && $firstBuyable->id === $variant->id)>
                                            <span class="block rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-all
                                                         hover:border-rose-200 peer-checked:border-[#f5125e] peer-checked:bg-rose-50 peer-checked:text-[#f5125e]
                                                         peer-disabled:cursor-not-allowed peer-disabled:border-slate-100 peer-disabled:bg-slate-50 peer-disabled:text-slate-400">
                                                {{ $variant->name }}
                                                @if($disabled)
                                                    <span class="ml-1 text-[9px] font-bold uppercase">habis</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>

                                <p data-variant-meta class="mt-2 text-[11px] font-medium text-amber-600"></p>
                                <p class="mt-1 text-[10px] text-slate-400">
                                    Harga &amp; ketersediaan dihitung ulang di server saat checkout.
                                </p>
                            </div>
                        @endif

                        {{-- Statistik ringkas --}}
                        <div class="flex items-center justify-around rounded-xl border border-slate-100 bg-slate-50 p-2.5 text-center">
                            <div>
                                <span class="block text-[10px] text-slate-400">Terjual</span>
                                <span class="font-bold text-slate-800">{{ $product->sold_count > 0 ? sold_label($product->sold_count) : '—' }}</span>
                            </div>
                            <div class="h-5 w-px bg-slate-200"></div>
                            <div>
                                <span class="block text-[10px] text-slate-400">Rating</span>
                                <span class="font-bold text-slate-800">
                                    <span class="text-amber-500">★</span> {{ $summary['average'] > 0 ? number_format($summary['average'], 1, ',', '.') : (trim((string) $product->rating_source) ?: '5') }}
                                </span>
                            </div>
                            <div class="h-5 w-px bg-slate-200"></div>
                            <div>
                                <span class="block text-[10px] text-slate-400">Garansi</span>
                                <span class="font-bold text-emerald-600">{{ $product->warranty ?: 'Resmi' }}</span>
                            </div>
                        </div>

                        {{-- Deskripsi --}}
                        <div>
                            <h4 class="mb-1 text-xs font-bold text-slate-800">Deskripsi Layanan</h4>
                            <div class="text-xs leading-relaxed text-slate-600">{!! safe_text($product->description) !!}</div>
                        </div>

                        @if($product->terms)
                            <div>
                                <h4 class="mb-1 text-xs font-bold text-slate-800">Syarat &amp; Ketentuan</h4>
                                <div class="whitespace-pre-line text-xs leading-relaxed text-slate-600">{!! safe_text($product->terms) !!}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </form>

                    {{-- ========================== Ulasan ========================== --}}
                    <div id="ulasan" class="space-y-3 border-b border-slate-100 p-4 pt-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="flex items-center gap-1.5 text-xs font-bold text-slate-800 sm:text-sm">
                                    <i data-lucide="star" class="h-4 w-4 fill-amber-500 text-amber-500"></i>
                                    <span>PENILAIAN PRODUK</span>
                                </h4>
                                <p class="mt-0.5 text-[10.5px] text-slate-500">
                                    ⭐ {{ number_format($summary['average'], 1, ',', '.') }} dari 5 • ({{ $summary['total'] }} ulasan terverifikasi)
                                </p>
                            </div>
                            <span class="rounded-md border border-rose-100 bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-[#f5125e]">Ulasan Terverifikasi</span>
                        </div>

                        @if($reviews->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5 text-[10px]">
                                <button type="button" data-review-filter="all"
                                        class="cursor-pointer rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-[10px] font-bold text-[#f5125e] shadow-2xs transition-all">Semua</button>
                                <button type="button" data-review-filter="star5"
                                        class="cursor-pointer rounded-lg bg-slate-100 px-2.5 py-1 text-[10px] font-medium text-slate-600 transition-all hover:bg-slate-200">Bintang 5</button>
                                <button type="button" data-review-filter="instant"
                                        class="cursor-pointer rounded-lg bg-slate-100 px-2.5 py-1 text-[10px] font-medium text-slate-600 transition-all hover:bg-slate-200">⚡ Kirim Instan</button>
                                <button type="button" data-review-filter="service"
                                        class="cursor-pointer rounded-lg bg-slate-100 px-2.5 py-1 text-[10px] font-medium text-slate-600 transition-all hover:bg-slate-200">🛡️ Garansi Aman</button>
                            </div>
                        @endif

                        {{-- Form ulasan: hanya untuk pembeli produk ini (diverifikasi nomor invoice) --}}
                        @if($canReview)
                            <div class="mt-2 space-y-2.5 rounded-2xl border border-rose-100 bg-rose-50/60 p-3">
                                <h5 class="flex items-center gap-1 text-xs font-bold text-slate-800">
                                    <i data-lucide="message-square-plus" class="h-3.5 w-3.5 text-[#f5125e]"></i> Tulis Ulasan Anda
                                </h5>

                                <form action="{{ route('reviews.store', $product->slug) }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                                    @csrf

                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <input type="text" name="user_name" value="{{ old('user_name') }}" placeholder="Nama Anda..." required maxlength="40"
                                                   class="w-full rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] focus:border-[#f5125e] focus:outline-none">
                                            @error('user_name')<p class="error-text">{{ $message }}</p>@enderror
                                        </div>
                                        <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-2.5 py-1">
                                            <span class="text-[10px] font-bold text-slate-500">Rating:</span>
                                            <select name="rating" class="bg-transparent text-xs font-bold text-amber-500 focus:outline-none" required>
                                                @foreach([5, 4, 3, 2, 1] as $star)
                                                    <option value="{{ $star }}" @selected(old('rating') == $star)>{{ str_repeat('⭐', $star) }} ({{ $star }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <input type="text" name="invoice" value="{{ old('invoice') }}" placeholder="Nomor invoice (INV...)" required maxlength="32"
                                               class="w-full rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] uppercase focus:border-[#f5125e] focus:outline-none">
                                        @error('invoice')<p class="error-text">{{ $message }}</p>@enderror
                                    </div>

                                    <textarea name="comment" rows="2" required maxlength="1000" placeholder="Bagikan pengalaman Anda menggunakan produk ini..."
                                              class="w-full resize-none rounded-xl border border-slate-200 bg-white p-2.5 text-[11px] focus:border-[#f5125e] focus:outline-none">{{ old('comment') }}</textarea>
                                    @error('comment')<p class="error-text">{{ $message }}</p>@enderror

                                    <div class="flex items-center gap-2">
                                        <label for="rev-image" class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-dashed border-slate-300 bg-white px-3 py-1.5 text-[11px] font-semibold text-slate-600 transition-colors hover:border-[#f5125e]">
                                            <i data-lucide="camera" class="h-3.5 w-3.5 text-[#f5125e]"></i>
                                            <span>Upload Foto / Bukti (Opsional)</span>
                                        </label>
                                        <input type="file" id="rev-image" name="image" accept="image/*" class="hidden">
                                    </div>
                                    @error('image')<p class="error-text">{{ $message }}</p>@enderror

                                    <button type="submit"
                                            class="flex w-full items-center justify-center gap-1.5 rounded-xl bg-[#f5125e] py-2 text-[11px] font-bold uppercase tracking-wider text-white shadow-xs transition hover:bg-[#d40c4e] active:scale-95">
                                        <i data-lucide="send" class="h-3.5 w-3.5"></i> Kirim Ulasan
                                    </button>
                                </form>
                            </div>
                        @elseif($reviews->isEmpty())
                            <p class="text-xs text-slate-500">Belum ada ulasan untuk produk ini.</p>
                        @endif

                        {{-- Daftar ulasan --}}
                        @if($reviews->isNotEmpty())
                            <div class="space-y-2.5 pt-1">
                                @foreach($reviews as $review)
                                    <x-review-item :review="$review" />
                                @endforeach
                                <p data-review-empty class="hidden text-center text-xs text-slate-500">Tidak ada ulasan pada filter ini.</p>
                            </div>
                        @endif
                    </div>

                    {{-- ==================== Bar aksi (sticky) ==================== --}}
                    <div class="sticky bottom-16 z-20 w-full rounded-b-3xl bg-white shadow-[0_-4px_10px_-1px_rgba(0,0,0,0.1)] md:bottom-0">
                        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                            <span class="text-xs font-bold text-slate-800 sm:text-sm">Jumlah Pembelian</span>
                            <div class="flex items-center overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <button type="button" data-qty-dec class="flex h-8 w-8 items-center justify-center text-slate-500 transition hover:bg-slate-100 active:bg-slate-200">
                                    <i data-lucide="minus" class="h-4 w-4"></i>
                                </button>
                                <input type="number" name="qty" data-qty form="form-beli" value="1" min="1" max="{{ \App\Services\Cart::MAX_QTY_PER_ITEM }}"
                                       class="w-10 border-x border-slate-200 bg-transparent py-1 text-center text-sm font-bold text-slate-800 [appearance:textfield] focus:outline-none">
                                <button type="button" data-qty-inc class="flex h-8 w-8 items-center justify-center text-[#f5125e] transition hover:bg-rose-50 active:bg-rose-100">
                                    <i data-lucide="plus" class="h-4 w-4"></i>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between px-4 pt-2.5 text-xs">
                            <span class="font-medium text-slate-500">Total tagihan</span>
                            <span data-total-output class="font-black text-[#f5125e]">{{ $firstBuyable ? rupiah($firstBuyable->price) : '—' }}</span>
                        </div>

                        <div class="grid grid-cols-5 gap-2 rounded-b-3xl bg-slate-50 p-3.5">
                            @php($waProduct = \App\Models\Setting::whatsappLink('Halo admin, saya mau tanya produk '.$product->name))
                            <a href="{{ $waProduct === '#' ? route('orders.track.form') : $waProduct }}"
                               @if($waProduct !== '#') target="_blank" rel="noopener" @endif
                               class="col-span-1 flex flex-col items-center justify-center rounded-xl border border-rose-200 bg-rose-50 p-2 text-[#f5125e] transition-colors hover:bg-rose-100">
                                <i data-lucide="message-square-more" class="h-4 w-4"></i>
                                <span class="text-[9px] font-bold">Chat</span>
                            </a>

                            <button type="submit" value="1" data-submit form="form-beli" @disabled($variants->isEmpty())
                                    class="col-span-2 rounded-xl border border-rose-200 bg-rose-50 py-2.5 text-xs font-bold text-[#f5125e] transition-colors hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50">
                                + Keranjang
                            </button>

                            <button type="submit" name="beli_sekarang" value="1" data-submit form="form-beli" @disabled($variants->isEmpty())
                                    class="col-span-2 cursor-pointer rounded-xl bg-gradient-to-r from-[#f5125e] to-[#ff2b56] py-2.5 text-xs font-bold text-white shadow-xs transition-transform active:scale-95 disabled:cursor-not-allowed disabled:opacity-50">
                                Beli Sekarang
                            </button>
                        </div>
                    </div>
        </div>
    </div>

    {{-- ========================= Produk serupa ========================= --}}
    @if($related->isNotEmpty())
        <div class="space-y-3 pt-2">
            <h2 class="flex items-center gap-2 px-1 text-sm font-extrabold text-slate-800 sm:text-base">
                <i data-lucide="sparkles" class="h-4 w-4 text-[#f5125e]"></i> Produk Serupa
            </h2>
            <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                @foreach($related as $item)
                    <x-product-card :product="$item" />
                @endforeach
            </div>
        </div>
    @endif
@endsection
