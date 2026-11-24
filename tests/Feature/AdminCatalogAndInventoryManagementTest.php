<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCatalogAndInventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function createAdminWithRole(string $roleSlug = 'product-manager'): User
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = User::create([
            'name' => 'Admin '.$suffix,
            'email' => "adm-{$roleSlug}-{$suffix}@example.com",
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    public function test_product_manager_can_crud_category_and_unauthorized_role_is_forbidden(): void
    {
        Storage::fake('public');

        $productManager = $this->createAdminWithRole('product-manager');
        $support = $this->createAdminWithRole('customer-support');

        $this->actingAs($support, 'admin')
            ->get(route('admin.categories.index'))
            ->assertForbidden();

        $createResponse = $this->actingAs($productManager, 'admin')
            ->post(route('admin.categories.store'), [
                'name' => 'Kerajinan Bambu',
                'description' => 'Produk anyaman bambu lokal.',
                'sort_order' => 1,
                'is_active' => '1',
                'image' => UploadedFile::fake()->image('bambu.jpg', 400, 400),
            ]);

        $createResponse->assertRedirect(route('admin.categories.index'));

        $category = Category::where('slug', 'kerajinan-bambu')->firstOrFail();
        $this->assertNotNull($category->image_path);
        Storage::disk('public')->assertExists($category->image_path);

        $this->actingAs($productManager, 'admin')
            ->put(route('admin.categories.update', $category), [
                'name' => 'Kerajinan Bambu Nusantara',
                'slug' => 'kerajinan-bambu-nusantara',
                'sort_order' => 2,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.categories.index'));

        $category->refresh();
        $this->assertSame('Kerajinan Bambu Nusantara', $category->name);
        $this->assertSame('kerajinan-bambu-nusantara', $category->slug);

        // Delete empty category -> force deleted
        $this->actingAs($productManager, 'admin')
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'));

        $this->assertNull(Category::withTrashed()->find($category->id));
    }

    public function test_product_crud_with_variants_images_storefront_sync_and_non_destructive_delete(): void
    {
        Storage::fake('public');

        $admin = $this->createAdminWithRole('product-manager');
        $category = Category::create([
            'name' => 'Kopi Lokal',
            'slug' => 'kopi-lokal',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Create simple product with initial stock & image
        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.products.store'), [
                'sku' => 'KOP-TEST-01',
                'name' => 'Kopi Gayo Pilihan',
                'primary_category_id' => $category->id,
                'description' => 'Kopi arabika Aceh Gayo sangrai medium.',
                'cost_price' => 45000,
                'price' => 75000,
                'weight_grams' => 250,
                'min_stock' => 5,
                'initial_stock' => 12,
                'has_variants' => '0',
                'is_active' => '1',
                'is_featured' => '1',
                'images' => [UploadedFile::fake()->image('kopi.jpg', 600, 600)],
            ]);

        $product = Product::where('sku', 'KOP-TEST-01')->firstOrFail();
        $response->assertRedirect(route('admin.products.edit', $product));
        $this->assertSame(12, $product->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 12,
            'stock_before' => 0,
            'stock_after' => 12,
        ]);

        /** @var ProductImage $image */
        $image = $product->images()->firstOrFail();
        Storage::disk('public')->assertExists($image->image_path);

        // Storefront immediately reflects created product
        $this->get(route('storefront.catalog'))
            ->assertOk()
            ->assertSee('Kopi Gayo Pilihan');

        // Product has StockMovement history -> delete must soft-delete & deactivate, NOT permanently destroy
        $this->actingAs($admin, 'admin')
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('dinonaktifkan dan diarsipkan');

        $trashed = Product::withTrashed()->findOrFail($product->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertFalse($trashed->is_active);

        // Storefront immediately hides deactivated/soft-deleted product
        $this->get(route('storefront.catalog'))
            ->assertOk()
            ->assertDontSee('Kopi Gayo Pilihan');
    }

    public function test_product_with_order_history_or_active_reservation_respects_delete_rules(): void
    {
        $admin = $this->createAdminWithRole('product-manager');
        $customer = Customer::create([
            'name' => 'Pembeli Histori',
            'email' => 'hist-cust@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $product = Product::create([
            'sku' => 'SKU-RES-01',
            'name' => 'Produk Reservasi',
            'slug' => 'produk-reservasi',
            'description' => 'Produk dengan reservasi.',
            'price' => 50000,
            'weight_grams' => 200,
            'stock' => 10,
            'reserved_stock' => 2,
            'min_stock' => 2,
            'has_variants' => false,
            'is_active' => true,
        ]);

        $order = Order::create([
            'order_number' => 'PLN-20261123-RES01',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 100000,
            'shipping_cost' => 10000,
            'grand_total' => 110000,
            'total_weight_grams' => 400,
            'payment_due_at' => now()->addHours(12),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 50000,
            'cost_price_snapshot' => 30000,
            'weight_grams_snapshot' => 200,
            'quantity' => 2,
            'line_total' => 100000,
        ]);

        $reservation = StockReservation::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->addHours(12),
        ]);

        // Active reservation blocks delete
        $this->actingAs($admin, 'admin')
            ->delete(route('admin.products.destroy', $product))
            ->assertSessionHasErrors('error');

        $this->assertNull($product->fresh()->deleted_at);

        // Once reservation released, product with OrderItem history soft-deletes & deactivates safely
        $reservation->update(['status' => 'released', 'released_at' => now()]);
        $product->update(['reserved_stock' => 0]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'));

        $trashed = Product::withTrashed()->findOrFail($product->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertFalse($trashed->is_active);
    }

    public function test_manual_stock_adjustment_records_audit_trail_and_blocks_invalid_or_negative_stock(): void
    {
        $productManager = $this->createAdminWithRole('product-manager');
        $orderManager = $this->createAdminWithRole('order-manager');

        $product = Product::create([
            'sku' => 'SKU-INV-01',
            'name' => 'Gula Aren Organik',
            'slug' => 'gula-aren-organik',
            'description' => 'Gula aren murni.',
            'price' => 35000,
            'weight_grams' => 500,
            'stock' => 10,
            'reserved_stock' => 4,
            'min_stock' => 3,
            'has_variants' => false,
            'is_active' => true,
        ]);

        $this->actingAs($productManager, 'admin')
            ->get(route('admin.inventory.index'))
            ->assertOk()
            ->assertSee('Gula Aren Organik');

        // Unauthorized role cannot view or adjust stock
        $this->actingAs($orderManager, 'admin')
            ->post(route('admin.inventory.adjust'), [
                'product_id' => $product->id,
                'reason' => 'restock',
                'quantity_change' => 5,
                'notes' => 'Restock ilegal',
            ])
            ->assertForbidden();

        // Missing notes rejected by validation
        $this->actingAs($productManager, 'admin')
            ->post(route('admin.inventory.adjust'), [
                'product_id' => $product->id,
                'reason' => 'restock',
                'quantity_change' => 5,
                'notes' => '',
            ])
            ->assertSessionHasErrors('notes');

        // Adjusting below reserved_stock (10 - 7 = 3 < 4 reserved) rejected
        $this->actingAs($productManager, 'admin')
            ->post(route('admin.inventory.adjust'), [
                'product_id' => $product->id,
                'reason' => 'damaged',
                'quantity_change' => -7,
                'notes' => 'Kemasan rusak',
            ])
            ->assertSessionHasErrors('error');
        $this->assertSame(10, $product->fresh()->stock);

        // Adjusting to negative (10 - 15 = -5) rejected
        $this->actingAs($productManager, 'admin')
            ->post(route('admin.inventory.adjust'), [
                'product_id' => $product->id,
                'reason' => 'lost',
                'quantity_change' => -15,
                'notes' => 'Selisih opname',
            ])
            ->assertSessionHasErrors('error');
        $this->assertSame(10, $product->fresh()->stock);

        // Valid adjustment records StockAdjustment + StockMovement atomically
        $this->actingAs($productManager, 'admin')
            ->post(route('admin.inventory.adjust'), [
                'product_id' => $product->id,
                'reason' => 'restock',
                'quantity_change' => 8,
                'notes' => 'Restock dari pengrajin batch November',
            ])
            ->assertRedirect(route('admin.inventory.index'));

        $this->assertSame(18, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_adjustments', [
            'product_id' => $product->id,
            'reason' => 'restock',
            'quantity_change' => 8,
            'adjusted_by' => $productManager->id,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => 8,
            'stock_before' => 10,
            'stock_after' => 18,
            'reference_type' => StockAdjustment::class,
        ]);
    }

    public function test_variant_product_manual_stock_adjustment_requires_variant_and_updates_variant_stock(): void
    {
        $admin = $this->createAdminWithRole('product-manager');

        $product = Product::create([
            'sku' => 'SKU-VAR-PARENT',
            'name' => 'Keripik Tempe',
            'slug' => 'keripik-tempe',
            'description' => 'Keripik tempe renyah.',
            'price' => 20000,
            'weight_grams' => 200,
            'stock' => 0,
            'reserved_stock' => 0,
            'min_stock' => 5,
            'has_variants' => true,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-VAR-PEDAS',
            'name' => 'Pedas 200g',
            'price' => 22000,
            'weight_grams' => 200,
            'stock' => 5,
            'reserved_stock' => 1,
            'min_stock' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.inventory.adjust'), [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'reason' => 'opname',
                'quantity_change' => 10,
                'notes' => 'Hasil stok opname gudang',
            ])
            ->assertRedirect(route('admin.inventory.index'));

        $this->assertSame(15, $variant->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock_before' => 5,
            'stock_after' => 15,
        ]);
    }
}
