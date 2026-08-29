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
    |
    | Never wire these to Laravel DB_USERNAME. Credentials must stay out of Git.
    |
    */

    'backup_account' => 'gestion_backup',
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
