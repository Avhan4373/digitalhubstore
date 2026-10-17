<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id', 'description', 'meta', 'ip',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        string $action,
        string $description,
        ?Model $subject = null,
        array $meta = [],
        ?User $user = null,
    ): self {
        return static::create([
            'user_id' => ($user ?? auth()->user())?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 255),
            'meta' => $meta ?: null,
            'ip' => request()->ip(),
        ]);
    }
}
