<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/StockServiceTest.php');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Rbac/AuthorizationServiceTest.php');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Rbac/AdminProtectionServiceTest.php');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Rbac/AuthorizationCacheTest.php');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Rbac/RbacAuditTest.php');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Rbac/LegacyPermissionMigrationTest.php');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Rbac/RolePresetIntegrityTest.php');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Rbac/RbacArchitectureIntegrityTest.php');

pest()->extend(Tests\TestCase::class)
    ->in('Unit/Infrastructure');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use App\Models\Category;
use App\Models\Product;

function createTestCategory(): Category
{
    return Category::create([
        'name' => 'Catégorie test '.uniqid(),
        'color' => '#3B82F6',
    ]);
}

function createTestProduct(array $overrides = []): Product
{
    $category = $overrides['category_id'] ?? createTestCategory()->id;

    return Product::create(array_merge([
        'name' => 'Produit test '.uniqid(),
        'sku' => 'TS'.strtoupper(substr(uniqid(), -4)),
        'price' => 1000,
        'cost_price' => 500,
        'stock_quantity' => 12,
        'min_stock_level' => 2,
        'unit' => 'pièce',
        'category_id' => $category,
        'is_active' => true,
    ], $overrides));
}
