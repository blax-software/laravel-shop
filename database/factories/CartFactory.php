<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        // $product1 = Product::factory()->withStocks()->withPrices(1, 782)->create();
        // $product2 = Product::factory()->withStocks()->withPrices(1, 402)->create();
        // $product3 = Product::factory()->withStocks()->withPrices(1, 855)->create();

        // $cart->addToCart($product1);
        // $cart->addToCart($product2);
        // $cart->addToCart($product3);

        return [];
    }

    public function forCustomer(Model $model): static
    {
        return $this->state([
            'customer_type' => get_class($model),
            'customer_id' => $model->getKey(),
        ]);
    }

    public function withNewProductInCart(
        int $quantity = 1,
        float $unit_amount,
        float|null $sale_unit_amount = null,
        int|null $stocks = 0,
        Carbon|null $sale_start = null,
        Carbon|null $sale_end = null,
    ): static {
        return $this->afterCreating(function (Cart $cart) use (
            $quantity,
            $unit_amount,
            $sale_unit_amount,
            $stocks,
            $sale_start,
            $sale_end
        ) {
            $product = Product::factory()
                ->withStocks($stocks ?? 0)
                ->withPrices(1, $unit_amount, $sale_unit_amount)
                ->onSale($sale_start, $sale_end)
                ->create();

            $cart->addToCart($product, $quantity);
        });
    }
}
