<?php

namespace Database\Seeders;

use App\Auth\PermissionCatalog;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the application's permissions from the central catalog (idempotent).
     */
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $definition) {
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
    }
}
