<?php

namespace App\Enums;

enum InventorySessionStatus: string
{
    case Draft = 'draft';
    case Counting = 'counting';
    case Review = 'review';
    case Validated = 'validated';
    case Applied = 'applied';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isCounting(): bool
    {
        return $this === self::Counting;
    }

    public function isReview(): bool
    {
        return $this === self::Review;
    }

    public function isValidated(): bool
    {
        return $this === self::Validated;
    }

    public function isApplied(): bool
    {
        return $this === self::Applied;
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    /**
     * Session encore ouverte dans le workflow (règle « une session active par magasin » — Phase 4B).
     */
    public function isActive(): bool
    {
        return in_array($this, self::activeStatuses(), true);
    }

    /**
     * @return list<self>
     */
    public static function activeStatuses(): array
    {
        return [
            self::Draft,
            self::Counting,
            self::Review,
            self::Validated,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
