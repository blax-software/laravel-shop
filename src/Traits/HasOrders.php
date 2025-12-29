<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Models\Order;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasOrders
{
    /**
     * Get all orders for this customer.
     */
    public function orders(): MorphMany
    {
        return $this->morphMany(
            config('shop.models.order', Order::class),
            'customer'
        );
    }

    /**
     * Get orders with a specific status.
     */
    public function ordersWithStatus(OrderStatus $status): MorphMany
    {
        return $this->orders()->where('status', $status->value);
    }

    /**
     * Get pending orders.
     */
    public function pendingOrders(): MorphMany
    {
        return $this->ordersWithStatus(OrderStatus::PENDING);
    }

    /**
     * Get processing orders.
     */
    public function processingOrders(): MorphMany
    {
        return $this->ordersWithStatus(OrderStatus::PROCESSING);
    }

    /**
     * Get completed orders.
     */
    public function completedOrders(): MorphMany
    {
        return $this->ordersWithStatus(OrderStatus::COMPLETED);
    }

    /**
     * Get active orders (not in a final state).
     */
    public function activeOrders(): MorphMany
    {
        return $this->orders()->whereIn('status', [
            OrderStatus::PENDING->value,
            OrderStatus::PROCESSING->value,
            OrderStatus::ON_HOLD->value,
            OrderStatus::IN_PREPARATION->value,
            OrderStatus::READY_FOR_PICKUP->value,
            OrderStatus::SHIPPED->value,
        ]);
    }

    /**
     * Get orders that have been paid.
     */
    public function paidOrders(): MorphMany
    {
        return $this->orders()->where('amount_paid', '>', 0);
    }

    /**
     * Get fully paid orders.
     */
    public function fullyPaidOrders(): MorphMany
    {
        return $this->orders()->whereColumn('amount_paid', '>=', 'amount_total');
    }

    /**
     * Get orders within a date range.
     */
    public function ordersBetween(\DateTimeInterface $from, \DateTimeInterface $to): MorphMany
    {
        return $this->orders()->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Get the most recent order.
     */
    public function latestOrder(): ?Order
    {
        return $this->orders()->latest('created_at')->latest('id')->first();
    }

    /**
     * Get total amount spent by this customer (sum of amount_paid across all orders).
     */
    public function getTotalSpentAttribute(): int
    {
        return $this->orders()->sum('amount_paid') ?? 0;
    }

    /**
     * Get total number of completed orders.
     */
    public function getOrderCountAttribute(): int
    {
        return $this->orders()->count();
    }

    /**
     * Get total number of completed orders.
     */
    public function getCompletedOrderCountAttribute(): int
    {
        return $this->completedOrders()->count();
    }

    /**
     * Check if the customer has any orders.
     */
    public function hasOrders(): bool
    {
        return $this->orders()->exists();
    }

    /**
     * Check if the customer has any active orders.
     */
    public function hasActiveOrders(): bool
    {
        return $this->activeOrders()->exists();
    }

    /**
     * Find an order by order number.
     */
    public function findOrderByNumber(string $orderNumber): ?Order
    {
        return $this->orders()->where('order_number', $orderNumber)->first();
    }
}
