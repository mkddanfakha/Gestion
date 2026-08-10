<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    public function __construct(
        protected NotificationSettingsService $settings,
    ) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Notifications', [
            'preferencesPayload' => $this->settings->getPreferencesPayload($request->user()->id),
        ]);
    }
}
