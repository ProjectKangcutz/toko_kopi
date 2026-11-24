<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'dashboard' => ['view'],
            'products' => ['view', 'create', 'update', 'delete', 'import_export'],
            'categories' => ['view', 'create', 'update', 'delete'],
            'inventory' => ['view', 'adjust'],
            'orders' => ['view', 'update_status', 'cancel', 'refund', 'export'],
            'customers' => ['view', 'update_status'],
            'payments' => ['view', 'reconcile', 'refund'],
            'shipments' => ['view', 'update', 'tracking'],
            'promotions' => ['view', 'create', 'update', 'delete'],
            'conversations' => ['view', 'reply', 'assign', 'close'],
            'content' => ['view', 'manage'],
            'seo' => ['view', 'manage'],
            'reports' => ['view', 'export'],
            'settings' => ['view', 'manage', 'integrations'],
            'users' => ['view', 'manage'],
            'roles' => ['view', 'manage'],
        ];

        $allPermissionIds = [];
        $modulePermissions = [];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                $slug = "{$module}.{$action}";
                $permission = Permission::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => ucfirst($module).' - '.ucwords(str_replace('_', ' ', $action)),
                        'module' => $module,
                        'description' => "Izin {$action} pada modul {$module}",
                    ]
                );
                $allPermissionIds[] = $permission->id;
                $modulePermissions[$module][] = $permission->id;
            }
        }

        $superAdmin = Role::updateOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Owner / Super Admin', 'description' => 'Akses penuh seluruh sistem toko.', 'is_system' => true]
        );
        $superAdmin->permissions()->sync($allPermissionIds);

        $storeAdmin = Role::updateOrCreate(
            ['slug' => 'store-admin'],
            ['name' => 'Admin Toko', 'description' => 'Pengelola operasional harian toko.', 'is_system' => true]
        );
        $storeAdminIds = collect($modulePermissions)
            ->except(['users', 'roles'])
            ->flatten()
            ->all();
        $storeAdmin->permissions()->sync($storeAdminIds);

        $productManager = Role::updateOrCreate(
            ['slug' => 'product-manager'],
            ['name' => 'Pengelola Produk', 'description' => 'Mengelola katalog, kategori, dan stok.', 'is_system' => true]
        );
        $productManager->permissions()->sync(array_merge(
            $modulePermissions['dashboard'],
            $modulePermissions['products'],
            $modulePermissions['categories'],
            $modulePermissions['inventory']
        ));

        $orderManager = Role::updateOrCreate(
            ['slug' => 'order-manager'],
            ['name' => 'Pengelola Pesanan', 'description' => 'Memproses pesanan, pembayaran, dan pengiriman.', 'is_system' => true]
        );
        $orderManager->permissions()->sync(array_merge(
            $modulePermissions['dashboard'],
            $modulePermissions['orders'],
            $modulePermissions['payments'],
            $modulePermissions['shipments']
        ));

        $supportRole = Role::updateOrCreate(
            ['slug' => 'customer-support'],
            ['name' => 'Customer Support', 'description' => 'Melayani percakapan dan kendala pelanggan.', 'is_system' => true]
        );
        $supportRole->permissions()->sync(array_merge(
            $modulePermissions['dashboard'],
            $modulePermissions['conversations'],
            [$modulePermissions['orders'][0], $modulePermissions['customers'][0]]
        ));
    }
}
