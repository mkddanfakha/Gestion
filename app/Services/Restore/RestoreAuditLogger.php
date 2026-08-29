<?php

namespace App\Services\Restore;

use Illuminate\Support\Facades\Log;

class RestoreAuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(string $event, array $context = []): void
    {
        unset(
            $context['password'],
            $context['db_password'],
            $context['APP_KEY'],
            $context['secret'],
            $context['token'],
        );

        Log::info('restore.audit', array_merge([
            'event' => $event,
            'type' => 'database',
            'user_id' => auth()->id(),
            'request_id' => request()?->headers->get('X-Request-Id'),
            'at' => now()->toIso8601String(),
        ], $context));
    }
}
