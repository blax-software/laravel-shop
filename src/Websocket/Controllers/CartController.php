<?php

declare(strict_types=1);

namespace Blax\Shop\Websocket\Controllers;

use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Facades\Cart as CartFacade;
use Blax\Shop\Http\Resources\CartResource;
use Blax\Shop\Services\StripeCheckoutConfirmation;

/**
 * Reusable storefront cart controller over the Blax WebSocket bridge.
 *
 * Ship-once, use-everywhere: a consuming app exposes it with a one-line stub in
 * app/Websocket/Controllers/ (the resolver only scans that namespace + this
 * package's, and resolves INHERITED methods):
 *
 *     class CartController extends \Blax\Shop\Websocket\Controllers\CartController {}
 *
 * The cart "follows" the socket: booted() adopts the client-held cart id and
 * re-binds it to this connection (see CartService::adopt), so every cart.*
 * method operates on the right guest cart without an account. All handlers reply
 * with the {@see CartResource} wire shape.
 *
 * Requires blax-software/laravel-websockets (soft dependency — this class is only
 * ever loaded inside a WS runtime, so the package installs fine without it).
 */
class CartController extends \BlaxSoftware\LaravelWebSockets\Websocket\Controller
{
    /** Guest storefront: no account required. */
    public $need_auth = false;

    /** @var \Blax\Shop\Models\Cart */
    protected $cart = null;

    /**
     * Resolve + re-bind this connection's cart before every cart.* method.
     */
    public function booted()
    {
        $cartId = request('cart_id');
        $cartId = is_string($cartId) && $cartId !== '' ? $cartId : null;

        $this->cart = CartFacade::adopt($cartId, $this->connection->socketId);
    }

    /**
     * Echo the (already-adopted) cart back to the client. The frontend persists
     * `cart.id` locally and re-sends it as `cart_id` on subsequent calls.
     */
    public function setCart()
    {
        return $this->success([
            'message' => __('shop::cart.session_updated'),
            'cart' => CartResource::make($this->cart),
        ]);
    }

    /**
     * Add a purchasable to the cart. `cart_item_id` is overloaded (frontend
     * contract): it may be a CartItem id, a ProductPrice id, or a Product id.
     */
    public function add()
    {
        $data = request()->validate([
            'cart_item_id' => 'sometimes|string',
            'quantity' => 'required|integer|min:1',
        ]);

        $addable = $this->resolveAddable($data['cart_item_id'] ?? null);

        if (! $addable) {
            return $this->error(['message' => __('shop::cart.not_found')]);
        }

        $this->alignCartCurrency($addable);

        try {
            $this->cart->addToCart(cartable: $addable, quantity: $data['quantity']);
        } catch (NotEnoughStockException) {
            return $this->error(['message' => __('shop::cart.out_of_stock')]);
        } catch (\Throwable $e) {
            return $this->error([
                'message' => __('shop::cart.add_failed'),
                'detail' => $e->getMessage(),
            ]);
        }

        return $this->success([
            'message' => __('shop::cart.added'),
            'cart' => CartResource::make($this->cart->fresh()),
        ]);
    }

    /**
     * Remove (or decrement) a line by cart item id.
     */
    public function remove()
    {
        $cartItemModel = config('shop.models.cart_item');

        $data = request()->validate([
            'cart_item_id' => 'required|string',
            'quantity' => 'required|integer|min:1',
        ]);

        $item = $cartItemModel::find($data['cart_item_id']);

        if (! $item) {
            return $this->error(['message' => __('shop::cart.item_not_found')]);
        }

        $this->cart->removeFromCart(cartable: $item, quantity: $data['quantity']);

        return $this->success([
            'message' => __('shop::cart.removed'),
            'cart' => CartResource::make($this->cart->fresh()),
        ]);
    }

    /**
     * Return the current cart.
     */
    public function show()
    {
        return $this->success(CartResource::make($this->cart));
    }

    /**
     * Return the cart, streaming a conversion frame if it has already become an
     * order (e.g. the client is polling after a Stripe redirect-back).
     */
    public function checkoutDetails()
    {
        if ($this->cart->isConverted()) {
            $this->progress([
                'message' => __('shop::cart.converted'),
                'status' => CartStatus::CONVERTED,
                'order' => $this->cart->order,
            ]);
        }

        return $this->success(CartResource::make($this->cart));
    }

    /**
     * Create a Stripe Checkout Session and hand back its hosted-payment URL.
     */
    public function checkout()
    {
        $data = request()->validate([
            'url' => 'sometimes|url',
        ]);

        try {
            $url = CartFacade::checkoutSessionLink($this->cart, [], $data['url'] ?? null);
        } catch (\Throwable $e) {
            return $this->error([
                'message' => __('shop::cart.checkout_failed'),
                'detail' => $e->getMessage(),
            ]);
        }

        if (! $url) {
            return $this->error(['message' => __('shop::cart.checkout_unavailable')]);
        }

        return $this->success([
            'message' => __('shop::cart.checkout_created'),
            'redirect' => $url,
        ]);
    }

    /**
     * `cart.confirm {session_id}` → `{ status, order: {id, order_number, status}|null, cart }`
     *
     * The buyer is back from Stripe Checkout (the success URL carries
     * `session_id`). Settles the session through the webhook's code path, see
     * {@see StripeCheckoutConfirmation} for the statuses. `cart` is the cart
     * this connection shops with now: a fresh one once the old cart became an
     * order, so the client can swap its stored cart id right away.
     */
    public function confirm()
    {
        $data = request()->validate([
            'session_id' => 'required|string|max:255',
        ]);

        try {
            $result = app(StripeCheckoutConfirmation::class)->confirm($data['session_id']);
        } catch (\Throwable $e) {
            return $this->error([
                'message' => __('shop::cart.confirm_failed'),
                'detail' => $e->getMessage(),
            ]);
        }

        $order = $result['order'];

        // booted() adopted the client's cart before the payment was settled; a
        // converted cart hands this connection a fresh one.
        if ($this->cart->isConverted()) {
            $this->cart = CartFacade::adopt(null, $this->connection->socketId);
        }

        return $this->success([
            'status' => $result['status'],
            'order' => $order ? [
                'id' => $order->getKey(),
                'order_number' => $order->order_number,
                'status' => $order->status,
            ] : null,
            'cart' => CartResource::make($this->cart->fresh()),
        ]);
    }

    /**
     * Resolve the overloaded `cart_item_id` into a purchasable (CartItem's
     * purchasable, else a ProductPrice, else a Product).
     */
    protected function resolveAddable(?string $id)
    {
        if (! $id) {
            return null;
        }

        $cartItemModel = config('shop.models.cart_item');
        $productPriceModel = config('shop.models.product_price');
        $productModel = config('shop.models.product');

        $addable = $cartItemModel::find($id)?->purchasable;
        $addable ??= $productPriceModel::find($id);
        $addable ??= $productModel::find($id);

        return $addable;
    }

    /**
     * Keep the cart's currency in step with the purchasable being added so a
     * mixed-currency cart can't form.
     */
    protected function alignCartCurrency($addable): void
    {
        $productPriceModel = config('shop.models.product_price');

        $currency = $addable instanceof $productPriceModel
            ? $addable->currency
            : $addable->defaultPrice()->first()?->currency;

        if ($currency && $currency !== $this->cart->currency) {
            $this->cart->update(['currency' => $currency]);
        }
    }
}
