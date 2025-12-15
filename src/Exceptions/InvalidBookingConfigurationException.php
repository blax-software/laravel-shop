<?php

namespace Blax\Shop\Exceptions;

class InvalidBookingConfigurationException extends NotPurchasable
{
    public static function notABookingProduct(string $productName): self
    {
        return new self(
            "Product '{$productName}' is not a booking product. " .
                "To create a booking product:\n\n" .
                "1. Set the product type:\n" .
                "Product::create([\n" .
                "    'type' => ProductType::BOOKING,\n" .
                "    'name' => 'Conference Room A',\n" .
                "    'manage_stock' => true,  // Required for bookings\n" .
                "]);\n\n" .
                "2. Set initial stock (typically 1 for a single bookable unit):\n" .
                "\$product->increaseStock(1);\n\n" .
                "3. Set pricing (per day/hour):\n" .
                "ProductPrice::create([\n" .
                "    'purchasable_id' => \$product->id,\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'unit_amount' => 10000,  // Price per day in cents\n" .
                "    'currency' => 'USD',\n" .
                "    'is_default' => true,\n" .
                "]);"
        );
    }

    public static function stockManagementNotEnabled(string $productName): self
    {
        return new self(
            "Booking product '{$productName}' does not have stock management enabled.\n\n" .
                "Stock management is required for booking products to track availability.\n\n" .
                "To enable:\n" .
                "\$product->update(['manage_stock' => true]);\n" .
                "\$product->increaseStock(1);"
        );
    }

    public static function noStockAvailable(string $productName): self
    {
        return new self(
            "Booking product '{$productName}' has no stock available.\n\n" .
                "For booking products, stock represents the number of bookable units.\n" .
                "Typically, set stock to 1 for single-unit items (rooms, equipment, etc.)\n\n" .
                "To add stock:\n" .
                "\$product->increaseStock(1);"
        );
    }

    public static function invalidTimespan(\DateTimeInterface $from, \DateTimeInterface $until): self
    {
        return new self(
            "Invalid booking timespan: from '{$from->format('Y-m-d H:i:s')}' to '{$until->format('Y-m-d H:i:s')}'\n\n" .
                "Booking validation rules:\n" .
                "1. 'from' must be before 'until'\n" .
                "2. 'from' cannot be in the past\n" .
                "3. Both dates must be provided\n\n" .
                "Example:\n" .
                "Cart::addBooking(\n" .
                "    \$product,\n" .
                "    1,\n" .
                "    Carbon::now()->addDay(),     // from (future date)\n" .
                "    Carbon::now()->addDays(3),   // until (after 'from')\n" .
                ");"
        );
    }

    public static function timespanRequired(string $productName): self
    {
        return new self(
            "Booking product '{$productName}' requires a timespan (from/until dates).\n\n" .
                "When adding a booking product to cart, you must specify when it will be used:\n\n" .
                "Using CartService:\n" .
                "use Blax\Shop\Facades\Cart;\n\n" .
                "Cart::addBooking(\n" .
                "    \$bookingProduct,\n" .
                "    \$quantity,\n" .
                "    Carbon::parse('2025-01-15 09:00'),  // from\n" .
                "    Carbon::parse('2025-01-17 17:00'),  // until\n" .
                ");\n\n" .
                "Or when creating cart items manually:\n" .
                "\$cart->items()->create([\n" .
                "    'purchasable_id' => \$product->id,\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'quantity' => 1,\n" .
                "    'from' => Carbon::parse('2025-01-15 09:00'),\n" .
                "    'until' => Carbon::parse('2025-01-17 17:00'),\n" .
                "    'price' => \$product->getCurrentPrice() * 2,  // 2 days\n" .
                "    'subtotal' => \$product->getCurrentPrice() * 2,\n" .
                "]);"
        );
    }

    public static function notAvailableForPeriod(
        string $productName,
        \DateTimeInterface $from,
        \DateTimeInterface $until,
        int $requested,
        int $available
    ): self {
        return new self(
            "Booking product '{$productName}' is not available for the requested period.\n\n" .
                "Period: {$from->format('Y-m-d H:i:s')} to {$until->format('Y-m-d H:i:s')}\n" .
                "Requested quantity: {$requested}\n" .
                "Available quantity: {$available}\n\n" .
                "Possible reasons:\n" .
                "- Another booking overlaps with this period\n" .
                "- Not enough stock for the requested quantity\n" .
                "- Stock claims exist for this period\n\n" .
                "To check availability:\n" .
                "\$available = \$product->isAvailableForBooking(\$from, \$until, \$quantity);\n\n" .
                "To see existing claims:\n" .
                "\$claims = \$product->stocks()\n" .
                "    ->where('type', StockType::CLAIMED)\n" .
                "    ->where('status', StockStatus::PENDING)\n" .
                "    ->whereBetween('claimed_from', [\$from, \$until])\n" .
                "    ->get();"
        );
    }

    public static function overlappingBooking(
        string $productName,
        \DateTimeInterface $from,
        \DateTimeInterface $until
    ): self {
        return new self(
            "Booking overlaps with existing reservations for '{$productName}'.\n\n" .
                "Requested period: {$from->format('Y-m-d H:i:s')} to {$until->format('Y-m-d H:i:s')}\n\n" .
                "Overlap detection rules:\n" .
                "1. New booking starts during existing booking\n" .
                "2. New booking ends during existing booking\n" .
                "3. New booking completely contains existing booking\n" .
                "4. New booking is completely contained by existing booking\n\n" .
                "Note: Back-to-back bookings are allowed (e.g., 23:59:59 → 00:00:00)"
        );
    }

    public static function noPricingConfigured(string $productName): self
    {
        return new self(
            "Booking product '{$productName}' has no pricing configured.\n\n" .
                "To set pricing:\n\n" .
                "use Blax\Shop\Models\ProductPrice;\n\n" .
                "ProductPrice::create([\n" .
                "    'purchasable_id' => \$product->id,\n" .
                "    'purchasable_type' => Product::class,\n" .
                "    'unit_amount' => 10000,  // Price per day in cents (100.00 USD)\n" .
                "    'currency' => 'USD',\n" .
                "    'is_default' => true,\n" .
                "]);\n\n" .
                "The price will be multiplied by the number of days in the booking period.\n" .
                "Example: 100.00/day × 3 days = 300.00 total"
        );
    }
}
