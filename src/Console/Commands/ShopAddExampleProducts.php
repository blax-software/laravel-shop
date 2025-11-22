<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\ProductAttribute;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Models\ProductPrice;
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

        $regularPrice = $this->faker->randomFloat(2, 10, 500);
        $onSale = $this->faker->boolean(30); // 30% chance of being on sale

        $product = Product::create([
            'name' => $productName,
            'slug' => $slug,
            'sku' => 'EX-' . strtoupper($this->faker->bothify('??-####')),
            'type' => $type,
            'status' => $this->faker->randomElement(['published', 'published', 'published', 'draft']), // mostly published
            'is_visible' => true,
            'featured' => $this->faker->boolean(20), // 20% featured
            'price' => $onSale ? $regularPrice * 0.8 : $regularPrice,
            'regular_price' => $regularPrice,
            'sale_price' => $onSale ? $regularPrice * $this->faker->randomFloat(2, 0.6, 0.9) : null,
            'sale_start' => $onSale ? now()->subDays($this->faker->numberBetween(1, 30)) : null,
            'sale_end' => $onSale ? now()->addDays($this->faker->numberBetween(7, 60)) : null,
            'manage_stock' => $type !== 'external',
            'stock_quantity' => $type !== 'external' ? $this->faker->numberBetween(0, 100) : 0,
            'low_stock_threshold' => $type !== 'external' ? 5 : null,
            'in_stock' => true,
            'stock_status' => 'instock',
            'weight' => $type === 'virtual' ? null : $this->faker->randomFloat(2, 0.1, 50),
            'length' => $type === 'virtual' ? null : $this->faker->randomFloat(2, 5, 100),
            'width' => $type === 'virtual' ? null : $this->faker->randomFloat(2, 5, 100),
            'height' => $type === 'virtual' ? null : $this->faker->randomFloat(2, 5, 100),
            'virtual' => $type === 'variable' ? $this->faker->boolean(20) : false,
            'downloadable' => $type === 'simple' ? $this->faker->boolean(15) : false,
            'published_at' => now(),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'tax_class' => $this->faker->randomElement(['standard', 'reduced', 'zero']),
            'meta' => json_encode((object)[
                'description' => $this->faker->paragraph(3),
                'short_description' => $this->faker->sentence(10),
                'example' => true,
            ]),
        ]);

        // Set localized name
        $product->setLocalized('name', $productName, null, true);

        // Add to random categories
        $randomCategories = $this->faker->randomElements($this->categories, $this->faker->numberBetween(1, 3));
        $product->categories()->attach(collect($randomCategories)->pluck('id'));

        // Add attributes
        $this->addAttributes($product, $type);

        // Add additional prices (multi-currency or subscription)
        if ($type === 'simple' || $type === 'variable') {
            $this->addAdditionalPrices($product);
        }

        // Add example actions
        $this->addExampleActions($product);

        // For variable products, add variations
        if ($type === 'variable') {
            $this->addVariations($product);
        }

        // For grouped products, add child products
        if ($type === 'grouped') {
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

    protected function addAdditionalPrices(Product $product): void
    {
        // Add a subscription price option
        if ($this->faker->boolean(30)) {
            ProductPrice::create([
                'product_id' => $product->id,
                'active' => true,
                'name' => 'Monthly Subscription',
                'type' => 'recurring',
                'price' => (int)($product->price * 100 * 0.3), // 30% of regular price monthly
                'billing_scheme' => 'per_unit',
                'interval' => 'month',
                'interval_count' => 1,
                'trial_period_days' => $this->faker->randomElement([0, 7, 14, 30]),
                'currency' => 'usd',
                'is_default' => false,
                'meta' => json_encode((object)['example' => true]),
            ]);
        }

        // Add EUR price
        if ($this->faker->boolean(40)) {
            ProductPrice::create([
                'product_id' => $product->id,
                'active' => true,
                'name' => 'EUR Price',
                'type' => 'one_time',
                'price' => (int)($product->price * 100 * 0.92), // Convert to cents and EUR rate
                'billing_scheme' => 'per_unit',
                'currency' => 'eur',
                'is_default' => false,
                'meta' => json_encode((object)['example' => true]),
            ]);
        }
    }

    protected function addExampleActions(Product $product): void
    {
        $actions = [
            [
                'event' => 'purchased',
                'action_type' => 'SendThankYouEmail',
                'config' => ['template' => 'thank-you', 'delay' => 0],
                'description' => 'Send thank you email after purchase',
            ],
            [
                'event' => 'purchased',
                'action_type' => 'UpdateCustomerStats',
                'config' => ['increment' => 'total_purchases'],
                'description' => 'Update customer purchase statistics',
            ],
            [
                'event' => 'low_stock',
                'action_type' => 'NotifyAdmin',
                'config' => ['threshold' => 5],
                'description' => 'Notify admin when stock is low',
            ],
        ];

        foreach ($actions as $index => $actionData) {
            ProductAction::create([
                'product_id' => $product->id,
                'event' => $actionData['event'],
                'action_type' => $actionData['action_type'],
                'config' => $actionData['config'],
                'active' => $this->faker->boolean(70), // 70% active
                'sort_order' => $index,
            ]);
        }
    }

    protected function addVariations(Product $product): void
    {
        $variations = ['Small', 'Medium', 'Large'];

        foreach ($variations as $index => $variation) {
            $variationProduct = Product::create([
                'name' => $product->name . ' - ' . $variation,
                'slug' => $product->slug . '-' . \Illuminate\Support\Str::slug($variation),
                'sku' => $product->sku . '-' . strtoupper(substr($variation, 0, 1)),
                'type' => 'simple',
                'parent_id' => $product->id,
                'status' => 'published',
                'is_visible' => false, // Variations are not directly visible
                'price' => $product->price + ($index * 5), // Slight price increase per size
                'regular_price' => $product->regular_price + ($index * 5),
                'manage_stock' => true,
                'stock_quantity' => $this->faker->numberBetween(5, 50),
                'in_stock' => true,
                'stock_status' => 'instock',
                'published_at' => now(),
                'meta' => json_encode((object)['variation' => $variation, 'example' => true]),
            ]);

            $variationProduct->setLocalized('name', $product->name . ' - ' . $variation, null, true);

            ProductAttribute::create([
                'product_id' => $variationProduct->id,
                'key' => 'Size',
                'value' => $variation,
                'sort_order' => 0,
                'meta' => json_encode((object)[]),
            ]);
        }
    }

    protected function addGroupedProducts(Product $product): void
    {
        $groupSize = $this->faker->numberBetween(2, 4);

        for ($i = 0; $i < $groupSize; $i++) {
            $childProduct = Product::create([
                'name' => $product->name . ' Item ' . ($i + 1),
                'slug' => $product->slug . '-item-' . ($i + 1),
                'sku' => $product->sku . '-' . ($i + 1),
                'type' => 'simple',
                'parent_id' => $product->id,
                'status' => 'published',
                'is_visible' => false,
                'price' => $this->faker->randomFloat(2, 10, 100),
                'manage_stock' => true,
                'stock_quantity' => $this->faker->numberBetween(10, 50),
                'in_stock' => true,
                'stock_status' => 'instock',
                'published_at' => now(),
                'meta' => json_encode((object)['grouped_item' => true, 'example' => true]),
            ]);

            $childProduct->setLocalized('name', $this->faker->words(3, true), null, true);
        }
    }
}
