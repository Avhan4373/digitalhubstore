<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VariantController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $data = $this->validated($request);

        $variant = $product->variants()->create($data);

        ActivityLog::record(
            'variant.created',
            'Varian "'.$variant->name.'" pada '.$product->name.' dibuat',
            $variant
        );

        return back()->with('success', 'Varian "'.$variant->name.'" ditambahkan.');
    }

    public function update(Request $request, Variant $variant)
    {
        $data = $this->validated($request);

        // Harga varian yang sudah dibeli tetap boleh diubah; order lama sudah
        // menyimpan snapshot harga di order_items sehingga tidak terpengaruh.
        $variant->update($data);

        ActivityLog::record('variant.updated', 'Varian "'.$variant->name.'" diperbarui', $variant, [
            'price' => $variant->price,
        ]);

        return back()->with('success', 'Varian diperbarui.');
    }

    public function destroy(Variant $variant)
    {
        $terjual = DB::table('order_items')->where('variant_id', $variant->id)->count();

        if ($terjual > 0) {
            // Jangan hapus kalau sudah ada order: nonaktifkan saja supaya
            // riwayat tetap valid.
            $variant->update(['is_active' => false]);
            ActivityLog::record('variant.deactivated', 'Varian "'.$variant->name.'" dinonaktifkan (sudah ada '.$terjual.' order)', $variant);

            return back()->with('success', 'Varian dipakai order lama, jadi hanya dinonaktifkan.');
        }

        $name = $variant->name;
        $variant->delete();

        ActivityLog::record('variant.deleted', 'Varian "'.$name.'" dihapus');

        return back()->with('success', 'Varian "'.$name.'" dihapus.');
    }

    // ------------------------------------------------------------------- stok
    public function stock(Variant $variant)
    {
        $items = $variant->stockItems()
            ->orderByRaw("CASE status WHEN 'available' THEN 1 WHEN 'reserved' THEN 2 WHEN 'delivered' THEN 3 ELSE 4 END")
            ->orderByDesc('id')
            ->paginate(50);

        return view('admin.variants.stock', [
            'variant' => $variant->load('product'),
            'items' => $items,
            'counts' => [
                'available' => $variant->stockItems()->where('status', StockItem::STATUS_AVAILABLE)->count(),
                'reserved' => $variant->stockItems()->where('status', StockItem::STATUS_RESERVED)->count(),
                'delivered' => $variant->stockItems()->where('status', StockItem::STATUS_DELIVERED)->count(),
            ],
        ]);
    }

    /**
     * Tambah stok massal. Satu baris = satu akun.
     * Payload langsung dienkripsi oleh model StockItem.
     */
    public function storeStock(Request $request, Variant $variant)
    {
        $data = $request->validate([
            'payloads' => ['required', 'string', 'max:200000'],
        ]);

        $lines = collect(preg_split('/\r\n|\r|\n/', $data['payloads']))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->unique();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['payloads' => 'Tidak ada baris valid untuk diimpor.']);
        }

        $existing = $variant->stockItems()->pluck('payload_hash')->all();
        $added = 0;
        $skipped = 0;

        DB::transaction(function () use ($variant, $lines, $existing, &$added, &$skipped) {
            foreach ($lines as $line) {
                $hash = hash('sha256', $line);

                if (in_array($hash, $existing, true)) {
                    $skipped++;
                    continue;
                }

                $item = new StockItem(['variant_id' => $variant->id]);
                $item->setPayloadPlain($line);
                $item->status = StockItem::STATUS_AVAILABLE;
                $item->save();

                $existing[] = $hash;
                $added++;
            }
        });

        ActivityLog::record('stock.added', "Stok {$variant->name}: +{$added} item", $variant, [
            'skipped' => $skipped,
        ]);

        if ($variant->delivery_type !== Variant::DELIVERY_AUTO && $added > 0) {
            $variant->update(['delivery_type' => Variant::DELIVERY_AUTO]);
        }

        return back()->with(
            'success',
            "{$added} stok ditambahkan".($skipped ? ", {$skipped} duplikat dilewati." : '.')
        );
    }

    public function destroyStock(StockItem $stockItem)
    {
        if ($stockItem->status === StockItem::STATUS_DELIVERED) {
            return back()->with('error', 'Stok yang sudah terkirim tidak bisa dihapus (jejak garansi).');
        }

        $stockItem->delete();
        ActivityLog::record('stock.deleted', 'Stok #'.$stockItem->id.' dihapus');

        return back()->with('success', 'Stok dihapus.');
    }

    // ---------------------------------------------------------------- helpers
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'price' => ['required', 'integer', 'min:0', 'max:999999999'],
            'original_price' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'delivery_type' => ['required', 'in:manual,auto,file'],
            'delivery_instruction' => ['nullable', 'string', 'max:5000'],
            'delivery_links' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'payment_type' => ['nullable', 'string', 'max:30'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            // referensi supplier (alur reseller manual, mis. itemku)
            'supplier_name' => ['nullable', 'string', 'max:60'],
            'supplier_sku' => ['nullable', 'string', 'max:120'],
            'supplier_url' => ['nullable', 'url:http,https', 'max:500'],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            // satu label per baris, mis. "Player ID"
            'required_fields' => ['nullable', 'string', 'max:1000'],
        ], [
            'supplier_url.url' => 'Tautan supplier harus berupa URL http/https yang valid.',
        ]);

        $links = collect(preg_split('/\r\n|\r|\n/', (string) ($data['delivery_links'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => filter_var($line, FILTER_VALIDATE_URL) !== false)
            ->values()
            ->all();

        $required = collect(preg_split('/\r\n|\r|\n/', (string) ($data['required_fields'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->unique()
            ->take(5)
            ->values()
            ->all();

        $data['delivery_links'] = $links ?: null;
        $data['required_fields'] = $required ?: null;
        $data['original_price'] = $data['original_price'] ?? 0;
        $data['cost_price'] = $data['cost_price'] ?? 0;
        $data['payment_type'] = $data['payment_type'] ?? 'default';
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
