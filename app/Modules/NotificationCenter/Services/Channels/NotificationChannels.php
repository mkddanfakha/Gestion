<?php

namespace App\Modules\NotificationCenter\Services\Channels;

use App\Modules\NotificationCenter\Contracts\NotificationChannelInterface;
use App\Modules\NotificationCenter\Services\NotificationSettingsService;

/** Canal in-app (comportement actuel — toujours actif si configuré). */
class AppNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        protected NotificationSettingsService $settings,
    ) {}

    public function name(): string
    {
        return 'app';
    }

    public function isEnabled(string $type): bool
    {
        return $this->settings->isChannelEnabled($type, 'app');
    }

    public function send(int $userId, array $payload): void
    {
        // La persistance + broadcast Pusher est gérée par NotificationService.
    }
}

/** Stubs prêts pour extension future. */
abstract class StubNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        protected NotificationSettingsService $settings,
    ) {}

    abstract public function name(): string;

    public function isEnabled(string $type): bool
    {
        return $this->settings->isChannelEnabled($type, $this->name());
    }

    public function send(int $userId, array $payload): void
    {
        // Non implémenté — prêt pour intégration future.
    }
}

class EmailNotificationChannel extends StubNotificationChannel
{
    public function name(): string
    {
        return 'email';
    }
}

class SmsNotificationChannel extends StubNotificationChannel
{
    public function name(): string
    {
        return 'sms';
    }
}

class WhatsAppNotificationChannel extends StubNotificationChannel
{
    public function name(): string
    {
        return 'whatsapp';
    }
}

class PushNotificationChannel extends StubNotificationChannel
{
    public function name(): string
    {
        return 'push';
    }
}
