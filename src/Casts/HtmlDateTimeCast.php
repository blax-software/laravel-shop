<?php

namespace Blax\Shop\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast for datetime fields that:
 * - Accepts string, DateTimeInterface, or Carbon as input
 * - Stores as Unix timestamp in database (integer)
 * - Returns Carbon instance on get
 * 
 * Usage for HTML5 datetime-local inputs:
 * $model->created_at->format('Y-m-d\TH:i')
 */
class HtmlDateTimeCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        // Convert timestamp to Carbon
        return Carbon::createFromTimestamp($value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        // Handle Carbon instances
        if ($value instanceof Carbon) {
            return $value->timestamp;
        }

        // Handle DateTimeInterface
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->timestamp;
        }

        // Handle string input (including HTML5 datetime-local format)
        if (is_string($value)) {
            return Carbon::parse($value)->timestamp;
        }

        // Handle numeric timestamp
        if (is_numeric($value)) {
            return (int) $value;
        }

        throw new \InvalidArgumentException(
            "Invalid datetime value for {$key}. Expected string, DateTimeInterface, Carbon, or timestamp."
        );
    }
}
