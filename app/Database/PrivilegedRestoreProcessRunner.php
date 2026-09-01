<?php

namespace App\Database;

use App\Services\Backup\BackupPathGuard;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs db:restore in an isolated subprocess with gestion_restore credentials.
 * Target allow-list is enforced before launch and again inside the child process.
 */
class PrivilegedRestoreProcessRunner
{
    public const DEFAULT_TIMEOUT_SECONDS = 1800;

    public function __construct(
        private PrivilegedCredentialLoader $credentialLoader,
        private PrivilegedProcessRunner $processRunner,
    ) {}

    /**
     * Fail-closed target validation (defense in depth before subprocess).
     */
    public function assertRestoreTargetAllowed(string $target): string
    {
        return DatabaseSafetyGuard::assertExplicitRestoreTarget($target);
    }

    public function runRestore(
        string $backupFileName,
        string $target,
        string $confirmationPhrase,
        ?int $timeoutSeconds = null,
    ): PrivilegedProcessResult {
        $validatedTarget = $this->assertRestoreTargetAllowed($target);
        $safeBackupName = BackupPathGuard::sanitizeBackupFileName($backupFileName);
        $credentials = $this->credentialLoader->loadRestoreCredentials();

        $command = $this->processRunner->buildArtisanCommand('db:restore', [
            '--backup' => $safeBackupName,
            '--target' => $validatedTarget,
            '--confirmation' => $confirmationPhrase,
        ]);

        $environment = $this->buildRestoreSubprocessEnvironment($credentials, $validatedTarget);
        $this->assertCommandLineContainsNoSecret($command, $credentials['password']);

        $process = new Process(
            $command,
            base_path(),
            $environment,
            null,
            $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS,
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
     * @param  array{username: string, password: string, host: string, port: ?string, database: string, source: string}  $credentials
     * @return array<string, string>
     */
    public function buildRestoreSubprocessEnvironment(array $credentials, string $validatedTarget): array
    {
        $environment = $this->inheritProcessEnvironment();

        $overrides = [
            PrivilegedProcessRunner::SUBPROCESS_MARKER => PrivilegedProcessRunner::SUBPROCESS_OPERATION_RESTORE,
            'DB_CONNECTION' => 'mysql',
            'DB_USERNAME' => $credentials['username'],
            'DB_PASSWORD' => $credentials['password'],
            'DB_DATABASE' => $validatedTarget,
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
                'DATABASE SAFETY BLOCK: refused to launch restore subprocess — secret would appear in CLI arguments.',
            );
        }
    }
}
