<?php

namespace App\Modules\NotificationCenter\Models;

use App\Modules\NotificationCenter\Enums\NotificationAudience;
use App\Modules\NotificationCenter\Enums\NotificationPriority;
use App\Modules\NotificationCenter\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $table = 'notification_reads';

    protected $fillable = [
        'user_id',
        'notification_type',
        'notification_id',
        'type',
        'priority',
        'status',
        'audience',
        'entity_type',
        'entity_id',
        'group_key',
        'created_by',
        'read_at',
        'resolved_at',
        'resolved_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'priority' => NotificationPriority::class,
            'status' => NotificationStatus::class,
            'audience' => NotificationAudience::class,
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Notification $notification) {
            if ($notification->entity_id === null && $notification->notification_id !== null) {
                $notification->entity_id = $notification->notification_id;
            }

            if ($notification->notification_id === null && $notification->entity_id !== null) {
                $notification->notification_id = (int) $notification->entity_id;
            }
        });
    }

    public function user(): BelongsTo
    {
        $model = config('notification-center.user_model');

        return $this->belongsTo($model);
    }

    public function creator(): BelongsTo
    {
        $model = config('notification-center.user_model');

        return $this->belongsTo($model, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        $model = config('notification-center.user_model');

        return $this->belongsTo($model, 'resolved_by');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at')
            ->where('status', NotificationStatus::Active->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', NotificationStatus::Active->value);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeByPriority(Builder $query, NotificationPriority $priority): Builder
    {
        return $query->where('priority', $priority->value);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('priority', NotificationPriority::Critical->value);
    }

    public function scopeForEntity(Builder $query, string $entityType, int|string $entityId): Builder
    {
        return $query->where('entity_type', $entityType)
            ->where(function (Builder $q) use ($entityId) {
                $q->where('entity_id', $entityId)
                    ->orWhere('notification_id', $entityId);
            });
    }

    public function scopeRecent(Builder $query, ?int $limit = null): Builder
    {
        $limit ??= (int) config('notification-center.delays.recent_limit', 50);

        return $query->latest()->limit($limit);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null && $this->status === NotificationStatus::Active;
    }

    public function markAsRead(?int $readerId = null): bool
    {
        return $this->update([
            'read_at' => now(),
            'status' => NotificationStatus::Resolved->value,
            'resolved_at' => $this->resolved_at ?? now(),
            'resolved_by' => $readerId ?? $this->resolved_by,
        ]);
    }

    public function markAsResolved(?int $resolverId = null): bool
    {
        return $this->update([
            'status' => NotificationStatus::Resolved->value,
            'resolved_at' => now(),
            'resolved_by' => $resolverId,
            'read_at' => $this->read_at ?? now(),
        ]);
    }

    public function markAsArchived(): bool
    {
        return $this->update([
            'status' => NotificationStatus::Archived->value,
        ]);
    }

    public function getResolvedEntityIdAttribute(): ?int
    {
        return $this->entity_id ?? $this->notification_id;
    }

    public function getLegacyTypeAttribute(): ?string
    {
        return $this->notification_type;
    }

    /** Titre affiché — depuis metadata ou type. */
    public function getTitleAttribute(): string
    {
        return (string) ($this->metadata['title'] ?? $this->type ?? 'Notification');
    }

    /** Description affichée. */
    public function getDescriptionAttribute(): string
    {
        return (string) ($this->metadata['description'] ?? '');
    }

    /** URL de navigation. */
    public function getUrlAttribute(): ?string
    {
        $url = $this->metadata['url'] ?? null;

        return is_string($url) ? $url : null;
    }
}
