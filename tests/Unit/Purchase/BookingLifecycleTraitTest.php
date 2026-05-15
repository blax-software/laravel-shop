<?php

namespace Blax\Shop\Tests\Unit\Purchase;

use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Bare unit tests for {@see \Blax\Shop\Traits\HasBookingLifecycle} — the
 * trait extracted from ProductPurchase. Tests the trait's contract directly
 * on a ProductPurchase row, independent of Product / Cart / pool wiring.
 */
class BookingLifecycleTraitTest extends TestCase
{
    use RefreshDatabase;

    private function purchase(array $attrs = []): ProductPurchase
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['manage_stock' => false]);

        return $product->purchases()->create(array_merge([
            'purchaser_id' => $user->id,
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
        ], $attrs));
    }

    #[Test]
    public function is_booking_is_true_only_when_both_from_and_until_are_set(): void
    {
        $this->assertFalse($this->purchase()->isBooking());

        $this->assertFalse(
            $this->purchase(['from' => Carbon::parse('2026-01-01 10:00:00')])->isBooking(),
            'only from set → not a booking'
        );

        $this->assertFalse(
            $this->purchase(['until' => Carbon::parse('2026-01-10 10:00:00')])->isBooking(),
            'only until set → not a booking'
        );

        $this->assertTrue(
            $this->purchase([
                'from' => Carbon::parse('2026-01-01 10:00:00'),
                'until' => Carbon::parse('2026-01-10 10:00:00'),
            ])->isBooking()
        );
    }

    #[Test]
    public function is_booking_ended_returns_false_for_non_bookings(): void
    {
        $purchase = $this->purchase();

        $this->assertFalse($purchase->isBookingEnded());
    }

    #[Test]
    public function is_booking_ended_returns_true_only_after_until(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-05 10:00:00'));

        $future = $this->purchase([
            'from' => Carbon::parse('2026-01-01 10:00:00'),
            'until' => Carbon::parse('2026-01-10 10:00:00'),
        ]);
        $this->assertFalse($future->isBookingEnded(), 'mid-booking → not ended');

        Carbon::setTestNow(Carbon::parse('2026-01-11 10:00:00'));
        $this->assertTrue($future->isBookingEnded(), 'past until → ended');
    }

    #[Test]
    public function bookings_scope_returns_only_rows_with_both_dates(): void
    {
        $bookingId = $this->purchase([
            'from' => Carbon::parse('2026-01-01 10:00:00'),
            'until' => Carbon::parse('2026-01-10 10:00:00'),
        ])->id;

        $partialId = $this->purchase(['from' => Carbon::parse('2026-01-01 10:00:00')])->id;
        $plainId = $this->purchase()->id;

        $ids = ProductPurchase::query()->bookings()->pluck('id')->all();

        $this->assertContains($bookingId, $ids);
        $this->assertNotContains($partialId, $ids);
        $this->assertNotContains($plainId, $ids);
    }

    #[Test]
    public function ended_bookings_scope_is_past_until_intersected_with_bookings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 10:00:00'));

        $past = $this->purchase([
            'from' => Carbon::parse('2026-01-01 10:00:00'),
            'until' => Carbon::parse('2026-01-10 10:00:00'),
        ]);
        $future = $this->purchase([
            'from' => Carbon::parse('2026-09-01 10:00:00'),
            'until' => Carbon::parse('2026-09-10 10:00:00'),
        ]);

        $ids = ProductPurchase::query()->endedBookings()->pluck('id')->all();

        $this->assertContains($past->id, $ids);
        $this->assertNotContains($future->id, $ids);
    }
}
