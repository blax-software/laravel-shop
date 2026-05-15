<?php

declare(strict_types=1);

namespace Blax\Shop\Contracts;

/**
 * Contract for billable parties (typically the customer / authenticated user)
 * that own one or more stored payment methods on a payment provider.
 *
 * A `Chargable` actor is the *who* in "who pays for this {@see Purchasable}".
 * Orders and recurring charges resolve the actor's preferred provider +
 * method through this contract before handing off to the payment service.
 *
 * Reference implementation: pair {@see \Blax\Shop\Traits\HasPaymentMethods}
 * onto a `User` model — the trait satisfies both contract methods (the
 * Collection it returns degrades to an array via Eloquent's serialization,
 * which is what the array return type on this interface captures).
 *
 * Note: this contract is deliberately small. Anything beyond "which method
 * do I bill against" lives on {@see \Blax\Shop\Models\PaymentMethod} or
 * the provider service ({@see \Blax\Shop\Services\PaymentProvider\PaymentProviderService}).
 */
interface Chargable
{
    /**
     * Return the provider key of the actor's default payment method
     * (e.g. `'stripe'`), or `null` when none is configured yet.
     *
     * The order pipeline uses this to pick the right provider service
     * when no method is supplied explicitly at checkout.
     */
    public function getDefaultPaymentMethod(): ?string;

    /**
     * Return the actor's payment methods.
     *
     * Implementations may return an indexed array of method
     * descriptors or any iterable that array-casts cleanly — the
     * canonical implementation on {@see \Blax\Shop\Traits\HasPaymentMethods}
     * returns an {@see \Illuminate\Support\Collection} of
     * {@see \Blax\Shop\Models\PaymentMethod}, which satisfies this
     * contract via Collection-to-array coercion.
     *
     * @return array<int, mixed>|\Illuminate\Support\Collection
     */
    public function paymentMethods(): array;
}
