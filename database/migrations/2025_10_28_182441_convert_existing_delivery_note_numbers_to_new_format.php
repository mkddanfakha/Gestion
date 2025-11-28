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
        $deliveryNotes = \DB::table('delivery_notes')->get();

        foreach ($deliveryNotes as $dn) {
            $oldNumber = $dn->delivery_number;

            // Ignorer les numéros déjà au nouveau format (BLYYMMDDXXX)
            if (preg_match('/^BL\d{8}\d{3}$/', $oldNumber)) {
                continue;
            }

            // Extraire la date de création
            $createdDate = \Carbon\Carbon::parse($dn->created_at);
            $datePrefix = $createdDate->format('ymd'); // Format: YYMMDD

            // Trouver le prochain numéro séquentiel pour cette date
            $lastNumber = \DB::table('delivery_notes')
                ->where('delivery_number', 'like', 'BL' . $datePrefix . '%')
                ->where('id', '!=', $dn->id)
                ->orderBy('delivery_number', 'desc')
                ->value('delivery_number');

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

            // Générer le nouveau numéro au format BLYYMMDDXXX
            $newNumber = 'BL' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

            // Vérifier l'unicité
            $attempt = 0;
            while (\DB::table('delivery_notes')->where('delivery_number', $newNumber)->exists() && $attempt < 1000) {
                $attempt++;
                $nextNumber++;
                if ($nextNumber > 999) {
                    $nextNumber = 1;
                }
                $newNumber = 'BL' . $datePrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
            }

            // Mettre à jour le numéro
            \DB::table('delivery_notes')
                ->where('id', $dn->id)
                ->update(['delivery_number' => $newNumber]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cette opération est irréversible intentionnellement
    }
};
