<?php

namespace App\Modules\NotificationCenter\Contracts;

/**
 * Canal de notification — abstraction pour App, Email, SMS, WhatsApp, Push.
 */
interface NotificationChannelInterface
{
    public function name(): string;

    public function isEnabled(string $type): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(int $userId, array $payload): void;
}
