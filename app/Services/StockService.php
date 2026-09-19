<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** The sole writer for the append-only inventory ledger. */
class StockService
{
    public function current(int $shopId, int $productId): float
    {
        return round((float) StockMovement::query()
            ->where('shop_id', $shopId)
            ->where('product_id', $productId)
            ->sum('quantity_delta'), 3);
    }

    public function record(
        Product $product,
        float $delta,
        MovementType $type,
        User $by,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $unitCost = null,
    ): StockMovement {
        return StockMovement::create([
            'shop_id' => $product->shop_id,
            'product_id' => $product->id,
            'quantity_delta' => $delta,
            'unit_cost' => $unitCost ?? $product->current_cost,
            'movement_type' => $type,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'reason' => $reason,
            'performed_by' => $by->id,
        ]);
    }
}
