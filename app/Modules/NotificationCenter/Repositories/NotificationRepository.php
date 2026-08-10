<?php

namespace App\Modules\NotificationCenter\Repositories;

use App\Modules\NotificationCenter\Contracts\GroupedEntityProviderInterface;
use App\Modules\NotificationCenter\Contracts\NotificationRepositoryInterface;
use App\Modules\NotificationCenter\Enums\NotificationPriority;
use App\Modules\NotificationCenter\Enums\NotificationStatus;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Support\NotificationTypeConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class NotificationRepository implements NotificationRepositoryInterface
{
    public function __construct(
        protected GroupedEntityProviderInterface $groupedEntityProvider,
    ) {}

    public function create(array $attributes): Notification
    {
        return Notification::create($attributes);
    }

    public function updateOrCreate(array $unique, array $attributes): Notification
    {
        return Notification::updateOrCreate($unique, $attributes);
    }

    public function find(int $id): ?Notification
    {
        return Notification::find($id);
    }

    public function findForUser(int $userId, int $id): ?Notification
    {
        return Notification::query()->forUser($userId)->find($id);
    }

    public function getForUser(int $userId, ?int $limit = null): Collection
    {
        return Notification::query()
            ->forUser($userId)
            ->recent($limit)
            ->get();
    }

    public function getUnread(?int $userId = null): Collection
    {
        $query = Notification::query()->unread();

        if ($userId !== null) {
            $query->forUser($userId);
        }

        return $query->latest()->get();
    }

    public function markAllAsRead(Authenticatable $user): int
    {
        return Notification::query()
            ->forUser($user->getAuthIdentifier())
            ->unread()
            ->update([
                'read_at' => now(),
                'status' => NotificationStatus::Resolved->value,
                'resolved_at' => now(),
                'resolved_by' => $user->getAuthIdentifier(),
            ]);
    }

    public function deleteRead(?int $userId = null): int
    {
        $query = Notification::query()->whereNotNull('read_at');

        if ($userId !== null) {
            $query->forUser($userId);
        }

        return $query->delete();
    }

    public function findActiveGrouped(int $userId, string $type, string $groupKey): ?Notification
    {
        return Notification::query()
            ->forUser($userId)
            ->byType($type)
            ->active()
            ->where('group_key', $groupKey)
            ->first();
    }

    public function findGrouped(int $userId, string $type, string $groupKey): ?Notification
    {
        return Notification::query()
            ->forUser($userId)
            ->byType($type)
            ->where('group_key', $groupKey)
            ->first();
    }

    public function resolveActiveGrouped(string $type, string $groupKey, ?Authenticatable $resolver = null): int
    {
        return Notification::query()
            ->byType($type)
            ->active()
            ->where('group_key', $groupKey)
            ->update([
                'status' => NotificationStatus::Resolved->value,
                'resolved_at' => now(),
                'resolved_by' => $resolver?->getAuthIdentifier(),
            ]);
    }

    public function resolveActiveForEntity(string $type, string $entityType, int $entityId, ?Authenticatable $resolver = null): int
    {
        return Notification::query()
            ->byType($type)
            ->active()
            ->forEntity($entityType, $entityId)
            ->whereNull('group_key')
            ->update([
                'status' => NotificationStatus::Resolved->value,
                'resolved_at' => now(),
                'resolved_by' => $resolver?->getAuthIdentifier(),
            ]);
    }

    public function findActiveForEntity(int $userId, string $type, int $entityId, ?string $entityType = null): ?Notification
    {
        $entityType ??= NotificationTypeConfig::entityType($type) ?? 'unknown';

        return Notification::query()
            ->forUser($userId)
            ->byType($type)
            ->active()
            ->forEntity($entityType, $entityId)
            ->whereNull('group_key')
            ->first();
    }

    public function getLegacyReadIdsByType(int $userId): array
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->whereNotNull('read_at')
            ->get()
            ->groupBy('notification_type')
            ->map(fn ($reads) => $reads->pluck('notification_id')->toArray())
            ->toArray();
    }

    public function markLegacyAsRead(Authenticatable $user, string $legacyType, int $entityId): Notification
    {
        $type = NotificationTypeConfig::fromLegacy($legacyType);
        $priority = $type
            ? NotificationPriority::from(NotificationTypeConfig::defaultPriority($type))
            : NotificationPriority::Info;

        return $this->updateOrCreate(
            [
                'user_id' => $user->getAuthIdentifier(),
                'notification_type' => $legacyType,
                'notification_id' => $entityId,
            ],
            [
                'type' => $type,
                'priority' => $priority->value,
                'status' => NotificationStatus::Resolved->value,
                'entity_type' => $type ? NotificationTypeConfig::entityType($type) : null,
                'entity_id' => $entityId,
                'read_at' => now(),
                'resolved_at' => now(),
                'resolved_by' => $user->getAuthIdentifier(),
            ]
        );
    }

    public function markLegacyBulkAsRead(Authenticatable $user, string $legacyType, array $entityIds): void
    {
        if ($entityIds === []) {
            return;
        }

        $type = NotificationTypeConfig::fromLegacy($legacyType);
        $priority = $type
            ? NotificationPriority::from(NotificationTypeConfig::defaultPriority($type))
            : NotificationPriority::Info;
        $entityType = $type ? NotificationTypeConfig::entityType($type) : null;
        $now = now();

        $inserts = [];
        foreach ($entityIds as $entityId) {
            $inserts[] = [
                'user_id' => $user->getAuthIdentifier(),
                'notification_type' => $legacyType,
                'notification_id' => $entityId,
                'type' => $type,
                'priority' => $priority->value,
                'status' => NotificationStatus::Resolved->value,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'read_at' => $now,
                'resolved_at' => $now,
                'resolved_by' => $user->getAuthIdentifier(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notification_reads')->insertOrIgnore($inserts);
    }

    public function clearLegacyReads(int $userId, string $legacyType, array $entityIds): int
    {
        $entityIds = array_values(array_filter(array_map('intval', $entityIds), fn (int $id) => $id > 0));

        if ($entityIds === []) {
            return 0;
        }

        return DB::table('notification_reads')
            ->where('user_id', $userId)
            ->where('notification_type', $legacyType)
            ->whereIn('notification_id', $entityIds)
            ->delete();
    }

    public function getLegacyActiveEntityIds(string $legacyType, ?Authenticatable $user = null): array
    {
        return array_map(
            'intval',
            $this->groupedEntityProvider->getLegacyActiveEntityIds($legacyType, $user)
        );
    }

    /**
     * @return list<int>
     */
    public function getEntityIdsForGroupedType(string $type, ?Authenticatable $user = null): array
    {
        return array_map(
            'intval',
            $this->groupedEntityProvider->getEntityIdsForType($type, $user)
        );
    }

    /**
     * Compteurs de notifications non lues actives, par priorité.
     *
     * @return array{total: int, critical: int, warning: int, info: int}
     */
    public function getUnreadCountsByPriority(int $userId): array
    {
        $rows = Notification::query()
            ->forUser($userId)
            ->unread()
            ->where('status', NotificationStatus::Active->value)
            ->selectRaw('priority, COUNT(*) as aggregate')
            ->groupBy('priority')
            ->pluck('aggregate', 'priority');

        $critical = (int) ($rows[NotificationPriority::Critical->value] ?? 0);
        $warning = (int) ($rows[NotificationPriority::Warning->value] ?? 0);
        $info = (int) ($rows[NotificationPriority::Info->value] ?? 0);

        return [
            'total' => $critical + $warning + $info,
            'critical' => $critical,
            'warning' => $warning,
            'info' => $info,
        ];
    }
}
