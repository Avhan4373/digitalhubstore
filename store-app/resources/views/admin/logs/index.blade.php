@extends('layouts.admin')

@section('title', 'Log Aktivitas')
@section('heading', 'Log Aktivitas')
@section('subheading', 'Jejak audit aksi admin — tidak ada di situs asal')

@section('content')
    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-48 flex-1">
            <label class="label" for="q">Cari deskripsi</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="field" placeholder="invoice, nama produk…">
        </div>
        <div>
            <label class="label" for="aksi">Aksi</label>
            <select id="aksi" name="aksi" class="field w-56">
                <option value="">Semua aksi</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['aksi'] ?? null) === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-secondary">Filter</button>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Pelaku</th>
                    <th>Aksi</th>
                    <th>Deskripsi</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td class="whitespace-nowrap text-xs text-slate-500">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                        <td class="text-sm">
                            {{ $log->user?->name ?? 'sistem' }}
                            <span class="block text-[11px] text-slate-400">{{ $log->user?->email }}</span>
                        </td>
                        <td><span class="badge bg-slate-100 text-slate-600 ring-slate-200">{{ $log->action }}</span></td>
                        <td class="text-sm">
                            {{ $log->description }}
                            @if($log->subject_type)
                                <span class="block text-[11px] text-slate-400">{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</span>
                            @endif
                        </td>
                        <td class="font-mono text-[11px] text-slate-400">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-10 text-center text-slate-500">Belum ada aktivitas tercatat.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
