<?php

namespace App\Services;

use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only figures built from the append-only records (sales, refunds,
 * payments, purchases, expenses). Nothing here stores a total: every number
 * can be re-derived from the ledgers.
 *
 * Days are the shop's own days (its organisation's timezone), not UTC, so
 * "today" means today in Kampala.
 *
 * - Net sales    = completed sales - refunds, each dated when it happened.
 * - Cost of goods = what the items cost when they were sold (historical_cost),
 *                  less the cost of refunded items that went back on the shelf.
 * - Gross profit = net sales - cost of goods.
 * - Net profit   = gross profit - expenses.
 */
class ReportService
{
    private const MAX_DAYS = 366;

    /** @return array{tz: string, from: string, to: string, start: Carbon, end: Carbon, days: array<int, string>} */
    public function period(Shop $shop, ?string $from = null, ?string $to = null): array
    {
        $tz = $shop->organization?->timezone ?: 'Africa/Kampala';
        $today = Carbon::now($tz)->startOfDay();

        $first = $from ? Carbon::parse($from, $tz)->startOfDay() : $today->copy()->startOfMonth();
        $last = $to ? Carbon::parse($to, $tz)->startOfDay() : $today->copy();

        if ($last->lt($first)) {
            throw ValidationException::withMessages(['to' => 'The end date must not be before the start date.']);
        }

        if ($first->diffInDays($last) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose a period of a year or less.']);
        }

        $days = [];
        for ($d = $first->copy(); $d->lte($last); $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return [
            'tz' => $tz,
            'from' => $first->toDateString(),
            'to' => $last->toDateString(),
            'start' => $first->copy()->utc(),
            'end' => $last->copy()->endOfDay()->utc(),
            'days' => $days,
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    public function overview(Shop $shop, array $period, ?int $cashierId = null): array
    {
        $tz = $period['tz'];
        $day = fn ($utc) => Carbon::parse($utc, 'UTC')->setTimezone($tz)->toDateString();

        $daily = [];
        foreach ($period['days'] as $date) {
            $daily[$date] = ['date' => $date, 'gross_sales' => 0, 'refunds' => 0, 'cost' => 0, 'sales_count' => 0];
        }

        $sales = DB::table('sales')
            ->where('shop_id', $shop->id)->where('status', 'completed')
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId))
            ->get(['id', 'cashier_id', 'total', 'discount', 'amount_due', 'created_at']);

        $totals = ['gross_sales' => 0, 'discounts' => 0, 'refunds' => 0, 'cost' => 0, 'sales_count' => $sales->count(), 'on_credit' => 0];
        $byCashier = [];

        foreach ($sales as $sale) {
            $d = $day($sale->created_at);
            $daily[$d]['gross_sales'] += $sale->total;
            $daily[$d]['sales_count']++;
            $totals['gross_sales'] += $sale->total;
            $totals['discounts'] += $sale->discount;
            $totals['on_credit'] += $sale->amount_due;
            $byCashier[$sale->cashier_id]['sales'] = ($byCashier[$sale->cashier_id]['sales'] ?? 0) + $sale->total;
            $byCashier[$sale->cashier_id]['count'] = ($byCashier[$sale->cashier_id]['count'] ?? 0) + 1;
        }

        $byCategory = [];

        // Own-sales views (a cashier's dashboard) never see costs or refunds.
        if ($cashierId === null) {
            foreach ($this->soldItems($shop, $period) as $item) {
                $cost = (int) round($item->historical_cost * (float) $item->quantity);
                $daily[$day($item->sold_at)]['cost'] += $cost;
                $totals['cost'] += $cost;

                $cat = $item->category ?? 'Uncategorised';
                $byCategory[$cat]['sales'] = ($byCategory[$cat]['sales'] ?? 0) + $item->line_total;
                $byCategory[$cat]['cost'] = ($byCategory[$cat]['cost'] ?? 0) + $cost;
            }

            foreach ($this->refundedItems($shop, $period) as $item) {
                $d = $day($item->refunded_at);
                $daily[$d]['refunds'] += $item->amount;
                $totals['refunds'] += $item->amount;

                $cat = $item->category ?? 'Uncategorised';
                $byCategory[$cat]['sales'] = ($byCategory[$cat]['sales'] ?? 0) - $item->amount;

                if ($item->restock) {
                    $cost = (int) round($item->historical_cost * (float) $item->quantity);
                    $daily[$d]['cost'] -= $cost;
                    $totals['cost'] -= $cost;
                    $byCategory[$cat]['cost'] = ($byCategory[$cat]['cost'] ?? 0) - $cost;
                }
            }
        }

        $netSales = $totals['gross_sales'] - $totals['refunds'];
        $grossProfit = $netSales - $totals['cost'];
        $expenses = $cashierId === null ? $this->expenses($shop, $period) : 0;

        return [
            'period' => ['from' => $period['from'], 'to' => $period['to'], 'timezone' => $tz],
            'totals' => [
                'gross_sales' => $totals['gross_sales'],
                'discounts' => $totals['discounts'],
                'refunds' => $totals['refunds'],
                'net_sales' => $netSales,
                'cost_of_goods' => $totals['cost'],
                'gross_profit' => $grossProfit,
                'expenses' => $expenses,
                'net_profit' => $grossProfit - $expenses,
                'purchases' => $cashierId === null ? $this->purchases($shop, $period) : 0,
                'sales_count' => $totals['sales_count'],
                'average_sale' => $totals['sales_count'] ? (int) round($totals['gross_sales'] / $totals['sales_count']) : 0,
            ],
            'payments' => $this->paymentSplit($shop, $period, $cashierId) + ['on_credit' => $totals['on_credit']],
            'daily' => array_values(array_map(fn ($row) => $row + [
                'net_sales' => $row['gross_sales'] - $row['refunds'],
                'gross_profit' => $row['gross_sales'] - $row['refunds'] - $row['cost'],
            ], $daily)),
            'by_cashier' => $this->cashierNames($byCashier),
            'by_category' => collect($byCategory)->map(fn ($row, $name) => [
                'category' => $name,
                'net_sales' => $row['sales'],
                'gross_profit' => $row['sales'] - $row['cost'],
            ])->sortByDesc('net_sales')->values()->all(),
        ];
    }

    /**
     * Per product: what was sold, what it earned and what it cost.
     *
     * @param  array<string, mixed>  $period
     * @return array<int, array<string, mixed>>
     */
    public function products(Shop $shop, array $period, int $limit = 50): array
    {
        $rows = [];

        foreach ($this->soldItems($shop, $period) as $item) {
            $r = &$rows[$item->product_id];
            $r['product_id'] = $item->product_id;
            $r['name'] = $item->product_name;
            $r['quantity'] = ($r['quantity'] ?? 0) + round((float) $item->quantity * (float) $item->unit_conversion, 3);
            $r['revenue'] = ($r['revenue'] ?? 0) + $item->line_total;
            $r['cost'] = ($r['cost'] ?? 0) + (int) round($item->historical_cost * (float) $item->quantity);
            unset($r);
        }

        foreach ($this->refundedItems($shop, $period) as $item) {
            if (! isset($rows[$item->product_id])) {
                $rows[$item->product_id] = ['product_id' => $item->product_id, 'name' => $item->product_name, 'quantity' => 0, 'revenue' => 0, 'cost' => 0];
            }

            $rows[$item->product_id]['quantity'] -= round((float) $item->quantity * (float) $item->unit_conversion, 3);
            $rows[$item->product_id]['revenue'] -= $item->amount;

            if ($item->restock) {
                $rows[$item->product_id]['cost'] -= (int) round($item->historical_cost * (float) $item->quantity);
            }
        }

        return collect($rows)->map(fn ($r) => $r + [
            'profit' => $r['revenue'] - $r['cost'],
            'margin' => $r['revenue'] > 0 ? (int) round(($r['revenue'] - $r['cost']) / $r['revenue'] * 100) : null,
        ])->sortByDesc('revenue')->take($limit)->values()->all();
    }

    /**
     * What customers owe the shop and what the shop owes suppliers.
     *
     * @return array{receivable: array<string, mixed>, payable: array<string, mixed>}
     */
    public function balances(Shop $shop): array
    {
        $customers = DB::table('customers')
            ->join('customer_ledger_entries as l', 'l.customer_id', '=', 'customers.id')
            ->where('customers.shop_id', $shop->id)
            ->groupBy('customers.id', 'customers.name', 'customers.phone')
            ->havingRaw('sum(l.amount) > 0')
            ->orderByRaw('sum(l.amount) desc')
            ->get(['customers.id', 'customers.name', 'customers.phone', DB::raw('sum(l.amount) as balance')]);

        $suppliers = DB::table('suppliers')
            ->join('supplier_ledger_entries as l', 'l.supplier_id', '=', 'suppliers.id')
            ->where('suppliers.shop_id', $shop->id)
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.phone')
            ->havingRaw('sum(l.amount) > 0')
            ->orderByRaw('sum(l.amount) desc')
            ->get(['suppliers.id', 'suppliers.name', 'suppliers.phone', DB::raw('sum(l.amount) as balance')]);

        $shape = fn ($rows) => [
            'total' => (int) $rows->sum('balance'),
            'count' => $rows->count(),
            'rows' => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'balance' => (int) $r->balance])->all(),
        ];

        return ['receivable' => $shape($customers), 'payable' => $shape($suppliers)];
    }

    /**
     * Money received for sales made in the period, by how it was paid.
     *
     * @param  array<string, mixed>  $period
     * @return array{by_method: array<int, array{method: string, total: int}>}
     */
    public function paymentSplit(Shop $shop, array $period, ?int $cashierId = null): array
    {
        $rows = DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->where('payments.shop_id', $shop->id)
            ->where('payments.direction', 'in')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$period['start'], $period['end']])
            ->when($cashierId, fn ($q) => $q->where('sales.cashier_id', $cashierId))
            ->groupBy('payments.method')
            ->selectRaw('payments.method as method, sum(payments.amount) as total')
            ->orderByDesc('total')
            ->get();

        return ['by_method' => $rows->map(fn ($r) => ['method' => $r->method, 'total' => (int) $r->total])->all()];
    }

    /** @param  array<string, mixed>  $period */
    public function expenses(Shop $shop, array $period): int
    {
        return (int) DB::table('expenses')->where('shop_id', $shop->id)
            ->whereBetween('expense_date', [$period['from'], $period['to']])->sum('amount');
    }

    /** @param  array<string, mixed>  $period */
    public function purchases(Shop $shop, array $period): int
    {
        return (int) DB::table('purchases')->where('shop_id', $shop->id)->where('status', 'received')
            ->whereBetween('purchase_date', [$period['from'], $period['to']])->sum('total');
    }

    /** @param  array<string, mixed>  $period */
    private function soldItems(Shop $shop, array $period)
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.shop_id', $shop->id)->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$period['start'], $period['end']])
            ->get([
                'sale_items.product_id', 'sale_items.product_name', 'sale_items.quantity', 'sale_items.unit_conversion',
                'sale_items.historical_cost', 'sale_items.line_total', 'sales.created_at as sold_at', 'categories.name as category',
            ]);
    }

    /** @param  array<string, mixed>  $period */
    private function refundedItems(Shop $shop, array $period)
    {
        return DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->join('sale_items', 'sale_items.id', '=', 'refund_items.sale_item_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('refunds.shop_id', $shop->id)
            ->whereBetween('refunds.created_at', [$period['start'], $period['end']])
            ->get([
                'refund_items.quantity', 'refund_items.amount', 'refund_items.restock', 'sale_items.historical_cost',
                'sale_items.unit_conversion', 'sale_items.product_id', 'sale_items.product_name',
                'categories.name as category', 'refunds.created_at as refunded_at',
            ]);
    }

    /**
     * @param  array<int, array{sales: int, count: int}>  $byCashier
     * @return array<int, array<string, mixed>>
     */
    private function cashierNames(array $byCashier): array
    {
        if ($byCashier === []) {
            return [];
        }

        $names = DB::table('users')->whereIn('id', array_keys($byCashier))->pluck('name', 'id');

        return collect($byCashier)->map(fn ($row, $id) => [
            'cashier_id' => $id,
            'name' => $names[$id] ?? 'Unknown',
            'sales_count' => $row['count'],
            'gross_sales' => $row['sales'],
        ])->sortByDesc('gross_sales')->values()->all();
    }
}
