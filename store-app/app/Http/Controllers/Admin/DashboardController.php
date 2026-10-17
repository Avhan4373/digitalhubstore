<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\StockItem;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $last30 = Carbon::today()->subDays(29);

        $revenue = fn () => Order::query()->paid()->sum('total');

        $stats = [
            'revenue_today' => Order::query()->paid()->where('paid_at', '>=', $today)->sum('total'),
            'revenue_30d' => Order::query()->paid()->where('paid_at', '>=', $last30)->sum('total'),
            'revenue_all' => $revenue(),
            'orders_today' => Order::query()->where('created_at', '>=', $today)->count(),
            'orders_pending' => Order::query()->unpaid()->count(),
            'orders_awaiting_delivery' => Order::query()->awaitingDelivery()->count(),
            'orders_30d' => Order::query()->where('created_at', '>=', $last30)->count(),
            'products' => Product::query()->count(),
            'products_inactive' => Product::query()->where('is_active', false)->count(),
            'reviews_pending' => Review::query()->pending()->count(),
            'stock_available' => StockItem::query()->available()->count(),
            'stock_alert' => $this->lowStockVariants(),
        ];

        $conversion = $stats['orders_30d'] > 0
            ? round(
                Order::query()->paid()->where('created_at', '>=', $last30)->count() / $stats['orders_30d'] * 100,
                1
            )
            : 0.0;

        $stats['conversion_30d'] = $conversion;

        // Penjualan 14 hari terakhir untuk grafik sederhana berbasis CSS.
        $daily = Order::query()
            ->paid()
            ->where('paid_at', '>=', Carbon::today()->subDays(13))
            ->selectRaw('DATE(paid_at) AS day, SUM(total) AS total, COUNT(*) AS orders')
            ->groupBy('day')
            ->pluck('total', 'day');

        $chart = collect(range(13, 0))->map(function ($daysAgo) use ($daily) {
            $date = Carbon::today()->subDays($daysAgo);

            return [
                'label' => $date->format('d/m'),
                'total' => (int) ($daily[$date->toDateString()] ?? 0),
            ];
        });

        $topProducts = Order::query()
            ->paid()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.paid_at', '>=', $last30)
            ->selectRaw('order_items.product_name, SUM(order_items.qty) AS qty, SUM(order_items.subtotal) AS revenue')
            ->groupBy('order_items.product_name')
            ->orderByDesc('revenue')
            ->limit(6)
            ->get();

        return view('admin.dashboard', [
            'stats' => $stats,
            'chart' => $chart,
            'chartMax' => max(1, (int) $chart->max('total')),
            'topProducts' => $topProducts,
            'recentOrders' => Order::query()->withCount('items')->orderByDesc('created_at')->limit(8)->get(),
            'pendingReviews' => Review::query()->with('product')->pending()->orderByDesc('created_at')->limit(5)->get(),
        ]);
    }

    /** Varian mode `auto` yang stoknya menipis / habis. */
    private function lowStockVariants()
    {
        return Variant::query()
            ->with('product:id,name,slug')
            ->where('is_active', true)
            ->where('delivery_type', Variant::DELIVERY_AUTO)
            ->withCount(['stockItems as available_count' => fn ($q) => $q->available()])
            ->orderBy('available_count')
            ->limit(8)
            ->get()
            ->filter(fn (Variant $v) => $v->available_count <= 3);
    }
}
