<?php

namespace App\Modules\NotificationCenter\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class NotificationLogger
{
    public static function info(string $message, array $context = []): void
    {
        Log::channel('notifications')->info($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        Log::channel('notifications')->warning($message, $context);
    }

    public static function error(string $message, array $context = [], ?Throwable $exception = null): void
    {
        if ($exception) {
            $context['exception'] = $exception->getMessage();
            $context['trace'] = $exception->getTraceAsString();
        }

        Log::channel('notifications')->error($message, $context);
    }

    public static function pusherError(string $message, array $context = [], ?Throwable $exception = null): void
    {
        self::error('[pusher] '.$message, $context, $exception);
    }

    public static function apiError(string $message, array $context = [], ?Throwable $exception = null): void
    {
        self::error('[api] '.$message, $context, $exception);
    }

    public static function queueError(string $message, array $context = [], ?Throwable $exception = null): void
    {
        self::error('[queue] '.$message, $context, $exception);
    }
}
