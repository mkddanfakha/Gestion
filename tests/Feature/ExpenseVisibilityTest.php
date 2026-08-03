<?php

use App\Models\Expense;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

function createGestionnaireWithExpensesView(): User
{
    (new PermissionSeeder())->run();

    $gestionnaire = User::factory()->create(['role' => 'gestionnaire']);
    $permission = Permission::where('name', 'expenses.view')->firstOrFail();
    $gestionnaire->permissions()->attach($permission);

    return $gestionnaire;
}

function createExpenseForUser(User $user): Expense
{
    return Expense::create([
        'expense_number' => Expense::generateExpenseNumber(),
        'title' => 'Achat fournitures',
        'amount' => 5000,
        'category' => 'fournitures',
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
        'user_id' => $user->id,
    ]);
}

test('gestionnaire only sees their own expenses in index', function () {
    $gestionnaire = createGestionnaireWithExpensesView();
    $otherGestionnaire = User::factory()->create(['role' => 'gestionnaire']);

    $ownExpense = createExpenseForUser($gestionnaire);
    createExpenseForUser($otherGestionnaire);

    $response = $this->actingAs($gestionnaire)
        ->get(route('expenses.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Expenses/Index')
        ->has('expenses.data', 1)
        ->where('expenses.data.0.id', $ownExpense->id)
    );
});

test('gestionnaire cannot view another users expense', function () {
    $gestionnaire = createGestionnaireWithExpensesView();
    $otherGestionnaire = User::factory()->create(['role' => 'gestionnaire']);
    $otherExpense = createExpenseForUser($otherGestionnaire);

    $this->actingAs($gestionnaire)
        ->get(route('expenses.show', $otherExpense))
        ->assertForbidden();
});

test('admin can view all expenses', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $gestionnaire = User::factory()->create(['role' => 'gestionnaire']);

    createExpenseForUser($gestionnaire);
    createExpenseForUser($admin);

    $this->actingAs($admin)
        ->get(route('expenses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Expenses/Index')
            ->has('expenses.data', 2)
        );
});
