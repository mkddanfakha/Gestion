<?php

namespace App\Modules\NotificationCenter\Http\Resources;

use App\Modules\NotificationCenter\Contracts\GroupedPreviewProviderInterface;
use App\Modules\NotificationCenter\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Notification */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata ?? [];

        if (! empty($metadata['grouped']) && ! empty($metadata['entity_ids']) && app()->bound(GroupedPreviewProviderInterface::class)) {
            $metadata['products'] = app(GroupedPreviewProviderInterface::class)->buildPreviews(
                (string) $this->type,
                $metadata['entity_ids'],
                $request->user(),
            );
            $metadata['count'] = count($metadata['entity_ids']);
        }

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'priority' => $this->priority?->value,
            'status' => $this->status?->value,
            'icon' => $metadata['icon'] ?? null,
            'url' => $this->url,
            'created_at' => $this->created_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'metadata' => $metadata,
        ];
    }
}
