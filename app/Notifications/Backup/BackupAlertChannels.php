<?php

namespace App\Notifications\Backup;

/**
 * PRE-PROD 10.1 — Backup alert channels (no secrets in code).
 *
 * Enable only when BACKUP_ALERT_MAIL_ENABLED=true and BACKUP_ALERT_EMAIL is set.
 * Never logs credentials.
 */
final class BackupAlertChannels
{
    /**
     * @return list<string>
     */
    public static function mailWhenConfigured(): array
    {
        if (! filter_var(env('BACKUP_ALERT_MAIL_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }

        $to = trim((string) env('BACKUP_ALERT_EMAIL', ''));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        return ['mail'];
    }

    public static function isMailAlertConfigured(): bool
    {
        return self::mailWhenConfigured() !== [];
    }
}
