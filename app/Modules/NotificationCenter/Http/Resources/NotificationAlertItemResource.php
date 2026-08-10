<?php

namespace App\Modules\NotificationCenter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
class NotificationAlertItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'type' => $this->resource['type'],
            'priority' => $this->resource['priority'],
            'severity' => $this->resource['severity'] ?? $this->resource['priority'],
            'title' => $this->resource['title'],
            'description' => $this->resource['description'],
            'message' => $this->resource['message'] ?? $this->resource['description'],
            'entity_id' => $this->resource['entity_id'],
            'legacy_type' => $this->resource['legacy_type'],
            'product' => $this->resource['product'] ?? null,
            'url' => $this->resource['url'] ?? null,
            'read_at' => $this->resource['read_at'] ?? null,
            'created_at' => $this->resource['created_at'],
            'status' => $this->resource['status'] ?? 'active',
        ];
    }
}
