<?php

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createUserWithCompanyUpdate(): User
{
    (new PermissionSeeder())->run();

    $user = User::factory()->create(['role' => 'user']);
    $permission = Permission::where('name', 'company.update')->firstOrFail();
    $user->permissions()->attach($permission);

    return $user;
}

beforeEach(function () {
    Storage::fake('media');
    Company::query()->delete();
});

test('authorized user can upload company logo', function () {
    $user = createUserWithCompanyUpdate();
    $company = Company::getInstance();

    $file = UploadedFile::fake()->image('logo.png', 200, 100)->size(100);

    $this->actingAs($user)
        ->post(route('company.logo.upload'), ['logo' => $file])
        ->assertRedirect(route('company.edit'));

    $company->refresh();

    expect($company->logo_path)->not->toBeNull();
    expect(Storage::disk('media')->exists($company->logo_path))->toBeTrue();
    expect($company->logo_url)->toStartWith('/storage/');
});

test('unauthorized user cannot upload company logo', function () {
    $user = User::factory()->create(['role' => 'user']);
    $file = UploadedFile::fake()->image('logo.png');

    $this->actingAs($user)
        ->post(route('company.logo.upload'), ['logo' => $file])
        ->assertForbidden();
});

test('company logo upload rejects invalid file type', function () {
    $user = createUserWithCompanyUpdate();
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    $this->actingAs($user)
        ->post(route('company.logo.upload'), ['logo' => $file])
        ->assertSessionHasErrors('logo');
});

test('authorized user can delete company logo', function () {
    $user = createUserWithCompanyUpdate();
    $company = Company::getInstance();

    $file = UploadedFile::fake()->image('logo.png');
    $path = $file->storeAs('companies/' . $company->id, 'logo.png', 'media');
    $company->update(['logo_path' => $path]);

    $this->actingAs($user)
        ->delete(route('company.logo.delete'))
        ->assertRedirect(route('company.edit'));

    $company->refresh();

    expect($company->logo_path)->toBeNull();
    expect(Storage::disk('media')->exists($path))->toBeFalse();
});

test('company logo upload is logged in activity log', function () {
    $user = createUserWithCompanyUpdate();
    $file = UploadedFile::fake()->image('logo.png');

    $this->actingAs($user)
        ->post(route('company.logo.upload'), ['logo' => $file])
        ->assertRedirect();

    expect(ActivityLog::query()->where('description', 'a ajouté le logo de l\'entreprise')->exists())->toBeTrue();
});

test('company logo replace is logged in activity log', function () {
    $user = createUserWithCompanyUpdate();
    $company = Company::getInstance();

    $existing = UploadedFile::fake()->image('old.png');
    $path = $existing->storeAs('companies/' . $company->id, 'logo.png', 'media');
    $company->update(['logo_path' => $path]);

    $file = UploadedFile::fake()->image('new.png');

    $this->actingAs($user)
        ->post(route('company.logo.upload'), ['logo' => $file])
        ->assertRedirect();

    expect(ActivityLog::query()->where('description', 'a remplacé le logo de l\'entreprise')->exists())->toBeTrue();
});

test('document header partial renders company logo when present', function () {
    $company = Company::getInstance();
    $file = UploadedFile::fake()->image('logo.png');
    $path = $file->storeAs('companies/' . $company->id, 'logo.png', 'media');
    $company->update(['logo_path' => $path]);

    $html = view('partials.document-header', [
        'company' => $company->fresh(),
        'documentNumber' => 'F-2026-001',
        'documentDate' => now(),
        'documentLabel' => 'Facture',
    ])->render();

    expect($html)->toContain('company-logo');
    expect($html)->toContain($company->fresh()->logo_absolute_path);
});
