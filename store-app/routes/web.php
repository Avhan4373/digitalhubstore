<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VariantController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Storefront\ReviewController;
use App\Http\Controllers\Webhook\TripayWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront
|--------------------------------------------------------------------------
*/

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/produk', [CatalogController::class, 'index'])->name('catalog');
Route::get('/produk/{product:slug}', [CatalogController::class, 'show'])->name('products.show');
Route::post('/produk/{product:slug}/ulasan', [ReviewController::class, 'store'])
    ->middleware('throttle:10,60')
    ->name('reviews.store');

// Keranjang
Route::prefix('keranjang')->name('cart.')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('index');
    Route::post('/tambah', [CartController::class, 'add'])->name('add');
    Route::post('/ubah', [CartController::class, 'update'])->name('update');
    Route::post('/hapus', [CartController::class, 'remove'])->name('remove');
    Route::post('/kosongkan', [CartController::class, 'clear'])->name('clear');
});

// Checkout & pesanan
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
Route::post('/checkout', [CheckoutController::class, 'store'])
    ->middleware('throttle:30,10')
    ->name('checkout.store');

Route::get('/lacak', [OrderController::class, 'trackForm'])->name('orders.track.form');
Route::post('/lacak', [OrderController::class, 'track'])
    ->middleware('throttle:10,10')
    ->name('orders.track');

// Cek transaksi cepat dari popup "Cek Transaksi" (hanya butuh nomor invoice,
// hasilnya lembar E-Invoice — tanpa isi akun).
Route::post('/cek-transaksi', [OrderController::class, 'check'])
    ->middleware('throttle:20,10')
    ->name('orders.check');

Route::get('/pesanan/{invoice}/bayar', [OrderController::class, 'pay'])->name('orders.pay');
Route::get('/pesanan/{invoice}/status', [OrderController::class, 'status'])
    ->middleware('throttle:120,1')
    ->name('orders.status');
Route::get('/pesanan/{invoice}', [OrderController::class, 'show'])->name('orders.show');
Route::post('/pesanan/{invoice}/buka', [OrderController::class, 'unlock'])
    ->middleware('throttle:10,10')
    ->name('orders.unlock');
Route::get('/pesanan/{invoice}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');

/*
|--------------------------------------------------------------------------
| Webhook (tanpa auth, diverifikasi lewat tanda tangan HMAC)
|--------------------------------------------------------------------------
*/
Route::post('/webhook/tripay', [TripayWebhookController::class, 'handle'])->name('webhook.tripay');

/*
|--------------------------------------------------------------------------
| Autentikasi
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Panel admin
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Produk
    Route::get('produk', [AdminProductController::class, 'index'])->name('products.index');
    Route::get('produk/baru', [AdminProductController::class, 'create'])->name('products.create');
    Route::post('produk', [AdminProductController::class, 'store'])->name('products.store');
    Route::get('produk/{product}/edit', [AdminProductController::class, 'edit'])->name('products.edit');
    Route::put('produk/{product}', [AdminProductController::class, 'update'])->name('products.update');
    // Aktif/nonaktif langsung dari daftar produk (tanpa membuka form edit).
    Route::post('produk/{product}/status', [AdminProductController::class, 'status'])->name('products.status');
    Route::delete('produk/{product}', [AdminProductController::class, 'destroy'])->name('products.destroy');
    Route::post('produk/{id}/pulihkan', [AdminProductController::class, 'restore'])->name('products.restore');

    // Varian + stok
    Route::post('produk/{product}/varian', [VariantController::class, 'store'])->name('variants.store');
    Route::put('varian/{variant}', [VariantController::class, 'update'])->name('variants.update');
    // Ubah harga & status jual langsung dari daftar produk.
    Route::put('varian/{variant}/cepat', [VariantController::class, 'quick'])->name('variants.quick');
    Route::delete('varian/{variant}', [VariantController::class, 'destroy'])->name('variants.destroy');
    Route::get('varian/{variant}/stok', [VariantController::class, 'stock'])->name('variants.stock');
    Route::post('varian/{variant}/stok', [VariantController::class, 'storeStock'])->name('variants.stock.store');
    Route::delete('stok/{stockItem}', [VariantController::class, 'destroyStock'])->name('stock.destroy');

    // Pesanan
    Route::get('pesanan', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('pesanan/live', [AdminOrderController::class, 'live'])->name('orders.live');
    Route::post('pesanan/kedaluwarsa', [AdminOrderController::class, 'expireStale'])->name('orders.expire');
    Route::get('pesanan/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::post('pesanan/{order}/lunas', [AdminOrderController::class, 'markPaid'])->name('orders.paid');
    Route::post('pesanan/{order}/kirim', [AdminOrderController::class, 'deliver'])->name('orders.deliver');
    Route::post('pesanan/{order}/batal', [AdminOrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('pesanan/{order}/catatan', [AdminOrderController::class, 'updateNote'])->name('orders.note');
    Route::post('pesanan/{order}/item/{item}/kirim-manual', [AdminOrderController::class, 'completeItem'])
        ->name('orders.items.complete');

    // Ulasan
    Route::get('ulasan', [AdminReviewController::class, 'index'])->name('reviews.index');
    Route::post('ulasan/{review}/setujui', [AdminReviewController::class, 'approve'])->name('reviews.approve');
    Route::post('ulasan/{review}/tolak', [AdminReviewController::class, 'reject'])->name('reviews.reject');
    Route::delete('ulasan/{review}', [AdminReviewController::class, 'destroy'])->name('reviews.destroy');

    // Voucher
    Route::get('voucher', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('voucher', [CouponController::class, 'store'])->name('coupons.store');
    Route::put('voucher/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
    Route::delete('voucher/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');

    // Pengaturan (khusus admin: berisi kredensial pembayaran)
    Route::middleware('can:manage-settings')->group(function () {
        Route::get('pengaturan', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('pengaturan', [SettingController::class, 'update'])->name('settings.update');
    });

    // Log aktivitas
    Route::get('log', [ActivityLogController::class, 'index'])->name('logs.index');

    // Pengguna (khusus admin)
    Route::middleware('can:manage-users')->group(function () {
        Route::get('pengguna', [UserController::class, 'index'])->name('users.index');
        Route::post('pengguna', [UserController::class, 'store'])->name('users.store');
        Route::put('pengguna/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('pengguna/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
