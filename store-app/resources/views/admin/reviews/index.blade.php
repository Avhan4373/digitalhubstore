@extends('layouts.admin')

@section('title', 'Ulasan')
@section('heading', 'Moderasi Ulasan')
@section('subheading', 'Menunggu: '.$counts['pending'].' · tayang: '.$counts['approved'].' · ditolak: '.$counts['rejected'])

@section('content')
    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="label" for="status">Status</label>
            <select id="status" name="status" class="field w-40">
                <option value="">Semua</option>
                @foreach(['pending' => 'Menunggu', 'approved' => 'Tayang', 'rejected' => 'Ditolak'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="produk">Produk</label>
            <select id="produk" name="produk" class="field w-56">
                <option value="">Semua produk</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}" @selected(($filters['produk'] ?? null) == $product->id)>{{ $product->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="rating">Rating</label>
            <select id="rating" name="rating" class="field w-32">
                <option value="">Semua</option>
                @foreach([5, 4, 3, 2, 1] as $star)
                    <option value="{{ $star }}" @selected(($filters['rating'] ?? null) == $star)>{{ $star }} bintang</option>
                @endforeach
            </select>
        </div>
        <button class="btn-secondary">Filter</button>
    </form>

    <div class="space-y-3">
        @forelse($reviews as $review)
            <div class="card-pad">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">
                                {{ $review->initial() }}
                            </span>
                            <span class="text-sm font-semibold text-slate-800">{{ $review->user_name }}</span>
                            <x-star-rating :rating="$review->rating" />
                            <span class="badge {{ status_badge($review->status) }}">{{ $review->status }}</span>
                            @if($review->external_id)
                                <span class="badge bg-slate-100 text-slate-500 ring-slate-200">impor</span>
                            @endif
                        </div>

                        <p class="mt-1 text-xs text-slate-400">
                            <a href="{{ route('products.show', $review->product->slug) }}" target="_blank" class="text-brand-600 hover:underline">
                                {{ $review->product->name }}
                            </a>
                            · {{ $review->created_at->format('d/m/Y H:i') }}
                            @if($review->variant_name) · {{ $review->variant_name }} @endif
                            @if($review->order_id) · order #{{ $review->order_id }} @endif
                        </p>

                        {{-- di-escape: konten kiriman pembeli tidak pernah dieksekusi --}}
                        <div class="mt-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-700 ring-1 ring-slate-100">
                            {!! safe_text($review->comment) !!}
                        </div>

                        @if($review->imageUrl())
                            <a href="{{ $review->imageUrl() }}" target="_blank" rel="noopener">
                                <img src="{{ $review->imageUrl() }}" alt="Foto ulasan" class="mt-2 h-24 rounded-lg object-cover ring-1 ring-slate-200">
                            </a>
                        @endif

                        @if($review->admin_note)
                            <p class="mt-2 text-xs text-slate-500">Catatan moderator: {{ $review->admin_note }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if($review->status !== \App\Models\Review::STATUS_APPROVED)
                            <form action="{{ route('admin.reviews.approve', $review) }}" method="POST">
                                @csrf
                                <button class="btn-primary btn-sm">Setujui</button>
                            </form>
                        @endif

                        @if($review->status !== \App\Models\Review::STATUS_REJECTED)
                            <form action="{{ route('admin.reviews.reject', $review) }}" method="POST">
                                @csrf
                                <button class="btn-secondary btn-sm">Tolak</button>
                            </form>
                        @endif

                        <form action="{{ route('admin.reviews.destroy', $review) }}" method="POST"
                              data-confirm="Hapus ulasan ini permanen?">
                            @csrf @method('DELETE')
                            <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Hapus</button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="card-pad py-12 text-center text-slate-500">Tidak ada ulasan yang cocok.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $reviews->links() }}</div>

    <div class="card-pad mt-4">
        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Catatan keamanan</h2>
        <p class="hint">
            Ulasan situs asal berhasil mengeksekusi skrip (stored XSS) karena HTML-nya tidak di-escape.
            Di sini semua teks pembeli dirender lewat <code>safe_text()</code>, jadi tag HTML selalu
            ditampilkan sebagai teks biasa. Ulasan baru juga hanya bisa dikirim pembeli yang invoice-nya cocok.
        </p>
    </div>
@endsection
