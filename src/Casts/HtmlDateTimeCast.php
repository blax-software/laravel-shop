<?php

namespace Blax\Shop\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast for datetime fields that:
 * - Accepts string, DateTimeInterface, Carbon, or Unix timestamp as input
 * - Stores as datetime string in database (for timestamp columns)
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

        // Handle datetime strings from database
        return Carbon::parse($value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Handle Carbon instances
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        // Handle DateTimeInterface
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d H:i:s');
        }

        // Handle string input (including HTML5 datetime-local format)
        if (is_string($value)) {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        }

        // Handle numeric timestamp (Unix timestamp)
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value)->format('Y-m-d H:i:s');
        }

        throw new \InvalidArgumentException(
            "Invalid datetime value for {$key}. Expected string, DateTimeInterface, Carbon, or timestamp."
        );
    }
}
