<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Role;
use App\Models\User;
use App\Services\Customer\CustomerAddressService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAddressManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function createCustomer(array $overrides = []): Customer
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return Customer::create(array_merge([
            'name' => 'Pelanggan '.$suffix,
            'email' => 'customer-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ], $overrides));
    }

    protected function createAdmin(array $overrides = []): User
    {
        $suffix = str_replace('.', '', uniqid('', true));

        $admin = User::create(array_merge([
            'name' => 'Admin '.$suffix,
            'email' => 'admin-'.$suffix.'@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ], $overrides));

        $role = Role::where('slug', 'super-admin')->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    protected function validAddressPayload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Rumah Utama',
            'recipient_name' => 'Rina Kartika',
            'recipient_phone' => '081234567890',
            'address_line' => 'Jl. Braga No. 45 RT 02 RW 05, Kelurahan Braga',
            'province_id' => '9',
            'province_name' => 'Jawa Barat',
            'city_id' => '23',
            'city_name' => 'Kota Bandung',
            'district_id' => '345',
            'district_name' => 'Sumur Bandung',
            'subdistrict_id' => '34501',
            'subdistrict_name' => 'Braga',
            'postal_code' => '40111',
            'is_primary' => false,
        ], $overrides);
    }

    public function test_guest_cannot_access_any_customer_address_endpoints(): void
    {
        $customer = $this->createCustomer();
        $address = $customer->addresses()->create($this->validAddressPayload(['is_primary' => true]));

        $this->get(route('customer.addresses.index'))->assertRedirect(route('customer.login'));
        $this->get(route('customer.addresses.create'))->assertRedirect(route('customer.login'));
        $this->post(route('customer.addresses.store'), $this->validAddressPayload())->assertRedirect(route('customer.login'));
        $this->get(route('customer.addresses.edit', $address))->assertRedirect(route('customer.login'));
        $this->put(route('customer.addresses.update', $address), $this->validAddressPayload())->assertRedirect(route('customer.login'));
        $this->patch(route('customer.addresses.set-primary', $address))->assertRedirect(route('customer.login'));
        $this->delete(route('customer.addresses.destroy', $address))->assertRedirect(route('customer.login'));
    }

    public function test_admin_logged_in_only_on_admin_guard_cannot_access_customer_addresses(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer();
        $address = $customer->addresses()->create($this->validAddressPayload(['is_primary' => true]));

        $this->actingAs($admin, 'admin')
            ->get(route('customer.addresses.index'))
            ->assertRedirect(route('customer.login'));

        $this->actingAs($admin, 'admin')
            ->post(route('customer.addresses.store'), $this->validAddressPayload())
            ->assertRedirect(route('customer.login'));

        $this->actingAs($admin, 'admin')
            ->patch(route('customer.addresses.set-primary', $address))
            ->assertRedirect(route('customer.login'));

        $this->actingAs($admin, 'admin')
            ->delete(route('customer.addresses.destroy', $address))
            ->assertRedirect(route('customer.login'));

        $this->assertAuthenticated('admin');
        $this->assertSame(1, CustomerAddress::count());
    }

    public function test_active_customer_can_create_address_and_customer_id_always_comes_from_authenticated_customer(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();

        $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.index'))
            ->assertStatus(200);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.create'))
            ->assertStatus(200);

        $response = $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), $this->validAddressPayload([
                'customer_id' => $otherCustomer->id,
                'label' => 'Rumah Bandung',
                'is_primary' => false,
            ]));

        $response->assertRedirect(route('customer.addresses.index'))
            ->assertSessionHas('success');

        $created = CustomerAddress::firstOrFail();
        $this->assertSame($customer->id, $created->customer_id);
        $this->assertNotSame($otherCustomer->id, $created->customer_id);
        $this->assertSame('Rumah Bandung', $created->label);
        // Alamat pertama otomatis menjadi utama walaupun is_primary=false dikirim
        $this->assertTrue($created->is_primary);

        $this->assertDatabaseHas('activity_logs', [
            'actor_type' => 'customer',
            'actor_id' => $customer->id,
            'action' => 'customer.address.created',
            'subject_type' => CustomerAddress::class,
            'subject_id' => $created->id,
        ]);
    }

    public function test_second_address_preserves_existing_primary_unless_explicitly_marked_primary(): void
    {
        $customer = $this->createCustomer();

        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), $this->validAddressPayload([
                'label' => 'Alamat Pertama',
                'is_primary' => false,
            ]))
            ->assertRedirect(route('customer.addresses.index'));

        $firstAddress = $customer->addresses()->firstOrFail();
        $this->assertTrue($firstAddress->is_primary);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), $this->validAddressPayload([
                'label' => 'Alamat Kedua',
                'is_primary' => false,
            ]))
            ->assertRedirect(route('customer.addresses.index'));

        $secondAddress = $customer->addresses()->where('label', 'Alamat Kedua')->firstOrFail();

        $this->assertTrue($firstAddress->fresh()->is_primary);
        $this->assertFalse($secondAddress->fresh()->is_primary);
        $this->assertSame(1, $customer->addresses()->where('is_primary', true)->count());

        // Menambah alamat ketiga dengan is_primary=true menonaktifkan alamat utama sebelumnya
        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), $this->validAddressPayload([
                'label' => 'Alamat Ketiga',
                'is_primary' => true,
            ]))
            ->assertRedirect(route('customer.addresses.index'));

        $thirdAddress = $customer->addresses()->where('label', 'Alamat Ketiga')->firstOrFail();

        $this->assertFalse($firstAddress->fresh()->is_primary);
        $this->assertFalse($secondAddress->fresh()->is_primary);
        $this->assertTrue($thirdAddress->fresh()->is_primary);
        $this->assertSame(1, $customer->addresses()->where('is_primary', true)->count());
    }

    public function test_set_primary_switches_primary_address_and_maintains_single_primary_invariant(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();

        $first = $customer->addresses()->create($this->validAddressPayload(['label' => 'Rumah', 'is_primary' => true]));
        $second = $customer->addresses()->create($this->validAddressPayload(['label' => 'Kantor', 'is_primary' => false]));
        $otherPrimary = $otherCustomer->addresses()->create($this->validAddressPayload(['label' => 'Milik Lain', 'is_primary' => true]));

        $this->actingAs($customer, 'customer')
            ->patch(route('customer.addresses.set-primary', $second))
            ->assertRedirect(route('customer.addresses.index'))
            ->assertSessionHas('success');

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertTrue($otherPrimary->fresh()->is_primary);
        $this->assertSame(1, $customer->addresses()->where('is_primary', true)->count());
        $this->assertSame($second->id, $customer->fresh()->primaryAddress->id);

        // Idempotent ketika dipanggil ulang pada alamat yang sudah utama
        $this->actingAs($customer, 'customer')
            ->patch(route('customer.addresses.set-primary', $second))
            ->assertRedirect(route('customer.addresses.index'));

        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame(1, $customer->addresses()->where('is_primary', true)->count());

        $this->assertDatabaseHas('activity_logs', [
            'actor_type' => 'customer',
            'actor_id' => $customer->id,
            'action' => 'customer.address.set_primary',
            'subject_id' => $second->id,
        ]);
    }

    public function test_update_address_preserves_ownership_and_prevents_losing_only_primary(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();

        $address = $customer->addresses()->create($this->validAddressPayload([
            'label' => 'Rumah Lama',
            'is_primary' => true,
        ]));

        $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.edit', $address))
            ->assertStatus(200);

        $this->actingAs($customer, 'customer')
            ->put(route('customer.addresses.update', $address), $this->validAddressPayload([
                'customer_id' => $otherCustomer->id,
                'label' => 'Rumah Baru',
                'recipient_name' => 'Rina Wijaya',
                'is_primary' => false,
            ]))
            ->assertRedirect(route('customer.addresses.index'))
            ->assertSessionHas('success');

        $updated = $address->fresh();
        $this->assertSame($customer->id, $updated->customer_id);
        $this->assertSame('Rumah Baru', $updated->label);
        $this->assertSame('Rina Wijaya', $updated->recipient_name);
        // Tidak boleh kehilangan status alamat utama jika tidak ada alamat utama pengganti
        $this->assertTrue($updated->is_primary);

        $this->assertDatabaseHas('activity_logs', [
            'actor_type' => 'customer',
            'actor_id' => $customer->id,
            'action' => 'customer.address.updated',
            'subject_id' => $updated->id,
        ]);
    }

    public function test_customer_cannot_view_edit_update_set_primary_or_delete_another_customers_address(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();

        $addressB = $customerB->addresses()->create($this->validAddressPayload([
            'label' => 'Alamat Rahasia B',
            'is_primary' => true,
        ]));

        // Daftar alamat customer A tidak menampilkan alamat customer B
        $this->actingAs($customerA, 'customer')
            ->getJson(route('customer.addresses.index'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'addresses');

        $this->actingAs($customerA, 'customer')
            ->get(route('customer.addresses.edit', $addressB))
            ->assertStatus(403);

        $this->actingAs($customerA, 'customer')
            ->put(route('customer.addresses.update', $addressB), $this->validAddressPayload([
                'label' => 'Dibajak A',
            ]))
            ->assertStatus(403);

        $this->actingAs($customerA, 'customer')
            ->patch(route('customer.addresses.set-primary', $addressB))
            ->assertStatus(403);

        $this->actingAs($customerA, 'customer')
            ->delete(route('customer.addresses.destroy', $addressB))
            ->assertStatus(403);

        $this->assertSame('Alamat Rahasia B', $addressB->fresh()->label);
        $this->assertNull($addressB->fresh()->deleted_at);
    }

    public function test_soft_deleted_and_missing_addresses_return_404(): void
    {
        $customer = $this->createCustomer();
        $address = $customer->addresses()->create($this->validAddressPayload(['is_primary' => true]));
        $addressId = $address->id;
        $address->delete();

        $this->actingAs($customer, 'customer')
            ->get("/akun/alamat/{$addressId}/edit")
            ->assertStatus(404);

        $this->actingAs($customer, 'customer')
            ->put("/akun/alamat/{$addressId}", $this->validAddressPayload())
            ->assertStatus(404);

        $this->actingAs($customer, 'customer')
            ->patch("/akun/alamat/{$addressId}/utama")
            ->assertStatus(404);

        $this->actingAs($customer, 'customer')
            ->delete("/akun/alamat/{$addressId}")
            ->assertStatus(404);

        $this->actingAs($customer, 'customer')
            ->get('/akun/alamat/999999/edit')
            ->assertStatus(404);
    }

    public function test_deleting_primary_address_promotes_newest_remaining_active_address(): void
    {
        $customer = $this->createCustomer();

        $oldest = $customer->addresses()->create($this->validAddressPayload(['label' => 'Lama', 'is_primary' => false]));
        $oldest->forceFill(['created_at' => now()->subDays(3)])->save();

        $primary = $customer->addresses()->create($this->validAddressPayload(['label' => 'Utama Dihapus', 'is_primary' => true]));
        $primary->forceFill(['created_at' => now()->subDays(2)])->save();

        $newest = $customer->addresses()->create($this->validAddressPayload(['label' => 'Terbaru', 'is_primary' => false]));
        $newest->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($customer, 'customer')
            ->delete(route('customer.addresses.destroy', $primary))
            ->assertRedirect(route('customer.addresses.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('customer_addresses', ['id' => $primary->id]);
        $this->assertFalse($primary->fresh()->is_primary);
        $this->assertTrue($newest->fresh()->is_primary);
        $this->assertFalse($oldest->fresh()->is_primary);
        $this->assertSame(1, $customer->addresses()->where('is_primary', true)->count());

        $this->assertDatabaseHas('activity_logs', [
            'actor_type' => 'customer',
            'actor_id' => $customer->id,
            'action' => 'customer.address.deleted',
            'subject_id' => $primary->id,
        ]);

        // Menghapus semua sisa alamat tidak membuat alamat pengganti otomatis
        $this->actingAs($customer, 'customer')->delete(route('customer.addresses.destroy', $newest));
        $this->actingAs($customer, 'customer')->delete(route('customer.addresses.destroy', $oldest));

        $this->assertSame(0, $customer->addresses()->count());
        $this->assertSame(3, CustomerAddress::withTrashed()->where('customer_id', $customer->id)->count());
    }

    public function test_deleting_non_primary_address_does_not_change_existing_primary(): void
    {
        $customer = $this->createCustomer();
        $primary = $customer->addresses()->create($this->validAddressPayload(['label' => 'Utama Tetap', 'is_primary' => true]));
        $nonPrimary = $customer->addresses()->create($this->validAddressPayload(['label' => 'Cadangan', 'is_primary' => false]));

        $this->actingAs($customer, 'customer')
            ->delete(route('customer.addresses.destroy', $nonPrimary))
            ->assertRedirect(route('customer.addresses.index'));

        $this->assertSoftDeleted('customer_addresses', ['id' => $nonPrimary->id]);
        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertSame(1, $customer->addresses()->where('is_primary', true)->count());
    }

    public function test_phone_numbers_and_postal_code_are_normalized_consistently(): void
    {
        $customer = $this->createCustomer();

        // Format 08xx dengan spasi, strip, dan tanda kurung
        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), $this->validAddressPayload([
                'label' => 'Nomor Nol',
                'recipient_phone' => ' (0812) 3456-7890 ',
                'postal_code' => ' 40115 ',
            ]))
            ->assertRedirect(route('customer.addresses.index'));

        $first = $customer->addresses()->where('label', 'Nomor Nol')->firstOrFail();
        $this->assertSame('081234567890', $first->recipient_phone);
        $this->assertSame('40115', $first->postal_code);

        // Format +62 dengan spasi dan strip mempertahankan awalan 62
        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), $this->validAddressPayload([
                'label' => 'Nomor Enam Dua',
                'recipient_phone' => '+62 812-3456-7891',
            ]))
            ->assertRedirect(route('customer.addresses.index'));

        $second = $customer->addresses()->where('label', 'Nomor Enam Dua')->firstOrFail();
        $this->assertSame('6281234567891', $second->recipient_phone);
    }

    public function test_invalid_inputs_return_validation_errors(): void
    {
        $customer = $this->createCustomer();

        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), [
                'label' => str_repeat('A', 61),
                'recipient_name' => '',
                'recipient_phone' => '12345',
                'address_line' => '',
                'province_id' => '',
                'province_name' => '',
                'city_id' => '',
                'city_name' => '',
                'postal_code' => '4011A',
            ])
            ->assertSessionHasErrors([
                'label',
                'recipient_name',
                'recipient_phone',
                'address_line',
                'province_id',
                'province_name',
                'city_id',
                'city_name',
                'postal_code',
            ]);

        // Telepon mengandung huruf maupun melebihi 16 digit wajib ditolak
        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), $this->validAddressPayload([
                'recipient_phone' => '0812abc3456',
            ]))
            ->assertSessionHasErrors(['recipient_phone']);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), $this->validAddressPayload([
                'recipient_phone' => '08123456789012345',
            ]))
            ->assertSessionHasErrors(['recipient_phone']);

        $this->assertSame(0, CustomerAddress::count());
    }

    public function test_suspended_customer_cannot_access_address_endpoints_and_gets_logged_out(): void
    {
        $customer = $this->createCustomer(['status' => 'suspended']);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.index'))
            ->assertRedirect(route('customer.login'))
            ->assertSessionHasErrors(['email']);

        $this->assertGuest('customer');

        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), $this->validAddressPayload())
            ->assertRedirect(route('customer.login'));

        $this->assertSame(0, CustomerAddress::count());
    }

    public function test_json_requests_for_guest_return_401_and_suspended_customer_return_403(): void
    {
        $this->getJson(route('customer.addresses.index'))
            ->assertStatus(401)
            ->assertJsonStructure(['message']);

        $suspended = $this->createCustomer(['status' => 'suspended']);
        $admin = $this->createAdmin();

        // Admin tetap login pada guard admin saat customer suspended ditolak 403
        $this->actingAs($admin, 'admin')
            ->actingAs($suspended, 'customer')
            ->getJson(route('customer.addresses.index'))
            ->assertStatus(403)
            ->assertJsonStructure(['message']);

        $this->assertGuest('customer');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_order_address_snapshot_remains_unchanged_when_customer_address_is_updated_or_deleted(): void
    {
        $customer = $this->createCustomer();

        /** @var CustomerAddressService $addressService */
        $addressService = app(CustomerAddressService::class);
        $address = $addressService->create($customer, $this->validAddressPayload([
            'label' => 'Rumah Asli',
            'recipient_name' => 'Rina Asli',
            'recipient_phone' => '081234567890',
            'address_line' => 'Jl. Asia Afrika No. 10 Bandung',
            'postal_code' => '40111',
        ]));

        $order = Order::create([
            'order_number' => 'PLN-ADDR-'.uniqid(),
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'subtotal' => 100000,
            'grand_total' => 100000,
        ]);

        $orderAddress = OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'address_line' => $address->address_line,
            'province_id' => $address->province_id,
            'province_name' => $address->province_name,
            'city_id' => $address->city_id,
            'city_name' => $address->city_name,
            'district_id' => $address->district_id,
            'district_name' => $address->district_name,
            'subdistrict_id' => $address->subdistrict_id,
            'subdistrict_name' => $address->subdistrict_name,
            'postal_code' => $address->postal_code,
        ]);

        // Edit alamat pelanggan
        $this->actingAs($customer, 'customer')
            ->put(route('customer.addresses.update', $address), $this->validAddressPayload([
                'label' => 'Alamat Pindahan',
                'recipient_name' => 'Budi Penerima Baru',
                'recipient_phone' => '089988776655',
                'address_line' => 'Jl. Sudirman No. 99 Jakarta',
                'province_id' => '6',
                'province_name' => 'DKI Jakarta',
                'city_id' => '152',
                'city_name' => 'Kota Jakarta Pusat',
                'postal_code' => '10220',
            ]))
            ->assertRedirect(route('customer.addresses.index'));

        $snapshotAfterEdit = $orderAddress->fresh();
        $this->assertSame('Rina Asli', $snapshotAfterEdit->recipient_name);
        $this->assertSame('081234567890', $snapshotAfterEdit->recipient_phone);
        $this->assertSame('Jl. Asia Afrika No. 10 Bandung', $snapshotAfterEdit->address_line);
        $this->assertSame('Kota Bandung', $snapshotAfterEdit->city_name);
        $this->assertSame('40111', $snapshotAfterEdit->postal_code);

        // Hapus alamat pelanggan
        $this->actingAs($customer, 'customer')
            ->delete(route('customer.addresses.destroy', $address))
            ->assertRedirect(route('customer.addresses.index'));

        $snapshotAfterDelete = $orderAddress->fresh();
        $this->assertNotNull($snapshotAfterDelete);
        $this->assertSame('Rina Asli', $snapshotAfterDelete->recipient_name);
        $this->assertSame('Jl. Asia Afrika No. 10 Bandung', $snapshotAfterDelete->address_line);
    }

    public function test_service_sequential_primary_transitions_always_preserve_single_primary_invariant(): void
    {
        $customer = $this->createCustomer();
        /** @var CustomerAddressService $service */
        $service = app(CustomerAddressService::class);

        $a1 = $service->create($customer, $this->validAddressPayload(['label' => 'A1', 'is_primary' => true]));
        $a2 = $service->create($customer, $this->validAddressPayload(['label' => 'A2', 'is_primary' => false]));
        $a3 = $service->create($customer, $this->validAddressPayload(['label' => 'A3', 'is_primary' => true]));

        $this->assertSame(1, $customer->addresses()->where('is_primary', true)->count());
        $this->assertTrue($a3->fresh()->is_primary);
        $this->assertFalse($a1->fresh()->is_primary);

        $service->setPrimary($customer, $a2);
        $this->assertSame(1, $customer->addresses()->where('is_primary', true)->count());
        $this->assertTrue($a2->fresh()->is_primary);

        $service->update($customer, $a1, $this->validAddressPayload(['label' => 'A1 Diubah', 'is_primary' => true]));
        $this->assertSame(1, $customer->addresses()->where('is_primary', true)->count());
        $this->assertTrue($a1->fresh()->is_primary);
        $this->assertFalse($a2->fresh()->is_primary);
        $this->assertFalse($a3->fresh()->is_primary);

        $listed = $service->listAddresses($customer);
        $this->assertCount(3, $listed);
        $this->assertSame($a1->id, $listed->first()->id);
        $this->assertSame(0, ActivityLog::where('actor_id', $customer->id)->count());
    }

    public function test_address_index_renders_empty_state_and_active_sidebar_navigation_without_errors(): void
    {
        $customer = $this->createCustomer();

        $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.index'))
            ->assertStatus(200)
            ->assertViewIs('customer.addresses.index')
            ->assertSee('Daftar Alamat Pengiriman')
            ->assertSee('Belum Ada Alamat Tersimpan')
            ->assertSee('Tambah Alamat Pertama')
            ->assertSee(route('customer.addresses.create'), false)
            ->assertSee(route('customer.addresses.index'), false)
            ->assertSee('Alamat Pengiriman', false);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard'))
            ->assertStatus(200)
            ->assertSee('Dashboard Pelanggan')
            ->assertSee(route('customer.addresses.index'), false);
    }

    public function test_address_index_displays_only_authenticated_customers_addresses_with_actions(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();

        /** @var CustomerAddressService $service */
        $service = app(CustomerAddressService::class);

        $primaryAddress = $service->create($customer, $this->validAddressPayload([
            'label' => 'Rumah Bandung',
            'recipient_name' => 'Rina Bandung',
            'recipient_phone' => '081211112222',
            'address_line' => 'Jl. Braga No. 45',
            'is_primary' => true,
        ]));

        $secondaryAddress = $service->create($customer, $this->validAddressPayload([
            'label' => 'Kantor Jakarta',
            'recipient_name' => 'Budi Kantor',
            'recipient_phone' => '081233334444',
            'address_line' => 'Jl. Sudirman Kav. 10',
            'province_id' => '6',
            'province_name' => 'DKI Jakarta',
            'city_id' => '152',
            'city_name' => 'Kota Jakarta Pusat',
            'postal_code' => '10220',
            'is_primary' => false,
        ]));

        $manualAddress = $service->create($customer, $this->validAddressPayload([
            'label' => 'Gudang Manual',
            'recipient_name' => 'Sari Gudang',
            'recipient_phone' => '081255556666',
            'address_line' => 'Jl. Kalimas Baru No. 3',
            'province_id' => 'manual',
            'province_name' => 'Jawa Timur',
            'city_id' => 'manual',
            'city_name' => 'Kota Surabaya',
            'district_id' => null,
            'district_name' => null,
            'subdistrict_id' => null,
            'subdistrict_name' => null,
            'postal_code' => '60165',
            'is_primary' => false,
        ]));

        $service->create($otherCustomer, $this->validAddressPayload([
            'label' => 'Alamat Rahasia Lain',
            'recipient_name' => 'Pemilik Lain',
            'is_primary' => true,
        ]));

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.index'))
            ->assertStatus(200);

        $response->assertSee('Rumah Bandung')
            ->assertSee('Alamat Utama')
            ->assertSee('Rina Bandung')
            ->assertSee($primaryAddress->formattedRegion())
            ->assertSee('Kantor Jakarta')
            ->assertSee('Budi Kantor')
            ->assertSee('Jadikan Utama')
            ->assertSee(route('customer.addresses.set-primary', $secondaryAddress), false)
            ->assertSee('Gudang Manual')
            ->assertSee('Input Manual')
            ->assertSee(route('customer.addresses.edit', $primaryAddress), false)
            ->assertSee(route('customer.addresses.destroy', $manualAddress), false)
            ->assertDontSee('Alamat Rahasia Lain')
            ->assertDontSee('Pemilik Lain');
    }

    public function test_address_create_and_edit_pages_render_region_search_integration_and_existing_values(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();

        /** @var CustomerAddressService $service */
        $service = app(CustomerAddressService::class);
        $address = $service->create($customer, $this->validAddressPayload([
            'label' => 'Rumah Utama',
            'recipient_name' => 'Rina Kartika',
            'city_name' => 'Kota Bandung',
            'postal_code' => '40111',
            'is_primary' => true,
        ]));

        $otherAddress = $service->create($otherCustomer, $this->validAddressPayload([
            'label' => 'Milik Orang Lain',
            'is_primary' => true,
        ]));

        $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.create'))
            ->assertStatus(200)
            ->assertViewIs('customer.addresses.create')
            ->assertSee('Tambah Alamat Pengiriman')
            ->assertSee(route('customer.addresses.store'), false)
            ->assertSee('addressRegionForm', false)
            ->assertSee('Cari Wilayah')
            ->assertSee('Isi Wilayah Secara Manual')
            ->assertSee('region_keyword')
            ->assertSee('Simpan Alamat');

        $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.edit', $address))
            ->assertStatus(200)
            ->assertViewIs('customer.addresses.edit')
            ->assertSee('Ubah Alamat: Rumah Utama')
            ->assertSee(route('customer.addresses.update', $address), false)
            ->assertSee('Rina Kartika')
            ->assertSee('Kota Bandung')
            ->assertSee('40111')
            ->assertSee('Simpan Perubahan');

        $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.edit', $otherAddress))
            ->assertStatus(403);
    }

    public function test_manual_fallback_submission_creates_address_successfully(): void
    {
        $customer = $this->createCustomer();

        $this->actingAs($customer, 'customer')
            ->post(route('customer.addresses.store'), [
                'label' => 'Rumah Manual',
                'recipient_name' => 'Dewi Lestari',
                'recipient_phone' => '081345678901',
                'address_line' => 'Jl. Diponegoro No. 88',
                'province_id' => 'manual',
                'province_name' => 'Jawa Timur',
                'city_id' => 'manual',
                'city_name' => 'Kota Surabaya',
                'district_id' => '',
                'district_name' => 'Tegalsari',
                'subdistrict_id' => '',
                'subdistrict_name' => 'Dr. Sutomo',
                'postal_code' => '60264',
                'is_primary' => '1',
            ])
            ->assertRedirect(route('customer.addresses.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'label' => 'Rumah Manual',
            'recipient_name' => 'Dewi Lestari',
            'province_id' => 'manual',
            'province_name' => 'Jawa Timur',
            'city_id' => 'manual',
            'city_name' => 'Kota Surabaya',
            'district_id' => null,
            'subdistrict_id' => null,
            'postal_code' => '60264',
            'is_primary' => true,
        ]);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.addresses.index'))
            ->assertStatus(200)
            ->assertSee('Rumah Manual')
            ->assertSee('Input Manual');
    }
}

