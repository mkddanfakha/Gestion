<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convertir les anciens numéros de vente au nouveau format FAYYMMXXX
        $sales = DB::table('sales')->get();
        
        foreach ($sales as $sale) {
            $oldSaleNumber = $sale->sale_number;
            $newSaleNumber = null;
            
            // Vérifier si c'est l'ancien format (YYMMDDXXX - 9 caractères)
            if (preg_match('/^(\d{2})(\d{2})(\d{2})(\d{3})$/', $oldSaleNumber, $matches)) {
                $year = $matches[1];   // YY
                $month = $matches[2];  // MM
                $day = $matches[3];    // DD (on ignore le jour)
                $number = $matches[4]; // XXX
                
                // Créer le nouveau format: FAYYMMXXX
                $newSaleNumber = 'FA' . $year . $month . $number;
            }
            // Si le numéro commence déjà par FA, le garder tel quel
            elseif (preg_match('/^FA\d{7}$/', $oldSaleNumber)) {
                $newSaleNumber = $oldSaleNumber;
            }
            // Si c'est un autre format, essayer de le convertir
            else {
                // Extraire la date de création de la vente
                $createdAt = $sale->created_at ?? now();
                $year = date('y', strtotime($createdAt));
                $month = date('m', strtotime($createdAt));
                
                // Utiliser l'ID comme numéro séquentiel (modulo 1000 pour rester à 3 chiffres)
                $number = str_pad(($sale->id % 1000), 3, '0', STR_PAD_LEFT);
                
                $newSaleNumber = 'FA' . $year . $month . $number;
            }
            
            // Vérifier que le nouveau numéro n'existe pas déjà
            $exists = DB::table('sales')
                ->where('sale_number', $newSaleNumber)
                ->where('id', '!=', $sale->id)
                ->exists();
            
            if ($exists) {
                // Si le numéro existe déjà, incrémenter le numéro séquentiel
                $basePrefix = substr($newSaleNumber, 0, 6); // FAYYMM
                $baseNumber = (int) substr($newSaleNumber, 6, 3); // XXX
                $counter = $baseNumber + 1;
                
                do {
                    $newSaleNumber = $basePrefix . str_pad($counter, 3, '0', STR_PAD_LEFT);
                    $counter++;
                } while (DB::table('sales')
                    ->where('sale_number', $newSaleNumber)
                    ->where('id', '!=', $sale->id)
                    ->exists() && $counter <= 999);
            }
            
            // Mettre à jour le numéro de vente
            if ($newSaleNumber) {
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
        // Convertir les numéros FAYYMMXXX vers YYMMDDXXX
        $sales = DB::table('sales')->get();
        
        foreach ($sales as $sale) {
            $oldSaleNumber = $sale->sale_number;
            
            // Vérifier si c'est le format FA (FAYYMMXXX)
            if (preg_match('/^FA(\d{2})(\d{2})(\d{3})$/', $oldSaleNumber, $matches)) {
                $year = $matches[1];   // YY
                $month = $matches[2];  // MM
                $number = $matches[3]; // XXX
                
                // Utiliser le jour 01 par défaut pour la conversion
                $day = '01';
                
                // Créer l'ancien format: YYMMDDXXX
                $newSaleNumber = $year . $month . $day . $number;
                
                // Mettre à jour le numéro de vente
                DB::table('sales')
                    ->where('id', $sale->id)
                    ->update(['sale_number' => $newSaleNumber]);
            }
        }
    }
};
