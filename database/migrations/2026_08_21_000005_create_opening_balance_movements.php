<?php

use App\Models\StockMovement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        StockMovement::createOpeningBalancesFromExistingStocks();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('stock_movements')
            ->where('type', 'opening_balance')
            ->where('metadata->migration', 'phase_1_inventory_foundation')
            ->delete();
    }
};
