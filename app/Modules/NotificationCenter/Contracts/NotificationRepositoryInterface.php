<?php

namespace App\Modules\NotificationCenter\Contracts;

use App\Modules\NotificationCenter\Models\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

interface NotificationRepositoryInterface
{
    public function create(array $attributes): Notification;

    public function updateOrCreate(array $unique, array $attributes): Notification;

    public function find(int $id): ?Notification;

    public function findForUser(int $userId, int $id): ?Notification;

    public function getForUser(int $userId, ?int $limit = null): Collection;

    public function getUnread(?int $userId = null): Collection;

    public function markAllAsRead(Authenticatable $user): int;

    public function deleteRead(?int $userId = null): int;

    public function findActiveGrouped(int $userId, string $type, string $groupKey): ?Notification;

    public function findGrouped(int $userId, string $type, string $groupKey): ?Notification;

    public function resolveActiveGrouped(string $type, string $groupKey, ?Authenticatable $resolver = null): int;

    public function resolveActiveForEntity(string $type, string $entityType, int $entityId, ?Authenticatable $resolver = null): int;

    public function findActiveForEntity(int $userId, string $type, int $entityId, ?string $entityType = null): ?Notification;

    /**
     * @return array<string, list<int>>
     */
    public function getLegacyReadIdsByType(int $userId): array;

    public function markLegacyAsRead(Authenticatable $user, string $legacyType, int $entityId): Notification;

    /**
     * @param  list<int>  $entityIds
     */
    public function markLegacyBulkAsRead(Authenticatable $user, string $legacyType, array $entityIds): void;

    /**
     * @param  list<int>  $entityIds
     */
    public function clearLegacyReads(int $userId, string $legacyType, array $entityIds): int;

    /**
     * @return list<int>
     */
    public function getLegacyActiveEntityIds(string $legacyType, ?Authenticatable $user = null): array;
}
