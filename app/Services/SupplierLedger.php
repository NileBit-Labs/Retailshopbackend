<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The only writer of supplier balances. A balance is never stored: it is the
 * sum of the append-only ledger, so it can always be re-derived and audited.
 */
class SupplierLedger
{
    public const PURCHASE = 'PURCHASE';

    public const PAYMENT = 'PAYMENT';

    public const PURCHASE_CANCEL = 'PURCHASE_CANCEL';

    public function balance(Supplier $supplier): int
    {
        return (int) SupplierLedgerEntry::where('supplier_id', $supplier->id)->sum('amount');
    }

    /** @param  int  $amount  positive = the shop owes more, negative = it owes less */
    public function record(Supplier $supplier, string $type, int $amount, User $by, ?Model $reference = null, ?string $note = null): SupplierLedgerEntry
    {
        return SupplierLedgerEntry::create([
            'shop_id' => $supplier->shop_id,
            'supplier_id' => $supplier->id,
            'type' => $type,
            'amount' => $amount,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'recorded_by' => $by->id,
        ]);
    }
}
