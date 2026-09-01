<?php

namespace App\Database;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs privileged Artisan commands in an isolated subprocess with process-only credentials.
 * Secrets are passed via subprocess environment only — never CLI arguments.
 */
class PrivilegedProcessRunner
{
    public const SUBPROCESS_MARKER = 'MKDPRO_PRIVILEGED_SUBPROCESS';

    public const SUBPROCESS_OPERATION_BACKUP = 'backup';

    public const SUBPROCESS_OPERATION_RESTORE = 'restore';

    public function __construct(
        private PrivilegedCredentialLoader $credentialLoader,
    ) {}

    /**
     * Spawn subprocess: php artisan backup:run [--only-db]
     */
    public function runBackupRun(bool $onlyDb = false, ?array $credentials = null): PrivilegedProcessResult
    {
        $credentials ??= $this->credentialLoader->loadBackupCredentials();

        $command = $this->buildArtisanCommand('backup:run', $onlyDb ? ['--only-db' => true] : []);
        $environment = $this->buildBackupSubprocessEnvironment($credentials);

        $this->assertCommandLineContainsNoSecret($command, $credentials['password']);

        $process = new Process(
            $command,
            base_path(),
            $environment,
            null,
            null,
        );

        $process->run();

        $exitCode = $process->getExitCode();
        $secret = $credentials['password'];

        return new PrivilegedProcessResult(
            is_int($exitCode) ? $exitCode : 1,
            PrivilegedCredentialLoader::sanitizeForLog($process->getOutput(), $secret),
            PrivilegedCredentialLoader::sanitizeForLog($process->getErrorOutput(), $secret),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function buildArtisanCommand(string $artisanCommand, array $options = []): array
    {
        $binary = defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== ''
            ? PHP_BINARY
            : 'php';

        $command = [$binary, base_path('artisan'), $artisanCommand];

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $command[] = (string) $value;

                continue;
            }

            if ($value === false || $value === null) {
                continue;
            }

            if ($value === true) {
                $command[] = $key;

                continue;
            }

            $command[] = $key;
            $command[] = (string) $value;
        }

        return $command;
    }

    /**
     * @param  array{username: string, password: string, host: string, port: ?string, database: string}  $credentials
     * @return array<string, string>
     */
    public function buildBackupSubprocessEnvironment(array $credentials): array
    {
        $environment = $this->inheritProcessEnvironment();

        $overrides = [
            self::SUBPROCESS_MARKER => self::SUBPROCESS_OPERATION_BACKUP,
            'DB_CONNECTION' => 'mysql',
            'DB_USERNAME' => $credentials['username'],
            'DB_PASSWORD' => $credentials['password'],
            'DB_DATABASE' => $credentials['database'],
            'DB_HOST' => $credentials['host'],
            'CACHE_STORE' => 'file',
            'BACKUP_LOCK_CACHE_STORE' => 'file',
        ];

        if ($credentials['port'] !== null) {
            $overrides['DB_PORT'] = $credentials['port'];
        }

        return array_merge($environment, $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function inheritProcessEnvironment(): array
    {
        $environment = [];

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $environment[$key] = (string) $value;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $environment[$key] ??= (string) $value;
        }

        return $environment;
    }

    /**
     * @param  list<string>  $command
     */
    private function assertCommandLineContainsNoSecret(array $command, string $secret): void
    {
        if ($secret === '') {
            return;
        }

        $joined = implode(' ', $command);

        if (str_contains($joined, $secret)) {
            throw new RuntimeException(
                'DATABASE SAFETY BLOCK: refused to launch subprocess — secret would appear in CLI arguments.',
            );
        }
    }
}
