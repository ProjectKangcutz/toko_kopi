<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(Customer|User $actor, Order $order): bool
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('orders.view');
        }

        return $order->customer_id !== null && (int) $order->customer_id === (int) $actor->id;
    }

    public function cancel(Customer|User $actor, Order $order): bool
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('orders.cancel');
        }

        return $order->customer_id !== null
            && (int) $order->customer_id === (int) $actor->id
            && in_array($order->order_status, ['pending', 'awaiting_payment'], true)
            && ! in_array($order->payment_status, ['paid', 'refunded', 'partially_refunded'], true)
            && $order->paid_at === null;
    }

    public function updateStatus(Customer|User $actor, Order $order): bool
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('orders.update_status');
        }

        return false;
    }

    public function updateShipment(Customer|User $actor, Order $order): bool
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('shipments.update')
                || $actor->hasPermission('orders.update_status');
        }

        return false;
    }

    public function complete(Customer|User $actor, Order $order): bool
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('orders.update_status')
                && $order->order_status === 'delivered';
        }

        return $order->customer_id !== null
            && (int) $order->customer_id === (int) $actor->id
            && in_array($order->order_status, ['shipped', 'delivered'], true);
    }
}
