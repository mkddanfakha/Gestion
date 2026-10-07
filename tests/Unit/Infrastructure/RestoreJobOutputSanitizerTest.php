<?php

use App\Database\PrivilegedCredentialLoader;
use App\Services\Restore\RestoreJobOutputSanitizer;

test('RestoreJobOutputSanitizer redacts loaded restore password from output', function () {
    $secret = 'unit-restore-secret';

    $this->mock(PrivilegedCredentialLoader::class, function ($mock) use ($secret) {
        $mock->shouldReceive('loadRestoreCredentials')->andReturn([
            'username' => 'gestion_restore',
            'password' => $secret,
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'gestion_recovery',
            'source' => '.mysql-gestion-restore.local',
        ]);
        $mock->shouldReceive('loadBackupCredentials')->andThrow(new RuntimeException('skip'));
    });

    $sanitizer = app(RestoreJobOutputSanitizer::class);
    $sanitized = $sanitizer->sanitize('Error: '.$secret);

    expect($sanitized)->not->toContain($secret);
    expect($sanitized)->toContain('[REDACTED]');
});
