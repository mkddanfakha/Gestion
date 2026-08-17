<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormDraft extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'form_type',
        'mode',
        'entity_id',
        'scope_context',
        'data',
        'version',
        'instance_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'entity_id' => 'integer',
            'version' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
