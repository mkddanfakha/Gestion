<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $purchaseOrders = \DB::table('purchase_orders')->get();

        foreach ($purchaseOrders as $po) {
            $oldNumber = $po->po_number;
            
            // Ignorer les numéros déjà au nouveau format (BCYYMMDDXXX)
            if (preg_match('/^BC\d{8}\d{3}$/', $oldNumber)) {
                continue;
            }
            
            // Extraire la date de création
            $createdDate = \Carbon\Carbon::parse($po->created_at);
            $datePrefix = $createdDate->format('ymd'); // Format: YYMMDD
            
            // Trouver le prochain numéro séquentiel pour cette date
            $lastNumber = \DB::table('purchase_orders')
                ->where('po_number', 'like', 'BC' . $datePrefix . '%')
                ->where('id', '!=', $po->id)
                ->orderBy('po_number', 'desc')
                ->value('po_number');
            
            if ($lastNumber) {
                // Extraire le numéro séquentiel (3 derniers caractères)
                $lastSeqNumber = (int) substr($lastNumber, -3);
                $nextNumber = $lastSeqNumber + 1;
            } else {
                $nextNumber = 1;
            }
            
            // Limiter à 999
            if ($nextNumber > 999) {
                $nextNumber = 1;
            }
            
            // Générer le nouveau numéro au format BCYYMMDDXXX
            $newNumber = 'BC' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
            
            // Vérifier l'unicité
            $attempt = 0;
            while (\DB::table('purchase_orders')->where('po_number', $newNumber)->exists() && $attempt < 1000) {
                $attempt++;
                $nextNumber++;
                if ($nextNumber > 999) {
                    $nextNumber = 1;
                }
                $newNumber = 'BC' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
            }
            
            // Mettre à jour le numéro
            \DB::table('purchase_orders')
                ->where('id', $po->id)
                ->update(['po_number' => $newNumber]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Ne pas restaurer les anciens numéros pour éviter les conflits
        // Cette opération est irréversible intentionnellement
    }
};
