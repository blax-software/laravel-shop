<?php

namespace Blax\Shop\Tests\Feature\Cart;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class CartCalendarAvailabilityHintsTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithCart(): array
    {
        $user = User::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'name' => 'Test User',
            'email' => 'hints@example.com',
            'password' => bcrypt('password'),
        ]);

        $cart = Cart::factory()->forCustomer($user)->create();

        return [$user, $cart];
    }

    #[Test]
    public function it_returns_empty_payload_for_empty_cart(): void
    {
        [, $cart] = $this->createUserWithCart();

        $hints = $cart->calendarAvailabilityHints();

        $this->assertSame([], $hints['dates']);
        $this->assertSame([], $hints['items']);
    }

    #[Test]
    public function it_marks_every_visible_day_bookable_when_stock_covers_required_quantity(): void
    {
        [, $cart] = $this->createUserWithCart();

        $product = Product::factory()->withStocks(10)->withPrices(1, 1000)->create();
        $cart->addToCart($product, 1);

        $from = Carbon::today();
        $until = Carbon::today()->addDays(4);
        $hints = $cart->calendarAvailabilityHints($from, $until);

        $this->assertCount(5, $hints['dates']);
        foreach ($hints['dates'] as $iso => $bookable) {
            $this->assertTrue($bookable, "expected $iso bookable");
        }
        $this->assertCount(1, $hints['items']);
        $this->assertSame(1, $hints['items'][0]['required_quantity']);
        $this->assertTrue($hints['items'][0]['ever_available']);
        $this->assertSame([], $hints['items'][0]['dates_unavailable']);
    }

    #[Test]
    public function it_flags_zero_stock_product_as_never_available(): void
    {
        [, $cart] = $this->createUserWithCart();

        $product = Product::factory()->create(['manage_stock' => true]);
        $product->prices()->create([
            'unit_amount' => 1000,
            'currency' => 'usd',
            'is_default' => true,
        ]);
        $cart->addToCart($product, 1);

        $hints = $cart->calendarAvailabilityHints();

        $this->assertNotEmpty($hints['items']);
        $this->assertFalse($hints['items'][0]['ever_available']);
        // Every visible day should be in dates_unavailable.
        $this->assertNotEmpty($hints['items'][0]['dates_unavailable']);
    }

    #[Test]
    public function it_emits_one_items_entry_per_cart_item_row_for_same_product(): void
    {
        [, $cart] = $this->createUserWithCart();

        $product = Product::factory()->withStocks(10)->withPrices(1, 1000)->create();
        // Two add calls of the SAME product still create a single cart_item row
        // (quantity sums) — so we still expect ONE hints entry. This test pins
        // that behaviour so a future refactor that splits adds into separate
        // rows would also need to update the hints emission.
        $cart->addToCart($product, 1);
        $cart->addToCart($product, 2);

        $hints = $cart->calendarAvailabilityHints();

        $this->assertCount(1, $hints['items']);
        $this->assertSame(3, $hints['items'][0]['required_quantity']);
    }

    #[Test]
    public function it_reports_available_for_selected_when_cart_dates_are_fully_bookable(): void
    {
        [, $cart] = $this->createUserWithCart();

        $product = Product::factory()->withStocks(5)->withPrices(1, 1000)->create();
        $cart->addToCart($product, 1);

        $cart->setDates(
            Carbon::today()->addDays(1),
            Carbon::today()->addDays(3),
        );

        $hints = $cart->calendarAvailabilityHints(
            Carbon::today(),
            Carbon::today()->addDays(10),
        );

        $this->assertTrue($hints['items'][0]['available_for_selected']);
    }
}
