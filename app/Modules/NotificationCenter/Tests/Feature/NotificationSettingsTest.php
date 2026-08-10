<?php

namespace App\Modules\NotificationCenter\Tests\Feature;

use App\Models\User;
use App\Modules\NotificationCenter\Models\NotificationGlobalSetting;
use App\Modules\NotificationCenter\Models\NotificationTypeSetting;
use App\Modules\NotificationCenter\Services\NotificationAudienceResolver;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(NotificationSettingsService::class)->ensureDefaults();
    }

    public function test_admin_can_update_global_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->putJson('/api/notifications/settings', [
            'global' => [
                'enabled' => true,
                'realtime_enabled' => false,
                'browser_enabled' => true,
                'sound_enabled' => true,
                'toasts_enabled' => true,
                'badge_enabled' => true,
                'grouping_enabled' => false,
                'auto_mark_read_on_open' => true,
                'default_sound' => 'discrete',
                'maintenance_cleanup_days' => 60,
            ],
            'types' => [],
        ]);

        $response->assertOk()->assertJsonPath('data.global.realtime_enabled', false);

        $this->assertDatabaseHas('notification_global_settings', [
            'realtime_enabled' => false,
            'grouping_enabled' => false,
            'default_sound' => 'discrete',
        ]);
    }

    public function test_non_admin_cannot_update_settings(): void
    {
        $user = User::factory()->create(['role' => 'gestionnaire', 'is_active' => true]);

        $response = $this->actingAs($user)->putJson('/api/notifications/settings', [
            'global' => ['enabled' => false],
            'types' => [],
        ]);

        $response->assertForbidden();
    }

    public function test_audience_resolver_uses_database_recipients(): void
    {
        NotificationTypeSetting::query()->where('type', 'low_stock')->update([
            'recipients' => [
                'admin' => false,
                'manager' => false,
                'seller' => true,
            ],
        ]);

        app(NotificationSettingsService::class)->clearCache();

        $seller = User::factory()->create(['role' => 'vendeur', 'is_active' => true]);
        User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $resolver = app(NotificationAudienceResolver::class);
        $userIds = $resolver->resolveUserIds('low_stock');

        $this->assertSame([$seller->id], $userIds);
    }

    public function test_user_can_update_notification_preferences(): void
    {
        $user = User::factory()->create(['role' => 'gestionnaire', 'is_active' => true]);

        $response = $this->actingAs($user)->putJson('/api/user/notification-preferences', [
            'toasts_enabled' => false,
            'sound_enabled' => true,
            'browser_enabled' => false,
            'critical_only' => true,
            'hide_resolved' => false,
            'sound_volume' => 0.5,
            'toast_position' => 'top-right',
            'toast_durations' => ['critical' => 5000, 'warning' => 4000, 'info' => 3000],
            'sound_profiles' => ['info' => 'discrete', 'warning' => 'classic', 'critical' => 'critical'],
            'auto_mark_read_on_open' => false,
            'grouping_enabled' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('effective.critical_only', true)
            ->assertJsonPath('effective.toast_position', 'top-right');

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $user->id,
            'critical_only' => true,
            'toast_position' => 'top-right',
        ]);
    }

    public function test_preferences_payload_includes_effective_settings(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/user/notification-preferences');

        $response->assertOk()
            ->assertJsonStructure([
                'user',
                'effective' => [
                    'toasts_enabled',
                    'sound_enabled',
                    'toast_durations',
                    'sound_profiles',
                    'sound_catalog',
                ],
                'meta' => ['toast_positions', 'sound_profiles'],
                'global',
            ]);
    }

    public function test_settings_service_reads_priority_from_database(): void
    {
        NotificationTypeSetting::query()->where('type', 'product_expired')->update([
            'priority' => 'warning',
        ]);

        app(NotificationSettingsService::class)->clearCache();

        $priority = app(NotificationSettingsService::class)->getPriorityForType('product_expired');

        $this->assertSame('warning', $priority);
    }

    public function test_realtime_disabled_skips_broadcast(): void
    {
        NotificationGlobalSetting::query()->first()?->update(['realtime_enabled' => false]);
        app(NotificationSettingsService::class)->clearCache();

        $this->assertFalse(app(NotificationSettingsService::class)->isRealtimeEnabled());
    }
}
