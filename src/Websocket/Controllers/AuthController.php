<?php

declare(strict_types=1);

namespace Blax\Shop\Websocket\Controllers;

use Blax\Shop\Facades\Cart as CartFacade;
use Illuminate\Support\Facades\Auth;

/**
 * Reusable storefront auth over the Blax WebSocket bridge (Sanctum PAT).
 *
 * Expose in a consuming app with a one-line stub:
 *
 *     class AuthController extends \Blax\Shop\Websocket\Controllers\AuthController {}
 *
 * `login` authenticates by email+password OR by an existing bearer token, issues
 * a Sanctum personal access token the client stores and re-sends as `authtoken`,
 * and MERGES the connection's guest cart into the user's cart (closing the
 * shop.cart.merge_on_login gap via CartService::mergeGuestIntoUser). `me`
 * self-heals the connection from a bearer token and seeds the per-socket auth
 * cache so subsequent need_auth controllers resolve the user.
 *
 * Requires Sanctum on the consuming app (guarded) + a User model using
 * HasApiTokens and the shop HasCart/HasShoppingCapabilities trait.
 */
class AuthController extends \BlaxSoftware\LaravelWebSockets\Websocket\Controller
{
    public $need_auth = false;

    public function login()
    {
        $requestData = request()->all();

        if (! empty($requestData['token'])) {
            $data = request()->validate([
                'token' => 'required|string',
                'email' => 'sometimes',
                'password' => 'sometimes',
            ]);
        } else {
            $data = request()->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);
        }

        $token = null;

        // Path 1: validate an existing bearer token.
        if (! empty($data['token']) && $this->sanctumAvailable()) {
            $tokenable = optional(\Laravel\Sanctum\PersonalAccessToken::findToken($data['token']))->tokenable;

            if ($tokenable) {
                Auth::login($tokenable);
                $token = $data['token'];
            }
        }

        // Path 2: email + password → new token.
        if (! Auth::check() && isset($data['email'], $data['password']) && Auth::attempt($data)) {
            $user = Auth::user();

            if (method_exists($user, 'createToken')) {
                $token = explode('|', $user->createToken('authtoken')->plainTextToken)[1] ?? null;
            }
        }

        if (! Auth::check()) {
            return $this->error(__('shop::auth.invalid_credentials'));
        }

        $user = Auth::user();

        $this->seedSocketAuthCache();
        $this->mergeGuestCart($user);

        return $this->success([
            'message' => __('shop::auth.login_ok'),
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function me()
    {
        $token = request('authtoken') ?? request('token');

        if ($token && ! Auth::check() && $this->sanctumAvailable()) {
            $tokenable = optional(\Laravel\Sanctum\PersonalAccessToken::findToken($token))->tokenable;

            if ($tokenable) {
                Auth::login($tokenable);
            }
        }

        if (Auth::check()) {
            $this->seedSocketAuthCache();
        }

        return $this->success(['user' => Auth::user()]);
    }

    /**
     * Merge this connection's guest cart into the just-authenticated user's cart.
     */
    protected function mergeGuestCart($user): void
    {
        try {
            $guest = CartFacade::guest($this->connection->socketId);
            CartFacade::mergeGuestIntoUser($guest, $user);
        } catch (\Throwable) {
            // A cart merge must never block login.
        }
    }

    /**
     * Cache {id,type} under socket_<id> so subsequent need_auth controllers on
     * this connection can resolve the user without another token round-trip.
     */
    protected function seedSocketAuthCache(): void
    {
        $key = 'socket_' . $this->connection->socketId;
        cache()->forget($key);
        cache()->remember($key, 60 * 15, fn () => [
            'id' => Auth::id(),
            'type' => Auth::user() ? get_class(Auth::user()) : null,
        ]);
    }

    protected function sanctumAvailable(): bool
    {
        return class_exists(\Laravel\Sanctum\PersonalAccessToken::class);
    }
}
