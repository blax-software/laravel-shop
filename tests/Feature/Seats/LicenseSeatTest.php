<?php

namespace Blax\Shop\Tests\Feature\Seats;

use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Enums\SeatStatus;
use Blax\Shop\Events\SeatAssigned;
use Blax\Shop\Events\SeatReassigned;
use Blax\Shop\Events\SeatRevoked;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\User;

/**
 * Test double: records the grantee + event each time the product's seat grant
 * actions fire, so a test can prove an assigned seat confers access to the
 * *holder* and revokes it from the *previous* holder — reusing the exact same
 * ProductAction engine a normal purchase uses.
 */
class SeatGrantSpy
{
    /** @var array<int, array{event: ?string, grantee_id: ?string, seat_id: ?string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public static function handle(...$args): void
    {
        self::$calls[] = [
            'event' => $args['event'] ?? null,
            'grantee_id' => isset($args['grantee']) ? (string) $args['grantee']->getKey() : null,
            'seat_id' => isset($args['seat']) ? (string) $args['seat']->getKey() : null,
        ];
    }

    /** @return array<int, string> grantee ids granted/revoked for $event, in order */
    public static function granteesFor(string $event): array
    {
        return array_values(array_filter(array_map(
            fn ($c) => $c['event'] === $event ? $c['grantee_id'] : null,
            self::$calls,
        )));
    }
}

class LicenseSeatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SeatGrantSpy::reset();
    }

    private function seatBasedProduct(): Product
    {
        $product = Product::factory()->create([
            'meta' => ['seat_based' => true],
            'status' => 'published',
            'is_visible' => true,
        ]);

        // One action serving both seat lifecycle events, recording the grantee.
        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['seat.assigned', 'seat.revoked'],
            'class' => SeatGrantSpy::class,
            'method' => 'handle',
            'defer' => false,
            'active' => true,
        ]);

        return $product;
    }

    private function completedPurchase(Product $product, User $buyer, int $quantity): ProductPurchase
    {
        return ProductPurchase::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'purchaser_id' => $buyer->getKey(),
            'purchaser_type' => $buyer->getMorphClass(),
            'quantity' => $quantity,
            'status' => PurchaseStatus::COMPLETED,
            'amount' => 1000 * $quantity,
        ]);
    }

    public function test_completed_seat_based_purchase_provisions_one_seat_per_quantity(): void
    {
        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();

        $purchase = $this->completedPurchase($product, $buyer, 3);

        $this->assertSame(3, $purchase->seats()->count());
        $this->assertSame(3, $purchase->seats()->where('status', SeatStatus::UNASSIGNED->value)->count());
    }

    public function test_provisioning_is_idempotent(): void
    {
        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();
        $purchase = $this->completedPurchase($product, $buyer, 2);

        // Re-running fulfillment (a duplicate / updated webhook) must not
        // over-provision.
        $purchase->update(['meta' => (object) ['touched' => true]]);

        $this->assertSame(2, $purchase->seats()->count());
    }

    public function test_non_seat_product_provisions_no_seats(): void
    {
        $product = Product::factory()->create([
            'meta' => ['seat_based' => false],
            'status' => 'published',
            'is_visible' => true,
        ]);
        $buyer = User::factory()->create();

        $purchase = $this->completedPurchase($product, $buyer, 4);

        $this->assertSame(0, $purchase->seats()->count());
    }

    public function test_assigning_a_seat_grants_the_holder_not_the_buyer(): void
    {
        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();
        $student = User::factory()->create();
        $purchase = $this->completedPurchase($product, $buyer, 1);
        $seat = $purchase->seats()->first();

        Event::fake([SeatAssigned::class]);
        SeatGrantSpy::reset();

        $seat->assign($student);
        $seat->refresh();

        $this->assertSame(SeatStatus::ASSIGNED, $seat->status);
        $this->assertSame((string) $student->getKey(), (string) $seat->assignee_id);

        $grantees = SeatGrantSpy::granteesFor('seat.assigned');
        $this->assertSame([(string) $student->getKey()], $grantees);
        $this->assertNotContains((string) $buyer->getKey(), $grantees);

        Event::assertDispatched(SeatAssigned::class);
    }

    public function test_reassigning_moves_access_from_one_holder_to_another(): void
    {
        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $purchase = $this->completedPurchase($product, $buyer, 1);
        $seat = $purchase->seats()->first();

        $seat->assign($a);

        Event::fake([SeatReassigned::class]);
        SeatGrantSpy::reset();

        $seat->reassign($b);
        $seat->refresh();

        $this->assertSame((string) $b->getKey(), (string) $seat->assignee_id);
        $this->assertSame([(string) $a->getKey()], SeatGrantSpy::granteesFor('seat.revoked'));
        $this->assertSame([(string) $b->getKey()], SeatGrantSpy::granteesFor('seat.assigned'));

        Event::assertDispatched(SeatReassigned::class);
    }

    public function test_reclaiming_frees_the_seat_and_revokes_access(): void
    {
        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();
        $student = User::factory()->create();
        $purchase = $this->completedPurchase($product, $buyer, 1);
        $seat = $purchase->seats()->first();

        $seat->assign($student);

        Event::fake([SeatRevoked::class]);
        SeatGrantSpy::reset();

        $seat->reclaim();
        $seat->refresh();

        $this->assertSame(SeatStatus::UNASSIGNED, $seat->status);
        $this->assertNull($seat->assignee_id);
        $this->assertSame([(string) $student->getKey()], SeatGrantSpy::granteesFor('seat.revoked'));

        Event::assertDispatched(SeatRevoked::class);
    }

    public function test_retiring_a_seat_revokes_access_and_leaves_it_unassignable(): void
    {
        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();
        $student = User::factory()->create();
        $purchase = $this->completedPurchase($product, $buyer, 1);
        $seat = $purchase->seats()->first();
        $seat->assign($student);

        SeatGrantSpy::reset();
        $seat->retire();
        $seat->refresh();

        $this->assertSame(SeatStatus::REVOKED, $seat->status);
        $this->assertNull($seat->assignee_id);
        $this->assertSame([(string) $student->getKey()], SeatGrantSpy::granteesFor('seat.revoked'));
        // A retired seat no longer counts against the pool.
        $this->assertSame(0, $purchase->seats()->active()->count());
    }

    public function test_refresh_grant_refires_the_holders_grant(): void
    {
        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();
        $student = User::factory()->create();
        $purchase = $this->completedPurchase($product, $buyer, 1);
        $seat = $purchase->seats()->first();
        $seat->assign($student);

        SeatGrantSpy::reset();
        $seat->refreshGrant();

        $this->assertSame([(string) $student->getKey()], SeatGrantSpy::granteesFor('seat.assigned'));
    }

    public function test_assigning_a_held_seat_delegates_to_reassign(): void
    {
        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $purchase = $this->completedPurchase($product, $buyer, 1);
        $seat = $purchase->seats()->first();

        $seat->assign($a);
        SeatGrantSpy::reset();

        // Assigning an already-held seat to a different user moves it.
        $seat->assign($b);
        $seat->refresh();

        $this->assertSame((string) $b->getKey(), (string) $seat->assignee_id);
        $this->assertSame([(string) $a->getKey()], SeatGrantSpy::granteesFor('seat.revoked'));
        $this->assertSame([(string) $b->getKey()], SeatGrantSpy::granteesFor('seat.assigned'));
    }

    public function test_reassigning_to_the_same_holder_does_not_revoke(): void
    {
        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();
        $student = User::factory()->create();
        $purchase = $this->completedPurchase($product, $buyer, 1);
        $seat = $purchase->seats()->first();

        $seat->assign($student);
        SeatGrantSpy::reset();

        $seat->reassign($student);

        $this->assertSame([], SeatGrantSpy::granteesFor('seat.revoked'));
        $this->assertSame([(string) $student->getKey()], SeatGrantSpy::granteesFor('seat.assigned'));
    }

    public function test_seats_disabled_globally_provisions_nothing(): void
    {
        config()->set('shop.seats.enabled', false);

        $product = $this->seatBasedProduct();
        $buyer = User::factory()->create();

        $purchase = $this->completedPurchase($product, $buyer, 3);

        $this->assertSame(0, $purchase->seats()->count());
    }
}
