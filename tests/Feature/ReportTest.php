<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-morning in Kampala, so "today" is unambiguous.
        $this->travelTo(Carbon::parse('2026-09-21 09:00:00', 'Africa/Kampala'));
    }

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function sell($user, $shop, Product $product, int $qty, array $extra = [], string $method = 'CASH')
    {
        return $this->api($user, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => $qty] + ($extra['line'] ?? [])],
            'payments' => $extra['payments'] ?? [['method' => $method, 'amount' => $qty * $product->selling_price - ($extra['line']['discount'] ?? 0)]],
        ] + array_diff_key($extra, ['line' => 1, 'payments' => 1]))->assertCreated();
    }

    private function overview($user, $shop, string $query = '')
    {
        return $this->api($user, $shop)->getJson('/api/reports/overview'.$query)->assertOk();
    }

    public function test_profit_is_sales_minus_refunds_minus_what_the_goods_cost_minus_expenses(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);

        $a = $this->sell($owner, $shop, $product, 5)->json();                                   // 5,000, cost 3,000
        $this->sell($owner, $shop, $product, 2, ['line' => ['discount' => 200]]);               // 1,800, cost 1,200
        Expense::create(['shop_id' => $shop->id, 'category' => 'Rent', 'amount' => 700, 'recorded_by' => $owner->id, 'expense_date' => '2026-09-21']);
        $this->api($owner, $shop)->postJson("/api/sales/{$a['id']}/refund", [
            'lines' => [['sale_item_id' => $a['items'][0]['id'], 'quantity' => 1, 'restock' => true]], 'method' => 'CASH', 'reason' => 'Changed mind',
        ])->assertCreated();                                                                    // -1,000, cost back 600

        $this->overview($owner, $shop)
            ->assertJsonPath('totals.gross_sales', 6800)
            ->assertJsonPath('totals.discounts', 200)
            ->assertJsonPath('totals.refunds', 1000)
            ->assertJsonPath('totals.net_sales', 5800)
            ->assertJsonPath('totals.cost_of_goods', 3600)
            ->assertJsonPath('totals.gross_profit', 2200)
            ->assertJsonPath('totals.expenses', 700)
            ->assertJsonPath('totals.net_profit', 1500)
            ->assertJsonPath('totals.sales_count', 2)
            ->assertJsonPath('totals.average_sale', 3400);
    }

    public function test_a_refund_that_is_not_restocked_keeps_the_cost_of_the_lost_goods(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);
        $sale = $this->sell($owner, $shop, $product, 5)->json();

        $this->api($owner, $shop)->postJson("/api/sales/{$sale['id']}/refund", [
            'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'restock' => false]], 'method' => 'CASH', 'reason' => 'Broken',
        ])->assertCreated();

        // Sold 5,000, gave back 1,000, and the broken item's 600 cost is gone for good.
        $this->overview($owner, $shop)->assertJsonPath('totals.cost_of_goods', 3000)->assertJsonPath('totals.gross_profit', 1000);
    }

    public function test_voided_sales_are_left_out(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);
        $this->sell($owner, $shop, $product, 3);
        $voided = $this->sell($owner, $shop, $product, 4)->json('id');

        $this->api($owner, $shop)->postJson("/api/sales/{$voided}/void", ['reason' => 'Entered twice'])->assertOk();

        $this->overview($owner, $shop)->assertJsonPath('totals.gross_sales', 3000)->assertJsonPath('totals.sales_count', 1)->assertJsonPath('totals.cost_of_goods', 1800);
    }

    public function test_profit_uses_the_cost_at_the_time_of_sale_not_todays_cost(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);
        $this->sell($owner, $shop, $product, 5);

        $product->update(['current_cost' => 900]);

        $this->overview($owner, $shop)->assertJsonPath('totals.cost_of_goods', 3000)->assertJsonPath('totals.gross_profit', 2000);
    }

    public function test_selling_by_the_carton_costs_and_counts_in_the_right_units(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);
        ProductUnit::create(['product_id' => $product->id, 'unit_name' => 'carton', 'conversion_to_base_unit' => 12, 'selling_price' => 11000]);

        $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit' => 'carton']],
            'payments' => [['method' => 'CASH', 'amount' => 22000]],
        ])->assertCreated();

        // 2 cartons = 24 pieces at 600 = 14,400 cost.
        $this->overview($owner, $shop)->assertJsonPath('totals.cost_of_goods', 14400)->assertJsonPath('totals.gross_profit', 7600);

        $this->api($owner, $shop)->getJson('/api/reports/products')
            ->assertJsonPath('products.0.quantity', 24)->assertJsonPath('products.0.revenue', 22000)->assertJsonPath('products.0.profit', 7600);
    }

    public function test_payments_are_split_by_method_and_credit_sales_are_shown_separately(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Mama Rose']);

        $this->sell($owner, $shop, $product, 3, [], 'CASH');
        $this->sell($owner, $shop, $product, 2, [], 'MOBILE_MONEY');
        $this->sell($owner, $shop, $product, 4, ['customer_id' => $customer->id, 'payments' => [['method' => 'CASH', 'amount' => 1000]]]);

        $split = collect($this->overview($owner, $shop)->json('payments.by_method'))->pluck('total', 'method');
        $this->assertSame(4000, $split['CASH']);
        $this->assertSame(2000, $split['MOBILE_MONEY']);
        $this->overview($owner, $shop)->assertJsonPath('payments.on_credit', 3000);
    }

    public function test_money_taken_for_a_voided_sale_is_not_in_the_payment_split(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);

        $this->sell($owner, $shop, $product, 3, [], 'CASH');
        $voided = $this->sell($owner, $shop, $product, 4, [], 'MOBILE_MONEY')->json('id');
        $this->api($owner, $shop)->postJson("/api/sales/{$voided}/void", ['reason' => 'Entered twice'])->assertOk();

        $this->assertSame(['CASH'], array_column($this->overview($owner, $shop)->json('payments.by_method'), 'method'));
    }

    public function test_a_balance_the_shop_owes_a_customer_is_not_counted_as_money_owed_to_the_shop(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Overpaid']);
        CustomerLedgerEntry::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'type' => 'PAYMENT', 'amount' => -500, 'recorded_by' => $owner->id]);

        $this->api($owner, $shop)->getJson('/api/reports/balances')->assertJsonPath('receivable.total', 0)->assertJsonPath('receivable.count', 0);
    }

    public function test_the_period_uses_the_shops_own_days_not_utc(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);

        // 21:30 UTC on the 20th is 00:30 on the 21st in Kampala.
        $this->sell($owner, $shop, $product, 1, ['client_created_at' => '2026-09-20T21:30:00Z']);
        // 20:30 UTC on the 20th is 23:30 on the 20th in Kampala.
        $this->sell($owner, $shop, $product, 2, ['client_created_at' => '2026-09-20T20:30:00Z']);

        $this->overview($owner, $shop, '?from=2026-09-21&to=2026-09-21')->assertJsonPath('totals.gross_sales', 1000);
        $this->overview($owner, $shop, '?from=2026-09-20&to=2026-09-20')->assertJsonPath('totals.gross_sales', 2000);

        $daily = $this->overview($owner, $shop, '?from=2026-09-19&to=2026-09-21')->json('daily');
        $this->assertSame(['2026-09-19', '2026-09-20', '2026-09-21'], array_column($daily, 'date'));
        $this->assertSame([0, 2000, 1000], array_column($daily, 'net_sales'));
    }

    public function test_purchases_and_expenses_count_on_their_own_dates(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Wholesale']);
        $product = $this->productWithStock($shop, $owner, stock: 0);

        Expense::create(['shop_id' => $shop->id, 'category' => 'Rent', 'amount' => 500, 'recorded_by' => $owner->id, 'expense_date' => '2026-09-10']);
        Expense::create(['shop_id' => $shop->id, 'category' => 'Rent', 'amount' => 300, 'recorded_by' => $owner->id, 'expense_date' => '2026-09-21']);
        $this->api($owner, $shop)->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'purchase_date' => '2026-09-15', 'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1000]],
        ])->assertCreated();

        $this->overview($owner, $shop)->assertJsonPath('totals.expenses', 800)->assertJsonPath('totals.purchases', 10000);
        $this->overview($owner, $shop, '?from=2026-09-16&to=2026-09-21')->assertJsonPath('totals.expenses', 300)->assertJsonPath('totals.purchases', 0);
    }

    public function test_the_product_report_ranks_by_revenue_and_nets_off_refunds(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $big = $this->productWithStock($shop, $owner, price: 5000, cost: 3000, stock: 50, attributes: ['name' => 'Big']);
        $small = $this->productWithStock($shop, $owner, price: 500, cost: 300, stock: 50, attributes: ['name' => 'Small']);

        $sale = $this->sell($owner, $shop, $big, 4)->json();
        $this->sell($owner, $shop, $small, 10);
        $this->api($owner, $shop)->postJson("/api/sales/{$sale['id']}/refund", [
            'lines' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'restock' => true]], 'method' => 'CASH', 'reason' => 'Faulty',
        ])->assertCreated();

        $rows = $this->api($owner, $shop)->getJson('/api/reports/products')->assertOk()->json('products');

        $this->assertSame(['Big', 'Small'], array_column($rows, 'name'));
        $this->assertSame([3, 10], array_column($rows, 'quantity'));
        $this->assertSame([15000, 5000], array_column($rows, 'revenue'));
        $this->assertSame([6000, 2000], array_column($rows, 'profit'));
        $this->assertSame([40, 40], array_column($rows, 'margin'));
    }

    public function test_categories_and_cashiers_are_broken_down(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);

        $this->sell($owner, $shop, $product, 2);
        $this->sell($cashier, $shop, $product, 3);

        $overview = $this->overview($owner, $shop);
        $this->assertSame(['Uncategorised'], array_column($overview->json('by_category'), 'category'));
        $this->assertSame(5000, $overview->json('by_category.0.net_sales'));
        $this->assertSame([3000, 2000], array_column($overview->json('by_cashier'), 'gross_sales'));
    }

    public function test_another_shops_figures_never_leak_in(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $mine = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);
        $theirs = $this->productWithStock($otherShop, $other, price: 9000, cost: 1, stock: 100);

        $this->sell($owner, $shop, $mine, 1);
        $this->sell($other, $otherShop, $theirs, 5);

        $this->overview($owner, $shop)->assertJsonPath('totals.gross_sales', 1000)->assertJsonPath('totals.sales_count', 1);
        $this->api($owner, $shop)->getJson('/api/reports/products')->assertJsonCount(1, 'products');
    }

    public function test_balances_list_who_owes_the_shop_and_whom_the_shop_owes(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 100);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Mama Rose']);
        Customer::create(['shop_id' => $shop->id, 'name' => 'Settled']);
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Wholesale']);

        $this->sell($owner, $shop, $product, 4, ['customer_id' => $customer->id, 'payments' => []]);
        $this->api($owner, $shop)->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 500]],
        ])->assertCreated();

        $this->api($owner, $shop)->getJson('/api/reports/balances')
            ->assertJsonPath('receivable.total', 4000)->assertJsonPath('receivable.count', 1)->assertJsonPath('receivable.rows.0.name', 'Mama Rose')
            ->assertJsonPath('payable.total', 5000)->assertJsonPath('payable.rows.0.name', 'Wholesale');
    }

    public function test_bad_periods_are_refused(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->api($owner, $shop)->getJson('/api/reports/overview?from=2026-09-21&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->api($owner, $shop)->getJson('/api/reports/overview?from=2024-01-01&to=2026-09-21')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->api($owner, $shop)->getJson('/api/reports/overview?from=yesterday')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_cashiers_cannot_open_reports(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);

        foreach (['overview', 'products', 'balances'] as $report) {
            $this->api($cashier, $shop)->getJson("/api/reports/{$report}")->assertForbidden();
        }
    }
}
