<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'produk' => ['nullable', 'integer'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $reviews = Review::query()
            ->with(['product:id,name,slug'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['produk'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['rating'] ?? null, fn ($q, $v) => $q->where('rating', $v))
            ->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 WHEN 'rejected' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.reviews.index', [
            'reviews' => $reviews,
            'filters' => $filters,
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
            'counts' => [
                'pending' => Review::query()->pending()->count(),
                'approved' => Review::query()->approved()->count(),
                'rejected' => Review::query()->where('status', Review::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function approve(Review $review)
    {
        $review->update(['status' => Review::STATUS_APPROVED, 'admin_note' => null]);
        ActivityLog::record('review.approved', 'Ulasan #'.$review->id.' disetujui', $review);

        return back()->with('success', 'Ulasan disetujui dan tayang di halaman produk.');
    }

    public function reject(Request $request, Review $review)
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:160']]);

        $review->update([
            'status' => Review::STATUS_REJECTED,
            'admin_note' => $data['admin_note'] ?? 'Tidak memenuhi ketentuan.',
        ]);

        ActivityLog::record('review.rejected', 'Ulasan #'.$review->id.' ditolak', $review);

        return back()->with('success', 'Ulasan ditolak dan tidak ditampilkan.');
    }

    public function destroy(Review $review)
    {
        $id = $review->id;
        $review->delete();

        ActivityLog::record('review.deleted', 'Ulasan #'.$id.' dihapus');

        return back()->with('success', 'Ulasan dihapus permanen.');
    }
}
