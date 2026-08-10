<?php

namespace App\Modules\NotificationCenter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserNotificationPreferencesController extends Controller
{
    public function __construct(
        protected NotificationSettingsService $settings,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(
            $this->settings->getPreferencesPayload($request->user()->id)
        );
    }

    public function update(Request $request): JsonResponse
    {
        $soundKeys = implode(',', NotificationSettingsService::SOUND_PROFILES);
        $positionKeys = implode(',', array_keys(config('notification-center.user_preferences.toast_positions', [])));

        $validated = $request->validate([
            'toasts_enabled' => 'boolean',
            'sound_enabled' => 'boolean',
            'browser_enabled' => 'boolean',
            'critical_only' => 'boolean',
            'hide_resolved' => 'boolean',
            'sound_profile' => "nullable|string|in:{$soundKeys}",
            'toast_position' => "nullable|string|in:{$positionKeys}",
            'toast_durations' => 'nullable|array',
            'toast_durations.critical' => 'integer|min:1000|max:60000',
            'toast_durations.warning' => 'integer|min:1000|max:60000',
            'toast_durations.info' => 'integer|min:1000|max:60000',
            'sound_volume' => 'numeric|min:0|max:1',
            'sound_profiles' => 'nullable|array',
            'sound_profiles.info' => "string|in:{$soundKeys}",
            'sound_profiles.warning' => "string|in:{$soundKeys}",
            'sound_profiles.critical' => "string|in:{$soundKeys}",
            'auto_mark_read_on_open' => 'boolean',
            'grouping_enabled' => 'boolean',
        ]);

        $this->settings->updateUserPreferences($request->user()->id, $validated);

        return response()->json([
            'success' => true,
            ...$this->settings->getPreferencesPayload($request->user()->id),
        ]);
    }
}
