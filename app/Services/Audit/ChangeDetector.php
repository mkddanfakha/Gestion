<?php

namespace App\Services\Audit;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class ChangeDetector
{
    /** @var array<string, array<string, mixed>> */
    private static array $snapshots = [];

    public static function rememberOriginal(Model $model): void
    {
        if (!$model->exists) {
            return;
        }

        $key = self::snapshotKey($model);

        foreach ($model->getDirty() as $field => $newValue) {
            if (self::isExcluded($field)) {
                continue;
            }

            if (!isset(self::$snapshots[$key][$field])) {
                self::$snapshots[$key][$field] = self::normalizeValue(
                    $model->getRawOriginal($field)
                );
            }
        }
    }

    public static function detectChanges(Model $model): array
    {
        $key = self::snapshotKey($model);

        if (!empty(self::$snapshots[$key])) {
            $before = self::$snapshots[$key];
            unset(self::$snapshots[$key]);

            $oldValues = [];
            $newValues = [];

            foreach ($before as $field => $old) {
                $new = self::normalizeValue($model->getAttribute($field));

                if (self::valuesAreEqual($old, $new)) {
                    continue;
                }

                $oldValues[$field] = $old;
                $newValues[$field] = $new;
            }

            return [
                'old_values' => empty($oldValues) ? null : $oldValues,
                'new_values' => empty($newValues) ? null : $newValues,
            ];
        }

        $oldValues = [];
        $newValues = [];

        foreach ($model->getChanges() as $field => $newValue) {
            if (self::isExcluded($field)) {
                continue;
            }

            $old = self::normalizeValue($model->getRawOriginal($field));
            $new = self::normalizeValue($newValue);

            if (self::valuesAreEqual($old, $new)) {
                continue;
            }

            $oldValues[$field] = $old;
            $newValues[$field] = $new;
        }

        return [
            'old_values' => empty($oldValues) ? null : $oldValues,
            'new_values' => empty($newValues) ? null : $newValues,
        ];
    }

    public static function captureCreation(Model $model): array
    {
        $newValues = self::filterAttributes($model->getAttributes());

        return [
            'old_values' => null,
            'new_values' => empty($newValues) ? null : $newValues,
        ];
    }

    public static function captureDeletion(Model $model): array
    {
        $oldValues = self::filterAttributes($model->getAttributes());

        return [
            'old_values' => empty($oldValues) ? null : $oldValues,
            'new_values' => null,
        ];
    }

    public static function captureRestore(Model $model): array
    {
        return [
            'old_values' => [
                'deleted_at' => self::normalizeValue($model->getRawOriginal('deleted_at')),
            ],
            'new_values' => [
                'deleted_at' => null,
            ],
        ];
    }

    public static function clearSnapshots(): void
    {
        self::$snapshots = [];
    }

    public static function isExcluded(string $field): bool
    {
        return in_array($field, config('audit.excluded_fields', []), true);
    }

    public static function filterAttributes(array $attributes): array
    {
        $filtered = [];

        foreach ($attributes as $field => $value) {
            if (self::isExcluded($field)) {
                continue;
            }

            $filtered[$field] = self::normalizeValue($value);
        }

        return $filtered;
    }

    public static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return Arr::sortRecursive($value);
        }

        if ($value === '' || $value === null) {
            return null;
        }

        if (is_string($value) && is_numeric($value)) {
            $numeric = $value + 0;

            return is_int($numeric) || (float) $numeric === (float) (int) $numeric
                ? (int) $numeric
                : round((float) $numeric, 2);
        }

        if (is_float($value)) {
            return round($value, 2);
        }

        return $value;
    }

    public static function valuesAreEqual(mixed $old, mixed $new): bool
    {
        $old = self::normalizeValue($old);
        $new = self::normalizeValue($new);

        if ($old === $new) {
            return true;
        }

        if ($old === null && $new === null) {
            return true;
        }

        if (is_numeric($old) && is_numeric($new)) {
            return round((float) $old, 2) === round((float) $new, 2);
        }

        return (string) $old === (string) $new;
    }

    private static function snapshotKey(Model $model): string
    {
        return $model->getMorphClass() . ':' . $model->getKey();
    }
}
