<?php

namespace App\Services\Shipping;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\ShippingCourier;
use App\Models\ShippingService;
use App\Services\Setting\StoreSettingService;
use App\Services\Storefront\CartService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ShippingRateService
{
    public const CACHE_TTL_SECONDS = 900; // 15 menit

    public const CACHE_VERSION_KEY = 'shipping:rates:v2:version';

    public function __construct(
        protected RajaOngkirShippingClient $client,
        protected CartService $cartService,
        protected StoreSettingService $settingService,
    ) {}

    /**
     * Menghitung opsi ongkos kirim untuk keranjang aktif dan alamat milik pelanggan aktif.
     * Berat, nilai barang, origin, destinasi, dan kurir sepenuhnya dihitung di server.
     *
     * @param  array<int, string>|null  $requestedCourierCodes
     * @return array{
     *     origin_id: string,
     *     destination_id: string,
     *     actual_weight_grams: int,
     *     billable_weight_grams: int,
     *     item_value: float,
     *     courier_codes: array<int, string>,
     *     from_cache: bool,
     *     rates: array<int, array{
     *         courier_code: string,
     *         courier_name: string,
     *         service_code: string,
     *         service_name: string,
     *         description: ?string,
     *         cost: int|float,
     *         etd: ?string
     *     }>
     * }
     */
    public function calculateForCustomer(
        Customer $customer,
        CustomerAddress|int $address,
        ?array $requestedCourierCodes = null
    ): array {
        if ($customer->status !== 'active') {
            throw new AuthorizationException('Akun pelanggan Anda sedang dinonaktifkan.');
        }

        $resolvedAddress = $this->resolveOwnedAddress($customer, $address);
        $destinationId = $this->resolveDestinationId($resolvedAddress);

        $config = $this->client->resolveConfig();
        $originId = $config['origin_id'];

        $cart = $this->cartService->getCart($customer);
        if (($cart['valid_quantity'] ?? 0) <= 0 || $cart['items']->isEmpty()) {
            throw ShippingRateException::emptyCart();
        }

        $actualWeightGrams = max(0, (int) ($cart['total_weight_grams'] ?? 0));
        $billableWeightGrams = $this->calculateBillableWeightGrams($actualWeightGrams);
        $itemValue = round((float) ($cart['subtotal'] ?? 0), 2);

        $activeCouriers = ShippingCourier::query()
            ->with(['services' => static fn ($query) => $query->orderBy('id')])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy(static fn (ShippingCourier $courier): string => strtolower(trim($courier->code)));

        if ($activeCouriers->isEmpty()) {
            throw ShippingRateException::noActiveCouriers();
        }

        $activeCodes = $activeCouriers->keys()->values()->all();
        if ($requestedCourierCodes !== null) {
            $normalizedRequested = RajaOngkirShippingClient::normalizeCourierCodes($requestedCourierCodes);
            $targetCodes = array_values(array_intersect($activeCodes, $normalizedRequested));
            if ($targetCodes === []) {
                throw ShippingRateException::noActiveCouriers();
            }
        } else {
            $targetCodes = $activeCodes;
        }

        $cacheKey = self::buildCacheKey(
            $config['base_url'],
            $originId,
            $destinationId,
            $billableWeightGrams,
            $itemValue,
            $targetCodes
        );

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return [
                'origin_id' => $originId,
                'destination_id' => $destinationId,
                'actual_weight_grams' => $actualWeightGrams,
                'billable_weight_grams' => $billableWeightGrams,
                'item_value' => $itemValue,
                'courier_codes' => $targetCodes,
                'from_cache' => true,
                'rates' => $cached,
            ];
        }

        $rawRates = $this->client->calculateDomesticCost(
            $destinationId,
            $billableWeightGrams,
            $targetCodes
        );

        $filteredRates = $this->filterAndEnrichRates($rawRates, $activeCouriers, $targetCodes);

        Cache::put($cacheKey, $filteredRates, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return [
            'origin_id' => $originId,
            'destination_id' => $destinationId,
            'actual_weight_grams' => $actualWeightGrams,
            'billable_weight_grams' => $billableWeightGrams,
            'item_value' => $itemValue,
            'courier_codes' => $targetCodes,
            'from_cache' => false,
            'rates' => $filteredRates,
        ];
    }

    /**
     * Memilih dan memverifikasi tarif resmi satu layanan kurir di sisi server
     * sehingga nilai ongkos kirim dari input browser tidak pernah dipercaya.
     *
     * @return array{
     *     courier_code: string,
     *     courier_name: string,
     *     service_code: string,
     *     service_name: string,
     *     description: ?string,
     *     cost: int|float,
     *     etd: ?string,
     *     origin_id: string,
     *     destination_id: string,
     *     actual_weight_grams: int,
     *     billable_weight_grams: int,
     *     from_cache: bool
     * }
     */
    public function resolveSelectedRate(
        Customer $customer,
        CustomerAddress|int $address,
        string $courierCode,
        string $serviceCode
    ): array {
        $normalizedCourier = strtolower(trim($courierCode));
        $normalizedService = strtoupper(trim($serviceCode));

        if ($normalizedCourier === '' || $normalizedService === '') {
            throw ShippingRateException::rateNotFound();
        }

        $result = $this->calculateForCustomer($customer, $address, [$normalizedCourier]);

        foreach ($result['rates'] as $rate) {
            if (
                strtolower($rate['courier_code']) === $normalizedCourier
                && strtoupper($rate['service_code']) === $normalizedService
            ) {
                return array_merge($rate, [
                    'origin_id' => $result['origin_id'],
                    'destination_id' => $result['destination_id'],
                    'actual_weight_grams' => $result['actual_weight_grams'],
                    'billable_weight_grams' => $result['billable_weight_grams'],
                    'from_cache' => $result['from_cache'],
                ]);
            }
        }

        throw ShippingRateException::rateNotFound();
    }

    /**
     * Menghitung berat tagih pengiriman berdasarkan `store.min_weight_grams` dan
     * `store.weight_rounding_grams`, dengan jaminan hasil tidak pernah kurang dari berat aktual.
     */
    public function calculateBillableWeightGrams(int $actualWeightGrams): int
    {
        $sanitizedActual = max(0, $actualWeightGrams);
        $minWeightGrams = max(1, (int) $this->settingService->get('store', 'min_weight_grams', 100));
        $roundingGrams = max(0, (int) $this->settingService->get('store', 'weight_rounding_grams', 1000));

        $baseWeight = max($sanitizedActual, $minWeightGrams);

        if ($roundingGrams <= 1) {
            return $baseWeight;
        }

        // Pembulatan ke atas kelipatan roundingGrams menggunakan aritmetika integer agar presisi.
        $multiples = intdiv($baseWeight + $roundingGrams - 1, $roundingGrams);

        return max($baseWeight, $multiples * $roundingGrams);
    }

    /**
     * @param  array<int, string>  $courierCodes
     */
    public static function buildCacheKey(
        string $baseUrl,
        string $originId,
        string $destinationId,
        int $weightGrams,
        float $itemValue,
        array $courierCodes
    ): string {
        $sortedCouriers = RajaOngkirShippingClient::normalizeCourierCodes($courierCodes);
        sort($sortedCouriers);

        $version = self::cacheVersion();
        $signature = implode('|', [
            rtrim(strtolower(trim($baseUrl)), '/'),
            trim($originId),
            trim($destinationId),
            (string) $weightGrams,
            number_format($itemValue, 2, '.', ''),
            implode(',', $sortedCouriers),
        ]);

        return 'shipping:rates:v2:'.$version.':'.sha1($signature);
    }

    public static function cacheVersion(): int
    {
        try {
            return max(1, (int) Cache::get(self::CACHE_VERSION_KEY, 1));
        } catch (Throwable) {
            return 1;
        }
    }

    public static function invalidateCache(): void
    {
        try {
            $nextVersion = self::cacheVersion() + 1;
            Cache::put(self::CACHE_VERSION_KEY, $nextVersion, now()->addDays(7));
        } catch (Throwable) {
            // Abaikan kegagalan cache saat bootstrap awal atau sebelum tabel cache tersedia.
        }
    }

    protected function resolveOwnedAddress(Customer $customer, CustomerAddress|int $address): CustomerAddress
    {
        if ($address instanceof CustomerAddress) {
            if ($address->trashed()) {
                throw ShippingRateException::invalidDestination('Alamat pengiriman yang dipilih sudah dihapus.');
            }

            Gate::forUser($customer)->authorize('view', $address);

            return $address;
        }

        $found = $customer->addresses()
            ->active()
            ->whereKey($address)
            ->first();

        if (! $found) {
            throw new AuthorizationException('Alamat pengiriman tidak ditemukan atau bukan milik Anda.');
        }

        return $found;
    }

    protected function resolveDestinationId(CustomerAddress $address): string
    {
        if (
            strtolower(trim((string) $address->province_id)) === 'manual'
            || strtolower(trim((string) $address->city_id)) === 'manual'
        ) {
            throw ShippingRateException::invalidDestination(
                'Alamat ini diisi secara manual dan belum memiliki ID wilayah pengiriman. Silakan pilih wilayah melalui pencarian wilayah terlebih dahulu.'
            );
        }

        $subdistrictId = trim((string) ($address->subdistrict_id ?? ''));
        if ($subdistrictId === '') {
            throw ShippingRateException::invalidDestination(
                'Alamat tujuan belum memiliki ID kelurahan/kecamatan pengiriman yang valid.'
            );
        }

        return RajaOngkirShippingClient::validateLocationId($subdistrictId, 'tujuan');
    }

    /**
     * @param  array<int, array{
     *     courier_code: string,
     *     courier_name: string,
     *     service_code: string,
     *     service_name: string,
     *     description: ?string,
     *     cost: int|float,
     *     etd: ?string
     * }>  $rawRates
     * @param  \Illuminate\Support\Collection<string, ShippingCourier>  $activeCouriers
     * @param  array<int, string>  $targetCodes
     * @return array<int, array{
     *     courier_code: string,
     *     courier_name: string,
     *     service_code: string,
     *     service_name: string,
     *     description: ?string,
     *     cost: int|float,
     *     etd: ?string
     * }>
     */
    protected function filterAndEnrichRates(array $rawRates, $activeCouriers, array $targetCodes): array
    {
        $filtered = [];

        foreach ($rawRates as $rate) {
            $courierCode = strtolower(trim($rate['courier_code']));

            if (! in_array($courierCode, $targetCodes, true) || ! $activeCouriers->has($courierCode)) {
                continue;
            }

            /** @var ShippingCourier $courier */
            $courier = $activeCouriers->get($courierCode);

            /** @var ?ShippingService $matchedService */
            $matchedService = $courier->services->first(
                static fn (ShippingService $service): bool => strtoupper(trim($service->service_code)) === strtoupper(trim($rate['service_code']))
            );

            // Jika layanan terdaftar di master lokal tetapi dinonaktifkan admin, kecualikan dari hasil.
            if ($matchedService !== null && ! $matchedService->is_active) {
                continue;
            }

            $filtered[] = [
                'courier_code' => $courierCode,
                'courier_name' => $courier->name ?: $rate['courier_name'],
                'service_code' => $rate['service_code'],
                'service_name' => $matchedService?->service_name ?: $rate['service_name'],
                'description' => $rate['description'],
                'cost' => $rate['cost'],
                'etd' => $rate['etd'],
            ];
        }

        return $filtered;
    }
}
