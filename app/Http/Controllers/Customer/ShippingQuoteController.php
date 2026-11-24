<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ShippingQuoteRequest;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Services\Shipping\ShippingRateException;
use App\Services\Shipping\ShippingRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ShippingQuoteController extends Controller
{
    public function __construct(
        protected ShippingRateService $shippingRateService,
    ) {}

    public function quote(ShippingQuoteRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        $addressId = (int) $request->validated('address_id');
        $address = CustomerAddress::query()
            ->whereKey($addressId)
            ->firstOrFail();

        Gate::forUser($customer)->authorize('view', $address);

        try {
            // Semua parameter tarif (berat, nilai item, origin, destinasi, kurir aktif)
            // dihitung penuh di server oleh ShippingRateService tanpa input lain dari browser.
            $quote = $this->shippingRateService->calculateForCustomer($customer, $address);

            return response()->json([
                'success' => true,
                'data' => [
                    'address_id' => $address->id,
                    'origin_id' => $quote['origin_id'],
                    'destination_id' => $quote['destination_id'],
                    'actual_weight_grams' => $quote['actual_weight_grams'],
                    'billable_weight_grams' => $quote['billable_weight_grams'],
                    'item_value' => $quote['item_value'],
                    'courier_codes' => $quote['courier_codes'],
                    'from_cache' => $quote['from_cache'],
                    'rates' => $quote['rates'],
                ],
            ]);
        } catch (ShippingRateException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'data' => null,
            ], $e->statusCode());
        }
    }
}
