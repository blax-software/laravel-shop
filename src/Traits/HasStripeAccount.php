<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Exceptions\MultiplePurchaseOptions;
use Blax\Shop\Exceptions\NotPurchasable;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Cashier\Billable;

trait HasStripeAccount
{
    use Billable;
}