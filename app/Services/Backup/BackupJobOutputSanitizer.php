<?php

namespace App\Services\Backup;

use App\Database\PrivilegedCredentialLoader;

/**
 * Redact secrets from backup:production console output before logging or UI hints.
 */
final class BackupJobOutputSanitizer
{
    public function sanitize(string $output): string
    {
        $secret = $this->resolveBackupPasswordForRedaction();

        $sanitized = PrivilegedCredentialLoader::sanitizeForLog($output, $secret);

        return $this->redactAwsLikeSecrets($sanitized);
    }

    private function resolveBackupPasswordForRedaction(): string
    {
        try {
            $credentials = app(PrivilegedCredentialLoader::class)->loadBackupCredentials();

            return $credentials['password'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function redactAwsLikeSecrets(string $text): string
    {
        $awsSecret = env('AWS_SECRET_ACCESS_KEY');
        if (is_string($awsSecret) && $awsSecret !== '') {
            $text = str_replace($awsSecret, '[REDACTED]', $text);
        }

        return $text;
    }
}
