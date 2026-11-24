<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SaveAddressRequest;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Services\Customer\CustomerAddressService;
use App\Services\Security\AuditLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class AddressController extends Controller
{
    public function __construct(
        protected CustomerAddressService $addressService,
    ) {
    }

    public function index(Request $request): View|JsonResponse
    {
        $customer = $this->authenticatedCustomer();
        Gate::forUser($customer)->authorize('viewAny', CustomerAddress::class);

        $addresses = $this->addressService->listAddresses($customer);

        if ($request->expectsJson()) {
            return response()->json([
                'addresses' => $addresses,
            ]);
        }

        return view('customer.addresses.index', [
            'addresses' => $addresses,
        ]);
    }

    public function create(Request $request): View|JsonResponse
    {
        $customer = $this->authenticatedCustomer();
        Gate::forUser($customer)->authorize('create', CustomerAddress::class);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Form tambah alamat tersedia.',
            ]);
        }

        return view('customer.addresses.create', [
            'hasAddresses' => $this->addressService->listAddresses($customer)->isNotEmpty(),
        ]);
    }

    public function store(SaveAddressRequest $request): RedirectResponse|JsonResponse
    {
        $customer = $this->authenticatedCustomer();
        Gate::forUser($customer)->authorize('create', CustomerAddress::class);

        $address = $this->addressService->create($customer, $request->validated());

        AuditLogService::record(
            'customer',
            $customer->id,
            'customer.address.created',
            'Pelanggan menambahkan alamat pengiriman.',
            $address,
            [
                'address_id' => $address->id,
                'label' => $address->label,
                'is_primary' => $address->is_primary,
            ],
            $request
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Alamat baru berhasil ditambahkan.',
                'address' => $address,
            ], 201);
        }

        return redirect()->route('customer.addresses.index')
            ->with('success', 'Alamat baru berhasil ditambahkan.');
    }

    public function edit(Request $request, CustomerAddress $address): View|JsonResponse
    {
        $customer = $this->authenticatedCustomer();
        Gate::forUser($customer)->authorize('update', $address);

        if ($request->expectsJson()) {
            return response()->json([
                'address' => $address,
            ]);
        }

        return view('customer.addresses.edit', [
            'address' => $address,
            'hasOtherAddresses' => $customer->addresses()
                ->active()
                ->whereKeyNot($address->id)
                ->exists(),
        ]);
    }

    public function update(SaveAddressRequest $request, CustomerAddress $address): RedirectResponse|JsonResponse
    {
        $customer = $this->authenticatedCustomer();
        Gate::forUser($customer)->authorize('update', $address);

        $updatedAddress = $this->addressService->update($customer, $address, $request->validated());

        AuditLogService::record(
            'customer',
            $customer->id,
            'customer.address.updated',
            'Pelanggan memperbarui alamat pengiriman.',
            $updatedAddress,
            [
                'address_id' => $updatedAddress->id,
                'label' => $updatedAddress->label,
                'is_primary' => $updatedAddress->is_primary,
            ],
            $request
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Alamat berhasil diperbarui.',
                'address' => $updatedAddress,
            ]);
        }

        return redirect()->route('customer.addresses.index')
            ->with('success', 'Alamat berhasil diperbarui.');
    }

    public function setPrimary(Request $request, CustomerAddress $address): RedirectResponse|JsonResponse
    {
        $customer = $this->authenticatedCustomer();
        Gate::forUser($customer)->authorize('setPrimary', $address);

        $primaryAddress = $this->addressService->setPrimary($customer, $address);

        AuditLogService::record(
            'customer',
            $customer->id,
            'customer.address.set_primary',
            'Pelanggan menetapkan alamat utama.',
            $primaryAddress,
            [
                'address_id' => $primaryAddress->id,
                'label' => $primaryAddress->label,
            ],
            $request
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Alamat utama berhasil diperbarui.',
                'address' => $primaryAddress,
            ]);
        }

        return redirect()->route('customer.addresses.index')
            ->with('success', 'Alamat utama berhasil diperbarui.');
    }

    public function destroy(Request $request, CustomerAddress $address): RedirectResponse|JsonResponse
    {
        $customer = $this->authenticatedCustomer();
        Gate::forUser($customer)->authorize('delete', $address);

        $deletedAddressId = $address->id;
        $deletedLabel = $address->label;
        $promotedAddress = $this->addressService->delete($customer, $address);

        AuditLogService::record(
            'customer',
            $customer->id,
            'customer.address.deleted',
            'Pelanggan menghapus alamat pengiriman.',
            $address,
            array_filter([
                'address_id' => $deletedAddressId,
                'label' => $deletedLabel,
                'promoted_primary_address_id' => $promotedAddress?->id,
            ], static fn ($value): bool => $value !== null),
            $request
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Alamat berhasil dihapus.',
                'promoted_primary_address' => $promotedAddress,
            ]);
        }

        return redirect()->route('customer.addresses.index')
            ->with('success', 'Alamat berhasil dihapus.');
    }

    private function authenticatedCustomer(): Customer
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        return $customer;
    }
}
