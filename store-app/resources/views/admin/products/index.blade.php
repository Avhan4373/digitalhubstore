@extends('layouts.admin')

@section('title', 'Produk')
@section('heading', 'Produk & Varian')
@section('subheading')
    Menampilkan <span data-count-shown>{{ $products->count() }}</span> dari <span data-count-total>{{ $products->total() }}</span> produk
@endsection

@section('content')
    @php($pencarian = array_filter([
        'q' => $filters['q'] ?? null,
        'kategori' => $filters['kategori'] ?? null,
        'urut' => $filters['urut'] ?? null,
    ]))
    @php($saring = array_filter($pencarian + ['masalah' => $filters['masalah'] ?? null]))

    {{-- Tab status: angka langsung terlihat, sekali klik untuk berpindah. --}}
    <div class="mb-3 flex flex-wrap items-center gap-1.5">
        @foreach([
            ['', 'Semua', $counts['semua']],
            ['aktif', 'Aktif', $counts['aktif']],
            ['nonaktif', 'Nonaktif', $counts['nonaktif']],
            ['sampah', 'Sampah', $counts['sampah']],
        ] as [$nilai, $label, $jumlah])
            @php($tabAktif = ($filters['status'] ?? '') === $nilai && ! ($filters['masalah'] ?? null))
            <a href="{{ route('admin.products.index', array_filter($pencarian + ['status' => $nilai])) }}"
               class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold ring-1 transition active:scale-95 {{ $tabAktif ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' }}">
                {{ $label }}
                <span data-count="{{ $nilai ?: 'semua' }}"
                      class="rounded-full px-1.5 text-[10px] {{ $tabAktif ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500' }}">{{ $jumlah }}</span>
            </a>
        @endforeach

        {{-- Selalu dirender (disembunyikan saat 0) supaya angka & visibilitasnya
             bisa diperbarui aksi cepat tanpa reload. --}}
        <a href="{{ route('admin.products.index', array_filter(['masalah' => 'tanpa_varian'] + $saring)) }}"
           data-count-pill="tanpa_varian"
           class="items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-800 ring-1 ring-amber-200 transition hover:bg-amber-100 active:scale-95 {{ $counts['tanpa_varian'] > 0 ? 'inline-flex' : 'hidden' }}">
            <i data-lucide="triangle-alert" class="h-3.5 w-3.5"></i>
            Tanpa varian aktif
            <span data-count="tanpa_varian" class="rounded-full bg-white px-1.5 text-[10px] font-black text-amber-700 ring-1 ring-amber-200">{{ $counts['tanpa_varian'] }}</span>
        </a>
    </div>

    <form method="GET" class="card mb-4 p-3 sm:p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-52 flex-1">
                <label class="label" for="q">Cari produk</label>
                <div class="relative">
                    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
                    <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="field pl-9"
                           placeholder="nama, slug, atau deskripsi" autocomplete="off">
                </div>
            </div>
            <div>
                <label class="label" for="kategori">Kategori</label>
                <select id="kategori" name="kategori" class="field w-44">
                    <option value="">Semua kategori</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected(($filters['kategori'] ?? null) == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="urut">Urutkan</label>
                <select id="urut" name="urut" class="field w-40" data-auto-submit>
                    @foreach([
                        'urutan' => 'Urutan katalog',
                        'nama' => 'Nama A–Z',
                        'termurah' => 'Harga termurah',
                        'termahal' => 'Harga termahal',
                        'terjual' => 'Paling terjual',
                        'terbaru' => 'Terbaru',
                    ] as $nilai => $label)
                        <option value="{{ $nilai }}" @selected(($filters['urut'] ?? 'urutan') === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <input type="hidden" name="status" value="{{ $filters['status'] ?? '' }}">
            <input type="hidden" name="masalah" value="{{ $filters['masalah'] ?? '' }}">

            <button class="btn-secondary">Terapkan</button>

            @if($saring || ($filters['status'] ?? null))
                <a href="{{ route('admin.products.index') }}" class="btn-ghost">Reset</a>
            @endif

            <a href="{{ route('admin.products.create') }}" class="btn-primary w-full sm:ml-auto sm:w-auto">
                <i data-lucide="plus" class="h-4 w-4"></i> Produk baru
            </a>
        </div>
    </form>

    {{-- Baris produk dirender dari satu partial (`_row.blade.php`) supaya aksi
         cepat bisa meminta render ulang barisnya lewat JSON tanpa
         menduplikasi markup di JavaScript. --}}
    <div class="space-y-2.5"
         data-products-list
         data-status-tab="{{ $filters['status'] ?? '' }}"
         data-masalah-tab="{{ $filters['masalah'] ?? '' }}">
        @foreach($products as $product)
            @include('admin.products._row', ['product' => $product, 'sampah' => $sampah])
        @endforeach

        {{-- Keadaan kosong: dipakai saat halaman dimuat tanpa hasil, dan saat
             baris terakhir hilang karena aksi cepat (tanpa reload). --}}
        <div data-list-empty class="card-pad py-14 text-center {{ $products->isNotEmpty() ? 'hidden' : '' }}">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                <i data-lucide="{{ $saring || ($filters['status'] ?? null) ? 'search-x' : 'package' }}" class="h-6 w-6"></i>
            </span>
            <p class="mt-3 text-sm font-semibold text-slate-700">
                {{ $saring || ($filters['status'] ?? null) ? 'Tidak ada produk yang cocok.' : 'Belum ada produk.' }}
            </p>
            <p class="mt-1 text-xs text-slate-500">
                {{ $saring || ($filters['status'] ?? null) ? 'Coba ubah kata kunci atau filter.' : 'Tambahkan produk pertama untuk mulai berjualan.' }}
            </p>
            <div class="mt-4 flex justify-center gap-2">
                @if($saring || ($filters['status'] ?? null))
                    <a href="{{ route('admin.products.index') }}" class="btn-secondary btn-sm">Reset filter</a>
                @endif
                <a href="{{ route('admin.products.create') }}" class="btn-primary btn-sm">+ Produk baru</a>
            </div>
        </div>
    </div>

    <x-admin-pagination :paginator="$products" />
@endsection
