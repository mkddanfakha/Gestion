<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application runtime MySQL account
    |--------------------------------------------------------------------------
    |
    | PRE-PROD 9.1–9.3 — least-privilege Laravel runtime. Never grant DDL.
    |
    */

    'runtime_account' => env('DB_APP_USERNAME', 'gestion_app'),

    /*
    |--------------------------------------------------------------------------
    | Runtime allowed pairs (PRE-PROD 9.5.2E)
    |--------------------------------------------------------------------------
    |
    | Fail-closed: Laravel mysql runtime may only boot for an exact
    | (database, username) pair. Never authorize by username alone.
    | DB_APP_USERNAME remains a legacy label for status / privileged messaging;
    | it does NOT grant a global username allow-list.
    |
    */

    'runtime_allowed_pairs' =>  env('DB_RUNTIME_ALLOWED_PAIRS', ''),

    /*
    |--------------------------------------------------------------------------
    | MySQL migrate allowed pairs (PRE-PROD 9.5.4)
    |--------------------------------------------------------------------------
    |
    | Fail-closed: php artisan migrate on mysql is allowed only for an exact
    | (database, username) pair. Username-only allow-lists are forbidden.
    | Backup / restore keep dedicated accounts and are not listed here.
    |
    */

    'migration_allowed_pairs' =>  env('DB_MIGRATION_ALLOWED_PAIRS', ''),
    'runtime_hosts' => [
        'localhost',
        '127.0.0.1',
    ],

    'runtime_databases' => [
        'gestion',
    ],

    'runtime_privileges' => [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
    ],

    'forbidden_runtime_privileges' => [
        'CREATE',
        'DROP',
        'ALTER',
        'INDEX',
        'CREATE USER',
        'GRANT OPTION',
        'SUPER',
        'FILE',
        'RELOAD',
        'SHUTDOWN',
        'PROCESS',
        'EVENT',
        'TRIGGER',
        'CREATE ROUTINE',
        'ALTER ROUTINE',
        'CREATE DATABASE',
        'DROP DATABASE',
        'LOCK TABLES',
        'REFERENCES',
        'TRUNCATE',
    ],

    /*
    |--------------------------------------------------------------------------
    | Privileged accounts (policy only until human CREATE approval)
    |--------------------------------------------------------------------------
    | Normally, backup credentials must not be wired to Laravel DB_USERNAME.
    | A single-account mode is allowed only when the hosting provider forces
    |runtime and backup to use the same database account.
    | Credentials must stay out of Git.
    |
    */
    'backup_account' => env('DB_BACKUP_USERNAME', 'gestion_backup'),
    'single_account_mode' => env('DB_SINGLE_ACCOUNT_MODE', false),
    'backup_account_created' => true,
    'backup_hosts' => ['localhost', '127.0.0.1'],
    'backup_databases' => ['gestion'],
    'backup_privileges' => [
        'SELECT',
        'SHOW VIEW',
        'TRIGGER',
        'LOCK TABLES',
    ],

    'restore_account' => 'gestion_restore',
    'restore_account_created' => true,
    'restore_hosts' => ['localhost', '127.0.0.1'],
    'restore_databases' => [
        'gestion_recovery',
        'gestion_test',
    ],
    'restore_privileges' => [
        'CREATE',
        'DROP',
        'ALTER',
        'INDEX',
        'REFERENCES',
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE DATABASE',
        'DROP DATABASE',
    ],

    'migration_account' => 'gestion_migration',
    'migration_account_created' => true,
    'migration_hosts' => ['localhost', '127.0.0.1'],
    'migration_databases' => ['gestion'],
    /*
    | PRE-PROD 9.3.3 confirmed: GRANT DROP ON gestion.* allows DROP DATABASE gestion.
    | PRE-PROD 9.4: DROP revoked. DROP TABLE migrations require DBA (root) procedure.
    */
    'migration_privileges' => [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'ALTER',
        'INDEX',
        'REFERENCES',
    ],
    'migration_forbidden_privileges' => [
        'DROP',
        'DROP DATABASE',
        'CREATE USER',
        'GRANT OPTION',
        'SUPER',
        'FILE',
    ],

    /*
    | DBA / emergency CLI only — never Laravel runtime.
    */
    'dba_account' => 'root',

    'env_cutover_executed' => true,

];
