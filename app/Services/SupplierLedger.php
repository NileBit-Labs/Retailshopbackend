<?php

namespace App\Services;

use App\Enums\SupplierLedgerType;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;

class SupplierLedger
{
    public function balance(Supplier $supplier): int
    {
        return (int) SupplierLedgerEntry::query()
            ->where('supplier_id', $supplier->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount WHEN type IN (?, ?) THEN -amount ELSE amount END), 0) as balance', [
                SupplierLedgerType::PurchaseCredit->value,
                SupplierLedgerType::Payment->value,
                SupplierLedgerType::Return->value,
            ])->value('balance');
    }

    public function payment(Supplier $supplier, int $amount, User $by, ?string $reference = null, ?string $notes = null): SupplierLedgerEntry
    {
        return SupplierLedgerEntry::create([
            'supplier_id' => $supplier->id,
            'type' => SupplierLedgerType::Payment,
            'amount' => $amount,
            'reference' => $reference,
            'notes' => $notes,
            'recorded_by' => $by->id,
        ]);
    }
}
