<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Send the email verification notification.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyEmailNotification());
    }

    /**
     * Vérifier si l'utilisateur est administrateur
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Vérifier si l'utilisateur a un rôle spécifique
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Constantes pour les rôles
     */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER = 'user';
    public const ROLE_VENDEUR = 'vendeur';
    public const ROLE_GESTIONNAIRE = 'gestionnaire';
    
    /**
     * Vérifier si l'utilisateur est vendeur
     */
    public function isVendeur(): bool
    {
        return $this->role === 'vendeur';
    }
    
    /**
     * Vérifier si l'utilisateur est gestionnaire
     */
    public function isGestionnaire(): bool
    {
        return $this->role === 'gestionnaire';
    }

    /**
     * Les permissions de l'utilisateur
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withTimestamps();
    }

    /**
     * Vérifier si l'utilisateur a une permission spécifique
     */
    public function hasPermission(string $resource, string $action): bool
    {
        // Les administrateurs ont toutes les permissions
        if ($this->isAdmin()) {
            return true;
        }

        $permissionName = Permission::generateName($resource, $action);
        
        return $this->permissions()
            ->where('name', $permissionName)
            ->exists();
    }

    /**
     * Vérifier si l'utilisateur a une permission par son nom
     */
    public function hasPermissionByName(string $permissionName): bool
    {
        // Les administrateurs ont toutes les permissions
        if ($this->isAdmin()) {
            return true;
        }

        return $this->permissions()
            ->where('name', $permissionName)
            ->exists();
    }

    /**
     * Obtenir toutes les permissions de l'utilisateur sous forme de tableau
     */
    public function getPermissionsArray(): array
    {
        return $this->permissions()
            ->pluck('name')
            ->toArray();
    }
}
