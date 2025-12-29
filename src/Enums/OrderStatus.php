<?php

namespace Blax\Shop\Enums;

/**
 * Order status enum representing the lifecycle of an order.
 * 
 * Inspired by WooCommerce order statuses with additional e-commerce best practices.
 */
enum OrderStatus: string
{
    /**
     * Order received but awaiting payment confirmation.
     */
    case PENDING = 'pending';

    /**
     * Payment received and order is being processed.
     */
    case PROCESSING = 'processing';

    /**
     * Order is on hold, awaiting further action (manual review, stock, etc.)
     */
    case ON_HOLD = 'on_hold';

    /**
     * Order is being prepared (packing, manufacturing, etc.)
     */
    case IN_PREPARATION = 'in_preparation';

    /**
     * Order is ready for pickup (for local pickup orders).
     */
    case READY_FOR_PICKUP = 'ready_for_pickup';

    /**
     * Order has been shipped and is in transit.
     */
    case SHIPPED = 'shipped';

    /**
     * Order has been delivered to the customer.
     */
    case DELIVERED = 'delivered';

    /**
     * Order is complete - all actions have been fulfilled.
     */
    case COMPLETED = 'completed';

    /**
     * Order has been cancelled.
     */
    case CANCELLED = 'cancelled';

    /**
     * Order has been fully or partially refunded.
     */
    case REFUNDED = 'refunded';

    /**
     * Order payment or processing failed.
     */
    case FAILED = 'failed';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Payment',
            self::PROCESSING => 'Processing',
            self::ON_HOLD => 'On Hold',
            self::IN_PREPARATION => 'In Preparation',
            self::READY_FOR_PICKUP => 'Ready for Pickup',
            self::SHIPPED => 'Shipped',
            self::DELIVERED => 'Delivered',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            self::REFUNDED => 'Refunded',
            self::FAILED => 'Failed',
        };
    }

    /**
     * Get a color code for the status (for UI purposes).
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'yellow',
            self::PROCESSING => 'blue',
            self::ON_HOLD => 'orange',
            self::IN_PREPARATION => 'indigo',
            self::READY_FOR_PICKUP => 'teal',
            self::SHIPPED => 'purple',
            self::DELIVERED => 'green',
            self::COMPLETED => 'green',
            self::CANCELLED => 'gray',
            self::REFUNDED => 'red',
            self::FAILED => 'red',
        };
    }

    /**
     * Check if this status indicates the order is still active/pending.
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::PROCESSING,
            self::ON_HOLD,
            self::IN_PREPARATION,
            self::READY_FOR_PICKUP,
            self::SHIPPED,
        ]);
    }

    /**
     * Check if this status indicates the order is finalized.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::CANCELLED,
            self::REFUNDED,
            self::FAILED,
            self::DELIVERED,
        ]);
    }

    /**
     * Check if this status requires payment.
     */
    public function requiresPayment(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if this status indicates successful payment.
     */
    public function isPaid(): bool
    {
        return in_array($this, [
            self::PROCESSING,
            self::IN_PREPARATION,
            self::READY_FOR_PICKUP,
            self::SHIPPED,
            self::DELIVERED,
            self::COMPLETED,
        ]);
    }

    /**
     * Get valid transitions from this status.
     * 
     * @return array<OrderStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [
                self::PROCESSING,
                self::ON_HOLD,
                self::CANCELLED,
                self::FAILED,
            ],
            self::PROCESSING => [
                self::IN_PREPARATION,
                self::READY_FOR_PICKUP,
                self::SHIPPED,
                self::COMPLETED,
                self::ON_HOLD,
                self::CANCELLED,
                self::REFUNDED,
            ],
            self::ON_HOLD => [
                self::PENDING,
                self::PROCESSING,
                self::CANCELLED,
            ],
            self::IN_PREPARATION => [
                self::READY_FOR_PICKUP,
                self::SHIPPED,
                self::COMPLETED,
                self::ON_HOLD,
                self::CANCELLED,
            ],
            self::READY_FOR_PICKUP => [
                self::COMPLETED,
                self::DELIVERED,
                self::ON_HOLD,
                self::CANCELLED,
            ],
            self::SHIPPED => [
                self::DELIVERED,
                self::COMPLETED,
                self::REFUNDED,
            ],
            self::DELIVERED => [
                self::COMPLETED,
                self::REFUNDED,
            ],
            self::COMPLETED => [
                self::REFUNDED,
            ],
            self::CANCELLED => [],
            self::REFUNDED => [],
            self::FAILED => [
                self::PENDING,
            ],
        };
    }

    /**
     * Check if a transition to the given status is allowed.
     */
    public function canTransitionTo(OrderStatus $status): bool
    {
        return in_array($status, $this->allowedTransitions());
    }
}
