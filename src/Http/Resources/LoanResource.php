<?php

namespace Blax\Shop\Http\Resources;

/**
 * Loan-flavoured purchase resource — same shape as {@see PurchaseResource}
 * but named explicitly for loan/rental contexts. Host apps generally only
 * need to override `purchasableResource()` to point at their domain resource:
 *
 *     class LoanResource extends \Blax\Shop\Http\Resources\LoanResource
 *     {
 *         protected function purchasableResource(): ?string
 *         {
 *             return BookResource::class;
 *         }
 *     }
 *
 * Or skip the subclass entirely and use this class directly when the raw
 * `item` shape on the wire is fine.
 */
class LoanResource extends PurchaseResource
{
}
