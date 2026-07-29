<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Enums\SeatStatus;
use Blax\Shop\Models\LicenseSeat;
use Blax\Shop\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class LicenseSeatFactory extends Factory
{
    protected $model = LicenseSeat::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'status' => SeatStatus::UNASSIGNED,
            'meta' => [],
        ];
    }

    public function assignedTo(Model $assignee): static
    {
        return $this->state(fn () => [
            'status' => SeatStatus::ASSIGNED,
            'assignee_id' => (string) $assignee->getKey(),
            'assignee_type' => $assignee->getMorphClass(),
            'assigned_at' => now(),
        ]);
    }
}
