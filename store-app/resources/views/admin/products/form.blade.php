@extends('layouts.admin')

@php($isNew = ! $product->exists)

@section('title', $isNew ? 'Produk baru' : $product->name)
@section('heading', $isNew ? 'Produk baru' : $product->name)
@section('subheading', $isNew ? 'Isi data produk, lalu tambahkan varian dan harganya' : 'Kelola data produk, varian, harga, dan stok')

@section('content')
    @if($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-rose-200">
            <p class="font-semibold">Ada yang perlu diperbaiki:</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Grid pembungkus. Form produk sengaja hanya membungkus kartu "Data
         produk": HTML tidak mengizinkan form bersarang, sementara kartu varian
         di bawahnya berisi banyak form (satu per varian + form tambah varian).
         Dulu form produk membungkus semuanya, sehingga browser menutupnya lebih
         awal: tombol "Simpan perubahan" sampai tidak punya form sama sekali dan
         field produk setelah varian (gambar, SEO, "terjual") tidak ikut
         terkirim. Kontrol di kolom kanan dihubungkan lewat `form="produk-form"`. --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <form id="produk-form"
                  action="{{ $isNew ? route('admin.products.store') : route('admin.products.update', $product) }}"
                  method="POST" enctype="multipart/form-data" class="card-pad space-y-4"
                  {{-- Form edit disimpan tanpa memuat ulang halaman; form produk
                       baru tetap submit biasa karena tujuannya berpindah ke
                       halaman edit. --}}
                  @if(! $isNew) data-ajax="product-form" @endif>
                @csrf
                @if(! $isNew) @method('PUT') @endif

                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Data produk</h2>

                <div>
                    <label class="label" for="name">Nama produk</label>
                    <input id="name" name="name" value="{{ old('name', $product->name) }}" class="field" required maxlength="160">
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="category_id">Kategori</label>
                        <select id="category_id" name="category_id" class="field" required>
                            <option value="">Pilih kategori</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id) == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="warranty">Garansi</label>
                        <input id="warranty" name="warranty" value="{{ old('warranty', $product->warranty) }}" class="field" maxlength="120" placeholder="mis. Garansi 30 hari">
                    </div>
                </div>

                <div>
                    <label class="label" for="description">Deskripsi</label>
                    <textarea id="description" name="description" rows="6" class="field" maxlength="20000">{{ old('description', $product->description) }}</textarea>
                    <p class="hint">Teks ini ditampilkan apa adanya (di-escape), jadi aman dari HTML tak diinginkan.</p>
                </div>

                <div>
                    <label class="label" for="terms">Syarat &amp; ketentuan</label>
                    <textarea id="terms" name="terms" rows="3" class="field" maxlength="5000">{{ old('terms', $product->terms) }}</textarea>
                </div>
            </form>

            @unless($isNew)
                <div class="card-pad">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Varian &amp; harga</h2>
                        <span class="text-xs text-slate-400">{{ $variants->count() }} varian</span>
                    </div>

                    <div class="mt-4 space-y-3">
                        @forelse($variants as $variant)
                            <details id="varian-{{ $variant->id }}" class="scroll-mt-24 rounded-xl ring-1 ring-slate-200"
                                     @if($loop->first || request('varian') == $variant->id) open @endif>
                                @include('admin.variants._summary', ['variant' => $variant])

                                <form action="{{ route('admin.variants.update', $variant) }}" method="POST"
                                      class="border-t border-slate-100 p-3.5" data-ajax="variant-form">
                                    @csrf @method('PUT')

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="label">Nama varian</label>
                                            <input name="name" value="{{ $variant->name }}" class="field" required maxlength="120">
                                        </div>
                                        <div>
                                            <label class="label">Tipe pengiriman</label>
                                            <select name="delivery_type" class="field">
                                                @foreach([
                                                    \App\Models\Variant::DELIVERY_MANUAL => 'Manual (via admin/WhatsApp)',
                                                    \App\Models\Variant::DELIVERY_AUTO => 'Otomatis (dari stok akun)',
                                                    \App\Models\Variant::DELIVERY_FILE => 'File / tautan unduhan',
                                                ] as $value => $label)
                                                    <option value="{{ $value }}" @selected($variant->delivery_type === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="label">Harga (Rp)</label>
                                            <input name="price" type="number" min="0" value="{{ $variant->price }}" class="field" required>
                                        </div>
                                        <div>
                                            <label class="label">Harga sebelum diskon (opsional)</label>
                                            <input name="original_price" type="number" min="0" value="{{ $variant->original_price }}" class="field">
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <label class="label">Instruksi pengiriman</label>
                                        <textarea name="delivery_instruction" rows="2" class="field" maxlength="5000">{{ $variant->delivery_instruction }}</textarea>
                                    </div>

                                    <div class="mt-3">
                                        <label class="label">Tautan unduhan (satu per baris)</label>
                                        <textarea name="delivery_links" rows="2" class="field" maxlength="2000">{{ implode("\n", $variant->delivery_links ?? []) }}</textarea>
                                    </div>

                                    <div class="mt-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Supplier — pembelian manual
                                        </p>
                                        <p class="hint">Isi kalau varian ini dibeli manual di marketplace seperti itemku.</p>

                                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <label class="label">Nama supplier</label>
                                                <input name="supplier_name" value="{{ $variant->supplier_name }}" class="field" maxlength="60" placeholder="mis. itemku">
                                            </div>
                                            <div>
                                                <label class="label">ID / kode produk di supplier</label>
                                                <input name="supplier_sku" value="{{ $variant->supplier_sku }}" class="field" maxlength="120" placeholder="mis. 1234567">
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="label">Tautan produk di supplier</label>
                                                <input name="supplier_url" type="url" value="{{ $variant->supplier_url }}" class="field" maxlength="500" placeholder="https://www.itemku.com/...">
                                            </div>
                                            <div>
                                                <label class="label">Harga modal (Rp)</label>
                                                <input name="cost_price" type="number" min="0" value="{{ $variant->cost_price }}" class="field">
                                            </div>
                                            <div>
                                                <label class="label">Margin tersimpan</label>
                                                <p class="field bg-white text-slate-500">
                                                    {{ rupiah($variant->margin()) }}@if($variant->cost_price > 0) · {{ $variant->marginPercent() }}%@endif
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <label class="label">Data wajib dari pembeli (satu per baris, maks. 5)</label>
                                        <textarea name="required_fields" rows="2" class="field" maxlength="1000"
                                                  placeholder="mis.&#10;Player ID&#10;Zone ID">{{ implode("\n", $variant->requiredFieldLabels()) }}</textarea>
                                        <p class="hint">Pembeli wajib mengisinya saat checkout, lalu tampil di detail pesanan sebagai bekal pembelian ke supplier.</p>
                                    </div>

                                    <div class="mt-3">
                                        <label class="label">Label khusus bot WA (opsional)</label>
                                        <input name="bot_label" type="text" class="field" maxlength="60"
                                               value="{{ $variant->bot_label }}"
                                               placeholder="mis. 1 Bulan Famhead">
                                        <p class="hint">Nama paket yang tampil di bot WhatsApp. Kosong = pakai nama varian.</p>
                                    </div>

                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="label">Urutan</label>
                                            <input name="sort_order" type="number" min="0" value="{{ $variant->sort_order }}" class="field">
                                        </div>
                                        <label class="mt-6 flex items-center gap-2 text-sm text-slate-600">
                                            <input type="checkbox" name="is_active" value="1" @checked($variant->is_active)
                                                   class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                                            Varian aktif dijual
                                        </label>
                                    </div>

                                    <div class="mt-4 flex gap-2">
                                        <button class="btn-primary btn-sm">Simpan varian</button>
                                        <span class="text-xs text-slate-400 self-center">
                                            Order lama tetap memakai harga saat dibeli.
                                        </span>
                                    </div>
                                </form>

                                <form action="{{ route('admin.variants.destroy', $variant) }}" method="POST"
                                      class="border-t border-slate-100 px-3.5 py-2.5"
                                      data-confirm="Hapus varian ini? Kalau sudah pernah dibeli, hanya akan dinonaktifkan.">
                                    @csrf @method('DELETE')
                                    <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">Hapus varian</button>
                                </form>
                            </details>
                        @empty
                            <p class="text-sm text-slate-500">Belum ada varian. Tambahkan minimal satu supaya produk bisa dibeli.</p>
                        @endforelse
                    </div>

                    <form action="{{ route('admin.variants.store', $product) }}" method="POST" id="tambah-varian"
                          class="mt-5 scroll-mt-24 rounded-xl bg-slate-50 p-3.5 ring-1 ring-slate-200">
                        @csrf
                        <p class="text-sm font-semibold text-slate-700">Tambah varian baru</p>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label">Nama varian</label>
                                <input name="name" class="field" required maxlength="120" placeholder="mis. 1 Bulan">
                            </div>
                            <div>
                                <label class="label">Tipe pengiriman</label>
                                <select name="delivery_type" class="field">
                                    <option value="manual">Manual (via admin/WhatsApp)</option>
                                    <option value="auto">Otomatis (dari stok akun)</option>
                                    <option value="file">File / tautan unduhan</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Harga (Rp)</label>
                                <input name="price" type="number" min="0" class="field" required>
                            </div>
                            <div>
                                <label class="label">Harga sebelum diskon</label>
                                <input name="original_price" type="number" min="0" class="field">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="label">Instruksi pengiriman</label>
                            <textarea name="delivery_instruction" rows="2" class="field" maxlength="5000"
                                      placeholder="mis. Silakan chat admin dengan menyertakan nomor invoice."></textarea>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="label">Supplier (opsional)</label>
                                <input name="supplier_name" class="field" maxlength="60" placeholder="mis. itemku">
                            </div>
                            <div>
                                <label class="label">ID produk supplier</label>
                                <input name="supplier_sku" class="field" maxlength="120">
                            </div>
                            <div>
                                <label class="label">Harga modal (Rp)</label>
                                <input name="cost_price" type="number" min="0" class="field" placeholder="0">
                            </div>
                            <div class="sm:col-span-3">
                                <label class="label">Tautan produk supplier</label>
                                <input name="supplier_url" type="url" class="field" maxlength="500" placeholder="https://www.itemku.com/...">
                            </div>
                            <div class="sm:col-span-3">
                                <label class="label">Data wajib dari pembeli (satu per baris, maks. 5)</label>
                                <textarea name="required_fields" rows="2" class="field" maxlength="1000"
                                          placeholder="mis.&#10;Player ID&#10;Zone ID"></textarea>
                            </div>
                            <div class="sm:col-span-3">
                                <label class="label">Label khusus bot WA (opsional)</label>
                                <input name="bot_label" type="text" class="field" maxlength="60"
                                       placeholder="mis. 1 Bulan Famhead">
                            </div>
                        </div>

                        <label class="mt-3 flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" name="is_active" value="1" checked
                                   class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                            Langsung aktifkan
                        </label>

                        <button class="btn-primary btn-sm mt-3">Tambah varian</button>
                    </form>
                </div>
            @endunless
        </div>

        <div class="space-y-4">
            <div class="card-pad space-y-4">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Publikasi</h2>

                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_active" value="1" form="produk-form" @checked(old('is_active', $product->is_active))
                               class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                        Tampilkan di toko
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_best_seller" value="1" form="produk-form" @checked(old('is_best_seller', $product->is_best_seller))
                               class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                        Tandai "Terlaris"
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_hot" value="1" form="produk-form" @checked(old('is_hot', $product->is_hot))
                               class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                        Tandai "Hot"
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label" for="sold_count">Terjual (tampilan)</label>
                        <input id="sold_count" name="sold_count" type="number" min="0" max="10000000" form="produk-form"
                               value="{{ old('sold_count', $product->sold_count) }}" class="field">
                        <p class="hint">Angka marketing. Aslinya dihitung dari order lunas.</p>
                    </div>
                    <div>
                        <label class="label" for="sort_order">Urutan</label>
                        <input id="sort_order" name="sort_order" type="number" min="0" form="produk-form" value="{{ old('sort_order', $product->sort_order ?? 0) }}" class="field">
                    </div>
                </div>
            </div>

            <div class="card-pad space-y-4">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Gambar utama</h2>

                {{-- Pratinjau + elemen kosong selalu ada supaya unggahan tanpa
                     reload bisa menampilkan gambar baru (lihat initAjaxForms). --}}
                <img data-cover-preview @if($product->cover_image) src="{{ $product->coverUrl() }}" @endif alt=""
                     class="w-full rounded-xl object-cover ring-1 ring-slate-200 {{ $product->cover_image ? '' : 'hidden' }}">
                <div data-cover-empty class="grid h-32 place-items-center rounded-xl bg-slate-100 text-sm text-slate-400 {{ $product->cover_image ? 'hidden' : '' }}">Belum ada gambar</div>

                <input type="file" name="cover" accept="image/*" class="field" form="produk-form" data-cover-input>
                <p class="hint">JPG/PNG/WebP maksimal 3 MB. Mengunggah gambar baru akan mengganti yang lama.</p>
            </div>

            <div class="card-pad space-y-4">
                <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">SEO</h2>
                <div>
                    <label class="label" for="meta_title">Judul meta</label>
                    <input id="meta_title" name="meta_title" form="produk-form" value="{{ old('meta_title', $product->meta_title) }}" class="field" maxlength="180">
                </div>
                <div>
                    <label class="label" for="meta_description">Deskripsi meta</label>
                    <textarea id="meta_description" name="meta_description" form="produk-form" rows="2" class="field" maxlength="255">{{ old('meta_description', $product->meta_description) }}</textarea>
                </div>
            </div>

            <div class="card-pad">
                <button class="btn-primary w-full" form="produk-form">{{ $isNew ? 'Buat produk' : 'Simpan perubahan' }}</button>
                <a href="{{ route('admin.products.index') }}" class="btn-ghost mt-2 w-full">Kembali ke daftar</a>
            </div>

            @unless($isNew)
                <div class="card-pad">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Zona berbahaya</h2>
                    <p class="hint">Produk dihapus secara soft delete — order lama tetap utuh dan bisa dipulihkan.</p>
                    <form action="{{ route('admin.products.destroy', $product) }}" method="POST" class="mt-3"
                          data-confirm="Hapus produk ini dari toko?">
                        @csrf @method('DELETE')
                        <button class="btn-danger btn-sm w-full">Hapus produk</button>
                    </form>
                </div>
            @endunless
        </div>
    </div>
@endsection
