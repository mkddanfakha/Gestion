<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed progress for async backup creation jobs.
 */
final class BackupCreationProgress
{
    public const TTL_SECONDS = 1800;

    public static function cacheKey(string $jobId): string
    {
        return 'backup_create_progress_'.$jobId;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function put(string $jobId, array $data): void
    {
        Cache::put(self::cacheKey($jobId), array_merge([
            'job_id' => $jobId,
            'percentage' => 0,
            'message' => '',
            'status' => 'queued',
            'updated_at' => now()->toIso8601String(),
        ], $data, [
            'job_id' => $jobId,
            'updated_at' => now()->toIso8601String(),
        ]), self::TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $jobId): ?array
    {
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $jobId)) {
            return null;
        }

        $data = Cache::get(self::cacheKey($jobId));

        return is_array($data) ? $data : null;
    }

    public static function forget(string $jobId): void
    {
        Cache::forget(self::cacheKey($jobId));
    }
}
