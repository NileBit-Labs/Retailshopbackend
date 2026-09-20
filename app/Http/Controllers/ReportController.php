<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner/manager only (see routes): profit and cost figures are not for the till. */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function overview(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        return response()->json($this->reports->overview($shop, $this->period($request)));
    }

    public function products(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $period = $this->period($request);

        return response()->json([
            'period' => ['from' => $period['from'], 'to' => $period['to'], 'timezone' => $period['tz']],
            'products' => $this->reports->products($shop, $period, min($request->integer('limit', 100), 500)),
        ]);
    }

    public function balances(Request $request): JsonResponse
    {
        return response()->json($this->reports->balances($request->attributes->get('shop')));
    }

    /** @return array<string, mixed> */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->reports->period($request->attributes->get('shop'), $data['from'] ?? null, $data['to'] ?? null);
    }
}
