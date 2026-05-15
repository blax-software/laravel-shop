<?php

namespace Blax\Shop\Tests\Unit\Resources;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Http\Resources\PurchaseResource;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class PurchaseResourceTest extends TestCase
{
    use RefreshDatabase;

    private function loan(array $overrides = []): ProductPurchase
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Hyperion',
            'type' => ProductType::LOANABLE,
            'manage_stock' => true,
        ]);
        $product->increaseStock(1);

        return $product->purchases()->create(array_merge([
            'purchaser_id' => $user->id,
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
            'from' => Carbon::parse('2026-05-14 10:00:00'),
            'until' => Carbon::parse('2026-05-28 10:00:00'),
            'meta' => ['extensions_used' => 0],
        ], $overrides));
    }

    #[Test]
    public function it_translates_e_commerce_columns_into_loan_vocabulary(): void
    {
        $loan = $this->loan();

        $payload = PurchaseResource::make($loan)->toArray(Request::create('/'));

        $this->assertSame($loan->id, $payload['id']);
        $this->assertSame('2026-05-14T10:00:00+00:00', $payload['loaned_at']);
        $this->assertSame('2026-05-28T10:00:00+00:00', $payload['due_at']);
        $this->assertNull($payload['returned_at']);
        $this->assertSame('active', $payload['status']);
        $this->assertSame(0, $payload['extensions_used']);
        $this->assertSame(PurchaseStatus::PENDING->value, $payload['lifecycle_status']);
        $this->assertSame(1, $payload['quantity']);
    }

    #[Test]
    public function it_surfaces_returned_status_after_mark_returned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 16:00:00'));

        $loan = $this->loan();
        $loan->markReturned();

        $payload = PurchaseResource::make($loan)->toArray(Request::create('/'));

        $this->assertSame('returned', $payload['status']);
        $this->assertSame('2026-05-20T16:00:00+00:00', $payload['returned_at']);
        $this->assertSame(PurchaseStatus::COMPLETED->value, $payload['lifecycle_status']);
    }

    #[Test]
    public function it_reports_overdue_when_due_date_is_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

        $loan = $this->loan();

        $payload = PurchaseResource::make($loan)->toArray(Request::create('/'));

        $this->assertSame('overdue', $payload['status']);
        $this->assertNull($payload['returned_at']);
    }

    #[Test]
    public function purchasable_resource_hook_can_serialise_the_item(): void
    {
        $loan = $this->loan();
        $loan->load('purchasable');

        $resource = new class ($loan) extends PurchaseResource {
            protected function purchasableResource(): ?string
            {
                return BookSummaryResource::class;
            }
        };

        $request = Request::create('/');
        $payload = $resource->toArray($request);

        // PurchaseResource hands back the nested resource as a JsonResource
        // instance; Laravel resolves it during HTTP serialisation. Resolve it
        // here to verify shape.
        $this->assertInstanceOf(JsonResource::class, $payload['item']);
        $this->assertSame('Hyperion', $payload['item']->toArray($request)['name']);
    }
}

class BookSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
