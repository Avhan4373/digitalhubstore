@extends('layouts.admin')

@section('title', 'Pesanan '.$order->invoice_no)
@section('heading', 'Pesanan '.$order->invoice_no)
@section('subheading', $order->buyer_name.' · '.wa_display($order->buyer_wa).' · '.rupiah($order->total))

@section('content')
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @unless($order->isCancelled())
            <span class="badge {{ $order->paymentBadge() }}">{{ $order->paymentLabel() }}</span>
        @endunless
        <span class="badge {{ status_badge($order->order_status) }}">{{ $order->statusLabel() }}</span>
        <span class="badge bg-white text-slate-600 ring-slate-200">
            {{ $order->payment_method === \App\Models\Order::METHOD_TRIPAY ? 'Gateway otomatis' : 'QRIS manual' }}
        </span>
        <span class="badge bg-white text-slate-600 ring-slate-200">{{ $order->created_at->format('d/m/Y H:i') }}</span>
        @if($order->gateway_reference)
            <span class="badge bg-white text-slate-600 ring-slate-200">ref {{ $order->gateway_reference }}</span>
        @endif

        <div class="ml-auto flex flex-wrap gap-2">
            @if(! $order->isPaid() && ! $order->isCancelled())
                <form action="{{ route('admin.orders.paid', $order) }}" method="POST"
                      data-confirm="Konfirmasi pembayaran pesanan ini? Produk bertipe otomatis akan langsung dikirim.">
                    @csrf
                    <button class="btn-primary btn-sm">Konfirmasi pembayaran</button>
                </form>
            @elseif($order->isPaid() && ! $order->isDelivered() && ! $order->isCancelled())
                <form action="{{ route('admin.orders.deliver', $order) }}" method="POST">
                    @csrf
                    <button class="btn-primary btn-sm">Proses pengiriman otomatis</button>
                </form>
            @endif

            @php($wa = \App\Models\Setting::whatsappLink('Halo '.$order->buyer_name.', terkait pesanan '.$order->invoice_no))
            @if($wa !== '#')
                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn-secondary btn-sm">Chat pembeli</a>
            @endif

            @php($kirimIsi = $order->buyerWhatsAppLink())
            @if($kirimIsi)
                <a href="{{ $kirimIsi }}" target="_blank" rel="noopener" class="btn-secondary btn-sm">Kirim isi akun via WA</a>
            @endif

            <a href="{{ route('orders.invoice', $order->invoice_no) }}" target="_blank" class="btn-secondary btn-sm">Invoice</a>
        </div>
    </div>

    @if($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-rose-200">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            @foreach($order->items as $item)
                <div class="card-pad">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-bold text-slate-900">{{ $item->product_name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $item->variant_name }} · {{ $item->qty }} × {{ rupiah($item->unit_price) }}
                                = <span class="font-semibold">{{ rupiah($item->subtotal) }}</span>
                            </p>
                            @if($item->variant)
                                <p class="mt-1 text-[11px] text-slate-400">
                                    Tipe pengiriman: {{ $item->variant->deliveryLabel() }}
                                </p>
                            @endif
                        </div>
                        @if($order->isCancelled())
                            <span class="badge bg-slate-100 text-slate-600 ring-slate-200">Dibatalkan</span>
                        @else
                            <span class="badge {{ $item->is_fulfilled ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200' }}">
                                {{ $item->is_fulfilled ? 'Terkirim' : 'Belum terkirim' }}
                            </span>
                        @endif
                    </div>

                    @php($deliveries = $item->deliveries)

                    @if($deliveries->isNotEmpty())
                        <div class="mt-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                Isi yang diterima pembeli ({{ $deliveries->count() }}×, sumber: {{ $deliveries->first()->source }})
                            </p>
                            @foreach($deliveries as $delivery)
                                @php($content = $delivery->contentPlain())
                                <pre class="mt-2 whitespace-pre-wrap break-all rounded-lg bg-white p-2.5 font-mono text-xs text-slate-700 ring-1 ring-slate-200">{{ $content ?? '[gagal didekripsi — kunci aplikasi mungkin berubah]' }}</pre>
                            @endforeach
                        </div>
                    @endif

                    @php($info = $item->requiredInfoPairs())
                    @if($item->hasSupplier() || $item->cost_price > 0 || $info !== [])
                        <div class="mt-3 rounded-xl bg-sky-50/70 p-3 ring-1 ring-sky-100">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-700">
                                    Beli ke supplier{{ $item->supplier_name ? ' · '.$item->supplier_name : '' }}
                                </p>
                                @if($item->supplier_url)
                                    <a href="{{ $item->supplier_url }}" target="_blank" rel="noopener noreferrer"
                                       class="text-xs font-semibold text-sky-700 hover:underline">Buka produk di supplier &rarr;</a>
                                @endif
                            </div>

                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                                @if($item->supplier_sku)
                                    <span>ID supplier: <span class="font-mono text-slate-800">{{ $item->supplier_sku }}</span>
                                        <button type="button" class="ml-1 font-semibold text-sky-700 hover:underline"
                                                data-copy="{{ $item->supplier_sku }}">salin</button>
                                    </span>
                                @endif
                                <span>Modal {{ rupiah($item->cost_price) }} × {{ $item->qty }} =
                                    <span class="font-medium text-slate-800">{{ rupiah($item->costTotal()) }}</span></span>
                                <span>Margin
                                    <span class="font-medium {{ $item->margin() >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ rupiah($item->margin()) }}</span></span>
                            </div>

                            @if($info !== [])
                                <div class="mt-2 rounded-lg bg-white p-2.5 ring-1 ring-sky-100">
                                    <div class="flex items-center justify-between">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Data dari pembeli</p>
                                        <button type="button" class="text-[11px] font-semibold text-sky-700 hover:underline"
                                                data-copy="{{ collect($info)->map(fn ($v, $k) => $k.': '.$v)->implode("\n") }}">Salin semua</button>
                                    </div>
                                    <dl class="mt-1.5 space-y-1 text-xs">
                                        @foreach($info as $label => $value)
                                            <div class="flex gap-2">
                                                <dt class="w-28 shrink-0 text-slate-500">{{ $label }}</dt>
                                                <dd class="break-all font-mono text-slate-800">{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if(! $item->is_fulfilled)
                        @php($instruction = $item->deliveryInstructionPlain())
                        @if($instruction)
                            <div class="mt-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-900 ring-1 ring-amber-100">
                                <p class="font-semibold">Instruksi ke pembeli:</p>
                                <div class="mt-1 whitespace-pre-line">{{ $instruction }}</div>
                            </div>
                        @endif

                        <form action="{{ route('admin.orders.items.complete', [$order, $item]) }}" method="POST" class="mt-3">
                            @csrf
                            <label class="label" for="content-{{ $item->id }}">
                                Tandai terkirim &amp; catat isinya (isi ini yang dilihat pembeli)
                            </label>
                            <textarea id="content-{{ $item->id }}" name="content" rows="3" class="field font-mono text-xs"
                                      required maxlength="10000"
                                      placeholder="email@contoh.com|password">{{ old('content') }}</textarea>
                            <button class="btn-primary btn-sm mt-2">Kirim &amp; catat</button>
                        </form>
                    @endif
                </div>
            @endforeach

            <div class="card-pad">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Catatan admin</h2>
                <form action="{{ route('admin.orders.note', $order) }}" method="POST" class="mt-3">
                    @csrf
                    <textarea name="admin_note" rows="3" class="field" maxlength="1000">{{ $order->admin_note }}</textarea>
                    <button class="btn-secondary btn-sm mt-2">Simpan catatan</button>
                </form>
            </div>
        </div>

        <div class="space-y-4">
            <div class="card-pad">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Ringkasan</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd class="font-medium">{{ rupiah($order->subtotal) }}</dd></div>
                    @if($order->discount > 0)
                        <div class="flex justify-between text-emerald-700">
                            <dt>Voucher {{ $order->coupon_code }}</dt><dd>-{{ rupiah($order->discount) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-bold">
                        <dt>Total</dt><dd class="text-brand-600">{{ rupiah($order->total) }}</dd>
                    </div>
                </dl>

                <dl class="mt-4 space-y-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
                    <div class="flex justify-between gap-3"><dt>Email</dt><dd class="truncate">{{ $order->buyer_email ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt>Batas bayar</dt><dd>{{ $order->expires_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt>Dibayar</dt><dd>{{ $order->paid_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt>Terkirim</dt><dd>{{ $order->delivered_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt>IP</dt><dd class="truncate">{{ $order->ip ?: '—' }}</dd></div>
                </dl>

                @if($order->buyer_note)
                    <div class="mt-3 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 ring-1 ring-slate-200">
                        <p class="font-semibold">Catatan pembeli</p>
                        <div class="mt-1">{!! safe_text($order->buyer_note) !!}</div>
                    </div>
                @endif
            </div>

            @php($costTotal = $order->items->sum(fn ($i) => $i->costTotal()))
            @php($marginTotal = $order->items->sum(fn ($i) => $i->margin()))
            @if($costTotal > 0)
                <div class="card-pad">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Modal &amp; margin</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Harga jual</dt><dd class="font-medium">{{ rupiah($order->subtotal) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Modal supplier</dt><dd class="font-medium">-{{ rupiah($costTotal) }}</dd></div>
                        <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-bold">
                            <dt>Margin kotor</dt>
                            <dd class="{{ $marginTotal >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ rupiah($marginTotal) }}</dd>
                        </div>
                    </dl>
                    <p class="hint mt-2">Dihitung dari modal yang tersimpan saat pesanan dibuat, bukan dari harga varian sekarang.</p>
                </div>
            @endif

            <div class="card-pad">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Data terenkripsi</h2>
                <p class="hint">
                    Instruksi pengiriman dan isi produk disimpan terenkripsi (AES-256 via APP_KEY).
                    Kalau APP_KEY hilang, isi lama tidak bisa dibuka — jaga file <code>.env</code>.
                </p>
            </div>

            @if(! $order->isDelivered() && ! $order->isPaid() && ! $order->isCancelled())
                <div class="card-pad">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Batalkan pesanan</h2>
                    <p class="hint">
                        Stok yang direservasi akan dikembalikan otomatis. Pembeli melihat status
                        <b>Dibatalkan</b> dan tidak bisa melanjutkan pembayaran.
                    </p>

                    @error('order')
                        <p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700 ring-1 ring-rose-200">{{ $message }}</p>
                    @enderror

                    <form action="{{ route('admin.orders.cancel', $order) }}" method="POST" class="mt-3"
                          data-confirm="Batalkan pesanan ini? Stok dikembalikan dan pembeli tidak bisa membayar lagi.">
                        @csrf
                        <label class="label" for="alasan-batal">Alasan pembatalan</label>
                        <input id="alasan-batal" name="reason" class="field" placeholder="mis. stok habis / pembeli minta batal"
                               required maxlength="200" value="{{ old('reason') }}">
                        @error('reason')
                            <p class="mt-1 text-[11px] font-medium text-rose-600">{{ $message }}</p>
                        @enderror
                        <button class="btn-danger btn-sm mt-2 w-full">Batalkan pesanan</button>
                    </form>
                </div>
            @endif

            @if($order->isCancelled())
                <div class="card-pad">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Pesanan dibatalkan</h2>
                    <p class="hint">
                        Pesanan ini sudah ditutup dan stoknya dikembalikan. Pembeli tidak bisa
                        membayar lagi; silakan arahkan untuk membuat pesanan baru.
                    </p>
                </div>
            @endif
        </div>
    </div>
@endsection
