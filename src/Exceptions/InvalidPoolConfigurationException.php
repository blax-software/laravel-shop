<?php

namespace Blax\Shop\Exceptions;

class InvalidPoolConfigurationException extends NotPurchasable
{
    public static function notAPoolProduct(string $productName): self
    {
        return new self(
            "Product '{$productName}' is not a pool product. " .
                "To create a pool product:\n" .
                "1. Set type to ProductType::POOL\n" .
                "2. Add single items using: \$pool->productRelations()->attach(\$item->id, ['type' => ProductRelationType::SINGLE])\n" .
                "3. Ensure single items are ProductType::BOOKING for time-based bookings"
        );
    }

    public static function noSingleItems(string $productName): self
    {
        return new self(
            "Pool product '{$productName}' has no single items attached. " .
                "A pool product must have at least one single item.\n\n" .
                "To add single items:\n" .
                "\$pool->productRelations()->attach(\$singleItem->id, [\n" .
                "    'type' => ProductRelationType::SINGLE\n" .
                "]);\n\n" .
                "Example for parking spots:\n" .
                "\$parkingLot = Product::create(['type' => ProductType::POOL, 'name' => 'Parking Lot']);\n" .
                "\$spot1 = Product::create(['type' => ProductType::BOOKING, 'name' => 'Spot 1']);\n" .
                "\$spot2 = Product::create(['type' => ProductType::BOOKING, 'name' => 'Spot 2']);\n" .
                "\$parkingLot->productRelations()->attach([\$spot1->id, \$spot2->id], ['type' => ProductRelationType::SINGLE]);"
        );
    }

    public static function mixedSingleItemTypes(string $productName): self
    {
        return new self(
            "Pool product '{$productName}' contains mixed single item types. " .
                "While this is allowed, it may cause unexpected behavior.\n\n" .
                "Best practices:\n" .
                "- For time-based bookings: All single items should be ProductType::BOOKING\n" .
                "- For quantity-based pools: All single items should be ProductType::SIMPLE\n" .
                "- Mixed types will ignore timespans for non-booking items\n\n" .
                "Current recommendation: Use consistent product types within a pool."
        );
    }

    public static function singleItemsWithoutStock(string $productName, array $itemNames): self
    {
        $items = implode(', ', $itemNames);
        return new self(
            "Pool product '{$productName}' has single items without stock management enabled: {$items}\n\n" .
                "To enable stock management:\n" .
                "\$product->update(['manage_stock' => true]);\n" .
                "\$product->increaseStock(1); // Set initial stock\n\n" .
                "Why this matters:\n" .
                "- Pool products claim stock from single items during checkout\n" .
                "- Without stock management, availability cannot be tracked\n" .
                "- This will cause checkout failures"
        );
    }

    public static function singleItemsWithZeroStock(string $productName, array $itemNames): self
    {
        $items = implode(', ', $itemNames);
        return new self(
            "Pool product '{$productName}' has single items with zero available stock: {$items}\n\n" .
                "To add stock:\n" .
                "\$product->increaseStock(1);\n\n" .
                "Note: Each booking item typically has stock of 1, representing one bookable unit."
        );
    }

    public static function bookingItemsRequireTimespan(string $productName): self
    {
        return new self(
            "Pool product '{$productName}' contains booking items but no timespan was provided.\n\n" .
                "When adding a pool product with booking items to cart, you must specify a timespan:\n\n" .
                "Using CartService:\n" .
                "Cart::addBooking(\n" .
                "    \$poolProduct,\n" .
                "    \$quantity,\n" .
                "    Carbon::parse('2025-01-15 14:00'),  // from\n" .
                "    Carbon::parse('2025-01-15 18:00'),  // until\n" .
                ");\n\n" .
                "Using Cart directly:\n" .
                "\$cart->items()->create([\n" .
                "    'purchasable_id' => \$poolProduct->id,\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'quantity' => 1,\n" .
                "    'from' => Carbon::parse('2025-01-15 14:00'),\n" .
                "    'until' => Carbon::parse('2025-01-15 18:00'),\n" .
                "    // ... other fields\n" .
                "]);"
        );
    }

    public static function invalidPricingStrategy(string $strategy): self
    {
        return new self(
            "Invalid pricing strategy: '{$strategy}'\n\n" .
                "Supported pricing strategies:\n" .
                "- 'average' (default): Average price of all single items\n" .
                "- 'lowest': Minimum price among single items\n" .
                "- 'highest': Maximum price among single items\n\n" .
                "Example:\n" .
                "\$poolProduct->setPoolPricingStrategy('lowest');"
        );
    }

    public static function poolWithoutPricing(string $productName): self
    {
        return new self(
            "Pool product '{$productName}' has no pricing information.\n\n" .
                "You have two options:\n\n" .
                "Option 1: Set direct pool pricing (takes precedence)\n" .
                "ProductPrice::create([\n" .
                "    'purchasable_id' => \$poolProduct->id,\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'unit_amount' => 5000,  // Price in cents\n" .
                "    'currency' => 'USD',\n" .
                "    'is_default' => true,\n" .
                "]);\n\n" .
                "Option 2: Set prices on single items (pool will inherit)\n" .
                "ProductPrice::create([\n" .
                "    'purchasable_id' => \$singleItem->id,\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'unit_amount' => 5000,\n" .
                "    'currency' => 'USD',\n" .
                "    'is_default' => true,\n" .
                "]);\n\n" .
                "The pool will inherit prices using the configured strategy (average/lowest/highest)."
        );
    }

    public static function notEnoughAvailableItems(
        string $productName,
        \DateTimeInterface $from,
        \DateTimeInterface $until,
        int $requested,
        int $available
    ): self {
        return new self(
            "Pool product '{$productName}' does not have enough available items for the requested period.\n\n" .
                "Period: {$from->format('Y-m-d H:i:s')} to {$until->format('Y-m-d H:i:s')}\n" .
                "Requested quantity: {$requested}\n" .
                "Available quantity: {$available}\n\n" .
                "Possible reasons:\n" .
                "- Single items are already booked for this period\n" .
                "- Not enough single items in the pool\n" .
                "- Single items don't have sufficient stock\n\n" .
                "To check availability:\n" .
                "\$available = \$poolProduct->getPoolMaxQuantity(\$from, \$until);\n\n" .
                "To see pool composition:\n" .
                "\$singleItems = \$poolProduct->singleProducts;\n" .
                "foreach (\$singleItems as \$item) {\n" .
                "    \$available = \$item->isAvailableForBooking(\$from, \$until, 1);\n" .
                "    echo \"\$item->name: \" . (\$available ? 'Available' : 'Unavailable') . \"\\n\";\n" .
                "}"
        );
    }
}
