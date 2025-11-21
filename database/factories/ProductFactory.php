<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\Product;
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
            'visible' => true,
            'featured' => false,
            'price' => $this->faker->randomFloat(2, 10, 1000),
            'regular_price' => $this->faker->randomFloat(2, 10, 1000),
            'manage_stock' => true,
            'stock_quantity' => $this->faker->numberBetween(0, 100),
            'in_stock' => true,
            'stock_status' => 'instock',
            'published_at' => now(),
            'meta' => json_encode(new \stdClass()),
        ];
    }

    public function onSale(): static
    {
        return $this->state(function (array $attributes) {
            $regularPrice = $attributes['regular_price'];
            return [
                'sale_price' => $regularPrice * 0.8,
                'sale_start' => now()->subDay(),
                'sale_end' => now()->addWeek(),
            ];
        });
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
}
