<?php

use Blax\Shop\Http\Controllers\Api\CategoryController;
use Blax\Shop\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

$config = config('shop.routes');

Route::prefix($config['prefix'])
    ->middleware($config['middleware'])
    ->name($config['name_prefix'])
    ->group(function () {

        // Categories
        Route::get('categories', [CategoryController::class, 'index'])
            ->name('categories.index');

        Route::get('categories/tree', [CategoryController::class, 'tree'])
            ->name('categories.tree');

        Route::get('categories/{slug}', [CategoryController::class, 'show'])
            ->name('categories.show');

        Route::get('categories/{slug}/products', [CategoryController::class, 'products'])
            ->name('categories.products');

        // Products
        Route::get('products', [ProductController::class, 'index'])
            ->name('products.index');

        Route::get('products/{slug}', [ProductController::class, 'show'])
            ->name('products.show');
    });
