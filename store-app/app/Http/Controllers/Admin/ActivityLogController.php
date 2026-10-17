<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'aksi' => ['nullable', 'string', 'max:60'],
        ]);

        $logs = ActivityLog::query()
            ->with('user:id,name,email')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($sub) => $sub
                    ->where('description', 'like', $like)
                    ->orWhere('subject_id', 'like', $like));
            })
            ->when($filters['aksi'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('admin.logs.index', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => ActivityLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
