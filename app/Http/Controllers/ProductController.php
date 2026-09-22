<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductService;
use App\Support\ManagedProductPresenter;
use App\Support\PerPage;
use App\Support\ProductRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner/manager only (see routes): this is where cost prices live. */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $status = $request->query('status', 'active');
        $stockSql = '(select coalesce(sum(quantity_delta), 0) from stock_movements where stock_movements.product_id = products.id)';

        $query = Product::where('shop_id', $shop->id)
            ->with(['category:id,name', 'units'])
            ->withSum('stockMovements as stock', 'quantity_delta')
            ->orderBy('name');

        if ($status !== 'all') {
            $query->where('status', $status === 'archived' ? 'archived' : 'active');
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(coalesce(sku, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(barcode, \'\')) like ?', [$like]));
        }

        if ($request->boolean('low_stock')) {
            $query->whereRaw("low_stock_threshold > 0 and $stockSql > 0 and $stockSql <= low_stock_threshold");
        }

        if ($request->boolean('out_of_stock')) {
            $query->whereRaw("$stockSql <= 0");
        }

        return response()->json($query->paginate(PerPage::from($request))->through(fn (Product $p) => ManagedProductPresenter::format($p)));
    }

    public function store(Request $request, ProductService $products): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $data = $request->validate(ProductRules::rules($shop->id) + [
            'opening_stock' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $product = $products->create($shop, $request->user(), $data, (float) ($data['opening_stock'] ?? 0));

        return response()->json($this->fresh($request, $product->id), 201);
    }

    public function show(Request $request, int $product): JsonResponse
    {
        return response()->json($this->fresh($request, $product));
    }

    public function update(Request $request, ProductService $products, int $product): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Product::where('shop_id', $shop->id)->findOrFail($product);

        $data = $request->validate(ProductRules::rules($shop->id, $model->id, partial: true));
        $products->update($shop, $request->user(), $model, $data);

        return response()->json($this->fresh($request, $model->id));
    }

    public function archive(Request $request, ProductService $products, int $product): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $products->setStatus($shop, $request->user(), Product::where('shop_id', $shop->id)->findOrFail($product), 'archived');

        return response()->json($this->fresh($request, $product));
    }

    public function restore(Request $request, ProductService $products, int $product): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $products->setStatus($shop, $request->user(), Product::where('shop_id', $shop->id)->findOrFail($product), 'active');

        return response()->json($this->fresh($request, $product));
    }

    /** @return array<string, mixed> */
    private function fresh(Request $request, int $id): array
    {
        $product = Product::where('shop_id', $request->attributes->get('shop')->id)
            ->with(['category:id,name', 'units'])
            ->withSum('stockMovements as stock', 'quantity_delta')
            ->findOrFail($id);

        return ManagedProductPresenter::format($product);
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
