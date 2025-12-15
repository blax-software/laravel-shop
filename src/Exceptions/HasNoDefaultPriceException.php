<?php

namespace Blax\Shop\Exceptions;

class HasNoDefaultPriceException extends NotPurchasable
{
    public static function multiplePricesNoDefault(string $productName, int $priceCount): self
    {
        return new self(
            "Product '{$productName}' has {$priceCount} prices configured but none are marked as default.\n\n" .
                "When a product has multiple prices, one must be marked as the default price.\n\n" .
                "To fix this, update one of the existing prices:\n\n" .
                "use Blax\Shop\Models\ProductPrice;\n\n" .
                "// Option 1: Update existing price to be default\n" .
                "\$price = ProductPrice::where('purchasable_id', \$product->id)\n" .
                "    ->where('purchasable_type', Product::class)\n" .
                "    ->first();\n" .
                "\$price->update(['is_default' => true]);\n\n" .
                "// Option 2: When creating new prices, always set is_default\n" .
                "ProductPrice::create([\n" .
                "    'purchasable_id' => \$product->id,\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'unit_amount' => 10000,\n" .
                "    'currency' => 'USD',\n" .
                "    'is_default' => true,  // ✓ Always set this for the primary price\n" .
                "]);\n\n" .
                "Why this matters:\n" .
                "- The default price is used when adding products to cart\n" .
                "- Without a default, the system can't determine which price to use\n" .
                "- Multiple default prices will cause conflicts\n\n" .
                "Current state: {$priceCount} prices exist, 0 are marked as default"
        );
    }

    public static function onlyNonDefaultPriceExists(string $productName): self
    {
        return new self(
            "Product '{$productName}' has a price configured, but it's not marked as default.\n\n" .
                "When a product has only one price, it should be marked as default.\n\n" .
                "To fix this:\n\n" .
                "use Blax\Shop\Models\ProductPrice;\n\n" .
                "\$price = ProductPrice::where('purchasable_id', \$product->id)\n" .
                "    ->where('purchasable_type', Product::class)\n" .
                "    ->first();\n" .
                "\n" .
                "\$price->update(['is_default' => true]);\n\n" .
                "Or when creating the price:\n\n" .
                "ProductPrice::create([\n" .
                "    'purchasable_id' => \$product->id,\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'unit_amount' => 10000,\n" .
                "    'currency' => 'USD',\n" .
                "    'is_default' => true,  // ✓ Required\n" .
                "]);\n\n" .
                "Note: If you have only one price, it must be the default price."
        );
    }

    public static function multipleDefaultPrices(string $productName, int $defaultCount): self
    {
        return new self(
            "Product '{$productName}' has {$defaultCount} prices marked as default. Only one price can be default.\n\n" .
                "To fix this, keep only one price as default:\n\n" .
                "use Blax\Shop\Models\ProductPrice;\n\n" .
                "// Get all default prices\n" .
                "\$defaultPrices = ProductPrice::where('purchasable_id', \$product->id)\n" .
                "    ->where('purchasable_type', Product::class)\n" .
                "    ->where('is_default', true)\n" .
                "    ->get();\n\n" .
                "// Keep the first one as default, set others to non-default\n" .
                "\$defaultPrices->skip(1)->each(function (\$price) {\n" .
                "    \$price->update(['is_default' => false]);\n" .
                "});\n\n" .
                "Why this matters:\n" .
                "- Only one price should be used as the default for cart operations\n" .
                "- Multiple defaults create ambiguity\n" .
                "- The system can't determine which price to use\n\n" .
                "Best practice:\n" .
                "- Use is_default => true for the standard price\n" .
                "- Use is_default => false for alternative prices (bulk discounts, regions, etc.)\n" .
                "- Implement custom logic to select non-default prices when needed\n\n" .
                "Current state: {$defaultCount} prices are marked as default"
        );
    }
}
