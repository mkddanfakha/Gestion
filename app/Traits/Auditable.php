<?php

namespace App\Traits;

use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Trait optionnel pour auditer automatiquement un modèle Eloquent.
 *
 * Définir sur le modèle :
 * protected static string $auditModule = 'Produit';
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            if (!static::auditModule()) {
                return;
            }

            ActivityLogger::logCreate(static::auditModule(), $model);
        });

        static::updated(function ($model) {
            if (!static::auditModule()) {
                return;
            }

            ActivityLogger::logUpdate(static::auditModule(), $model);
        });

        static::deleted(function ($model) {
            if (!static::auditModule()) {
                return;
            }

            ActivityLogger::logDelete(static::auditModule(), $model);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function ($model) {
                if (!static::auditModule()) {
                    return;
                }

                ActivityLogger::logRestore(static::auditModule(), $model);
            });
        }
    }

    protected static function auditModule(): ?string
    {
        return property_exists(static::class, 'auditModule')
            ? static::$auditModule
            : null;
    }
}
