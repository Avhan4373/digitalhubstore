@extends('layouts.admin')

@section('title', 'Produk')
@section('heading', 'Produk & Varian')
@section('subheading', $products->total().' produk terdaftar')

@section('content')
    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-48 flex-1">
            <label class="label" for="q">Cari</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="field" placeholder="nama produk">
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
            <label class="label" for="status">Status</label>
            <select id="status" name="status" class="field w-36">
                <option value="">Semua</option>
                <option value="aktif" @selected(($filters['status'] ?? null) === 'aktif')>Aktif</option>
                <option value="nonaktif" @selected(($filters['status'] ?? null) === 'nonaktif')>Nonaktif</option>
            </select>
        </div>
        <button class="btn-secondary">Filter</button>
        <a href="{{ route('admin.products.create') }}" class="btn-primary ml-auto">+ Produk baru</a>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>Kategori</th>
                    <th class="text-center">Varian</th>
                    <th class="text-right">Rentang harga</th>
                    <th class="text-center">Terjual</th>
                    <th class="text-center">Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                    @php($variants = $product->activeVariants()->get())
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                @if($product->cover_image)
                                    <img src="{{ $product->coverUrl() }}" alt="" class="h-10 w-10 rounded-lg object-cover ring-1 ring-slate-200">
                                @else
                                    <span class="grid h-10 w-10 place-items-center rounded-lg bg-slate-100 text-xs font-bold text-slate-400">?</span>
                                @endif
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-800">{{ $product->name }}</p>
                                    <p class="truncate font-mono text-[11px] text-slate-400">{{ $product->slug }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="text-sm">{{ $product->category?->name ?? '—' }}</td>
                        <td class="text-center text-sm">{{ $variants->count() }}</td>
                        <td class="whitespace-nowrap text-right text-sm">
                            @php($min = $variants->min('price'))
                            @php($max = $variants->max('price'))
                            @if($min)
                                {{ rupiah($min) }}@if($max > $min) – {{ rupiah($max) }}@endif
                            @else
                                <span class="text-slate-400">belum diset</span>
                            @endif
                        </td>
                        <td class="text-center text-sm">{{ number_format($product->sold_count, 0, ',', '.') }}</td>
                        <td class="text-center">
                            <span class="badge {{ $product->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.products.edit', $product) }}" class="btn-secondary btn-sm">Kelola</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center text-slate-500">Tidak ada produk yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
@endsection
