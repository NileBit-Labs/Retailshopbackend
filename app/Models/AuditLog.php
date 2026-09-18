<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'shop_id', 'user_id', 'action', 'entity_type',
        'entity_id', 'before_data', 'after_data',
    ];

    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
        ];
    }
}
