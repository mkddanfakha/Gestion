<?php

namespace App\Modules\NotificationCenter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingsApiController extends Controller
{
    public function __construct(
        protected NotificationSettingsService $settings,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->toAdminPayload());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'global' => 'required|array',
            'global.enabled' => 'boolean',
            'global.realtime_enabled' => 'boolean',
            'global.browser_enabled' => 'boolean',
            'global.sound_enabled' => 'boolean',
            'global.toasts_enabled' => 'boolean',
            'global.badge_enabled' => 'boolean',
            'global.grouping_enabled' => 'boolean',
            'global.auto_mark_read_on_open' => 'boolean',
            'global.default_sound' => 'string|in:silent,discrete,classic,critical',
            'global.maintenance_cleanup_days' => 'integer|min:1|max:3650',
            'types' => 'array',
            'types.*.type' => 'required|string',
            'types.*.priority' => 'required|in:critical,warning,info',
            'types.*.recipients' => 'required|array',
            'types.*.channels' => 'required|array',
            'types.*.enabled' => 'boolean',
        ]);

        $this->settings->updateGlobal($validated['global']);

        if (! empty($validated['types'])) {
            $this->settings->updateTypeSettings($validated['types']);
        }

        return response()->json([
            'success' => true,
            'data' => $this->settings->toAdminPayload(),
        ]);
    }

    public function maintenanceArchive(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', 30);
        $count = $this->settings->archiveOldNotifications($days);

        return response()->json(['success' => true, 'archived' => $count]);
    }

    public function maintenanceDeleteArchived(): JsonResponse
    {
        $count = $this->settings->deleteArchivedNotifications();

        return response()->json(['success' => true, 'deleted' => $count]);
    }

    public function maintenanceCleanup(): JsonResponse
    {
        $count = $this->settings->cleanupOlderThan();

        return response()->json(['success' => true, 'deleted' => $count]);
    }
}
