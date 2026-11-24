<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_available_stock_subtracts_reserved_stock(): void
    {
        $product = new Product([
            'stock' => 25,
            'reserved_stock' => 4,
            'price' => 50000,
        ]);

        $this->assertSame(21, $product->availableStock());
    }

    public function test_setting_encrypts_and_decrypts_secret_values(): void
    {
        $encoded = Setting::encodeValue('secret-api-key-123', 'encrypted');
        $this->assertNotSame('secret-api-key-123', $encoded);

        $setting = new Setting([
            'group' => 'rajaongkir',
            'key' => 'api_key',
            'type' => 'encrypted',
            'value' => $encoded,
        ]);

        $this->assertSame('secret-api-key-123', $setting->decodedValue());
    }

    public function test_rbac_user_role_and_permission_relationships_work(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::create([
            'name' => 'Manajer Produk',
            'email' => 'pm@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', 'product-manager')->firstOrFail();
        $user->roles()->attach($role->id);

        $this->assertTrue($user->hasRole('product-manager'));
        $this->assertTrue($user->hasPermission('products.create'));
        $this->assertFalse($user->hasPermission('settings.integrations'));
    }

    public function test_catalog_customer_and_order_snapshot_relationships_work(): void
    {
        $parent = Category::create(['name' => 'Kuliner', 'slug' => 'kuliner', 'is_active' => true]);
        $child = Category::create(['parent_id' => $parent->id, 'name' => 'Kopi', 'slug' => 'kopi', 'is_active' => true]);

        $product = Product::create([
            'sku' => 'KOPI-001',
            'name' => 'Kopi Arabika Gayo',
            'slug' => 'kopi-arabika-gayo',
            'description' => 'Kopi pilihan Nusantara.',
            'price' => 85000,
            'stock' => 10,
            'weight_grams' => 250,
            'is_active' => true,
        ]);
        $product->categories()->attach($child->id, ['is_primary' => true]);

        $customer = Customer::create([
            'name' => 'Rina',
            'email' => 'rina@example.com',
            'phone' => '08123450000',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $address = CustomerAddress::create([
            'customer_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Rina',
            'recipient_phone' => '08123450000',
            'address_line' => 'Jl. Asia Afrika No. 10',
            'province_id' => '9',
            'province_name' => 'Jawa Barat',
            'city_id' => '23',
            'city_name' => 'Kota Bandung',
            'postal_code' => '40111',
            'is_primary' => true,
        ]);

        $order = Order::create([
            'order_number' => 'PLN-20261119-0001',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'subtotal' => 85000,
            'grand_total' => 85000,
            'total_weight_grams' => 250,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 85000,
            'weight_grams_snapshot' => 250,
            'quantity' => 1,
            'line_total' => 85000,
        ]);

        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'address_line' => $address->address_line,
            'province_id' => $address->province_id,
            'province_name' => $address->province_name,
            'city_id' => $address->city_id,
            'city_name' => $address->city_name,
            'postal_code' => $address->postal_code,
        ]);

        $this->assertCount(1, $parent->children);
        $this->assertCount(1, $product->categories);
        $this->assertSame($address->id, $customer->primaryAddress->id);
        $this->assertCount(1, $order->items);
        $this->assertSame('Kota Bandung', $order->address->city_name);
    }
}
