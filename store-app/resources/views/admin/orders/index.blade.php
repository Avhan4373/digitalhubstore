@extends('layouts.admin')

@section('title', 'Pesanan')
@section('heading', 'Pesanan')
@section('subheading', 'Belum bayar: '.$summary['pending'].' · perlu dikirim: '.$summary['awaiting'].' · hari ini: '.$summary['today'])

@section('content')
    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-48 flex-1">
            <label class="label" for="q">Cari</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="field" placeholder="invoice / nama / WhatsApp / email">
        </div>
        <div>
            <label class="label" for="bayar">Pembayaran</label>
            <select id="bayar" name="bayar" class="field w-40">
                <option value="">Semua</option>
                @foreach(['pending' => 'Menunggu', 'paid' => 'Lunas', 'expired' => 'Kedaluwarsa', 'failed' => 'Gagal', 'refunded' => 'Refund'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['bayar'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select id="status" name="status" class="field w-40">
                <option value="">Semua</option>
                @foreach(['new' => 'Baru', 'processing' => 'Diproses', 'delivered' => 'Terkirim', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-secondary">Filter</button>

        <button form="expire-form" class="btn-ghost ml-auto text-amber-700 hover:bg-amber-50">
            Bersihkan order kedaluwarsa
        </button>
    </form>

    <form id="expire-form" action="{{ route('admin.orders.expire') }}" method="POST" class="hidden"
          data-confirm="Tandai semua order yang lewat batas bayar sebagai kedaluwarsa dan kembalikan stoknya?">
        @csrf
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Pembeli</th>
                    <th class="text-center">Item</th>
                    <th class="text-right">Total</th>
                    <th>Bayar</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    <tr class="{{ $order->bisaDibayar() ? 'bg-amber-50/40 hover:bg-amber-50' : '' }}">
                        <td>
                            <a href="{{ route('admin.orders.show', $order->invoice_no) }}"
                               class="font-mono text-xs font-semibold text-brand-600 hover:underline">{{ $order->invoice_no }}</a>
                            <p class="text-[11px] text-slate-400">{{ $order->created_at->format('d/m/Y H:i') }}</p>
                        </td>
                        <td>
                            <p class="text-sm font-medium">{{ $order->buyer_name }}</p>
                            <p class="text-[11px] text-slate-400">{{ wa_display($order->buyer_wa) }}</p>
                        </td>
                        <td class="text-center text-sm">{{ $order->items_count }}</td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ rupiah($order->total) }}</td>
                        <td>
                            @if($order->isCancelled())
                                {{-- Pesanan batal: kolom bayar dikosongkan supaya tidak
                                     tampil "Dibatalkan" dua kali di baris yang sama. --}}
                                <span class="text-xs text-slate-400">—</span>
                            @else
                                <span class="badge {{ $order->paymentBadge() }}">{{ $order->paymentLabel() }}</span>
                            @endif
                        </td>
                        <td><span class="badge {{ status_badge($order->order_status) }}">{{ $order->statusLabel() }}</span></td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.orders.show', $order->invoice_no) }}" class="btn-secondary btn-sm">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center text-slate-500">Tidak ada pesanan yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $orders->links() }}</div>
@endsection
