<?php

namespace App\Services\Restore;

use App\Database\PrivilegedCredentialLoader;

/**
 * Redact restore/backup secrets from subprocess output before logs or user-facing hints.
 */
final class RestoreJobOutputSanitizer
{
    public function sanitize(string $output): string
    {
        $text = $output;

        foreach ($this->resolvePasswordsForRedaction() as $secret) {
            if ($secret !== '') {
                $text = PrivilegedCredentialLoader::sanitizeForLog($text, $secret);
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function resolvePasswordsForRedaction(): array
    {
        $passwords = [];
        $loader = app(PrivilegedCredentialLoader::class);

        try {
            $passwords[] = $loader->loadRestoreCredentials()['password'] ?? '';
        } catch (\Throwable) {
            // ignore
        }

        try {
            $passwords[] = $loader->loadBackupCredentials()['password'] ?? '';
        } catch (\Throwable) {
            // ignore
        }

        return $passwords;
    }
}
