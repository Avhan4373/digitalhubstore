<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'kategori' => ['nullable', 'string', 'max:80'],
            'urut' => ['nullable', 'in:sesuai,terlaris,termurah,termahal,terbaru,rating'],
        ]);

        $category = ! empty($filters['kategori'])
            ? Category::query()->where('slug', $filters['kategori'])->first()
            : null;

        // Halaman depan menampilkan seluruh katalog (seperti situs asal yang
        // memuat semua produk di satu halaman) + urutan yang bisa dipilih.
        $query = Product::query()->visible()->with(['category', 'activeVariants']);

        if ($category) {
            $query->where('category_id', $category->id);
        }

        $products = $query->sorted($filters['urut'] ?? 'sesuai')->get();

        $popular = Product::query()
            ->visible()
            ->with(['category', 'activeVariants'])
            ->orderByDesc('is_best_seller')
            ->orderByDesc('sold_count')
            ->limit(4)
            ->get();

        return view('storefront.home', [
            'categories' => Category::query()->active()->ordered()->get(),
            'products' => $products,
            'popular' => $popular,
            'activeCategory' => $category,
            'sort' => $filters['urut'] ?? 'sesuai',
            'bannerUrl' => Setting::bannerUrl(),
            'socialProof' => $this->socialProof(),
            'storeName' => Setting::storeName(),
            'tagline' => (string) Setting::get('store_tagline', ''),
        ]);
    }

    /**
     * Notifikasi "pembeli lain baru order" — dibuat dari pesanan nyata yang
     * sudah lunas, nama pembeli selalu disamarkan (tidak ada nomor WA/email).
     *
     * @return array<int,array{name:string,product:string,time:string}>
     */
    private function socialProof(): array
    {
        if (! Setting::get('show_social_proof', true)) {
            return [];
        }

        return Order::query()
            ->paid()
            ->with('items:id,order_id,product_name')
            ->orderByDesc('paid_at')
            ->limit(6)
            ->get()
            ->map(fn (Order $order) => [
                'name' => mask_buyer_name($order->buyer_name),
                'product' => (string) optional($order->items->first())->product_name,
                'time' => (string) optional($order->paid_at)->diffForHumans(),
            ])
            ->filter(fn (array $row) => $row['product'] !== '')
            ->values()
            ->all();
    }
}
