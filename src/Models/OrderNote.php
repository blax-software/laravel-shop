<?php

namespace Blax\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * OrderNote model for tracking order activity and notes.
 * 
 * Similar to WooCommerce order notes, this provides a complete audit trail
 * of all activities, status changes, and communications related to an order.
 */
class OrderNote extends Model
{
    use HasUuids, HasFactory;

    /**
     * Note types for categorization.
     */
    public const TYPE_NOTE = 'note';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_REFUND = 'refund';
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_EMAIL = 'email';
    public const TYPE_WEBHOOK = 'webhook';

    protected $fillable = [
        'order_id',
        'author_type',
        'author_id',
        'content',
        'type',
        'is_customer_note',
        'meta',
    ];

    protected $casts = [
        'is_customer_note' => 'boolean',
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.order_notes', 'order_notes'));
    }

    protected static function booted()
    {
        static::creating(function (OrderNote $note) {
            // Set default type
            if (empty($note->type)) {
                $note->type = self::TYPE_NOTE;
            }

            // Default to internal note
            if (!isset($note->is_customer_note)) {
                $note->is_customer_note = false;
            }
        });
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The order this note belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.order', Order::class), 'order_id');
    }

    /**
     * The author of this note (user, admin, system).
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    // =========================================================================
    // COMPUTED ATTRIBUTES
    // =========================================================================

    /**
     * Get human-readable type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_NOTE => 'Note',
            self::TYPE_STATUS_CHANGE => 'Status Change',
            self::TYPE_PAYMENT => 'Payment',
            self::TYPE_REFUND => 'Refund',
            self::TYPE_SHIPPING => 'Shipping',
            self::TYPE_CUSTOMER => 'Customer Message',
            self::TYPE_SYSTEM => 'System',
            self::TYPE_EMAIL => 'Email',
            self::TYPE_WEBHOOK => 'Webhook',
            default => ucfirst($this->type),
        };
    }

    /**
     * Get icon for the note type (for UI purposes).
     */
    public function getTypeIconAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_NOTE => 'pencil',
            self::TYPE_STATUS_CHANGE => 'arrow-path',
            self::TYPE_PAYMENT => 'credit-card',
            self::TYPE_REFUND => 'arrow-uturn-left',
            self::TYPE_SHIPPING => 'truck',
            self::TYPE_CUSTOMER => 'user',
            self::TYPE_SYSTEM => 'cog',
            self::TYPE_EMAIL => 'envelope',
            self::TYPE_WEBHOOK => 'bolt',
            default => 'information-circle',
        };
    }

    /**
     * Get color for the note type (for UI purposes).
     */
    public function getTypeColorAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_NOTE => 'gray',
            self::TYPE_STATUS_CHANGE => 'blue',
            self::TYPE_PAYMENT => 'green',
            self::TYPE_REFUND => 'red',
            self::TYPE_SHIPPING => 'purple',
            self::TYPE_CUSTOMER => 'yellow',
            self::TYPE_SYSTEM => 'indigo',
            self::TYPE_EMAIL => 'teal',
            self::TYPE_WEBHOOK => 'orange',
            default => 'gray',
        };
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Scope to get customer-visible notes.
     */
    public function scopeForCustomer($query)
    {
        return $query->where('is_customer_note', true);
    }

    /**
     * Scope to get internal notes only.
     */
    public function scopeInternal($query)
    {
        return $query->where('is_customer_note', false);
    }

    /**
     * Scope to filter by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter by multiple types.
     */
    public function scopeOfTypes($query, array $types)
    {
        return $query->whereIn('type', $types);
    }

    /**
     * Scope to get system notes.
     */
    public function scopeSystem($query)
    {
        return $query->where('type', self::TYPE_SYSTEM);
    }

    /**
     * Scope to get payment-related notes.
     */
    public function scopePaymentRelated($query)
    {
        return $query->whereIn('type', [self::TYPE_PAYMENT, self::TYPE_REFUND]);
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
    // FACTORY METHODS
    // =========================================================================

    /**
     * Create a system note.
     */
    public static function createSystemNote(Order $order, string $content, ?array $meta = null): self
    {
        return $order->notes()->create([
            'content' => $content,
            'type' => self::TYPE_SYSTEM,
            'is_customer_note' => false,
            'meta' => $meta ? (object) $meta : null,
        ]);
    }

    /**
     * Create a customer note (visible to customer).
     */
    public static function createCustomerNote(Order $order, string $content, $author = null): self
    {
        return $order->notes()->create([
            'content' => $content,
            'type' => self::TYPE_CUSTOMER,
            'is_customer_note' => true,
            'author_type' => $author ? get_class($author) : null,
            'author_id' => $author?->getKey(),
        ]);
    }

    /**
     * Create a payment note.
     */
    public static function createPaymentNote(
        Order $order,
        string $content,
        ?string $reference = null,
        ?int $amount = null
    ): self {
        $meta = [];
        if ($reference) {
            $meta['payment_reference'] = $reference;
        }
        if ($amount !== null) {
            $meta['amount'] = $amount;
        }

        return $order->notes()->create([
            'content' => $content,
            'type' => self::TYPE_PAYMENT,
            'is_customer_note' => false,
            'meta' => !empty($meta) ? (object) $meta : null,
        ]);
    }

    /**
     * Create a shipping note.
     */
    public static function createShippingNote(
        Order $order,
        string $content,
        ?string $trackingNumber = null,
        ?string $carrier = null
    ): self {
        $meta = [];
        if ($trackingNumber) {
            $meta['tracking_number'] = $trackingNumber;
        }
        if ($carrier) {
            $meta['carrier'] = $carrier;
        }

        return $order->notes()->create([
            'content' => $content,
            'type' => self::TYPE_SHIPPING,
            'is_customer_note' => true, // Shipping info should be visible to customer
            'meta' => !empty($meta) ? (object) $meta : null,
        ]);
    }
}
