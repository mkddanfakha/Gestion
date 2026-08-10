<?php

namespace App\Modules\NotificationCenter\DTO;

use App\Modules\NotificationCenter\Enums\NotificationPriority;
use App\Modules\NotificationCenter\Enums\NotificationStatus;

/**
 * DTO générique — seul contrat entre l'application métier et le module.
 */
final readonly class CreateNotificationData
{
    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $broadcastPayload
     */
    public function __construct(
        public int $userId,
        public string $title,
        public string $description,
        public string $type,
        public NotificationPriority $priority = NotificationPriority::Info,
        public NotificationStatus $status = NotificationStatus::Active,
        public ?string $url = null,
        public ?string $icon = null,
        public ?string $entityType = null,
        public int|string|null $entityId = null,
        public ?string $groupKey = null,
        public ?int $createdBy = null,
        public ?array $metadata = null,
        public bool $broadcast = true,
        public ?array $broadcastPayload = null,
        public ?string $legacyType = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: (int) $data['user_id'],
            title: (string) $data['title'],
            description: (string) ($data['description'] ?? ''),
            type: (string) $data['type'],
            priority: isset($data['priority'])
                ? ($data['priority'] instanceof NotificationPriority
                    ? $data['priority']
                    : NotificationPriority::from((string) $data['priority']))
                : NotificationPriority::Info,
            status: isset($data['status'])
                ? ($data['status'] instanceof NotificationStatus
                    ? $data['status']
                    : NotificationStatus::from((string) $data['status']))
                : NotificationStatus::Active,
            url: $data['url'] ?? null,
            icon: $data['icon'] ?? null,
            entityType: $data['entity_type'] ?? null,
            entityId: $data['entity_id'] ?? null,
            groupKey: $data['group_key'] ?? null,
            createdBy: isset($data['created_by']) ? (int) $data['created_by'] : null,
            metadata: $data['metadata'] ?? null,
            broadcast: (bool) ($data['broadcast'] ?? true),
            broadcastPayload: $data['broadcast_payload'] ?? null,
            legacyType: $data['legacy_type'] ?? null,
        );
    }
}
