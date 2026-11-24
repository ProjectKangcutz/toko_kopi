<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Cache;

class RegionLookupService
{
    public const CACHE_TTL_SECONDS = 86400; // 24 jam

    public function __construct(
        protected KomerceRegionClient $client
    ) {}

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    public function provinces(): array
    {
        $cacheKey = self::provincesCacheKey();
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $items = $this->client->fetchProvinces();

        if ($items !== []) {
            Cache::put($cacheKey, $items, now()->addSeconds(self::CACHE_TTL_SECONDS));
        }

        return $items;
    }

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    public function cities(string $provinceId): array
    {
        $validatedId = KomerceRegionClient::validateParentId($provinceId);
        $cacheKey = self::citiesCacheKey($validatedId);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $items = $this->client->fetchCities($validatedId);

        if ($items !== []) {
            Cache::put($cacheKey, $items, now()->addSeconds(self::CACHE_TTL_SECONDS));
        }

        return $items;
    }

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    public function districts(string $cityId): array
    {
        $validatedId = KomerceRegionClient::validateParentId($cityId);
        $cacheKey = self::districtsCacheKey($validatedId);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $items = $this->client->fetchDistricts($validatedId);

        if ($items !== []) {
            Cache::put($cacheKey, $items, now()->addSeconds(self::CACHE_TTL_SECONDS));
        }

        return $items;
    }

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    public function subdistricts(string $districtId): array
    {
        $validatedId = KomerceRegionClient::validateParentId($districtId);
        $cacheKey = self::subdistrictsCacheKey($validatedId);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $items = $this->client->fetchSubdistricts($validatedId);

        if ($items !== []) {
            Cache::put($cacheKey, $items, now()->addSeconds(self::CACHE_TTL_SECONDS));
        }

        return $items;
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     label: string,
     *     province_name: ?string,
     *     city_name: ?string,
     *     district_name: ?string,
     *     subdistrict_name: ?string,
     *     zip_code: ?string
     * }>
     */
    public function search(string $keyword, int $limit = 10): array
    {
        $validatedKeyword = KomerceRegionClient::validateSearchKeyword($keyword);
        $safeLimit = max(1, min(50, $limit));
        $cacheKey = self::searchCacheKey($validatedKeyword, $safeLimit);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $items = $this->client->searchDestinations($validatedKeyword, $safeLimit);

        if ($items !== []) {
            Cache::put($cacheKey, $items, now()->addSeconds(self::CACHE_TTL_SECONDS));
        }

        return $items;
    }

    public static function provincesCacheKey(): string
    {
        return 'regions:komerce:v2:provinces';
    }

    public static function citiesCacheKey(string $provinceId): string
    {
        return 'regions:komerce:v2:cities:'.$provinceId;
    }

    public static function districtsCacheKey(string $cityId): string
    {
        return 'regions:komerce:v2:districts:'.$cityId;
    }

    public static function subdistrictsCacheKey(string $districtId): string
    {
        return 'regions:komerce:v2:subdistricts:'.$districtId;
    }

    public static function searchCacheKey(string $keyword, int $limit = 10): string
    {
        return 'regions:komerce:search:'.sha1(mb_strtolower(trim($keyword)).'|'.$limit);
    }
}
