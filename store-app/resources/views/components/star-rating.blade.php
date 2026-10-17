@props(['rating' => 0, 'showValue' => false, 'count' => null])

@php($value = round((float) $rating, 1))
@php($filled = (int) round($value))

{{-- Bintang teks, sama seperti situs asal --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1']) }}>
    <span class="font-bold text-amber-500" aria-label="Rating {{ $value }} dari 5">{{ str_repeat('★', max(0, $filled)) }}<span class="text-slate-300">{{ str_repeat('★', max(0, 5 - $filled)) }}</span></span>
    @if($showValue)
        <span class="text-xs font-semibold text-slate-600">{{ number_format($value, 1, ',', '.') }}</span>
    @endif
    @if($count !== null)
        <span class="text-xs text-slate-400">({{ $count }})</span>
    @endif
</span>
