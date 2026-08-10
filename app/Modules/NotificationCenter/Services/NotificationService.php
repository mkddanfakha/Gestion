<?php

namespace App\Modules\NotificationCenter\Services;

use App\Modules\NotificationCenter\Contracts\AudienceResolverInterface;
use App\Modules\NotificationCenter\Contracts\GroupedPreviewProviderInterface;
use App\Modules\NotificationCenter\Contracts\NotificationRepositoryInterface;
use App\Modules\NotificationCenter\Contracts\RealtimeProviderInterface;
use App\Modules\NotificationCenter\DTO\CreateNotificationData;
use App\Modules\NotificationCenter\Enums\NotificationPriority;
use App\Modules\NotificationCenter\Enums\NotificationStatus;
use App\Modules\NotificationCenter\Jobs\SendNotificationJob;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Support\NotificationLogger;
use App\Modules\NotificationCenter\Support\NotificationTypeConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Service générique du module — aucune logique métier.
 */
class NotificationService
{
    public function __construct(
        protected NotificationRepositoryInterface $repository,
        protected AudienceResolverInterface $audienceResolver,
        protected RealtimeProviderInterface $realtimeProvider,
        protected NotificationSettingsService $settings,
        protected ?GroupedPreviewProviderInterface $previewProvider = null,
    ) {}

    /**
     * Point d'entrée unique recommandé pour l'application hôte.
     */
    public function create(CreateNotificationData $data): Notification
    {
        $priority = NotificationPriority::from(
            $this->settings->getPriorityForType($data->type)
        );

        $legacyType = $data->legacyType ?? NotificationTypeConfig::toLegacyType($data->type) ?? $data->type;
        $entityId = $data->entityId ?? 0;

        $metadata = array_merge($data->metadata ?? [], [
            'title' => $data->title,
            'description' => $data->description,
            'url' => $data->url,
            'icon' => $data->icon,
            'type_label' => NotificationTypeConfig::label($data->type),
        ]);

        $startedAt = microtime(true);

        $notification = $this->repository->create([
            'user_id' => $data->userId,
            'notification_type' => $legacyType,
            'notification_id' => is_numeric($entityId) ? (int) $entityId : 0,
            'type' => $data->type,
            'priority' => $priority->value,
            'status' => $data->status->value,
            'entity_type' => $data->entityType ?? NotificationTypeConfig::entityType($data->type),
            'entity_id' => $entityId,
            'group_key' => $data->groupKey,
            'created_by' => $data->createdBy ?? auth()->id(),
            'metadata' => $metadata,
            'read_at' => null,
        ]);

        $shouldBroadcast = $data->broadcast
            && $this->settings->isRealtimeEnabled()
            && NotificationTypeConfig::isRealtime($data->type);

        if ($shouldBroadcast) {
            $this->broadcast(
                $data->broadcastPayload ?? $this->buildBroadcastPayload($notification),
                $notification->user_id
            );
        }

        $this->settings->forgetUserNotificationCache($notification->user_id);
        $this->settings->recordMonitoringMetric([
            'last_processing_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $notification;
    }

    /**
     * Distribue une notification à plusieurs utilisateurs selon l'audience.
     *
     * @return Collection<int, Notification>
     */
    public function distribute(CreateNotificationData $template): Collection
    {
        if (! $this->settings->isNotificationsEnabled() || ! $this->settings->isTypeEnabled($template->type)) {
            return collect();
        }

        if (NotificationTypeConfig::isGroupable($template->type)) {
            $grouped = $this->syncGroupedAlert($template->type);

            return $grouped ? collect([$grouped]) : collect();
        }

        $userIds = $this->audienceResolver->resolveUserIds(
            $template->type,
            is_int($template->entityId) ? null : null
        );

        if (NotificationTypeConfig::isSellerScoped($template->type) && $template->userId) {
            $userIds = [$template->userId];
        }

        return collect($userIds)->map(function (int $userId) use ($template) {
            $entityId = $template->entityId;

            if ($entityId !== null && is_numeric($entityId)) {
                $existing = $this->repository->findActiveForEntity($userId, $template->type, (int) $entityId);
                if ($existing) {
                    return null;
                }
            }

            return $this->create(new CreateNotificationData(
                userId: $userId,
                title: $template->title,
                description: $template->description,
                type: $template->type,
                priority: $template->priority,
                status: $template->status,
                url: $template->url,
                icon: $template->icon,
                entityType: $template->entityType,
                entityId: $template->entityId,
                groupKey: $template->groupKey,
                createdBy: $template->createdBy,
                metadata: $template->metadata,
                broadcast: $template->broadcast,
                broadcastPayload: $template->broadcastPayload,
                legacyType: $template->legacyType,
            ));
        })->filter();
    }

    public function syncGroupedAlert(string $type): ?Notification
    {
        if (! $this->settings->isNotificationsEnabled() || ! $this->settings->isTypeEnabled($type)) {
            return null;
        }

        if (! NotificationTypeConfig::isGroupable($type)) {
            return null;
        }

        $entityIds = $this->repository->getEntityIdsForGroupedType($type);
        $count = count($entityIds);
        $groupKey = NotificationTypeConfig::groupedKey($type);
        $userIds = $this->audienceResolver->resolveUserIds($type);
        $lastNotification = null;

        foreach ($userIds as $userId) {
            $existing = $this->repository->findGrouped($userId, $type, $groupKey);

            if ($count === 0) {
                if ($existing && $existing->status === NotificationStatus::Active) {
                    $existing->markAsResolved();
                }

                continue;
            }

            $message = NotificationTypeConfig::groupedMessage($type, $count);
            $products = $this->previewProvider?->buildPreviews($type, $entityIds) ?? [];
            $metadata = [
                'grouped' => true,
                'count' => $count,
                'entity_ids' => $entityIds,
                'products' => $products,
                'entity_type' => NotificationTypeConfig::entityType($type),
                'message' => $message,
                'title' => NotificationTypeConfig::label($type),
                'description' => $message,
            ];

            if ($existing) {
                $previousCount = (int) ($existing->metadata['count'] ?? 0);
                $wasInactive = $existing->status !== NotificationStatus::Active;

                $existing->update([
                    'title' => NotificationTypeConfig::label($type),
                    'description' => $message,
                    'metadata' => $metadata,
                    'status' => NotificationStatus::Active->value,
                    'read_at' => null,
                    'resolved_at' => null,
                    'resolved_by' => null,
                ]);

                if (($previousCount !== $count || $wasInactive) && NotificationTypeConfig::isRealtime($type)) {
                    $this->broadcastGrouped($userId, $type, $metadata);
                }

                $this->settings->forgetUserNotificationCache($userId);
                $lastNotification = $existing->fresh();

                continue;
            }

            $lastNotification = $this->create(new CreateNotificationData(
                userId: $userId,
                title: NotificationTypeConfig::label($type),
                description: $message,
                type: $type,
                priority: NotificationPriority::from($this->settings->getPriorityForType($type)),
                entityId: 0,
                groupKey: $groupKey,
                metadata: $metadata,
                broadcast: false,
            ));

            if (NotificationTypeConfig::isRealtime($type)) {
                $this->broadcastGrouped($userId, $type, $metadata);
            }
        }

        if ($count === 0) {
            $this->repository->resolveActiveGrouped($type, $groupKey);
        }

        return $lastNotification;
    }

    public function markAsRead(Authenticatable $user, string $type, int|string $entityId): Notification
    {
        $legacyType = NotificationTypeConfig::toLegacyType($type) ?? $type;

        if (is_string($type) && NotificationTypeConfig::fromLegacy($type)) {
            $legacyType = $type;
        }

        return $this->repository->markLegacyAsRead($user, $legacyType, (int) $entityId);
    }

    public function markAsReadById(Authenticatable $user, int $notificationId): ?Notification
    {
        $notification = $this->repository->findForUser($user->getAuthIdentifier(), $notificationId);
        if (! $notification) {
            return null;
        }

        $notification->markAsRead($user->getAuthIdentifier());
        $this->settings->forgetUserNotificationCache((int) $user->getAuthIdentifier());

        return $notification->fresh();
    }

    public function markAllAsRead(Authenticatable $user, ?string $legacyType = null): void
    {
        if ($legacyType === null || $legacyType === 'all') {
            $legacyTypes = array_values(array_unique([
                ...config('notification-center.legacy_api_types', []),
                'stock_out',
                'product_expired',
                'product_expiring',
                'invoice_due',
            ]));

            foreach ($legacyTypes as $type) {
                $this->markAllLegacyTypeAsRead($user, $type);
            }

            $this->repository->markAllAsRead($user);
            $this->settings->forgetUserNotificationCache((int) $user->getAuthIdentifier());

            return;
        }

        $this->markAllLegacyTypeAsRead($user, $legacyType);
        $this->settings->forgetUserNotificationCache((int) $user->getAuthIdentifier());
    }

    public function archive(Notification $notification): Notification
    {
        $notification->markAsArchived();

        return $notification->fresh();
    }

    public function resolve(Notification $notification, ?Authenticatable $resolver = null): Notification
    {
        $notification->markAsResolved($resolver?->getAuthIdentifier());

        return $notification->fresh();
    }

    public function deleteRead(?int $userId = null): int
    {
        return $this->repository->deleteRead($userId);
    }

    public function listForUser(int $userId, ?int $limit = null): Collection
    {
        return $this->repository->getForUser($userId, $limit);
    }

    /**
     * @return array{total: int, critical: int, warning: int, info: int}
     */
    public function getUnreadCountsForUser(int $userId): array
    {
        return $this->repository->getUnreadCountsByPriority($userId);
    }

    protected function markAllLegacyTypeAsRead(Authenticatable $user, string $legacyType): void
    {
        $entityIds = $this->repository->getLegacyActiveEntityIds($legacyType, $user);
        $this->repository->markLegacyBulkAsRead($user, $legacyType, $entityIds);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function broadcastGrouped(int $userId, string $type, array $metadata): void
    {
        $legacyType = NotificationTypeConfig::toLegacyType($type) ?? $type;
        $entityIds = array_map('intval', $metadata['entity_ids'] ?? []);

        if ($entityIds !== []) {
            $this->repository->clearLegacyReads($userId, $legacyType, $entityIds);

            if ($legacyType === 'stock_out') {
                $this->repository->clearLegacyReads($userId, 'low_stock', $entityIds);
            } elseif ($legacyType === 'low_stock') {
                $this->repository->clearLegacyReads($userId, 'stock_out', $entityIds);
            } elseif (in_array($legacyType, ['product_expired', 'product_expiring'], true)) {
                $this->repository->clearLegacyReads($userId, 'expiring_product', $entityIds);
            }
        }

        $this->broadcast([
            'type' => $legacyType ?? $type,
            'id' => 0,
            'grouped' => true,
            'count' => $metadata['count'] ?? 0,
            'entity_ids' => $metadata['entity_ids'] ?? [],
            'products' => $metadata['products'] ?? [],
            'message' => $metadata['message'] ?? null,
            'title' => $metadata['title'] ?? null,
            'description' => $metadata['description'] ?? null,
            'priority' => $this->settings->getPriorityForType($type),
        ], $userId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $payload, int $userId): void
    {
        if (! $this->settings->isRealtimeEnabled()) {
            return;
        }

        $priority = (string) ($payload['priority'] ?? 'info');
        $type = (string) ($payload['type'] ?? '');
        $isCritical = $priority === 'critical';
        $isGrouped = ! empty($payload['grouped']);
        $immediateTypes = config('notification-center.realtime.immediate_types', []);
        $isImmediate = $isCritical
            || $type === 'test'
            || $isGrouped
            || in_array($type, $immediateTypes, true);
        $queueEnabled = (bool) config('notification-center.queue.enabled', true);

        if ($isImmediate || ! $queueEnabled) {
            $this->dispatchImmediateBroadcast($payload, $userId);

            return;
        }

        SendNotificationJob::dispatch($payload, $userId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function dispatchImmediateBroadcast(array $payload, int $userId): void
    {
        $startedAt = microtime(true);

        try {
            $this->realtimeProvider->broadcast($payload, $userId);

            $this->settings->touchRealtimeMeta([
                'last_event_at' => now()->toIso8601String(),
                'last_event_type' => $payload['type'] ?? null,
            ]);

            $this->settings->recordMonitoringMetric([
                'last_notification_sent_at' => now()->toIso8601String(),
                'last_send_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable $e) {
            NotificationLogger::pusherError('Échec broadcast Pusher', [
                'user_id' => $userId,
                'type' => $payload['type'] ?? null,
            ], $e);

            $this->settings->recordMonitoringMetric([
                'last_error_at' => now()->toIso8601String(),
                'last_error' => $e->getMessage(),
                'last_error_context' => 'pusher_broadcast',
            ]);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildBroadcastPayload(Notification $notification): array
    {
        $payload = [
            'type' => $notification->legacy_type ?? $notification->type,
            'id' => $notification->resolved_entity_id,
            'priority' => $notification->priority?->value,
            'status' => $notification->status?->value,
            'title' => $notification->title,
            'description' => $notification->description,
        ];

        if (is_array($notification->metadata)) {
            $payload = [...$payload, ...$notification->metadata];
        }

        return $payload;
    }
}
