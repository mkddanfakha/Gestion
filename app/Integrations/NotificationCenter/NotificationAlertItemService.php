<?php

namespace App\Integrations\NotificationCenter;

use App\Models\User;
use App\Modules\NotificationCenter\Contracts\GroupedEntityProviderInterface;
use App\Modules\NotificationCenter\Contracts\GroupedPreviewProviderInterface;
use App\Modules\NotificationCenter\Contracts\NotificationRepositoryInterface;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use App\Modules\NotificationCenter\Support\NotificationTypeConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Construit des alertes individuelles (1 produit / 1 entité) à partir des entités actives
 * et des lectures enregistrées — sans carte groupée intermédiaire.
 */
class NotificationAlertItemService
{
    /** @var list<string> */
    private const ALERT_TYPES = [
        'stock_out',
        'low_stock',
        'product_expired',
        'product_expiring',
        'invoice_due',
    ];

    /** @var array<string, int> */
    private const PRIORITY_ORDER = [
        'critical' => 0,
        'warning' => 1,
        'info' => 2,
    ];

    public function __construct(
        protected GroupedEntityProviderInterface $entityProvider,
        protected GroupedPreviewProviderInterface $previewProvider,
        protected NotificationRepositoryInterface $repository,
        protected NotificationSettingsService $settings,
    ) {}

    /**
     * @return array{total: int, critical: int, warning: int, info: int}
     */
    public function getUnreadCountsForUser(int $userId, ?Authenticatable $user = null): array
    {
        $counts = [
            'total' => 0,
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
        ];

        foreach (self::ALERT_TYPES as $type) {
            if (! $this->userCanReceiveType($user, $type)) {
                continue;
            }

            $unreadCount = count($this->getUnreadEntityIds($userId, $type, $user));
            if ($unreadCount === 0) {
                continue;
            }

            $priority = NotificationTypeConfig::defaultPriority($type);
            $counts[$priority] += $unreadCount;
            $counts['total'] += $unreadCount;
        }

        return $counts;
    }

    /**
     * @param  array{priority?: string|null, search?: string|null, page?: int, per_page?: int}  $options
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function paginateForUser(int $userId, array $options = [], ?Authenticatable $user = null): array
    {
        $priorityFilter = $options['priority'] ?? null;
        $search = mb_strtolower(trim((string) ($options['search'] ?? '')));
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($options['per_page'] ?? 5)));

        $stubs = $this->collectUnreadStubs($userId, $priorityFilter, $user);

        if ($search !== '') {
            $items = $this->buildItemsFromStubs($stubs, $user);
            $items = array_values(array_filter(
                $items,
                fn (array $item) => $this->matchesSearch($item, $search),
            ));
            $total = count($items);
            $slice = array_slice($items, ($page - 1) * $perPage, $perPage);
        } else {
            $total = count($stubs);
            $pageStubs = array_slice($stubs, ($page - 1) * $perPage, $perPage);
            $slice = $this->buildItemsFromStubs($pageStubs, $user);
        }

        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    /**
     * @return list<array{type: string, entity_id: int, priority: string, priority_order: int, sort_at: string}>
     */
    private function collectUnreadStubs(int $userId, ?string $priorityFilter, ?Authenticatable $user): array
    {
        $stubs = [];

        foreach (self::ALERT_TYPES as $type) {
            if (! $this->userCanReceiveType($user, $type)) {
                continue;
            }

            $priority = NotificationTypeConfig::defaultPriority($type);

            if ($priorityFilter !== null && $priorityFilter !== '' && $priority !== $priorityFilter) {
                continue;
            }

            $sortAt = $this->resolveSortTimestamp($userId, $type);

            foreach ($this->getUnreadEntityIds($userId, $type, $user) as $entityOrder => $entityId) {
                $stubs[] = [
                    'type' => $type,
                    'entity_id' => $entityId,
                    'priority' => $priority,
                    'priority_order' => self::PRIORITY_ORDER[$priority] ?? 9,
                    'sort_at' => $sortAt,
                    'entity_order' => $entityOrder,
                ];
            }
        }

        usort($stubs, function (array $a, array $b): int {
            if ($a['priority_order'] !== $b['priority_order']) {
                return $a['priority_order'] <=> $b['priority_order'];
            }

            if (
                $a['type'] === $b['type']
                && in_array($a['type'], ['product_expired', 'product_expiring'], true)
            ) {
                return ($a['entity_order'] ?? 0) <=> ($b['entity_order'] ?? 0);
            }

            return strcmp($b['sort_at'], $a['sort_at']);
        });

        return $stubs;
    }

    /**
     * @param  list<array{type: string, entity_id: int, priority: string, priority_order: int, sort_at: string}>  $stubs
     * @return list<array<string, mixed>>
     */
    private function buildItemsFromStubs(array $stubs, ?Authenticatable $user): array
    {
        if ($stubs === []) {
            return [];
        }

        $grouped = [];
        foreach ($stubs as $stub) {
            $grouped[$stub['type']][] = $stub['entity_id'];
        }

        $previewMap = [];
        foreach ($grouped as $type => $entityIds) {
            foreach ($this->previewProvider->buildPreviews($type, $entityIds, $user) as $preview) {
                $previewMap["{$type}:{$preview['id']}"] = $preview;
            }
        }

        $items = [];
        foreach ($stubs as $stub) {
            $key = "{$stub['type']}:{$stub['entity_id']}";
            $preview = $previewMap[$key] ?? null;

            if ($preview === null && in_array($stub['type'], ['stock_out', 'low_stock', 'product_expired', 'product_expiring'], true)) {
                continue;
            }

            $items[] = $this->formatAlertItem($stub, $preview);
        }

        return $items;
    }

    /**
     * @param  array{type: string, entity_id: int, priority: string, sort_at: string}  $stub
     * @param  array<string, mixed>|null  $preview
     * @return array<string, mixed>
     */
    private function formatAlertItem(array $stub, ?array $preview): array
    {
        $type = $stub['type'];
        $entityId = $stub['entity_id'];
        $title = match ($type) {
            'invoice_due' => 'Échéance de facture',
            default => NotificationTypeConfig::label($type),
        };
        $legacyType = $this->legacyKeyForType($type);

        $description = match ($type) {
            'stock_out' => 'Le produit est actuellement en rupture de stock.',
            'low_stock' => 'Le stock est inférieur au seuil minimum.',
            'product_expired' => 'Ce produit est périmé.',
            'product_expiring' => 'Ce produit expire bientôt.',
            'invoice_due' => 'Cette facture arrive à échéance aujourd\'hui.',
            default => 'Nouvelle alerte.',
        };

        if (
            $preview !== null
            && isset($preview['status'])
            && is_string($preview['status'])
            && ! in_array($type, ['product_expired', 'product_expiring'], true)
        ) {
            $description = (string) $preview['status'];
        }

        return [
            'id' => "{$type}:{$entityId}",
            'type' => $type,
            'priority' => $stub['priority'],
            'severity' => $stub['priority'],
            'title' => $title,
            'description' => $description,
            'message' => $description,
            'entity_id' => $entityId,
            'legacy_type' => $legacyType,
            'product' => $preview,
            'url' => $preview['url'] ?? null,
            'read_at' => null,
            'created_at' => $stub['sort_at'],
            'status' => 'active',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matchesSearch(array $item, string $search): bool
    {
        $product = is_array($item['product'] ?? null) ? $item['product'] : [];
        $haystacks = [
            (string) ($item['title'] ?? ''),
            (string) ($item['description'] ?? ''),
            (string) ($item['type'] ?? ''),
            (string) ($product['name'] ?? ''),
            (string) ($product['reference'] ?? ''),
            (string) ($product['sku'] ?? ''),
            (string) ($product['category'] ?? ''),
        ];

        foreach ($haystacks as $value) {
            if ($value !== '' && str_contains(mb_strtolower($value), $search)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function getUnreadEntityIds(int $userId, string $type, ?Authenticatable $user): array
    {
        $allIds = array_map('intval', $this->entityProvider->getEntityIdsForType($type, $user));
        $legacyType = $this->legacyKeyForType($type);
        $readIds = array_flip($this->getReadEntityIds($userId, $legacyType));

        return array_values(array_filter($allIds, fn (int $id) => ! isset($readIds[$id])));
    }

    /**
     * @return list<int>
     */
    private function getReadEntityIds(int $userId, string $legacyType): array
    {
        $grouped = $this->repository->getLegacyReadIdsByType($userId);
        $ids = array_map('intval', $grouped[$legacyType] ?? []);

        // Compatibilité système legacy (HandleInertiaRequests)
        if ($legacyType === 'stock_out') {
            $ids = array_merge($ids, array_map('intval', $grouped['low_stock'] ?? []));
        }

        if ($legacyType === 'product_expired' || $legacyType === 'product_expiring') {
            $ids = array_merge($ids, array_map('intval', $grouped['expiring_product'] ?? []));
        }

        if ($legacyType === 'invoice_due') {
            $ids = array_merge($ids, array_map('intval', $grouped['sale_due_today'] ?? []));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Réactive les alertes encore valides (produit toujours en alerte) en supprimant
     * les lectures enregistrées — permet d'afficher à nouveau les alertes actives.
     */
    public function reactivateActiveAlertEntities(int $userId, ?Authenticatable $user = null): void
    {
        foreach (self::ALERT_TYPES as $type) {
            if (! $this->userCanReceiveType($user, $type)) {
                continue;
            }

            $legacyType = $this->legacyKeyForType($type);
            $entityIds = array_map('intval', $this->entityProvider->getEntityIdsForType($type, $user));

            if ($entityIds === []) {
                continue;
            }

            $this->repository->clearLegacyReads($userId, $legacyType, $entityIds);

            foreach ($this->compatibleLegacyReadTypes($legacyType) as $compatibleType) {
                $this->repository->clearLegacyReads($userId, $compatibleType, $entityIds);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function compatibleLegacyReadTypes(string $legacyType): array
    {
        return match ($legacyType) {
            'stock_out' => ['low_stock'],
            'low_stock' => ['stock_out'],
            'product_expired', 'product_expiring' => ['expiring_product'],
            'invoice_due' => ['sale_due_today'],
            default => [],
        };
    }

    private function legacyKeyForType(string $type): string
    {
        return NotificationTypeConfig::toLegacyType($type) ?? $type;
    }

    private function resolveSortTimestamp(int $userId, string $type): string
    {
        $groupKey = NotificationTypeConfig::groupedKey($type);

        $notification = Notification::query()
            ->forUser($userId)
            ->where('type', $type)
            ->where('group_key', $groupKey)
            ->latest('updated_at')
            ->first();

        if ($notification?->updated_at instanceof Carbon) {
            return $notification->updated_at->toIso8601String();
        }

        return now()->toIso8601String();
    }

    private function userCanReceiveType(?Authenticatable $user, string $type): bool
    {
        if (! $user) {
            return false;
        }

        return $this->settings->userCanReceiveType($user, $type);
    }
}
