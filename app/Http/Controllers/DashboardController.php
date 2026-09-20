<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Purchase;
use App\Models\Shop;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The home screen. Owners and managers get the whole picture; a cashier gets
 * only their own sales today and this week - no costs, profit or balances.
 */
class DashboardController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function show(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $isManager = in_array($request->attributes->get('shopRole'), [Role::Owner, Role::Manager], true);
        $cashierId = $isManager ? null : $request->user()->id;

        $today = $this->reports->period($shop, null, null);
        $todayOnly = $this->reports->period($shop, $today['to'], $today['to']);
        $week = $this->reports->period($shop, Carbon::parse($today['to'], $today['tz'])->subDays(6)->toDateString(), $today['to']);

        $weekOverview = $this->reports->overview($shop, $week, $cashierId);
        $todayOverview = $this->reports->overview($shop, $todayOnly, $cashierId);
        $totals = $todayOverview['totals'];

        $payload = [
            'scope' => $isManager ? 'shop' : 'own',
            'date' => $today['to'],
            'today' => [
                'net_sales' => $totals['net_sales'],
                'gross_sales' => $totals['gross_sales'],
                'sales_count' => $totals['sales_count'],
                'gross_profit' => $isManager ? $totals['gross_profit'] : null,
                'expenses' => $isManager ? $totals['expenses'] : null,
            ],
            'week' => collect($weekOverview['daily'])->map(fn ($d) => [
                'date' => $d['date'], 'net_sales' => $d['net_sales'], 'sales_count' => $d['sales_count'],
            ])->all(),
            'payments' => $todayOverview['payments'],
        ];

        if (! $isManager) {
            return response()->json($payload);
        }

        $balances = $this->reports->balances($shop);

        return response()->json($payload + [
            'top_products' => array_slice($this->reports->products($shop, $week, 5), 0, 5),
            'low_stock' => $this->lowStock($shop),
            'owed_by_customers' => $balances['receivable']['total'],
            'owed_to_suppliers' => $balances['payable']['total'],
            'stock_value' => $this->stockValue($shop),
            'recent' => $this->recent($shop),
            'setup' => [
                'has_products' => DB::table('products')->where('shop_id', $shop->id)->exists(),
                'has_sales' => DB::table('sales')->where('shop_id', $shop->id)->exists(),
            ],
        ]);
    }

    /** @return array{count: int, items: array<int, array<string, mixed>>} */
    private function lowStock(Shop $shop): array
    {
        $stock = '(select coalesce(sum(quantity_delta), 0) from stock_movements where stock_movements.product_id = products.id)';

        $rows = DB::table('products')->where('shop_id', $shop->id)->where('status', 'active')
            ->where('low_stock_threshold', '>', 0)
            ->whereRaw("$stock <= low_stock_threshold")
            ->selectRaw("id, name, base_unit, low_stock_threshold, $stock as stock")
            ->orderByRaw("$stock / low_stock_threshold")
            ->get();

        return [
            'count' => $rows->count(),
            'items' => $rows->take(8)->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'unit' => $r->base_unit,
                'stock' => round((float) $r->stock, 3), 'threshold' => (float) $r->low_stock_threshold,
            ])->all(),
        ];
    }

    private function stockValue(Shop $shop): int
    {
        return (int) DB::table('products')
            ->join(DB::raw('(select product_id, sum(quantity_delta) as stock from stock_movements group by product_id) s'), 's.product_id', '=', 'products.id')
            ->where('products.shop_id', $shop->id)->where('products.status', 'active')->where('s.stock', '>', 0)
            ->selectRaw('coalesce(sum(s.stock * products.current_cost), 0) as value')
            ->value('value');
    }

    /** @return array<int, array<string, mixed>> */
    private function recent(Shop $shop): array
    {
        $sales = DB::table('sales')->join('users', 'users.id', '=', 'sales.cashier_id')
            ->where('sales.shop_id', $shop->id)->where('sales.status', 'completed')
            ->orderByDesc('sales.created_at')->limit(8)
            ->get(['sales.id', 'sales.sale_number', 'sales.total', 'sales.created_at', 'users.name'])
            ->map(fn ($r) => ['type' => 'sale', 'title' => "Sale {$r->sale_number}", 'amount' => (int) $r->total, 'by' => $r->name, 'at' => $r->created_at, 'link' => "/sales/{$r->id}"]);

        $refunds = DB::table('refunds')->join('users', 'users.id', '=', 'refunds.approved_by')->join('sales', 'sales.id', '=', 'refunds.sale_id')
            ->where('refunds.shop_id', $shop->id)->orderByDesc('refunds.created_at')->limit(4)
            ->get(['sales.id as sale_id', 'sales.sale_number', 'refunds.total_refund', 'refunds.created_at', 'users.name'])
            ->map(fn ($r) => ['type' => 'refund', 'title' => "Refund on {$r->sale_number}", 'amount' => -(int) $r->total_refund, 'by' => $r->name, 'at' => $r->created_at, 'link' => "/sales/{$r->sale_id}"]);

        $purchases = Purchase::where('shop_id', $shop->id)->where('status', 'received')->with('receiver:id,name')->orderByDesc('id')->limit(4)->get()
            ->map(fn ($p) => ['type' => 'purchase', 'title' => "Stock received {$p->purchase_number}", 'amount' => $p->total, 'by' => $p->receiver->name, 'at' => $p->created_at->toDateTimeString(), 'link' => "/purchases/{$p->id}"]);

        $expenses = DB::table('expenses')->join('users', 'users.id', '=', 'expenses.recorded_by')
            ->where('expenses.shop_id', $shop->id)->orderByDesc('expenses.created_at')->limit(4)
            ->get(['expenses.category', 'expenses.amount', 'expenses.created_at', 'users.name'])
            ->map(fn ($r) => ['type' => 'expense', 'title' => "Expense · {$r->category}", 'amount' => -(int) $r->amount, 'by' => $r->name, 'at' => $r->created_at, 'link' => '/expenses']);

        return collect()->concat($sales)->concat($refunds)->concat($purchases)->concat($expenses)
            ->sortByDesc('at')->take(10)->values()
            ->map(fn ($e) => ['at' => Carbon::parse($e['at'], 'UTC')->toIso8601String()] + $e)->all();
    }
}
