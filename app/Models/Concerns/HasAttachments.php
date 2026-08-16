<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAttachments
{
    public static function bootHasAttachments(): void
    {
        static::deleting(function (Model $model) {
            app(AttachmentService::class)->deleteFor($model);
        });
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
