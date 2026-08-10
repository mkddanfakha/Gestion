<?php

namespace App\Modules\NotificationCenter\Services\Realtime;

use App\Modules\NotificationCenter\Contracts\RealtimeProviderInterface;
use App\Modules\NotificationCenter\Events\NotificationSent;
use App\Modules\NotificationCenter\Support\NotificationLogger;
use Throwable;

class PusherRealtimeProvider implements RealtimeProviderInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $payload, int $userId): void
    {
        try {
            event(new NotificationSent($payload, $userId));
        } catch (Throwable $e) {
            NotificationLogger::pusherError('Impossible d\'émettre l\'événement NotificationSent', [
                'user_id' => $userId,
                'type' => $payload['type'] ?? null,
            ], $e);

            throw $e;
        }
    }
}
