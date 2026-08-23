<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Garantit qu'il reste toujours au moins un administrateur fonctionnel.
 *
 * Administrateur fonctionnel = role admin + is_active = true.
 * Contrainte métier (pas une permission RBAC).
 */
final class AdminProtectionService
{
    public const MESSAGE_CANNOT_DEMOTE = 'Impossible de retirer le rôle administrateur au dernier administrateur du système.';

    public const MESSAGE_CANNOT_DEACTIVATE = 'Impossible de désactiver le dernier administrateur du système.';

    public const MESSAGE_CANNOT_DELETE = 'Impossible de supprimer le dernier administrateur du système.';

    /**
     * Nombre d'administrateurs fonctionnels (actifs).
     */
    public function countActiveAdmins(): int
    {
        return $this->activeAdminsQuery()->count();
    }

    /**
     * Indique si l'utilisateur est le seul administrateur fonctionnel.
     */
    public function isLastAdmin(User $user): bool
    {
        if (! $this->isFunctionalAdmin($user)) {
            return false;
        }

        return $this->countActiveAdmins() <= 1;
    }

    /**
     * Peut-on retirer le rôle admin (démotion) à cet utilisateur ?
     */
    public function canChangeRole(User $user, string $newRole): bool
    {
        if ($user->role !== User::ROLE_ADMIN) {
            return true;
        }

        if ($newRole === User::ROLE_ADMIN) {
            return true;
        }

        // Démotion d'un admin inactif : n'affecte pas les admins fonctionnels.
        if (! $user->is_active) {
            return true;
        }

        return ! $this->isLastAdmin($user);
    }

    /**
     * Peut-on désactiver cet utilisateur ?
     */
    public function canDeactivate(User $user, bool $willBeActive): bool
    {
        if ($willBeActive) {
            return true;
        }

        if (! $this->isFunctionalAdmin($user)) {
            return true;
        }

        return ! $this->isLastAdmin($user);
    }

    /**
     * Peut-on supprimer cet utilisateur sans laisser le système sans admin ?
     */
    public function canRemoveAdmin(User $user): bool
    {
        if (! $this->isFunctionalAdmin($user)) {
            return true;
        }

        return ! $this->isLastAdmin($user);
    }

    public function assertCanChangeRole(User $user, string $newRole): void
    {
        if (! $this->canChangeRole($user, $newRole)) {
            abort(403, self::MESSAGE_CANNOT_DEMOTE);
        }
    }

    public function assertCanDeactivate(User $user, bool $willBeActive): void
    {
        if (! $this->canDeactivate($user, $willBeActive)) {
            abort(403, self::MESSAGE_CANNOT_DEACTIVATE);
        }
    }

    public function assertCanRemoveAdmin(User $user): void
    {
        if (! $this->canRemoveAdmin($user)) {
            abort(403, self::MESSAGE_CANNOT_DELETE);
        }
    }

    /**
     * Variante pour contrôleurs : exception catchable hors transaction.
     */
    public function guardChangeRole(User $user, string $newRole): void
    {
        if (! $this->canChangeRole($user, $newRole)) {
            throw new LastAdminProtectionException('demote', $user, self::MESSAGE_CANNOT_DEMOTE);
        }
    }

    public function guardDeactivate(User $user, bool $willBeActive): void
    {
        if (! $this->canDeactivate($user, $willBeActive)) {
            throw new LastAdminProtectionException('deactivate', $user, self::MESSAGE_CANNOT_DEACTIVATE);
        }
    }

    public function guardRemoveAdmin(User $user): void
    {
        if (! $this->canRemoveAdmin($user)) {
            throw new LastAdminProtectionException('delete', $user, self::MESSAGE_CANNOT_DELETE);
        }
    }

    /**
     * Exécute une mutation protégée sous verrouillage des admins actifs.
     *
     * @template T
     *
     * @param  callable(User): T  $callback
     * @return T
     */
    public function withAdminLock(User $user, callable $callback): mixed
    {
        return DB::transaction(function () use ($user, $callback) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->activeAdminsQuery()->lockForUpdate()->get();

            return $callback($lockedUser);
        });
    }

    private function isFunctionalAdmin(User $user): bool
    {
        return $user->role === User::ROLE_ADMIN && (bool) $user->is_active;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function activeAdminsQuery()
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true);
    }
}
