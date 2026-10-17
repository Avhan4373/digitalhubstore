@extends('layouts.admin')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('subheading', 'Ringkasan penjualan, pesanan, dan stok toko')

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php($kartuStatistik = [
            ['Pendapatan hari ini', rupiah($stats['revenue_today']), 'order masuk '.$stats['orders_today'], 'wallet', 'bg-emerald-50 text-emerald-600'],
            ['Pendapatan 30 hari', rupiah($stats['revenue_30d']), 'total '.rupiah($stats['revenue_all']), 'receipt', 'bg-brand-50 text-brand-600'],
            ['Menunggu bayar', (string) $stats['orders_pending'], 'perlu ditindak', 'clock', 'bg-amber-50 text-amber-600'],
            ['Perlu dikirim', (string) $stats['orders_awaiting_delivery'], 'sudah lunas, belum terkirim', 'send', 'bg-rose-50 text-rose-600'],
        ])
        @foreach($kartuStatistik as [$label, $value, $hint, $ikon, $warna])
            <div class="card-pad flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $label }}</p>
                    <p class="mt-1.5 text-2xl font-black tracking-tight text-slate-900">{{ $value }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
                </div>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $warna }}">
                    <i data-lucide="{{ $ikon }}" class="h-5 w-5"></i>
                </span>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <div class="card-pad lg:col-span-2">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Penjualan 14 hari</h2>
                <p class="text-xs text-slate-400">Konversi 30 hari: <span class="font-bold text-slate-700">{{ $stats['conversion_30d'] }}%</span></p>
            </div>

            <div class="mt-5 flex h-40 items-end gap-1.5">
                @foreach($chart as $point)
                    <div class="group relative flex flex-1 flex-col items-center justify-end">
                        <span class="pointer-events-none absolute -top-6 hidden whitespace-nowrap rounded-lg bg-slate-900 px-2 py-1 text-[10px] font-semibold text-white group-hover:block">
                            {{ rupiah_short($point['total']) }}
                        </span>
                        <div class="w-full rounded-t-md bg-brand-500/80 transition group-hover:bg-brand-600"
                             style="height: {{ max(2, round($point['total'] / $chartMax * 100)) }}%"></div>
                        <span class="mt-1.5 text-[10px] text-slate-400">{{ $point['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card-pad">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Produk terlaris 30 hari</h2>

            @if($topProducts->isEmpty())
                <p class="mt-3 text-sm text-slate-500">Belum ada penjualan lunas.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach($topProducts as $row)
                        <li>
                            <div class="flex items-baseline justify-between gap-2 text-sm">
                                <span class="min-w-0 flex-1 truncate font-medium text-slate-700">{{ $row->product_name }}</span>
                                <span class="shrink-0 font-bold text-slate-900">{{ rupiah_short($row->revenue) }}</span>
                            </div>
                            <div class="mt-1 flex items-center gap-2">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-brand-400"
                                         style="width: {{ max(3, round($row->revenue / max(1, $topProducts->max('revenue')) * 100)) }}%"></div>
                                </div>
                                <span class="shrink-0 text-[11px] text-slate-400">{{ $row->qty }} terjual</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <div class="card-pad lg:col-span-2">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Pesanan terbaru</h2>
                <a href="{{ route('admin.orders.index') }}" class="text-xs font-semibold text-brand-600 hover:underline">Semua pesanan</a>
            </div>

            <div class="table-wrap mt-3">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Pembeli</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOrders as $order)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.orders.show', $order->invoice_no) }}"
                                       class="font-mono text-xs font-semibold text-brand-600 hover:underline">
                                        {{ $order->invoice_no }}
                                    </a>
                                    <p class="text-[11px] text-slate-400">{{ $order->created_at->diffForHumans() }}</p>
                                </td>
                                <td>
                                    <p class="text-sm">{{ $order->buyer_name }}</p>
                                    <p class="text-[11px] text-slate-400">{{ wa_display($order->buyer_wa) }}</p>
                                </td>
                                <td class="whitespace-nowrap font-medium">{{ rupiah($order->total) }}</td>
                                <td>
                                    <span class="badge {{ $order->paymentBadge() }}">{{ $order->paymentLabel() }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-slate-500">Belum ada pesanan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4">
            <div class="card-pad">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Perlu perhatian</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li class="flex items-center justify-between">
                        <span class="text-slate-600">Ulasan menunggu moderasi</span>
                        <a href="{{ route('admin.reviews.index', ['status' => 'pending']) }}" class="font-bold text-brand-600">{{ $stats['reviews_pending'] }}</a>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-slate-600">Stok akun tersedia</span>
                        <span class="font-bold text-slate-800">{{ number_format($stats['stock_available'], 0, ',', '.') }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-slate-600">Produk nonaktif</span>
                        <span class="font-bold text-slate-800">{{ $stats['products_inactive'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-slate-600">Total produk</span>
                        <span class="font-bold text-slate-800">{{ $stats['products'] }}</span>
                    </li>
                </ul>
            </div>

            <div class="card-pad">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Stok menipis (mode otomatis)</h2>

                @if($stats['stock_alert']->isEmpty())
                    <p class="mt-3 text-sm text-slate-500">Semua varian otomatis masih aman.</p>
                @else
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach($stats['stock_alert'] as $variant)
                            <li class="flex items-center justify-between gap-2">
                                <a href="{{ route('admin.variants.stock', $variant) }}" class="min-w-0 flex-1 truncate text-slate-700 hover:text-brand-600">
                                    {{ $variant->product?->name }} — {{ $variant->name }}
                                </a>
                                <span class="badge shrink-0 {{ $variant->available_count === 0 ? 'bg-rose-50 text-rose-700 ring-rose-100' : 'bg-amber-50 text-amber-700 ring-amber-100' }}">
                                    {{ $variant->available_count }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if($pendingReviews->isNotEmpty())
                <div class="card-pad">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Ulasan terbaru</h2>
                    <ul class="mt-3 space-y-3">
                        @foreach($pendingReviews as $review)
                            <li class="text-sm">
                                <div class="flex items-center gap-2">
                                    <x-star-rating :rating="$review->rating" />
                                    <span class="text-xs font-semibold text-slate-600">{{ $review->user_name }}</span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($review->comment, 90) }}</p>
                                <form action="{{ route('admin.reviews.approve', $review) }}" method="POST" class="mt-1.5">
                                    @csrf
                                    <button class="btn-primary btn-sm">Setujui</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endsection
