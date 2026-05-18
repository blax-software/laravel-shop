<?php

declare(strict_types=1);

namespace Blax\Shop\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $productModel = config('shop.models.product');

        // Honour the request, fall back to the configured default, clamp to
        // the configured max. Defaults are applied here too so a missing
        // `shop.pagination.*` key can never collapse `min()` to 0 and
        // silently trigger Laravel's built-in default of 15.
        $defaultPerPage = (int) (config('shop.pagination.per_page') ?? 24);
        $maxPerPage = (int) (config('shop.pagination.max_per_page') ?? 100);
        $perPage = (int) request('per_page', $defaultPerPage);
        $perPage = max(1, min($perPage, $maxPerPage));

        $query = $productModel::query()
            ->published()
            ->visible();

        if (request('category')) {
            $query->whereHas('categories', function ($q) {
                $q->where('slug', request('category'));
            });
        }

        if (request('featured')) {
            $query->featured();
        }

        if (request('in_stock')) {
            $query->inStock();
        }

        $products = $query->with(['categories'])
            ->paginate($perPage);

        return response()->json($products);
    }

    public function show(string $slug): JsonResponse
    {
        $productModel = config('shop.models.product');

        $product = $productModel::query()
            ->published()
            ->visible()
            ->where('slug', $slug)
            ->with(['categories', 'children', 'attributes'])
            ->firstOrFail();

        return response()->json([
            'data' => array_merge(
                $product->toArray(),
                [
                    'current_price' => $product->getCurrentPrice(),
                    'on_sale' => $product->isOnSale(),
                ]
            ),
        ]);
    }
}
