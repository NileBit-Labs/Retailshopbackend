<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    protected $fillable = ['product_id', 'unit_name', 'conversion_to_base_unit', 'selling_price'];

    protected function casts(): array
    {
        return [
            'conversion_to_base_unit' => 'float',
            'selling_price' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
