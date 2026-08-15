<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerExportService;
use App\Services\CustomerIdentityService;
use App\Support\Countries;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('customer export service maps senegalese and foreign customers for excel', function () {
    $service = app(CustomerExportService::class);

    $senegalese = Customer::factory()->create([
        'name' => 'Moussa Diagne',
        'nationality' => Countries::SENEGAL_CODE,
        'country' => 'Sénégal',
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
        'birthday' => '1990-05-15',
        'notes' => 'Client local',
    ]);

    $foreign = Customer::factory()->create([
        'name' => 'Jean Dupont',
        'nationality' => 'FR',
        'country' => 'Sénégal',
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => '21AB45678',
    ]);

    $senegaleseRow = $service->mapCustomerForExcel($senegalese->fresh()->loadCount('sales'));
    $foreignRow = $service->mapCustomerForExcel($foreign->fresh()->loadCount('sales'));

    expect($senegaleseRow[8])->toBe('Sénégal')
        ->and($senegaleseRow[9])->toBe('Carte nationale d\'identité')
        ->and($senegaleseRow[10])->toBe('1234567890123')
        ->and($senegaleseRow[11])->toBe('15/05/1990')
        ->and($foreignRow[8])->toBe('France')
        ->and($foreignRow[9])->toBe('Passeport')
        ->and($foreignRow[10])->toBe('21AB45678');
});

test('customer export service maps pdf list rows with display placeholders', function () {
    $service = app(CustomerExportService::class);

    $customer = Customer::factory()->create([
        'nationality' => null,
        'identity_document_type' => null,
        'identity_document_number' => null,
    ]);

    $row = $service->mapCustomerForPdfList($customer->fresh()->loadCount('sales'));

    expect($row['nationality'])->toBe('—')
        ->and($row['identity_type'])->toBe('—')
        ->and($row['identity_number'])->toBe('—');
});

test('customer export query respects search filter like index', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $identityService = app(CustomerIdentityService::class);
    $exportService = app(CustomerExportService::class);

    $match = Customer::factory()->create([
        'name' => 'Client Recherche Export',
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => 'A12345678',
        'nationality' => Countries::SENEGAL_CODE,
    ]);

    Customer::factory()->create(['name' => 'Autre Client Export']);

    $results = $exportService->getCustomers('A12345678');

    expect($results)->toHaveCount(1)
        ->and($results->first()?->id)->toBe($match->id);
});

test('customers excel export route returns file for authorized user', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'nationality' => Countries::SENEGAL_CODE,
        'identity_document_type' => Customer::IDENTITY_TYPE_NATIONAL_ID,
        'identity_document_number' => '1234567890123',
    ]);

    $response = $this->actingAs($admin)->get(route('customers.export.excel'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
});

test('customers pdf export route returns pdf for authorized user', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Customer::factory()->create([
        'nationality' => 'FR',
        'identity_document_type' => Customer::IDENTITY_TYPE_PASSPORT,
        'identity_document_number' => '21AB45678',
    ]);

    $response = $this->actingAs($admin)->get(route('customers.export.pdf'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});
