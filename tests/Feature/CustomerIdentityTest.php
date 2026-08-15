<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerIdentityService;
use App\Support\Countries;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('customer identity can be created with senegalese national id', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Moussa Diallo',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
        'phone' => '+221 77 123 45 67',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.index'));

    $customer = Customer::query()->first();

    expect($customer)->not->toBeNull()
        ->and($customer->nationality)->toBe(Countries::SENEGAL_CODE)
        ->and($customer->identity_document_type)->toBe(Customer::IDENTITY_TYPE_NATIONAL_ID)
        ->and($customer->identity_document_number)->toBe('1234567890123')
        ->and($customer->identity_document_number_normalized)->toBe('1234567890123');
});

test('senegalese national id must contain exactly 13 digits on create', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('customers.store'), [
        'name' => 'Moussa Diallo',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456789012',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('senegalese passport must match A plus eight digits on create', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('customers.store'), [
        'name' => 'Awa Ndiaye',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'A12345678',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.index'));

    expect(Customer::query()->first()?->identity_document_number_normalized)->toBe('A12345678');
});

test('invalid senegalese passport is rejected on create', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('customers.store'), [
        'name' => 'Awa Ndiaye',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'B12345678',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('foreign passport is accepted without senegalese format rules', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Jean Dupont',
        'nationality' => 'FR',
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => '21AB45678',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.index'));

    expect(Customer::query()->first()?->identity_document_number)->toBe('21AB45678');
});

test('duplicate identity document is rejected on create', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
    ]);

    $response = $this->actingAs($admin)->postJson(route('customers.store'), [
        'name' => 'Autre Client',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('same normalized number with different document type is allowed', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
    ]);

    $response = $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Client Passeport',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => '1234567890123',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.index'));

    expect(Customer::query()->count())->toBe(2);
});

test('customer can keep own identity on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
    ]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.show', $customer));
});

test('legacy customer with non conforming identity can be updated without changing identity', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $customer = Customer::factory()->create([
        'name' => 'Client historique',
        'nationality' => null,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
    ]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => 'Client historique renommé',
        'nationality' => null,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.show', $customer));

    expect($customer->fresh()->name)->toBe('Client historique renommé');
});

test('customer cannot take another customers identity on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
    ]);

    $customer = Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'A87654321',
    ]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
        'is_active' => true,
    ]);

    $response->assertSessionHasErrors(['identity_document_number']);
});

test('two customers with same name but different identity are allowed', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'name' => 'Moussa Diallo',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1111111111111',
    ]);

    $response = $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Moussa Diallo',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '2222222222222',
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
        'nationality' => 'FR',
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
        'nationality' => 'FR',
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
        'nationality' => 'FR',
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
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
        ->and(config('audit.enum_labels.identity_document_type.national_id'))->toBe('Carte nationale d\'identité');
});

test('customer identity service validates foreign document formats flexibly', function () {
    $service = app(CustomerIdentityService::class);

    $service->assertDocumentFormat('FR', Customer::IDENTITY_TYPE_PASSPORT, '21AB45678');
    $service->assertDocumentFormat('ML', Customer::IDENTITY_TYPE_OTHER, 'XK-123456');
    $service->assertDocumentFormat('US', Customer::IDENTITY_TYPE_NATIONAL_ID, '477788555224155');

    expect(fn () => $service->assertDocumentFormat('FR', Customer::IDENTITY_TYPE_PASSPORT, '12'))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    expect(fn () => $service->assertDocumentFormat('US', Customer::IDENTITY_TYPE_NATIONAL_ID, '@@@@@@'))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('valid identity document number rule delegates to customer identity service', function () {
    $rule = new App\Rules\ValidIdentityDocumentNumber(Countries::SENEGAL_CODE, Customer::IDENTITY_TYPE_NATIONAL_ID);

    $failMessage = null;
    $rule->validate('identity_document_number', '1234567890123', function (string $message) use (&$failMessage) {
        $failMessage = $message;
    });

    expect($failMessage)->toBeNull();

    $rule->validate('identity_document_number', '123456789012', function (string $message) use (&$failMessage) {
        $failMessage = $message;
    });

    expect($failMessage)->toBe('Le numéro de CNI sénégalaise doit comporter exactement 13 chiffres.');
});

test('senegalese national id must contain exactly 13 digits on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
    ]);

    $response = $this->actingAs($admin)->putJson(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '123456789012',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('senegalese national id with 14 digits is rejected on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
    ]);

    $response = $this->actingAs($admin)->putJson(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '12345678901234',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('senegalese passport must match A plus eight digits on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'A12345678',
    ]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'A12345678',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.show', $customer));
});

test('invalid senegalese passport is rejected on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'A12345678',
    ]);

    $response = $this->actingAs($admin)->putJson(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'A1234567',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('foreign national id is accepted on update without senegalese format rules', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'nationality' => 'US',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '477788555224155',
    ]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => 'US',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '477788555224155',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.show', $customer));
});

test('changing nationality from senegal to foreign revalidates identity with foreign rules', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
    ]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => 'US',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.show', $customer));

    expect($customer->fresh())
        ->nationality->toBe('US')
        ->identity_document_number->toBe('1234567890123');
});

test('changing nationality from foreign to senegal applies senegalese rules', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'nationality' => 'US',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '477788555224155',
    ]);

    $response = $this->actingAs($admin)->putJson(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '477788555224155',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('changing document type from national id to passport revalidates format', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
    ]);

    $response = $this->actingAs($admin)->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'A12345678',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.show', $customer));

    expect($customer->fresh())
        ->identity_document_type->toBe(Customer::IDENTITY_TYPE_PASSPORT)
        ->identity_document_number->toBe('A12345678');
});

test('senegalese passport rejects national id number format', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('customers.store'), [
        'name' => 'Test Client',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => '1234567890123',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('senegalese national id rejects passport number format', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson(route('customers.store'), [
        'name' => 'Test Client',
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => 'A12345678',
        'is_active' => true,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document_number']);
});

test('senegalese national id remains valid when country of residence differs', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post(route('customers.store'), [
        'name' => 'Client expatrié',
        'nationality' => Countries::SENEGAL_CODE,
        'country' => 'États-Unis',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
        'is_active' => true,
    ]);

    $response->assertRedirect(route('customers.index'));
});
