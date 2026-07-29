<?php

namespace Blax\Shop\Tests\Feature\Seats;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\SeatStatus;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\Subscription;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

class SeatSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SubSeatGrantSpy::$calls = [];
    }

    private function seatProduct(bool $seatBased = true): Product
    {
        $product = Product::create([
            'name' => 'School Seats',
            'sku' => 'SEATS-'.uniqid(),
            'type' => ProductType::SUBSCRIPTION,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => false,
            'meta' => ['seat_based' => $seatBased],
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['seat.assigned', 'seat.revoked'],
            'class' => SubSeatGrantSpy::class,
            'method' => 'handle',
            'defer' => false,
            'active' => true,
        ]);

        return $product;
    }

    private function subscriptionFor(Product $product, User $user, int $quantity): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_x',
            'quantity' => $quantity,
        ]);
    }

    public function test_record_started_provisions_one_seat_per_quantity_with_expiry(): void
    {
        $user = User::factory()->create();
        $product = $this->seatProduct();
        $sub = $this->subscriptionFor($product, $user, 5);

        $expires = now()->addMonth();
        $sub->recordStarted($expires);

        $this->assertSame(5, $sub->seats()->count());
        $this->assertSame(5, $sub->seats()->where('status', SeatStatus::UNASSIGNED->value)->count());

        $seat = $sub->seats()->first();
        $this->assertNotNull($seat->expires_at);
        $this->assertSame($expires->toDateTimeString(), $seat->expires_at->toDateTimeString());
    }

    public function test_renewal_extends_expiry_and_refreshes_held_seat_grants(): void
    {
        $user = User::factory()->create();
        $student = User::factory()->create();
        $product = $this->seatProduct();
        $sub = $this->subscriptionFor($product, $user, 2);

        $sub->recordStarted(now()->addMonth());
        $seat = $sub->seats()->first();
        $seat->assign($student);

        SubSeatGrantSpy::$calls = [];
        $newExpiry = now()->addMonths(2);
        $sub->recordRenewed($newExpiry);

        $seat->refresh();
        $this->assertSame($newExpiry->toDateTimeString(), $seat->expires_at->toDateTimeString());

        // The held seat's grant was re-fired for the new cycle, targeting the
        // holder (never the subscription owner).
        $granted = array_values(array_filter(array_map(
            fn ($c) => ($c['event'] ?? null) === 'seat.assigned' && isset($c['grantee'])
                ? (string) $c['grantee']->getKey()
                : null,
            SubSeatGrantSpy::$calls,
        )));
        $this->assertContains((string) $student->getKey(), $granted);
    }

    public function test_renewal_does_not_over_provision(): void
    {
        $user = User::factory()->create();
        $product = $this->seatProduct();
        $sub = $this->subscriptionFor($product, $user, 3);

        $sub->recordStarted(now()->addMonth());
        $sub->recordRenewed(now()->addMonths(2));

        $this->assertSame(3, $sub->seats()->count());
    }

    public function test_non_seat_subscription_provisions_no_seats(): void
    {
        $user = User::factory()->create();
        $product = $this->seatProduct(seatBased: false);
        $sub = $this->subscriptionFor($product, $user, 4);

        $sub->recordStarted(now()->addMonth());

        $this->assertSame(0, $sub->seats()->count());
    }
}

/**
 * Records the grantee + event of each seat grant action so subscription tests
 * can assert renewal re-grants the holder.
 */
class SubSeatGrantSpy
{
    /** @var array<int, array<string, mixed>> */
    public static array $calls = [];

    public static function handle(...$args): void
    {
        self::$calls[] = $args;
    }
}
