<?php

namespace App\Services;

use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventorySession;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventorySessionService
{
    private const MODULE = 'Inventaire';

    public function __construct(
        private readonly ProductBarcodeService $productBarcodeService,
        private readonly InventoryApplicationService $inventoryApplicationService,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     scope_type: InventoryScopeType|string,
     *     scope_value?: array|null,
     * }  $data
     */
    public function create(array $data, User $user): InventorySession
    {
        return DB::transaction(function () use ($data, $user): InventorySession {
            $company = Company::getInstance();
            $store = $company->defaultStore()->firstOrFail();

            $this->assertStoreBelongsToCompany($store, $company);
            $this->assertNoActiveSessionOnStore($store->id);

            $scopeType = $data['scope_type'] instanceof InventoryScopeType
                ? $data['scope_type']
                : InventoryScopeType::from($data['scope_type']);

            $scopeValue = $data['scope_value'] ?? null;
            $this->assertScopeValueValid($scopeType, $scopeValue);

            $session = InventorySession::query()->create([
                'company_id' => $company->id,
                'store_id' => $store->id,
                'reference' => InventorySession::generateReference($company->id),
                'status' => InventorySessionStatus::Draft,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'scope_type' => $scopeType,
                'scope_value' => $scopeValue,
                'created_by' => $user->id,
            ]);

            ActivityLogger::logCreate(self::MODULE, $session);

            return $session->fresh(['company', 'store', 'createdBy']);
        });
    }

    public function start(InventorySession $session, User $user): InventorySession
    {
        return DB::transaction(function () use ($session, $user): InventorySession {
            $lockedSession = InventorySession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionBelongsToCurrentCompany($lockedSession);
            $this->assertStatus($lockedSession, InventorySessionStatus::Draft, 'démarrer');

            $store = Store::query()->whereKey($lockedSession->store_id)->lockForUpdate()->firstOrFail();
            $this->assertStoreActive($store);
            $this->assertNoActiveSessionOnStore($store->id, $lockedSession->id);

            $productIds = $this->resolveProductIds($lockedSession);

            if ($productIds === []) {
                throw ValidationException::withMessages([
                    'message' => ['Aucun produit éligible dans le périmètre sélectionné.'],
                ]);
            }

            $stocks = ProductStock::query()
                ->where('store_id', $lockedSession->store_id)
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy('product_id');

            $missingProductIds = array_values(array_diff($productIds, $stocks->keys()->all()));

            if ($missingProductIds !== []) {
                throw ValidationException::withMessages([
                    'message' => [
                        'ProductStock manquant pour '.count($missingProductIds).' produit(s) du magasin principal.',
                    ],
                    'missing_product_ids' => [json_encode($missingProductIds)],
                ]);
            }

            $now = now();
            $rows = [];

            foreach ($productIds as $productId) {
                $rows[] = [
                    'inventory_session_id' => $lockedSession->id,
                    'product_id' => $productId,
                    'stock_snapshot' => (int) $stocks[$productId]->quantity,
                    'quantity_counted' => null,
                    'counted_at' => null,
                    'counted_by' => null,
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                InventoryItem::query()->insert($chunk);
            }

            $previousStatus = $lockedSession->status;
            $lockedSession->update([
                'status' => InventorySessionStatus::Counting,
                'started_at' => $now,
                'started_by' => $user->id,
            ]);

            ActivityLogger::log(
                ActivityLog::ACTION_UPDATE,
                self::MODULE,
                sprintf('a démarré la session d\'inventaire "%s"', $lockedSession->reference),
                $lockedSession->fresh(),
                $user,
                oldValues: ['status' => $previousStatus->value],
                newValues: [
                    'status' => InventorySessionStatus::Counting->value,
                    'items_count' => count($productIds),
                ],
            );

            return $lockedSession->fresh(['items', 'store', 'startedBy']);
        });
    }

    public function countItem(
        InventorySession $session,
        InventoryItem $item,
        int $quantity,
        User $user,
    ): InventoryItem {
        if ($quantity < 0) {
            throw ValidationException::withMessages([
                'quantity_counted' => ['La quantité comptée doit être un entier supérieur ou égal à 0.'],
            ]);
        }

        return DB::transaction(function () use ($session, $item, $quantity, $user): InventoryItem {
            $lockedSession = InventorySession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionBelongsToCurrentCompany($lockedSession);
            $this->assertStatus($lockedSession, InventorySessionStatus::Counting, 'compter');

            $lockedItem = InventoryItem::query()
                ->whereKey($item->id)
                ->where('inventory_session_id', $lockedSession->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedItem) {
                throw ValidationException::withMessages([
                    'message' => ['Ce produit n\'appartient pas au périmètre de cette session.'],
                ]);
            }

            $previousQuantity = $lockedItem->quantity_counted;

            $lockedItem->update([
                'quantity_counted' => $quantity,
                'counted_at' => now(),
                'counted_by' => $user->id,
            ]);

            ActivityLogger::log(
                ActivityLog::ACTION_UPDATE,
                self::MODULE,
                sprintf(
                    'a compté le produit #%d pour la session "%s"',
                    $lockedItem->product_id,
                    $lockedSession->reference,
                ),
                $lockedSession,
                $user,
                oldValues: [
                    'inventory_item_id' => $lockedItem->id,
                    'product_id' => $lockedItem->product_id,
                    'quantity_counted' => $previousQuantity,
                ],
                newValues: [
                    'inventory_item_id' => $lockedItem->id,
                    'product_id' => $lockedItem->product_id,
                    'quantity_counted' => $quantity,
                ],
            );

            return $lockedItem->fresh(['product', 'countedBy']);
        });
    }

    /**
     * Incrémente le comptage d'un produit via code-barres (+1 par scan).
     *
     * NULL → increment ; valeur existante → +increment.
     * Aucun ActivityLog par scan (volumétrie).
     *
     * @return array<string, mixed>
     */
    public function countItemByBarcode(
        InventorySession $session,
        string $barcode,
        User $user,
        int $increment = 1,
    ): array {
        if ($increment < 1) {
            throw ValidationException::withMessages([
                'increment' => ['L\'incrément doit être strictement positif.'],
            ]);
        }

        $normalized = $this->productBarcodeService->normalize($barcode);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'barcode' => ['Le code-barres est obligatoire.'],
            ]);
        }

        $product = $this->productBarcodeService->findByBarcode($normalized);

        if (! $product) {
            throw ValidationException::withMessages([
                'barcode' => ['Code-barres inconnu.'],
            ]);
        }

        return DB::transaction(function () use ($session, $product, $user, $increment): array {
            $lockedSession = InventorySession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionBelongsToCurrentCompany($lockedSession);
            $this->assertStatus($lockedSession, InventorySessionStatus::Counting, 'scanner');

            $lockedItem = InventoryItem::query()
                ->where('inventory_session_id', $lockedSession->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedItem) {
                throw ValidationException::withMessages([
                    'message' => ['Ce produit ne fait pas partie de cet inventaire.'],
                ]);
            }

            $newQuantity = $lockedItem->quantity_counted === null
                ? $increment
                : $lockedItem->quantity_counted + $increment;

            $lockedItem->update([
                'quantity_counted' => $newQuantity,
                'counted_at' => now(),
                'counted_by' => $user->id,
            ]);

            $lockedItem->refresh();

            return $this->formatScanResponse($lockedItem, $product);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function formatCountingSessionPayload(InventorySession $session, ?User $user = null): array
    {
        return $this->formatSessionDetailPayload($session, $user);
    }

    /**
     * @return array<string, int|float>
     */
    public function getProgress(InventorySession $session): array
    {
        $total = $session->items()->count();
        $counted = $session->items()->whereNotNull('quantity_counted')->count();
        $uncounted = max(0, $total - $counted);

        return [
            'total' => $total,
            'counted' => $counted,
            'uncounted' => $uncounted,
            'percentage' => $total > 0 ? round(($counted / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getSummary(InventorySession $session): array
    {
        $items = $session->items()->get(['stock_snapshot', 'quantity_counted']);

        $totalUnits = 0;
        $positiveVariances = 0;
        $negativeVariances = 0;
        $zeroVariances = 0;
        $totalVariance = 0;

        foreach ($items as $item) {
            if ($item->quantity_counted === null) {
                continue;
            }

            $totalUnits += $item->quantity_counted;
            $difference = $item->quantity_counted - $item->stock_snapshot;
            $totalVariance += $difference;

            if ($difference > 0) {
                $positiveVariances++;
            } elseif ($difference < 0) {
                $negativeVariances++;
            } else {
                $zeroVariances++;
            }
        }

        return [
            'total_units' => $totalUnits,
            'positive_variances' => $positiveVariances,
            'negative_variances' => $negativeVariances,
            'zero_variances' => $zeroVariances,
            'total_variance' => $totalVariance,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUncountedItems(InventorySession $session, int $limit = 20): array
    {
        return InventoryItem::query()
            ->where('inventory_session_id', $session->id)
            ->whereNull('quantity_counted')
            ->with('product:id,name,barcode,sku')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'barcode' => $item->product->barcode,
            ])
            ->values()
            ->all();
    }

    public function canSubmit(InventorySession $session): bool
    {
        return $session->status === InventorySessionStatus::Counting
            && $this->getProgress($session)['uncounted'] === 0;
    }

    public function canValidate(InventorySession $session): bool
    {
        return $session->status === InventorySessionStatus::Review
            && $session->items()->whereNull('quantity_counted')->doesntExist();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatSessionDetailPayload(InventorySession $session, ?User $user = null): array
    {
        $session->load([
            'store:id,name',
            'items.product:id,name,barcode,sku',
            'createdBy:id,name',
            'validatedBy:id,name',
            'appliedBy:id,name',
            'closedBy:id,name',
        ]);

        $items = $session->items
            ->map(fn (InventoryItem $item) => $this->formatItemPayload($item))
            ->values();

        $progress = $this->getProgress($session);
        $summary = $this->getSummary($session);
        $applicationPreview = $session->status === InventorySessionStatus::Validated
            ? $this->inventoryApplicationService->previewApplication($session)
            : null;

        return [
            'id' => $session->id,
            'reference' => $session->reference,
            'name' => $session->name,
            'description' => $session->description,
            'status' => $session->status->value,
            'scope_type' => $session->scope_type->value,
            'scope_value' => $session->scope_value,
            'store' => [
                'id' => $session->store->id,
                'name' => $session->store->name,
            ],
            'items' => $items,
            'progress' => $progress,
            'summary' => $summary,
            'application_preview' => $applicationPreview,
            'application_summary' => $session->application_summary,
            'can_submit' => $this->canSubmit($session),
            'can_validate' => $this->canValidate($session),
            'can_apply' => $this->inventoryApplicationService->canApply($session),
            'can_close' => $session->status === InventorySessionStatus::Applied,
            'history' => [
                'created_by' => $session->createdBy?->name,
                'validated_by' => $session->validatedBy?->name,
                'validated_at' => $session->validated_at?->toIso8601String(),
                'applied_by' => $session->appliedBy?->name,
                'applied_at' => $session->applied_at?->toIso8601String(),
                'closed_by' => $session->closedBy?->name,
                'closed_at' => $session->closed_at?->toIso8601String(),
            ],
            'permissions' => $this->resolveSessionPermissions($user),
        ];
    }

    public function submit(InventorySession $session, User $user): InventorySession
    {
        return DB::transaction(function () use ($session, $user): InventorySession {
            $lockedSession = InventorySession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionBelongsToCurrentCompany($lockedSession);

            if ($lockedSession->status === InventorySessionStatus::Review) {
                throw ValidationException::withMessages([
                    'message' => ['Cette session a déjà été soumise en revue.'],
                ]);
            }

            $this->assertStatus($lockedSession, InventorySessionStatus::Counting, 'soumettre');

            $progress = $this->getProgress($lockedSession);

            if ($progress['uncounted'] > 0) {
                $uncountedItems = $this->getUncountedItems($lockedSession, 10);

                throw ValidationException::withMessages([
                    'message' => [
                        "Le comptage n'est pas terminé. {$progress['uncounted']} produit(s) n'ont pas encore été comptés.",
                    ],
                    'total_items' => [(string) $progress['total']],
                    'counted_items' => [(string) $progress['counted']],
                    'uncounted_items' => [(string) $progress['uncounted']],
                    'uncounted_products' => [json_encode($uncountedItems)],
                ]);
            }

            $summary = $this->getSummary($lockedSession);
            $previousStatus = $lockedSession->status;
            $lockedSession->update([
                'status' => InventorySessionStatus::Review,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
            ]);

            ActivityLogger::log(
                ActivityLog::ACTION_UPDATE,
                self::MODULE,
                sprintf('a terminé le comptage de l\'inventaire "%s"', $lockedSession->reference),
                $lockedSession->fresh(),
                $user,
                oldValues: ['status' => $previousStatus->value],
                newValues: array_merge(
                    ['status' => InventorySessionStatus::Review->value],
                    $progress,
                    $summary,
                ),
            );

            return $lockedSession->fresh(['items', 'submittedBy']);
        });
    }

    public function reopen(InventorySession $session, User $user): InventorySession
    {
        return DB::transaction(function () use ($session, $user): InventorySession {
            $lockedSession = InventorySession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionBelongsToCurrentCompany($lockedSession);
            $this->assertStatus($lockedSession, InventorySessionStatus::Review, 'rouvrir');

            $previousStatus = $lockedSession->status;
            $lockedSession->update([
                'status' => InventorySessionStatus::Counting,
            ]);

            ActivityLogger::log(
                ActivityLog::ACTION_UPDATE,
                self::MODULE,
                sprintf('a rouvert le comptage de l\'inventaire "%s"', $lockedSession->reference),
                $lockedSession->fresh(),
                $user,
                oldValues: ['status' => $previousStatus->value],
                newValues: ['status' => InventorySessionStatus::Counting->value],
            );

            return $lockedSession->fresh(['items']);
        });
    }

    public function validate(InventorySession $session, User $user): InventorySession
    {
        return DB::transaction(function () use ($session, $user): InventorySession {
            $lockedSession = InventorySession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionBelongsToCurrentCompany($lockedSession);

            if ($lockedSession->status === InventorySessionStatus::Validated) {
                throw ValidationException::withMessages([
                    'message' => ['Cette session a déjà été validée.'],
                ]);
            }

            $this->assertStatus($lockedSession, InventorySessionStatus::Review, 'valider');

            $uncountedCount = InventoryItem::query()
                ->where('inventory_session_id', $lockedSession->id)
                ->whereNull('quantity_counted')
                ->count();

            if ($uncountedCount > 0) {
                throw ValidationException::withMessages([
                    'message' => ['Impossible de valider : des produits ne sont pas comptés.'],
                ]);
            }

            $negativeCount = InventoryItem::query()
                ->where('inventory_session_id', $lockedSession->id)
                ->where('quantity_counted', '<', 0)
                ->count();

            if ($negativeCount > 0) {
                throw ValidationException::withMessages([
                    'message' => ['Impossible de valider : des quantités comptées sont négatives.'],
                ]);
            }

            $summary = $this->getSummary($lockedSession);
            $progress = $this->getProgress($lockedSession);
            $previousStatus = $lockedSession->status;
            $lockedSession->update([
                'status' => InventorySessionStatus::Validated,
                'validated_at' => now(),
                'validated_by' => $user->id,
            ]);

            ActivityLogger::log(
                ActivityLog::ACTION_VALIDATE,
                self::MODULE,
                sprintf('a validé l\'inventaire "%s"', $lockedSession->reference),
                $lockedSession->fresh(),
                $user,
                oldValues: ['status' => $previousStatus->value],
                newValues: array_merge(
                    ['status' => InventorySessionStatus::Validated->value],
                    $progress,
                    $summary,
                ),
            );

            return $lockedSession->fresh(['items', 'validatedBy']);
        });
    }

    public function cancel(InventorySession $session, User $user): InventorySession
    {
        return DB::transaction(function () use ($session, $user): InventorySession {
            $lockedSession = InventorySession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionBelongsToCurrentCompany($lockedSession);

            if (! in_array($lockedSession->status, [
                InventorySessionStatus::Draft,
                InventorySessionStatus::Counting,
                InventorySessionStatus::Review,
                InventorySessionStatus::Validated,
            ], true)) {
                throw ValidationException::withMessages([
                    'message' => ['Cette session d\'inventaire ne peut plus être annulée.'],
                ]);
            }

            $previousStatus = $lockedSession->status;
            $lockedSession->update([
                'status' => InventorySessionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
            ]);

            ActivityLogger::log(
                ActivityLog::ACTION_CANCEL,
                self::MODULE,
                sprintf('a annulé la session d\'inventaire "%s"', $lockedSession->reference),
                $lockedSession->fresh(),
                $user,
                oldValues: ['status' => $previousStatus->value],
                newValues: ['status' => InventorySessionStatus::Cancelled->value],
            );

            return $lockedSession->fresh(['cancelledBy']);
        });
    }

    public function close(InventorySession $session, User $user): InventorySession
    {
        return DB::transaction(function () use ($session, $user): InventorySession {
            $lockedSession = InventorySession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionBelongsToCurrentCompany($lockedSession);

            if ($lockedSession->status === InventorySessionStatus::Closed) {
                throw ValidationException::withMessages([
                    'message' => ['Cette session d\'inventaire est déjà clôturée.'],
                ]);
            }

            if ($lockedSession->status !== InventorySessionStatus::Applied) {
                throw ValidationException::withMessages([
                    'message' => [
                        'La fermeture n\'est possible qu\'après application des écarts.',
                    ],
                ]);
            }

            $previousStatus = $lockedSession->status;
            $lockedSession->update([
                'status' => InventorySessionStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $user->id,
            ]);

            ActivityLogger::log(
                ActivityLog::ACTION_UPDATE,
                self::MODULE,
                sprintf('a clôturé l\'inventaire "%s"', $lockedSession->reference),
                $lockedSession->fresh(),
                $user,
                oldValues: ['status' => $previousStatus->value],
                newValues: ['status' => InventorySessionStatus::Closed->value],
            );

            return $lockedSession->fresh(['closedBy']);
        });
    }

    /**
     * @return list<int>
     */
    public function resolveProductIds(InventorySession $session): array
    {
        $query = Product::query()->where('is_active', true);

        if ($session->scope_type === InventoryScopeType::Category) {
            $categoryId = $session->scope_value['category_id'] ?? null;

            if (! $categoryId || ! Category::query()->whereKey($categoryId)->exists()) {
                throw ValidationException::withMessages([
                    'scope_value' => ['La catégorie sélectionnée est invalide.'],
                ]);
            }

            $query->where('category_id', $categoryId);
        }

        if ($session->scope_type === InventoryScopeType::StockPositive) {
            $query->whereHas('productStocks', function ($stockQuery) use ($session): void {
                $stockQuery
                    ->where('store_id', $session->store_id)
                    ->where('quantity', '>', 0);
            });
        }

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return Collection<int, InventoryItem>
     */
    public function itemsWithSnapshotDifference(InventorySession $session): Collection
    {
        return $session->items()
            ->get()
            ->map(function (InventoryItem $item): InventoryItem {
                $item->setAttribute('difference_snapshot', $item->differenceFromSnapshot());

                return $item;
            });
    }

    private function assertSessionBelongsToCurrentCompany(InventorySession $session): void
    {
        $company = Company::getInstance();

        if ($session->company_id !== $company->id) {
            throw ValidationException::withMessages([
                'message' => ['Cette session d\'inventaire n\'appartient pas à l\'entreprise courante.'],
            ]);
        }

        $store = $session->relationLoaded('store')
            ? $session->store
            : Store::query()->find($session->store_id);

        if (! $store || $store->company_id !== $company->id) {
            throw ValidationException::withMessages([
                'message' => ['Le magasin de cette session n\'appartient pas à l\'entreprise courante.'],
            ]);
        }
    }

    private function assertStoreBelongsToCompany(Store $store, Company $company): void
    {
        if ($store->company_id !== $company->id) {
            throw ValidationException::withMessages([
                'message' => ['Le magasin sélectionné n\'appartient pas à l\'entreprise courante.'],
            ]);
        }
    }

    private function assertStoreActive(Store $store): void
    {
        if (! $store->is_active) {
            throw ValidationException::withMessages([
                'message' => ['Le magasin principal n\'est pas actif.'],
            ]);
        }
    }

    private function assertNoActiveSessionOnStore(int $storeId, ?int $excludeSessionId = null): void
    {
        $exists = InventorySession::query()
            ->where('store_id', $storeId)
            ->active()
            ->when($excludeSessionId, fn ($query) => $query->whereKeyNot($excludeSessionId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'message' => ['Une session d\'inventaire active existe déjà pour ce magasin.'],
            ]);
        }
    }

    private function assertStatus(
        InventorySession $session,
        InventorySessionStatus $expected,
        string $actionLabel,
    ): void {
        if ($session->status !== $expected) {
            throw ValidationException::withMessages([
                'message' => [
                    "Impossible de {$actionLabel} : la session n'est pas au statut « {$expected->value} ».",
                ],
            ]);
        }
    }

    private function assertScopeValueValid(InventoryScopeType $scopeType, ?array $scopeValue): void
    {
        if ($scopeType === InventoryScopeType::Category) {
            $categoryId = $scopeValue['category_id'] ?? null;

            if (! $categoryId || ! Category::query()->whereKey($categoryId)->exists()) {
                throw ValidationException::withMessages([
                    'scope_value' => ['Une catégorie valide est requise pour ce périmètre.'],
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function formatItemApiPayload(InventoryItem $item): array
    {
        $item->loadMissing('product:id,name,barcode,sku');

        return $this->formatItemPayload($item);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function buildListStats(int $companyId): array
    {
        $baseQuery = InventorySession::query()->where('company_id', $companyId);

        $lastSession = (clone $baseQuery)->latest('created_at')->first(['reference', 'created_at']);

        return [
            'active_count' => (clone $baseQuery)->active()->count(),
            'counting_count' => (clone $baseQuery)->where('status', InventorySessionStatus::Counting)->count(),
            'to_validate_count' => (clone $baseQuery)->whereIn('status', [
                InventorySessionStatus::Review,
                InventorySessionStatus::Validated,
            ])->count(),
            'last_reference' => $lastSession?->reference,
            'last_date' => $lastSession?->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatItemPayload(InventoryItem $item): array
    {
        $product = $item->product;

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'stock_snapshot' => $item->stock_snapshot,
            'quantity_counted' => $item->quantity_counted,
            'difference' => $item->differenceFromSnapshot(),
            'difference_from_snapshot' => $item->differenceFromSnapshot(),
            'is_counted' => $item->isCounted(),
            'variance_status' => $this->resolveVarianceStatus($item),
            'counted_at' => $item->counted_at?->toIso8601String(),
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'sku' => $product->sku,
                'image_url' => $product->getThumbImageUrl(),
            ],
        ];
    }

    private function resolveVarianceStatus(InventoryItem $item): string
    {
        if ($item->quantity_counted === null) {
            return 'uncounted';
        }

        $difference = $item->differenceFromSnapshot();

        if ($difference === 0) {
            return 'conforme';
        }

        return $difference > 0 ? 'surplus' : 'manque';
    }

    /**
     * @return array<string, bool>
     */
    private function resolveSessionPermissions(?User $user): array
    {
        if (! $user) {
            return [
                'count' => false,
                'submit' => false,
                'review' => false,
                'validate' => false,
                'apply' => false,
                'close' => false,
                'cancel' => false,
                'create' => false,
            ];
        }

        return [
            'count' => $user->hasPermission('inventory', 'count'),
            'submit' => $user->hasPermission('inventory', 'submit'),
            'review' => $user->hasPermission('inventory', 'review'),
            'validate' => $user->hasPermission('inventory', 'validate'),
            'apply' => $user->hasPermission('inventory', 'apply'),
            'close' => $user->hasPermission('inventory', 'close'),
            'cancel' => $user->hasPermission('inventory', 'cancel'),
            'create' => $user->hasPermission('inventory', 'create'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatScanResponse(InventoryItem $item, Product $product): array
    {
        return [
            'success' => true,
            'product' => $this->productBarcodeService->formatProductPayload($product),
            'item' => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'quantity_counted' => $item->quantity_counted,
                'stock_snapshot' => $item->stock_snapshot,
                'difference' => $item->differenceFromSnapshot(),
                'difference_from_snapshot' => $item->differenceFromSnapshot(),
                'is_counted' => $item->isCounted(),
                'variance_status' => $this->resolveVarianceStatus($item),
            ],
        ];
    }
}
