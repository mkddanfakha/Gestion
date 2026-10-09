<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Protected database names (business / métier)
    |--------------------------------------------------------------------------
    |
    | Exact match only. "gestion_test" is NOT covered by listing "gestion".
    |
    */

    'protected_databases' => array_values(array_filter(array_map(
        static fn (string $name): string => trim($name),
        explode(',', (string) env('DB_PROTECTED_DATABASES', 'gestion')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Databases allowed for application-level DROP/CREATE restore
    |--------------------------------------------------------------------------
    |
    | Restore that uses DROP DATABASE is forbidden on protected databases.
    | It is only allowed against these exact names (fail-closed otherwise).
    |
    */

    'restore_allowed_databases' => array_values(array_filter(array_map(
        static fn (string $name): string => trim($name),
        explode(',', (string) env(
            'DB_RESTORE_ALLOWED_DATABASES',
            'gestion_recovery,gestion_test',
        )),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Logical → physical restore database names (hosting prefixes)
    |--------------------------------------------------------------------------
    |
    | Comma-separated logical:physical pairs (colon only), e.g.
    | gestion_recovery:damo5182_gestion_recovery,gestion_test:damo5182_gestion_test
    |
    | Invalid values are captured in restore_database_map_configuration_error at boot
    | (application stays up; restore/snapshot operations fail explicitly).
    |
    */

    ... (static function (): array {
        $bootstrapped = \App\Database\RestoreDatabaseTargetResolver::bootstrapFromEnv(
            env('DB_RESTORE_DATABASE_MAP'),
        );

        return [
            'restore_database_map' => $bootstrapped['map'],
            'restore_database_map_configuration_error' => $bootstrapped['error'],
        ];
    })(),

    /*
    |--------------------------------------------------------------------------
    | Destructive Artisan commands
    |--------------------------------------------------------------------------
    */

    'destructive_commands' => [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
        'db:seed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore confirmation phrase (backend)
    |--------------------------------------------------------------------------
    */

    'restore_confirmation_phrase' => env('DB_RESTORE_CONFIRMATION_PHRASE', 'RESTORE'),

];
