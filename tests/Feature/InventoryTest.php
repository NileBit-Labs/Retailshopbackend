<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserShopRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_create_a_product_with_opening_stock_and_sell_units(): void
    {
        [$owner, $shop] = $this->ownerAndShop();

        $category = $this->actingAs($owner, 'sanctum')->withHeader('X-Shop-Id', $shop->id)
            ->postJson('/api/v1/categories', ['name' => 'Drinks'])->assertCreated();

        $response = $this->actingAs($owner, 'sanctum')->withHeader('X-Shop-Id', $shop->id)
            ->postJson('/api/v1/products', [
                'category_id' => $category->json('id'),
                'name' => 'Soda',
                'barcode' => '1234567890123',
                'base_unit' => 'piece',
                'selling_price' => 1500,
                'low_stock_threshold' => 5,
                'units' => [[
                    'unit_name' => 'crate',
                    'conversion_to_base_unit' => 24,
                    'selling_price' => 33000,
                ]],
                'opening_stock' => ['quantity' => 24, 'unit_cost' => 1000],
            ]);

        $response->assertCreated()
            ->assertJsonPath('stock', 24)
            ->assertJsonPath('is_low_stock', false)
            ->assertJsonPath('current_cost', 1000)
            ->assertJsonPath('units.0.unit_name', 'crate');

        $productId = $response->json('id');
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'movement_type' => MovementType::OpeningStock->value,
            'quantity_delta' => 24,
            'unit_cost' => 1000,
        ]);
    }

    public function test_only_an_owner_can_record_damage_and_stock_cannot_go_negative(): void
    {
        [$owner, $shop] = $this->ownerAndShop();
        $manager = User::factory()->create(['organization_id' => $shop->organization_id]);
        UserShopRole::create(['user_id' => $manager->id, 'shop_id' => $shop->id, 'role' => Role::Manager]);
        $product = $this->productWithStock($owner, $shop, 3);

        $this->actingAs($manager, 'sanctum')->withHeader('X-Shop-Id', $shop->id)
            ->postJson('/api/v1/inventory/damage', [
                'product_id' => $product->id,
                'quantity' => 1,
                'reason' => 'Broken bottle',
            ])->assertForbidden();

        $this->actingAs($owner, 'sanctum')->withHeader('X-Shop-Id', $shop->id)
            ->postJson('/api/v1/inventory/damage', [
                'product_id' => $product->id,
                'quantity' => 4,
                'reason' => 'Broken bottle',
            ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_inventory_and_movement_history_are_scoped_to_the_selected_shop(): void
    {
        [$owner, $shop] = $this->ownerAndShop();
        $product = $this->productWithStock($owner, $shop, 2);

        $this->actingAs($owner, 'sanctum')->withHeader('X-Shop-Id', $shop->id)
            ->postJson('/api/v1/inventory/loss', [
                'product_id' => $product->id,
                'quantity' => 1,
                'reason' => 'Missing during count',
            ])->assertCreated();

        $this->actingAs($owner, 'sanctum')->withHeader('X-Shop-Id', $shop->id)
            ->getJson('/api/v1/inventory?low_stock=1')
            ->assertOk()->assertJsonPath('0.product_id', $product->id)
            ->assertJsonPath('0.stock', 1);

        $this->actingAs($owner, 'sanctum')->withHeader('X-Shop-Id', $shop->id)
            ->getJson('/api/v1/inventory/movements?product_id='.$product->id)
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.movement_type', MovementType::Loss->value);
    }

    public function test_confirming_a_purchase_posts_stock_and_supplier_credit_once(): void
    {
        [$owner, $shop] = $this->ownerAndShop();
        $product = $this->productWithStock($owner, $shop, 1);
        $headers = ['X-Shop-Id' => $shop->id];

        $supplier = $this->actingAs($owner, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/suppliers', ['name' => 'Nile Suppliers'])->assertCreated();

        $draft = $this->actingAs($owner, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/purchases', [
                'supplier_id' => $supplier->json('id'),
                'amount_paid' => 500,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit' => 'piece',
                    'unit_cost' => 800,
                ]],
            ])->assertCreated()->assertJsonPath('status', 'DRAFT');

        $this->assertDatabaseCount('stock_movements', 1);

        $this->actingAs($owner, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/purchases/'.$draft->json('id').'/confirm')
            ->assertOk()
            ->assertJsonPath('status', 'CONFIRMED')
            ->assertJsonPath('total', 1600)
            ->assertJsonPath('amount_due', 1100);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_type' => MovementType::Purchase->value,
            'quantity_delta' => 2,
            'unit_cost' => 800,
        ]);
        $this->assertDatabaseHas('supplier_ledger_entries', ['amount' => 1100, 'type' => 'PURCHASE_CREDIT']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'current_cost' => 800]);

        $this->actingAs($owner, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/suppliers/'.$supplier->json('id').'/payments', ['amount' => 1100])
            ->assertCreated();

        $this->actingAs($owner, 'sanctum')->withHeaders($headers)
            ->getJson('/api/v1/suppliers/'.$supplier->json('id'))
            ->assertOk()->assertJsonPath('balance', 0);
    }

    /** @return array{User, Shop} */
    private function ownerAndShop(): array
    {
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Test Organization', 'owner_user_id' => $owner->id]);
        $owner->update(['organization_id' => $organization->id]);
        $shop = Shop::create([
            'organization_id' => $organization->id,
            'name' => 'Main Shop',
            'business_type' => 'mini_mart',
        ]);
        UserShopRole::create(['user_id' => $owner->id, 'shop_id' => $shop->id, 'role' => Role::Owner]);

        return [$owner, $shop];
    }

    private function productWithStock(User $owner, Shop $shop, int $quantity): Product
    {
        $product = Product::create([
            'shop_id' => $shop->id,
            'name' => 'Test Product',
            'base_unit' => 'piece',
            'selling_price' => 1000,
            'current_cost' => 500,
            'low_stock_threshold' => 2,
        ]);
        StockMovement::create([
            'shop_id' => $shop->id,
            'product_id' => $product->id,
            'quantity_delta' => $quantity,
            'unit_cost' => 500,
            'movement_type' => MovementType::OpeningStock,
            'performed_by' => $owner->id,
        ]);

        return $product;
    }
}
