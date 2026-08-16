<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'original_name',
        'stored_name',
        'disk',
        'path',
        'mime_type',
        'extension',
        'size',
        'file_hash',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected $appends = [
        'formatted_size',
        'is_image',
        'is_pdf',
        'file_icon',
        'show_url',
        'download_url',
    ];

    protected $hidden = [
        'path',
        'stored_name',
        'disk',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size;

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', ' ') . ' Ko';
        }

        return $bytes . ' o';
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function getIsPdfAttribute(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function getFileIconAttribute(): string
    {
        if ($this->is_image) {
            return 'bi-file-earmark-image';
        }

        if ($this->is_pdf) {
            return 'bi-file-earmark-pdf';
        }

        return 'bi-file-earmark';
    }

    public function getShowUrlAttribute(): string
    {
        return route('attachments.show', $this);
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('attachments.download', $this);
    }
}
