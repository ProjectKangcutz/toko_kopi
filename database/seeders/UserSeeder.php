<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $isProduction = app()->environment('production');

        $ownerPassword = env('SEED_OWNER_PASSWORD');
        $adminPassword = env('SEED_ADMIN_PASSWORD');
        $customerPassword = env('SEED_CUSTOMER_PASSWORD');

        if ($isProduction && (! $ownerPassword || ! $adminPassword)) {
            Log::channel('security')->warning('UserSeeder dilewati di production karena SEED_OWNER_PASSWORD / SEED_ADMIN_PASSWORD tidak disetel.');

            return;
        }

        $superAdminRole = Role::where('slug', 'super-admin')->first();
        $storeAdminRole = Role::where('slug', 'store-admin')->first();

        $owner = User::firstOrCreate(
            ['email' => 'owner@pasarlokal.test'],
            [
                'name' => 'Pemilik Toko UMKM',
                'phone' => '081200000001',
                'password' => $ownerPassword ?: 'Password!123',
                'is_active' => true,
            ]
        );
        if ($superAdminRole) {
            $owner->roles()->syncWithoutDetaching([$superAdminRole->id]);
        }

        $staff = User::firstOrCreate(
            ['email' => 'admin@pasarlokal.test'],
            [
                'name' => 'Admin Operasional',
                'phone' => '081200000002',
                'password' => $adminPassword ?: 'Password!123',
                'is_active' => true,
            ]
        );
        if ($storeAdminRole) {
            $staff->roles()->syncWithoutDetaching([$storeAdminRole->id]);
        }

        if (! $isProduction) {
            $customer = Customer::firstOrCreate(
                ['email' => 'pelanggan@pasarlokal.test'],
                [
                    'name' => 'Pelanggan Contoh',
                    'phone' => '081299990001',
                    'password' => $customerPassword ?: 'Password!123',
                    'email_verified_at' => now(),
                    'status' => 'active',
                ]
            );

            CustomerProfile::firstOrCreate(
                ['customer_id' => $customer->id],
                ['preferred_locale' => 'id', 'marketing_opt_in' => true]
            );
        }
    }
}
