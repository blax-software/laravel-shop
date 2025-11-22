<?php

declare(strict_types=1);

namespace Blax\Shop\Services;

use Blax\Shop\Models\Product;
use Illuminate\Support\Collection;

class ShopStripeService
{
    public static function syncProductDown(\Stripe\Product $stripeProduct)
    {
        $product = Product::updateOrCreate(
            ['stripe_product_id' => $stripeProduct->id],
            [
                'slug' => str()->slug($stripeProduct->name),
                'type' => $stripeProduct->type,
                'virtual' => $stripeProduct->type === 'service',
                'status' => $stripeProduct->active ? 'published' : 'draft',
            ]
        );

        $product->setLocalized('name', $stripeProduct->name);

        if (isset($stripeProduct->marketing_features)) {
            $product->setLocalized(
                'features',
                collect($stripeProduct->marketing_features)->map(fn($i) => $i->name)->toArray(),
            );
        }

        $product->save();

        // Sync prices
        self::syncProductPricesDown($product);

        if (app()->runningInConsole()) {
            echo "\n";
        }

        return $product;
    }

    public static function syncProductPricesDown(Product $product)
    {
        self::getProductPrices($product->stripe_product_id)->each(function ($stripePrice) use ($product) {
            if ($stripePrice->product !== $product->stripe_product_id) {
                return;
            }

            $price = $product->prices()->updateOrCreate(
                ['stripe_price_id' => $stripePrice->id],
                [
                    'name' => $stripePrice->nickname,
                    'type' => $stripePrice->type,
                    'price' => $stripePrice->unit_amount,
                    'currency' => $stripePrice->currency,
                    'billing_scheme' => $stripePrice->billing_scheme,
                    'interval' => $stripePrice->recurring ? $stripePrice->recurring->interval : null,
                    'interval_count' => $stripePrice->recurring ? $stripePrice->recurring->interval_count : null,
                    'trial_period_days' => $stripePrice->recurring ? $stripePrice->recurring->trial_period_days : null,
                    'is_default' => false,
                ]
            );

            if (app()->runningInConsole()) {
                echo " - Synced price {$price->id} ({$stripePrice->id})\n";
            }
        });
    }
}
