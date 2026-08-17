<?php

use App\Models\DeliveryNote;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AttachmentService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createUserWithDeliveryNotePdfPermissions(array $actions = ['view', 'download', 'print']): User
{
    (new PermissionSeeder())->run();

    $user = User::factory()->create(['role' => 'user']);

    foreach ($actions as $action) {
        $permission = Permission::where('name', "delivery-notes.{$action}")->firstOrFail();
        $user->permissions()->attach($permission);
    }

    return $user;
}

test('authorized user can download delivery note pdf', function () {
    $user = createUserWithDeliveryNotePdfPermissions(['view', 'download']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);

    $response = $this->actingAs($user)
        ->get(route('delivery-notes.download', $deliveryNote));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))
        ->toContain('BL-' . $deliveryNote->delivery_number . '.pdf');
});

test('authorized user can preview delivery note pdf inline', function () {
    $user = createUserWithDeliveryNotePdfPermissions(['view', 'print']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);

    $response = $this->actingAs($user)
        ->get(route('delivery-notes.print', $deliveryNote));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});

test('user without download permission cannot download delivery note pdf', function () {
    $user = createUserWithDeliveryNotePdfPermissions(['view']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);

    $this->actingAs($user)
        ->get(route('delivery-notes.download', $deliveryNote))
        ->assertForbidden();
});

test('download delivery note pdf returns 404 for missing delivery note', function () {
    $user = createUserWithDeliveryNotePdfPermissions(['view', 'download']);

    $this->actingAs($user)
        ->get(route('delivery-notes.download', ['deliveryNote' => 999999]))
        ->assertNotFound();
});

test('generating delivery note pdf does not change product stock or status', function () {
    Storage::fake('local');

    $user = createUserWithDeliveryNotePdfPermissions(['view', 'download', 'print']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);

    $stockBefore = $product->fresh()->stock_quantity;
    $statusBefore = $deliveryNote->status;

    $this->actingAs($user)->get(route('delivery-notes.download', $deliveryNote))->assertOk();
    $this->actingAs($user)->get(route('delivery-notes.print', $deliveryNote))->assertOk();

    $deliveryNote->refresh();
    $product->refresh();

    expect($product->stock_quantity)->toBe($stockBefore);
    expect($deliveryNote->status)->toBe($statusBefore);
});

test('pending and validated delivery notes can generate pdf', function () {
    $user = createUserWithDeliveryNotePdfPermissions(['view', 'download']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);

    $pending = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);
    $this->actingAs($user)->get(route('delivery-notes.download', $pending))->assertOk();

    $validated = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);
    $validated->update(['status' => 'validated']);
    $this->actingAs($user)->get(route('delivery-notes.download', $validated))->assertOk();
});

test('official delivery note pdf and attachments use separate paths', function () {
    Storage::fake('local');

    $user = createUserWithDeliveryNotePdfPermissions(['view', 'download', 'print']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);

    $supplierInvoice = UploadedFile::fake()->create('facture-fournisseur.pdf', 100, 'application/pdf');
    $signedBl = UploadedFile::fake()->create('bl-signe.pdf', 100, 'application/pdf');
    $photo = UploadedFile::fake()->image('photo-livraison.jpg');

    $attachmentService = app(AttachmentService::class);
    $attachmentService->add($deliveryNote, $supplierInvoice, $user);
    $attachmentService->add($deliveryNote, $signedBl, $user);
    $attachmentService->add($deliveryNote, $photo, $user);

    $deliveryNote->refresh();
    expect($deliveryNote->attachments)->toHaveCount(3);

    $officialPdf = $this->actingAs($user)
        ->get(route('delivery-notes.download', $deliveryNote));

    $officialPdf->assertOk();
    expect($officialPdf->headers->get('Content-Disposition'))
        ->toContain('BL-' . $deliveryNote->delivery_number . '.pdf');

    foreach ($deliveryNote->attachments as $attachment) {
        $this->actingAs($user)
            ->get(route('attachments.download', $attachment))
            ->assertOk();
    }

    expect($deliveryNote->attachments->pluck('original_name')->all())->toEqual([
        'facture-fournisseur.pdf',
        'bl-signe.pdf',
        'photo-livraison.jpg',
    ]);
});

test('legacy supplier invoice routes are removed', function () {
    $user = createUserWithDeliveryNotePdfPermissions(['view', 'download']);
    $supplier = createTestSupplier();
    $product = createTestProduct();
    $purchaseOrder = createTestPurchaseOrder($user, $supplier, $product);
    $deliveryNote = createTestDeliveryNote($user, $supplier, $product, $purchaseOrder);

    $this->actingAs($user)
        ->get('/delivery-notes/' . $deliveryNote->id . '/invoice')
        ->assertNotFound();
});
