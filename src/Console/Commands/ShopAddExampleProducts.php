<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\ProductAttribute;
use Blax\Shop\Models\ProductCategory;
use Illuminate\Console\Command;
use Faker\Factory as Faker;

class ShopAddExampleProducts extends Command
{
    protected $signature = 'shop:add-example-products 
                            {--clean : Remove existing example products first}
                            {--count=2 : Number of products per type}';

    protected $description = 'Adds all possible example products to the shop for demonstration purposes.';

    /**
     * Available product types in the shop system
     */
    const PRODUCT_TYPES = [
        'simple' => [
            'name' => 'Simple Product',
            'description' => 'A standalone product with no variations (e.g., a book, a service)',
        ],
        'variable' => [
            'name' => 'Variable Product',
            'description' => 'A product with variations/options (e.g., a t-shirt with different sizes and colors)',
        ],
        'grouped' => [
            'name' => 'Grouped Product',
            'description' => 'A collection of related products sold together (e.g., a product bundle)',
        ],
        'external' => [
            'name' => 'External Product',
            'description' => 'A product that links to an external site for purchase',
        ],
    ];

    protected $faker;
    protected $categories = [];

    public function handle()
    {
        $this->faker = Faker::create();

        if ($this->option('clean')) {
            $this->cleanExampleProducts();
        }

        $this->info('Creating example products for Laravel Shop Package...');
        $this->newLine();

        // Create categories first
        $this->createCategories();

        $count = (int) $this->option('count');
        $totalCreated = 0;

        foreach (self::PRODUCT_TYPES as $type => $details) {
            $this->line("<fg=cyan>Creating {$count} {$details['name']}(s)...</>");

            for ($i = 1; $i <= $count; $i++) {
                $product = $this->createProduct($type, $i);
                $totalCreated++;

                $this->line("  <fg=green>✓</> {$product->slug}");
            }

            $this->newLine();
        }

        $this->info("✓ Successfully created {$totalCreated} example products!");
        $this->line("  - Products: {$totalCreated}");
        $this->line("  - Categories: " . count($this->categories));
        $this->newLine();
        $this->info("You can view them in your shop or use them for testing.");
    }

    protected function cleanExampleProducts(): void
    {
        $this->warn('Cleaning existing example products...');

        Product::where('slug', 'like', 'example-%')->delete();
        ProductCategory::where('slug', 'like', 'example-%')->delete();

        $this->info('✓ Cleaned existing example products');
        $this->newLine();
    }

    protected function createCategories(): void
    {
        $categoryNames = [
            'Electronics' => 'Electronic devices and gadgets',
            'Clothing' => 'Apparel and fashion items',
            'Books' => 'Books and publications',
            'Home & Garden' => 'Home improvement and garden supplies',
            'Sports' => 'Sports equipment and accessories',
        ];

        foreach ($categoryNames as $name => $description) {
            $category = ProductCategory::firstOrCreate(
                ['slug' => 'example-' . \Illuminate\Support\Str::slug($name)],
                [
                    'name' => $name,
                    'description' => $description,
                    'is_visible' => true,
                    'sort_order' => 0,
                    'meta' => json_encode((object)[]),
                ]
            );

            $this->categories[] = $category;
        }
    }

    protected function createProduct(string $type, int $index): Product
    {
        $productName = $this->generateProductName($type);
        $slug = 'example-' . \Illuminate\Support\Str::slug($productName) . '-' . $this->faker->unique()->numberBetween(1000, 9999);

        // Determine pricing and sale window for the product (prices managed via ProductPrice)
        $baseUnitAmount = $this->faker->numberBetween(1000, 50000); // cents
        $onSale = $this->faker->boolean(30); // 30% chance of being on sale
        $saleStart = $onSale ? now()->subDays($this->faker->numberBetween(1, 30)) : null;
        $saleEnd = $onSale ? now()->addDays($this->faker->numberBetween(7, 60)) : null;

        $product = Product::create([
            'slug' => $slug,
            'name' => $productName,
            'sku' => 'EX-' . strtoupper($this->faker->bothify('??-####')),
            'type' => ProductType::from($type),
            'status' => $this->faker->randomElement([ProductStatus::PUBLISHED, ProductStatus::PUBLISHED, ProductStatus::PUBLISHED, ProductStatus::DRAFT]),
            'is_visible' => true,
            'featured' => $this->faker->boolean(20),
            'sale_start' => $saleStart,
            'sale_end' => $saleEnd,
            'manage_stock' => $type !== ProductType::EXTERNAL->value,
            'low_stock_threshold' => $type !== ProductType::EXTERNAL->value ? 5 : null,
            'weight' => $type === 'virtual' ? null : $this->faker->randomFloat(2, 0.1, 50),
            'length' => $type === 'virtual' ? null : $this->faker->randomFloat(2, 5, 100),
            'width' => $type === 'virtual' ? null : $this->faker->randomFloat(2, 5, 100),
            'height' => $type === 'virtual' ? null : $this->faker->randomFloat(2, 5, 100),
            'virtual' => $type === ProductType::VARIABLE->value ? $this->faker->boolean(20) : false,
            'downloadable' => $type === ProductType::SIMPLE->value ? $this->faker->boolean(15) : false,
            'published_at' => now(),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'tax_class' => $this->faker->randomElement(['standard', 'reduced', 'zero']),
            'meta' => [
                'description' => $this->faker->paragraph(3),
                'short_description' => $this->faker->sentence(10),
                'example' => true,
            ],
        ]);

        // Set localized name
        $product->setLocalized('name', $productName, null, true);

        // Add to random categories
        $randomCategories = $this->faker->randomElements($this->categories, $this->faker->numberBetween(1, 3));
        $product->categories()->attach(collect($randomCategories)->pluck('id'));

        // Add localized fields
        $product->setLocalized('name', $productName, null, true);
        $product->setLocalized('short_description', $product->meta->short_description ?? '', null, true);
        $product->setLocalized('description', $product->meta->description ?? '', null, true);

        // Create default price entry (prices are morph-related)
        $product->prices()->create([
            'name' => 'Default',
            'type' => 'one_time',
            'currency' => 'EUR',
            'unit_amount' => $baseUnitAmount,
            'sale_unit_amount' => $onSale ? (int) round($baseUnitAmount * $this->faker->randomFloat(2, 0.6, 0.9)) : null,
            'is_default' => true,
            'active' => true,
            'billing_scheme' => 'per_unit',
            'meta' => ['example' => true],
        ]);

        // Add attributes
        $this->addAttributes($product, $type);

        // Add additional prices (multi-currency or subscription)
        if ($type === ProductType::SIMPLE->value || $type === ProductType::VARIABLE->value) {
            $this->addAdditionalPrices($product, $baseUnitAmount);
        }

        // Add example actions
        $this->addExampleActions($product);

        // For variable products, add variations
        if ($type === ProductType::VARIABLE->value) {
            $this->addVariations($product, $baseUnitAmount);
        }

        // For grouped products, add child products
        if ($type === ProductType::GROUPED->value) {
            $this->addGroupedProducts($product);
        }

        return $product;
    }

    protected function generateProductName(string $type): string
    {
        $names = [
            'simple' => [
                'Premium Wireless Headphones',
                'Organic Cotton T-Shirt',
                'Stainless Steel Water Bottle',
                'Leather Wallet',
                'Bamboo Cutting Board',
                'Yoga Mat Pro',
                'Coffee Mug Set',
                'Digital Course: Web Development',
            ],
            'variable' => [
                'Classic Running Shoes',
                'Designer Hoodie',
                'Smart Watch Ultra',
                'Backpack Collection',
                'Sunglasses Elite',
                'Fitness Tracker Band',
            ],
            'grouped' => [
                'Home Office Starter Kit',
                'Camping Essentials Bundle',
                'Kitchen Utensil Set',
                'Travel Accessories Pack',
                'Gaming Setup Bundle',
            ],
            'external' => [
                'External Brand Laptop',
                'Partner Store Gift Card',
                'Affiliate Product Link',
                'Third-Party Service',
            ],
        ];

        return $this->faker->randomElement($names[$type] ?? $names['simple']);
    }

    protected function addAttributes(Product $product, string $type): void
    {
        $attributes = [];

        switch ($type) {
            case 'simple':
                $attributes = [
                    ['name' => 'Material', 'value' => $this->faker->randomElement(['Cotton', 'Polyester', 'Leather', 'Metal', 'Plastic', 'Wood'])],
                    ['name' => 'Brand', 'value' => $this->faker->company()],
                    ['name' => 'Country of Origin', 'value' => $this->faker->country()],
                ];
                break;

            case 'variable':
                $attributes = [
                    ['name' => 'Size', 'value' => $this->faker->randomElement(['S, M, L, XL', 'One Size', '6-12'])],
                    ['name' => 'Color', 'value' => $this->faker->randomElement(['Red, Blue, Green', 'Black, White', 'Multi-Color'])],
                    ['name' => 'Material', 'value' => $this->faker->randomElement(['Cotton', 'Polyester', 'Blend'])],
                ];
                break;

            case 'grouped':
                $attributes = [
                    ['name' => 'Items Included', 'value' => $this->faker->numberBetween(3, 10) . ' pieces'],
                    ['name' => 'Bundle Type', 'value' => 'Curated Collection'],
                ];
                break;

            case 'external':
                $attributes = [
                    ['name' => 'External URL', 'value' => 'https://example.com/product'],
                    ['name' => 'Affiliate Link', 'value' => 'Yes'],
                ];
                break;
        }

        foreach ($attributes as $index => $attr) {
            ProductAttribute::create([
                'product_id' => $product->id,
                'key' => $attr['name'],
                'value' => $attr['value'],
                'sort_order' => $index,
                'meta' => json_encode((object)[]),
            ]);
        }
    }

    protected function addAdditionalPrices(Product $product, int $baseUnitAmount): void
    {
        // Add a subscription price option
        if ($this->faker->boolean(30)) {
            $product->prices()->create([
                'active' => true,
                'name' => 'Monthly Subscription',
                'type' => 'recurring',
                'unit_amount' => (int) round($baseUnitAmount * 0.3), // ~30% monthly
                'billing_scheme' => 'per_unit',
                'interval' => 'month',
                'interval_count' => 1,
                'trial_period_days' => $this->faker->randomElement([0, 7, 14, 30]),
                'currency' => 'EUR',
                'is_default' => false,
                'meta' => ['example' => true],
            ]);
        }

        // Add USD price variant
        if ($this->faker->boolean(40)) {
            $product->prices()->create([
                'active' => true,
                'name' => 'USD Price',
                'type' => 'one_time',
                'unit_amount' => (int) round($baseUnitAmount * 1.08), // approx conversion
                'billing_scheme' => 'per_unit',
                'currency' => 'USD',
                'is_default' => false,
                'meta' => ['example' => true],
            ]);
        }
    }

    protected function addExampleActions(Product $product): void
    {
        $namespace = config('shop.actions.namespace', 'App\\Jobs\\ProductAction');
        $actions = [
            [
                'events' => ['purchased'],
                'class' => $namespace . '\\SendThankYouEmail',
                'method' => null,
                'parameters' => ['template' => 'thank-you', 'delay' => 0],
                'defer' => true,
            ],
            [
                'events' => ['purchased'],
                'class' => $namespace . '\\UpdateCustomerStats',
                'method' => null,
                'parameters' => ['increment' => 'total_purchases'],
                'defer' => true,
            ],
            [
                'events' => ['low_stock'],
                'class' => $namespace . '\\NotifyAdmin',
                'method' => null,
                'parameters' => ['threshold' => 5],
                'defer' => true,
            ],
        ];

        foreach ($actions as $index => $actionData) {
            $product->actions()->create([
                'events' => $actionData['events'],
                'class' => $actionData['class'],
                'method' => $actionData['method'],
                'parameters' => $actionData['parameters'],
                'defer' => $actionData['defer'],
                'active' => $this->faker->boolean(70),
                'sort_order' => $index,
            ]);
        }
    }

    protected function addVariations(Product $product, int $baseUnitAmount): void
    {
        $variations = ['Small', 'Medium', 'Large'];

        foreach ($variations as $index => $variation) {
            $variationProduct = Product::create([
                'slug' => $product->slug . '-' . \Illuminate\Support\Str::slug($variation),
                'sku' => $product->sku . '-' . strtoupper(substr($variation, 0, 1)),
                'type' => 'simple',
                'parent_id' => $product->id,
                'status' => 'published',
                'is_visible' => false,
                'manage_stock' => true,
                'published_at' => now(),
                'meta' => ['variation' => $variation, 'example' => true],
            ]);

            $variationProduct->setLocalized('name', ($product->getLocalized('name') ?: 'Product') . ' - ' . $variation, null, true);

            // Create a slightly adjusted default price for the variation
            $variationAmount = $baseUnitAmount + ($index * 500); // +5.00 per size
            $variationProduct->prices()->create([
                'name' => 'Default',
                'type' => 'one_time',
                'currency' => 'EUR',
                'unit_amount' => $variationAmount,
                'is_default' => true,
                'active' => true,
                'billing_scheme' => 'per_unit',
                'meta' => ['example' => true, 'variation' => $variation],
            ]);

            ProductAttribute::create([
                'product_id' => $variationProduct->id,
                'key' => 'Size',
                'value' => $variation,
                'sort_order' => 0,
                'meta' => null,
            ]);
        }
    }

    protected function addGroupedProducts(Product $product): void
    {
        $groupSize = $this->faker->numberBetween(2, 4);

        for ($i = 0; $i < $groupSize; $i++) {
            $childProduct = Product::create([
                'slug' => $product->slug . '-item-' . ($i + 1),
                'sku' => $product->sku . '-' . ($i + 1),
                'type' => 'simple',
                'parent_id' => $product->id,
                'status' => 'published',
                'is_visible' => false,
                'manage_stock' => true,
                'published_at' => now(),
                'meta' => ['grouped_item' => true, 'example' => true],
            ]);

            $childProduct->setLocalized('name', $this->faker->words(3, true), null, true);

            // Create a standalone default price for the child item
            $childAmount = $this->faker->numberBetween(1000, 10000);
            $childProduct->prices()->create([
                'name' => 'Default',
                'type' => 'one_time',
                'currency' => 'EUR',
                'unit_amount' => $childAmount,
                'is_default' => true,
                'active' => true,
                'billing_scheme' => 'per_unit',
                'meta' => ['example' => true, 'grouped_item' => true],
            ]);
        }
    }
}
