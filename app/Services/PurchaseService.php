<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\SupplierLedgerType;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(private StockService $stock) {}

    /** @param array<string, mixed> $data */
    public function createDraft(Shop $shop, User $user, array $data): Purchase
    {
        return DB::transaction(function () use ($shop, $user, $data) {
            $supplier = Supplier::query()->where('shop_id', $shop->id)->findOrFail($data['supplier_id']);
            $purchase = Purchase::create([
                'shop_id' => $shop->id,
                'supplier_id' => $supplier->id,
                'amount_paid' => $data['amount_paid'] ?? 0,
                'created_by' => $user->id,
            ]);
            $this->replaceItems($purchase, $shop, $data['items']);

            return $purchase;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(Purchase $purchase, Shop $shop, array $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $shop, $data) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->assertDraft($purchase);

            if (array_key_exists('supplier_id', $data)) {
                $supplier = Supplier::query()->where('shop_id', $shop->id)->findOrFail($data['supplier_id']);
                $purchase->supplier_id = $supplier->id;
            }

            if (array_key_exists('amount_paid', $data)) {
                $purchase->amount_paid = $data['amount_paid'];
            }

            $purchase->save();

            if (array_key_exists('items', $data)) {
                $this->replaceItems($purchase, $shop, $data['items']);
            }

            return $purchase;
        });
    }

    public function confirm(Purchase $purchase, Shop $shop, User $user): Purchase
    {
        return DB::transaction(function () use ($purchase, $shop, $user) {
            $purchase = Purchase::query()->where('shop_id', $shop->id)->lockForUpdate()->with('items')->findOrFail($purchase->id);
            $this->assertDraft($purchase);

            if ($purchase->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['A purchase needs at least one item before confirmation.']]);
            }

            $total = $purchase->items->sum('line_total');
            if ($purchase->amount_paid > $total) {
                throw ValidationException::withMessages(['amount_paid' => ['Paid amount cannot exceed the purchase total.']]);
            }

            $lockedShop = Shop::query()->lockForUpdate()->findOrFail($shop->id);
            $lockedShop->increment('purchase_counter');
            $lockedShop->refresh();

            foreach ($purchase->items as $item) {
                $product = Product::query()->where('shop_id', $shop->id)->lockForUpdate()->findOrFail($item->product_id);
                $baseQuantity = (float) $item->quantity * (float) $item->conversion_to_base_unit;
                $baseCost = (int) round($item->unit_cost / (float) $item->conversion_to_base_unit);
                $product->update(['current_cost' => $baseCost]);
                $this->stock->record($product, $baseQuantity, MovementType::Purchase, $user, $purchase, unitCost: $baseCost);
            }

            $purchase->update([
                'purchase_number' => 'PUR-'.str_pad((string) $lockedShop->purchase_counter, 6, '0', STR_PAD_LEFT),
                'total' => $total,
                'amount_due' => $total - $purchase->amount_paid,
                'status' => 'CONFIRMED',
                'confirmed_at' => now(),
            ]);

            if ($purchase->amount_due > 0) {
                SupplierLedgerEntry::create([
                    'supplier_id' => $purchase->supplier_id,
                    'purchase_id' => $purchase->id,
                    'type' => SupplierLedgerType::PurchaseCredit,
                    'amount' => $purchase->amount_due,
                    'recorded_by' => $user->id,
                ]);
            }

            return $purchase;
        });
    }

    /** @param array<int, array<string, mixed>> $items */
    private function replaceItems(Purchase $purchase, Shop $shop, array $items): void
    {
        $purchase->items()->delete();

        foreach ($items as $item) {
            $product = Product::query()->where('shop_id', $shop->id)->findOrFail($item['product_id']);
            $unit = $item['unit'] ?? $product->base_unit;
            $conversion = $this->conversionFor($product, $unit);
            $lineTotal = (int) round((float) $item['quantity'] * (int) $item['unit_cost']);

            $purchase->items()->create([
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'unit' => $unit,
                'conversion_to_base_unit' => $conversion,
                'unit_cost' => $item['unit_cost'],
                'line_total' => $lineTotal,
            ]);
        }
    }

    private function conversionFor(Product $product, string $unit): float
    {
        if ($unit === $product->base_unit) {
            return 1.0;
        }

        $conversion = $product->units()->where('unit_name', $unit)->value('conversion_to_base_unit');

        if ($conversion === null) {
            throw ValidationException::withMessages(['items' => ["{$unit} is not a unit for {$product->name}."]]);
        }

        return (float) $conversion;
    }

    private function assertDraft(Purchase $purchase): void
    {
        if ($purchase->status !== 'DRAFT') {
            throw ValidationException::withMessages(['purchase' => ['Only draft purchases can be changed or confirmed.']]);
        }
    }
}
