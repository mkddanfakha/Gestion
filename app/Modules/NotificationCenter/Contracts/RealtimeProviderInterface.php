<?php

namespace App\Modules\NotificationCenter\Contracts;

interface RealtimeProviderInterface
{
    /**
     * Diffuse une notification vers un utilisateur en temps réel.
     *
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $payload, int $userId): void;
}
