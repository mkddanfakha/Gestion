<?php

namespace App\Enums;

enum NotificationType: string
{
    case StockOut = 'stock_out';
    case LowStock = 'low_stock';
    case ProductExpired = 'product_expired';
    case ProductExpiring = 'product_expiring';
    case InvoiceDue = 'invoice_due';
    case PurchaseOrderReceived = 'purchase_order_received';
    case SaleCompleted = 'sale_completed';
    case SaleReturned = 'sale_returned';
    case SaleCancelled = 'sale_cancelled';
    case DeliveryNoteReceived = 'delivery_note_received';
    case BackupSuccess = 'backup_success';
    case BackupFailed = 'backup_failed';
    case UserCreated = 'user_created';
    case UserDeleted = 'user_deleted';
    case SystemError = 'system_error';
    case SystemInfo = 'system_info';

    /**
     * Convertit un type legacy (système existant) vers l'enum.
     */
    public static function fromLegacy(string $legacyType): ?self
    {
        $mapping = config('notifications.legacy_types', []);

        if (! isset($mapping[$legacyType])) {
            return null;
        }

        return self::tryFrom($mapping[$legacyType]);
    }

    /**
     * Retourne le type legacy associé, si applicable.
     */
    public function toLegacyType(): ?string
    {
        foreach (config('notifications.legacy_types', []) as $legacy => $enumValue) {
            if ($enumValue === $this->value) {
                return $legacy;
            }
        }

        return null;
    }

    public function label(): string
    {
        return config("notifications.types.{$this->value}.label", $this->value);
    }

    public function defaultPriority(): NotificationPriority
    {
        $priority = config("notifications.types.{$this->value}.priority", NotificationPriority::Info->value);

        return NotificationPriority::from($priority);
    }

    public function entityType(): ?string
    {
        return config("notifications.types.{$this->value}.entity_type");
    }

    public function isRealtime(): bool
    {
        return (bool) config("notifications.types.{$this->value}.realtime", true);
    }

    public function isGroupable(): bool
    {
        return in_array($this->value, config('notifications.groupable_types', []), true);
    }

    public function isSellerScoped(): bool
    {
        return in_array($this->value, config('notifications.seller_scoped_types', []), true);
    }

    public function groupedKey(): string
    {
        return $this->value . ':grouped';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
