<?php

namespace Blax\Shop\Exceptions;

class HasNoPriceException extends NotPurchasable
{
    public static function noPricesConfigured(string $productName, string $productId): self
    {
        return new self(
            "Product '{$productName}' has no pricing configured and cannot be added to cart.\n\n" .
                "To add pricing:\n\n" .
                "use Blax\Shop\Models\ProductPrice;\n\n" .
                "ProductPrice::create([\n" .
                "    'purchasable_id' => '{$productId}',\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'unit_amount' => 10000,  // Price in cents (100.00)\n" .
                "    'currency' => 'USD',\n" .
                "    'is_default' => true,    // Mark as default price\n" .
                "]);\n\n" .
                "For booking/pool products:\n" .
                "- The unit_amount is typically the price per day\n" .
                "- Total price = unit_amount × days × quantity\n\n" .
                "For simple products:\n" .
                "- The unit_amount is the product price\n" .
                "- Total price = unit_amount × quantity"
        );
    }

    public static function poolProductNoPriceAndNoSingleItemPrices(string $productName): self
    {
        return new self(
            "Cannot add pool product '{$productName}' to cart: No pricing available.\n\n" .
                "Pool products can be priced in two ways:\n\n" .
                "Option 1: Direct pool pricing\n" .
                "ProductPrice::create([\n" .
                "    'purchasable_id' => \$poolProduct->id,\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'unit_amount' => 5000,  // 50.00 per day\n" .
                "    'currency' => 'USD',\n" .
                "    'is_default' => true,\n" .
                "]);\n\n" .
                "Option 2: Price inheritance from single items (Recommended)\n" .
                "// Set prices on individual items in the pool\n" .
                "foreach (\$poolProduct->singleProducts as \$item) {\n" .
                "    ProductPrice::create([\n" .
                "        'purchasable_id' => \$item->id,\n" .
                "        'purchasable_type' => Product::class,\n" .
                "        'unit_amount' => 5000,\n" .
                "        'currency' => 'USD',\n" .
                "        'is_default' => true,\n" .
                "    ]);\n" .
                "}\n\n" .
                "// Configure pricing strategy (optional)\n" .
                "\$poolProduct->setPoolPricingStrategy('average');  // or 'lowest' or 'highest'\n\n" .
                "Current state:\n" .
                "- Pool product has no direct price\n" .
                "- No single items have prices to inherit from\n\n" .
                "At least one pricing method must be configured before adding to cart."
        );
    }
}
