<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('customer identity can be created with national id', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Moussa Diallo',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
        'phone' => '+221 77 123 45 67',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.index'));

    $customer = Customer::query()->first();

    expect($customer)->not->toBeNull()
        ->and($customer->identity_document_type)->toBe(Customer::IDENTITY_TYPE_NATIONAL_ID)
        ->and($customer->identity_document_number)->toBe('123456')
        ->and($customer->identity_document_number_normalized)->toBe('123456');
});

test('duplicate identity document is rejected on create', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
    ]);

    $response = $this->actingAs($admin)->postJson(route('customers.store'), [
        'name' => 'Autre Client',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('same normalized number with different document type is allowed', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
    ]);

    $response = $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Client Passeport',
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => '123456',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.index'));

    expect(Customer::query()->count())->toBe(2);
});

test('customer can keep own identity on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
    ]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.show', $customer));
});

test('customer cannot take another customers identity on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
    ]);

    $customer = Customer::factory()->create([
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => '999999',
    ]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
        'is_active' => true,
    ]);

    $response->assertSessionHasErrors(['identity_document_number']);
});

test('two customers with same name but different identity are allowed', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'name' => 'Moussa Diallo',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '111111',
    ]);

    $response = $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Moussa Diallo',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '222222',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.index'));
});

test('duplicate check detects phone match without blocking', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $existing = Customer::factory()->create([
        'phone' => '+221 77 123 45 67',
    ]);

    $response = $this->actingAs($admin)->getJson(route('customers.check-duplicates', [
        'phone' => '77 123 45 67',
    ]));

    $response->assertOk()
        ->assertJsonPath('phone_match.id', $existing->id)
        ->assertJsonPath('identity_available', true);
});

test('duplicate check detects email match', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $existing = Customer::factory()->create([
        'email' => 'client@example.com',
    ]);

    $response = $this->actingAs($admin)->getJson(route('customers.check-duplicates', [
        'email' => 'client@example.com',
    ]));

    $response->assertOk()
        ->assertJsonPath('email_match.id', $existing->id)
        ->assertJsonPath('has_duplicates', true);
});

test('duplicate check accepts incomplete email without 422', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->getJson(route('customers.check-duplicates', [
        'email' => 'tiguida@',
        'name' => 'Tiguida Diallo',
    ]));

    $response->assertOk()
        ->assertJsonPath('email_match', null)
        ->assertJsonPath('has_duplicates', false);
});

test('duplicate check ignores identity type without number', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'ABC123456',
    ]);

    $response = $this->actingAs($admin)->getJson(route('customers.check-duplicates', [
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'phone' => '776398547',
    ]));

    $response->assertOk()
        ->assertJsonPath('identity_conflict', null);
});

test('duplicate check excludes current customer on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $customer = Customer::factory()->create([
        'email' => 'tiguida@diallo.com',
        'phone' => '776398547',
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'ABC123456',
    ]);

    $response = $this->actingAs($admin)->getJson(route('customers.check-duplicates', [
        'customer_id' => $customer->id,
        'email' => 'tiguida@diallo.com',
        'phone' => '776398547',
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'ABC123456',
    ]));

    $response->assertOk()
        ->assertJsonPath('has_duplicates', false)
        ->assertJsonPath('identity_conflict', null)
        ->assertJsonPath('phone_match', null)
        ->assertJsonPath('email_match', null);
});

test('duplicate check returns empty result when no usable criteria', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->getJson(route('customers.check-duplicates', [
        'email' => 'ti',
    ]));

    $response->assertOk()
        ->assertJsonPath('has_duplicates', false)
        ->assertJsonPath('matches', []);
});

test('identity number normalization treats formatting variants as equal', function () {
    $service = app(CustomerIdentityService::class);

    expect($service->normalizeDocumentNumber('AB-123-456'))->toBe('AB123456')
        ->and($service->normalizeDocumentNumber('ab123456'))->toBe('AB123456');
});

test('phone normalization treats senegal formats as equal', function () {
    $service = app(CustomerIdentityService::class);

    expect($service->normalizePhone('+221 77 123 45 67'))->toBe('771234567')
        ->and($service->normalizePhone('77 123 45 67'))->toBe('771234567');
});

test('autocomplete search finds customer by identity number', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $customer = Customer::factory()->create([
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => 'AB-123-456',
    ]);

    $response = $this->actingAs($admin)->getJson(route('customers.autocomplete', [
        'q' => 'ab123456',
    ]));

    $response->assertOk()
        ->assertJsonFragment(['id' => $customer->id]);
});

test('identity update is tracked in audit log labels', function () {
    expect(config('audit.field_labels.identity_document_number'))->toBe('Numéro de pièce d\'identité')
        ->and(config('audit.enum_labels.identity_document_type.national_id'))->toBe('Carte d\'identité');
});
