<?php

namespace App\Modules\NotificationCenter\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTypeSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'type';

    protected $keyType = 'string';

    protected $fillable = [
        'type',
        'priority',
        'recipients',
        'channels',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'channels' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
