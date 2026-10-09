<?php

use App\Database\RestoreDatabaseTargetResolver;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('database-safety.protected_databases', ['gestion']);
    Config::set('database-safety.restore_allowed_databases', ['gestion_recovery', 'gestion_test']);
    Config::set('database.connections.mysql.database', 'damo5182_niane');
});

function o2switchRestoreMap(): array
{
    return [
        'gestion_recovery' => 'damo5182_gestion_recovery',
        'gestion_test' => 'damo5182_gestion_test',
    ];
}

test('RestoreDatabaseTargetResolver resolves gestion_recovery to damo5182_gestion_recovery', function () {
    Config::set('database-safety.restore_database_map', o2switchRestoreMap());

    $resolver = new RestoreDatabaseTargetResolver;

    expect($resolver->resolvePhysicalName('gestion_recovery'))->toBe('damo5182_gestion_recovery');
});

test('RestoreDatabaseTargetResolver resolves gestion_test to damo5182_gestion_test', function () {
    Config::set('database-safety.restore_database_map', o2switchRestoreMap());

    $resolver = new RestoreDatabaseTargetResolver;

    expect($resolver->resolvePhysicalName('gestion_test'))->toBe('damo5182_gestion_test');
});

test('RestoreDatabaseTargetResolver refuses mapping to active physical database damo5182_niane', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'damo5182_niane',
    ]);

    $resolver = new RestoreDatabaseTargetResolver;

    expect(fn () => $resolver->resolvePhysicalName('gestion_recovery'))
        ->toThrow(RuntimeException::class, 'active application database');
});

test('RestoreDatabaseTargetResolver refuses mapping to active physical database case insensitively', function () {
    Config::set('database.connections.mysql.database', 'DAMO5182_NIANE');
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'damo5182_niane',
    ]);

    $resolver = new RestoreDatabaseTargetResolver;

    expect(fn () => $resolver->resolvePhysicalName('gestion_recovery'))
        ->toThrow(RuntimeException::class, 'active application database');
});

test('RestoreDatabaseTargetResolver refuses mapping to protected database name gestion', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'gestion',
    ]);

    $resolver = new RestoreDatabaseTargetResolver;

    expect(fn () => $resolver->resolvePhysicalName('gestion_recovery'))
        ->toThrow(RuntimeException::class, 'protected database name');
});

test('RestoreDatabaseTargetResolver refuses duplicate physical mappings', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'damo5182_gestion_recovery',
        'gestion_test' => 'damo5182_gestion_recovery',
    ]);

    $resolver = new RestoreDatabaseTargetResolver;

    expect(fn () => $resolver->resolvePhysicalName('gestion_recovery'))
        ->toThrow(RuntimeException::class, 'duplicate physical database');
});

test('RestoreDatabaseTargetResolver parseEnvMap accepts colon separated o2switch pairs', function () {
    $map = RestoreDatabaseTargetResolver::parseEnvMap(
        'gestion_recovery:damo5182_gestion_recovery,gestion_test:damo5182_gestion_test',
    );

    expect($map)->toBe(o2switchRestoreMap());
});

test('RestoreDatabaseTargetResolver parseEnvMap rejects equals sign syntax', function () {
    expect(fn () => RestoreDatabaseTargetResolver::parseEnvMap(
        'gestion_recovery=damo5182_gestion_recovery',
    ))->toThrow(RuntimeException::class, 'colon separator');
});

test('RestoreDatabaseTargetResolver parseEnvMap rejects missing colon', function () {
    expect(fn () => RestoreDatabaseTargetResolver::parseEnvMap(
        'gestion_recovery',
    ))->toThrow(RuntimeException::class, 'invalid DB_RESTORE_DATABASE_MAP entry');
});

test('RestoreDatabaseTargetResolver parseEnvMap rejects incomplete pairs', function () {
    expect(fn () => RestoreDatabaseTargetResolver::parseEnvMap(
        'gestion_recovery:',
    ))->toThrow(RuntimeException::class, 'incomplete DB_RESTORE_DATABASE_MAP pair');
});

test('RestoreDatabaseTargetResolver parseEnvMap rejects duplicate logical keys', function () {
    expect(fn () => RestoreDatabaseTargetResolver::parseEnvMap(
        'gestion_recovery:damo5182_gestion_recovery,gestion_recovery:damo5182_gestion_test',
    ))->toThrow(RuntimeException::class, 'duplicate logical restore target');
});

test('RestoreDatabaseTargetResolver refuses missing map', function () {
    Config::set('database-safety.restore_database_map', []);

    $resolver = new RestoreDatabaseTargetResolver;

    expect(fn () => $resolver->resolvePhysicalName('gestion_recovery'))
        ->toThrow(RuntimeException::class, 'DB_RESTORE_DATABASE_MAP');
});

test('RestoreDatabaseTargetResolver refuses unmapped logical target', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'damo5182_gestion_recovery',
    ]);

    $resolver = new RestoreDatabaseTargetResolver;

    expect(fn () => $resolver->resolvePhysicalName('gestion_test'))
        ->toThrow(RuntimeException::class, 'no physical database mapping');
});

test('RestoreDatabaseTargetResolver refuses protected logical restore target', function () {
    Config::set('database-safety.restore_database_map', o2switchRestoreMap());

    $resolver = new RestoreDatabaseTargetResolver;

    expect(fn () => $resolver->resolvePhysicalName('gestion'))
        ->toThrow(\App\Database\ProtectedDatabaseException::class);
});

test('RestoreDatabaseTargetResolver refuses invalid physical identifier', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'bad-name-with-dash',
    ]);

    $resolver = new RestoreDatabaseTargetResolver;

    expect(fn () => $resolver->resolvePhysicalName('gestion_recovery'))
        ->toThrow(RuntimeException::class, 'invalid');
});

test('bootstrapFromEnv captures invalid equals syntax without throwing', function () {
    $bootstrapped = RestoreDatabaseTargetResolver::bootstrapFromEnv(
        'gestion_recovery=damo5182_gestion_recovery',
    );

    expect($bootstrapped['map'])->toBe([]);
    expect($bootstrapped['error'])->toContain('colon separator');
});

test('bootstrapFromEnv loads valid o2switch map without configuration error', function () {
    $bootstrapped = RestoreDatabaseTargetResolver::bootstrapFromEnv(
        'gestion_recovery:damo5182_gestion_recovery,gestion_test:damo5182_gestion_test',
    );

    expect($bootstrapped['error'])->toBeNull();
    expect($bootstrapped['map'])->toBe(o2switchRestoreMap());
});

test('stored configuration error blocks resolve explicitly and is not treated as missing map', function () {
    Config::set('database-safety.restore_database_map', []);
    Config::set(
        'database-safety.restore_database_map_configuration_error',
        'DATABASE SAFETY: DB_RESTORE_DATABASE_MAP must use logical:physical pairs (colon separator). Equals sign is not allowed.',
    );

    expect(fn () => (new RestoreDatabaseTargetResolver)->resolvePhysicalName('gestion_recovery'))
        ->toThrow(RuntimeException::class, 'colon separator');
});

test('invalid restore map configuration does not block unrelated database-safety settings', function () {
    Config::set(
        'database-safety.restore_database_map_configuration_error',
        'DATABASE SAFETY: invalid DB_RESTORE_DATABASE_MAP entry',
    );

    expect(config('database-safety.protected_databases'))->toContain('gestion');
    expect(config('database-safety.restore_allowed_databases'))->toContain('gestion_recovery');
});

test('MysqlPdoDumpImporter fails before SQL when restore map configuration is invalid', function () {
    Config::set('database-safety.restore_database_map', []);
    Config::set(
        'database-safety.restore_database_map_configuration_error',
        'DATABASE SAFETY: invalid DB_RESTORE_DATABASE_MAP entry (expected logical:physical): broken',
    );

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-restore-map-'.uniqid().'.sql';
    file_put_contents($tmp, "CREATE TABLE t (id int);\n");

    expect(fn () => (new \App\Services\Restore\MysqlPdoDumpImporter)->import($tmp, 'gestion_recovery'))
        ->toThrow(RuntimeException::class, 'logical:physical');

    unlink($tmp);
});

test('resolve refusal does not open restore snapshot SQL path', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'damo5182_niane',
    ]);

    $loader = Mockery::mock(\App\Database\PrivilegedCredentialLoader::class);
    $loader->shouldNotReceive('loadRestoreCredentials');

    $collector = new \App\Services\Restore\ControlledRestoreSnapshotCollector(
        new RestoreDatabaseTargetResolver,
        $loader,
    );

    $snapshot = $collector->collectRestoreTargetDatabaseSnapshot('gestion_recovery');

    expect($snapshot['status'])->toBe('FAIL');
    expect($snapshot['connection_available'])->toBeFalse();
});
