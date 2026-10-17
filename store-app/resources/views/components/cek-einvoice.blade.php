{{--
    Lembar "E-Invoice Pembelian" — salinan markup (inline style) situs asal.

    Hanya memuat data pembelian: nomor invoice, tanggal, metode, status,
    identitas pembeli (email & WhatsApp DISAMARKAN karena nomor WA adalah
    kunci membuka isi pesanan), dan daftar produk. Isi pesanan/kredensial
    TIDAK pernah dirender di sini.
--}}
@props(['order'])

@php
    $tanggal = $order->created_at->translatedFormat('d M Y').', '.$order->created_at->format('H.i');
    $metode = $order->payment_method === \App\Models\Order::METHOD_TRIPAY ? 'Gateway otomatis' : 'QRIS manual';
    $lunas = $order->isPaid();
    $gagal = in_array($order->payment_status, [
        \App\Models\Order::PAYMENT_FAILED,
        \App\Models\Order::PAYMENT_EXPIRED,
    ], true);

    [$badgeBg, $badgeFg, $badgeBorder] = match (true) {
        $lunas => ['#dcfce7', '#16a34a', '#bbf7d0'],
        $gagal => ['#ffe4e6', '#be123c', '#fecdd3'],
        default => ['#fef3c7', '#b45309', '#fde68a'],
    };
@endphp

<div style="border-bottom: 2px solid #f5125e; padding-bottom: 12px; margin-bottom: 15px;">
    <h2 style="color: #f5125e; margin: 0; font-size: 20px; font-weight: 900; letter-spacing: -0.5px; text-transform: uppercase;">
        {{ \App\Models\Setting::storeName() }}
    </h2>
    <p style="margin: 2px 0 0; font-size: 11px; color: #64748b; font-weight: bold;">E-Invoice Pembelian Resmi</p>
</div>

<div style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 11px;">
    <div>
        <p style="margin: 0; color: #94a3b8; text-transform: uppercase;">No. Invoice</p>
        <p style="margin: 2px 0 8px; font-weight: bold; font-family: monospace; font-size: 12px;">{{ $order->invoice_no }}</p>
        <p style="margin: 0; color: #94a3b8; text-transform: uppercase;">Tanggal Pesanan</p>
        <p style="margin: 2px 0 0; font-weight: bold;">{{ $tanggal }}</p>
    </div>
    <div style="text-align: right;">
        <p style="margin: 0; color: #94a3b8; text-transform: uppercase;">Metode Pembayaran</p>
        <p style="margin: 2px 0 8px; font-weight: bold;">{{ $metode }}</p>
        <p style="margin: 0; color: #94a3b8; text-transform: uppercase;">Status</p>
        <span style="display: inline-block; background: {{ $badgeBg }}; color: {{ $badgeFg }}; padding: 2px 8px; border-radius: 6px; font-weight: bold; font-size: 10px; border: 1px solid {{ $badgeBorder }};">
            {{ $lunas ? 'SUKSES / LUNAS' : mb_strtoupper($order->paymentLabel()) }}
        </span>
    </div>
</div>

<div style="background: #f8fafc; padding: 12px; border-radius: 12px; border: 1px solid #e2e8f0; font-size: 11px; margin-bottom: 15px;">
    <p style="margin: 0 0 6px; font-weight: bold; color: #334155;">Data Pembeli</p>
    <div style="display: grid; grid-template-columns: 80px 1fr; gap: 4px;">
        <span style="color: #64748b;">Nama</span><span style="font-weight: 600;">: {{ $order->buyer_name }}</span>
        <span style="color: #64748b;">Email</span><span style="font-weight: 600;">: {{ mask_email($order->buyer_email) }}</span>
        <span style="color: #64748b;">WhatsApp</span><span style="font-weight: 600;">: {{ mask_wa($order->buyer_wa) }}</span>
    </div>
</div>

<table style="width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 11.5px;">
    <thead>
        <tr style="background: #f5125e; color: white;">
            <th style="padding: 8px 10px; text-align: left; border-radius: 6px 0 0 6px;">Produk</th>
            <th style="padding: 8px 10px; text-align: right; border-radius: 0 6px 6px;">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($order->items as $item)
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;">
                    <b>{{ $item->product_name }}</b><br>
                    <span style="color: #64748b; font-size: 10.5px;">
                        Variasi: {{ $item->variant_name }}@if($item->qty > 1) &times; {{ $item->qty }}@endif
                    </span>
                </td>
                <td style="padding: 10px; text-align: right; border-bottom: 1px solid #e2e8f0; font-weight: bold; color: #f5125e;">
                    {{ rupiah($item->subtotal) }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

@if($order->items->count() > 1 || $order->discount > 0)
    <div style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 11.5px; font-weight: bold;">
        <span style="color: #64748b;">Total Pembayaran</span>
        <span style="color: #f5125e;">{{ rupiah($order->total) }}</span>
    </div>
@endif

<div style="text-align: center; color: #94a3b8; font-size: 10px; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
    @if($lunas)
        <p style="margin: 0; font-weight: bold; color: #16a34a;">Pesanan ini sudah sukses terverifikasi &amp; bergaransi penuh.</p>
    @else
        <p style="margin: 0; font-weight: bold; color: #b45309;">Pesanan ini belum lunas. Selesaikan pembayaran agar pesanan diproses.</p>
    @endif
    <p style="margin: 6px 0 0;">Isi akun tidak ditampilkan di lembar ini demi keamanan. Buka lewat menu Lacak dengan nomor invoice + nomor WhatsApp pesanan.</p>
</div>
