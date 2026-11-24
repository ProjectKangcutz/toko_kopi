<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreOrderRequest;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Services\Checkout\CheckoutOrderException;
use App\Services\Checkout\OrderCreationService;
use App\Services\Customer\CustomerAddressService;
use App\Services\Setting\StoreSettingService;
use App\Services\Shipping\ShippingRateException;
use App\Services\Shipping\ShippingRateService;
use App\Services\Storefront\CartService;
use App\Services\Storefront\HomepageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(
        protected CartService $cartService,
        protected CustomerAddressService $addressService,
        protected StoreSettingService $settingService,
        protected OrderCreationService $orderCreationService,
    ) {}

    public function index(Request $request): View
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        $cart = $this->cartService->getCart($customer);
        $validItems = collect($cart['items'] ?? [])
            ->filter(static fn (array $item): bool => (bool) ($item['is_available'] ?? false))
            ->values();

        $canCheckout = ((int) ($cart['valid_quantity'] ?? 0)) > 0 && $validItems->isNotEmpty();

        $addresses = $this->addressService->listAddresses($customer);
        /** @var ?CustomerAddress $selectedAddress */
        $selectedAddress = $addresses->firstWhere('is_primary', true) ?? $addresses->first();

        $subtotal = round((float) ($cart['subtotal'] ?? 0), 2);
        $discountAmount = 0.0;
        $taxPercentage = $this->resolveTaxPercentage();
        $taxAmount = $taxPercentage > 0
            ? round($subtotal * ($taxPercentage / 100), 2)
            : 0.0;

        $serializedItems = $validItems->map(static function (array $item): array {
            $variant = $item['variant'] ?? null;
            $product = $item['product'] ?? null;

            $attributes = [];
            if ($variant && $variant->relationLoaded('attributeValues')) {
                foreach ($variant->attributeValues as $attrValue) {
                    if ($attrValue->attribute && ! empty($attrValue->value)) {
                        $attributes[] = [
                            'name' => $attrValue->attribute->name,
                            'value' => $attrValue->value,
                        ];
                    }
                }
            }

            $productUrl = ($product && $product->is_active && ! empty($item['slug']))
                ? route('storefront.product', $item['slug'])
                : null;

            return [
                'key' => (string) $item['key'],
                'name' => (string) $item['name'],
                'sku' => $item['sku'],
                'product_url' => $productUrl,
                'variant_name' => $item['variant_name'],
                'variant_attributes' => $attributes,
                'image_url' => $item['image_url'] ?? HomepageService::mediaUrl($item['image_path'] ?? null),
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'regular_price' => (float) $item['regular_price'],
                'is_promo' => (bool) $item['is_promo'],
                'weight_grams' => (int) $item['weight_grams'],
                'line_subtotal' => (float) $item['line_subtotal'],
                'line_weight_grams' => (int) $item['line_weight_grams'],
            ];
        })->values()->all();

        $serializedAddresses = $addresses->map(static function (CustomerAddress $address): array {
            $hasValidRegion = strtolower(trim((string) $address->province_id)) !== 'manual'
                && strtolower(trim((string) $address->city_id)) !== 'manual'
                && trim((string) ($address->subdistrict_id ?? '')) !== '';

            return [
                'id' => (int) $address->id,
                'label' => (string) $address->label,
                'recipient_name' => (string) $address->recipient_name,
                'recipient_phone' => (string) $address->recipient_phone,
                'address_line' => (string) $address->address_line,
                'formatted_region' => $address->formattedRegion(),
                'postal_code' => (string) $address->postal_code,
                'is_primary' => (bool) $address->is_primary,
                'has_valid_region' => $hasValidRegion,
                'edit_url' => route('customer.addresses.edit', $address),
            ];
        })->values()->all();

        $serverSummary = [
            'valid_quantity' => (int) ($cart['valid_quantity'] ?? 0),
            'total_weight_grams' => (int) ($cart['total_weight_grams'] ?? 0),
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_percentage' => $taxPercentage,
            'tax_amount' => $taxAmount,
        ];

        $checkoutConfig = [
            'canCheckout' => $canCheckout,
            'quoteUrl' => ($canCheckout && $addresses->isNotEmpty()) ? route('customer.shipping.quote') : null,
            'storeUrl' => ($canCheckout && $addresses->isNotEmpty()) ? route('customer.checkout.store') : null,
            'initialAddressId' => ($canCheckout && $selectedAddress) ? (int) $selectedAddress->id : null,
            'addresses' => $canCheckout ? $serializedAddresses : [],
            'summary' => $serverSummary,
        ];

        $storeName = $this->resolveStoreName();

        $breadcrumbs = [
            ['label' => 'Beranda', 'url' => route('storefront.home')],
            ['label' => 'Keranjang Belanja', 'url' => route('storefront.cart')],
            ['label' => 'Checkout', 'url' => route('customer.checkout.index')],
        ];

        return view('customer.checkout.index', [
            'canCheckout' => $canCheckout,
            'cart' => $cart,
            'items' => $serializedItems,
            'addresses' => $serializedAddresses,
            'selectedAddress' => $selectedAddress,
            'serverSummary' => $serverSummary,
            'checkoutConfig' => $checkoutConfig,
            'breadcrumbs' => $breadcrumbs,
            'seo' => [
                'title' => 'Checkout — '.$storeName,
                'description' => 'Pilih alamat pengiriman dan layanan ekspedisi di '.$storeName.'.',
                'canonical' => route('customer.checkout.index'),
                'robots' => 'noindex,nofollow',
            ],
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        $validated = $request->validated();
        $addressId = (int) $validated['address_id'];

        $address = CustomerAddress::withTrashed()
            ->whereKey($addressId)
            ->firstOrFail();

        Gate::forUser($customer)->authorize('view', $address);

        try {
            $result = $this->orderCreationService->createFromCheckout($customer, $validated);
            $order = $result['order'];
            $isReplay = $result['idempotent_replay'];

            return response()->json([
                'success' => true,
                'message' => $isReplay
                    ? 'Pesanan Anda sudah berhasil dibuat sebelumnya.'
                    : 'Pesanan berhasil dibuat.',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => $order->order_status,
                    'payment_status' => $order->payment_status,
                    'shipment_status' => $order->shipment_status,
                    'grand_total' => (float) $order->grand_total,
                    'total_weight_grams' => (int) $order->total_weight_grams,
                    'payment_due_at' => $order->payment_due_at?->toIso8601String(),
                    'idempotent_replay' => $isReplay,
                    'redirect_url' => route('customer.orders.show', $order),
                ],
            ], $isReplay ? 200 : 201);
        } catch (CheckoutOrderException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'errors' => $e->errors(),
                'data' => null,
            ], $e->statusCode());
        } catch (ShippingRateException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'data' => null,
            ], $e->statusCode());
        }
    }

    protected function resolveTaxPercentage(): float
    {
        try {
            return max(0.0, (float) $this->settingService->get('store', 'tax_percentage', 0));
        } catch (Throwable) {
            return 0.0;
        }
    }

    protected function resolveStoreName(): string
    {
        try {
            return (string) ($this->settingService->publicSettings()['store_name'] ?? config('app.name', 'Pasar Lokal Nusantara'));
        } catch (Throwable) {
            return (string) config('app.name', 'Pasar Lokal Nusantara');
        }
    }
}
