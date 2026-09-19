<?php

namespace App\Models;

use App\Enums\SupplierLedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'supplier_id', 'purchase_id', 'type', 'amount', 'reference', 'notes', 'recorded_by', 'created_at',
    ];

    protected function casts(): array
    {
        return ['type' => SupplierLedgerType::class, 'amount' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
