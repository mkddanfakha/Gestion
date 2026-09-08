<?php

use App\Auth\PermissionCatalog;
use App\Enums\PermissionName;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: creates user-activities.view from PermissionCatalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        $definition = PermissionCatalog::find(PermissionName::UserActivitiesView);

        Permission::firstOrCreate(
            [
                'resource' => $definition['module'],
                'action' => $definition['action'],
            ],
            [
                'name' => $definition['name'],
                'description' => $definition['description'],
            ],
        );
    }

    public function down(): void
    {
        Permission::query()
            ->where('name', PermissionName::UserActivitiesView->value)
            ->delete();
    }
};
