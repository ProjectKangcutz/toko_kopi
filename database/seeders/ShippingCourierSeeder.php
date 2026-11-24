<?php

namespace Database\Seeders;

use App\Models\ShippingCourier;
use App\Models\ShippingService;
use Illuminate\Database\Seeder;

class ShippingCourierSeeder extends Seeder
{
    public function run(): void
    {
        $couriers = [
            ['code' => 'jne', 'name' => 'JNE Express', 'sort_order' => 1, 'services' => [['REG', 'JNE Reguler'], ['YES', 'JNE Yakin Esok Sampai'], ['OKE', 'JNE Ongkos Kirim Ekonomis']]],
            ['code' => 'pos', 'name' => 'POS Indonesia', 'sort_order' => 2, 'services' => [['Pos Reguler', 'Pos Reguler'], ['Pos Nextday', 'Pos Nextday']]],
            ['code' => 'tiki', 'name' => 'TIKI', 'sort_order' => 3, 'services' => [['REG', 'TIKI Regular'], ['ONS', 'TIKI Over Night Service']]],
            ['code' => 'jnt', 'name' => 'J&T Express', 'sort_order' => 4, 'services' => [['EZ', 'J&T Regular']]],
            ['code' => 'sicepat', 'name' => 'SiCepat Ekspres', 'sort_order' => 5, 'services' => [['REG', 'SiCepat Regular'], ['BEST', 'SiCepat Besok Sampai Tujuan']]],
            ['code' => 'anteraja', 'name' => 'AnterAja', 'sort_order' => 6, 'services' => [['REG', 'AnterAja Regular'], ['ND', 'AnterAja Next Day']]],
        ];

        foreach ($couriers as $item) {
            $courier = ShippingCourier::updateOrCreate(
                ['code' => $item['code']],
                ['name' => $item['name'], 'is_active' => true, 'sort_order' => $item['sort_order']]
            );

            foreach ($item['services'] as [$serviceCode, $serviceName]) {
                ShippingService::updateOrCreate(
                    ['courier_id' => $courier->id, 'service_code' => $serviceCode],
                    ['service_name' => $serviceName, 'is_active' => true]
                );
            }
        }
    }
}
