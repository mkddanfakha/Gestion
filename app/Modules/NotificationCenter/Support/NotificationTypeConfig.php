<?php

namespace App\Modules\NotificationCenter\Support;

use App\Modules\NotificationCenter\Services\NotificationSettingsService;

/**
 * Helper config-driven — aucun type métier codé en dur.
 */
final class NotificationTypeConfig
{
    public static function label(string $type): string
    {
        return (string) config("notification-center.types.{$type}.label", $type);
    }

    public static function defaultPriority(string $type): string
    {
        return (string) config("notification-center.types.{$type}.priority", 'info');
    }

    public static function entityType(string $type): ?string
    {
        $value = config("notification-center.types.{$type}.entity_type");

        return $value !== null ? (string) $value : null;
    }

    public static function isRealtime(string $type): bool
    {
        return (bool) config("notification-center.types.{$type}.realtime", true);
    }

    public static function isGroupable(string $type): bool
    {
        if (! in_array($type, config('notification-center.groupable_types', []), true)) {
            return false;
        }

        if (app()->bound(NotificationSettingsService::class)) {
            return app(NotificationSettingsService::class)->isGroupingEnabled();
        }

        return true;
    }

    public static function isSellerScoped(string $type): bool
    {
        return in_array($type, config('notification-center.seller_scoped_types', []), true);
    }

    public static function groupedKey(string $type): string
    {
        return $type . ':grouped';
    }

    public static function fromLegacy(string $legacyType): ?string
    {
        $mapping = config('notification-center.legacy_types', []);

        return isset($mapping[$legacyType]) ? (string) $mapping[$legacyType] : null;
    }

    public static function toLegacyType(string $type): ?string
    {
        foreach (config('notification-center.legacy_types', []) as $legacy => $enumValue) {
            if ($enumValue === $type) {
                return (string) $legacy;
            }
        }

        return null;
    }

    public static function groupedMessage(string $type, int $count): string
    {
        $template = (string) config("notification-center.grouped_messages.{$type}", ':count élément(s)');

        return __($template, ['count' => $count]);
    }
}
