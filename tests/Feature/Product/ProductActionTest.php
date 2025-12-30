<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class ProductActionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_a_product_action()
    {
        $product = Product::factory()->create();

        $action = $product->actions()->create([
            'events' => ['purchased', 'refunded'],
            'class' => 'App\\Actions\\SendWelcomeEmail',
        ]);

        $this->assertDatabaseHas('product_actions', [
            'id' => $action->id,
            'product_id' => $product->id,
        ]);

        $this->assertContains('purchased', $action->events ?? []);
        $this->assertContains('refunded', $action->events ?? []);
    }

    #[Test]
    public function product_has_many_actions()
    {
        $product = Product::factory()->create();

        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\SendWelcomeEmail',
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\GrantAccess',
        ]);

        $this->assertCount(2, $product->fresh()->actions);
    }

    #[Test]
    public function action_belongs_to_product()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'event' => ['purchased'],
            'class' => 'App\\Actions\\TestAction',
        ]);

        $this->assertEquals($product->id, $action->product->id);
    }

    #[Test]
    public function it_can_enable_and_disable_actions()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\TestAction',
        ]);

        $action->refresh();

        $this->assertTrue($action->active);

        $action->update(['active' => false]);

        $this->assertFalse($action->fresh()->active);
    }

    #[Test]
    public function it_can_store_action_parameters()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\SendEmail',
            'parameters' => [
                'template' => 'welcome',
                'delay' => 60,
                'subject' => 'Welcome to our service',
            ],
        ]);

        $action = $action->fresh();

        $this->assertEquals('welcome', $action->parameters['template']);
        $this->assertEquals(60, $action->parameters['delay']);
        $this->assertEquals('Welcome to our service', $action->parameters['subject']);
    }

    #[Test]
    public function it_can_set_action_priority()
    {
        $product = Product::factory()->create();

        $action1 = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\FirstAction',
        ]);

        $action2 = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\SecondAction',
        ]);

        $sorted = ProductAction::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertEquals($action1->id, $sorted[0]->id);
        $this->assertEquals($action2->id, $sorted[1]->id);
    }

    #[Test]
    public function it_can_have_different_events()
    {
        $product = Product::factory()->create();

        $purchasedAction = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\OnPurchase',
            'active' => true,
        ]);

        $refundedAction = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['refunded'],
            'class' => 'App\\Actions\\OnRefund',
            'active' => true,
        ]);

        $this->assertContains('purchased', $purchasedAction->events);
        $this->assertContains('refunded', $refundedAction->events);
    }

    #[Test]
    public function it_can_get_actions_for_specific_event()
    {
        $product = Product::factory()->create();

        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\OnPurchase',
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\OnPurchase',
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['refunded'],
            'class' => 'App\\Actions\\OnPurchase',
        ]);

        $purchaseActions = ProductAction::where('product_id', $product->id)
            ->whereJsonContains('events', 'purchased')
            ->get();

        $this->assertCount(2, $purchaseActions);
    }

    #[Test]
    public function it_can_filter_enabled_actions()
    {
        $product = Product::factory()->create();

        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\EnabledAction',
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\DisabledAction',
            'active' => false,
        ]);

        $enabledActions = ProductAction::where('product_id', $product->id)
            ->where('active', true)
            ->get();

        $this->assertCount(1, $enabledActions);
    }

    #[Test]
    public function multiple_products_can_have_same_action()
    {
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        ProductAction::create([
            'product_id' => $product1->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\CommonAction',
        ]);

        ProductAction::create([
            'product_id' => $product2->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\CommonAction',
        ]);

        $this->assertCount(1, $product1->actions);
        $this->assertCount(1, $product2->actions);
    }

    #[Test]
    public function it_can_update_action_parameters()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\TestAction',
            'parameters' => [
                'key' => 'old_value'
            ],
        ]);

        $action->update([
            'parameters' => [
                'key' => 'new_value',
                'another_key' => 'another_value'
            ],
        ]);

        $fresh = $action->fresh();

        $this->assertNotEquals('old_value', $fresh->parameters['key']);
        $this->assertEquals('new_value', $fresh->parameters['key']);
        $this->assertEquals('another_value', $fresh->parameters['another_key']);
    }

    #[Test]
    public function deleting_product_deletes_actions()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'event' => ['purchased'],
            'class' => 'App\\Actions\\TestAction',
            'active' => true,
        ]);

        $actionId = $action->id;

        $product->delete();

        $this->assertDatabaseMissing('product_actions', ['id' => $actionId]);
    }

    #[Test]
    public function action_can_have_empty_parameters()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\SimpleAction',
        ]);

        $this->assertNull($action->parameters);
    }

    #[Test]
    public function it_can_query_actions_by_priority_order()
    {
        $product = Product::factory()->create();

        $high = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\HighPriority',
            'sort_order' => 100,
            'active' => true,
        ]);

        $medium = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\MediumPriority',
            'sort_order' => 50,
            'active' => true,
        ]);

        $low = ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Actions\\LowPriority',
            'sort_order' => 10,
            'active' => true,
        ]);

        $ordered = ProductAction::where('product_id', $product->id)
            ->orderBy('sort_order', 'asc')
            ->get();

        $this->assertEquals($low->id, $ordered[0]->id);
        $this->assertEquals($medium->id, $ordered[1]->id);
        $this->assertEquals($high->id, $ordered[2]->id);
    }

    #[Test]
    public function it_can_be_triggered_on_purchase()
    {
        $user = User::factory()->create();
        $product = Product::factory()
            ->withStocks()
            ->withPrices(1, 5000)
            ->create();

        $product->actions()->create([
            'events' => ['purchased'],
            'class' => 'App\\Actions\\SendThankYouEmail',
            'defer' => false,
        ]);

        $product->actions()->create([
            'events' => ['purchased'],
            'class' => 'App\\Actions\\SendThankYouEmail',
            'defer' => false,
        ]);

        $purchase = $user->purchase($product, 1);

        $this->assertEquals(2, $purchase->actionRuns()->count());
    }
}
