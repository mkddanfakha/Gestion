<?php

namespace App\Modules\NotificationCenter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\NotificationCenter\NotificationAlertItemService;
use App\Modules\NotificationCenter\Http\Resources\NotificationAlertItemResource;
use App\Modules\NotificationCenter\Models\Notification;
use App\Modules\NotificationCenter\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class NotificationApiController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService,
        protected NotificationAlertItemService $alertItemService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 401);

        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'priority' => 'sometimes|string|in:critical,warning,info',
            'severity' => 'sometimes|string|in:critical,warning,info',
            'search' => 'sometimes|string|max:255',
        ]);

        $priority = $request->query('priority') ?? $request->query('severity');
        if ($priority === 'all') {
            $priority = null;
        }

        $filter = $request->query('filter');
        if ($priority === null && in_array($filter, ['critical', 'warning', 'info'], true)) {
            $priority = $filter;
        }

        $result = $this->alertItemService->paginateForUser($user->id, [
            'priority' => $priority,
            'search' => $request->query('search') ?? $request->query('q'),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 5),
        ], $user);

        return response()->json([
            'data' => NotificationAlertItemResource::collection(collect($result['data']))->resolve(),
            'meta' => $result['meta'],
        ]);
    }

    public function counts(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 401);

        $request->validate([
            'fresh' => 'sometimes|boolean',
        ]);

        if ($request->boolean('fresh')) {
            $this->alertItemService->reactivateActiveAlertEntities($user->id, $user);
        }

        $counts = $this->alertItemService->getUnreadCountsForUser($user->id, $user);

        return response()->json([
            'data' => [
                'all' => $counts['total'],
                'total' => $counts['total'],
                'critical' => $counts['critical'],
                'warning' => $counts['warning'],
                'info' => $counts['info'],
            ],
        ]);
    }

    public function markAsRead(Request $request): JsonResponse
    {
        $allowedTypes = array_values(array_unique([
            ...config('notification-center.legacy_api_types', []),
            'stock_out',
            'low_stock',
            'product_expired',
            'product_expiring',
            'invoice_due',
        ]));

        $request->validate([
            'type' => ['required', 'string', Rule::in($allowedTypes)],
            'id' => 'required|integer',
        ]);

        $user = Auth::user();
        abort_unless($user, 401);

        $this->notificationService->markAsRead($user, $request->string('type')->toString(), (int) $request->integer('id'));

        return response()->json(['success' => true]);
    }

    public function markAsReadById(int $id): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 401);

        $this->authorize('update', Notification::findOrFail($id));

        $this->notificationService->markAsReadById($user, $id);

        return response()->json(['success' => true]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $legacyTypes = [...config('notification-center.legacy_api_types', []), 'all'];

        $request->validate([
            'type' => ['required', 'string', Rule::in($legacyTypes)],
        ]);

        $user = Auth::user();
        abort_unless($user, 401);

        $this->notificationService->markAllAsRead($user, $request->string('type')->toString());

        return response()->json(['success' => true]);
    }

    public function archive(int $id): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 401);

        $notification = Notification::query()->forUser($user->id)->findOrFail($id);
        $this->authorize('update', $notification);

        $this->notificationService->archive($notification);

        return response()->json(['success' => true]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 401);

        $notification = Notification::query()->forUser($user->id)->findOrFail($id);
        $this->authorize('delete', $notification);

        $notification->delete();

        return response()->json(['success' => true]);
    }

    public function destroyRead(): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 401);

        $count = $this->notificationService->deleteRead($user->id);

        return response()->json(['success' => true, 'deleted' => $count]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->merge([
            'search' => $request->query('search', $request->query('q')),
        ]);

        return $this->index($request);
    }

    public function test(): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user, 401);

        $this->notificationService->broadcast([
            'type' => 'test',
            'id' => 999,
            'message' => 'Ceci est une notification de test',
            'timestamp' => now()->toDateTimeString(),
        ], $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Notification de test envoyée',
        ]);
    }
}
