@props(['review'])

{{-- Kartu ulasan gaya situs asal. Nama pembeli selalu disamarkan,
     dan isi ulasan tetap di-escape (stored XSS pernah terjadi di situs asal). --}}
<div class="animate-review space-y-1.5 rounded-2xl border border-slate-200/80 bg-slate-50 p-3"
     data-review-item
     data-rating="{{ $review->rating }}"
     data-comment="{{ \Illuminate\Support\Str::limit(strip_tags((string) $review->comment), 300) }}">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <div class="flex h-7 w-7 items-center justify-center rounded-full text-[10px] font-bold {{ avatar_classes($review->user_name) }}">
                {{ mb_strtoupper(mb_substr($review->user_name, 0, 1)) }}
            </div>
            <div>
                <p class="text-[11px] font-bold leading-tight text-slate-800">{{ mask_name($review->user_name) }}</p>
                <div class="flex items-center gap-0.5 text-[10px] text-amber-500">
                    {{ str_repeat('★', max(0, (int) $review->rating)) }}<span class="text-slate-300">{{ str_repeat('★', max(0, 5 - (int) $review->rating)) }}</span>
                </div>
            </div>
        </div>
        <span class="text-[9.5px] font-medium text-slate-400">{{ $review->created_at->diffForHumans() }}</span>
    </div>

    @if($review->variant_name)
        <div class="w-max rounded border border-slate-100 bg-white px-2 py-0.5 text-[10px] font-medium text-slate-500">
            Variasi: {{ $review->variant_name }}
        </div>
    @endif

    <div class="text-[11.5px] leading-relaxed text-slate-700">{!! safe_text($review->comment) !!}</div>

    @if($review->imageUrl())
        <a href="{{ $review->imageUrl() }}" target="_blank" rel="noopener" class="mt-2 block w-max">
            <img src="{{ $review->imageUrl() }}" alt="Foto ulasan" loading="lazy"
                 class="h-20 w-20 rounded-lg border border-slate-200 object-cover">
        </a>
    @endif
</div>
