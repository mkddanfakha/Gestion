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
        // Convertir les anciens numéros de vente au nouveau format (8 caractères)
        $sales = DB::table('sales')->get();
        
        foreach ($sales as $sale) {
            $oldSaleNumber = $sale->sale_number;
            
            // Vérifier si c'est l'ancien format (SALE-YYYYMMDD-XXXX)
            if (preg_match('/^SALE-(\d{8})-(\d{4})$/', $oldSaleNumber, $matches)) {
                $datePart = $matches[1]; // YYYYMMDD
                $numberPart = $matches[2]; // XXXX
                
                // Convertir YYYYMMDD en YYMMDD
                $year = substr($datePart, 2, 2); // Prendre les 2 derniers chiffres de l'année
                $month = substr($datePart, 4, 2);
                $day = substr($datePart, 6, 2);
                
                // Prendre les 2 derniers chiffres du numéro
                $newNumber = substr($numberPart, -2);
                
                $newSaleNumber = $year . $month . $day . $newNumber;
                
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
        // Cette migration ne peut pas être inversée facilement
        // car nous perdons l'information sur le format original
        throw new Exception('Cette migration ne peut pas être inversée.');
    }
};
