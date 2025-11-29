<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\PaymentMethod;
use Blax\Shop\Models\PaymentProviderIdentity;
use Blax\Shop\Tests\TestCase;
use Illuminate\Support\Carbon;

class PaymentMethodFieldsTest extends TestCase
{
    public function test_can_store_last_alphanumeric(): void
    {
        $identity = PaymentProviderIdentity::factory()->stripe()->create();

        $method = PaymentMethod::factory()
            ->forProviderIdentity($identity)
            ->create([
                'type' => 'wallet',
                'last_alphanumeric' => 'abc123xyz',
                'expires_at' => null,
            ]);

        $this->assertNotNull($method->id);
        $this->assertSame('abc123xyz', $method->last_alphanumeric);
    }

    public function test_expiration_via_expires_at(): void
    {
        $identity = PaymentProviderIdentity::factory()->stripe()->create();

        $past = Carbon::now()->subDay();
        $future = Carbon::now()->addDay();

        $expired = PaymentMethod::factory()
            ->forProviderIdentity($identity)
            ->create([
                'type' => 'wallet',
                'expires_at' => $past,
                'exp_month' => null,
                'exp_year' => null,
            ]);
        $this->assertTrue($expired->isExpired());

        $active = PaymentMethod::factory()
            ->forProviderIdentity($identity)
            ->create([
                'type' => 'wallet',
                'expires_at' => $future,
                'exp_month' => null,
                'exp_year' => null,
            ]);
        $this->assertFalse($active->isExpired());
    }

    public function test_expiration_via_month_year(): void
    {
        $identity = PaymentProviderIdentity::factory()->stripe()->create();

        $expired = PaymentMethod::factory()
            ->forProviderIdentity($identity)
            ->create([
                'type' => 'card',
                'exp_month' => 1,
                'exp_year' => Carbon::now()->year - 1,
                'expires_at' => null,
            ]);
        $this->assertTrue($expired->isExpired());

        $nonExpired = PaymentMethod::factory()
            ->forProviderIdentity($identity)
            ->create([
                'type' => 'card',
                'exp_month' => Carbon::now()->month,
                'exp_year' => Carbon::now()->year + 1,
                'expires_at' => null,
            ]);
        $this->assertFalse($nonExpired->isExpired());
    }
}
