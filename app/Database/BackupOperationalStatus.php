<?php

namespace App\Database;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only operational backup health for MKD-Pro (PRE-PROD 10.2).
 * Never restores, migrates, or prints credentials.
 */
final class BackupOperationalStatus
{
    public const RPO_HOURS = 24;

    /**
     * @return array<string, mixed>
     */
    public static function evaluate(?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $disks = array_values(array_filter(array_map(
            static fn ($d): string => trim((string) $d),
            config('backup.backup.destination.disks', ['local']) ?: ['local'],
        )));
        $folder = (string) config('backup.backup.name', 'laravel-backup');
        $s3InDest = in_array('s3', $disks, true);
        $bucket = trim((string) config('filesystems.disks.s3.bucket', ''));
        $endpoint = trim((string) config('filesystems.disks.s3.endpoint', ''));
        $pathStyle = filter_var(config('filesystems.disks.s3.use_path_style_endpoint', false), FILTER_VALIDATE_BOOLEAN);
        $offsiteWired = $s3InDest && $bucket !== '';

        $local = self::inspectDisk('local', $folder, $now);
        $offsite = $offsiteWired
            ? self::inspectDisk('s3', $folder, $now)
            : [
                'state' => 'NOT_CONFIGURED',
                'last_backup' => null,
                'age_hours' => null,
                'checksum' => null,
                'zip' => null,
                'archive_count' => 0,
            ];

        $mode = match (true) {
            $offsiteWired && ($local['state'] === 'OK' || $local['state'] === 'STALE')
                && ($offsite['state'] === 'OK' || $offsite['state'] === 'STALE') => 'LOCAL_AND_OFFSITE',
            $offsiteWired && ($local['state'] === 'OK' || $local['state'] === 'STALE')
                && in_array($offsite['state'], ['MISSING', 'FAILED'], true) => 'LOCAL_ONLY',
            $offsiteWired && $local['state'] === 'MISSING'
                && ($offsite['state'] === 'OK' || $offsite['state'] === 'STALE') => 'OFFSITE_ONLY',
            ! $offsiteWired && ($local['state'] === 'OK' || $local['state'] === 'STALE') => 'LOCAL_ONLY',
            default => 'FAILED',
        };

        $rpo = 'OK';
        if ($local['state'] === 'MISSING' && (! $offsiteWired || $offsite['state'] === 'MISSING' || $offsite['state'] === 'NOT_CONFIGURED')) {
            $rpo = 'BREACHED';
        } elseif ($local['state'] === 'STALE' || ($offsiteWired && $offsite['state'] === 'STALE')) {
            $rpo = 'BREACHED';
        } elseif ($offsiteWired && $offsite['state'] === 'MISSING' && ($local['state'] === 'OK' || $local['state'] === 'STALE')) {
            $rpo = 'BREACHED';
        }

        $overall = 'OK';
        if ($mode === 'FAILED' || $local['state'] === 'MISSING') {
            $overall = 'FAILED';
        } elseif (! $offsiteWired) {
            $overall = 'BACKUP DEGRADED';
        } elseif ($offsite['state'] === 'MISSING' || $offsite['state'] === 'FAILED' || $mode === 'LOCAL_ONLY' || $mode === 'OFFSITE_ONLY') {
            $overall = 'BACKUP DEGRADED';
        } elseif ($rpo === 'BREACHED' || $local['state'] === 'STALE' || $offsite['state'] === 'STALE') {
            $overall = 'BACKUP DEGRADED';
        }

        $checksum = 'FAIL';
        $zip = 'INVALID';
        if (($local['checksum'] ?? null) === 'PASS' || ($offsite['checksum'] ?? null) === 'PASS') {
            $checksum = 'PASS';
        }
        if (($local['zip'] ?? null) === 'VALID' || ($offsite['zip'] ?? null) === 'VALID') {
            $zip = 'VALID';
        }
        if ($local['state'] === 'MISSING' && (! $offsiteWired || $offsite['state'] === 'MISSING' || $offsite['state'] === 'NOT_CONFIGURED')) {
            $checksum = 'FAIL';
            $zip = 'INVALID';
        }

        return [
            'status' => $overall,
            'mode' => $mode,
            'backup_local' => $local['state'],
            'backup_offsite' => $offsite['state'],
            'last_backup' => $local['last_backup'] ?? $offsite['last_backup'] ?? null,
            'last_offsite_copy' => $offsiteWired ? ($offsite['last_backup'] ?? null) : null,
            'checksum' => $checksum,
            'zip' => $zip,
            'backup_age_hours' => $local['age_hours'] ?? $offsite['age_hours'] ?? null,
            'rpo' => $rpo,
            'rpo_hours' => self::RPO_HOURS,
            'backup_disks' => $disks,
            'offsite_wiring' => [
                's3_in_backup_disks' => $s3InDest ? 'YES' : 'NO',
                'aws_bucket' => $bucket !== '' ? 'SET' : 'NOT_SET',
                'aws_endpoint' => $endpoint !== '' ? 'SET' : 'NOT_SET',
                'path_style' => $pathStyle ? 'true' : 'false',
                'automated_offsite' => $offsiteWired ? 'CONFIGURED' : 'NOT_CONFIGURED',
            ],
            'local_detail' => $local,
            'offsite_detail' => $offsite,
        ];
    }

    /**
     * @return array{state: string, last_backup: ?string, age_hours: ?float, checksum: ?string, zip: ?string, archive_count: int, latest: ?array}
     */
    private static function inspectDisk(string $disk, string $folder, CarbonInterface $now): array
    {
        try {
            $files = Storage::disk($disk)->files($folder);
        } catch (\Throwable) {
            return [
                'state' => $disk === 's3' ? 'FAILED' : 'MISSING',
                'last_backup' => null,
                'age_hours' => null,
                'checksum' => 'FAIL',
                'zip' => 'INVALID',
                'archive_count' => 0,
                'latest' => null,
            ];
        }

        $zips = array_values(array_filter(
            $files,
            static fn (string $path): bool => str_ends_with(strtolower($path), '.zip'),
        ));

        if ($zips === []) {
            return [
                'state' => 'MISSING',
                'last_backup' => null,
                'age_hours' => null,
                'checksum' => 'FAIL',
                'zip' => 'INVALID',
                'archive_count' => 0,
                'latest' => null,
            ];
        }

        rsort($zips, SORT_STRING);
        $relative = $zips[0];
        $basename = basename($relative);

        $timestamp = null;
        if (preg_match('/(\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})\.zip$/i', $basename, $m)) {
            try {
                $timestamp = Carbon::createFromFormat('Y-m-d-H-i-s', $m[1]);
            } catch (\Throwable) {
                $timestamp = null;
            }
        }

        $ageHours = $timestamp ? round($timestamp->diffInMinutes($now) / 60, 2) : null;
        $state = 'OK';
        if ($ageHours !== null && $ageHours > self::RPO_HOURS) {
            $state = 'STALE';
        }

        $checksum = null;
        $zipVerdict = null;
        $latestMeta = [
            'file' => $basename,
            'disk' => $disk,
        ];

        if ($disk === 'local') {
            try {
                $absolute = Storage::disk($disk)->path($relative);
                $info = BackupArchiveInspector::inspect($absolute);
                $checksum = ! empty($info['sha256']) ? 'PASS' : 'FAIL';
                $readable = ($info['readable'] ?? true) === true && empty($info['error']);
                $zipVerdict = ($readable && ($info['sql']['present'] ?? false)) ? 'VALID' : 'INVALID';
                if (! $readable) {
                    $zipVerdict = 'INVALID';
                    $state = 'FAILED';
                }
                $latestMeta = array_merge($latestMeta, [
                    'size_bytes' => $info['size_bytes'] ?? null,
                    'sha256' => $info['sha256'] ?? null,
                    'verdict' => $info['verdict'] ?? null,
                ]);
            } catch (\Throwable) {
                $checksum = 'FAIL';
                $zipVerdict = 'INVALID';
            }
        } else {
            try {
                $size = Storage::disk($disk)->size($relative);
                $checksum = $size > 0 ? 'PASS' : 'FAIL';
                $zipVerdict = $size > 0 ? 'VALID' : 'INVALID';
                $latestMeta['size_bytes'] = $size;
            } catch (\Throwable) {
                $checksum = 'FAIL';
                $zipVerdict = 'INVALID';
                $state = 'FAILED';
            }
        }

        return [
            'state' => $state,
            'last_backup' => $timestamp?->toIso8601String() ?? $basename,
            'age_hours' => $ageHours,
            'checksum' => $checksum,
            'zip' => $zipVerdict,
            'archive_count' => count($zips),
            'latest' => $latestMeta,
        ];
    }
}
