<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Shipping\RegionLookupException;
use App\Services\Shipping\RegionLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegionLookupController extends Controller
{
    public function __construct(
        protected RegionLookupService $regionLookupService
    ) {}

    public function provinces(): JsonResponse
    {
        return $this->respondWithLookup(
            fn (): array => $this->regionLookupService->provinces()
        );
    }

    public function cities(string $provinceId): JsonResponse
    {
        return $this->respondWithLookup(
            fn (): array => $this->regionLookupService->cities($provinceId)
        );
    }

    public function districts(string $cityId): JsonResponse
    {
        return $this->respondWithLookup(
            fn (): array => $this->regionLookupService->districts($cityId)
        );
    }

    public function subdistricts(string $districtId): JsonResponse
    {
        return $this->respondWithLookup(
            fn (): array => $this->regionLookupService->subdistricts($districtId)
        );
    }

    public function search(Request $request): JsonResponse
    {
        $keyword = (string) $request->query('keyword', '');
        $limit = max(1, min(50, (int) $request->query('limit', 10)));

        return $this->respondWithLookup(
            fn (): array => $this->regionLookupService->search($keyword, $limit)
        );
    }

    /**
     * @param  callable(): array<int, array<string, mixed>>  $callback
     */
    protected function respondWithLookup(callable $callback): JsonResponse
    {
        try {
            $items = $callback();

            return response()->json([
                'success' => true,
                'data' => $items,
            ]);
        } catch (RegionLookupException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'fallback_manual_allowed' => true,
                'data' => [],
            ], $e->statusCode());
        }
    }
}
