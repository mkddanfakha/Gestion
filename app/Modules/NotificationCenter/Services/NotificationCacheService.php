<?php

namespace App\Modules\NotificationCenter\Services;

use App\Modules\NotificationCenter\Models\UserNotificationPreference;
use Closure;
use Illuminate\Support\Facades\Cache;

class NotificationCacheService
{
    private const CACHE_USER_PREFS = 'notification-center.user_prefs.%d';

    private const CACHE_UNREAD_COUNT = 'notification-center.unread_count.%d';

    public function settingsTtlMinutes(): int
    {
        return (int) config('notification-center.cache.settings_ttl_minutes', 10);
    }

    public function userPreferencesTtlMinutes(): int
    {
        return (int) config('notification-center.cache.user_preferences_ttl_minutes', 10);
    }

    public function unreadCountTtlSeconds(): int
    {
        return (int) config('notification-center.cache.unread_count_ttl_seconds', 60);
    }

    public function rememberUserPreferences(int $userId, Closure $callback): UserNotificationPreference
    {
        return Cache::remember(
            sprintf(self::CACHE_USER_PREFS, $userId),
            now()->addMinutes($this->userPreferencesTtlMinutes()),
            $callback
        );
    }

    public function forgetUserPreferences(int $userId): void
    {
        Cache::forget(sprintf(self::CACHE_USER_PREFS, $userId));
    }

    public function rememberUnreadCount(int $userId, Closure $callback): int
    {
        return (int) Cache::remember(
            sprintf(self::CACHE_UNREAD_COUNT, $userId),
            now()->addSeconds($this->unreadCountTtlSeconds()),
            $callback
        );
    }

    public function forgetUnreadCount(int $userId): void
    {
        Cache::forget(sprintf(self::CACHE_UNREAD_COUNT, $userId));
    }

    public function forgetAllForUser(int $userId): void
    {
        $this->forgetUserPreferences($userId);
        $this->forgetUnreadCount($userId);
    }
}
