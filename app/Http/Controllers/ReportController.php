<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\Reports\DashboardReport;
use App\Services\Reports\DebtReport;
use App\Services\Reports\SalesAnalytics;
use App\Services\Reports\StockReport;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reports are read-only views over what has already been recorded. The route
 * groups decide who may open each one; the owner-only cost and profit figures
 * are never computed for anyone else.
 */
class ReportController extends Controller
{
    public function dashboard(Request $request, DashboardReport $dashboard): JsonResponse
    {
        return response()->json($dashboard->for($request->attributes->get('shop'), $request->user(), $request->attributes->get('shopRole')));
    }

    public function sales(Request $request, SalesAnalytics $analytics): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $range = ReportRange::fromRequest($request, $shop, default: 'month');

        $products = collect($analytics->products($shop, $range))->sortByDesc('revenue')->take(15)->values()
            ->map(fn ($p) => ['product_id' => $p['product_id'], 'name' => $p['name'], 'quantity' => $p['quantity'], 'revenue' => $p['revenue']]);

        return response()->json([
            'range' => $range->toArray(),
            'summary' => $analytics->summary($shop, $range),
            'daily' => $analytics->daily($shop, $range),
            'payment_methods' => $analytics->paymentMethods($shop, $range),
            'cashiers' => $analytics->cashiers($shop, $range),
            'products' => $products,
        ]);
    }

    public function profit(Request $request, SalesAnalytics $analytics): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $range = ReportRange::fromRequest($request, $shop, default: 'month');

        $summary = $analytics->summary($shop, $range);
        $daily = $analytics->daily($shop, $range, withCost: true);
        $expensesByDay = $analytics->expensesByDay($shop, $range);
        $expenses = $analytics->expensesByCategory($shop, $range);

        $cost = array_sum(array_column($daily, 'cost_of_goods'));
        $expenseTotal = array_sum(array_column($expenses, 'amount'));
        $gross = $summary['net_sales'] - $cost;

        $products = collect($analytics->products($shop, $range))
            ->map(fn ($p) => $p + ['profit' => $p['revenue'] - $p['cost'], 'margin' => SalesAnalytics::margin($p['revenue'] - $p['cost'], $p['revenue'])])
            ->sortByDesc('profit')->take(50)->values();

        return response()->json([
            'range' => $range->toArray(),
            'summary' => [
                'net_sales' => $summary['net_sales'],
                'cost_of_goods' => $cost,
                'gross_profit' => $gross,
                'margin' => SalesAnalytics::margin($gross, $summary['net_sales']),
                'expenses' => $expenseTotal,
                'operating_profit' => $gross - $expenseTotal,
            ],
            'daily' => array_map(function (array $d) use ($expensesByDay) {
                $expense = $expensesByDay[$d['date']] ?? 0;
                $profit = $d['net_sales'] - $d['cost_of_goods'];

                return [
                    'date' => $d['date'], 'net_sales' => $d['net_sales'], 'cost_of_goods' => $d['cost_of_goods'],
                    'gross_profit' => $profit, 'expenses' => $expense, 'operating_profit' => $profit - $expense,
                ];
            }, $daily),
            'expenses' => $expenses,
            'products' => $products,
        ]);
    }

    public function stock(Request $request, StockReport $stock): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['out', 'low', 'ok'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($stock->report(
            $request->attributes->get('shop'),
            withValue: $request->attributes->get('shopRole') === Role::Owner,
            status: $data['status'] ?? null,
            search: isset($data['q']) ? trim($data['q']) : null,
            page: (int) ($data['page'] ?? 1),
        ));
    }

    public function debt(Request $request, DebtReport $debt): JsonResponse
    {
        return response()->json($debt->report($request->attributes->get('shop')));
    }
}
