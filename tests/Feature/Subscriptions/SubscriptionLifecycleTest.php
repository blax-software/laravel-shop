<?php

namespace Blax\Shop\Tests\Feature\Subscriptions;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Events\SubscriptionCanceled;
use Blax\Shop\Events\SubscriptionRenewed;
use Blax\Shop\Events\SubscriptionStarted;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\Subscription;
use Blax\Shop\Models\SubscriptionItem;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingSubscriptionAction::$calls = [];
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Subscription Product',
            'sku' => 'SUB-'.uniqid(),
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => false,
        ]);
    }

    private function actionFor(Product $product, string $event = 'subscription.started'): void
    {
        ProductAction::create([
            'product_id' => $product->id,
            'events' => [$event],
            'class' => RecordingSubscriptionAction::class,
            'method' => 'handle',
            'defer' => false,
            'active' => true,
        ]);
    }

    private function productWithStripe(string $tag, string $stripeProductId): Product
    {
        return Product::create([
            'name' => 'Bundle Product '.$tag,
            'sku' => 'SUB-'.$tag.'-'.uniqid(),
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => false,
            'stripe_product_id' => $stripeProductId,
        ]);
    }

    private function addItem(Subscription $sub, string $stripeProduct, string $stripePrice): void
    {
        SubscriptionItem::create([
            'subscription_id' => $sub->id,
            'stripe_id' => 'si_'.uniqid(),
            'stripe_product' => $stripeProduct,
            'stripe_price' => $stripePrice,
            'quantity' => 1,
        ]);
    }

    private function subscriptionFor(Product $product, User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_x',
            'quantity' => 1,
        ]);
    }

    #[Test]
    public function the_product_relation_and_resolver_link_subscription_to_product(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $sub = $this->subscriptionFor($product, $user);

        $this->assertTrue($sub->product->is($product));
        $this->assertTrue($sub->resolveProduct()->is($product));
    }

    #[Test]
    public function call_product_actions_runs_actions_with_subscription_context(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->actionFor($product, 'subscription.started');

        $sub = $this->subscriptionFor($product, $user);
        $sub->callProductActions();

        $this->assertCount(1, RecordingSubscriptionAction::$calls);
        $args = RecordingSubscriptionAction::$calls[0];
        $this->assertSame('subscription.started', $args['event']);
        $this->assertInstanceOf(Subscription::class, $args['subscription']);
        $this->assertTrue($args['subscription']->is($sub));
        $this->assertArrayHasKey('expiresAtOverride', $args);
    }

    #[Test]
    public function call_product_actions_grants_every_product_in_a_bundle_subscription(): void
    {
        // A combined / multi-product subscription (e.g. a "configurator" bundle)
        // has one line item per product. Every product's actions must fire — not
        // just the first item's. This is the regression guard for bundle grants.
        $user = User::factory()->create();
        $a = $this->productWithStripe('A', 'prod_A');
        $b = $this->productWithStripe('B', 'prod_B');
        $this->actionFor($a, 'subscription.started');
        $this->actionFor($b, 'subscription.started');

        $sub = Subscription::create([
            'user_id' => $user->id,
            // intentionally NO product_id — resolution must come from the items
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'quantity' => 1,
        ]);
        $this->addItem($sub, 'prod_A', 'price_a');
        $this->addItem($sub, 'prod_B', 'price_b');
        $sub->load('items');

        $sub->callProductActions();

        $this->assertCount(2, RecordingSubscriptionAction::$calls, 'Both bundle products should be fulfilled, not just the first item.');
        $fulfilled = collect(RecordingSubscriptionAction::$calls)
            ->map(fn ($c) => $c['subscriptionItem']?->stripe_product)
            ->sort()->values()->all();
        $this->assertSame(['prod_A', 'prod_B'], $fulfilled);
    }

    #[Test]
    public function record_started_fires_event_and_runs_actions(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->actionFor($product, 'subscription.started');
        $sub = $this->subscriptionFor($product, $user);

        Event::fake([SubscriptionStarted::class]);
        $sub->recordStarted();

        $this->assertCount(1, RecordingSubscriptionAction::$calls);
        Event::assertDispatched(SubscriptionStarted::class, fn (SubscriptionStarted $e) => $e->subscription->is($sub));
    }

    #[Test]
    public function record_renewed_fires_event_and_runs_renewal_actions(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $this->actionFor($product, 'subscription.renewed');
        $sub = $this->subscriptionFor($product, $user);

        Event::fake([SubscriptionRenewed::class]);
        $sub->recordRenewed();

        $this->assertCount(1, RecordingSubscriptionAction::$calls);
        $this->assertSame('subscription.renewed', RecordingSubscriptionAction::$calls[0]['event']);
        Event::assertDispatched(SubscriptionRenewed::class, fn (SubscriptionRenewed $e) => $e->subscription->is($sub));
    }

    #[Test]
    public function record_canceled_fires_event(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $sub = $this->subscriptionFor($product, $user);

        Event::fake([SubscriptionCanceled::class]);
        $sub->recordCanceled();

        Event::assertDispatched(SubscriptionCanceled::class, fn (SubscriptionCanceled $e) => $e->subscription->is($sub));
    }
}

/**
 * Test fulfillment handler — records each invocation's (named) arguments so the
 * test can assert the subscription context was passed through.
 */
class RecordingSubscriptionAction
{
    /** @var array<int, array<string, mixed>> */
    public static array $calls = [];

    public static function handle(...$args): void
    {
        self::$calls[] = $args;
    }
}
