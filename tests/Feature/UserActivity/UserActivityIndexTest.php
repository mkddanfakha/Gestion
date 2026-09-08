<?php

use App\Auth\RolePresets;
use App\Enums\InventoryScopeType;
use App\Enums\InventorySessionStatus;
use App\Enums\StockMovementType;
use App\Models\Company;
use App\Models\Expense;
use App\Models\InventorySession;
use App\Models\Permission;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\UserActivity\UserActivityQueryService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->withoutVite();
});

function seedUserActivitiesPermission(): Permission
{
    (new PermissionSeeder())->run();

    return Permission::where('name', 'user-activities.view')->firstOrFail();
}

function userWithUserActivitiesView(string $role = 'gestionnaire'): User
{
    $permission = seedUserActivitiesPermission();
    $user = User::factory()->create(['role' => $role]);
    $user->permissions()->attach($permission);

    return $user;
}

function createSaleActivity(User $user, array $overrides = []): Sale
{
    return Sale::create(array_merge([
        'sale_number' => Sale::generateSaleNumber(),
        'user_id' => $user->id,
        'subtotal' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'down_payment_amount' => 1000,
        'remaining_amount' => 0,
        'payment_status' => 'paid',
        'total_amount' => 1000,
        'status' => 'completed',
        'payment_method' => 'cash',
        'sale_date' => now(),
    ], $overrides));
}

function createExpenseActivity(User $user, array $overrides = []): Expense
{
    return Expense::create(array_merge([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Achat fournitures',
        'amount' => 5000,
        'category' => 'fournitures',
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
        'user_id' => $user->id,
    ], $overrides));
}

function createQuoteActivity(User $user, array $overrides = []): Quote
{
    return Quote::create(array_merge([
        'quote_number' => Quote::generateQuoteNumber(),
        'user_id' => $user->id,
        'subtotal' => 2000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 2000,
        'status' => 'draft',
        'quote_date' => now(),
    ], $overrides));
}

test('admin peut acceder a l activite des utilisateurs', function () {
    seedUserActivitiesPermission();
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('user-activities.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('UserActivities/Index')
            ->has('activities')
            ->has('stats')
            ->has('userSummary')
        );
});

test('gestionnaire avec permission peut acceder', function () {
    $gestionnaire = userWithUserActivitiesView();

    $this->actingAs($gestionnaire)
        ->get(route('user-activities.index'))
        ->assertOk();
});

test('utilisateur sans permission recoit 403', function () {
    seedUserActivitiesPermission();
    $vendeur = User::factory()->create(['role' => 'vendeur']);
    $vendeur->permissions()->sync(RolePresets::permissionIds(User::ROLE_VENDEUR));

    $this->actingAs($vendeur)
        ->get(route('user-activities.index'))
        ->assertForbidden();
});

test('vendeur ne peut pas contourner via parametres url', function () {
    seedUserActivitiesPermission();
    $vendeur = User::factory()->create(['role' => 'vendeur']);
    $other = User::factory()->create(['role' => 'vendeur']);
    createSaleActivity($other);

    $this->actingAs($vendeur)
        ->get(route('user-activities.index', [
            'user_id' => $other->id,
            'period' => 'this_month',
        ]))
        ->assertForbidden();
});

test('filtre par utilisateur', function () {
    $viewer = userWithUserActivitiesView();
    $alice = User::factory()->create(['name' => 'Alice Vente']);
    $bob = User::factory()->create(['name' => 'Bob Vente']);

    createSaleActivity($alice, ['total_amount' => 1500]);
    createSaleActivity($bob, ['total_amount' => 3000]);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'this_month',
            'user_id' => $alice->id,
            'activity_type' => 'sale',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('UserActivities/Index')
            ->has('activities.data', 1)
            ->where('activities.data.0.user_id', $alice->id)
            ->where('stats.sales_count', 1)
            ->where('stats.sales_amount', 1500)
        );
});

test('filtre par type d activite', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();

    createSaleActivity($actor);
    createExpenseActivity($actor);
    createQuoteActivity($actor);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'this_month',
            'activity_type' => 'expense',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.activity_type', 'expense')
            ->where('stats.expenses_count', 1)
            ->where('stats.sales_count', 0)
        );
});

test('filtre par date personnalisee', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();

    createSaleActivity($actor, [
        'sale_date' => now()->subDays(10),
        'total_amount' => 100,
    ]);
    createSaleActivity($actor, [
        'sale_date' => now()->subDays(2),
        'total_amount' => 250,
    ]);

    $from = now()->subDays(3)->toDateString();
    $to = now()->toDateString();

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'custom',
            'date_from' => $from,
            'date_to' => $to,
            'activity_type' => 'sale',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('stats.sales_count', 1)
            ->where('stats.sales_amount', 250)
        );
});

test('combinaison des filtres utilisateur type et date', function () {
    $viewer = userWithUserActivitiesView();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    createSaleActivity($alice, ['sale_date' => now(), 'total_amount' => 400]);
    createExpenseActivity($alice, ['expense_date' => now()->toDateString(), 'amount' => 50]);
    createSaleActivity($bob, ['sale_date' => now(), 'total_amount' => 900]);
    createSaleActivity($alice, [
        'sale_date' => now()->subMonthNoOverflow()->startOfMonth()->addDay(),
        'total_amount' => 777,
    ]);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'this_month',
            'user_id' => $alice->id,
            'activity_type' => 'sale',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.user_id', $alice->id)
            ->where('activities.data.0.activity_type', 'sale')
            ->where('stats.sales_count', 1)
            ->where('stats.sales_amount', 400)
            ->where('stats.expenses_count', 0)
        );
});

test('statistiques calculees depuis tables metier', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();

    createSaleActivity($actor, ['total_amount' => 1200]);
    createSaleActivity($actor, ['total_amount' => 800]);
    createExpenseActivity($actor, ['amount' => 300]);
    createQuoteActivity($actor, ['total_amount' => 500]);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', ['period' => 'this_month']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.total_activities', 4)
            ->where('stats.sales_count', 2)
            ->where('stats.sales_amount', 2000)
            ->where('stats.expenses_count', 1)
            ->where('stats.expenses_amount', 300)
            ->where('stats.quotes_count', 1)
            ->has('userSummary', 1)
            ->where('userSummary.0.user_id', $actor->id)
            ->where('userSummary.0.sales_count', 2)
            ->where('userSummary.0.sales_amount', 2000)
        );
});

test('pagination serveur limite a 20', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();

    for ($i = 0; $i < 25; $i++) {
        createSaleActivity($actor, ['total_amount' => 10 + $i]);
    }

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'this_month',
            'activity_type' => 'sale',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 20)
            ->where('activities.per_page', 20)
            ->where('activities.total', 25)
            ->where('activities.last_page', 2)
        );
});

test('operation sans user_id affiche Non attribue', function () {
    $viewer = userWithUserActivitiesView();
    $company = Company::getInstance();
    $store = $company->defaultStore()->firstOrFail();

    InventorySession::create([
        'company_id' => $company->id,
        'store_id' => $store->id,
        'reference' => 'INV-NA-001',
        'name' => 'Inventaire historique',
        'status' => InventorySessionStatus::Draft->value,
        'scope_type' => InventoryScopeType::Complete->value,
        'created_by' => null,
    ]);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'this_month',
            'activity_type' => 'inventory',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.user_id', null)
            ->where('activities.data.0.user_name', 'Non attribué')
        );
});

test('association correcte utilisateur vers operation', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create(['name' => 'Operateur Test']);

    $sale = createSaleActivity($actor, ['total_amount' => 555]);
    $expense = createExpenseActivity($actor, ['amount' => 111]);
    $quote = createQuoteActivity($actor, ['total_amount' => 222]);

    $payload = app(UserActivityQueryService::class)->build([
        'period' => 'this_month',
        'user_id' => $actor->id,
    ]);

    $ids = collect($payload['activities']->items())->pluck('id')->all();

    expect($ids)->toContain('sale:'.$sale->id)
        ->and($ids)->toContain('expense:'.$expense->id)
        ->and($ids)->toContain('quote:'.$quote->id);

    expect($payload['stats']['sales_amount'])->toBe(555.0)
        ->and($payload['stats']['expenses_amount'])->toBe(111.0)
        ->and($payload['stats']['quotes_count'])->toBe(1);
});

test('role user recoit 403', function () {
    seedUserActivitiesPermission();
    $user = User::factory()->create(['role' => 'user']);

    $this->actingAs($user)
        ->get(route('user-activities.index'))
        ->assertForbidden();
});

test('utilisateur authentifie sans permission recoit 403', function () {
    seedUserActivitiesPermission();
    $user = User::factory()->create(['role' => 'gestionnaire']);
    // aucune permission attachée

    $this->actingAs($user)
        ->get(route('user-activities.index'))
        ->assertForbidden();
});

test('pagination page 2 retourne les elements restants', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();

    for ($i = 0; $i < 25; $i++) {
        createSaleActivity($actor, ['total_amount' => 10 + $i]);
    }

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'this_month',
            'activity_type' => 'sale',
            'page' => 2,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 5)
            ->where('activities.current_page', 2)
            ->where('activities.total', 25)
        );
});

test('periodes today yesterday this_week last_month filtrent correctement', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();

    $todaySale = createSaleActivity($actor, [
        'sale_date' => now()->startOfDay()->addHours(10),
        'total_amount' => 10,
    ]);
    $yesterdaySale = createSaleActivity($actor, [
        'sale_date' => now()->subDay()->startOfDay()->addHours(12),
        'total_amount' => 20,
    ]);
    createSaleActivity($actor, [
        'sale_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2),
        'total_amount' => 30,
    ]);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', ['period' => 'today', 'activity_type' => 'sale']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.subject_id', $todaySale->id)
        );

    $this->actingAs($viewer)
        ->get(route('user-activities.index', ['period' => 'yesterday', 'activity_type' => 'sale']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.subject_id', $yesterdaySale->id)
        );

    $this->actingAs($viewer)
        ->get(route('user-activities.index', ['period' => 'this_week', 'activity_type' => 'sale']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.sales_count', fn ($count) => $count >= 1)
        );

    $this->actingAs($viewer)
        ->get(route('user-activities.index', ['period' => 'last_month', 'activity_type' => 'sale']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('stats.sales_amount', 30)
        );
});

test('bornes custom incluent debut et fin de journee', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();
    $day = now()->subDays(5)->toDateString();

    createSaleActivity($actor, [
        'sale_date' => Carbon::parse($day)->startOfDay(),
        'total_amount' => 11,
    ]);
    createSaleActivity($actor, [
        'sale_date' => Carbon::parse($day)->endOfDay()->startOfSecond(),
        'total_amount' => 22,
    ]);
    createSaleActivity($actor, [
        'sale_date' => Carbon::parse($day)->subDay()->endOfDay(),
        'total_amount' => 99,
    ]);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'custom',
            'date_from' => $day,
            'date_to' => $day,
            'activity_type' => 'sale',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 2)
            ->where('stats.sales_amount', 33)
        );
});

test('custom sans dates retombe sur this_month', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();

    createSaleActivity($actor, [
        'sale_date' => now(),
        'total_amount' => 50,
    ]);
    createSaleActivity($actor, [
        'sale_date' => now()->subMonths(2),
        'total_amount' => 999,
    ]);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'custom',
            'activity_type' => 'sale',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.period', 'this_month')
            ->has('activities.data', 1)
            ->where('stats.sales_amount', 50)
        );
});

test('activity_type inconnu ne provoque pas derreur', function () {
    $viewer = userWithUserActivitiesView();
    createSaleActivity(User::factory()->create());

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'this_month',
            'activity_type' => 'unknown_type_xyz',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.activity_type', null)
            ->where('stats.sales_count', 1)
        );
});

test('stock_movement nest pas inclus et vente nest pas dupliquee', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();
    $sale = createSaleActivity($actor, ['total_amount' => 777]);

    $company = Company::getInstance();
    $store = $company->defaultStore()->firstOrFail();
    $product = createTestProduct();

    StockMovement::create([
        'company_id' => $company->id,
        'store_id' => $store->id,
        'product_id' => $product->id,
        'type' => StockMovementType::Sale,
        'quantity' => 1,
        'quantity_before' => 10,
        'quantity_after' => 9,
        'reference_type' => $sale->getMorphClass(),
        'reference_id' => $sale->id,
        'user_id' => $actor->id,
        'reason' => 'Vente test',
    ]);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', [
            'period' => 'this_month',
            'user_id' => $actor->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.id', 'sale:'.$sale->id)
            ->where('stats.total_activities', 1)
            ->where('stats.sales_count', 1)
            ->missing('stats.stock_movements_count')
        );

    expect(array_key_exists('stock_movement', UserActivityQueryService::activityTypes()))->toBeFalse();
});

test('utilisateur sans activite nest pas dans le resume', function () {
    $viewer = userWithUserActivitiesView();
    $active = User::factory()->create(['name' => 'Actif']);
    $idle = User::factory()->create(['name' => 'Inactif']);

    createSaleActivity($active, ['total_amount' => 100]);

    $this->actingAs($viewer)
        ->get(route('user-activities.index', ['period' => 'this_month']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('userSummary', 1)
            ->where('userSummary.0.user_id', $active->id)
            ->where('userSummary.0.user_name', 'Actif')
        );

    expect($idle->id)->not->toBe($active->id);
});

test('gestionnaire voit user-activities mais sales.show reste protege', function () {
    (new PermissionSeeder())->run();
    $gestionnaire = User::factory()->create(['role' => User::ROLE_GESTIONNAIRE]);
    $gestionnaire->permissions()->sync(RolePresets::permissionIds(User::ROLE_GESTIONNAIRE));

    $vendeur = User::factory()->create(['role' => 'vendeur']);
    $sale = createSaleActivity($vendeur, ['total_amount' => 1500]);

    $this->actingAs($gestionnaire->fresh())
        ->get(route('user-activities.index', [
            'period' => 'this_month',
            'activity_type' => 'sale',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 1)
            ->where('activities.data.0.subject_id', $sale->id)
            ->where('stats.sales_amount', 1500)
        );

    $this->actingAs($gestionnaire->fresh())
        ->get(route('sales.show', $sale))
        ->assertForbidden();
});

test('show_route utilise id pour les documents et session pour inventaire', function () {
    $viewer = userWithUserActivitiesView();
    $actor = User::factory()->create();

    $sale = createSaleActivity($actor);
    $expense = createExpenseActivity($actor);
    $quote = createQuoteActivity($actor, ['status' => 'draft']);

    $company = Company::getInstance();
    $store = $company->defaultStore()->firstOrFail();
    $inventory = InventorySession::create([
        'company_id' => $company->id,
        'store_id' => $store->id,
        'reference' => 'INV-SR-001',
        'name' => 'Inventaire show_route',
        'status' => InventorySessionStatus::Draft->value,
        'scope_type' => InventoryScopeType::Complete->value,
        'created_by' => $actor->id,
    ]);

    $payload = app(UserActivityQueryService::class)->build([
        'period' => 'this_month',
        'user_id' => $actor->id,
    ]);

    $byType = collect($payload['activities']->items())->keyBy('activity_type');

    expect($byType['sale']['show_route'])->toBe([
        'name' => 'sales.show',
        'params' => ['id' => $sale->id],
    ])
        ->and($byType['expense']['show_route'])->toBe([
            'name' => 'expenses.show',
            'params' => ['id' => $expense->id],
        ])
        ->and($byType['quote']['show_route'])->toBe([
            'name' => 'quotes.show',
            'params' => ['id' => $quote->id],
        ])
        ->and($byType['inventory']['show_route'])->toBe([
            'name' => 'inventory.show',
            'params' => ['session' => $inventory->id],
        ]);

    // Aucune clé legacy qui laisserait {id} non substitué dans routes.ts
    expect($byType['quote']['show_route']['params'])->not->toHaveKey('quote')
        ->and($byType['sale']['show_route']['params'])->not->toHaveKey('sale');
});
