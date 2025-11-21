<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

class ProductActionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_a_product_action()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\SendWelcomeEmail',
            'active' => true,
            'sort_order' => 10,
        ]);

        $this->assertDatabaseHas('product_actions', [
            'id' => $action->id,
            'product_id' => $product->id,
            'event' => 'purchased',
        ]);
    }

    /** @test */
    public function product_has_many_actions()
    {
        $product = Product::factory()->create();

        ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\SendWelcomeEmail',
            'active' => true,
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\GrantAccess',
            'active' => true,
        ]);

        $this->assertCount(2, $product->fresh()->actions);
    }

    /** @test */
    public function action_belongs_to_product()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\TestAction',
            'active' => true,
        ]);

        $this->assertEquals($product->id, $action->product->id);
    }

    /** @test */
    public function it_can_enable_and_disable_actions()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\TestAction',
            'active' => true,
        ]);

        $this->assertTrue($action->active);

        $action->update(['active' => false]);

        $this->assertFalse($action->fresh()->active);
    }

    /** @test */
    public function it_can_store_action_parameters()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\SendEmail',
            'parameters' => [
                'template' => 'welcome',
                'delay' => 60,
                'subject' => 'Welcome to our service',
            ],
            'active' => true,
        ]);

        $this->assertEquals('welcome', $action->parameters['template']);
        $this->assertEquals(60, $action->parameters['delay']);
        $this->assertEquals('Welcome to our service', $action->parameters['subject']);
    }

    /** @test */
    public function it_can_set_action_priority()
    {
        $product = Product::factory()->create();

        $action1 = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\FirstAction',
            'sort_order' => 1,
            'active' => true,
        ]);

        $action2 = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\SecondAction',
            'sort_order' => 2,
            'active' => true,
        ]);

        $sorted = ProductAction::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertEquals($action1->id, $sorted[0]->id);
        $this->assertEquals($action2->id, $sorted[1]->id);
    }

    /** @test */
    public function it_can_have_different_events()
    {
        $product = Product::factory()->create();

        $purchasedAction = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\OnPurchase',
            'active' => true,
        ]);

        $refundedAction = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'refunded',
            'action_type' => 'App\\Actions\\OnRefund',
            'active' => true,
        ]);

        $this->assertEquals('purchased', $purchasedAction->event);
        $this->assertEquals('refunded', $refundedAction->event);
    }

    /** @test */
    public function it_can_get_actions_for_specific_event()
    {
        $product = Product::factory()->create();

        ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\OnPurchase',
            'active' => true,
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\AnotherPurchase',
            'active' => true,
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'event' => 'refunded',
            'action_type' => 'App\\Actions\\OnRefund',
            'active' => true,
        ]);

        $purchaseActions = ProductAction::where('product_id', $product->id)
            ->where('event', 'purchased')
            ->get();

        $this->assertCount(2, $purchaseActions);
    }

    /** @test */
    public function it_can_filter_enabled_actions()
    {
        $product = Product::factory()->create();

        ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\EnabledAction',
            'active' => true,
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\DisabledAction',
            'active' => false,
        ]);

        $enabledActions = ProductAction::where('product_id', $product->id)
            ->where('active', true)
            ->get();

        $this->assertCount(1, $enabledActions);
    }

    /** @test */
    public function multiple_products_can_have_same_action()
    {
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        ProductAction::create([
            'product_id' => $product1->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\CommonAction',
            'active' => true,
        ]);

        ProductAction::create([
            'product_id' => $product2->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\CommonAction',
            'active' => true,
        ]);

        $this->assertCount(1, $product1->actions);
        $this->assertCount(1, $product2->actions);
    }

    /** @test */
    public function it_can_update_action_parameters()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\TestAction',
            'parameters' => ['key' => 'old_value'],
            'active' => true,
        ]);

        $action->update([
            'parameters' => ['key' => 'new_value', 'another_key' => 'another_value'],
        ]);

        $fresh = $action->fresh();
        $this->assertEquals('new_value', $fresh->parameters['key']);
        $this->assertEquals('another_value', $fresh->parameters['another_key']);
    }

    /** @test */
    public function deleting_product_deletes_actions()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\TestAction',
            'active' => true,
        ]);

        $actionId = $action->id;

        $product->delete();

        $this->assertDatabaseMissing('product_actions', ['id' => $actionId]);
    }

    /** @test */
    public function action_can_have_empty_parameters()
    {
        $product = Product::factory()->create();

        $action = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\SimpleAction',
            'active' => true,
        ]);

        $this->assertNull($action->parameters);
    }

    /** @test */
    public function it_can_query_actions_by_priority_order()
    {
        $product = Product::factory()->create();

        $high = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\HighPriority',
            'sort_order' => 100,
            'active' => true,
        ]);

        $medium = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\MediumPriority',
            'sort_order' => 50,
            'active' => true,
        ]);

        $low = ProductAction::create([
            'product_id' => $product->id,
            'event' => 'purchased',
            'action_type' => 'App\\Actions\\LowPriority',
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
}
