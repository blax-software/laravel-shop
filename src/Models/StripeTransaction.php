<?php

declare(strict_types=1);

namespace Blax\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per Stripe BalanceTransaction — the immutable, user-independent money
 * ledger. Signed cents: refunds/disputes carry negative `amount`/`net`.
 *
 * @property string      $stripe_id
 * @property string|null $source_type
 * @property string|null $reporting_category
 * @property string|null $source_id
 * @property int         $amount
 * @property int         $fee
 * @property int         $net
 * @property string|null $currency
 * @property string|null $customer_id
 * @property string|null $customer_email
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created
 * @property \Illuminate\Support\Carbon|null $available_on
 * @property object|null $meta
 */
class StripeTransaction extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'fee' => 'integer',
        'net' => 'integer',
        'created' => 'datetime',
        'available_on' => 'datetime',
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('shop.tables.stripe_transactions', 'stripe_transactions'));
    }
}
