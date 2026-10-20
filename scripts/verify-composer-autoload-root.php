<?php

declare(strict_types=1);

/**
 * Vérifie en lecture seule que l'autoload Composer de l'application pointe vers
 * la racine d'installation courante (parent de vendor/), et non vers un autre worktree.
 *
 * Ne modifie aucun fichier et n'exécute pas Composer.
 *
 * Usage : php scripts/verify-composer-autoload-root.php [chemin-racine-app]
 * Code de sortie : 0 = OK, 1 = échec, 2 = prérequis manquants.
 */

/**
 * @return list<string>
 */
function composerAutoloadRootViolations(string $projectRoot): array
{
    $projectRootReal = realpath($projectRoot);
    if ($projectRootReal === false) {
        return ['Project root not found or not readable: '.$projectRoot];
    }

    $projectRootNorm = normalizePath($projectRootReal);
    $vendorDir = $projectRootNorm.'/vendor';
    $composerDir = $vendorDir.'/composer';

    if (! is_dir($vendorDir) || ! is_dir($composerDir)) {
        return ['Missing vendor/composer directory under project root'];
    }

    $violations = [];

    $psr4File = $composerDir.'/autoload_psr4.php';
    if (is_file($psr4File)) {
        $violations = array_merge($violations, verifyPsr4AppPaths($psr4File, $projectRootNorm, $vendorDir));
    }

    foreach (['autoload_static.php', 'autoload_classmap.php', 'autoload_files.php'] as $name) {
        $path = $composerDir.'/'.$name;
        if (! is_file($path)) {
            continue;
        }
        $violations = array_merge(
            $violations,
            scanAutoloadFileForForeignAppPaths((string) file_get_contents($path), $projectRootNorm, $name),
        );
    }

    return array_values(array_unique($violations));
}

function normalizePath(string $path): string
{
    $path = str_replace('\\', '/', $path);

    return rtrim($path, '/');
}

/**
 * @return list<string>
 */
function verifyPsr4AppPaths(string $psr4File, string $projectRootNorm, string $vendorDirNorm): array
{
    /** @var array<string, list<string>> $map */
    $map = require $psr4File;
    $violations = [];
    $expectedAppDir = $projectRootNorm.'/app';

    foreach (['App\\', 'Database\\Factories\\', 'Database\\Seeders\\'] as $namespace) {
        if (! isset($map[$namespace])) {
            continue;
        }

        foreach ($map[$namespace] as $dir) {
            $dirNorm = normalizePath($dir);
            if (str_contains($dirNorm, $vendorDirNorm.'/')) {
                continue;
            }

            if (! pathIsUnderRoot($dirNorm, $projectRootNorm)) {
                $violations[] = sprintf(
                    'PSR-4 %s maps to path outside project root (%s)',
                    $namespace,
                    redactPathForOutput($dirNorm),
                );
                continue;
            }

            if ($namespace === 'App\\' && ! pathIsUnderRoot($dirNorm, $expectedAppDir)) {
                $violations[] = sprintf(
                    'PSR-4 App\\ maps outside %s/app (%s)',
                    redactPathForOutput($projectRootNorm),
                    redactPathForOutput($dirNorm),
                );
            }
        }
    }

    return $violations;
}

/**
 * Détecte des chemins App\\ codés en dur avec une racine filesystem absolue (hors $baseDir / __DIR__).
 *
 * @return list<string>
 */
function scanAutoloadFileForForeignAppPaths(string $contents, string $projectRootNorm, string $sourceFile): array
{
    $violations = [];

    $patterns = [
        "/'App\\\\\\\\[^']+'\\s*=>\\s*'([^']+)'/",
        '/"App\\\\[^"]+"\\s*=>\\s*"([^"]+)"/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $contents, $classMapMatches) === false) {
            continue;
        }

        foreach ($classMapMatches[1] as $classPath) {
            if (str_contains($classPath, '$baseDir') || str_contains($classPath, '__DIR__')) {
                continue;
            }

            $pathNorm = normalizePath($classPath);
            if ($pathNorm === '' || ! isFilesystemAbsoluteRoot($pathNorm)) {
                continue;
            }

            if (! pathIsUnderRoot($pathNorm, $projectRootNorm)) {
                $violations[] = sprintf(
                    '%s classmap App\\\\ entry outside project root (%s)',
                    $sourceFile,
                    redactPathForOutput($pathNorm),
                );
            }
        }
    }

    if (preg_match_all(
        "/(?:=>|\\.)\\s*'((?:\\/home|\\/var|\\/opt|\\/srv|\\/Users|[A-Za-z]:)[^']*\\/app[^']*)'/",
        $contents,
        $literalMatches,
    ) !== false) {
        foreach ($literalMatches[1] as $literalPath) {
            $pathNorm = normalizePath($literalPath);
            if (! pathIsUnderRoot($pathNorm, $projectRootNorm)) {
                $violations[] = sprintf(
                    '%s literal absolute app path outside project root (%s)',
                    $sourceFile,
                    redactPathForOutput($pathNorm),
                );
            }
        }
    }

    return $violations;
}

/**
 * Racine absolue explicite (pas un suffixe /app/… issu d'une concaténation relative).
 */
function isFilesystemAbsoluteRoot(string $path): bool
{
    if (preg_match('/^[A-Za-z]:\\//', $path) === 1) {
        return true;
    }

    return preg_match('#^/(?:home|var|opt|srv|Users)/#', $path) === 1;
}

function pathIsUnderRoot(string $path, string $rootNorm): bool
{
    $pathNorm = normalizePath($path);
    $root = normalizePath($rootNorm);

    if ($pathNorm === $root) {
        return true;
    }

    return str_starts_with($pathNorm.'/', $root.'/');
}

function redactPathForOutput(string $path): string
{
    $norm = normalizePath($path);

    return strlen($norm) > 120 ? substr($norm, 0, 117).'...' : $norm;
}

$executedDirectly = PHP_SAPI === 'cli'
    && isset($argv[0])
    && realpath($argv[0]) === realpath(__FILE__);

if ($executedDirectly) {
    $projectRoot = $argv[1] ?? dirname(__DIR__);
    $violations = composerAutoloadRootViolations($projectRoot);

    if ($violations !== [] && str_contains($violations[0], 'Missing vendor')) {
        fwrite(STDERR, 'verify-composer-autoload-root: '.$violations[0]."\n");
        exit(2);
    }

    if ($violations !== []) {
        fwrite(STDERR, "verify-composer-autoload-root: FAIL\n");
        foreach ($violations as $violation) {
            fwrite(STDERR, ' - '.$violation."\n");
        }
        exit(1);
    }

    echo "verify-composer-autoload-root: OK\n";
    echo 'project_root='.redactPathForOutput(normalizePath((string) realpath($projectRoot)))."\n";
    exit(0);
}
