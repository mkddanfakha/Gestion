<?php

namespace App\Auth;

use App\Models\User;

/**
 * Levée lorsqu'une opération violerait la règle du dernier administrateur actif.
 * Permet d'auditer le refus hors transaction (après rollback du withAdminLock).
 */
final class LastAdminProtectionException extends \RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly User $target,
        string $message,
    ) {
        parent::__construct($message);
    }
}
