<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\SupplierLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    public function index(Request $request, SupplierLedger $ledger): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $suppliers = Supplier::query()->where('shop_id', $shop->id)->orderBy('name')->get();

        return response()->json($suppliers->map(fn (Supplier $supplier) => $this->present($supplier, $ledger->balance($supplier)))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $supplier = Supplier::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]) + ['shop_id' => $shop->id]);

        return response()->json($supplier, 201);
    }

    public function show(Request $request, SupplierLedger $ledger, int $supplier): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Supplier::query()->where('shop_id', $shop->id)->findOrFail($supplier);

        return response()->json($this->present($model, $ledger->balance($model)));
    }

    public function ledger(Request $request, SupplierLedger $ledger, int $supplier): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Supplier::query()->where('shop_id', $shop->id)->findOrFail($supplier);
        $balance = 0;
        $entries = $model->ledgerEntries()->with('recorder:id,name')->orderBy('created_at')->orderBy('id')->get()
            ->map(function ($entry) use (&$balance) {
                $balance += in_array($entry->type->value, ['PAYMENT', 'RETURN'], true) ? -$entry->amount : $entry->amount;
                $entry->setAttribute('running_balance', $balance);

                return $entry;
            })->values();

        return response()->json(['supplier' => $this->present($model, $ledger->balance($model)), 'data' => $entries]);
    }

    public function pay(Request $request, SupplierLedger $ledger, int $supplier): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $entry = DB::transaction(function () use ($shop, $supplier, $data, $request, $ledger) {
            $model = Supplier::query()->where('shop_id', $shop->id)->lockForUpdate()->findOrFail($supplier);
            if ($data['amount'] > $ledger->balance($model)) {
                throw ValidationException::withMessages(['amount' => ['Payment cannot exceed the outstanding supplier balance.']]);
            }

            return $ledger->payment($model, $data['amount'], $request->user(), $data['reference'] ?? null, $data['notes'] ?? null);
        });

        return response()->json($entry->load('recorder:id,name'), 201);
    }

    private function present(Supplier $supplier, int $balance): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'notes' => $supplier->notes,
            'balance' => $balance,
            'created_at' => $supplier->created_at,
            'updated_at' => $supplier->updated_at,
        ];
    }
}
