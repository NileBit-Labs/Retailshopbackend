<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-21 09:00:00', 'Africa/Kampala'));
    }

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function sell($user, $shop, $product, int $qty, array $extra = [])
    {
        return $this->api($user, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'payments' => [['method' => 'CASH', 'amount' => $qty * $product->selling_price]],
        ] + $extra)->assertCreated();
    }

    public function test_an_empty_shop_shows_zeros_and_an_unfinished_setup(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->api($owner, $shop)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('scope', 'shop')
            ->assertJsonPath('today.net_sales', 0)->assertJsonPath('today.sales_count', 0)
            ->assertJsonPath('low_stock.count', 0)->assertJsonPath('recent', [])
            ->assertJsonPath('setup.has_products', false)->assertJsonPath('setup.has_sales', false)
            ->assertJsonCount(7, 'week');
    }

    public function test_the_manager_sees_todays_figures_the_week_and_what_needs_attention(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 20, attributes: ['name' => 'Sugar', 'low_stock_threshold' => 15]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Mama Rose']);
        // Not low: plenty above its warning level. And one that never warns (level 0), however little is left.
        $this->productWithStock($shop, $owner, stock: 50, attributes: ['name' => 'Plenty', 'low_stock_threshold' => 5]);
        $this->productWithStock($shop, $owner, stock: 1, attributes: ['name' => 'No warning', 'low_stock_threshold' => 0]);

        $this->sell($owner, $shop, $product, 3);
        $this->sell($owner, $shop, $product, 2, ['client_created_at' => '2026-09-18T10:00:00Z']);
        $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [], 'customer_id' => $customer->id,
        ])->assertCreated();
        Expense::create(['shop_id' => $shop->id, 'category' => 'Transport', 'amount' => 400, 'recorded_by' => $owner->id, 'expense_date' => '2026-09-21']);

        $response = $this->api($owner, $shop)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('today.net_sales', 4000)->assertJsonPath('today.sales_count', 2)
            ->assertJsonPath('today.gross_profit', 1600)->assertJsonPath('today.expenses', 400)
            ->assertJsonPath('owed_by_customers', 1000)
            ->assertJsonPath('stock_value', (14 + 50 + 1) * 600)
            ->assertJsonPath('low_stock.count', 1)->assertJsonPath('low_stock.items.0.name', 'Sugar')->assertJsonPath('low_stock.items.0.stock', 14)
            ->assertJsonPath('top_products.0.name', 'Sugar')->assertJsonPath('top_products.0.quantity', 6)
            ->assertJsonPath('setup.has_products', true)->assertJsonPath('setup.has_sales', true);

        $week = collect($response->json('week'))->pluck('net_sales', 'date');
        $this->assertSame(7, $week->count());
        $this->assertSame(2000, $week['2026-09-18']);
        $this->assertSame(4000, $week['2026-09-21']);
    }

    public function test_sales_older_than_the_week_are_not_in_the_week(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 20);

        $this->sell($owner, $shop, $product, 3, ['client_created_at' => '2026-09-14T10:00:00Z']);

        $response = $this->api($owner, $shop)->getJson('/api/dashboard')->assertOk();

        $this->assertSame(0, array_sum(array_column($response->json('week'), 'net_sales')));
        $this->assertSame([], $response->json('top_products'));
    }

    public function test_recent_activity_mixes_sales_purchases_and_expenses_newest_first(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 20);

        $this->sell($owner, $shop, $product, 1);
        $this->travel(5)->minutes();
        Expense::create(['shop_id' => $shop->id, 'category' => 'Rent', 'amount' => 900, 'recorded_by' => $owner->id, 'expense_date' => '2026-09-21']);

        $recent = $this->api($owner, $shop)->getJson('/api/dashboard')->json('recent');

        $this->assertSame(['expense', 'sale'], array_column($recent, 'type'));
        $this->assertSame(-900, $recent[0]['amount']);
        $this->assertStringStartsWith('Sale S-', $recent[1]['title']);
    }

    public function test_a_cashier_sees_only_their_own_sales_and_no_profit_or_balances(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 50);

        $this->sell($owner, $shop, $product, 5);
        $this->sell($cashier, $shop, $product, 2);
        Expense::create(['shop_id' => $shop->id, 'category' => 'Rent', 'amount' => 900, 'recorded_by' => $owner->id, 'expense_date' => '2026-09-21']);

        $response = $this->api($cashier, $shop)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('scope', 'own')
            ->assertJsonPath('today.net_sales', 2000)->assertJsonPath('today.sales_count', 1)
            ->assertJsonPath('today.gross_profit', null)->assertJsonPath('today.expenses', null);

        foreach (['top_products', 'low_stock', 'owed_by_customers', 'owed_to_suppliers', 'stock_value', 'recent', 'setup'] as $key) {
            $response->assertJsonMissingPath($key);
        }

        $this->assertSame(2000, array_sum(array_column($response->json('week'), 'net_sales')));
    }

    public function test_the_dashboard_is_scoped_to_the_shop(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $theirs = $this->productWithStock($otherShop, $other, price: 9000, cost: 1, stock: 100);
        $this->sell($other, $otherShop, $theirs, 5);

        $this->api($owner, $shop)->getJson('/api/dashboard')->assertJsonPath('today.net_sales', 0)->assertJsonPath('recent', []);
        $this->api($owner, $otherShop)->getJson('/api/dashboard')->assertForbidden();
    }
}
