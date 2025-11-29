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
        $name = $this->faker->words(3, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
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
            $prices = \Blax\Shop\Models\ProductPrice::factory()
                ->count($count)
                ->create([
                    'purchasable_type' => get_class($product),
                    'purchasable_id' => $product->id,
                    'unit_amount' => $unit_amount ?? $this->faker->randomFloat(2, 10, 1000),
                    'sale_unit_amount' => $sale_unit_amount,
                    'currency' => 'EUR',
                ]);

            // Set the first price as default
            if ($prices->isNotEmpty()) {
                $defaultPrice = $prices->first();
                $defaultPrice->is_default = true;
                $defaultPrice->save();
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
