<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $query = Purchase::query()->where('shop_id', $shop->id)->with('supplier:id,name')->withCount('items')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request, PurchaseService $purchases): JsonResponse
    {
        $purchase = $purchases->createDraft($request->attributes->get('shop'), $request->user(), $this->validated($request));

        return response()->json($this->load($purchase), 201);
    }

    public function show(Request $request, int $purchase): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Purchase::query()->where('shop_id', $shop->id)->findOrFail($purchase);

        return response()->json($this->load($model));
    }

    public function update(Request $request, PurchaseService $purchases, int $purchase): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Purchase::query()->where('shop_id', $shop->id)->findOrFail($purchase);
        $purchase = $purchases->updateDraft($model, $shop, $this->validated($request, true));

        return response()->json($this->load($purchase));
    }

    public function confirm(Request $request, PurchaseService $purchases, int $purchase): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Purchase::query()->where('shop_id', $shop->id)->findOrFail($purchase);
        $purchase = $purchases->confirm($model, $shop, $request->user());

        return response()->json($this->load($purchase));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'supplier_id' => [$partial ? 'sometimes' : 'required', 'integer'],
            'amount_paid' => ['sometimes', 'integer', 'min:0'],
            'items' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.unit_cost' => ['required_with:items', 'integer', 'min:0'],
        ]);
    }

    private function load(Purchase $purchase): Purchase
    {
        return $purchase->refresh()->load(['supplier:id,name,phone', 'creator:id,name', 'items.product:id,name,base_unit']);
    }
}
