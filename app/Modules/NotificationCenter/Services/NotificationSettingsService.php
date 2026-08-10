<?php

namespace App\Modules\NotificationCenter\Services;

use App\Modules\NotificationCenter\Enums\NotificationAudience;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Models\NotificationGlobalSetting;
use App\Modules\NotificationCenter\Models\NotificationTypeSetting;
use App\Modules\NotificationCenter\Models\UserNotificationPreference;
use App\Modules\NotificationCenter\Support\NotificationTypeConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NotificationSettingsService
{
    private const CACHE_GLOBAL = 'notification-center.settings.global';

    private const CACHE_TYPES = 'notification-center.settings.types';

    public function __construct(
        protected NotificationCacheService $cache,
    ) {}

    /** @var list<string> */
    public const CHANNELS = ['app', 'email', 'sms', 'whatsapp', 'push'];

    /** @var list<string> */
    public const RECIPIENT_KEYS = ['admin', 'manager', 'seller'];

    /** @var list<string> */
    public const SOUND_PROFILES = ['silent', 'discrete', 'classic', 'critical'];

    public function ensureDefaults(): void
    {
        if (NotificationGlobalSetting::query()->exists()) {
            $this->syncTypeSettingsFromConfig();

            return;
        }

        NotificationGlobalSetting::create([
            'enabled' => true,
            'realtime_enabled' => true,
            'browser_enabled' => false,
            'sound_enabled' => false,
            'toasts_enabled' => true,
            'badge_enabled' => true,
            'grouping_enabled' => true,
            'auto_mark_read_on_open' => false,
            'default_sound' => 'classic',
            'maintenance_cleanup_days' => (int) config('notification-center.delays.archive_after_days', 90),
        ]);

        $this->syncTypeSettingsFromConfig();
        $this->clearCache();
    }

    public function getGlobal(): NotificationGlobalSetting
    {
        return Cache::remember(self::CACHE_GLOBAL, now()->addMinutes($this->cache->settingsTtlMinutes()), function () {
            $this->ensureDefaults();

            return NotificationGlobalSetting::query()->firstOrFail();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGlobal(array $data): NotificationGlobalSetting
    {
        $settings = $this->getGlobal();
        $settings->update(collect($data)->only($settings->getFillable())->all());
        $this->clearCache();

        return $settings->fresh();
    }

    /**
     * @return Collection<int, NotificationTypeSetting>
     */
    public function getAllTypeSettings(): Collection
    {
        return Cache::remember(self::CACHE_TYPES, now()->addMinutes($this->cache->settingsTtlMinutes()), function () {
            $this->ensureDefaults();

            return NotificationTypeSetting::query()->orderBy('type')->get();
        });
    }

    public function getTypeSetting(string $type): ?NotificationTypeSetting
    {
        return $this->getAllTypeSettings()->firstWhere('type', $type);
    }

    /**
     * @param  array<int, array<string, mixed>>  $types
     */
    public function updateTypeSettings(array $types): void
    {
        foreach ($types as $row) {
            $type = (string) ($row['type'] ?? '');
            if ($type === '') {
                continue;
            }

            NotificationTypeSetting::query()->updateOrCreate(
                ['type' => $type],
                [
                    'priority' => (string) ($row['priority'] ?? 'info'),
                    'recipients' => $row['recipients'] ?? $this->defaultRecipients($type),
                    'channels' => $row['channels'] ?? $this->defaultChannels(),
                    'enabled' => (bool) ($row['enabled'] ?? true),
                ]
            );
        }

        $this->clearCache();
    }

    /**
     * @return list<string> Audience enum values (admin, manager, seller)
     */
    public function getAudiencesForType(string $type): array
    {
        $setting = $this->getTypeSetting($type);

        if ($setting && is_array($setting->recipients)) {
            return $this->recipientsToAudiences($setting->recipients);
        }

        return config("notification-center.audience_rules.{$type}", []);
    }

    public function getPriorityForType(string $type): string
    {
        $setting = $this->getTypeSetting($type);

        if ($setting?->priority) {
            return $setting->priority;
        }

        return NotificationTypeConfig::defaultPriority($type);
    }

    public function userCanReceiveType(Authenticatable $user, string $type): bool
    {
        if (! $this->isNotificationsEnabled() || ! $this->isTypeEnabled($type)) {
            return false;
        }

        $role = $user->role ?? null;
        if (! $role) {
            return false;
        }

        foreach ($this->getAudiencesForType($type) as $audience) {
            $audienceRole = NotificationAudience::tryFrom($audience)?->userRole();
            if ($audienceRole === $role) {
                return true;
            }
        }

        return false;
    }

    public function isTypeEnabled(string $type): bool
    {
        $setting = $this->getTypeSetting($type);

        return $setting?->enabled ?? true;
    }

    public function isChannelEnabled(string $type, string $channel): bool
    {
        $setting = $this->getTypeSetting($type);
        $channels = $setting?->channels ?? $this->defaultChannels();

        return (bool) ($channels[$channel] ?? ($channel === 'app'));
    }

    public function isNotificationsEnabled(): bool
    {
        return $this->getGlobal()->enabled;
    }

    public function isRealtimeEnabled(): bool
    {
        return $this->getGlobal()->enabled && $this->getGlobal()->realtime_enabled;
    }

    public function isGroupingEnabled(): bool
    {
        return $this->getGlobal()->grouping_enabled;
    }

    public function shouldAutoMarkReadOnOpen(): bool
    {
        return $this->getGlobal()->auto_mark_read_on_open;
    }

    public function getUserPreferences(int $userId): UserNotificationPreference
    {
        return $this->cache->rememberUserPreferences($userId, function () use ($userId) {
            return UserNotificationPreference::query()->firstOrCreate(
                ['user_id' => $userId],
                $this->defaultUserPreferenceAttributes()
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultUserPreferenceAttributes(): array
    {
        $global = $this->getGlobal();
        $defaultSound = $global->default_sound ?: 'classic';

        return [
            'toasts_enabled' => true,
            'sound_enabled' => true,
            'browser_enabled' => false,
            'critical_only' => false,
            'hide_resolved' => true,
            'sound_profile' => $defaultSound,
            'toast_position' => array_key_first(config('notification-center.user_preferences.toast_positions', [])) ?: 'bottom-right',
            'toast_durations' => config('notification-center.user_preferences.default_toast_durations', []),
            'sound_volume' => (float) config('notification-center.user_preferences.default_sound_volume', 0.15),
            'sound_profiles' => [
                'info' => $defaultSound,
                'warning' => $defaultSound,
                'critical' => $defaultSound,
            ],
            'auto_mark_read_on_open' => false,
            'grouping_enabled' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildEffectivePreferences(int $userId): array
    {
        $global = $this->getGlobal();
        $user = $this->getUserPreferences($userId);
        $meta = $this->getPreferencesMeta();

        $toastDurations = is_array($user->toast_durations) && $user->toast_durations !== []
            ? $user->toast_durations
            : $meta['default_toast_durations'];

        $soundProfiles = is_array($user->sound_profiles) && $user->sound_profiles !== []
            ? $user->sound_profiles
            : [
                'info' => $user->sound_profile ?: $global->default_sound,
                'warning' => $user->sound_profile ?: $global->default_sound,
                'critical' => $user->sound_profile ?: $global->default_sound,
            ];

        return [
            'toasts_enabled' => $global->toasts_enabled && $user->toasts_enabled,
            'sound_enabled' => $global->sound_enabled && $user->sound_enabled,
            'browser_enabled' => $global->browser_enabled && $user->browser_enabled,
            'badge_enabled' => $global->badge_enabled,
            'critical_only' => $user->critical_only,
            'hide_resolved' => $user->hide_resolved,
            'auto_mark_read_on_open' => $global->auto_mark_read_on_open && $user->auto_mark_read_on_open,
            'grouping_enabled' => $global->grouping_enabled && $user->grouping_enabled,
            'toast_position' => $user->toast_position ?: 'bottom-right',
            'toast_durations' => $toastDurations,
            'sound_volume' => (float) ($user->sound_volume ?? $meta['default_sound_volume']),
            'sound_profiles' => $soundProfiles,
            'sound_catalog' => $meta['sound_profiles'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreferencesMeta(): array
    {
        $soundProfiles = config('notification-center.user_preferences.sound_profiles', []);

        return [
            'toast_positions' => config('notification-center.user_preferences.toast_positions', []),
            'default_toast_durations' => config('notification-center.user_preferences.default_toast_durations', []),
            'default_sound_volume' => (float) config('notification-center.user_preferences.default_sound_volume', 0.15),
            'sound_profiles' => $soundProfiles,
            'sound_profile_keys' => array_keys($soundProfiles),
            'priorities' => array_keys(config('notification-center.priorities', [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreferencesPayload(int $userId): array
    {
        $global = $this->getGlobal();
        $user = $this->getUserPreferences($userId);

        return [
            'user' => $user->toArray(),
            'effective' => $this->buildEffectivePreferences($userId),
            'meta' => $this->getPreferencesMeta(),
            'global' => [
                'toasts_enabled' => $global->toasts_enabled,
                'sound_enabled' => $global->sound_enabled,
                'browser_enabled' => $global->browser_enabled,
                'badge_enabled' => $global->badge_enabled,
                'grouping_enabled' => $global->grouping_enabled,
                'auto_mark_read_on_open' => $global->auto_mark_read_on_open,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateUserPreferences(int $userId, array $data): UserNotificationPreference
    {
        $prefs = UserNotificationPreference::query()->firstOrCreate(
            ['user_id' => $userId],
            $this->defaultUserPreferenceAttributes()
        );
        $prefs->update($data);
        $this->cache->forgetUserPreferences($userId);

        return $prefs->fresh();
    }

    public function getUnreadCountForUser(int $userId): int
    {
        return $this->cache->rememberUnreadCount($userId, function () use ($userId) {
            return Notification::query()
                ->forUser($userId)
                ->whereNull('read_at')
                ->where('status', 'active')
                ->count();
        });
    }

    public function forgetUserNotificationCache(int $userId): void
    {
        $this->cache->forgetAllForUser($userId);
    }

    /**
     * @param  array<string, mixed>  $partial
     */
    public function touchRealtimeMeta(array $partial): void
    {
        $settings = NotificationGlobalSetting::query()->first();
        if (! $settings) {
            return;
        }

        $meta = array_merge($settings->realtime_meta ?? [], $partial);
        $settings->update(['realtime_meta' => $meta]);

        $fresh = $settings->fresh();
        Cache::put(self::CACHE_GLOBAL, $fresh, now()->addMinutes($this->cache->settingsTtlMinutes()));
    }

    /**
     * @param  array<string, mixed>  $partial
     */
    public function recordMonitoringMetric(array $partial): void
    {
        $settings = NotificationGlobalSetting::query()->first();
        if (! $settings) {
            return;
        }

        $meta = array_merge($settings->monitoring_meta ?? [], $partial);

        if (isset($partial['last_send_duration_ms'])) {
            $previous = (int) ($meta['avg_send_duration_ms'] ?? 0);
            $samples = (int) ($meta['send_duration_samples'] ?? 0);
            $current = (int) $partial['last_send_duration_ms'];
            $meta['send_duration_samples'] = $samples + 1;
            $meta['avg_send_duration_ms'] = $samples === 0
                ? $current
                : (int) round(($previous * $samples + $current) / ($samples + 1));
        }

        if (isset($partial['last_processing_ms'])) {
            $previous = (int) ($meta['avg_processing_ms'] ?? 0);
            $samples = (int) ($meta['processing_samples'] ?? 0);
            $current = (int) $partial['last_processing_ms'];
            $meta['processing_samples'] = $samples + 1;
            $meta['avg_processing_ms'] = $samples === 0
                ? $current
                : (int) round(($previous * $samples + $current) / ($samples + 1));
        }

        $settings->update(['monitoring_meta' => $meta]);
    }

    public function userWantsToast(int $userId): bool
    {
        if (! $this->getGlobal()->toasts_enabled) {
            return false;
        }

        return $this->getUserPreferences($userId)->toasts_enabled;
    }

    public function userWantsSound(int $userId): bool
    {
        if (! $this->getGlobal()->sound_enabled) {
            return false;
        }

        return $this->getUserPreferences($userId)->sound_enabled;
    }

    /**
     * @return array<string, int>
     */
    public function getMaintenanceStats(): array
    {
        return [
            'total' => Notification::query()->count(),
            'active' => Notification::query()->where('status', 'active')->count(),
            'archived' => Notification::query()->where('status', 'archived')->count(),
            'unread' => Notification::query()->whereNull('read_at')->where('status', 'active')->count(),
            'critical' => Notification::query()->where('priority', 'critical')->where('status', 'active')->count(),
        ];
    }

    public function archiveOldNotifications(int $days = 30): int
    {
        return Notification::query()
            ->where('status', 'active')
            ->where('created_at', '<', now()->subDays($days))
            ->update(['status' => 'archived']);
    }

    public function archiveResolvedNotifications(int $days): int
    {
        return Notification::query()
            ->where('status', 'resolved')
            ->where('resolved_at', '<', now()->subDays($days))
            ->update(['status' => 'archived']);
    }

    public function deleteArchivedNotifications(): int
    {
        return Notification::query()->where('status', 'archived')->delete();
    }

    public function deleteArchivedOlderThan(int $days): int
    {
        return Notification::query()
            ->where('status', 'archived')
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();
    }

    public function cleanupOrphanNotifications(): int
    {
        $userModel = config('notification-center.user_model');
        $validUserIds = $userModel::query()->pluck('id');

        return Notification::query()
            ->whereNotIn('user_id', $validUserIds)
            ->delete();
    }

    public function cleanupOlderThan(?int $days = null): int
    {
        $days ??= $this->getGlobal()->maintenance_cleanup_days;

        return Notification::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    public function canReceiveType(Authenticatable $user, string $capability): bool
    {
        $roles = config("notification-center.{$capability}_roles", []);
        $role = $user->role ?? null;

        if ($role && in_array($role, $roles, true)) {
            return true;
        }

        if ($capability === 'inventory_alert') {
            return $this->userHasAnyTypeAudience($user, ['stock_out', 'low_stock', 'product_expired', 'product_expiring']);
        }

        if ($capability === 'invoice_alert') {
            return $this->userHasAnyTypeAudience($user, ['invoice_due']);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminPayload(): array
    {
        $global = $this->getGlobal();
        $types = $this->getAllTypeSettings()->map(fn (NotificationTypeSetting $t) => [
            'type' => $t->type,
            'label' => NotificationTypeConfig::label($t->type),
            'priority' => $t->priority,
            'recipients' => $t->recipients,
            'channels' => $t->channels,
            'enabled' => $t->enabled,
        ])->values();

        return [
            'global' => $global->toArray(),
            'types' => $types,
            'sound_profiles' => self::SOUND_PROFILES,
            'channels' => self::CHANNELS,
            'recipient_keys' => self::RECIPIENT_KEYS,
            'maintenance' => $this->getMaintenanceStats(),
            'monitoring' => $global->monitoring_meta ?? [],
            'realtime' => [
                'driver' => config('notification-center.realtime.driver', 'pusher'),
                'channel' => config('notification-center.realtime.channel', 'user.{userId}.notifications'),
                'event' => config('notification-center.realtime.event', 'notification.sent'),
                'meta' => $global->realtime_meta ?? [],
            ],
        ];
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_GLOBAL);
        Cache::forget(self::CACHE_TYPES);
    }

    protected function syncTypeSettingsFromConfig(): void
    {
        $types = config('notification-center.types', []);

        foreach ($types as $type => $config) {
            if (NotificationTypeSetting::query()->where('type', $type)->exists()) {
                continue;
            }

            NotificationTypeSetting::create([
                'type' => $type,
                'priority' => $config['priority'] ?? 'info',
                'recipients' => $this->defaultRecipients($type),
                'channels' => $this->defaultChannels(),
                'enabled' => true,
            ]);
        }
    }

    /**
     * @return array<string, bool>
     */
    protected function defaultRecipients(string $type): array
    {
        $audiences = config("notification-center.audience_rules.{$type}", ['admin']);

        return [
            'admin' => in_array('admin', $audiences, true),
            'manager' => in_array('manager', $audiences, true),
            'seller' => in_array('seller', $audiences, true),
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function defaultChannels(): array
    {
        return [
            'app' => true,
            'email' => false,
            'sms' => false,
            'whatsapp' => false,
            'push' => false,
        ];
    }

    /**
     * @param  array<string, bool>  $recipients
     * @return list<string>
     */
    protected function recipientsToAudiences(array $recipients): array
    {
        $audiences = [];

        foreach (self::RECIPIENT_KEYS as $key) {
            if (! empty($recipients[$key])) {
                $audiences[] = $key;
            }
        }

        return $audiences;
    }

    /**
     * @param  list<string>  $types
     */
    protected function userHasAnyTypeAudience(Authenticatable $user, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->userCanReceiveType($user, $type)) {
                return true;
            }
        }

        return false;
    }
}
