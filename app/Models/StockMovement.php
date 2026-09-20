<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'shop_id', 'product_id', 'quantity_delta', 'unit_cost', 'movement_type',
        'reference_type', 'reference_id', 'reason', 'performed_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'float',
            'unit_cost' => 'integer',
            'movement_type' => MovementType::class,
        ];
    }
}
