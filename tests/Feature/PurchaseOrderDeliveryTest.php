<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createProcurementFixture(int $orderedQuantity = 100, int $initialStock = 50): array
{
    $admin = User::factory()->create(['role' => 'admin']);

    $supplier = Supplier::create([
        'name' => 'Fournisseur Test',
        'status' => 'active',
    ]);

    $category = Category::create([
        'name' => 'Test',
        'slug' => 'test-'.uniqid(),
    ]);

    $product = Product::create([
        'name' => 'Riz 25 kg',
        'sku' => 'RIZ-25',
        'price' => 15000,
        'cost_price' => 12000,
        'stock_quantity' => $initialStock,
        'min_stock_level' => 1,
        'unit' => 'u',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'po_number' => PurchaseOrder::generatePONumber(),
        'supplier_id' => $supplier->id,
        'user_id' => $admin->id,
        'order_date' => now()->toDateString(),
        'status' => 'confirmed',
        'subtotal' => $orderedQuantity * 12000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => $orderedQuantity * 12000,
    ]);

    PurchaseOrderItem::create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'quantity' => $orderedQuantity,
        'unit_price' => 12000,
        'total_price' => $orderedQuantity * 12000,
    ]);

    return compact('admin', 'supplier', 'product', 'purchaseOrder');
}

function createPendingDeliveryNote(PurchaseOrder $purchaseOrder, Supplier $supplier, Product $product, int $quantity, User $admin): DeliveryNote
{
    $deliveryNote = DeliveryNote::create([
        'delivery_number' => DeliveryNote::generateDeliveryNumber(),
        'purchase_order_id' => $purchaseOrder->id,
        'supplier_id' => $supplier->id,
        'user_id' => $admin->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => $quantity * 12000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => $quantity * 12000,
    ]);

    DeliveryNoteItem::create([
        'delivery_note_id' => $deliveryNote->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'unit_price' => 12000,
        'total_price' => $quantity * 12000,
    ]);

    return $deliveryNote->fresh(['items']);
}

function countDeliveryNoteStockAuditLogs(): int
{
    return ActivityLog::query()
        ->where('module', 'Produit')
        ->where('description', 'like', '%via bon de livraison%')
        ->count();
}

test('remaining quantity starts at ordered amount', function () {
    ['purchaseOrder' => $purchaseOrder] = createProcurementFixture(100);
    $service = app(PurchaseOrderDeliveryService::class);

    $summary = $service->buildReceiptSummary($purchaseOrder);

    expect($summary['totals']['ordered'])->toBe(100)
        ->and($summary['totals']['delivered'])->toBe(0)
        ->and($summary['totals']['remaining'])->toBe(100)
        ->and($summary['progress_percent'])->toBe(0.0)
        ->and($summary['can_create_delivery'])->toBeTrue()
        ->and($service->canCreateDelivery($purchaseOrder))->toBeTrue();
});

test('partial delivery updates remaining quantity and purchase order status', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $service = app(PurchaseOrderDeliveryService::class);

    $deliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 40, $admin);
    $service->validateDeliveryNote($deliveryNote);

    $summary = $service->buildReceiptSummary($purchaseOrder->fresh());
    $product->refresh();
    $purchaseOrder->refresh();

    expect($summary['totals']['delivered'])->toBe(40)
        ->and($summary['totals']['remaining'])->toBe(60)
        ->and($summary['progress_percent'])->toBe(40.0)
        ->and($purchaseOrder->status)->toBe('partially_received')
        ->and($product->stock_quantity)->toBe(90);
});

test('multiple partial deliveries reach fully received status', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $service = app(PurchaseOrderDeliveryService::class);

    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 40, $admin));
    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 30, $admin));
    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 30, $admin));

    $summary = $service->buildReceiptSummary($purchaseOrder->fresh());
    $purchaseOrder->refresh();
    $product->refresh();

    expect($summary['totals']['delivered'])->toBe(100)
        ->and($summary['totals']['remaining'])->toBe(0)
        ->and($summary['is_fully_delivered'])->toBeTrue()
        ->and($summary['can_create_delivery'])->toBeFalse()
        ->and($purchaseOrder->status)->toBe('received')
        ->and($product->stock_quantity)->toBe(150);
});

test('delivery exceeding remaining quantity is rejected', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100);
    $service = app(PurchaseOrderDeliveryService::class);

    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 80, $admin));

    expect(fn () => $service->assertDeliveryQuantities($purchaseOrder->fresh(), [
        ['product_id' => $product->id, 'quantity' => 25],
    ]))->toThrow(ValidationException::class);
});

test('validated delivery note applies stock delta only once', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $service = app(PurchaseOrderDeliveryService::class);
    $deliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 20, $admin);

    $service->validateDeliveryNote($deliveryNote);
    $service->validateDeliveryNote($deliveryNote->fresh());

    expect($product->fresh()->stock_quantity)->toBe(70);
});

test('validated delivery note quantity update applies stock delta', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $service = app(PurchaseOrderDeliveryService::class);
    $deliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 20, $admin);
    $service->validateDeliveryNote($deliveryNote);

    $service->updateValidatedDeliveryNoteItems($deliveryNote->fresh(), [[
        'product_id' => $product->id,
        'quantity' => 25,
        'unit_price' => 12000,
        'total_price' => 300000,
    ]]);

    expect($product->fresh()->stock_quantity)->toBe(75);

    $service->updateValidatedDeliveryNoteItems($deliveryNote->fresh(), [[
        'product_id' => $product->id,
        'quantity' => 15,
        'unit_price' => 12000,
        'total_price' => 180000,
    ]]);

    expect($product->fresh()->stock_quantity)->toBe(65);
});

test('cancelling validated delivery note reverses stock and purchase order status', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $service = app(PurchaseOrderDeliveryService::class);
    $deliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 25, $admin);

    $service->validateDeliveryNote($deliveryNote);
    $service->cancelDeliveryNote($deliveryNote->fresh());

    $summary = $service->buildReceiptSummary($purchaseOrder->fresh());

    expect($deliveryNote->fresh()->status)->toBe('cancelled')
        ->and($summary['totals']['delivered'])->toBe(0)
        ->and($summary['totals']['remaining'])->toBe(100)
        ->and($product->fresh()->stock_quantity)->toBe(50)
        ->and($purchaseOrder->fresh()->status)->toBe('confirmed');
});

test('delivery note store endpoint rejects over delivery', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100);
    $service = app(PurchaseOrderDeliveryService::class);
    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 80, $admin));

    $response = $this->actingAs($admin)->post(route('delivery-notes.store'), [
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $purchaseOrder->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 300000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 300000,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 25,
            'unit_price' => 12000,
            'total_price' => 300000,
        ]],
    ]);

    $response->assertSessionHasErrors();
});

test('purchase order receipt summary endpoint returns remaining quantities', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100);
    $service = app(PurchaseOrderDeliveryService::class);
    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 40, $admin));

    $response = $this->actingAs($admin)->getJson(route('purchase-orders.receipt-summary', $purchaseOrder));

    $response->assertOk()
        ->assertJsonPath('totals.remaining', 60)
        ->assertJsonPath('progress_percent', 40);
});

test('creating pending delivery note does not change stock', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);

    $this->actingAs($admin)->post(route('delivery-notes.store'), [
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $purchaseOrder->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 240000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 240000,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 20,
            'unit_price' => 12000,
            'total_price' => 240000,
        ]],
    ])->assertRedirect(route('delivery-notes.index'));

    expect($product->fresh()->stock_quantity)->toBe(50)
        ->and(countDeliveryNoteStockAuditLogs())->toBe(0);
});

test('delivery note show endpoint is read only for stock', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $deliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 20, $admin);

    $this->actingAs($admin)->get(route('delivery-notes.show', $deliveryNote))->assertOk();
    $this->actingAs($admin)->get(route('delivery-notes.show', $deliveryNote))->assertOk();

    expect($product->fresh()->stock_quantity)->toBe(50)
        ->and($deliveryNote->fresh()->status)->toBe('pending')
        ->and(countDeliveryNoteStockAuditLogs())->toBe(0);
});

test('validate endpoint applies stock exactly once', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 100);
    $deliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 20, $admin);

    $this->actingAs($admin)
        ->post(route('delivery-notes.validate', $deliveryNote))
        ->assertRedirect(route('delivery-notes.show', $deliveryNote));

    expect($product->fresh()->stock_quantity)->toBe(120)
        ->and($deliveryNote->fresh()->status)->toBe('validated')
        ->and(countDeliveryNoteStockAuditLogs())->toBe(1);
});

test('validate endpoint rejects second validation without changing stock', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 100);
    $deliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 20, $admin);

    $this->actingAs($admin)->post(route('delivery-notes.validate', $deliveryNote));
    $this->actingAs($admin)
        ->post(route('delivery-notes.validate', $deliveryNote))
        ->assertSessionHasErrors(['message']);

    expect($product->fresh()->stock_quantity)->toBe(120)
        ->and(countDeliveryNoteStockAuditLogs())->toBe(1);
});

test('cancel endpoint is idempotent for stock', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $deliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 20, $admin);
    $service = app(PurchaseOrderDeliveryService::class);
    $service->validateDeliveryNote($deliveryNote);

    $this->actingAs($admin)->post(route('delivery-notes.cancel', $deliveryNote));
    $this->actingAs($admin)->post(route('delivery-notes.cancel', $deliveryNote->fresh()));

    expect($product->fresh()->stock_quantity)->toBe(50)
        ->and(countDeliveryNoteStockAuditLogs())->toBe(2);
});

test('confirmed purchase order does not change stock on its own', function () {
    ['product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);

    expect($purchaseOrder->status)->toBe('confirmed')
        ->and($product->fresh()->stock_quantity)->toBe(50)
        ->and(countDeliveryNoteStockAuditLogs())->toBe(0);
});

test('standalone delivery note can be stored without purchase order', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product] = createProcurementFixture(100, 50);

    $this->actingAs($admin)
        ->post(route('delivery-notes.store'), [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => null,
            'delivery_date' => now()->toDateString(),
            'status' => 'pending',
            'subtotal' => 24000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 24000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 12000,
                    'total_price' => 24000,
                ],
            ],
        ])
        ->assertRedirect(route('delivery-notes.index'));

    $deliveryNote = DeliveryNote::first();
    expect($deliveryNote)->not->toBeNull()
        ->and($deliveryNote->purchase_order_id)->toBeNull()
        ->and($product->fresh()->stock_quantity)->toBe(50);
});

test('standalone delivery note validation updates stock without purchase order', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product] = createProcurementFixture(100, 50);

    $deliveryNote = DeliveryNote::create([
        'delivery_number' => DeliveryNote::generateDeliveryNumber(),
        'purchase_order_id' => null,
        'supplier_id' => $supplier->id,
        'user_id' => $admin->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 24000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 24000,
    ]);

    DeliveryNoteItem::create([
        'delivery_note_id' => $deliveryNote->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 12000,
        'total_price' => 24000,
    ]);

    $service = app(PurchaseOrderDeliveryService::class);
    $service->validateDeliveryNote($deliveryNote->fresh(['items']));

    expect($deliveryNote->fresh()->status)->toBe('validated')
        ->and($product->fresh()->stock_quantity)->toBe(52);
});

test('draft purchase order cannot receive delivery', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $purchaseOrder->update(['status' => 'draft']);
    $service = app(PurchaseOrderDeliveryService::class);

    expect($service->canCreateDelivery($purchaseOrder->fresh()))->toBeFalse();
});

test('cancelled purchase order cannot receive delivery', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $purchaseOrder->update(['status' => 'cancelled']);
    $service = app(PurchaseOrderDeliveryService::class);

    expect($service->canCreateDelivery($purchaseOrder->fresh()))->toBeFalse();

    $response = $this->actingAs($admin)->post(route('delivery-notes.store'), [
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $purchaseOrder->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 120000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 120000,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_price' => 12000,
            'total_price' => 120000,
        ]],
    ]);

    $response->assertSessionHasErrors();
    expect($product->fresh()->stock_quantity)->toBe(50);
});

test('fully delivered purchase order cannot receive new delivery note', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $service = app(PurchaseOrderDeliveryService::class);

    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 100, $admin));

    $purchaseOrder->refresh();
    $summary = $service->buildReceiptSummary($purchaseOrder);

    expect($purchaseOrder->status)->toBe('received')
        ->and($summary['can_create_delivery'])->toBeFalse()
        ->and($service->canCreateDelivery($purchaseOrder))->toBeFalse();

    $response = $this->actingAs($admin)->post(route('delivery-notes.store'), [
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $purchaseOrder->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 12000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 12000,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 12000,
            'total_price' => 12000,
        ]],
    ]);

    $response->assertSessionHasErrors();
    expect(DeliveryNote::count())->toBe(1);
});

test('sequential deliveries of 30 50 and 20 update stock and purchase order progressively', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 0);
    $service = app(PurchaseOrderDeliveryService::class);

    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 30, $admin));

    $summary = $service->buildReceiptSummary($purchaseOrder->fresh());
    expect($summary['totals']['delivered'])->toBe(30)
        ->and($summary['totals']['remaining'])->toBe(70)
        ->and($product->fresh()->stock_quantity)->toBe(30)
        ->and($purchaseOrder->fresh()->status)->toBe('partially_received');

    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 50, $admin));

    $summary = $service->buildReceiptSummary($purchaseOrder->fresh());
    expect($summary['totals']['delivered'])->toBe(80)
        ->and($summary['totals']['remaining'])->toBe(20)
        ->and($product->fresh()->stock_quantity)->toBe(80);

    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 20, $admin));

    $summary = $service->buildReceiptSummary($purchaseOrder->fresh());
    expect($summary['totals']['delivered'])->toBe(100)
        ->and($summary['totals']['remaining'])->toBe(0)
        ->and($summary['is_fully_delivered'])->toBeTrue()
        ->and($product->fresh()->stock_quantity)->toBe(100)
        ->and($purchaseOrder->fresh()->status)->toBe('received')
        ->and(countDeliveryNoteStockAuditLogs())->toBe(3);
});

test('pending delivery note is not counted as delivered on purchase order', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $service = app(PurchaseOrderDeliveryService::class);

    createPendingDeliveryNote($purchaseOrder, $supplier, $product, 40, $admin);

    $summary = $service->buildReceiptSummary($purchaseOrder->fresh());

    expect($summary['totals']['delivered'])->toBe(0)
        ->and($summary['totals']['remaining'])->toBe(100)
        ->and($summary['totals']['pending'])->toBe(40)
        ->and($product->fresh()->stock_quantity)->toBe(50)
        ->and(countDeliveryNoteStockAuditLogs())->toBe(0);
});

test('validate endpoint rejects over delivery without changing stock or purchase order', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $service = app(PurchaseOrderDeliveryService::class);

    $service->validateDeliveryNote(createPendingDeliveryNote($purchaseOrder, $supplier, $product, 80, $admin));

    $overDeliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 30, $admin);

    $this->actingAs($admin)
        ->post(route('delivery-notes.validate', $overDeliveryNote))
        ->assertSessionHasErrors();

    expect($overDeliveryNote->fresh()->status)->toBe('pending')
        ->and($product->fresh()->stock_quantity)->toBe(130)
        ->and($purchaseOrder->fresh()->status)->toBe('partially_received')
        ->and(countDeliveryNoteStockAuditLogs())->toBe(1);
});

test('delivery note update endpoint does not change stock while pending', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 50);
    $deliveryNote = createPendingDeliveryNote($purchaseOrder, $supplier, $product, 20, $admin);
    $item = $deliveryNote->items->first();

    $this->actingAs($admin)->put(route('delivery-notes.update', $deliveryNote), [
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $purchaseOrder->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 360000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 360000,
        'items' => [[
            'id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 30,
            'unit_price' => 12000,
            'total_price' => 360000,
        ]],
    ])->assertRedirect(route('delivery-notes.show', $deliveryNote));

    expect($product->fresh()->stock_quantity)->toBe(50)
        ->and($deliveryNote->fresh()->status)->toBe('pending')
        ->and($deliveryNote->fresh()->items->first()->quantity)->toBe(30)
        ->and(countDeliveryNoteStockAuditLogs())->toBe(0);
});

test('concurrent pending delivery notes cannot exceed ordered quantity on store', function () {
    ['admin' => $admin, 'supplier' => $supplier, 'product' => $product, 'purchaseOrder' => $purchaseOrder] = createProcurementFixture(100, 0);

    $this->actingAs($admin)->post(route('delivery-notes.store'), [
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $purchaseOrder->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 840000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 840000,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 70,
            'unit_price' => 12000,
            'total_price' => 840000,
        ]],
    ])->assertRedirect(route('delivery-notes.index'));

    $this->actingAs($admin)->post(route('delivery-notes.store'), [
        'supplier_id' => $supplier->id,
        'purchase_order_id' => $purchaseOrder->id,
        'delivery_date' => now()->toDateString(),
        'status' => 'pending',
        'subtotal' => 840000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 840000,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 70,
            'unit_price' => 12000,
            'total_price' => 840000,
        ]],
    ])->assertSessionHasErrors();

    expect(DeliveryNote::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(0);
});
