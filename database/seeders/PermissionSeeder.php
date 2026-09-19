<?php

namespace Database\Seeders;

use App\Auth\PermissionCatalogSynchronizer;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the application's permissions from the central catalog (idempotent).
     */
    public function run(): void
    {
        app(PermissionCatalogSynchronizer::class)->sync();
    }
}
