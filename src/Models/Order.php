<?php

namespace Blax\Shop\Models;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Order model representing a completed/paid cart.
 * 
 * Orders are created when a cart is converted (checked out) and represent
 * a customer's purchase transaction with full tracking capabilities.
 */
class Order extends Model
{
    use HasUuids, HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'cart_id',
        'customer_type',
        'customer_id',
        'status',
        'currency',
        'amount_subtotal',
        'amount_discount',
        'amount_shipping',
        'amount_tax',
        'amount_total',
        'amount_paid',
        'amount_refunded',
        'payment_method',
        'payment_provider',
        'payment_reference',
        'billing_address',
        'shipping_address',
        'customer_note',
        'internal_note',
        'ip_address',
        'user_agent',
        'completed_at',
        'paid_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'refunded_at',
        'meta',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'amount_subtotal' => 'integer',
        'amount_discount' => 'integer',
        'amount_shipping' => 'integer',
        'amount_tax' => 'integer',
        'amount_total' => 'integer',
        'amount_paid' => 'integer',
        'amount_refunded' => 'integer',
        'billing_address' => 'object',
        'shipping_address' => 'object',
        'meta' => 'object',
        'completed_at' => 'datetime',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    protected $appends = [
        'amount_outstanding',
        'is_paid',
        'is_fully_paid',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.orders', 'orders'));
    }

    protected static function booted()
    {
        static::creating(function (Order $order) {
            // Generate order number if not set
            if (empty($order->order_number)) {
                $order->order_number = static::generateOrderNumber();
            }

            // Set default status
            if (empty($order->status)) {
                $order->status = OrderStatus::PENDING;
            }

            // Initialize amounts if not set
            $order->amount_paid = $order->amount_paid ?? 0;
            $order->amount_refunded = $order->amount_refunded ?? 0;
        });

        static::updating(function (Order $order) {
            // Log status changes
            if ($order->isDirty('status')) {
                $oldStatus = $order->getOriginal('status');
                $newStatus = $order->status;

                $order->addNote(
                    "Order status changed from {$oldStatus->label()} to {$newStatus->label()}",
                    'status_change',
                    false
                );

                // Set timestamp fields based on status
                if ($newStatus === OrderStatus::COMPLETED && !$order->completed_at) {
                    $order->completed_at = now();
                }
                if ($newStatus === OrderStatus::SHIPPED && !$order->shipped_at) {
                    $order->shipped_at = now();
                }
                if ($newStatus === OrderStatus::DELIVERED && !$order->delivered_at) {
                    $order->delivered_at = now();
                }
                if ($newStatus === OrderStatus::CANCELLED && !$order->cancelled_at) {
                    $order->cancelled_at = now();
                }
                if ($newStatus === OrderStatus::REFUNDED && !$order->refunded_at) {
                    $order->refunded_at = now();
                }
            }

            // Track payment changes
            if ($order->isDirty('amount_paid')) {
                $oldPaid = $order->getOriginal('amount_paid') ?? 0;
                $newPaid = $order->amount_paid;
                $difference = $newPaid - $oldPaid;

                if ($difference > 0) {
                    $order->addNote(
                        "Payment received: " . static::formatMoney($difference, $order->currency),
                        'payment',
                        false
                    );

                    // Mark as paid if fully paid
                    if (!$order->paid_at && $newPaid >= $order->amount_total) {
                        $order->paid_at = now();
                    }
                }
            }
        });
    }

    /**
     * Generate a unique order number.
     */
    public static function generateOrderNumber(): string
    {
        $prefix = config('shop.orders.number_prefix', 'ORD-');
        $date = now()->format('Ymd');

        // Find the last order number for today
        $lastOrder = static::where('order_number', 'like', "{$prefix}{$date}%")
            ->orderBy('order_number', 'desc')
            ->first();

        if ($lastOrder) {
            // Extract the sequence number and increment
            $lastNumber = (int) substr($lastOrder->order_number, strlen("{$prefix}{$date}"));
            $sequence = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $sequence = '0001';
        }

        return "{$prefix}{$date}{$sequence}";
    }

    /**
     * Format money amount for display.
     */
    public static function formatMoney(int $amount, string $currency = 'USD'): string
    {
        $formatted = number_format($amount / 100, 2);
        return strtoupper($currency) . ' ' . $formatted;
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The cart this order was created from.
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.cart', Cart::class), 'cart_id');
    }

    /**
     * The customer who placed this order.
     */
    public function customer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Order notes and activity log.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(config('shop.models.order_note', OrderNote::class), 'order_id')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get the purchases associated with this order through the cart.
     */
    public function purchases(): HasManyThrough
    {
        return $this->hasManyThrough(
            config('shop.models.product_purchase', ProductPurchase::class),
            config('shop.models.cart', Cart::class),
            'id',         // Foreign key on carts table (Cart.id)
            'cart_id',    // Foreign key on product_purchases table (ProductPurchase.cart_id)
            'cart_id',    // Local key on orders table (Order.cart_id)
            'id'          // Local key on carts table (Cart.id)
        );
    }

    /**
     * Direct access to purchases via cart_id.
     */
    public function directPurchases(): HasMany
    {
        return $this->hasMany(
            config('shop.models.product_purchase', ProductPurchase::class),
            'cart_id',
            'cart_id'
        );
    }

    // =========================================================================
    // COMPUTED ATTRIBUTES
    // =========================================================================

    /**
     * Get the outstanding amount (amount_total - amount_paid + amount_refunded).
     */
    public function getAmountOutstandingAttribute(): int
    {
        return max(0, ($this->amount_total ?? 0) - ($this->amount_paid ?? 0));
    }

    /**
     * Check if any payment has been received.
     */
    public function getIsPaidAttribute(): bool
    {
        return ($this->amount_paid ?? 0) > 0;
    }

    /**
     * Check if the order is fully paid.
     */
    public function getIsFullyPaidAttribute(): bool
    {
        return ($this->amount_paid ?? 0) >= ($this->amount_total ?? 0);
    }

    // =========================================================================
    // STATUS MANAGEMENT
    // =========================================================================

    /**
     * Update the order status with validation.
     * 
     * @throws \InvalidArgumentException if transition is not allowed
     */
    public function updateStatus(OrderStatus $newStatus, ?string $note = null): self
    {
        if ($this->status && !$this->status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition order from '{$this->status->label()}' to '{$newStatus->label()}'"
            );
        }

        $this->status = $newStatus;
        $this->save();

        if ($note) {
            $this->addNote($note, 'status_change');
        }

        return $this;
    }

    /**
     * Force update status without transition validation.
     */
    public function forceStatus(OrderStatus $newStatus, ?string $note = null): self
    {
        $this->status = $newStatus;
        $this->save();

        if ($note) {
            $this->addNote($note, 'status_change');
        }

        return $this;
    }

    /**
     * Mark order as processing (payment received).
     */
    public function markAsProcessing(?string $note = null): self
    {
        return $this->updateStatus(OrderStatus::PROCESSING, $note ?? 'Payment confirmed, order is being processed');
    }

    /**
     * Mark order as in preparation.
     */
    public function markAsInPreparation(?string $note = null): self
    {
        return $this->updateStatus(OrderStatus::IN_PREPARATION, $note ?? 'Order is being prepared');
    }

    /**
     * Mark order as shipped.
     */
    public function markAsShipped(?string $trackingNumber = null, ?string $carrier = null): self
    {
        $note = 'Order has been shipped';
        if ($trackingNumber) {
            $note .= " with tracking number: {$trackingNumber}";
            $this->updateMetaKey('tracking_number', $trackingNumber);
        }
        if ($carrier) {
            $note .= " via {$carrier}";
            $this->updateMetaKey('shipping_carrier', $carrier);
        }

        return $this->updateStatus(OrderStatus::SHIPPED, $note);
    }

    /**
     * Mark order as delivered.
     */
    public function markAsDelivered(?string $note = null): self
    {
        return $this->updateStatus(OrderStatus::DELIVERED, $note ?? 'Order has been delivered');
    }

    /**
     * Mark order as completed.
     */
    public function markAsCompleted(?string $note = null): self
    {
        return $this->updateStatus(OrderStatus::COMPLETED, $note ?? 'Order completed');
    }

    /**
     * Cancel the order.
     */
    public function cancel(?string $reason = null): self
    {
        return $this->updateStatus(OrderStatus::CANCELLED, $reason ?? 'Order cancelled');
    }

    /**
     * Put order on hold.
     */
    public function hold(?string $reason = null): self
    {
        return $this->updateStatus(OrderStatus::ON_HOLD, $reason ?? 'Order placed on hold');
    }

    // =========================================================================
    // PAYMENT MANAGEMENT
    // =========================================================================

    /**
     * Record a payment for this order.
     */
    public function recordPayment(
        int $amount,
        ?string $reference = null,
        ?string $method = null,
        ?string $provider = null
    ): self {
        DB::transaction(function () use ($amount, $reference, $method, $provider) {
            $this->amount_paid = ($this->amount_paid ?? 0) + $amount;

            if ($reference) {
                $this->payment_reference = $reference;
            }
            if ($method) {
                $this->payment_method = $method;
            }
            if ($provider) {
                $this->payment_provider = $provider;
            }

            $this->save();

            // Update associated purchases to paid status
            if ($this->is_fully_paid) {
                $this->directPurchases()->update([
                    'status' => PurchaseStatus::COMPLETED,
                    'amount_paid' => DB::raw('amount'),
                ]);

                // Move to processing if still pending
                if ($this->status === OrderStatus::PENDING) {
                    $this->markAsProcessing();
                }
            }
        });

        return $this;
    }

    /**
     * Record a refund for this order.
     */
    public function recordRefund(int $amount, ?string $reason = null): self
    {
        DB::transaction(function () use ($amount, $reason) {
            $this->amount_refunded = ($this->amount_refunded ?? 0) + $amount;
            $this->save();

            $this->addNote(
                "Refund processed: " . static::formatMoney($amount, $this->currency) .
                    ($reason ? " - Reason: {$reason}" : ''),
                'refund'
            );

            // If fully refunded, update status
            if ($this->amount_refunded >= $this->amount_paid) {
                $this->forceStatus(OrderStatus::REFUNDED);
            }
        });

        return $this;
    }

    // =========================================================================
    // NOTES MANAGEMENT
    // =========================================================================

    /**
     * Add a note to the order.
     */
    public function addNote(
        string $content,
        string $type = 'note',
        bool $isCustomerNote = false,
        ?string $authorType = null,
        ?string $authorId = null
    ): OrderNote {
        return $this->notes()->create([
            'content' => $content,
            'type' => $type,
            'is_customer_note' => $isCustomerNote,
            'author_type' => $authorType,
            'author_id' => $authorId,
        ]);
    }

    /**
     * Get customer-visible notes only.
     */
    public function customerNotes(): HasMany
    {
        return $this->notes()->where('is_customer_note', true);
    }

    /**
     * Get internal notes only (not visible to customer).
     */
    public function internalNotes(): HasMany
    {
        return $this->notes()->where('is_customer_note', false);
    }

    // =========================================================================
    // META HELPERS
    // =========================================================================

    /**
     * Get a value from the meta object.
     */
    public function getMeta(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->meta ?? new \stdClass();
        }

        return $this->meta?->{$key} ?? $default;
    }

    /**
     * Update a key in the meta object.
     */
    public function updateMetaKey(string $key, $value): self
    {
        $meta = (array) ($this->meta ?? new \stdClass());
        $meta[$key] = $value;
        $this->meta = (object) $meta;
        $this->save();

        return $this;
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, OrderStatus $status)
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope to filter by multiple statuses.
     */
    public function scopeWithStatuses($query, array $statuses)
    {
        return $query->whereIn('status', array_map(fn($s) => $s->value, $statuses));
    }

    /**
     * Scope to get active orders (not final).
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            OrderStatus::PENDING->value,
            OrderStatus::PROCESSING->value,
            OrderStatus::ON_HOLD->value,
            OrderStatus::IN_PREPARATION->value,
            OrderStatus::READY_FOR_PICKUP->value,
            OrderStatus::SHIPPED->value,
        ]);
    }

    /**
     * Scope to get completed orders.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', OrderStatus::COMPLETED->value);
    }

    /**
     * Scope to get paid orders.
     */
    public function scopePaid($query)
    {
        return $query->whereColumn('amount_paid', '>=', 'amount_total');
    }

    /**
     * Scope to get unpaid orders.
     */
    public function scopeUnpaid($query)
    {
        return $query->whereColumn('amount_paid', '<', 'amount_total');
    }

    /**
     * Scope to filter by customer.
     */
    public function scopeForCustomer($query, Model $customer)
    {
        return $query->where('customer_type', get_class($customer))
            ->where('customer_id', $customer->getKey());
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeCreatedBetween($query, $from, $until)
    {
        return $query->whereBetween('created_at', [$from, $until]);
    }

    /**
     * Scope to filter by payment provider.
     */
    public function scopeByPaymentProvider($query, string $provider)
    {
        return $query->where('payment_provider', $provider);
    }

    /**
     * Scope to filter by payment method.
     */
    public function scopeByPaymentMethod($query, string $method)
    {
        return $query->where('payment_method', $method);
    }

    /**
     * Scope to get orders with refunds.
     */
    public function scopeWithRefunds($query)
    {
        return $query->where('amount_refunded', '>', 0);
    }

    /**
     * Scope to get fully refunded orders.
     */
    public function scopeFullyRefunded($query)
    {
        return $query->whereColumn('amount_refunded', '>=', 'amount_paid');
    }

    /**
     * Scope to get orders created today.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', now()->toDateString());
    }

    /**
     * Scope to get orders created this week.
     */
    public function scopeThisWeek($query)
    {
        return $query->whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ]);
    }

    /**
     * Scope to get orders created this month.
     */
    public function scopeThisMonth($query)
    {
        return $query->whereBetween('created_at', [
            now()->startOfMonth(),
            now()->endOfMonth(),
        ]);
    }

    /**
     * Scope to get orders created this year.
     */
    public function scopeThisYear($query)
    {
        return $query->whereBetween('created_at', [
            now()->startOfYear(),
            now()->endOfYear(),
        ]);
    }

    // =========================================================================
    // STATIC SUMMARY METHODS
    // =========================================================================

    /**
     * Get total revenue (sum of amount_paid) across all orders.
     * Returns value in cents.
     */
    public static function getTotalRevenue(): int
    {
        return (int) static::sum('amount_paid');
    }

    /**
     * Get total revenue for a date range.
     * Returns value in cents.
     */
    public static function getRevenueBetween(\DateTimeInterface $from, \DateTimeInterface $until): int
    {
        return (int) static::createdBetween($from, $until)->sum('amount_paid');
    }

    /**
     * Get total refunded amount across all orders.
     * Returns value in cents.
     */
    public static function getTotalRefunded(): int
    {
        return (int) static::sum('amount_refunded');
    }

    /**
     * Get net revenue (revenue minus refunds).
     * Returns value in cents.
     */
    public static function getNetRevenue(): int
    {
        return static::getTotalRevenue() - static::getTotalRefunded();
    }

    /**
     * Get average order value.
     * Returns value in cents.
     */
    public static function getAverageOrderValue(): float
    {
        return (float) (static::avg('amount_total') ?? 0);
    }

    /**
     * Get order counts by status.
     */
    public static function getCountsByStatus(): array
    {
        $counts = static::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Initialize all statuses with 0
        $result = [];
        foreach (OrderStatus::cases() as $status) {
            $result[$status->value] = $counts[$status->value] ?? 0;
        }

        return $result;
    }

    /**
     * Get revenue summary for a specific period.
     */
    public static function getRevenueSummary(\DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $query = static::createdBetween($from, $until);

        return [
            'period' => [
                'from' => $from->format('Y-m-d H:i:s'),
                'until' => $until->format('Y-m-d H:i:s'),
            ],
            'orders' => [
                'total' => $query->count(),
                'completed' => (clone $query)->completed()->count(),
                'paid' => (clone $query)->paid()->count(),
                'unpaid' => (clone $query)->unpaid()->count(),
            ],
            'revenue' => [
                'gross' => (int) (clone $query)->sum('amount_total'),
                'paid' => (int) (clone $query)->sum('amount_paid'),
                'refunded' => (int) (clone $query)->sum('amount_refunded'),
                'net' => (int) ((clone $query)->sum('amount_paid') - (clone $query)->sum('amount_refunded')),
            ],
            'averages' => [
                'order_value' => (float) (clone $query)->avg('amount_total') ?? 0,
                'paid_amount' => (float) (clone $query)->avg('amount_paid') ?? 0,
            ],
        ];
    }

    /**
     * Get daily revenue breakdown for a date range.
     */
    public static function getDailyRevenue(\DateTimeInterface $from, \DateTimeInterface $until): \Illuminate\Support\Collection
    {
        return static::createdBetween($from, $until)
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(amount_total) as total_amount')
            ->selectRaw('SUM(amount_paid) as paid_amount')
            ->selectRaw('SUM(amount_refunded) as refunded_amount')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Get monthly revenue breakdown for a date range.
     */
    public static function getMonthlyRevenue(\DateTimeInterface $from, \DateTimeInterface $until): \Illuminate\Support\Collection
    {
        return static::createdBetween($from, $until)
            ->selectRaw('YEAR(created_at) as year')
            ->selectRaw('MONTH(created_at) as month')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(amount_total) as total_amount')
            ->selectRaw('SUM(amount_paid) as paid_amount')
            ->selectRaw('SUM(amount_refunded) as refunded_amount')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();
    }

    // =========================================================================
    // FACTORY METHODS
    // =========================================================================

    /**
     * Create an order from a converted cart.
     */
    public static function createFromCart(Cart $cart): self
    {
        if (!$cart->converted_at) {
            throw new \InvalidArgumentException('Cart must be converted before creating an order');
        }

        $order = static::create([
            'cart_id' => $cart->id,
            'customer_type' => $cart->customer_type,
            'customer_id' => $cart->customer_id,
            'currency' => $cart->currency ?? config('shop.currency', 'USD'),
            'amount_subtotal' => (int) $cart->getTotal() * 100,
            'amount_discount' => 0, // TODO: Calculate from cart discounts
            'amount_shipping' => 0,
            'amount_tax' => 0,
            'amount_total' => (int) $cart->getTotal() * 100,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'status' => OrderStatus::PENDING,
        ]);

        $order->addNote('Order created from cart checkout', 'system', false);

        return $order;
    }
}
