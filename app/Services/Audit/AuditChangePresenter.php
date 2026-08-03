<?php

namespace App\Services\Audit;

class AuditChangePresenter
{
    public static function present(?array $oldValues, ?array $newValues, string $action): array
    {
        if (empty($oldValues) || empty($newValues)) {
            return [];
        }

        if (in_array($action, [
            \App\Models\ActivityLog::ACTION_CREATE,
            \App\Models\ActivityLog::ACTION_DELETE,
            \App\Models\ActivityLog::ACTION_LOGIN,
            \App\Models\ActivityLog::ACTION_LOGOUT,
        ], true)) {
            return [];
        }

        $fields = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
        $changes = [];

        foreach ($fields as $field) {
            $old = $oldValues[$field] ?? null;
            $new = $newValues[$field] ?? null;

            if (ChangeDetector::valuesAreEqual($old, $new)) {
                continue;
            }

            $changes[] = [
                'field' => AuditFieldTranslator::label($field),
                'field_key' => $field,
                'old' => AuditFieldTranslator::formatValue($field, $old),
                'new' => AuditFieldTranslator::formatValue($field, $new),
            ];
        }

        return $changes;
    }
}
