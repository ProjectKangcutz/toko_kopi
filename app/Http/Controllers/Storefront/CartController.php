<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\AddToCartRequest;
use App\Http\Requests\Storefront\UpdateCartItemRequest;
use App\Models\CartItem;
use App\Models\Customer;
use App\Services\Setting\StoreSettingService;
use App\Services\Storefront\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService,
        protected StoreSettingService $settingService,
    ) {
    }

    public function index(): View
    {
        $cart = $this->cartService->getCart();
        $cartSnapshot = $this->serializeCartSnapshot($cart);
        $storeName = $this->resolveStoreName();

        $breadcrumbs = [
            ['label' => 'Beranda', 'url' => route('storefront.home')],
            ['label' => 'Keranjang Belanja', 'url' => route('storefront.cart')],
        ];

        return view('storefront.cart.index', [
            'cart' => $cart,
            'cartSnapshot' => $cartSnapshot,
            'breadcrumbs' => $breadcrumbs,
            'seo' => [
                'title' => 'Keranjang Belanja — '.$storeName,
                'description' => 'Kelola produk belanja Anda di '.$storeName.' sebelum melanjutkan ke tahap pemesanan.',
                'canonical' => route('storefront.cart'),
                'robots' => 'noindex,nofollow',
            ],
        ]);
    }

    public function store(AddToCartRequest $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();

        $result = $this->cartService->addItem(
            productId: (int) $validated['product_id'],
            variantId: isset($validated['product_variant_id']) ? (int) $validated['product_variant_id'] : null,
            quantity: (int) $validated['quantity'],
        );

        $message = 'Produk berhasil ditambahkan ke keranjang.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'cart_item_count' => $result['total_quantity'],
                'item' => $result,
            ]);
        }

        return back()->with('success', $message);
    }

    public function update(UpdateCartItemRequest $request, string $item): JsonResponse|RedirectResponse
    {
        $this->cartService->updateItem($item, (int) $request->validated('quantity'));

        $cart = $this->cartService->getCart();
        $cartSnapshot = $this->serializeCartSnapshot($cart);
        $headerCount = (int) $cartSnapshot['valid_quantity'];
        $message = 'Kuantitas produk berhasil diperbarui.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'cart_item_count' => $headerCount,
                'cart' => $cartSnapshot,
            ]);
        }

        return redirect()->route('storefront.cart')->with('success', $message);
    }

    public function destroy(Request $request, string $item): JsonResponse|RedirectResponse
    {
        $customer = Auth::guard('customer')->user();
        if ($customer instanceof Customer) {
            if ($customer->status !== 'active') {
                abort(403, 'Akun pelanggan Anda sedang dinonaktifkan.');
            }

            if (is_numeric($item)) {
                $cartItem = CartItem::query()->with('cart')->find((int) $item);
                if ($cartItem !== null && $customer->cannot('delete', $cartItem)) {
                    abort(403, 'Anda tidak memiliki izin menghapus item keranjang ini.');
                }
            }
        }

        $removed = $this->cartService->removeItem($item);
        $cart = $this->cartService->getCart();
        $cartSnapshot = $this->serializeCartSnapshot($cart);
        $headerCount = (int) $cartSnapshot['valid_quantity'];

        $message = $removed
            ? 'Produk berhasil dihapus dari keranjang.'
            : 'Item tidak ditemukan atau sudah dihapus dari keranjang.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => $removed,
                'message' => $message,
                'cart_item_count' => $headerCount,
                'cart' => $cartSnapshot,
            ]);
        }

        return redirect()->route('storefront.cart')
            ->with($removed ? 'success' : 'error', $message);
    }

    public function clear(Request $request): JsonResponse|RedirectResponse
    {
        $this->cartService->clearCart();

        $cart = $this->cartService->getCart();
        $cartSnapshot = $this->serializeCartSnapshot($cart);
        $headerCount = (int) $cartSnapshot['valid_quantity'];
        $message = 'Keranjang belanja berhasil dikosongkan.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'cart_item_count' => $headerCount,
                'cart' => $cartSnapshot,
            ]);
        }

        return redirect()->route('storefront.cart')->with('success', $message);
    }

    protected function serializeCartSnapshot(array $cart): array
    {
        $items = collect($cart['items'] ?? [])->map(function (array $item) {
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
                'identifier' => (string) $item['identifier'],
                'key' => (string) $item['key'],
                'product_id' => (int) $item['product_id'],
                'product_variant_id' => $item['product_variant_id'] ? (int) $item['product_variant_id'] : null,
                'name' => (string) $item['name'],
                'slug' => $item['slug'],
                'product_url' => $productUrl,
                'sku' => $item['sku'],
                'variant_name' => $item['variant_name'],
                'variant_attributes' => $attributes,
                'image_url' => $item['image_url'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'regular_price' => (float) $item['regular_price'],
                'is_promo' => (bool) $item['is_promo'],
                'available_stock' => (int) $item['available_stock'],
                'weight_grams' => (int) $item['weight_grams'],
                'line_subtotal' => (float) $item['line_subtotal'],
                'line_weight_grams' => (int) $item['line_weight_grams'],
                'is_available' => (bool) $item['is_available'],
                'unavailable_reason' => $item['unavailable_reason'],
                'update_url' => route('storefront.cart.update', ['item' => (string) $item['identifier']]),
                'remove_url' => route('storefront.cart.remove', ['item' => (string) $item['identifier']]),
            ];
        })->values()->all();

        return [
            'mode' => (string) ($cart['mode'] ?? 'guest'),
            'total_quantity' => (int) ($cart['total_quantity'] ?? 0),
            'valid_quantity' => (int) ($cart['valid_quantity'] ?? 0),
            'subtotal' => (float) ($cart['subtotal'] ?? 0.0),
            'total_weight_grams' => (int) ($cart['total_weight_grams'] ?? 0),
            'has_unavailable_items' => (bool) ($cart['has_unavailable_items'] ?? false),
            'warnings' => array_values($cart['warnings'] ?? []),
            'items' => $items,
        ];
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
