<?php

use App\Models\Company;
use App\Models\Store;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Company::query()->orderBy('id')->each(function (Company $company): void {
            Store::ensureDefaultForCompany($company);
        });

        Store::initializeProductStocksForPrimaryCompany();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Les données sont supprimées avec les tables stores / product_stocks.
    }
};
