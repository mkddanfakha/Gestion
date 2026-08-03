<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AuditFieldTranslator
{
    public static function label(string $field): string
    {
        return config("audit.field_labels.{$field}")
            ?? ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $field)));
    }

    public static function formatValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }

        if (self::isForeignKey($field)) {
            return self::resolveForeignKey($field, $value);
        }

        $enumLabel = self::resolveEnumLabel($field, (string) $value);
        if ($enumLabel !== null) {
            return $enumLabel;
        }

        if (self::looksLikeDate($value)) {
            return self::formatDateValue($value);
        }

        if (is_numeric($value) && self::isMoneyField($field)) {
            return number_format((float) $value, 0, ',', ' ') . ' Fcfa';
        }

        return (string) $value;
    }

    public static function resolveForeignKey(string $field, mixed $value): string
    {
        $modelClass = config("audit.foreign_keys.{$field}");

        if (!$modelClass || !class_exists($modelClass)) {
            return (string) $value;
        }

        /** @var Model|null $record */
        $record = $modelClass::query()->find($value);

        if (!$record) {
            return (string) $value;
        }

        return ActivityLog::resolveSubjectLabel($record);
    }

    private static function isForeignKey(string $field): bool
    {
        return str_ends_with($field, '_id') && config("audit.foreign_keys.{$field}") !== null;
    }

    private static function resolveEnumLabel(string $field, string $value): ?string
    {
        return config("audit.enum_labels.{$field}.{$value}");
    }

    private static function isMoneyField(string $field): bool
    {
        return in_array($field, [
            'price', 'cost_price', 'credit_limit', 'subtotal', 'tax_amount',
            'discount_amount', 'total_amount', 'down_payment_amount',
            'remaining_amount', 'unit_price', 'total_price', 'amount',
        ], true);
    }

    private static function looksLikeDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $value);
    }

    private static function formatDateValue(mixed $value): string
    {
        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
