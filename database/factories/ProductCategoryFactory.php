<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'is_visible' => true,
            'sort_order' => $this->faker->numberBetween(0, 100),
            'meta' => json_encode(new \stdClass()),
        ];
    }
}
