<?php

namespace Blax\Shop\Tests\Unit\Enums;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ProductTypeTest extends TestCase
{
    #[Test]
    public function it_exposes_loanable_as_a_distinct_product_type(): void
    {
        $this->assertSame('loanable', ProductType::LOANABLE->value);
        $this->assertSame('Loanable', ProductType::LOANABLE->label());
        $this->assertSame(ProductType::LOANABLE, ProductType::from('loanable'));
    }

    #[Test]
    public function every_product_type_has_a_human_label(): void
    {
        foreach (ProductType::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Missing label for {$case->value}");
        }
    }
}
