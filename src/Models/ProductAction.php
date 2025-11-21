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
        'event',
        'action_type',
        'config',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'config' => 'array',
        'active' => 'boolean',
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

    public static function callForProduct(
        Product $product,
        string $event,
        ?ProductPurchase $productPurchase = null,
        array $additionalData = []
    ): void {
        $actions = $product->actions()
            ->where('event', $event)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        if ($actions->isEmpty()) {
            return;
        }

        $available_actions = self::getAvailableActions();

        foreach ($actions as $action) {
            try {
                if (!isset($available_actions[$action->action_type])) {
                    Log::warning('Product action not found', [
                        'product_id' => $product->id,
                        'event' => $event,
                        'action_type' => $action->action_type,
                    ]);
                    continue;
                }

                $namespace = config('shop.actions.namespace', 'App\\Jobs\\ProductAction');
                $action_job = $namespace . '\\' . $action->action_type;

                $params = [
                    'product' => $product,
                    'productPurchase' => $productPurchase,
                    'event' => $event,
                    ...($action->config ?? []),
                    ...$additionalData,
                ];

                dispatch(new $action_job(...$params));
            } catch (\Exception $e) {
                Log::error('Error calling product action', [
                    'product_id' => $product->id,
                    'event' => $event,
                    'action_type' => $action->action_type ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                report($e);
            }
        }
    }

    public function execute(
        Product $product,
        ?ProductPurchase $productPurchase = null,
        array $additionalData = []
    ): void {
        $namespace = config('shop.actions.namespace', 'App\\Jobs\\ProductAction');
        $action_job = $namespace . '\\' . $this->action_type;

        if (!class_exists($action_job)) {
            throw new \Exception("Action class {$action_job} not found");
        }

        $params = [
            'product' => $product,
            'productPurchase' => $productPurchase,
            'event' => $this->event,
            ...($this->config ?? []),
            ...$additionalData,
        ];

        dispatch(new $action_job(...$params));
    }
}
