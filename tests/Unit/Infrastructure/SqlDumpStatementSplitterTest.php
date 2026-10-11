<?php

use App\Database\DatabaseAccountGuard;
use App\Database\ProtectedDatabaseException;
use App\Database\RestoreDatabaseTargetResolver;
use App\Services\Restore\MysqlPdoDumpImporter;
use App\Services\Restore\SqlDumpStatementSplitter;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('database-safety.protected_databases', ['gestion', 'damo5182_niane']);
    Config::set('database-safety.restore_allowed_databases', ['gestion_recovery', 'gestion_test']);
    Config::set('database.connections.mysql.database', 'damo5182_gestion_staging');
});

test('SqlDumpStatementSplitter preserves MySQL conditional SET NAMES', function () {
    $splitter = new SqlDumpStatementSplitter;
    $statements = $splitter->split("/*!40101 SET NAMES utf8mb4 */;\n");

    expect($statements)->toHaveCount(1);
    expect($statements[0])->toContain('SET NAMES utf8mb4');
});

test('SqlDumpStatementSplitter preserves MySQL conditional ALTER DISABLE and ENABLE KEYS', function () {
    $splitter = new SqlDumpStatementSplitter;
    $sql = <<<'SQL'
/*!40000 ALTER TABLE `items` DISABLE KEYS */;
INSERT INTO `items` VALUES (1);
/*!40000 ALTER TABLE `items` ENABLE KEYS */;
SQL;

    $statements = $splitter->split($sql);

    expect($statements)->toHaveCount(3);
    expect($statements[0])->toContain('ALTER TABLE `items` DISABLE KEYS');
    expect($statements[1])->toContain('INSERT INTO `items`');
    expect($statements[2])->toContain('ALTER TABLE `items` ENABLE KEYS');
});

test('SqlDumpStatementSplitter preserves MariaDB conditional comments', function () {
    $splitter = new SqlDumpStatementSplitter;
    $sql = <<<'SQL'
/*M!100616 SET NOTE_VERBOSITY=0 */;
/*M!999999\- enable the sandbox mode */;
SQL;

    $statements = $splitter->split($sql);

    expect($statements)->toHaveCount(2);
    expect($statements[0])->toContain('SET NOTE_VERBOSITY=0');
    expect($statements[1])->toContain('enable the sandbox mode');
});

test('SqlDumpStatementSplitter removes ordinary block comments including semicolons', function () {
    $splitter = new SqlDumpStatementSplitter;
    $statements = $splitter->split('SELECT 1; /* ordinary ; comment */ SELECT 2;');

    expect($statements)->toEqual(['SELECT 1', 'SELECT 2']);
});

test('SqlDumpStatementSplitter removes dash and hash line comments', function () {
    $splitter = new SqlDumpStatementSplitter;
    $sql = <<<'SQL'
-- leading dash comment
SELECT 1;
# hash comment
SELECT 2;
SQL;

    $statements = $splitter->split($sql);

    expect($statements)->toEqual(['SELECT 1', 'SELECT 2']);
});

test('SqlDumpStatementSplitter keeps semicolons inside quoted strings', function () {
    $splitter = new SqlDumpStatementSplitter;
    $sql = <<<'SQL'
INSERT INTO `t` VALUES ('a;b', "c;d");
INSERT INTO `t` VALUES ('it''s;ok');
SQL;

    $statements = $splitter->split($sql);

    expect($statements)->toHaveCount(2);
    expect($statements[0])->toContain("'a;b'");
    expect($statements[0])->toContain('"c;d"');
    expect($statements[1])->toContain("it''s;ok");
});

test('SqlDumpStatementSplitter splits multiple consecutive statements', function () {
    $splitter = new SqlDumpStatementSplitter;
    $statements = $splitter->split('CREATE TABLE `a` (id int); CREATE TABLE `b` (id int);');

    expect($statements)->toEqual([
        'CREATE TABLE `a` (id int)',
        'CREATE TABLE `b` (id int)',
    ]);
});

test('SqlDumpStatementSplitter rejects unclosed block comments and strings', function () {
    $splitter = new SqlDumpStatementSplitter;

    expect(fn () => $splitter->split('SELECT 1; /* unclosed'))
        ->toThrow(RuntimeException::class, 'unclosed block comment');

    expect(fn () => $splitter->split("INSERT INTO t VALUES ('open);"))
        ->toThrow(RuntimeException::class, 'unclosed string');
});

test('SqlDumpStatementSplitter rejects DELIMITER directives', function () {
    $splitter = new SqlDumpStatementSplitter;

    expect(fn () => $splitter->split("DELIMITER ;;\nCREATE PROCEDURE p() BEGIN END;;"))
        ->toThrow(RuntimeException::class, 'DELIMITER');
});

test('SqlDumpStatementSplitter shouldSkipStatement ignores USE and LOCK TABLES', function () {
    $splitter = new SqlDumpStatementSplitter;

    expect($splitter->shouldSkipStatement('USE `other_database`'))->toBeTrue();
    expect($splitter->shouldSkipStatement('LOCK TABLES `t` WRITE'))->toBeTrue();
    expect($splitter->shouldSkipStatement('UNLOCK TABLES'))->toBeTrue();
    expect($splitter->shouldSkipStatement('CREATE TABLE `t` (id int)'))->toBeFalse();
});

test('restore refuses protected physical target before any database drop', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'damo5182_niane',
    ]);

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-restore-protected-'.uniqid().'.sql';
    file_put_contents($tmp, "CREATE TABLE t (id int);\n");

    expect(fn () => (new MysqlPdoDumpImporter)->import($tmp, 'gestion_recovery'))
        ->toThrow(RuntimeException::class, 'protected database name');

    unlink($tmp);
});

test('restore does not infer target from USE in dump', function () {
    $splitter = new SqlDumpStatementSplitter;
    $statements = $splitter->split('USE `some_other_db`; CREATE TABLE `t` (id int);');

    expect($splitter->shouldSkipStatement($statements[0]))->toBeTrue();
    expect($statements[1])->toBe('CREATE TABLE `t` (id int)');
});

test('MysqlPdoDumpImporter fails before SQL when restore map configuration is invalid', function () {
    Config::set('database-safety.restore_database_map', []);
    Config::set(
        'database-safety.restore_database_map_configuration_error',
        'DATABASE SAFETY: invalid DB_RESTORE_DATABASE_MAP entry (expected logical:physical): broken',
    );

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-restore-map-'.uniqid().'.sql';
    file_put_contents($tmp, "/*!40101 SET NAMES utf8mb4 */;\n");

    expect(fn () => (new MysqlPdoDumpImporter)->import($tmp, 'gestion_recovery'))
        ->toThrow(RuntimeException::class, 'logical:physical');

    unlink($tmp);
});

test('RestoreDatabaseTargetResolver blocks logical gestion restore target', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'damo5182_gestion_recovery',
        'gestion_test' => 'damo5182_gestion_test',
    ]);

    expect(fn () => (new RestoreDatabaseTargetResolver)->resolvePhysicalName('gestion'))
        ->toThrow(ProtectedDatabaseException::class);
});

test('DatabaseAccountGuard blocks gestion_app from restore operation', function () {
    Config::set('database.connections.mysql.username', 'gestion_app');
    Config::set('database-accounts.env_cutover_executed', true);

    expect(fn () => DatabaseAccountGuard::assertAccountForOperation(DatabaseAccountGuard::OPERATION_RESTORE))
        ->toThrow(ProtectedDatabaseException::class);
});

test('validateExecutableStatements rejects dump with only mysqldump housekeeping statements', function () {
    $splitter = new SqlDumpStatementSplitter;
    $statements = $splitter->split(
        "USE `legacy`;\nCREATE DATABASE IF NOT EXISTS `legacy`;\nLOCK TABLES `t` WRITE;\nUNLOCK TABLES;\n",
    );

    expect(fn () => $splitter->validateExecutableStatements($statements))
        ->toThrow(RuntimeException::class, 'no executable statements');
});

test('validateExecutableStatements rejects standalone CREATE DATABASE not skipped as executable', function () {
    $splitter = new SqlDumpStatementSplitter;
    $statements = ['CREATE DATABASE `sneaky` CHARACTER SET utf8mb4'];

    expect($splitter->shouldSkipStatement($statements[0]))->toBeTrue();

    $statementsWithPayload = array_merge($statements, ['CREATE TABLE `t` (id int)']);

    expect(fn () => $splitter->validateExecutableStatements($statementsWithPayload))
        ->not->toThrow(RuntimeException::class);
});

test('DROP DATABASE dump statements are skipped and never count as executable payload', function () {
    $splitter = new SqlDumpStatementSplitter;
    $statements = $splitter->split("SET sql_mode=NO_ENGINE_SUBSTITUTION;\nDROP DATABASE `other`;\nCREATE TABLE t (id int);\n");

    expect($splitter->shouldSkipStatement($statements[1]))->toBeTrue();
    $splitter->validateExecutableStatements($statements);
});

test('validateExecutableStatements allows DROP DATABASE text inside INSERT string literals', function () {
    $splitter = new SqlDumpStatementSplitter;
    $statements = $splitter->split(
        "INSERT INTO `notes` VALUES (1, 'DROP DATABASE gestion is only text');\nCREATE TABLE `t` (id int);\n",
    );

    expect(fn () => $splitter->validateExecutableStatements($statements))
        ->not->toThrow(RuntimeException::class);
});

test('validateExecutableStatements rejects DROP DATABASE embedded in MySQL conditional comment', function () {
    $splitter = new SqlDumpStatementSplitter;
    $statements = $splitter->split("/*!40101 DROP DATABASE `evil` */;\nCREATE TABLE `t` (id int);\n");

    expect(fn () => $splitter->validateExecutableStatements($statements))
        ->not->toThrow(RuntimeException::class);

    expect($splitter->shouldSkipStatement($statements[0]))->toBeTrue();
});

test('MysqlPdoDumpImporter rejects truncated dump before target resolution or PDO', function () {
    Config::set('database-safety.restore_database_map', [
        'gestion_recovery' => 'damo5182_niane',
    ]);

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-restore-truncated-'.uniqid().'.sql';
    file_put_contents($tmp, "CREATE TABLE t (id int);\nSELECT 1; /* truncated");

    expect(fn () => (new MysqlPdoDumpImporter)->import($tmp, 'gestion_recovery'))
        ->toThrow(RuntimeException::class, 'unclosed block comment');

    unlink($tmp);
});

test('MysqlPdoDumpImporter parse and validation run before invalid restore map is consulted', function () {
    Config::set('database-safety.restore_database_map', []);
    Config::set(
        'database-safety.restore_database_map_configuration_error',
        'DATABASE SAFETY: invalid DB_RESTORE_DATABASE_MAP entry (expected logical:physical): broken',
    );

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-restore-parse-first-'.uniqid().'.sql';
    file_put_contents($tmp, "USE `x`;\n");

    expect(fn () => (new MysqlPdoDumpImporter)->import($tmp, 'gestion_recovery'))
        ->toThrow(RuntimeException::class, 'no executable statements');

    unlink($tmp);
});

test('restore parsing errors do not include database credentials', function () {
    Config::set('database.connections.mysql.username', 'gestion_restore_user');
    Config::set('database.connections.mysql.password', 'super-secret-restore-password');

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mkd-restore-secret-'.uniqid().'.sql';
    file_put_contents($tmp, "DELIMITER ;;\n");

    try {
        (new MysqlPdoDumpImporter)->import($tmp, 'gestion_test');
        expect(false)->toBeTrue('expected import to throw');
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
        expect($message)->not->toContain('super-secret-restore-password');
        expect($message)->not->toContain('gestion_restore_user');
    } finally {
        unlink($tmp);
    }
});
