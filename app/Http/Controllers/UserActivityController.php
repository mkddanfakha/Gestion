<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserActivity\UserActivityDetailService;
use App\Services\UserActivity\UserActivityQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserActivityController extends Controller
{
    public function __construct(
        private readonly UserActivityQueryService $userActivityQueryService,
        private readonly UserActivityDetailService $userActivityDetailService,
    ) {}

    public function index(Request $request): Response
    {
        $this->checkPermission($request, 'user-activities', 'view');

        $payload = $this->userActivityQueryService->build($request->only([
            'user_id',
            'activity_type',
            'period',
            'date_from',
            'date_to',
            'search',
        ]));

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('UserActivities/Index', [
            'activities' => $payload['activities'],
            'stats' => $payload['stats'],
            'userSummary' => $payload['user_summary'],
            'users' => $users,
            'activityTypes' => $payload['activity_types'],
            'periodPresets' => $payload['period_presets'],
            'filters' => $payload['filters'],
        ]);
    }

    /**
     * Détail JSON pour le modal (RBAC métier appliqué — pas de contournement).
     */
    public function detail(Request $request, string $type, int $id): JsonResponse
    {
        $this->checkPermission($request, 'user-activities', 'view');

        $payload = $this->userActivityDetailService->detail($request, $type, $id);

        return response()->json($payload);
    }
}
