<?php

namespace Blax\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ProductAction extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'events',
        'class',
        'method',
        'defer',
        'parameters',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'events' => 'array',
        'parameters' => 'array',
        'active' => 'boolean',
        'defer' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.product_actions', 'product_actions'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.product', Product::class));
    }

    public function runs()
    {
        return $this->morphMany(ProductActionRun::class, 'action');
    }

    protected static function booted(): void
    {
        // 
    }

    // Backward compatibility accessor: expose first event as 'event'
    public function getEventAttribute(): ?string
    {
        $events = $this->events ?? [];
        return is_array($events) ? ($events[0] ?? null) : null;
    }

    // Backward compatibility mutators for legacy fields used in tests/factories
    public function setEventAttribute($value): void
    {
        // Ensure events array reflects provided single event
        $this->events = is_null($value) ? [] : [(string) $value];
    }

    public function setActionTypeAttribute($value): void
    {
        if (is_string($value)) {
            // If a fully-qualified class is passed, use it; otherwise prefix with namespace config
            if (str_starts_with($value, '\\') || str_contains($value, '\\')) {
                $this->class = $value;
            } else {
                $namespace = config('shop.actions.namespace', 'App\\Jobs\\ProductAction');
                $this->class = $namespace . '\\' . $value;
            }
        }
    }

    public static function callForProduct(
        Product $product,
        string $event,
        ?ProductPurchase $productPurchase = null,
        array $additionalData = []
    ): void {
        $actions = $product->actions()
            ->whereJsonContains('events', $event)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        if ($actions->isEmpty()) {
            return;
        }

        foreach ($actions as $action) {
            $success = false;
            try {
                $class = $action->class;
                $method = $action->method;
                $defer = (bool) $action->defer;

                $params = [
                    'product' => $product,
                    'productPurchase' => $productPurchase,
                    'event' => $event,
                    ...($action->parameters ?? []),
                    ...$additionalData,
                ];

                // Skip if class is not defined
                if (empty($class) || !is_string($class)) {
                    Log::warning('Product action class missing', [
                        'product_id' => $product->id,
                        'event' => $event,
                        'action_id' => $action->id ?? null,
                    ]);

                    ProductActionRun::create([
                        'action_id' => $action->id,
                        'action_type' => ProductAction::class,
                        'product_purchase_id' => $productPurchase?->id,
                        'success' => false,
                    ]);

                    continue;
                }

                // Defer via queue or call synchronously
                if ($defer) {
                    // If a method is provided, dispatch a closure job calling the static method
                    if ($method) {
                        defer(
                            fn() =>
                            dispatch(function () use ($class, $method, $params, $action, $productPurchase) {
                                if (!class_exists($class)) {
                                    Log::warning('Product action class not found for deferred static call', ['class' => $class, 'method' => $method]);

                                    ProductActionRun::create([
                                        'action_id' => $action->id,
                                        'action_type' => ProductAction::class,
                                        'product_purchase_id' => $productPurchase?->id,
                                        'success' => false,
                                    ]);
                                }

                                $class::$method(...$params);
                            })
                        );

                        continue;
                    } else {
                        // Assume class is a Job or invokable and can be dispatched directly
                        if (class_exists($class)) {
                            dispatch(new $class(...$params));
                        } else {
                            defer(
                                fn() =>
                                dispatch(function () use ($class, $action, $productPurchase) {
                                    Log::warning('Product action class not found for deferred job', ['class' => $class]);

                                    ProductActionRun::create([
                                        'action_id' => $action->id,
                                        'action_type' => ProductAction::class,
                                        'product_purchase_id' => $productPurchase?->id,
                                        'success' => false,
                                    ]);
                                })
                            );

                            continue;
                        }
                    }

                    // For deferred jobs, we assume success since they were dispatched
                    $success = true;
                } else {
                    if ($method) {
                        // Call static method directly
                        if (class_exists($class)) {
                            $class::$method(...$params);
                            $success = true;
                        } else {
                            Log::warning('Product action class not found for static call', ['class' => $class, 'method' => $method]);

                            ProductActionRun::create([
                                'action_id' => $action->id,
                                'action_type' => ProductAction::class,
                                'product_purchase_id' => $productPurchase?->id,
                                'success' => false,
                            ]);

                            continue;
                        }
                    } else {
                        // Instantiate and invoke if invokable
                        if (class_exists($class)) {
                            $instance = new $class(...$params);
                            if (is_callable($instance)) {
                                $instance();
                                $success = true;
                            }
                        } else {
                            Log::warning('Product action class not found for direct instantiation', ['class' => $class]);

                            ProductActionRun::create([
                                'action_id' => $action->id,
                                'action_type' => ProductAction::class,
                                'product_purchase_id' => $productPurchase?->id,
                                'success' => false,
                            ]);

                            continue;
                        }
                    }
                }

                // Log successful action run
                ProductActionRun::create([
                    'action_id' => $action->id,
                    'action_type' => ProductAction::class,
                    'product_purchase_id' => $productPurchase?->id,
                    'success' => $success,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error calling product action', [
                    'product_id' => $product->id,
                    'event' => $event,
                    'class' => $action->class ?? 'unknown',
                    'method' => $action->method ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Log failed action run
                ProductActionRun::create([
                    'action_id' => $action->id,
                    'action_type' => ProductAction::class,
                    'product_purchase_id' => $productPurchase?->id,
                    'success' => false,
                ]);

                report($e);
            }
        }
    }
}
