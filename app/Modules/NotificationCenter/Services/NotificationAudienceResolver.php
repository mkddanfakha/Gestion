<?php

namespace App\Modules\NotificationCenter\Services;

use App\Modules\NotificationCenter\Contracts\AudienceResolverInterface;
use App\Modules\NotificationCenter\Enums\NotificationAudience;
use App\Modules\NotificationCenter\Support\NotificationTypeConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

class NotificationAudienceResolver implements AudienceResolverInterface
{
    public function resolveUserIds(string $type, ?int $ownerUserId = null): array
    {
        $settings = app(NotificationSettingsService::class);

        if (! $settings->isNotificationsEnabled() || ! $settings->isTypeEnabled($type)) {
            return [];
        }

        if (NotificationTypeConfig::isSellerScoped($type)) {
            return $this->resolveSellerScoped($type, $ownerUserId, $settings);
        }

        $audiences = $settings->getAudiencesForType($type);

        if ($audiences === []) {
            $audiences = config("notification-center.audience_rules.{$type}", []);
        }

        if ($audiences === []) {
            return [];
        }

        $roles = collect($audiences)
            ->map(fn (string $audience) => NotificationAudience::from($audience)->userRole())
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->getActiveUserIdsByRoles($roles);
    }

    public function canReceiveType(Authenticatable $user, string $capability): bool
    {
        return app(NotificationSettingsService::class)->canReceiveType($user, $capability);
    }

    /**
     * @return list<int>
     */
    protected function resolveSellerScoped(string $type, ?int $ownerUserId, NotificationSettingsService $settings): array
    {
        if ($ownerUserId === null) {
            return [];
        }

        $userModel = config('notification-center.user_model');
        $owner = $userModel::query()
            ->where('id', $ownerUserId)
            ->where('is_active', true)
            ->first();

        if (! $owner) {
            return [];
        }

        $allowed = $settings->getAudiencesForType($type);

        if (! in_array(NotificationAudience::Seller->value, $allowed, true)) {
            return [];
        }

        return [$owner->id];
    }

    /**
     * @param  list<string>  $roles
     * @return list<int>
     */
    protected function getActiveUserIdsByRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        sort($roles);
        $cacheKey = 'notification-center.active_users.' . md5(implode(',', $roles));
        $userModel = config('notification-center.user_model');

        /** @var list<int> $ids */
        $ids = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($roles, $userModel) {
            return $userModel::query()
                ->where('is_active', true)
                ->whereIn('role', $roles)
                ->pluck('id')
                ->all();
        });

        return $ids;
    }
}
