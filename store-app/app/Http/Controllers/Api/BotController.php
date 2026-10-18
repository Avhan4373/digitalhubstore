<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Variant;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API internal untuk bot WhatsApp.
 *
 * Bot TIDAK menghitung harga atau memutuskan ketersediaan sendiri: semua angka
 * di respons berasal dari database, dan pembuatan pesanan lewat `OrderService`
 * yang sama dengan checkout web. Dengan begitu bot dan web tidak pernah
 * berbeda harga.
 *
 * Semua route di sini dijaga middleware `bot.token`.
 */
class BotController extends Controller
{
    /** Daftar kategori + produk aktif untuk perintah `list`. */
    public function catalog(): JsonResponse
    {
        $maxProducts = max(1, (int) config('bot.max_products', 40));

        $categories = Category::query()
            ->active()
            ->ordered()
            ->limit(max(1, (int) config('bot.max_categories', 20)))
            ->with(['products' => function ($query) use ($maxProducts) {
                $query->visible()
                    ->with(['activeVariants'])
                    ->orderByDesc('is_best_seller')
                    ->orderBy('name')
                    ->limit($maxProducts);
            }])
            ->get();

        return response()->json([
            'store' => [
                'name' => Setting::storeName(),
                'whatsapp' => Setting::whatsappNumber(),
                'tagline' => (string) Setting::get('store_tagline', ''),
            ],
            // Urutan kategori -> produk di sini adalah urutan nomor yang
            // dilihat pembeli. Bot menyusun daftar datar dari struktur ini,
            // jadi jangan menambah array datar terpisah yang bisa berbeda urutan.
            'categories' => $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'products' => $category->products
                    ->map(fn (Product $product) => $this->ringkasProduk($product, $category->name))
                    ->values(),
            ])->values(),
        ]);
    }

    /** Detail satu produk: seluruh varian aktif + harga + stok. */
    public function product(Product $product): JsonResponse
    {
        if (! $product->is_active) {
            return response()->json(['error' => 'not_found', 'message' => 'Produk tidak tersedia.'], 404);
        }

        $product->loadMissing('category');

        $variants = $product->activeVariants()
            ->orderBy('price')
            ->limit(max(1, (int) config('bot.max_variants', 12)))
            ->get();

        return response()->json([
            'product' => $this->ringkasProduk($product) + [
                'description' => (string) $product->description,
                'warranty' => (string) $product->warranty,
                'url' => route('products.show', $product->slug),
            ],
            'variants' => $variants->map(fn (Variant $variant) => $this->ringkasVarian($variant))->values(),
        ]);
    }

    /** Buat pesanan dari bot. Harga tetap dihitung server. */
    public function order(Request $request, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'whatsapp' => ['required', 'string', 'max:24', function (string $attr, mixed $value, \Closure $fail) {
                $digits = wa_normalize((string) $value);

                if (strlen($digits) < 9 || strlen($digits) > 15 || ! str_starts_with($digits, '62')) {
                    $fail('Nomor WhatsApp tidak valid.');
                }
            }],
            'variant_id' => ['required', 'integer', 'exists:variants,id'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $variant = Variant::query()->with('product')->findOrFail($data['variant_id']);

        // Data wajib (mis. email akun) dikirim bot sebagai `required_info`.
        $requiredInfo = [$variant->id => (array) $request->input('required_info', [])];

        try {
            $order = $orders->createFromLines(
                [['variant' => $variant, 'qty' => (int) ($data['qty'] ?? 1)]],
                [
                    'name' => $data['name'],
                    'whatsapp' => $data['whatsapp'],
                    'note' => $data['note'] ?? null,
                    'source' => 'whatsapp-bot',
                ],
                Order::METHOD_QRIS,
                $data['coupon_code'] ?? null,
                $requiredInfo,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'validation',
                'message' => collect($e->errors())->flatten()->first(),
            ], 422);
        }

        return response()->json([
            'order' => $this->ringkasPesanan($order),
            'payment' => [
                'method' => Order::METHOD_QRIS,
                'qris_url' => $this->qrisUrl(),
                'holder' => (string) Setting::get('qris_holder', ''),
                'expires_at' => optional($order->expires_at)->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Status pesanan berdasarkan invoice + nomor WhatsApp pembeli.
     *
     * Isi akun HANYA dikirim kalau pesanan sudah lunas, dan nomor WA harus
     * cocok dengan pemesan — bot tidak boleh membocorkan akun ke nomor lain.
     */
    public function orderStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice' => ['required', 'string', 'max:40'],
            'whatsapp' => ['required', 'string', 'max:24'],
        ]);

        $order = Order::query()
            ->with(['items'])
            ->where('invoice_no', $data['invoice'])
            ->first();

        if (! $order) {
            return response()->json(['error' => 'not_found', 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        // Nomor pembeli adalah kunci akses: harus cocok persis.
        if (wa_normalize($order->buyer_wa) !== wa_normalize($data['whatsapp'])) {
            return response()->json(['error' => 'forbidden', 'message' => 'Nomor WhatsApp tidak cocok dengan pesanan ini.'], 403);
        }

        return response()->json([
            'order' => $this->ringkasPesanan($order),
            'contents' => $order->isPaid() ? $this->isiPesanan($order) : [],
            // Pembeli sering kehilangan gambar QRIS. Kirim ulang alamatnya
            // HANYA kalau pesanan masih bisa dibayar.
            'payment' => $order->bisaDibayar() ? [
                'qris_url' => $this->qrisUrl(),
                'holder' => (string) Setting::get('qris_holder', ''),
            ] : null,
        ]);
    }

    /** Cek beberapa invoice sekaligus (riwayat pesanan satu nomor). */
    public function orderHistory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'whatsapp' => ['required', 'string', 'max:24'],
        ]);

        $orders = Order::query()
            ->forBuyer($data['whatsapp'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $order) => $this->ringkasPesanan($order))->values(),
        ]);
    }

    // ------------------------------------------------------------------ ser

    /**
     * @param  string|null  $kategoriNama  nama kategori kalau sudah diketahui
     *                                     (menghindari query per produk)
     * @return array<string,mixed>
     */
    private function ringkasProduk(Product $product, ?string $kategoriNama = null): array
    {
        $variants = $product->relationLoaded('activeVariants')
            ? $product->activeVariants
            : $product->activeVariants()->get();

        $harga = $variants->pluck('price')->filter()->values();

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'category' => $kategoriNama ?? $product->category?->name,
            'price_min' => (int) ($harga->min() ?? 0),
            'price_max' => (int) ($harga->max() ?? 0),
            'variant_count' => $variants->count(),
            'sold_count' => (int) $product->sold_count,
            'rating' => (string) ($product->rating_source ?: '5'),
            'warranty' => (string) $product->warranty,
        ];
    }

    /** @return array<string,mixed> */
    private function ringkasVarian(Variant $variant): array
    {
        return [
            'id' => $variant->id,
            'name' => $variant->name,
            'bot_label' => (string) ($variant->bot_label ?: ''),
            'price' => (int) $variant->price,
            'original_price' => $variant->hasDiscount() ? (int) $variant->original_price : null,
            'stock' => $variant->requiresStock() ? $variant->availableStock() : null,
            'purchasable' => $variant->isPurchasable(),
            'delivery' => $variant->deliveryLabel(),
            'required_fields' => $variant->requiredFieldLabels(),
        ];
    }

    /** @return array<string,mixed> */
    private function ringkasPesanan(Order $order): array
    {
        return [
            'invoice' => $order->invoice_no,
            'buyer_name' => $order->buyer_name,
            'total' => (int) $order->total,
            'payment_status' => $order->payment_status,
            'payment_label' => $order->paymentLabel(),
            'order_status' => $order->order_status,
            'status_label' => $order->statusLabel(),
            'is_paid' => $order->isPaid(),
            'is_delivered' => $order->isDelivered(),
            'can_pay' => $order->bisaDibayar(),
            'expires_at' => optional($order->expires_at)->toIso8601String(),
            'created_at' => optional($order->created_at)->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'qty' => (int) $item->qty,
                'subtotal' => (int) $item->subtotal,
                'is_fulfilled' => (bool) $item->is_fulfilled,
            ])->values(),
        ];
    }

    /**
     * Isi akun yang sudah terkirim. Hanya dipanggil untuk pesanan lunas.
     *
     * @return list<array{product_name: string, variant_name: string, contents: list<string>}>
     */
    private function isiPesanan(Order $order): array
    {
        return $order->items
            ->map(fn ($item) => [
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'contents' => $item->deliveredContents(),
            ])
            ->filter(fn (array $row) => $row['contents'] !== [])
            ->values()
            ->all();
    }

    private function qrisUrl(): ?string
    {
        $path = (string) Setting::get('qris_image', '');

        if ($path === '') {
            return null;
        }

        // Path dari pengaturan admin: URL penuh atau path disk publik.
        return filter_var($path, FILTER_VALIDATE_URL) ? $path : storage_url($path);
    }
}
