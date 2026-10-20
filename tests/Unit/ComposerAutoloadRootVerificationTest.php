<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once __DIR__.'/../../scripts/verify-composer-autoload-root.php';

test('verify-composer-autoload-root script succeeds on local project vendor', function () {
    $root = dirname(__DIR__, 2);

    $process = Process::fromShellCommandline(
        'php '.escapeshellarg($root.'/scripts/verify-composer-autoload-root.php').' '.escapeshellarg($root),
        $root,
    );
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('verify-composer-autoload-root: OK');
});

test('scan detects absolute App path pointing to foreign worktree', function () {
    $projectRoot = normalizePath((string) realpath(dirname(__DIR__, 2)));

    $foreign = '/home/example/backups/niane-deploy-build-5eebe9f/app/Services/UserActivity/UserActivityQueryService.php';
    $contents = "'App\\\\Services\\\\UserActivity\\\\UserActivityQueryService' => '".$foreign."',";

    $violations = scanAutoloadFileForForeignAppPaths($contents, $projectRoot, 'autoload_classmap.php');

    expect($violations)->not->toBeEmpty();
});

test('scan ignores relative __DIR__ based app paths', function () {
    $projectRoot = normalizePath((string) realpath(dirname(__DIR__, 2)));

    $contents = "'App\\\\' => array ( 0 => __DIR__ . '/..' . '/../app', ),";

    $violations = scanAutoloadFileForForeignAppPaths($contents, $projectRoot, 'autoload_static.php');

    expect($violations)->toBeEmpty();
});
