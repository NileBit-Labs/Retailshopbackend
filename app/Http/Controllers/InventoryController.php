<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $query = Product::query()
            ->where('shop_id', $shop->id)
            ->with('category:id,name')
            ->withSum('stockMovements as stock', 'quantity_delta')
            ->orderBy('name');

        if (! $request->boolean('include_archived')) {
            $query->where('status', 'active');
        }

        if ($request->boolean('low_stock')) {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(quantity_delta), 0) FROM stock_movements WHERE stock_movements.product_id = products.id) <= products.low_stock_threshold'
            );
        }

        return response()->json($query->get()->map(function (Product $product): array {
            $stock = round((float) ($product->stock ?? 0), 3);

            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->category?->name,
                'base_unit' => $product->base_unit,
                'stock' => $stock,
                'low_stock_threshold' => $product->low_stock_threshold,
                'is_low_stock' => $product->low_stock_threshold > 0 && $stock <= $product->low_stock_threshold,
            ];
        })->values());
    }

    public function movements(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $query = StockMovement::query()
            ->where('shop_id', $shop->id)
            ->with(['product:id,name,base_unit', 'performer:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->filled('type')) {
            $query->where('movement_type', $request->string('type'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function adjustment(Request $request, StockService $stock): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->record($request, $stock, $data['product_id'], (float) $data['quantity_delta'], MovementType::Adjustment, $data['reason']);
    }

    public function damage(Request $request, StockService $stock): JsonResponse
    {
        return $this->stockOut($request, $stock, MovementType::Damage);
    }

    public function loss(Request $request, StockService $stock): JsonResponse
    {
        return $this->stockOut($request, $stock, MovementType::Loss);
    }

    private function stockOut(Request $request, StockService $stock, MovementType $type): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->record($request, $stock, $data['product_id'], -((float) $data['quantity']), $type, $data['reason']);
    }

    private function record(Request $request, StockService $stock, int $productId, float $delta, MovementType $type, string $reason): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $movement = DB::transaction(function () use ($shop, $productId, $delta, $type, $reason, $request, $stock) {
            $product = Product::query()->where('shop_id', $shop->id)->lockForUpdate()->findOrFail($productId);
            $current = $stock->current($shop->id, $product->id);

            if ($current + $delta < 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['This stock action would make inventory negative.'],
                ]);
            }

            return $stock->record($product, $delta, $type, $request->user(), reason: $reason);
        });

        return response()->json($movement->load(['product:id,name,base_unit', 'performer:id,name']), 201);
    }
}
