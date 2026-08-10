<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function __construct(
        protected NotificationSettingsService $settings,
    ) {}

    public function edit(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $payload = $this->settings->toAdminPayload();
        $payload['realtime'] = array_merge($payload['realtime'], [
            'pusher_configured' => ! empty(config('broadcasting.connections.pusher.key')),
            'connected_user' => $request->user()?->only(['id', 'name', 'email']),
        ]);

        return Inertia::render('Admin/Settings/Notifications', [
            'settings' => $payload,
        ]);
    }

    protected function authorizeAdmin(Request $request): void
    {
        if (! $request->user()?->isAdmin()) {
            abort(403);
        }
    }
}
