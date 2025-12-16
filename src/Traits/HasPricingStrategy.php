<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\PricingStrategy;

trait HasPricingStrategy
{
    /**
     * Get the pricing strategy from metadata
     * Defaults to LOWEST if not set
     */
    public function getPricingStrategy(): PricingStrategy
    {
        $meta = $this->getMeta();
        $strategyValue = $meta->pricing_strategy ?? PricingStrategy::default()->value;

        return PricingStrategy::tryFrom($strategyValue) ?? PricingStrategy::default();
    }

    /**
     * Set the pricing strategy
     */
    public function setPricingStrategy(PricingStrategy $strategy): void
    {
        $this->updateMetaKey('pricing_strategy', $strategy->value);
        $this->save();
    }
}
