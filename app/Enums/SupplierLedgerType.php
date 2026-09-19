<?php

namespace App\Enums;

enum SupplierLedgerType: string
{
    case PurchaseCredit = 'PURCHASE_CREDIT';
    case Payment = 'PAYMENT';
    case Return = 'RETURN';
    case Adjustment = 'ADJUSTMENT';
}
