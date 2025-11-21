<?php

namespace Blax\Shop\Http\Controllers\Api;

use Blax\Shop\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = ProductCategory::visible()
            ->roots()
            ->with('children')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    public function tree(): JsonResponse
    {
        return response()->json([
            'data' => ProductCategory::getTree(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $category = ProductCategory::visible()
            ->where('slug', $slug)
            ->with(['children', 'parent'])
            ->firstOrFail();

        return response()->json([
            'data' => array_merge(
                $category->toArray(),
                ['breadcrumbs' => $category->getPath()]
            ),
        ]);
    }

    public function products(string $slug): JsonResponse
    {
        $category = ProductCategory::visible()
            ->where('slug', $slug)
            ->firstOrFail();

        $perPage = min(
            request('per_page', config('shop.pagination.per_page')),
            config('shop.pagination.max_per_page')
        );

        $products = $category->products()
            ->published()
            ->inStock()
            ->paginate($perPage);

        return response()->json($products);
    }
}
