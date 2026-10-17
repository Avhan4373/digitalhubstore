@extends('layouts.admin')

@section('title', 'Pengaturan')
@section('heading', 'Pengaturan Toko')
@section('subheading', 'Semua nilai yang dulu di-hardcode di dalam file JS')

@section('content')
    <form action="{{ route('admin.settings.update') }}" method="POST" enctype="multipart/form-data" class="max-w-3xl space-y-4">
        @csrf
        @method('PUT')

        @foreach($groups as $group => $settings)
            <div class="card-pad">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">
                    {{ match($group) {
                        'general' => 'Identitas toko',
                        'payment' => 'Pembayaran manual (QRIS)',
                        'gateway' => 'Gateway otomatis (Tripay)',
                        'storefront' => 'Perilaku toko',
                        default => $group,
                    } }}
                </h2>

                <div class="mt-4 space-y-4">
                    @foreach($settings as $setting)
                        @php($input = 'setting_'.$setting->key)
                        @php($value = $setting->is_secret ? '' : \App\Models\Setting::get($setting->key))

                        <div>
                            <label class="label" for="{{ $input }}">
                                {{ $setting->label ?: $setting->key }}
                                @if($setting->is_secret)
                                    <span class="ml-1 badge bg-rose-50 text-rose-700 ring-rose-100">rahasia</span>
                                @endif
                            </label>

                            @if($setting->type === 'bool')
                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="checkbox" id="{{ $input }}" name="{{ $input }}" value="1"
                                           @checked((bool) \App\Models\Setting::get($setting->key))
                                           class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                                    Aktifkan
                                </label>
                            @elseif($setting->type === 'file')
                                @if(\App\Models\Setting::get($setting->key))
                                    <img src="{{ storage_url(\App\Models\Setting::get($setting->key)) }}"
                                         alt="" class="mb-2 h-40 rounded-xl bg-white object-contain ring-1 ring-slate-200">
                                @endif
                                <input type="file" id="{{ $input }}" name="{{ $input }}" accept="image/*" class="field">
                                <p class="hint">Unggah untuk mengganti. Biarkan kosong kalau tidak diubah.</p>
                            @elseif($setting->key === 'payment_mode')
                                <select id="{{ $input }}" name="{{ $input }}" class="field">
                                    @foreach(['manual' => 'Manual saja (QRIS + verifikasi admin)', 'gateway' => 'Gateway saja (otomatis)', 'both' => 'Keduanya (pembeli memilih)'] as $option => $label)
                                        <option value="{{ $option }}" @selected($value === $option)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @elseif($setting->key === 'tripay_mode')
                                <select id="{{ $input }}" name="{{ $input }}" class="field">
                                    <option value="sandbox" @selected($value === 'sandbox')>Sandbox (uji coba)</option>
                                    <option value="production" @selected($value === 'production')>Production (uang nyata)</option>
                                </select>
                            @elseif($setting->type === 'text')
                                <textarea id="{{ $input }}" name="{{ $input }}" rows="3" class="field">{{ $value }}</textarea>
                            @else
                                <input id="{{ $input }}" name="{{ $input }}" type="{{ $setting->type === 'int' ? 'number' : 'text' }}"
                                       value="{{ $value }}" class="field"
                                       placeholder="{{ $setting->is_secret ? '•••••• (biarkan kosong bila tidak diubah)' : '' }}">
                            @endif

                            <p class="hint">
                                <code class="text-[11px]">{{ $setting->key }}</code>
                                @if($setting->is_secret) · disimpan terenkripsi, tidak pernah ditampilkan kembali @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="card-pad">
            <button class="btn-primary">Simpan pengaturan</button>
        </div>
    </form>

    <div class="card-pad mt-4 max-w-3xl">
        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">URL webhook untuk Tripay</h2>
        <p class="hint">Daftarkan URL ini di dashboard Tripay (menu Callback) supaya pembayaran otomatis terverifikasi.</p>
        <div class="mt-2 flex items-center gap-2">
            <code class="min-w-0 flex-1 truncate rounded-xl bg-slate-50 px-3 py-2 text-xs ring-1 ring-slate-200">{{ route('webhook.tripay') }}</code>
            <button type="button" class="btn-secondary btn-sm shrink-0" data-copy="{{ route('webhook.tripay') }}">Salin</button>
        </div>
        <p class="hint mt-2">
            Webhook diverifikasi lewat HMAC SHA-256 atas body mentah dengan private key. Permintaan
            tanpa tanda tangan yang valid langsung ditolak dengan status 403.
        </p>
    </div>
@endsection
