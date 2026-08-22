<?php

namespace App\Http\Controllers;

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Http\Requests\CountInventoryItemRequest;
use App\Http\Requests\ScanInventoryItemRequest;
use App\Http\Requests\StoreInventorySessionRequest;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventorySession;
use App\Services\InventoryApplicationService;
use App\Services\InventorySessionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workflow inventaire (Phases 4B–4E).
 */
class InventorySessionController extends Controller
{
    private const LIST_FILTER_KEYS = [
        'search',
        'status',
        'scope_type',
        'category_id',
        'date_from',
        'date_to',
    ];

    public function __construct(
        private readonly InventorySessionService $inventorySessionService,
        private readonly InventoryApplicationService $inventoryApplicationService,
    ) {}

    public function index(Request $request): Response
    {
        $this->checkPermission($request, 'inventory', 'view');

        $company = Company::getInstance();

        $user = $request->user();

        $query = InventorySession::query()
            ->with(['store', 'createdBy'])
            ->withCount([
                'items as items_total',
                'items as items_counted' => fn ($query) => $query->whereNotNull('quantity_counted'),
            ])
            ->where('company_id', $company->id);

        $this->applyIndexFilters($query, $request);

        $sessions = $query
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Inventory/Index', [
            'sessions' => $sessions,
            'listStats' => $this->inventorySessionService->buildListStats($company->id),
            'hasSessions' => InventorySession::query()->where('company_id', $company->id)->exists(),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(self::LIST_FILTER_KEYS),
            'permissions' => [
                'create' => $user->hasPermission('inventory', 'create'),
                'count' => $user->hasPermission('inventory', 'count'),
            ],
        ]);
    }

    public function show(Request $request, InventorySession $session): Response
    {
        $this->checkPermission($request, 'inventory', 'view');
        $this->assertSessionAccessible($session);

        return Inertia::render('Inventory/Index', [
            'countingSession' => $this->inventorySessionService->formatSessionDetailPayload(
                $session,
                $request->user(),
            ),
            'listFilters' => $request->only(self::LIST_FILTER_KEYS),
        ]);
    }

    public function store(StoreInventorySessionRequest $request): RedirectResponse
    {
        $this->checkPermission($request, 'inventory', 'create');

        $session = $this->inventorySessionService->create(
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('inventory.show', $session)
            ->with('success', "Session d'inventaire {$session->reference} créée.");
    }

    public function start(Request $request, InventorySession $session): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'inventory', 'create');
        $this->assertSessionAccessible($session);

        $session = $this->inventorySessionService->start($session, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Session démarrée.',
                'session' => $this->inventorySessionService->formatSessionDetailPayload(
                    $session,
                    $request->user(),
                ),
            ]);
        }

        return back()->with('success', 'Session d\'inventaire démarrée.');
    }

    public function countItem(
        CountInventoryItemRequest $request,
        InventorySession $session,
        InventoryItem $item,
    ): JsonResponse {
        $this->checkPermission($request, 'inventory', 'count');
        $this->assertSessionAccessible($session);
        $this->assertItemBelongsToSession($session, $item);

        $item = $this->inventorySessionService->countItem(
            $session,
            $item,
            (int) $request->validated('quantity_counted'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Produit compté.',
            'item' => $this->inventorySessionService->formatItemApiPayload($item),
        ]);
    }

    public function scan(
        ScanInventoryItemRequest $request,
        InventorySession $session,
    ): JsonResponse {
        $this->checkPermission($request, 'inventory', 'count');
        $this->assertSessionAccessible($session);

        $payload = $this->inventorySessionService->countItemByBarcode(
            $session,
            $request->validated('barcode'),
            $request->user(),
        );

        return response()->json($payload);
    }

    public function submit(Request $request, InventorySession $session): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'inventory', 'submit');
        $this->assertSessionAccessible($session);

        $session = $this->inventorySessionService->submit($session, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Comptage terminé. Session en revue.',
                'session' => $this->inventorySessionService->formatSessionDetailPayload(
                    $session,
                    $request->user(),
                ),
            ]);
        }

        return redirect()
            ->route('inventory.show', $session)
            ->with('success', 'Comptage terminé. Session en revue.');
    }

    public function reopen(Request $request, InventorySession $session): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'inventory', 'review');
        $this->assertSessionAccessible($session);

        $session = $this->inventorySessionService->reopen($session, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Comptage rouvert.',
                'session' => $this->inventorySessionService->formatSessionDetailPayload(
                    $session,
                    $request->user(),
                ),
            ]);
        }

        return redirect()
            ->route('inventory.show', $session)
            ->with('success', 'Comptage rouvert.');
    }

    public function validateSession(Request $request, InventorySession $session): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'inventory', 'validate');
        $this->assertSessionAccessible($session);

        $session = $this->inventorySessionService->validate($session, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Inventaire validé — en attente d\'application.',
                'session' => $this->inventorySessionService->formatSessionDetailPayload(
                    $session,
                    $request->user(),
                ),
            ]);
        }

        return redirect()
            ->route('inventory.show', $session)
            ->with('success', 'Inventaire validé — en attente d\'application.');
    }

    public function apply(Request $request, InventorySession $session): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'inventory', 'apply');
        $this->assertSessionAccessible($session);

        $result = $this->inventoryApplicationService->apply($session, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Inventaire appliqué avec succès.',
                'session' => $this->inventorySessionService->formatSessionDetailPayload(
                    $result['session'],
                    $request->user(),
                ),
                'application_summary' => $result['summary'],
            ]);
        }

        return redirect()
            ->route('inventory.show', $result['session'])
            ->with('success', 'Inventaire appliqué avec succès.');
    }

    public function cancel(Request $request, InventorySession $session): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'inventory', 'cancel');
        $this->assertSessionAccessible($session);

        $session = $this->inventorySessionService->cancel($session, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Session annulée.',
                'session' => $this->inventorySessionService->formatSessionDetailPayload(
                    $session,
                    $request->user(),
                ),
            ]);
        }

        return back()->with('success', 'Session d\'inventaire annulée.');
    }

    public function close(Request $request, InventorySession $session): JsonResponse|RedirectResponse
    {
        $this->checkPermission($request, 'inventory', 'close');
        $this->assertSessionAccessible($session);

        $session = $this->inventorySessionService->close($session, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Inventaire clôturé.',
                'session' => $this->inventorySessionService->formatSessionDetailPayload(
                    $session,
                    $request->user(),
                ),
            ]);
        }

        return redirect()
            ->route('inventory.show', $session)
            ->with('success', 'Inventaire clôturé.');
    }

    private function applyIndexFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));

            if ($term !== '') {
                $query->where(function (Builder $searchQuery) use ($term) {
                    $searchQuery
                        ->where('reference', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%")
                        ->orWhereHas('store', fn (Builder $storeQuery) => $storeQuery->where('name', 'like', "%{$term}%"));
                });
            }
        }

        if ($request->filled('status')) {
            $status = InventorySessionStatus::tryFrom((string) $request->input('status'));

            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('scope_type')) {
            $scopeType = InventoryScopeType::tryFrom((string) $request->input('scope_type'));

            if ($scopeType !== null) {
                $query->where('scope_type', $scopeType);
            }
        }

        if ($request->filled('category_id')) {
            $categoryId = (int) $request->input('category_id');

            if ($categoryId > 0 && Category::query()->whereKey($categoryId)->exists()) {
                $query
                    ->where('scope_type', InventoryScopeType::Category)
                    ->where('scope_value->category_id', $categoryId);
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
    }

    private function assertSessionAccessible(InventorySession $session): void
    {
        $company = Company::getInstance();

        if ($session->company_id !== $company->id) {
            abort(404);
        }
    }

    private function assertItemBelongsToSession(InventorySession $session, InventoryItem $item): void
    {
        if ($item->inventory_session_id !== $session->id) {
            abort(404);
        }
    }
}
