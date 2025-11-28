<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convertir les anciens numéros de vente au nouveau format (9 caractères)
        $sales = DB::table('sales')->get();

        foreach ($sales as $sale) {
            $oldSaleNumber = $sale->sale_number;

            // Vérifier si c'est l'ancien format (YYMMDDXX - 8 caractères)
            if (preg_match('/^(\d{6})(\d{2})$/', $oldSaleNumber, $matches)) {
                $datePart = $matches[1]; // YYMMDD
                $numberPart = $matches[2]; // XX

                // Convertir XX en XXX (ajouter un zéro devant)
                $newNumber = str_pad($numberPart, 3, '0', STR_PAD_LEFT);

                $newSaleNumber = $datePart . $newNumber;

                // Mettre à jour le numéro de vente
                DB::table('sales')
                    ->where('id', $sale->id)
                    ->update(['sale_number' => $newSaleNumber]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convertir les nouveaux numéros de vente à l'ancien format (8 caractères)
        $sales = DB::table('sales')->get();

        foreach ($sales as $sale) {
            $newSaleNumber = $sale->sale_number;

            // Vérifier si c'est le nouveau format (YYMMDDXXX - 9 caractères)
            if (preg_match('/^(\d{6})(\d{3})$/', $newSaleNumber, $matches)) {
                $datePart = $matches[1]; // YYMMDD
                $numberPart = $matches[2]; // XXX

                // Convertir XXX en XX (enlever le zéro de devant si c'est 0XX)
                $oldNumber = ltrim($numberPart, '0');
                if (empty($oldNumber)) {
                    $oldNumber = '0';
                }
                $oldNumber = str_pad($oldNumber, 2, '0', STR_PAD_LEFT);

                $oldSaleNumber = $datePart . $oldNumber;

                // Mettre à jour le numéro de vente
                DB::table('sales')
                    ->where('id', $sale->id)
                    ->update(['sale_number' => $oldSaleNumber]);
            }
        }
    }
};
