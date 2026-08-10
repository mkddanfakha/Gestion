<?php

namespace App\Modules\NotificationCenter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\NotificationCenter\Support\NotificationLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationClientLogController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context' => 'required|string|in:browser,sound,api,pusher,realtime',
            'message' => 'required|string|max:2000',
            'details' => 'nullable|array',
        ]);

        $user = Auth::user();

        NotificationLogger::error('[client:'.$validated['context'].'] '.$validated['message'], [
            'user_id' => $user?->getAuthIdentifier(),
            'details' => $validated['details'] ?? [],
        ]);

        return response()->json(['success' => true]);
    }
}
