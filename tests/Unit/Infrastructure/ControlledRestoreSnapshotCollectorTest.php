<?php

use App\Database\PrivilegedCredentialLoader;
use App\Database\RestoreDatabaseTargetResolver;
use App\Services\Restore\ControlledRestoreSnapshotCollector;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('database-safety.protected_databases', ['gestion']);
    Config::set('database-safety.restore_allowed_databases', ['gestion_recovery', 'gestion_test']);
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'damo5182_gestion_recovery',
        'gestion_test' => 'damo5182_gestion_test',
    ]);
    Config::set('database.connections.mysql.database', 'damo5182_niane');
    Config::set('database.connections.mysql.driver', 'sqlite');
});

test('application snapshot uses runtime connection config physical database name', function () {
    Config::set('database.connections.mysql.driver', 'sqlite');
    Config::set('database.connections.mysql.database', 'damo5182_niane');

    $collector = new ControlledRestoreSnapshotCollector;
    $snapshot = $collector->collectApplicationDatabaseSnapshot();

    expect($snapshot['logical_database'])->toBe('gestion');
    expect($snapshot['physical_database'])->toBe('damo5182_niane');
    expect($snapshot['connection_mode'])->toBe('application');
});

test('restore target snapshot fails explicitly when database map is missing', function () {
    Config::set('database-safety.restore_database_map', []);

    $collector = new ControlledRestoreSnapshotCollector;
    $snapshot = $collector->collectRestoreTargetDatabaseSnapshot('gestion_recovery');

    expect($snapshot['status'])->toBe('FAIL');
    expect($snapshot['connection_available'])->toBeFalse();
    expect($snapshot['detail'])->toContain('DB_RESTORE_DATABASE_MAP');
});

test('restore target snapshot fails when restore credentials are unavailable', function () {
    $loader = Mockery::mock(PrivilegedCredentialLoader::class);
    $loader->shouldReceive('loadRestoreCredentials')
        ->andThrow(new RuntimeException('restore credential file missing'));

    $collector = new ControlledRestoreSnapshotCollector(new RestoreDatabaseTargetResolver, $loader);
    $snapshot = $collector->collectRestoreTargetDatabaseSnapshot('gestion_recovery');

    expect($snapshot['status'])->toBe('FAIL');
    expect($snapshot['connection_available'])->toBeFalse();
    expect($snapshot['schema_fingerprint'])->toBeNull();
});

test('restore target snapshot uses dedicated restore connection and resolved physical database', function () {
    $trackingPdo = new class extends PDO
    {
        public int $execCalls = 0;

        public function __construct()
        {
            parent::__construct('sqlite::memory:');
        }

        public function exec(string $statement): int|false
        {
            $this->execCalls++;

            throw new RuntimeException('WRITE BLOCKED during snapshot');
        }
    };

    $loader = Mockery::mock(PrivilegedCredentialLoader::class);
    $loader->shouldReceive('loadRestoreCredentials')->andReturn([
        'username' => 'gestion_restore',
        'password' => 'secret',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'gestion_recovery',
        'source' => 'test.local',
    ]);

    $collector = new class(new RestoreDatabaseTargetResolver, $loader, $trackingPdo) extends ControlledRestoreSnapshotCollector
    {
        public function __construct(
            RestoreDatabaseTargetResolver $resolver,
            PrivilegedCredentialLoader $loader,
            private PDO $pdo,
        ) {
            parent::__construct($resolver, $loader);
        }

        protected function openRestoreReadOnlyPdo(): PDO
        {
            return $this->pdo;
        }
    };

    $snapshot = $collector->collectRestoreTargetDatabaseSnapshot('gestion_recovery');

    expect($trackingPdo->execCalls)->toBe(0);
    expect($snapshot['connection_mode'])->toBe('restore');
    expect($snapshot['logical_database'])->toBe('gestion_recovery');
    expect($snapshot['physical_database'])->toBe('damo5182_gestion_recovery');
});

test('restore target snapshot refuses protected database target', function () {
    $collector = new ControlledRestoreSnapshotCollector;
    $snapshot = $collector->collectRestoreTargetDatabaseSnapshot('gestion');

    expect($snapshot['status'])->toBe('FAIL');
    expect($snapshot['connection_available'])->toBeFalse();
});

test('compareSnapshots remains compatible with logical database field', function () {
    $collector = new ControlledRestoreSnapshotCollector;

    $before = [
        'database' => 'gestion_recovery',
        'schema_fingerprint' => 'abc',
        'row_counts' => ['users' => 1],
    ];
    $after = [
        'database' => 'gestion_recovery',
        'schema_fingerprint' => 'abc',
        'row_counts' => ['users' => 1],
    ];

    expect($collector->compareSnapshots($before, $after)['status'])->toBe('PASS');
});

test('ControlledRestoreVerificationService pre protocol never executes restore', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'gestion_recovery',
        'gestion_test' => 'gestion_test',
    ]);

    $report = app(\App\Services\Restore\ControlledRestoreVerificationService::class)
        ->runPreRestoreProtocol(null, 'gestion_recovery');

    expect($report['restore_executed'])->toBeFalse();
    expect($report['dry_run'])->toBeTrue();
});
