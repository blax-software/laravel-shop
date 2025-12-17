<?php

namespace Blax\Shop\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Blax\Shop\Models\Cart current()
 * @method static \Blax\Shop\Models\Cart guest(string|null $sessionId = null)
 * @method static \Blax\Shop\Models\Cart forUser(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Blax\Shop\Models\Cart|null find(string $cartId)
 * @method static \Blax\Shop\Models\CartItem add(\Illuminate\Database\Eloquent\Model $product, int $quantity = 1, array $parameters = [])
 * @method static \Blax\Shop\Models\CartItem|true remove(\Illuminate\Database\Eloquent\Model $product, int $quantity = 1, array $parameters = [])
 * @method static \Blax\Shop\Models\CartItem update(\Blax\Shop\Models\CartItem $cartItem, int $quantity)
 * @method static int clear(\Blax\Shop\Models\Cart|null $cart = null)
 * @method static void clearSession()
 * @method static \Illuminate\Support\Collection|mixed checkout(\Blax\Shop\Models\Cart|null $cart = null)
 * @method static float total(\Blax\Shop\Models\Cart|null $cart = null)
 * @method static int itemCount(\Blax\Shop\Models\Cart|null $cart = null)
 * @method static \Illuminate\Database\Eloquent\Collection items(\Blax\Shop\Models\Cart|null $cart = null)
 * @method static bool isEmpty(\Blax\Shop\Models\Cart|null $cart = null)
 * @method static bool isExpired(\Blax\Shop\Models\Cart|null $cart = null)
 * @method static bool isConverted(\Blax\Shop\Models\Cart|null $cart = null)
 * @method static float unpaidAmount(\Blax\Shop\Models\Cart|null $cart = null)
 * @method static float paidAmount(\Blax\Shop\Models\Cart|null $cart = null)
 */
class Cart extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'shop.cart';
    }
}
