<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Modules\NotificationCenter\DTO\CreateNotificationData;
use App\Modules\NotificationCenter\Contracts\AudienceResolverInterface;
use App\Modules\NotificationCenter\Contracts\NotificationRepositoryInterface;
use App\Modules\NotificationCenter\Contracts\RealtimeProviderInterface;
use App\Modules\NotificationCenter\Enums\NotificationPriority;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use App\Modules\NotificationCenter\Services\NotificationService as ModuleNotificationService;
use Illuminate\Support\Collection;

/**
 * Façade application Gestion — délègue au module + logique métier produits/ventes.
 */
class NotificationService extends ModuleNotificationService
{
    public function __construct(
        protected NotificationRepositoryInterface $repository,
        protected AudienceResolverInterface $audienceResolver,
        protected RealtimeProviderInterface $realtimeProvider,
        protected NotificationSettingsService $settings,
    ) {
        parent::__construct($repository, $audienceResolver, $realtimeProvider, $settings);
    }

    /**
     * @param  array{
     *     type: NotificationType,
     *     entity_type?: string|null,
     *     entity_id?: int|null,
     *     owner_user_id?: int|null,
     *     created_by?: int|null,
     *     metadata?: array|null,
     *     activity_log_id?: int|null,
     *     broadcast?: bool,
     *     broadcast_payload?: array|null,
     * }  $data
     * @return Collection<int, Notification>
     */
    public function dispatch(array $data): Collection
    {
        $type = $data['type'];
        $typeValue = $type instanceof NotificationType ? $type->value : (string) $type;

        if ($type instanceof NotificationType && $type->isGroupable()) {
            return collect([$this->syncGroupedAlert($typeValue)]);
        }

        $userIds = $this->audienceResolver->resolveUserIds(
            $typeValue,
            $data['owner_user_id'] ?? null
        );

        return collect($userIds)->map(function (int $userId) use ($data, $type, $typeValue) {
            $entityId = $data['entity_id'] ?? null;

            if ($entityId !== null && $this->repository->findActiveForEntity($userId, $typeValue, (int) $entityId)) {
                return null;
            }

            return $this->createFromLegacyArray([...$data, 'user_id' => $userId], $type);
        })->filter();
    }

    /**
     * Crée une notification à partir du format legacy (enum NotificationType + tableau).
     *
     * @param  array{
     *     type: NotificationType,
     *     user_id: int,
     *     entity_type?: string|null,
     *     entity_id?: int|null,
     *     created_by?: int|null,
     *     metadata?: array|null,
     *     activity_log_id?: int|null,
     *     broadcast?: bool,
     *     broadcast_payload?: array|null,
     *     group_key?: string|null,
     * }  $data
     */
    public function createLegacy(array $data): Notification
    {
        return $this->createFromLegacyArray($data, $data['type']);
    }

    public function createForUser(int $userId, array $data): Notification
    {
        return $this->createFromLegacyArray([...$data, 'user_id' => $userId], $data['type']);
    }

    public function syncGroupedAlert(NotificationType|string $type): ?Notification
    {
        $typeValue = $type instanceof NotificationType ? $type->value : $type;

        return parent::syncGroupedAlert($typeValue);
    }

    public function handleProductStockChange(Product $product, ?int $activityLogId = null): void
    {
        $this->autoResolveProductStockAlerts($product);

        if ($product->stock_quantity <= 0) {
            $this->syncGroupedAlert(NotificationType::StockOut);
        } else {
            $this->repository->resolveActiveForEntity(NotificationType::StockOut->value, 'product', $product->id);
            $this->syncGroupedAlert(NotificationType::StockOut);
        }

        if ($product->stock_quantity > 0 && $product->isLowStock()) {
            $this->syncGroupedAlert(NotificationType::LowStock);
        } else {
            $this->repository->resolveActiveForEntity(NotificationType::LowStock->value, 'product', $product->id);
            $this->syncGroupedAlert(NotificationType::LowStock);
        }
    }

    public function handleProductExpirationChange(Product $product, ?int $activityLogId = null): void
    {
        if ($product->isExpired()) {
            $this->repository->resolveActiveForEntity(NotificationType::ProductExpiring->value, 'product', $product->id);
            $this->syncGroupedAlert(NotificationType::ProductExpiring);
            $this->syncGroupedAlert(NotificationType::ProductExpired);
        } elseif ($product->isExpiringSoon()) {
            $this->repository->resolveActiveForEntity(NotificationType::ProductExpired->value, 'product', $product->id);
            $this->syncGroupedAlert(NotificationType::ProductExpired);
            $this->syncGroupedAlert(NotificationType::ProductExpiring);
        } else {
            $this->resolveProductExpirationAlerts($product);
        }
    }

    public function resolveProductNotifications(Product $product, ?User $resolver = null): void
    {
        foreach ([NotificationType::StockOut, NotificationType::LowStock, NotificationType::ProductExpired, NotificationType::ProductExpiring] as $type) {
            $this->repository->resolveActiveForEntity($type->value, 'product', $product->id, $resolver);
        }

        $this->syncGroupedAlert(NotificationType::StockOut);
        $this->syncGroupedAlert(NotificationType::LowStock);
        $this->syncGroupedAlert(NotificationType::ProductExpired);
        $this->syncGroupedAlert(NotificationType::ProductExpiring);
    }

    public function handleSaleInvoiceDue(Sale $sale): void
    {
        if ($sale->due_date && $sale->due_date->isToday() && $sale->payment_status !== 'paid') {
            $this->syncGroupedAlert(NotificationType::InvoiceDue);
        } else {
            $this->repository->resolveActiveForEntity(NotificationType::InvoiceDue->value, 'sale', $sale->id);
            $this->syncGroupedAlert(NotificationType::InvoiceDue);
        }
    }

    public function handleSaleCompleted(Sale $sale): void
    {
        if (! $sale->user_id) {
            return;
        }

        $this->dispatch([
            'type' => NotificationType::SaleCompleted,
            'entity_id' => $sale->id,
            'owner_user_id' => $sale->user_id,
            'metadata' => [
                'sale_number' => $sale->sale_number,
                'total_amount' => $sale->total_amount,
            ],
            'broadcast_payload' => [
                'type' => NotificationType::SaleCompleted->value,
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
            ],
        ]);
    }

    public function handleSaleCancelled(Sale $sale): void
    {
        $this->repository->resolveActiveForEntity(NotificationType::InvoiceDue->value, 'sale', $sale->id);
        $this->syncGroupedAlert(NotificationType::InvoiceDue);

        if (! $sale->user_id) {
            return;
        }

        $this->dispatch([
            'type' => NotificationType::SaleCancelled,
            'entity_id' => $sale->id,
            'owner_user_id' => $sale->user_id,
            'metadata' => ['sale_number' => $sale->sale_number],
            'broadcast_payload' => [
                'type' => NotificationType::SaleCancelled->value,
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
            ],
        ]);
    }

    public static function notifySaleDueToday(Sale $sale): void
    {
        app(self::class)->handleSaleInvoiceDue($sale);
    }

    public static function notifyLowStock(Product $product, ?int $userId = null): void
    {
        app(self::class)->handleProductStockChange($product);
    }

    public static function notifyExpiringProduct(Product $product, ?int $userId = null): void
    {
        app(self::class)->handleProductExpirationChange($product);
    }

    protected function createFromLegacyArray(array $data, NotificationType $type): Notification
    {
        $entityId = $data['entity_id'] ?? null;
        $metadata = array_merge($data['metadata'] ?? [], [
            'type_label' => $type->label(),
        ]);

        if (isset($data['activity_log_id'])) {
            $metadata['activity_log_id'] = $data['activity_log_id'];
        }

        return parent::create(new CreateNotificationData(
            userId: (int) $data['user_id'],
            title: $metadata['title'] ?? $type->label(),
            description: $metadata['description'] ?? $metadata['message'] ?? '',
            type: $type->value,
            priority: NotificationPriority::from($type->defaultPriority()->value),
            entityType: $data['entity_type'] ?? $type->entityType(),
            entityId: $entityId,
            groupKey: $data['group_key'] ?? null,
            createdBy: $data['created_by'] ?? auth()->id(),
            metadata: $metadata,
            broadcast: $data['broadcast'] ?? $type->isRealtime(),
            broadcastPayload: $data['broadcast_payload'] ?? null,
            legacyType: $type->toLegacyType(),
        ));
    }

    protected function autoResolveProductStockAlerts(Product $product): void
    {
        if ($product->stock_quantity > $product->min_stock_level) {
            $this->repository->resolveActiveForEntity(NotificationType::LowStock->value, 'product', $product->id);
        }

        if ($product->stock_quantity > 0) {
            $this->repository->resolveActiveForEntity(NotificationType::StockOut->value, 'product', $product->id);
        }
    }

    protected function resolveProductExpirationAlerts(Product $product): void
    {
        foreach ([NotificationType::ProductExpired, NotificationType::ProductExpiring] as $type) {
            $this->repository->resolveActiveForEntity($type->value, 'product', $product->id);
        }

        $this->syncGroupedAlert(NotificationType::ProductExpired);
        $this->syncGroupedAlert(NotificationType::ProductExpiring);
    }
}
