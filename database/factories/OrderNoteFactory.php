<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\Order;
use Blax\Shop\Models\OrderNote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class OrderNoteFactory extends Factory
{
    protected $model = OrderNote::class;

    public function definition(): array
    {
        return [
            'content' => $this->faker->sentence(),
            'type' => OrderNote::TYPE_NOTE,
            'is_customer_note' => false,
        ];
    }

    /**
     * Associate note with an order.
     */
    public function forOrder(Order $order): static
    {
        return $this->state([
            'order_id' => $order->id,
        ]);
    }

    /**
     * Set the author of the note.
     */
    public function byAuthor(Model $author): static
    {
        return $this->state([
            'author_type' => get_class($author),
            'author_id' => $author->getKey(),
        ]);
    }

    /**
     * Make it a customer-visible note.
     */
    public function forCustomer(): static
    {
        return $this->state([
            'is_customer_note' => true,
        ]);
    }

    /**
     * Make it an internal note.
     */
    public function internal(): static
    {
        return $this->state([
            'is_customer_note' => false,
        ]);
    }

    /**
     * Set as status change note.
     */
    public function statusChange(): static
    {
        return $this->state([
            'type' => OrderNote::TYPE_STATUS_CHANGE,
            'content' => 'Order status changed',
        ]);
    }

    /**
     * Set as payment note.
     */
    public function payment(int $amount = null): static
    {
        return $this->state([
            'type' => OrderNote::TYPE_PAYMENT,
            'content' => 'Payment received: ' . ($amount ? number_format($amount / 100, 2) : '100.00'),
            'meta' => $amount ? (object) ['amount' => $amount] : null,
        ]);
    }

    /**
     * Set as refund note.
     */
    public function refund(int $amount = null, string $reason = null): static
    {
        $content = 'Refund processed: ' . ($amount ? number_format($amount / 100, 2) : '50.00');
        if ($reason) {
            $content .= " - Reason: {$reason}";
        }

        return $this->state([
            'type' => OrderNote::TYPE_REFUND,
            'content' => $content,
            'meta' => (object) array_filter([
                'amount' => $amount,
                'reason' => $reason,
            ]),
        ]);
    }

    /**
     * Set as shipping note.
     */
    public function shipping(string $trackingNumber = null, string $carrier = null): static
    {
        $content = 'Order shipped';
        if ($trackingNumber) {
            $content .= " with tracking: {$trackingNumber}";
        }
        if ($carrier) {
            $content .= " via {$carrier}";
        }

        return $this->state([
            'type' => OrderNote::TYPE_SHIPPING,
            'content' => $content,
            'is_customer_note' => true,
            'meta' => (object) array_filter([
                'tracking_number' => $trackingNumber,
                'carrier' => $carrier,
            ]),
        ]);
    }

    /**
     * Set as system note.
     */
    public function system(): static
    {
        return $this->state([
            'type' => OrderNote::TYPE_SYSTEM,
        ]);
    }

    /**
     * Set as customer message.
     */
    public function customerMessage(): static
    {
        return $this->state([
            'type' => OrderNote::TYPE_CUSTOMER,
            'is_customer_note' => true,
        ]);
    }
}
