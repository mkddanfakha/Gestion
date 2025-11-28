<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'resource',
        'action',
        'description',
    ];

    /**
     * Les utilisateurs qui ont cette permission
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions')
            ->withTimestamps();
    }

    /**
     * Générer le nom de la permission à partir de la ressource et de l'action
     */
    public static function generateName(string $resource, string $action): string
    {
        return "{$resource}.{$action}";
    }
}
