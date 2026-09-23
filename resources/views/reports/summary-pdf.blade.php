<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 20mm 14mm 18mm; }
        * { box-sizing: border-box; }
        body { color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.45; }
        h1, h2, h3, p { margin: 0; }
        .brand { border-bottom: 2px solid #0d7b66; padding-bottom: 12px; margin-bottom: 16px; }
        .brand table, .metrics { width: 100%; border-collapse: collapse; }
        .brand-name { color: #0d7b66; font-size: 18px; font-weight: bold; }
        .edition { color: #64748b; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; }
        .shop { font-size: 14px; font-weight: bold; text-align: right; }
        .muted { color: #64748b; }
        h2 { color: #0d7b66; font-size: 11px; margin: 16px 0 7px; }
        h3 { font-size: 9px; margin: 0 0 4px; }
        .metrics td { width: 25%; padding: 8px; border: 1px solid #d9e3df; vertical-align: top; }
        .metric-label { color: #64748b; font-size: 8px; }
        .metric-value { color: #102a25; font-size: 12px; font-weight: bold; margin-top: 2px; }
        .cols { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin-left: -8px; }
        .cols td { width: 50%; vertical-align: top; }
        .box { border: 1px solid #d9e3df; padding: 9px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #eef6f3; color: #48615a; font-size: 8px; text-align: left; text-transform: uppercase; }
        table.data th, table.data td { border-bottom: 1px solid #d9e3df; padding: 5px; }
        table.data .num { text-align: right; white-space: nowrap; }
        .warn { color: #b45309; }
        .footer { position: fixed; bottom: -11mm; left: 0; right: 0; color: #64748b; font-size: 8px; }
        .footer .right { float: right; }
        .page:after { content: counter(page) ' of ' counter(pages); }
    </style>
</head>
<body>
    <div class="brand">
        <table><tr><td>
            <div class="brand-name">NileBit POS</div>
            <div class="edition">NileBit POS for Retail</div>
            <p class="muted">Business summary · {{ $range->from->format('j M Y') }} – {{ $range->to->format('j M Y') }}</p>
        </td><td class="shop">
            {{ $shop->name }}<br><span class="muted" style="font-size:8px;font-weight:normal">Generated {{ $generatedAt->format('j M Y, H:i T') }}</span>
        </td></tr></table>
    </div>

    <h2>Executive summary</h2>
    <table class="metrics"><tr>
        <td><div class="metric-label">Net sales</div><div class="metric-value">UGX {{ number_format($report['sales']['summary']['net_sales']) }}</div><div class="muted">{{ $report['sales']['summary']['sales_count'] }} sale(s)</div></td>
        <td><div class="metric-label">Customer credit outstanding</div><div class="metric-value">UGX {{ number_format($report['debt']['summary']['total_owed']) }}</div><div class="muted">{{ $report['debt']['summary']['customers_owing'] }} customer(s) owing</div></td>
        <td><div class="metric-label">Stock warning</div><div class="metric-value">{{ $report['stock']['summary']['out'] + $report['stock']['summary']['low'] }}</div><div class="muted">{{ $report['stock']['summary']['out'] }} out · {{ $report['stock']['summary']['low'] }} low</div></td>
        @if(isset($report['profit']))
            <td><div class="metric-label">Profit after expenses</div><div class="metric-value">UGX {{ number_format($report['profit']['summary']['operating_profit']) }}</div><div class="muted">Owner report</div></td>
        @else
            <td><div class="metric-label">Expenses</div><div class="metric-value">UGX {{ number_format($report['expenses']['total']) }}</div><div class="muted">Recorded this period</div></td>
        @endif
    </tr></table>

    <table class="cols"><tr><td>
        <h2>Sales</h2><div class="box">
            <table class="data"><tbody>
                <tr><td>Gross sales</td><td class="num">UGX {{ number_format($report['sales']['summary']['gross_sales']) }}</td></tr>
                <tr><td>Refunds</td><td class="num">UGX {{ number_format($report['sales']['summary']['refunds']) }}</td></tr>
                <tr><td><strong>Net sales</strong></td><td class="num"><strong>UGX {{ number_format($report['sales']['summary']['net_sales']) }}</strong></td></tr>
                @foreach(array_slice($report['sales']['payment_methods'], 0, 4) as $method)<tr><td>{{ ucwords(strtolower(str_replace('_', ' ', $method['method']))) }}</td><td class="num">UGX {{ number_format($method['amount']) }}</td></tr>@endforeach
            </tbody></table>
        </div>
    </td><td>
        <h2>Expenses</h2><div class="box">
            @if($report['expenses']['categories'])<table class="data"><tbody>@foreach(array_slice($report['expenses']['categories'], 0, 5) as $expense)<tr><td>{{ $expense['category'] }}</td><td class="num">UGX {{ number_format($expense['amount']) }}</td></tr>@endforeach</tbody></table>
            @else <p class="muted">No expenses recorded in this period.</p> @endif
        </div>
    </td></tr></table>

    @if($report['sales']['products'])
        <h2>Products</h2><table class="data"><thead><tr><th>Best sellers</th><th class="num">Quantity</th><th class="num">Revenue</th></tr></thead><tbody>
        @foreach(array_slice($report['sales']['products'], 0, 8) as $product)<tr><td>{{ $product['name'] }}</td><td class="num">{{ $product['quantity'] }}</td><td class="num">UGX {{ number_format($product['revenue']) }}</td></tr>@endforeach
        </tbody></table>
    @endif

    @if($report['stock']['summary']['out'] || $report['stock']['summary']['low'])
        <h2>Stock to act on</h2><table class="data"><thead><tr><th>Product</th><th class="num">On hand</th><th>Status</th></tr></thead><tbody>
        @foreach(array_slice(array_filter($report['stock']['page']['data'], fn($item) => $item['status'] !== 'ok'), 0, 10) as $item)<tr><td>{{ $item['name'] }}</td><td class="num">{{ $item['stock'] }} {{ $item['unit'] }}</td><td class="warn">{{ ucfirst($item['status']) }}</td></tr>@endforeach
        </tbody></table>
    @endif

    @if($report['debt']['customers'])
        <h2>Customers owing</h2><table class="data"><thead><tr><th>Customer</th><th class="num">Owes</th><th class="num">Overdue</th></tr></thead><tbody>
        @foreach(array_slice($report['debt']['customers'], 0, 8) as $customer)<tr><td>{{ $customer['name'] }}</td><td class="num">UGX {{ number_format($customer['balance']) }}</td><td class="num">{{ $customer['overdue'] ? 'UGX '.number_format($customer['overdue']) : '—' }}</td></tr>@endforeach
        </tbody></table>
    @endif

    @if($report['suppliers']['total_owed'] || $report['suppliers']['bought'])
        <h2>Suppliers</h2><div class="box"><p>Total owed to suppliers: <strong>UGX {{ number_format($report['suppliers']['total_owed']) }}</strong> · Purchases this period: <strong>UGX {{ number_format($report['suppliers']['bought']) }}</strong></p></div>
    @endif

    @if(isset($report['profit']))
        <h2>Owner-only profit</h2><table class="data"><tbody>
            <tr><td>Cost of goods sold</td><td class="num">UGX {{ number_format($report['profit']['summary']['cost_of_goods']) }}</td></tr>
            <tr><td>Gross profit</td><td class="num">UGX {{ number_format($report['profit']['summary']['gross_profit']) }}</td></tr>
            <tr><td>Margin</td><td class="num">{{ $report['profit']['summary']['margin'] === null ? '—' : $report['profit']['summary']['margin'].'%' }}</td></tr>
            <tr><td><strong>Profit after expenses</strong></td><td class="num"><strong>UGX {{ number_format($report['profit']['summary']['operating_profit']) }}</strong></td></tr>
        </tbody></table>
    @endif

    <div class="footer">Generated by NileBit POS for Retail · {{ $shop->name }} · {{ $generatedAt->format('j M Y, H:i T') }}<span class="right">Page <span class="page"></span></span></div>
</body>
</html>
