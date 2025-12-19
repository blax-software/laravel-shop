<?php

namespace Blax\Shop\Services;

use Blax\Shop\Exceptions\HasNoDefaultPriceException;
use Blax\Shop\Exceptions\ProductMissingAssociationException;
use Blax\Shop\Exceptions\StripeNotEnabledException;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Product as StripeProduct;
use Stripe\Price as StripePrice;

class StripeSyncService
{
    public function __construct()
    {
        if (config('shop.stripe.enabled')) {
            Stripe::setApiKey(config('services.stripe.secret'));
        }
    }

    /**
     * Sync a product to Stripe and return the Stripe product ID
     * 
     * @param Product $product
     * @return string Stripe Product ID
     */
    public function syncProduct(Product $product): string
    {
        if (!config('shop.stripe.enabled')) {
            throw new StripeNotEnabledException();
        }

        // Check if product already has a Stripe ID
        if ($product->stripe_product_id) {
            try {
                // Verify the product still exists in Stripe
                StripeProduct::retrieve($product->stripe_product_id);

                // Update the product in Stripe
                StripeProduct::update($product->stripe_product_id, [
                    'name' => $product->name,
                    'description' => $product->short_description ?? $product->description,
                    'active' => $product->status === \Blax\Shop\Enums\ProductStatus::PUBLISHED,
                    'metadata' => [
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                    ],
                ]);

                return $product->stripe_product_id;
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Product doesn't exist in Stripe, create a new one
                Log::warning('Stripe product not found, creating new one', [
                    'product_id' => $product->id,
                    'stripe_product_id' => $product->stripe_product_id,
                ]);
            }
        }

        // Create new Stripe product
        $stripeProduct = StripeProduct::create([
            'name' => $product->name,
            'description' => $product->short_description ?? $product->description,
            'active' => $product->status === \Blax\Shop\Enums\ProductStatus::PUBLISHED,
            'metadata' => [
                'product_id' => $product->id,
                'sku' => $product->sku,
            ],
        ]);

        // Update local product with Stripe ID
        $product->update(['stripe_product_id' => $stripeProduct->id]);

        Log::info('Product synced to Stripe', [
            'product_id' => $product->id,
            'stripe_product_id' => $stripeProduct->id,
        ]);

        return $stripeProduct->id;
    }

    /**
     * Sync a product price to Stripe and return the Stripe price ID
     * 
     * @param ProductPrice $price
     * @param Product|null $product
     * @return string Stripe Price ID
     */
    public function syncPrice(ProductPrice $price, ?Product $product = null): string
    {
        if (!config('shop.stripe.enabled')) {
            throw new StripeNotEnabledException();
        }

        // Get the product if not provided
        if (!$product && $price->purchasable instanceof Product) {
            $product = $price->purchasable;
        }

        if (!$product) {
            throw new ProductMissingAssociationException();
        }

        // Ensure product is synced to Stripe
        $stripeProductId = $this->syncProduct($product);

        // Check if price already has a Stripe ID
        if ($price->stripe_price_id) {
            try {
                // Verify the price still exists in Stripe
                $stripePrice = StripePrice::retrieve($price->stripe_price_id);

                // Check if price parameters match
                $unitAmount = (int) ($price->unit_amount * 100); // Convert to cents

                if (
                    $stripePrice->unit_amount === $unitAmount &&
                    $stripePrice->currency === strtolower($price->currency)
                ) {
                    return $price->stripe_price_id;
                }

                // Price parameters changed, need to create a new price
                // (Stripe prices are immutable)
                Log::info('Price parameters changed, creating new Stripe price', [
                    'price_id' => $price->id,
                    'old_stripe_price_id' => $price->stripe_price_id,
                ]);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Price doesn't exist in Stripe, create a new one
                Log::warning('Stripe price not found, creating new one', [
                    'price_id' => $price->id,
                    'stripe_price_id' => $price->stripe_price_id,
                ]);
            }
        }

        // Create new Stripe price
        $unitAmount = (int) ($price->unit_amount * 100); // Convert to cents

        $priceParams = [
            'product' => $stripeProductId,
            'unit_amount' => $unitAmount,
            'currency' => strtolower($price->currency),
            'metadata' => [
                'price_id' => $price->id,
            ],
        ];

        // Add recurring parameters if applicable
        if ($price->type === \Blax\Shop\Enums\PriceType::RECURRING) {
            $priceParams['recurring'] = [
                'interval' => $price->interval->value,
            ];

            if ($price->interval_count && $price->interval_count > 1) {
                $priceParams['recurring']['interval_count'] = $price->interval_count;
            }
        }

        $stripePrice = StripePrice::create($priceParams);

        // Update local price with Stripe ID
        $price->update(['stripe_price_id' => $stripePrice->id]);

        Log::info('Price synced to Stripe', [
            'price_id' => $price->id,
            'stripe_price_id' => $stripePrice->id,
        ]);

        return $stripePrice->id;
    }

    /**
     * Sync product and its default price to Stripe
     * 
     * @param Product $product
     * @return array ['product_id' => string, 'price_id' => string]
     */
    public function syncProductWithPrice(Product $product): array
    {
        $stripeProductId = $this->syncProduct($product);

        $defaultPrice = $product->defaultPrice()->first();

        if (!$defaultPrice) {
            throw HasNoDefaultPriceException::forProduct($product->name);
        }

        $stripePriceId = $this->syncPrice($defaultPrice, $product);

        return [
            'product_id' => $stripeProductId,
            'price_id' => $stripePriceId,
        ];
    }
}
