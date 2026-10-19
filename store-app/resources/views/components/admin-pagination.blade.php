@props(['paginator'])

{{--
 | Navigasi halaman bergaya panel admin (menggantikan tampilan bawaan Laravel
 | yang berbahasa Inggris dan berukuran besar). Dipakai daftar produk; halaman
 | lain boleh memakainya juga.
 |
 | Parameter: `paginator` — instance LengthAwarePaginator.
--}}
@if($paginator->total() > 0)
    @php
        $halamanIni = $paginator->currentPage();
        $halamanAkhir = max(1, $paginator->lastPage());

        // Jendela nomor halaman: 1 … 4 5 [6] 7 8 … 20
        $jendela = collect(range(max(1, $halamanIni - 2), min($halamanAkhir, $halamanIni + 2)))
            ->when($halamanIni <= 3, fn ($k) => collect(range(1, min($halamanAkhir, 5))))
            ->when($halamanIni >= $halamanAkhir - 2, fn ($k) => collect(range(max(1, $halamanAkhir - 4), $halamanAkhir)))
            ->unique()
            ->sort()
            ->values();
    @endphp

    <nav class="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Navigasi halaman">
        <p class="text-xs text-slate-500">
            Menampilkan
            <span class="font-bold text-slate-700">{{ $paginator->firstItem() }}</span>–<span class="font-bold text-slate-700">{{ $paginator->lastItem() }}</span>
            dari <span class="font-bold text-slate-700">{{ $paginator->total() }}</span>
        </p>

        @if($halamanAkhir > 1)
            <div class="flex items-center gap-1">
                @if($paginator->onFirstPage())
                    <span class="grid h-8 w-8 place-items-center rounded-lg text-slate-300" aria-hidden="true">
                        <i data-lucide="chevron-left" class="h-4 w-4"></i>
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Halaman sebelumnya"
                       class="grid h-8 w-8 place-items-center rounded-lg bg-white text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-50 active:scale-95">
                        <i data-lucide="chevron-left" class="h-4 w-4"></i>
                    </a>
                @endif

                @php($sebelumnya = 0)
                @foreach($jendela as $halaman)
                    @if($halaman - $sebelumnya > 1)
                        <span class="px-1 text-xs text-slate-400">…</span>
                    @endif

                    @if($halaman === $halamanIni)
                        <span aria-current="page"
                              class="grid h-8 min-w-8 place-items-center rounded-lg bg-slate-900 px-2 text-xs font-bold text-white">{{ $halaman }}</span>
                    @else
                        <a href="{{ $paginator->url($halaman) }}"
                           class="grid h-8 min-w-8 place-items-center rounded-lg bg-white px-2 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-50 active:scale-95">{{ $halaman }}</a>
                    @endif

                    @php($sebelumnya = $halaman)
                @endforeach

                @if($halamanAkhir - $sebelumnya > 0)
                    <span class="px-1 text-xs text-slate-400">…</span>
                    <a href="{{ $paginator->url($halamanAkhir) }}"
                       class="grid h-8 min-w-8 place-items-center rounded-lg bg-white px-2 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-50 active:scale-95">{{ $halamanAkhir }}</a>
                @endif

                @if($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Halaman berikutnya"
                       class="grid h-8 w-8 place-items-center rounded-lg bg-white text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-50 active:scale-95">
                        <i data-lucide="chevron-right" class="h-4 w-4"></i>
                    </a>
                @else
                    <span class="grid h-8 w-8 place-items-center rounded-lg text-slate-300" aria-hidden="true">
                        <i data-lucide="chevron-right" class="h-4 w-4"></i>
                    </span>
                @endif
            </div>
        @endif
    </nav>
@endif
