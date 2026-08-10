<?php

namespace App\Modules\NotificationCenter\Tests\Feature;

use App\Models\User;
use App\Modules\NotificationCenter\Jobs\SendNotificationJob;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Services\NotificationHealthService;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NotificationProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_monitoring_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/notifications/settings/monitoring');

        $response->assertOk()->assertJsonStructure([
            'stats' => ['total', 'active', 'archived', 'unread', 'critical'],
            'metrics',
            'realtime',
            'health',
        ]);
    }

    public function test_admin_can_access_health_check(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/notifications/settings/health');

        $response->assertOk()->assertJsonStructure([
            'status',
            'checked_at',
            'checks' => ['database', 'settings', 'cache', 'queue', 'scheduler', 'pusher'],
        ]);
    }

    public function test_non_admin_cannot_access_monitoring(): void
    {
        $user = User::factory()->create(['role' => 'vendeur', 'is_active' => true]);

        $this->actingAs($user)->getJson('/api/notifications/settings/monitoring')->assertForbidden();
    }

    public function test_user_preferences_are_cached_and_invalidated(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $settings = app(NotificationSettingsService::class);

        $settings->getUserPreferences($user->id);
        $cacheKey = sprintf('notification-center.user_prefs.%d', $user->id);
        $this->assertTrue(Cache::has($cacheKey));

        $settings->updateUserPreferences($user->id, ['toasts_enabled' => false]);
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_unread_count_is_cached(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $settings = app(NotificationSettingsService::class);

        Notification::create([
            'user_id' => $user->id,
            'notification_type' => 'system_info',
            'notification_id' => 1,
            'type' => 'system_info',
            'priority' => 'info',
            'status' => 'active',
            'metadata' => ['title' => 'Test'],
        ]);

        $count = $settings->getUnreadCountForUser($user->id);
        $this->assertSame(1, $count);
        $this->assertTrue(Cache::has(sprintf('notification-center.unread_count.%d', $user->id)));
    }

    public function test_non_critical_broadcast_is_queued(): void
    {
        Bus::fake();
        config(['notification-center.queue.enabled' => true]);

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $service = app(\App\Modules\NotificationCenter\Services\NotificationService::class);

        $service->broadcast(['type' => 'low_stock', 'id' => 1, 'priority' => 'warning'], $user->id);

        Bus::assertDispatched(SendNotificationJob::class);
    }

    public function test_grouped_low_stock_broadcast_is_immediate(): void
    {
        Bus::fake();
        config(['notification-center.queue.enabled' => true]);

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $service = app(\App\Modules\NotificationCenter\Services\NotificationService::class);

        $service->broadcast([
            'type' => 'low_stock',
            'id' => 0,
            'grouped' => true,
            'count' => 2,
            'message' => '2 produits en stock faible',
            'priority' => 'warning',
        ], $user->id);

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_critical_broadcast_is_immediate(): void
    {
        Bus::fake();
        config(['notification-center.queue.enabled' => true]);

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $service = app(\App\Modules\NotificationCenter\Services\NotificationService::class);

        $service->broadcast(['type' => 'stock_out', 'id' => 1, 'priority' => 'critical'], $user->id);

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_client_log_endpoint_records_error(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($user)->postJson('/api/notifications/client-log', [
            'context' => 'sound',
            'message' => 'Test erreur son',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_archive_resolved_command(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        Notification::create([
            'user_id' => $user->id,
            'notification_type' => 'system_info',
            'notification_id' => 1,
            'type' => 'system_info',
            'priority' => 'info',
            'status' => 'resolved',
            'resolved_at' => now()->subDays(40),
            'metadata' => ['title' => 'Old'],
        ]);

        $this->artisan('notifications:archive-resolved', ['--sync' => true, '--days' => 30])
            ->assertSuccessful();

        $this->assertDatabaseHas('notification_reads', [
            'user_id' => $user->id,
            'status' => 'archived',
        ]);
    }

    public function test_scheduler_run_is_recorded(): void
    {
        NotificationHealthService::markSchedulerRun('notifications:cleanup');

        $health = app(NotificationHealthService::class)->check();

        $this->assertNotNull($health['checks']['scheduler']['last_run'] ?? null);
    }
}
