<?php

namespace Blax\Shop\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $productModel = config('shop.models.product');

        $perPage = min(
            request('per_page', config('shop.pagination.per_page')),
            config('shop.pagination.max_per_page')
        );

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
                    'average_rating' => $product->getAverageRating(),
                ]
            ),
        ]);
    }
}
