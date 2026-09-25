<?php

namespace Blax\Shop\Tests\Unit\Resources;

use Blax\Shop\Http\Resources\CartResource;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;

class CartItemResourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function items_carry_the_chosen_price_their_parameters_and_the_virtual_flag()
    {
        config(['shop.cart.prices_as_options' => true]);

        $service = Product::factory()->create(['name' => 'Portrait', 'manage_stock' => false, 'virtual' => true]);
        ProductPrice::factory()->create(['purchasable_id' => $service->id, 'purchasable_type' => Product::class, 'unit_amount' => 5000, 'is_default' => true, 'name' => 'A5']);
        $a4 = ProductPrice::factory()->create(['purchasable_id' => $service->id, 'purchasable_type' => Product::class, 'unit_amount' => 7000, 'is_default' => false, 'name' => 'A4']);

        $physical = Product::factory()->withPrices(unit_amount: 1000)->create(['name' => 'Keychain', 'manage_stock' => false]);

        $cart = Cart::create(['currency' => 'EUR']);
        $cart->addToCart($a4, 1, ['brief' => 'Two cats in space']);
        $cart->addToCart($physical, 1);

        $items = collect(CartResource::make($cart->fresh())->toArray(new Request)['items']->toArray(new Request))->keyBy(fn ($i) => $i['purchasable']['name']);

        $this->assertSame($a4->id, $items['Portrait']['price_id']);
        $this->assertSame('Two cats in space', ((array) $items['Portrait']['parameters'])['brief']);
        $this->assertTrue($items['Portrait']['purchasable']['virtual']);
        $this->assertSame(7000, $items['Portrait']['price']);

        $this->assertSame([], (array) $items['Keychain']['parameters']);
        $this->assertFalse($items['Keychain']['purchasable']['virtual']);
    }

    #[Test]
    public function without_price_options_a_price_stays_the_purchasable()
    {
        $service = Product::factory()->create(['name' => 'Portrait', 'manage_stock' => false]);
        $a4 = ProductPrice::factory()->create(['purchasable_id' => $service->id, 'purchasable_type' => Product::class, 'unit_amount' => 7000, 'is_default' => true, 'name' => 'A4']);

        $cart = Cart::create(['currency' => 'EUR']);
        $item = $cart->addToCart($a4, 1);

        $this->assertSame(ProductPrice::class, $item->purchasable_type);
        $this->assertSame($a4->id, $item->purchasable_id);
    }

    #[Test]
    public function price_options_merge_only_with_the_same_option()
    {
        config(['shop.cart.prices_as_options' => true]);

        $service = Product::factory()->create(['name' => 'Portrait', 'manage_stock' => false]);
        $a5 = ProductPrice::factory()->create(['purchasable_id' => $service->id, 'purchasable_type' => Product::class, 'unit_amount' => 5000, 'is_default' => true, 'name' => 'A5']);
        $a4 = ProductPrice::factory()->create(['purchasable_id' => $service->id, 'purchasable_type' => Product::class, 'unit_amount' => 7000, 'is_default' => false, 'name' => 'A4']);

        $cart = Cart::create(['currency' => 'EUR']);
        $cart->addToCart($a4, 1);
        $cart->addToCart($a4, 1);
        $cart->addToCart($a5, 1);

        $items = $cart->fresh()->items;
        $this->assertCount(2, $items);
        $this->assertSame(2, (int) $items->firstWhere('price_id', $a4->id)->quantity);
        $this->assertSame(14000, (int) $items->firstWhere('price_id', $a4->id)->subtotal);
        $this->assertSame(5000, (int) $items->firstWhere('price_id', $a5->id)->price);
        $this->assertTrue($items->every(fn ($i) => $i->purchasable_type === Product::class));
    }
}
