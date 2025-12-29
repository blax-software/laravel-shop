<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        // Generate realistic product names
        $productTypes = [
            'Laptop',
            'Smartphone',
            'Headphones',
            'Camera',
            'Tablet',
            'Watch',
            'Monitor',
            'Keyboard',
            'Mouse',
            'Speaker',
            'Charger',
            'Cable',
            'Case',
            'Stand',
            'Adapter'
        ];

        $adjectives = ['Pro', 'Max', 'Plus', 'Ultra', 'Premium', 'Deluxe'];

        $productType = $this->faker->randomElement($productTypes);
        $adjective = $this->faker->optional(0.6)->randomElement($adjectives);

        $name = $adjective ? "{$productType} {$adjective}" : $productType;

        return [
            'name' => $name,
            'slug' => Str::slug($name . '-' . $this->faker->unique()->numberBetween(1000, 9999)),
            'sku' => strtoupper($this->faker->bothify('??-####')),
            'type' => 'simple',
            'status' => 'published',
            'is_visible' => true,
            'featured' => false,
            'manage_stock' => true,
            'stock_quantity' => $this->faker->numberBetween(0, 100),
            'in_stock' => true,
            'stock_status' => 'instock',
            'published_at' => now(),
            'meta' => json_encode(new \stdClass()),
        ];
    }

    public function outOfStock(): static
    {
        return $this->state([
            'stock_quantity' => 0,
            'in_stock' => false,
            'stock_status' => 'outofstock',
        ]);
    }

    public function variable(): static
    {
        return $this->state(['type' => 'variable']);
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function featured(): static
    {
        return $this->state(['featured' => true]);
    }

    public function withPrices(
        int $count = 1,
        null|float $unit_amount = null,
        null|float $sale_unit_amount = null
    ): static {
        return $this->afterCreating(function (Product $product) use ($count, $unit_amount, $sale_unit_amount) {
            // Use realistic price range if not specified
            $priceAmount = $unit_amount ?? $this->faker->randomElement([
                1999,  // $19.99
                2999,  // $29.99
                4999,  // $49.99
                7999,  // $79.99
                9999,  // $99.99
                14999, // $149.99
                19999, // $199.99
                29999, // $299.99
                49999, // $499.99
            ]);

            // Create first price with is_default = true to avoid second query
            \Blax\Shop\Models\ProductPrice::factory()->create([
                'purchasable_type' => get_class($product),
                'purchasable_id' => $product->id,
                'unit_amount' => $priceAmount,
                'sale_unit_amount' => $sale_unit_amount,
                'currency' => 'EUR',
                'is_default' => true,
            ]);

            // Create additional prices if count > 1
            if ($count > 1) {
                \Blax\Shop\Models\ProductPrice::factory()
                    ->count($count - 1)
                    ->create([
                        'purchasable_type' => get_class($product),
                        'purchasable_id' => $product->id,
                        'unit_amount' => $priceAmount,
                        'sale_unit_amount' => $sale_unit_amount,
                        'currency' => 'EUR',
                    ]);
            }
        });
    }

    public function withStocks(int $quantity = 10): static
    {
        return $this->afterCreating(function (Product $product) use ($quantity) {
            $product->increaseStock($quantity);
        });
    }

    public function onSale(Carbon|null $sale_start, Carbon|null $sale_end = null)
    {
        return $this->state([
            'sale_start' => $sale_start,
            'sale_end' => $sale_end,
        ]);
    }
}
