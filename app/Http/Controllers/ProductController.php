<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $query = Product::query()
            ->where('shop_id', $shop->id)
            ->with(['category:id,name', 'units'])
            ->withSum('stockMovements as stock', 'quantity_delta')
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        } else {
            $query->where('status', 'active');
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($search = trim((string) $request->query('search'))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn ($products) => $products
                ->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw("lower(coalesce(sku, '')) like ?", [$like])
                ->orWhereRaw("lower(coalesce(barcode, '')) like ?", [$like]));
        }

        return response()->json($query->get()->map(fn (Product $product) => $this->present($product))->values());
    }

    public function store(Request $request, StockService $stock): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $data = $this->validated($request, $shop->id, false);

        $product = DB::transaction(function () use ($data, $shop, $request, $stock) {
            $openingStock = $data['opening_stock'] ?? null;
            unset($data['opening_stock']);

            $product = Product::create($data + ['shop_id' => $shop->id]);
            $this->replaceUnits($product, $data['units'] ?? []);

            if ($openingStock !== null && (float) $openingStock['quantity'] > 0) {
                $cost = (int) $openingStock['unit_cost'];
                $product->update(['current_cost' => $cost]);
                $stock->record($product, (float) $openingStock['quantity'], MovementType::OpeningStock, $request->user(), unitCost: $cost);
            }

            return $product;
        });

        return response()->json($this->loadAndPresent($product), 201);
    }

    public function show(Request $request, int $product): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Product::query()->where('shop_id', $shop->id)->with(['category:id,name', 'units'])
            ->withSum('stockMovements as stock', 'quantity_delta')->findOrFail($product);

        return response()->json($this->present($model));
    }

    public function update(Request $request, StockService $stock, int $product): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Product::query()->where('shop_id', $shop->id)->findOrFail($product);
        $data = $this->validated($request, $shop->id, true, $model);

        DB::transaction(function () use ($data, $model) {
            $units = $data['units'] ?? null;
            unset($data['units']);
            $model->update($data);

            if ($units !== null) {
                $this->replaceUnits($model, $units);
            }
        });

        return response()->json($this->loadAndPresent($model));
    }

    public function archive(Request $request, int $product): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Product::query()->where('shop_id', $shop->id)->findOrFail($product);
        $model->update(['status' => 'archived']);

        return response()->json($this->loadAndPresent($model));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $shopId, bool $partial, ?Product $product = null): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $uniqueSku = Rule::unique('products', 'sku')->where('shop_id', $shopId)->ignore($product?->id);
        $uniqueBarcode = Rule::unique('products', 'barcode')->where('shop_id', $shopId)->ignore($product?->id);

        $data = $request->validate([
            'category_id' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'integer', Rule::exists('categories', 'id')->where('shop_id', $shopId)],
            'name' => [$required, 'string', 'max:255'],
            'sku' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:100', $uniqueSku],
            'barcode' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:100', $uniqueBarcode],
            'base_unit' => [$required, 'string', 'max:50'],
            'selling_price' => [$required, 'integer', 'min:0'],
            'current_cost' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'integer', 'min:0'],
            'low_stock_threshold' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'numeric', 'min:0'],
            'units' => ['sometimes', 'array'],
            'units.*.unit_name' => ['required_with:units', 'string', 'max:50', 'distinct'],
            'units.*.conversion_to_base_unit' => ['required_with:units', 'numeric', 'gt:0'],
            'units.*.selling_price' => ['required_with:units', 'integer', 'min:0'],
            'opening_stock' => [$partial ? 'prohibited' : 'nullable', 'array'],
            'opening_stock.quantity' => ['required_with:opening_stock', 'numeric', 'min:0'],
            'opening_stock.unit_cost' => ['required_with:opening_stock', 'integer', 'min:0'],
        ]);

        if (! $partial && array_key_exists('current_cost', $data) && $data['current_cost'] === null) {
            unset($data['current_cost']);
        }

        return $data;
    }

    /** @param array<int, array<string, mixed>> $units */
    private function replaceUnits(Product $product, array $units): void
    {
        $product->units()->delete();
        $product->units()->createMany($units);
    }

    private function loadAndPresent(Product $product): array
    {
        $product->load(['category:id,name', 'units'])->loadSum('stockMovements as stock', 'quantity_delta');

        return $this->present($product);
    }

    private function present(Product $product): array
    {
        $stock = round((float) ($product->stock ?? 0), 3);

        return [
            'id' => $product->id,
            'category' => $product->category,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'base_unit' => $product->base_unit,
            'selling_price' => $product->selling_price,
            'current_cost' => $product->current_cost,
            'low_stock_threshold' => $product->low_stock_threshold,
            'stock' => $stock,
            'is_low_stock' => $product->low_stock_threshold > 0 && $stock <= $product->low_stock_threshold,
            'status' => $product->status,
            'units' => $product->units,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
        ];
    }
}
